<?php
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/nav.php';
require_once __DIR__ . '/../inc/cms.php';
require_once __DIR__ . '/../inc/footer.php';
require_once __DIR__ . '/../inc/pagebuilder.php';
require_once __DIR__ . '/../inc/blog.php';

// Riordino drag&drop (AJAX)
if (($_POST['action'] ?? '') === 'reorder') {
  header('Content-Type: application/json');
  try { nav_reorder(array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')))); echo json_encode(['ok' => true]); }
  catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
  exit;
}

$hier = nav_has_col('parent_id'); // gerarchia (menu a tendina) disponibile?
$msg = ''; $msgType = 'ok';
$act = $_POST['action'] ?? '';
try {
  if ($act === 'save') {
    if (trim($_POST['label'] ?? '') === '') throw new Exception('L\'etichetta è obbligatoria.');
    $kind = ($_POST['kind'] ?? 'link') === 'header' ? 'header' : 'link';
    if ($kind === 'link' && trim($_POST['href'] ?? '') === '') throw new Exception('Il link è obbligatorio (o scegli tipo “Intestazione”).');
    nav_item_save(['id' => $_POST['id'] ?? 0, 'label' => $_POST['label'], 'href' => $_POST['href'] ?? '#',
      'sort' => (int)($_POST['sort'] ?? 0), 'is_cta' => isset($_POST['is_cta']) ? 1 : 0, 'active' => isset($_POST['active']) ? 1 : 0,
      'parent_id' => $_POST['parent_id'] ?? '', 'kind' => $kind]);
    $msg = 'Voce salvata ✓ — premi “Pubblica su tutte le pagine” per applicarla al sito.';
  } elseif ($act === 'delete') {
    nav_item_delete($_POST['id']); $msg = 'Voce eliminata' . ($hier ? ' (con le sue eventuali sotto-voci).' : '.');
  } elseif ($act === 'footer_save') {
    setting_set('footer_left', trim($_POST['footer_left'] ?? ''));
    setting_set('footer_right', trim($_POST['footer_right'] ?? ''));
    $msg = 'Piè di pagina salvato ✓ — premi “Pubblica su tutte le pagine” per applicarlo al sito.';
  } elseif ($act === 'publish_all') {
    $ok = []; $fail = [];
    foreach (array_keys(cms_pages()) as $slug) { try { cms_publish($slug); $ok[] = $slug; } catch (Throwable $e) { $fail[] = $slug; } }
    if (pb_table_ready()) foreach (pb_pages_all() as $c) {
      if ($c['status'] !== 'published') continue; // le bozze non hanno ancora un file da riscrivere
      try { pb_publish($c['id']); $ok[] = $c['slug']; } catch (Throwable $e) { $fail[] = $c['slug']; }
    }
    if (blog_table_ready()) {
      try { blog_publish_all(); $ok[] = 'blog'; } catch (Throwable $e) { $fail[] = 'blog'; }
    }
    $msg = 'Menu pubblicato su ' . count($ok) . ' pagine ✓' . ($fail ? ' — non riuscite: ' . implode(', ', $fail) : '');
    if ($fail) $msgType = 'err';
  }
} catch (Throwable $e) { $msgType = 'err'; $msg = $e->getMessage(); }

$ready = nav_table_ready();
$items = $ready ? nav_items(false) : [];
// gerarchia: raggruppa figli per padre (per la visualizzazione indentata)
$childrenOf = [];
foreach ($items as $it) { $pid = $it['parent_id'] ?? null; if ($pid) $childrenOf[$pid][] = $it; }
$topItems = array_values(array_filter($items, fn($it) => empty($it['parent_id'])));

$PAGE_NAMES = ['home' => 'Home'];
$menuPages = [];
foreach (cms_pages() as $slug => $p) $menuPages[] = ['out' => $p['out'], 'label' => $PAGE_NAMES[$slug] ?? $p['title']];
if (pb_table_ready()) foreach (pb_pages_all() as $c) $menuPages[] = ['out' => $c['slug'] . '.html', 'label' => $c['title']];
if (blog_table_ready()) $menuPages[] = ['out' => 'blog.html', 'label' => 'Blog'];
$knownOuts = array_column($menuPages, 'out');

function vb_href_field($current) {
  global $menuPages, $knownOuts;
  $isCustom = !in_array($current, $knownOuts, true);
  $selName = $isCustom ? '' : 'href';
  $h = '<select class="vb-hrefsel"' . ($selName ? ' name="href"' : '') . ' style="flex:0 0 170px">';
  foreach ($menuPages as $p) $h .= '<option value="' . h($p['out']) . '"' . ($current === $p['out'] ? ' selected' : '') . '>' . h($p['label']) . '</option>';
  $h .= '<option value="__custom__"' . ($isCustom ? ' selected' : '') . '>Altro (ancora/URL)…</option></select>';
  $h .= '<input type="text"' . ($isCustom ? ' name="href"' : '') . ' class="vb-hreftxt" value="' . h($current) . '" placeholder="pagina.html#ancora / https://…" style="flex:1;min-width:150px;' . ($isCustom ? '' : 'display:none') . '">';
  return $h;
}
// Dropdown "genitore": solo le voci di primo livello (max 2 livelli). Escludo self.
function vb_parent_field($topItems, $current, $selfId = 0) {
  $cur = ($current ?? '') === '' ? '' : (int)$current;
  $h = '<select name="parent_id" class="vb-parent" style="flex:0 0 150px" title="Voce padre">';
  $h .= '<option value=""' . ($cur === '' ? ' selected' : '') . '>— primo livello —</option>';
  foreach ($topItems as $t) {
    if ((int)$t['id'] === (int)$selfId) continue;
    $h .= '<option value="' . (int)$t['id'] . '"' . ($cur === (int)$t['id'] ? ' selected' : '') . '>↳ sotto “' . h($t['label']) . '”</option>';
  }
  return $h . '</select>';
}
function vb_kind_field($current) {
  $k = ($current ?? 'link') === 'header' ? 'header' : 'link';
  return '<select name="kind" class="vb-kind" style="flex:0 0 120px" title="Tipo voce">'
    . '<option value="link"' . ($k === 'link' ? ' selected' : '') . '>Link</option>'
    . '<option value="header"' . ($k === 'header' ? ' selected' : '') . '>Intestazione</option></select>';
}

// Render di una riga voce (form). $child = true per lo stile indentato.
function vb_nav_row($it, $topItems, $hier, $child = false) {
  ob_start(); ?>
  <form method="post" data-id="<?= (int)$it['id'] ?>" style="display:flex;gap:7px;align-items:center;margin-bottom:7px;flex-wrap:wrap;<?= $child ? 'margin-left:34px;' : '' ?><?= $it['active'] ? '' : 'opacity:.55' ?>">
    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="sort" value="<?= (int)$it['sort'] ?>">
    <span class="vb-drag" style="cursor:grab;color:#b0b5a8;padding:0 2px" title="trascina"><i class="ti ti-grip-vertical"></i></span>
    <input type="text" name="label" value="<?= h($it['label']) ?>" placeholder="Etichetta" style="flex:0 0 150px">
    <?php if (($it['kind'] ?? 'link') === 'header'): ?>
      <span style="flex:0 0 170px;font-size:12px;color:#8a9184;font-style:italic">intestazione (nessun link)</span>
      <input type="hidden" name="href" value="#">
    <?php else: ?>
      <?= vb_href_field($it['href']) ?>
    <?php endif; ?>
    <?php if ($hier): ?><?= vb_parent_field($topItems, $it['parent_id'] ?? '', $it['id']) ?><?= vb_kind_field($it['kind'] ?? 'link') ?><?php endif; ?>
    <label style="font-weight:400;font-size:12.5px;margin:0;white-space:nowrap"><input type="checkbox" name="is_cta" value="1" <?= $it['is_cta'] ? 'checked' : '' ?>> CTA</label>
    <label style="font-weight:400;font-size:12.5px;margin:0;white-space:nowrap"><input type="checkbox" name="active" value="1" <?= $it['active'] ? 'checked' : '' ?>> visibile</label>
    <button class="btn sm ghost" type="submit">Salva</button>
    <button class="btn sm danger" type="submit" name="action" value="delete" onclick="return confirm('Eliminare questa voce<?= $hier ? ' e le sue sotto-voci' : '' ?>?')"><i class="ti ti-trash"></i></button>
  </form>
  <?php return ob_get_clean();
}

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('menu', 'Menu — VelociBuilder LITE');
?>
<div class="hd">
  <div><h1>Menu e piè di pagina</h1><p class="sub">Barra in alto e footer, condivisi da tutte le pagine</p></div>
  <div style="display:flex;gap:8px">
    <a class="btn ghost" href="../preview.php?p=home" target="_blank"><i class="ti ti-eye"></i> Anteprima</a>
    <form method="post" style="margin:0"><input type="hidden" name="action" value="publish_all"><button class="btn" type="submit"><i class="ti ti-cloud-upload"></i> Pubblica su tutte le pagine</button></form>
  </div>
</div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>
<?php if (!$ready): ?>
  <div class="msg err">Tabella non trovata. Lancia prima: <a class="lnk" href="../api/migrate_nav.php" target="_blank">/api/migrate_nav.php</a></div>
<?php else: ?>
<div class="msg" style="background:#eef3fb;border:1px solid #cfe0f5;color:#2b4a7a">
  <?php if ($hier): ?>Menu a <strong>due livelli</strong>: imposta <strong>Genitore</strong> = “sotto …” per far diventare una voce un elemento del menu a tendina del suo padre. Una voce di primo livello <em>con figli</em> diventa automaticamente un menu a tendina. Il <strong>Tipo “Intestazione”</strong> è un'etichetta non cliccabile dentro un pannello (es. “Catalogo Xerox®”).<br><?php endif; ?>
  Trascina <i class="ti ti-grip-vertical" style="vertical-align:-2px"></i> per riordinare. Dopo le modifiche premi <strong>Pubblica su tutte le pagine</strong>.
</div>

<div class="card">
  <div class="sec" style="margin-top:0">Voci del menu <span style="color:#8a9184;font-weight:400;font-size:13px">(<?= count($items) ?>)</span></div>
  <div id="navList">
  <?php if ($hier):
    foreach ($topItems as $it) {
      echo vb_nav_row($it, $topItems, $hier, false);
      foreach ($childrenOf[$it['id']] ?? [] as $ch) echo vb_nav_row($ch, $topItems, $hier, true);
    }
  else:
    foreach ($items as $it) echo vb_nav_row($it, $topItems, $hier, false);
  endif; ?>
  </div>
  <form method="post" style="display:flex;gap:7px;align-items:center;margin-top:12px;border-top:1px solid #eef1ec;padding-top:12px;flex-wrap:wrap">
    <input type="hidden" name="action" value="save"><input type="hidden" name="active" value="1">
    <input type="text" name="label" placeholder="Nuova voce" style="flex:0 0 150px" required>
    <?= vb_href_field($menuPages ? $menuPages[0]['out'] : '') ?>
    <?php if ($hier): ?><?= vb_parent_field($topItems, '') ?><?= vb_kind_field('link') ?><?php endif; ?>
    <label style="font-weight:400;font-size:12.5px;margin:0;white-space:nowrap"><input type="checkbox" name="is_cta" value="1"> CTA</label>
    <button class="btn sm" type="submit"><i class="ti ti-plus"></i> Aggiungi voce</button>
  </form>
</div>

<?php $fv = footer_values(); ?>
<div class="card">
  <div class="sec" style="margin-top:0"><i class="ti ti-layout-bottombar" style="vertical-align:-2px"></i> Piè di pagina</div>
  <p style="font-size:12.5px;color:#8a9184;margin:0 0 14px">La riga in fondo a ogni pagina (© a sinistra, certificazioni a destra). Il logo accanto al testo di sinistra è fisso.</p>
  <form method="post">
    <input type="hidden" name="action" value="footer_save">
    <div class="row">
      <div class="field"><label>Testo a sinistra (©)</label><input type="text" name="footer_left" value="<?= h($fv['left']) ?>"></div>
      <div class="field"><label>Testo a destra</label><input type="text" name="footer_right" value="<?= h($fv['right']) ?>"></div>
    </div>
    <button class="btn sm" type="submit"><i class="ti ti-device-floppy"></i> Salva piè di pagina</button>
  </form>
</div>

<div id="vbToast" style="display:none;position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:#1e2418;color:#fff;padding:10px 18px;border-radius:100px;font-size:13px;font-weight:600;z-index:3000;box-shadow:0 8px 24px rgba(0,0,0,.2)"></div>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(function(){
  function toast(t,ok){var e=document.getElementById('vbToast');e.textContent=t;e.style.background=ok?'#1F7A3D':'#b23a3a';e.style.display='block';clearTimeout(e._t);e._t=setTimeout(function(){e.style.display='none';},2000);}
  // dropdown pagine <-> campo personalizzato: solo il campo attivo ha name="href" (evita doppio invio)
  document.querySelectorAll('.vb-hrefsel').forEach(function(sel){
    var txt=sel.nextElementSibling;
    sel.addEventListener('change',function(){
      if(sel.value==='__custom__'){ sel.removeAttribute('name'); txt.setAttribute('name','href'); txt.style.display=''; txt.focus(); }
      else { sel.setAttribute('name','href'); txt.removeAttribute('name'); txt.style.display='none'; }
    });
  });
  if(typeof Sortable==='undefined')return;
  var list=document.getElementById('navList');
  if(list)new Sortable(list,{handle:'.vb-drag',animation:150,onEnd:function(){
    var ids=[].map.call(list.querySelectorAll('form[data-id]'),function(f){return f.dataset.id;});
    var b=new URLSearchParams();b.append('action','reorder');b.append('ids',ids.join(','));
    fetch('menu.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b})
      .then(function(r){return r.json();}).then(function(j){toast(j.ok?'Ordine salvato ✓ — poi “Pubblica su tutte le pagine”.':'Errore',j.ok);})
      .catch(function(){toast('Errore di rete',false);});
  }});
})();
</script>
<?php endif; ?>
<?php nc_admin_bottom();
