<?php
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/products.php';
require_once __DIR__ . '/../inc/cms.php';
require_once __DIR__ . '/../inc/repeater_field.php';

const PROD_GALLERY_FIELDS = [
  ['key' => 'image',   'label' => 'Immagine',             'type' => 'image'],
  ['key' => 'alt',     'label' => 'Testo alternativo',    'type' => 'text'],
  ['key' => 'caption', 'label' => 'Didascalia (facolt.)', 'type' => 'text'],
];

// Riordino drag&drop (AJAX): risponde JSON e termina.
if (($_POST['action'] ?? '') === 'reorder') {
  header('Content-Type: application/json');
  try {
    $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
    if (($_POST['type'] ?? '') === 'families') prod_reorder_families($ids);
    else prod_reorder_products($ids);
    echo json_encode(['ok' => true]);
  } catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
  exit;
}

$msg = ''; $msgType = 'ok';
$act = $_POST['action'] ?? '';
try {
  if ($act === 'family_save') {
    prod_family_save($_POST['id'] ?? 0, trim($_POST['name'] ?? ''), trim($_POST['tagline'] ?? ''), (int)($_POST['sort'] ?? 0));
    $msg = 'Famiglia salvata ✓';
  } elseif ($act === 'family_delete') {
    prod_family_delete($_POST['id']); $msg = 'Famiglia (e suoi prodotti) eliminata.';
  } elseif ($act === 'product_delete') {
    prod_product_delete($_POST['id']); $msg = 'Prodotto eliminato.';
  } elseif ($act === 'product_save') {
    // helper upload
    $up = function ($field, $exts, $subdir) {
      if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) return null;
      $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, $exts)) throw new Exception('Formato file non valido per ' . $field . ' (ammessi: ' . implode(', ', $exts) . ').');
      $dir = __DIR__ . '/../uploads/' . $subdir;
      if (!is_dir($dir)) @mkdir($dir, 0775, true);
      $fn = 'p' . date('YmdHis') . '-' . mt_rand(100, 999) . '.' . $ext;
      if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $fn)) throw new Exception('Upload non riuscito (permessi cartella uploads/).');
      return 'uploads/' . $subdir . '/' . $fn;
    };
    $image = $_POST['image_current'] ?? '';
    if (($_POST['remove_image'] ?? '') === '1') { prod_file_cleanup($image); $image = ''; }
    $newImg = $up('image', ['png', 'jpg', 'jpeg', 'webp'], 'prodotti');
    if ($newImg) { prod_file_cleanup($_POST['image_current'] ?? ''); $image = $newImg; }
    // scelta dalla Media Library (usata solo se non è stato caricato un file nuovo)
    $picked = trim($_POST['image_picked'] ?? '');
    if (!$newImg && $picked !== '' && $picked !== $image) { prod_file_cleanup($_POST['image_current'] ?? ''); $image = $picked; }

    $brochure = $_POST['brochure_current'] ?? '';
    if (($_POST['remove_brochure'] ?? '') === '1') { prod_file_cleanup($brochure); $brochure = ''; }
    $newBr = $up('brochure', ['pdf'], 'brochure');
    if ($newBr) { prod_file_cleanup($_POST['brochure_current'] ?? ''); $brochure = $newBr; }

    if (trim($_POST['model'] ?? '') === '') throw new Exception('Il modello è obbligatorio.');
    prod_product_save([
      'id' => $_POST['id'] ?? 0, 'family_id' => (int)($_POST['family_id'] ?? 0), 'model' => trim($_POST['model']),
      'category' => trim($_POST['category'] ?? ''), 'speed' => trim($_POST['speed'] ?? ''), 'cycle' => trim($_POST['cycle'] ?? ''),
      'bullets' => trim($_POST['bullets'] ?? ''), 'image' => $image, 'brochure' => $brochure,
      'gallery_images' => trim($_POST['gallery_images'] ?? ''),
      'sort' => (int)($_POST['sort'] ?? 0), 'active' => isset($_POST['active']) ? 1 : 0,
    ]);
    $msg = 'Prodotto salvato ✓ — ricordati di premere “Pubblica pagina”.';
  } elseif ($act === 'publish') {
    cms_publish('prodotti'); $msg = 'Pagina Prodotti pubblicata ✓ — il sito è aggiornato.';
  }
} catch (Throwable $e) { $msgType = 'err'; $msg = $e->getMessage(); }

$families = prod_families();
$noTable = false;
try { db()->query('SELECT 1 FROM cms_products LIMIT 1'); } catch (Throwable $e) { $noTable = true; }

// vista form prodotto
$formView = false; $edit = null;
if (isset($_GET['edit'])) { $edit = prod_product_get($_GET['edit']); $formView = (bool)$edit; }
elseif (isset($_GET['new'])) { $edit = ['id' => 0, 'family_id' => $_GET['family'] ?? ($families[0]['id'] ?? 0), 'model' => '', 'category' => '', 'speed' => '', 'cycle' => '', 'bullets' => '', 'image' => '', 'brochure' => '', 'gallery_images' => '', 'sort' => 0, 'active' => 1]; $formView = true; }

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('prodotti', 'Prodotti — VelociBuilder LITE');

if ($noTable) { echo '<div class="hd"><div><h1>Prodotti</h1></div></div><div class="msg err">Tabelle non trovate. Lancia prima: /api/migrate_products.php</div>'; nc_admin_bottom(); exit; }

if ($formView):
?>
<div class="hd">
  <div><h1><?= $edit['id'] ? 'Modifica prodotto' : 'Nuovo prodotto' ?></h1><p class="sub">Scheda prodotto</p></div>
  <a class="btn ghost" href="prodotti.php"><i class="ti ti-arrow-left"></i> Torna ai prodotti</a>
</div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card">
  <input type="hidden" name="action" value="product_save">
  <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
  <input type="hidden" name="image_current" value="<?= h($edit['image']) ?>">
  <div class="row">
    <div class="field"><label>Famiglia</label><select name="family_id"><?php foreach ($families as $f): ?><option value="<?= $f['id'] ?>" <?= $f['id'] == $edit['family_id'] ? 'selected' : '' ?>><?= h($f['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Modello (senza “BiancoDigitale”)</label><input type="text" name="model" value="<?= h($edit['model']) ?>" required></div>
  </div>
  <div class="field"><label>Categoria</label><input type="text" name="category" value="<?= h($edit['category']) ?>"></div>
  <div class="row">
    <div class="field"><label>Velocità <span style="color:#8a9184;font-weight:400">(vuoto = nascosto)</span></label><input type="text" name="speed" value="<?= h($edit['speed']) ?>" placeholder="Fino a 70 ppm"></div>
    <div class="field"><label>Ciclo mensile <span style="color:#8a9184;font-weight:400">(vuoto = nascosto)</span></label><input type="text" name="cycle" value="<?= h($edit['cycle']) ?>" placeholder="Fino a 300.000 pag/mese"></div>
  </div>
  <div class="field"><label>Caratteristiche (una per riga)</label><textarea name="bullets" style="min-height:100px"><?= h($edit['bullets']) ?></textarea></div>
  <div class="field">
    <label>Immagine</label>
    <input type="hidden" name="image_picked" id="image_picked" value="">
    <div style="margin-bottom:8px;display:flex;align-items:center;gap:12px">
      <img id="imgPreview" src="../<?= h($edit['image'] ?: 'assets/product-placeholder.png') ?>" style="height:56px;border:1px solid #e3e7de;border-radius:8px;background:#fff;object-fit:contain">
      <?php if ($edit['image']): ?><label style="font-weight:400;font-size:13px;margin:0"><input type="checkbox" name="remove_image" value="1"> rimuovi immagine (usa segnaposto)</label><?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="file" name="image" accept="image/png,image/jpeg,image/webp" style="flex:1;min-width:200px" onchange="if(this.files[0]){document.getElementById('imgPreview').src=URL.createObjectURL(this.files[0]);document.getElementById('image_picked').value='';}">
      <button type="button" class="btn sm ghost" onclick="VBpickMedia(function(p){document.getElementById('image_picked').value=p;document.getElementById('imgPreview').src='../'+p;var r=document.querySelector('[name=remove_image]');if(r)r.checked=false;})"><i class="ti ti-photo"></i> Scegli dalla libreria</button>
    </div>
    <div style="font-size:12px;color:#8a9184;margin-top:5px">Carica un file, scegli dalla libreria, o lascia com'è. Senza immagine appare un segnaposto.</div>
  </div>
  <div class="field">
    <label>Brochure PDF <span style="color:#8a9184;font-weight:400">(facoltativa)</span></label>
    <input type="hidden" name="brochure_current" value="<?= h($edit['brochure'] ?? '') ?>">
    <?php if (!empty($edit['brochure'])): ?><div style="margin-bottom:8px;display:flex;align-items:center;gap:12px;font-size:13px"><a class="lnk" href="../<?= h($edit['brochure']) ?>" target="_blank"><i class="ti ti-file-type-pdf"></i> brochure attuale</a><label style="font-weight:400;margin:0"><input type="checkbox" name="remove_brochure" value="1"> rimuovi</label></div><?php endif; ?>
    <input type="file" name="brochure" accept="application/pdf">
    <div style="font-size:12px;color:#8a9184;margin-top:5px">Se presente, sulla scheda appare “Scarica la brochure (PDF)”.</div>
  </div>
  <div class="field">
    <label>Galleria fotografica <span style="color:#8a9184;font-weight:400">(foto aggiuntive, mostrate in una lightbox al clic sull'immagine)</span></label>
    <?php if (!prod_gallery_ready()): ?>
      <div class="msg err">Colonna non trovata. Lancia prima: <a class="lnk" href="../api/migrate_galleries.php" target="_blank">/api/migrate_galleries.php</a></div>
    <?php else: ?>
      <?= vb_repeater_field('gallery_images', PROD_GALLERY_FIELDS, $edit['gallery_images'] ?? '') ?>
    <?php endif; ?>
  </div>
  <div class="row">
    <div class="field"><label>Ordine</label><input type="number" name="sort" value="<?= (int)$edit['sort'] ?>"></div>
    <div class="field"><label>Stato</label><label style="font-weight:400;font-size:14px"><input type="checkbox" name="active" <?= $edit['active'] ? 'checked' : '' ?>> visibile sul sito</label></div>
  </div>
  <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Salva prodotto</button>
</form>
<?php require_once __DIR__ . '/../inc/media_picker.php'; nc_media_picker(); vb_repeater_assets(); ?>
<?php
else:
?>
<div class="hd">
  <div><h1>Prodotti</h1><p class="sub">Gamma, famiglie e schede</p></div>
  <div style="display:flex;gap:8px">
    <a class="btn ghost" href="../preview.php?p=prodotti" target="_blank"><i class="ti ti-eye"></i> Anteprima</a>
    <form method="post" style="margin:0"><input type="hidden" name="action" value="publish"><button class="btn" type="submit"><i class="ti ti-cloud-upload"></i> Pubblica pagina</button></form>
  </div>
</div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>
<div class="msg" style="background:#eef3fb;border:1px solid #cfe0f5;color:#2b4a7a">Dopo aver modificato prodotti o famiglie, premi <strong>Pubblica pagina</strong> per aggiornare il sito.</div>

<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px"><div class="sec" style="margin:0">Famiglie</div><span style="font-size:12px;color:#8a9184"><i class="ti ti-arrows-move" style="vertical-align:-2px"></i> trascina per riordinare</span></div>
  <div id="famList">
  <?php foreach ($families as $f): ?>
    <form method="post" data-id="<?= (int)$f['id'] ?>" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap">
      <input type="hidden" name="action" value="family_save"><input type="hidden" name="id" value="<?= $f['id'] ?>">
      <span class="vb-drag" style="cursor:grab;color:#b0b5a8;padding:0 2px" title="trascina"><i class="ti ti-grip-vertical"></i></span>
      <input type="text" name="name" value="<?= h($f['name']) ?>" style="flex:0 0 190px">
      <input type="text" name="tagline" value="<?= h($f['tagline']) ?>" style="flex:1;min-width:200px">
      <input type="hidden" name="sort" value="<?= (int)$f['sort'] ?>">
      <button class="btn sm ghost" type="submit">Salva</button>
      <button class="btn sm danger" type="submit" name="action" value="family_delete" onclick="return confirm('Eliminare la famiglia e TUTTI i suoi prodotti?')">Elimina</button>
    </form>
  <?php endforeach; ?>
  </div>
  <form method="post" style="display:flex;gap:8px;align-items:center;margin-top:12px;border-top:1px solid #eef1ec;padding-top:12px;flex-wrap:wrap">
    <input type="hidden" name="action" value="family_save">
    <input type="text" name="name" placeholder="Nuova famiglia" style="flex:0 0 200px" required>
    <input type="text" name="tagline" placeholder="Sottotitolo" style="flex:1;min-width:200px">
    <button class="btn sm" type="submit"><i class="ti ti-plus"></i> Aggiungi famiglia</button>
  </form>
</div>

<?php foreach ($families as $f): $prods = prod_products($f['id']); ?>
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
      <div class="sec" style="margin:0"><?= h($f['name']) ?> <span style="color:#8a9184;font-weight:400;font-size:13px">(<?= count($prods) ?>)</span></div>
      <a class="btn sm" href="prodotti.php?new=1&family=<?= $f['id'] ?>"><i class="ti ti-plus"></i> Prodotto</a>
    </div>
    <table>
      <thead><tr><th style="width:28px"></th><th style="width:60px"></th><th>Modello</th><th>Categoria</th><th>Stato</th><th></th></tr></thead>
      <tbody class="prodList" data-family="<?= (int)$f['id'] ?>">
      <?php if (!$prods): ?><tr><td colspan="6" style="color:#8a9184">Nessun prodotto in questa famiglia.</td></tr><?php endif; ?>
      <?php foreach ($prods as $p): ?>
        <tr data-id="<?= (int)$p['id'] ?>">
          <td class="vb-drag" style="cursor:grab;color:#b0b5a8;text-align:center"><i class="ti ti-grip-vertical"></i></td>
          <td><img src="../<?= h($p['image'] ?: 'assets/product-placeholder.png') ?>" style="height:34px;max-width:54px;object-fit:contain"></td>
          <td style="font-weight:600">BiancoDigitale <?= h($p['model']) ?></td>
          <td style="color:#6a7266"><?= h($p['category']) ?></td>
          <td><?= $p['active'] ? '<span style="color:#1F7A3D;font-size:12px">visibile</span>' : '<span style="color:#b0b5a8;font-size:12px">nascosto</span>' ?></td>
          <td style="white-space:nowrap">
            <a class="btn sm ghost" href="prodotti.php?edit=<?= $p['id'] ?>"><i class="ti ti-edit"></i></a>
            <form method="post" style="display:inline" onsubmit="return confirm('Eliminare questo prodotto?')"><input type="hidden" name="action" value="product_delete"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn sm danger" type="submit"><i class="ti ti-trash"></i></button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>

<div id="vbToast" style="display:none;position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:#1e2418;color:#fff;padding:10px 18px;border-radius:100px;font-size:13px;font-weight:600;z-index:3000;box-shadow:0 8px 24px rgba(0,0,0,.2)"></div>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(function(){
  function toast(t,ok){var e=document.getElementById('vbToast');e.textContent=t;e.style.background=ok?'#1F7A3D':'#b23a3a';e.style.display='block';clearTimeout(e._t);e._t=setTimeout(function(){e.style.display='none';},1800);}
  function persist(type,ids){
    var b=new URLSearchParams();b.append('action','reorder');b.append('type',type);b.append('ids',ids.join(','));
    fetch('prodotti.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b})
      .then(function(r){return r.json();}).then(function(j){toast(j.ok?'Ordine salvato ✓ — ricordati di “Pubblica pagina”.':'Errore nel riordino',j.ok);})
      .catch(function(){toast('Errore di rete',false);});
  }
  if(typeof Sortable==='undefined')return;
  var fam=document.getElementById('famList');
  if(fam)new Sortable(fam,{handle:'.vb-drag',animation:150,onEnd:function(){
    persist('families',[].map.call(fam.querySelectorAll('form[data-id]'),function(f){return f.dataset.id;}));}});
  document.querySelectorAll('tbody.prodList').forEach(function(tb){
    new Sortable(tb,{handle:'.vb-drag',animation:150,onEnd:function(){
      persist('products',[].map.call(tb.querySelectorAll('tr[data-id]'),function(tr){return tr.dataset.id;}));}});
  });
})();
</script>
<?php endif; ?>
<?php nc_admin_bottom();
