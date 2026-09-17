<?php
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/pagebuilder.php';
require_once __DIR__ . '/../inc/color_picker.php';
require_once __DIR__ . '/../inc/media_picker.php';
require_once __DIR__ . '/../inc/richtext_editor.php';
require_once __DIR__ . '/../inc/repeater_field.php';

$pageId = (int)($_GET['page'] ?? 0);

// Riordino drag&drop (AJAX)
if (($_POST['action'] ?? '') === 'reorder') {
  header('Content-Type: application/json');
  try { blocks_reorder(array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')))); echo json_encode(['ok' => true]); }
  catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
  exit;
}
// Aggiunta blocco trascinato dalla palette a una posizione precisa (AJAX)
if (($_POST['action'] ?? '') === 'block_add_at' && $pageId) {
  header('Content-Type: application/json');
  try {
    $newId = block_add($pageId, $_POST['type'] ?? '');
    $ids = array_values(array_filter(array_column(blocks_of_page($pageId, false), 'id'), fn($x) => $x != $newId));
    $idx = max(0, min((int)($_POST['index'] ?? count($ids)), count($ids)));
    array_splice($ids, $idx, 0, [$newId]);
    blocks_reorder($ids);
    echo json_encode(['ok' => true, 'id' => $newId]);
  } catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
  exit;
}

$page = pb_table_ready() ? pb_page_get($pageId) : null;

$msg = ''; $msgType = 'ok';
$act = $_POST['action'] ?? '';
try {
  if ($act === 'page_save' && $page) {
    $slug = pb_page_save($pageId, $_POST);
    $msg = 'Impostazioni salvate ✓ (URL: ' . h($slug) . '.html)';
    $page = pb_page_get($pageId);
  } elseif ($act === 'block_add' && $page) {
    block_add($pageId, $_POST['type'] ?? ''); $msg = 'Blocco aggiunto — compilalo qui sotto.';
  } elseif ($act === 'block_save' && $page) {
    $b = block_get((int)($_POST['id'] ?? 0)); $def = $b ? block_def($b['block_type']) : null;
    if ($def) {
      $data = []; foreach ($def['fields'] as $f) $data[$f['key']] = trim($_POST['f_' . $f['key']] ?? '');
      block_save_data($_POST['id'], $data, isset($_POST['active']) ? 1 : 0);
      $msg = 'Blocco salvato ✓ — controlla l\'anteprima, poi “Pubblica”.';
    }
  } elseif ($act === 'block_delete') {
    block_delete($_POST['id']); $msg = 'Blocco eliminato.';
  } elseif ($act === 'publish' && $page) {
    pb_publish($pageId); $msg = 'Pagina pubblicata ✓ — è online su ' . h($page['slug']) . '.html.'; $page = pb_page_get($pageId);
  }
} catch (Throwable $e) { $msgType = 'err'; $msg = $e->getMessage(); }

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('pagine', 'Editor pagina — VelociBuilder LITE');

if (!pb_table_ready()) { echo '<div class="hd"><div><h1>Editor pagina</h1></div></div><div class="msg err">Tabelle non trovate. Lancia prima: <a class="lnk" href="../api/migrate_pagebuilder.php" target="_blank">/api/migrate_pagebuilder.php</a></div>'; nc_admin_bottom(); exit; }
if (!$page) { echo '<div class="hd"><div><h1>Editor pagina</h1></div></div><div class="msg err">Pagina non trovata. <a class="lnk" href="pagine.php">Torna alle pagine</a></div>'; nc_admin_bottom(); exit; }

$blocks = blocks_of_page($pageId, false);
$registry = blocks_registry();
?>
<style>
.pb-tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid #e3e7de}
.pb-tab{border:none;background:transparent;padding:10px 18px;font:600 14px "Public Sans",sans-serif;color:#8a9184;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px}
.pb-tab.on{color:#1F7A3D;border-bottom-color:#1F7A3D}
.pb-layout{display:flex;gap:18px;align-items:flex-start}
.pb-palette{width:190px;flex-shrink:0;position:sticky;top:14px}
.pb-palitem{display:flex;align-items:center;gap:9px;padding:11px 12px;border:1px solid #e3e7de;border-radius:9px;background:#fff;margin-bottom:8px;cursor:grab;font-size:13px;font-weight:600;color:#3a4136;user-select:none}
.pb-palitem:hover{border-color:#1F7A3D;color:#1F7A3D;background:#f7fbf7}
.pb-palitem i{color:#1F7A3D;font-size:16px;flex-shrink:0}
.pb-palette-hint{font-size:11.5px;color:#8a9184;margin:0 0 10px}
.pb-canvas{flex:1;min-width:0}
</style>
<div class="hd">
  <div><h1><?= h($page['title']) ?></h1><p class="sub">Pagina libera a blocchi · <?= $page['status'] === 'published' ? '<span style="color:#1F7A3D">pubblicata</span>' : '<span style="color:#8a9184">bozza</span>' ?> · <?= h($page['slug']) ?>.html</p></div>
  <div style="display:flex;gap:8px">
    <a class="btn ghost" href="pagine.php"><i class="ti ti-arrow-left"></i> Pagine</a>
    <?php if ($page['status'] === 'published'): ?><a class="btn ghost" href="../<?= h($page['slug']) ?>.html" target="_blank"><i class="ti ti-eye"></i> Vedi online</a><?php endif; ?>
    <form method="post" style="margin:0"><input type="hidden" name="action" value="publish"><button class="btn" type="submit"><i class="ti ti-cloud-upload"></i> Pubblica</button></form>
  </div>
</div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<div class="pb-tabs">
  <button type="button" class="pb-tab on" data-tab="struttura">Struttura</button>
  <button type="button" class="pb-tab" data-tab="anteprima">Anteprima</button>
</div>

<div id="tabStruttura">
<div class="pb-layout">
  <div class="pb-palette">
    <div class="sec" style="margin-top:0">Blocchi</div>
    <p class="pb-palette-hint">Trascina un blocco nella pagina, o clicca per aggiungerlo in fondo.</p>
    <div id="paletteList">
    <?php foreach ($registry as $type => $def): ?>
      <div class="pb-palitem" data-type="<?= h($type) ?>"><i class="ti <?= h($def['icon']) ?>"></i> <?= h($def['label']) ?></div>
    <?php endforeach; ?>
    </div>
  </div>

  <div class="pb-canvas">
    <div class="card">
      <div class="sec" style="margin-top:0"><i class="ti ti-settings" style="vertical-align:-2px"></i> Impostazioni pagina</div>
      <form method="post">
        <input type="hidden" name="action" value="page_save">
        <div class="row">
          <div class="field"><label>Titolo</label><input type="text" name="title" value="<?= h($page['title']) ?>"></div>
          <div class="field"><label>URL (slug)</label><input type="text" name="slug" value="<?= h($page['slug']) ?>"></div>
        </div>
        <div class="field"><label>Titolo SEO <span style="color:#8a9184;font-weight:400">(vuoto = usa il titolo)</span></label><input type="text" name="seo_title" value="<?= h($page['seo_title'] ?? '') ?>"></div>
        <div class="field"><label>Descrizione SEO</label><textarea name="seo_desc"><?= h($page['seo_desc'] ?? '') ?></textarea></div>
        <div class="field"><label>Immagine condivisione social (URL) <span style="color:#8a9184;font-weight:400">(vuoto = immagine di default del sito)</span></label><input type="text" name="seo_image" value="<?= h($page['seo_image'] ?? '') ?>"></div>
        <button class="btn sm ghost" type="submit"><i class="ti ti-device-floppy"></i> Salva impostazioni</button>
      </form>
    </div>

    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
        <div class="sec" style="margin:0">Blocchi della pagina <span style="color:#8a9184;font-weight:400;font-size:13px">(<?= count($blocks) ?>)</span></div>
        <span style="font-size:12px;color:#8a9184"><i class="ti ti-arrows-move" style="vertical-align:-2px"></i> trascina per riordinare</span>
      </div>

      <div id="blockList">
      <?php if (!$blocks): ?><p style="color:#8a9184;text-align:center;padding:28px 10px;border:1.5px dashed #e3e7de;border-radius:10px;margin:0">Trascina qui un blocco dalla colonna a sinistra per iniziare.</p><?php endif; ?>
      <?php foreach ($blocks as $b): $def = block_def($b['block_type']); if (!$def) continue; ?>
        <div style="border:1px solid #e3e7de;border-radius:10px;margin-bottom:12px;<?= $b['active'] ? '' : 'opacity:.55' ?>" data-id="<?= (int)$b['id'] ?>">
          <div style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:#f4f6f3;border-bottom:1px solid #e3e7de;border-radius:10px 10px 0 0">
            <span class="vb-drag" style="cursor:grab;color:#b0b5a8" title="trascina"><i class="ti ti-grip-vertical"></i></span>
            <i class="ti <?= h($def['icon']) ?>" style="color:#1F7A3D"></i>
            <strong style="font-size:13.5px"><?= h($def['label']) ?></strong>
            <form method="post" style="margin-left:auto"><input type="hidden" name="action" value="block_delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn sm danger" type="submit" onclick="return confirm('Eliminare questo blocco?')"><i class="ti ti-trash"></i></button></form>
          </div>
          <form method="post" style="padding:14px">
            <input type="hidden" name="action" value="block_save"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
            <div style="display:flex;gap:12px;flex-wrap:wrap">
            <?php foreach ($def['fields'] as $f):
              $v = $b['data'][$f['key']] ?? '';
              $full = in_array($f['type'], ['textarea', 'html', 'repeater'], true);
              $w = $full ? 'flex:1 1 100%' : 'flex:1 1 200px';
            ?>
              <div style="<?= $w ?>;min-width:0">
                <label style="font-size:11px;color:#8a9184;font-weight:600;display:block;margin-bottom:3px"><?= h($f['label']) ?></label>
                <?php if ($f['type'] === 'color'): ?>
                  <?= vb_color_field('f_' . $f['key'], $v) ?>
                <?php elseif ($f['type'] === 'html'): ?>
                  <?= vb_richtext_field('f_' . $f['key'], $v) ?>
                <?php elseif ($f['type'] === 'repeater'): ?>
                  <?= vb_repeater_field('f_' . $f['key'], $f['item_fields'] ?? [], $v) ?>
                <?php elseif ($f['type'] === 'select'):
                  // opzioni statiche dal registro, oppure calcolate a runtime (es. 'dynamic'=>'forms' -> elenco form creati)
                  if (($f['dynamic'] ?? '') === 'forms') {
                    require_once __DIR__ . '/../inc/forms.php';
                    $opts = ['' => '— scegli un modulo —']; foreach (forms_all() as $ff) $opts[$ff['id']] = $ff['name'];
                  } else { $opts = $f['options']; }
                ?>
                  <select name="f_<?= h($f['key']) ?>"><?php foreach ($opts as $ov => $ol): ?><option value="<?= h($ov) ?>" <?= (string)$v === (string)$ov ? 'selected' : '' ?>><?= h($ol) ?></option><?php endforeach; ?></select>
                  <?php if (($f['dynamic'] ?? '') === 'forms' && !forms_all()): ?><div style="font-size:11px;color:#8a9184;margin-top:4px">Nessun modulo creato: vai in <a class="lnk" href="forms.php">Moduli</a> per crearne uno.</div><?php endif; ?>
                <?php elseif ($f['type'] === 'image'): ?>
                  <div style="display:flex;gap:8px;align-items:center">
                    <img src="<?= $v ? '../' . h($v) : '../assets/product-placeholder.png' ?>" style="width:52px;height:40px;object-fit:cover;border:1px solid #e3e7de;border-radius:6px;background:#fff" class="vb-imgprev">
                    <input type="text" name="f_<?= h($f['key']) ?>" value="<?= h($v) ?>" placeholder="percorso immagine" style="flex:1;font-size:12px" class="vb-imginput">
                    <button type="button" class="btn sm ghost" onclick="VBpickMedia((function(inp,img){return function(p){inp.value=p;img.src='../'+p;};})(this.previousElementSibling,this.previousElementSibling.previousElementSibling))"><i class="ti ti-photo"></i></button>
                  </div>
                <?php elseif ($f['type'] === 'textarea'): ?>
                  <textarea name="f_<?= h($f['key']) ?>" style="min-height:70px"><?= h($v) ?></textarea>
                <?php else: ?>
                  <input type="text" name="f_<?= h($f['key']) ?>" value="<?= h($v) ?>">
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            </div>
            <div style="display:flex;gap:10px;align-items:center;margin-top:12px">
              <button class="btn sm ghost" type="submit">Salva blocco</button>
              <label style="font-weight:400;font-size:13px;margin:0"><input type="checkbox" name="active" value="1" <?= $b['active'] ? 'checked' : '' ?>> visibile</label>
            </div>
          </form>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
</div>

<div id="tabAnteprima" style="display:none">
  <iframe id="pbPreviewFrame" style="width:100%;height:78vh;border:1px solid #e3e7de;border-radius:12px;background:#fff" title="Anteprima pagina"></iframe>
</div>

<?php nc_media_picker(); vb_richtext_assets(); vb_repeater_assets(); ?>

<div id="vbToast" style="display:none;position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:#1e2418;color:#fff;padding:10px 18px;border-radius:100px;font-size:13px;font-weight:600;z-index:3000;box-shadow:0 8px 24px rgba(0,0,0,.2)"></div>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(function(){
  var PAGE_ID = <?= (int)$pageId ?>;
  function toast(t,ok){var e=document.getElementById('vbToast');e.textContent=t;e.style.background=ok?'#1F7A3D':'#b23a3a';e.style.display='block';clearTimeout(e._t);e._t=setTimeout(function(){e.style.display='none';},2200);}
  function post(action,extra){
    var b=new URLSearchParams(); b.append('action',action); Object.keys(extra||{}).forEach(function(k){b.append(k,extra[k]);});
    return fetch('builder.php?page='+PAGE_ID,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b}).then(function(r){return r.json();});
  }

  // --- Tab Struttura / Anteprima ---
  var tabs=document.querySelectorAll('.pb-tab'), panelStruttura=document.getElementById('tabStruttura'), panelAnteprima=document.getElementById('tabAnteprima'), frame=document.getElementById('pbPreviewFrame');
  tabs.forEach(function(t){ t.addEventListener('click',function(){
    tabs.forEach(function(x){x.classList.remove('on')}); t.classList.add('on');
    if(t.dataset.tab==='anteprima'){ panelStruttura.style.display='none'; panelAnteprima.style.display='block'; frame.src='builder_preview.php?page='+PAGE_ID+'&t='+Date.now(); }
    else { panelAnteprima.style.display='none'; panelStruttura.style.display='block'; }
  });});

  // --- Palette: clic = aggiungi in fondo; trascina = aggiungi nella posizione di rilascio ---
  document.querySelectorAll('.pb-palitem').forEach(function(item){
    item.addEventListener('click', function(){
      var f=document.createElement('form'); f.method='post'; f.action='builder.php?page='+PAGE_ID;
      f.innerHTML='<input type="hidden" name="action" value="block_add"><input type="hidden" name="type" value="'+item.dataset.type+'">';
      document.body.appendChild(f); f.submit();
    });
  });

  if(typeof Sortable==='undefined')return;
  var palette=document.getElementById('paletteList'), list=document.getElementById('blockList');
  if(palette) new Sortable(palette,{group:{name:'blocks',pull:'clone',put:false},sort:false,animation:150});
  if(list) new Sortable(list,{group:{name:'blocks',pull:false,put:true},handle:'.vb-drag',animation:150,
    onAdd:function(evt){
      var type=evt.item.dataset.type, index=evt.newIndex;
      evt.item.remove(); // era solo un clone "fantasma" dalla palette: ricarichiamo per mostrare la vera scheda
      post('block_add_at',{type:type,index:index}).then(function(j){ if(j.ok) location.reload(); else toast('Errore: '+(j.error||''),false); });
    },
    onEnd:function(evt){
      if(evt.from!==evt.to) return; // il caso "trascinato dalla palette" è già gestito da onAdd
      var ids=[].map.call(list.querySelectorAll('[data-id]'),function(f){return f.dataset.id;});
      post('reorder',{ids:ids.join(',')}).then(function(j){ toast(j.ok?'Ordine salvato ✓ — poi “Pubblica”.':'Errore',j.ok); }).catch(function(){toast('Errore di rete',false);});
    }
  });
})();
</script>
<?php nc_admin_bottom();
