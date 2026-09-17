<?php
// VelociBuilder LITE — Opzioni globali (social, Google Maps, e ogni data-vb-opt del Design).
// I valori valgono OVUNQUE quei link appaiono nel sito; "Salva e pubblica" li applica subito.
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/options.php';
require_once __DIR__ . '/../inc/cms.php';

try { db()->exec('CREATE TABLE IF NOT EXISTS cms_settings (skey VARCHAR(64) PRIMARY KEY, svalue TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); } catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  foreach (opt_all_grouped() as $opts) {
    foreach ($opts as $key => $meta) {
      if (isset($_POST['opt'][$key])) opt_set($key, trim((string) $_POST['opt'][$key]));
    }
  }
  $pub = 0; $errs = [];
  if (($_POST['action'] ?? '') === 'save_publish') {
    foreach (array_keys(cms_pages()) as $slug) {
      try { cms_publish($slug); $pub++; } catch (Throwable $e) { $errs[] = $slug . ': ' . $e->getMessage(); }
    }
  }
  $q = 'ok=1' . ($pub ? '&pub=' . $pub : '') . (count($errs) ? '&err=' . rawurlencode(implode('; ', $errs)) : '');
  header('Location: opzioni.php?' . $q); exit;
}

require_once __DIR__ . '/../inc/admin_layout.php';
$groups = opt_all_grouped();
$total = array_sum(array_map('count', $groups));

nc_admin_top('opzioni', 'Opzioni — VelociBuilder LITE');
?>
<div class="hd">
  <div>
    <h1>Opzioni globali</h1>
    <p class="sub">Link social, Google Maps e altri valori globali riconosciuti dal Design. Valgono ovunque appaiano nel sito.</p>
  </div>
</div>

<?php if (isset($_GET['ok'])): ?>
  <div class="msg ok">Opzioni salvate.<?= isset($_GET['pub']) ? ' ' . (int)$_GET['pub'] . ' pagine ripubblicate.' : '' ?></div>
<?php endif; ?>
<?php if (!empty($_GET['err'])): ?><div class="msg err">Errori in pubblicazione: <?= h($_GET['err']) ?></div><?php endif; ?>

<?php if ($total === 0): ?>
  <div class="card">
    <p style="margin:0;color:#5a6255;font-size:14px">Nessuna opzione rilevata. Le opzioni compaiono qui quando il Design contiene <strong>link social</strong> (Facebook, Instagram, LinkedIn, ecc.), un <strong>link a Google Maps</strong>, oppure elementi marcati <code>data-vb-opt="chiave"</code>. Dopo aver aggiornato il Design, rilancia il build.</p>
  </div>
<?php else: ?>
<form method="post">
  <?php foreach ($groups as $group => $opts): ?>
  <div class="card">
    <div class="sec" style="margin-top:0"><?= h($group) ?></div>
    <?php foreach ($opts as $key => $meta): ?>
      <div class="field">
        <label><?= h($meta['label']) ?> <span style="color:#9aa093;font-weight:400;font-size:12px">(<?= h($key) ?>)</span></label>
        <input type="text" name="opt[<?= h($key) ?>]" value="<?= h($meta['value']) ?>" placeholder="<?= h($meta['default']) ?>">
      </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <div style="display:flex;gap:12px;flex-wrap:wrap">
    <button class="btn" type="submit" name="action" value="save_publish"><i class="ti ti-rocket"></i> Salva e pubblica</button>
    <button class="btn ghost" type="submit" name="action" value="save">Salva soltanto</button>
  </div>
  <p class="sub" style="margin-top:12px">"Salva soltanto" registra i valori; per vederli online usa "Salva e pubblica" (rigenera le pagine statiche).</p>
</form>
<?php endif; ?>

<?php nc_admin_bottom(); ?>
