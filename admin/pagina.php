<?php
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/cms.php';

$NAMES = ['home'=>'Home','processo'=>'Processo','prodotti'=>'Prodotti','sostenibilita'=>'Sostenibilità','rivenditori'=>'Rivenditori','contatti'=>'Contatti'];
$slug = preg_replace('/[^a-z0-9_-]/', '', $_GET['p'] ?? 'home');
$page = cms_page($slug);

$msg = ''; $msgType = 'ok';
if ($page && in_array(($_POST['action'] ?? ''), ['save', 'publish'], true)) {
  try {
    cms_save_page($slug, $_POST);
    if ($_POST['action'] === 'publish') { cms_publish($slug); $msg = 'Salvato e pubblicato ✓ — la pagina è aggiornata sul sito.'; }
    else $msg = 'Bozza salvata ✓ — vedila in anteprima, poi “Pubblica”.';
  } catch (Throwable $e) { $msgType = 'err'; $msg = 'Errore: ' . $e->getMessage(); }
}

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('pagine', ($NAMES[$slug] ?? $slug) . ' — VelociBuilder LITE');

if (!$page) { echo '<div class="msg err">Pagina non trovata. Rilancia il build.</div>'; nc_admin_bottom(); exit; }
$vals = cms_values($slug);
?>
<div class="hd">
  <div><h1><?= h($NAMES[$slug] ?? $slug) ?></h1><p class="sub"><?= count($page['fields']) ?> testi modificabili</p></div>
  <div style="display:flex;gap:8px">
    <a class="btn ghost" href="pagine.php"><i class="ti ti-arrow-left"></i> Pagine</a>
    <a class="btn ghost" href="../preview.php?p=<?= h($slug) ?>" target="_blank"><i class="ti ti-eye"></i> Anteprima</a>
  </div>
</div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<?php
  $seo = array_values(array_filter($page['fields'], fn($f) => strpos($f['key'], 'seo_') === 0));
  $content = array_values(array_filter($page['fields'], fn($f) => strpos($f['key'], 'seo_') !== 0));
  $renderField = function ($f) use ($vals) {
    $v = $vals[$f['key']] ?? ''; $lab = $f['label'];
    echo '<div class="fld" data-t="' . h(mb_strtolower($lab . ' ' . $v)) . '" style="margin-bottom:14px">';
    echo '<label style="color:#8a9184;font-weight:500;font-size:12px">' . h($lab) . '</label>';
    if (($f['type'] ?? 'text') === 'textarea') echo '<textarea name="' . h($f['key']) . '">' . h($v) . '</textarea>';
    else echo '<input type="text" name="' . h($f['key']) . '" value="' . h($v) . '">';
    echo '</div>';
  };
?>
<form method="post">
  <?php if ($seo): ?>
  <div class="card">
    <div class="sec" style="margin-top:0"><i class="ti ti-share" style="vertical-align:-2px"></i> SEO e condivisione</div>
    <p style="font-size:12.5px;color:#8a9184;margin:0 0 14px">Come appare la pagina su Google e quando la condividi (WhatsApp, LinkedIn, Facebook).</p>
    <?php foreach ($seo as $f) $renderField($f); ?>
  </div>
  <?php endif; ?>

  <div style="position:relative;margin-bottom:14px">
    <input id="filtro" type="text" placeholder="Cerca un testo da modificare…" style="padding-left:38px">
    <i class="ti ti-search" style="position:absolute;left:13px;top:13px;color:#8a9184"></i>
  </div>
  <div class="card">
    <div class="sec" style="margin-top:0"><i class="ti ti-align-left" style="vertical-align:-2px"></i> Testi della pagina</div>
    <?php foreach ($content as $f) $renderField($f); ?>
  </div>

  <div style="display:flex;gap:10px;margin-top:8px;position:sticky;bottom:0;background:#f4f6f3;padding:12px 0">
    <button class="btn ghost" type="submit" name="action" value="save">Salva bozza</button>
    <button class="btn" type="submit" name="action" value="publish"><i class="ti ti-cloud-upload"></i> Salva e pubblica</button>
  </div>
</form>
<script>
(function(){var i=document.getElementById('filtro');var f=document.querySelectorAll('.fld');
i.addEventListener('input',function(){var q=i.value.toLowerCase().trim();f.forEach(function(el){if(el.closest('.card').querySelector('.sec').textContent.indexOf('SEO')>-1)return;el.style.display=(!q||el.dataset.t.indexOf(q)>-1)?'':'none';});});})();
</script>
<?php nc_admin_bottom();
