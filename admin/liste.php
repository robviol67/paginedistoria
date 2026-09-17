<?php
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/lists.php';
require_once __DIR__ . '/../inc/color_picker.php';
require_once __DIR__ . '/../inc/media_picker.php';
require_once __DIR__ . '/../inc/settings.php';

// Riordino drag&drop (AJAX)
if (($_POST['action'] ?? '') === 'reorder') {
  header('Content-Type: application/json');
  try { list_reorder(array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')))); echo json_encode(['ok' => true]); }
  catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
  exit;
}

$defs = lists_defs();
$key = preg_replace('/[^a-z0-9_]/', '', $_GET['list'] ?? '');
$def = $key ? ($defs[$key] ?? null) : null;

$msg = ''; $msgType = 'ok';
$act = $_POST['action'] ?? '';
try {
  if ($act === 'item_save' && $def) {
    $data = [];
    foreach ($def['fields'] as $f) $data[$f['key']] = trim($_POST['f_' . $f['key']] ?? '');
    list_item_save($key, (int)($_POST['id'] ?? 0), $data, isset($_POST['active']) ? 1 : 0);
    $msg = 'Elemento salvato ✓ — premi “Pubblica pagina” per aggiornare il sito.';
  } elseif ($act === 'item_delete') {
    list_item_delete($_POST['id']); $msg = 'Elemento eliminato.';
  } elseif ($act === 'config_save' && $def) {
    // impostazioni globali di uno slider/galleria (salvate in cms_settings, override dei default dal Design)
    $render = $def['render'] ?? 'list';
    $cfg = [];
    if ($render === 'slider') {
      $cfg = [
        'transition' => in_array($_POST['transition'] ?? 'fade', ['fade', 'slide'], true) ? $_POST['transition'] : 'fade',
        'autoplay'   => ($_POST['autoplay'] ?? '1') === '0' ? '0' : '1',
        'interval'   => (string) max(2, (int)($_POST['interval'] ?? 5)),
        'height'     => in_array($_POST['height'] ?? '70vh', ['50vh', '70vh', '100vh'], true) ? $_POST['height'] : '70vh',
      ];
    } elseif ($render === 'gallery') {
      $cfg = ['columns' => in_array((string)($_POST['columns'] ?? '3'), ['2', '3', '4'], true) ? (string)$_POST['columns'] : '3'];
    }
    setting_set('listcfg::' . $key, json_encode($cfg, JSON_UNESCAPED_UNICODE));
    $msg = 'Impostazioni salvate ✓ — premi “Pubblica pagina”.';
  } elseif ($act === 'publish' && $def) {
    require_once __DIR__ . '/../inc/cms.php';
    cms_publish($def['page']); $msg = 'Pagina “' . h($def['page']) . '” pubblicata ✓ — il sito è aggiornato.';
  }
} catch (Throwable $e) { $msgType = 'err'; $msg = $e->getMessage(); }

$ready = lists_table_ready();

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('liste', 'Liste — VelociBuilder LITE');
?>
<?php if (!$ready): ?>
  <div class="hd"><div><h1>Liste</h1></div></div>
  <div class="msg err">Tabella non trovata. Lancia prima: <a class="lnk" href="../api/migrate_lists.php" target="_blank">/api/migrate_lists.php</a></div>
  <?php nc_admin_bottom(); exit; endif; ?>

<?php if (!$def): /* elenco liste */ ?>
<div class="hd"><div><h1>Liste ripetibili</h1><p class="sub">Elenchi che puoi allungare/accorciare (fasi, statistiche, ...)</p></div></div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>
<?php if (!$defs): ?>
  <div class="card" style="text-align:center;color:#8a9184;padding:44px">
    <div style="font-size:30px;color:#c3c9ba"><i class="ti ti-list-details"></i></div>
    <p style="margin:12px 0 0">Nessuna lista definita. Marca un blocco ripetibile nel Design con <code>data-vb-list="nome"</code> e rilancia il build.</p>
  </div>
<?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px">
    <?php foreach ($defs as $k => $d): ?>
      <a href="liste.php?list=<?= h($k) ?>" style="text-decoration:none;color:inherit">
        <div class="card" style="margin:0">
          <div class="sec" style="margin:0 0 4px"><i class="ti ti-list-details" style="vertical-align:-2px"></i> <?= h($d['label'] ?? $k) ?></div>
          <div style="font-size:13px;color:#8a9184"><?= list_count($k) ?> elementi · pagina <strong><?= h($d['page']) ?></strong></div>
          <div style="font-size:12px;color:#8a9184;margin-top:8px"><?= h(implode(', ', array_map(fn($f) => $f['label'], $d['fields']))) ?></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php else: /* gestione di una lista */ ?>
<div class="hd">
  <div><h1><?= h($def['label'] ?? $key) ?></h1><p class="sub">Elementi della lista — pagina <?= h($def['page']) ?></p></div>
  <div style="display:flex;gap:8px">
    <a class="btn ghost" href="liste.php"><i class="ti ti-arrow-left"></i> Liste</a>
    <a class="btn ghost" href="../preview.php?p=<?= h($def['page']) ?>" target="_blank"><i class="ti ti-eye"></i> Anteprima</a>
    <form method="post" style="margin:0"><input type="hidden" name="action" value="publish"><button class="btn" type="submit"><i class="ti ti-cloud-upload"></i> Pubblica pagina</button></form>
  </div>
</div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>
<div class="msg" style="background:#eef3fb;border:1px solid #cfe0f5;color:#2b4a7a">Trascina <i class="ti ti-grip-vertical" style="vertical-align:-2px"></i> per riordinare. Aggiungi o elimina elementi, poi premi <strong>Pubblica pagina</strong>.</div>

<?php
  $items = list_items($key, false);
  $renderFields = function ($vals) use ($def) {
    $h = '';
    foreach ($def['fields'] as $f) {
      $v = $vals[$f['key']] ?? '';
      $type = $f['type'] ?? 'text';
      $full = $type === 'textarea';
      $flex = $full ? 'flex:1 1 100%' : ($type === 'color' ? 'flex:0 0 auto' : ($type === 'image' ? 'flex:1 1 260px' : 'flex:1 1 150px'));
      $h .= '<div style="' . $flex . ';min-width:0">';
      $h .= '<label style="font-size:11px;color:#8a9184;font-weight:600;display:block;margin-bottom:3px">' . h($f['label']) . '</label>';
      if ($type === 'color') $h .= vb_color_field('f_' . $f['key'], $v);
      elseif ($type === 'image') {
        $prev = $v ? '../' . h($v) : '../assets/product-placeholder.png';
        $h .= '<div style="display:flex;gap:6px;align-items:center">'
          . '<img src="' . $prev . '" style="width:44px;height:34px;object-fit:cover;border:1px solid #e3e7de;border-radius:6px;background:#fff;flex-shrink:0">'
          . '<input type="text" name="f_' . h($f['key']) . '" value="' . h($v) . '" placeholder="percorso immagine" style="flex:1;font-size:12px">'
          . '<button type="button" class="btn sm ghost" onclick="VBpickMedia((function(inp,img){return function(p){inp.value=p;img.src=\'../\'+p;};})(this.previousElementSibling,this.previousElementSibling.previousElementSibling))"><i class="ti ti-photo"></i></button>'
          . '</div>';
      }
      elseif ($full) $h .= '<textarea name="f_' . h($f['key']) . '" style="min-height:64px">' . h($v) . '</textarea>';
      else $h .= '<input type="text" name="f_' . h($f['key']) . '" value="' . h($v) . '">';
      $h .= '</div>';
    }
    return $h;
  };
?>
<div class="card">
  <div class="sec" style="margin-top:0">Elementi <span style="color:#8a9184;font-weight:400;font-size:13px">(<?= count($items) ?>)</span></div>
  <div id="listItems">
  <?php foreach ($items as $it): ?>
    <form method="post" data-id="<?= (int)$it['id'] ?>" style="border:1px solid #e3e7de;border-radius:10px;padding:12px;margin-bottom:10px;<?= $it['active'] ? '' : 'opacity:.55' ?>">
      <input type="hidden" name="action" value="item_save"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
      <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap">
        <span class="vb-drag" style="cursor:grab;color:#b0b5a8;padding:6px 2px" title="trascina"><i class="ti ti-grip-vertical"></i></span>
        <?= $renderFields($it['data']) ?>
      </div>
      <div style="display:flex;gap:8px;align-items:center;margin-top:10px">
        <button class="btn sm ghost" type="submit">Salva</button>
        <label style="font-weight:400;font-size:13px;margin:0"><input type="checkbox" name="active" value="1" <?= $it['active'] ? 'checked' : '' ?>> visibile</label>
        <button class="btn sm danger" type="submit" name="action" value="item_delete" style="margin-left:auto" onclick="return confirm('Eliminare questo elemento?')"><i class="ti ti-trash"></i></button>
      </div>
    </form>
  <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <div class="sec" style="margin-top:0"><i class="ti ti-plus" style="vertical-align:-2px"></i> Aggiungi elemento</div>
  <form method="post">
    <input type="hidden" name="action" value="item_save"><input type="hidden" name="active" value="1">
    <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap"><?= $renderFields([]) ?></div>
    <button class="btn sm" type="submit" style="margin-top:10px"><i class="ti ti-plus"></i> Aggiungi</button>
  </form>
</div>

<?php $render = $def['render'] ?? 'list'; if ($render === 'slider' || $render === 'gallery'): $cfg = lists_config($key, $def); ?>
<div class="card">
  <div class="sec" style="margin-top:0"><i class="ti ti-adjustments" style="vertical-align:-2px"></i> Impostazioni <?= $render === 'slider' ? 'slider' : 'galleria' ?></div>
  <form method="post">
    <input type="hidden" name="action" value="config_save">
    <?php if ($render === 'slider'): ?>
      <div class="row">
        <div class="field"><label>Transizione</label><select name="transition"><option value="fade" <?= ($cfg['transition'] ?? 'fade') === 'fade' ? 'selected' : '' ?>>Dissolvenza</option><option value="slide" <?= ($cfg['transition'] ?? '') === 'slide' ? 'selected' : '' ?>>Scorrimento</option></select></div>
        <div class="field"><label>Avanzamento automatico</label><select name="autoplay"><option value="1" <?= ($cfg['autoplay'] ?? '1') !== '0' ? 'selected' : '' ?>>Sì</option><option value="0" <?= ($cfg['autoplay'] ?? '1') === '0' ? 'selected' : '' ?>>No</option></select></div>
      </div>
      <div class="row">
        <div class="field"><label>Intervallo (secondi)</label><input type="number" name="interval" min="2" value="<?= h($cfg['interval'] ?? '5') ?>"></div>
        <div class="field"><label>Altezza</label><select name="height"><option value="50vh" <?= ($cfg['height'] ?? '70vh') === '50vh' ? 'selected' : '' ?>>Bassa</option><option value="70vh" <?= ($cfg['height'] ?? '70vh') === '70vh' ? 'selected' : '' ?>>Media</option><option value="100vh" <?= ($cfg['height'] ?? '70vh') === '100vh' ? 'selected' : '' ?>>Schermo intero</option></select></div>
      </div>
    <?php else: ?>
      <div class="field" style="max-width:220px"><label>Colonne</label><select name="columns"><?php foreach (['2', '3', '4'] as $c): ?><option value="<?= $c ?>" <?= (string)($cfg['columns'] ?? '3') === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <button class="btn sm ghost" type="submit" style="margin-top:6px"><i class="ti ti-device-floppy"></i> Salva impostazioni</button>
  </form>
</div>
<?php endif; ?>

<div id="vbToast" style="display:none;position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:#1e2418;color:#fff;padding:10px 18px;border-radius:100px;font-size:13px;font-weight:600;z-index:3000;box-shadow:0 8px 24px rgba(0,0,0,.2)"></div>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(function(){
  function toast(t,ok){var e=document.getElementById('vbToast');e.textContent=t;e.style.background=ok?'#1F7A3D':'#b23a3a';e.style.display='block';clearTimeout(e._t);e._t=setTimeout(function(){e.style.display='none';},2000);}
  if(typeof Sortable==='undefined')return;
  var list=document.getElementById('listItems');
  if(list)new Sortable(list,{handle:'.vb-drag',animation:150,onEnd:function(){
    var ids=[].map.call(list.querySelectorAll('form[data-id]'),function(f){return f.dataset.id;});
    var b=new URLSearchParams();b.append('action','reorder');b.append('ids',ids.join(','));
    fetch('liste.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b})
      .then(function(r){return r.json();}).then(function(j){toast(j.ok?'Ordine salvato ✓ — poi “Pubblica pagina”.':'Errore',j.ok);})
      .catch(function(){toast('Errore di rete',false);});
  }});
})();
</script>
<?php nc_media_picker(); /* per i campi immagine di slider/galleria */ ?>
<?php endif; ?>
<?php nc_admin_bottom();
