<?php
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/cms.php';
require_once __DIR__ . '/../inc/pagebuilder.php';
$NAMES = ['home'=>'Home','processo'=>'Processo','prodotti'=>'Prodotti','sostenibilita'=>'Sostenibilità','rivenditori'=>'Rivenditori','contatti'=>'Contatti'];
$DESC  = ['home'=>'Hero, numeri, sezioni','processo'=>'Testi e fasi','prodotti'=>'Intro e schede','sostenibilita'=>'Bilancio ESG','rivenditori'=>'Vantaggi e testi','contatti'=>'Testi e recapiti'];
$pages = cms_pages();

$msg = ''; $msgType = 'ok';
if (($_POST['action'] ?? '') === 'new_custom' && pb_table_ready()) {
  try { $id = pb_page_create(trim($_POST['title'] ?? '')); header('Location: builder.php?page=' . (int)$id); exit; }
  catch (Throwable $e) { $msgType = 'err'; $msg = $e->getMessage(); }
} elseif (($_POST['action'] ?? '') === 'delete_custom') {
  try { pb_page_delete($_POST['id']); $msg = 'Pagina eliminata.'; } catch (Throwable $e) { $msgType = 'err'; $msg = $e->getMessage(); }
}

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('pagine', 'Pagine — VelociBuilder LITE');
?>
<div class="hd"><div><h1>Pagine</h1><p class="sub">Modifica i testi e pubblica</p></div></div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>
<?php if (!$pages): ?>
  <div class="msg err">Nessuna pagina nel manifest. Rilancia: <code>node build/build.js</code></div>
<?php else: ?>
<div class="card" style="padding:8px">
  <?php foreach ($pages as $slug => $p): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-bottom:1px solid #eef1ec">
      <div><div style="font-weight:600"><?= h($NAMES[$slug] ?? $slug) ?></div><div style="color:#8a9184;font-size:12.5px"><?= h($DESC[$slug] ?? '') ?> · <?= count($p['fields']) ?> testi</div></div>
      <div style="display:flex;gap:6px">
        <a class="btn sm ghost" href="../preview.php?p=<?= h($slug) ?>" target="_blank"><i class="ti ti-eye"></i></a>
        <a class="btn sm" href="pagina.php?p=<?= h($slug) ?>"><i class="ti ti-edit"></i> Modifica</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="hd" style="margin-top:8px"><div><h1 style="font-size:18px">Pagine libere <span style="color:#8a9184;font-weight:400;font-size:13px">(a blocchi, senza Design)</span></h1></div></div>
<?php if (!pb_table_ready()): ?>
  <div class="msg err">Tabelle non trovate. Lancia prima: <a class="lnk" href="../api/migrate_pagebuilder.php" target="_blank">/api/migrate_pagebuilder.php</a></div>
<?php else: ?>
<div class="card" style="padding:8px">
  <?php $customs = pb_pages_all(); if (!$customs): ?>
    <p style="color:#8a9184;text-align:center;padding:18px;margin:0">Nessuna pagina libera ancora. Creane una qui sotto.</p>
  <?php endif; ?>
  <?php foreach ($customs as $c): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-bottom:1px solid #eef1ec">
      <div><div style="font-weight:600"><?= h($c['title']) ?></div><div style="color:#8a9184;font-size:12.5px"><?= h($c['slug']) ?>.html · <?= $c['status'] === 'published' ? '<span style="color:#1F7A3D">pubblicata</span>' : 'bozza' ?></div></div>
      <div style="display:flex;gap:6px">
        <?php if ($c['status'] === 'published'): ?><a class="btn sm ghost" href="../<?= h($c['slug']) ?>.html" target="_blank"><i class="ti ti-eye"></i></a><?php endif; ?>
        <a class="btn sm" href="builder.php?page=<?= (int)$c['id'] ?>"><i class="ti ti-edit"></i> Modifica</a>
        <form method="post" style="margin:0" onsubmit="return confirm('Eliminare questa pagina e i suoi blocchi?')"><input type="hidden" name="action" value="delete_custom"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn sm danger" type="submit"><i class="ti ti-trash"></i></button></form>
      </div>
    </div>
  <?php endforeach; ?>
  <form method="post" style="display:flex;gap:8px;padding:12px 14px">
    <input type="hidden" name="action" value="new_custom">
    <input type="text" name="title" placeholder="Titolo della nuova pagina" style="flex:1" required>
    <button class="btn sm" type="submit"><i class="ti ti-plus"></i> Nuova pagina libera</button>
  </form>
</div>
<?php endif; ?>
<?php nc_admin_bottom();
