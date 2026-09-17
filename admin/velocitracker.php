<?php
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/velocitracker.php';

$FIELDS = ['vt_enabled','vt_base_url','vt_token','vt_form_contatti','vt_form_guida','vt_pipeline_id','vt_pipeline_punto_id',
  'vt_anag_stato_id','vt_tratt_stato_id','vt_campagna_id','vt_categoria','vt_autore_id','vt_utente_id','vt_importo','vt_opportunita'];
$CHECKS = ['vt_enabled','vt_form_contatti','vt_form_guida','vt_opportunita'];

$ready = settings_ready();
$msg = ''; $msgType = 'ok'; $testResult = null;
$act = $_POST['action'] ?? '';
if ($ready && $act === 'save') {
  try {
    foreach ($FIELDS as $f) {
      $v = in_array($f, $CHECKS, true) ? (isset($_POST[$f]) ? '1' : '0') : trim($_POST[$f] ?? '');
      setting_set($f, $v);
    }
    $msg = 'Impostazioni salvate ✓';
  } catch (Throwable $e) { $msgType = 'err'; $msg = $e->getMessage(); }
} elseif ($ready && $act === 'test') {
  // salva prima base/token così il test usa i valori a schermo
  try { setting_set('vt_base_url', trim($_POST['vt_base_url'] ?? '')); setting_set('vt_token', trim($_POST['vt_token'] ?? '')); } catch (Throwable $e) {}
  $testResult = vt_test_connection();
}

$cfg = fn($k, $d = '') => setting_get($k, $d);

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('velocitracker', 'VelociTracker — VelociBuilder LITE');
?>
<div class="hd">
  <div><h1>VelociTracker (CRM)</h1><p class="sub">I form del sito creano anagrafiche e opportunità nel CRM</p></div>
</div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>
<?php if (!$ready): ?>
  <div class="msg err">Tabelle non trovate. Lancia prima: <a class="lnk" href="../api/migrate_velocitracker.php" target="_blank">/api/migrate_velocitracker.php</a></div>
<?php else: ?>

<?php if ($testResult !== null): ?>
  <div class="msg <?= $testResult['ok'] ? 'ok' : 'err' ?>">
    <?= $testResult['ok'] ? '✓ Connessione riuscita — token valido, API raggiungibile.' : '✗ Connessione fallita (HTTP ' . h($testResult['status']) . ') ' . h($testResult['error']) ?>
  </div>
<?php endif; ?>

<?php
  // menu a tendina popolati dall'API (best-effort)
  $statiAnag = []; $statiTratt = []; $punti = [];
  if (trim($cfg('vt_base_url')) !== '' && trim($cfg('vt_token')) !== '') {
    try { $statiAnag = vt_stati_anagrafiche(); } catch (Throwable $e) {}
    try { $statiTratt = vt_stati_trattative(); } catch (Throwable $e) {}
    try { $punti = vt_pipeline_punti($cfg('vt_pipeline_id', '1')); } catch (Throwable $e) {}
  }
  $selOpts = function ($rows, $current) {
    $out = '<option value="">— (nessuno / lascia decidere al CRM) —</option>';
    foreach ($rows as $r) {
      $id = $r['id'] ?? ''; $lab = $r['nome'] ?? ($r['descrizione'] ?? $id);
      $out .= '<option value="' . h($id) . '"' . ((string)$current === (string)$id ? ' selected' : '') . '>' . h($lab) . ' (#' . h($id) . ')</option>';
    }
    return $out;
  };
?>

<form method="post">
  <div class="card">
    <label style="display:flex;align-items:center;gap:10px;font-size:15px;margin:0">
      <input type="checkbox" name="vt_enabled" value="1" <?= $cfg('vt_enabled') === '1' ? 'checked' : '' ?> style="width:auto">
      <strong>Integrazione attiva</strong> — quando spenta i form funzionano normalmente ma non inviano nulla al CRM
    </label>
  </div>

  <div class="card">
    <div class="sec" style="margin-top:0"><i class="ti ti-plug-connected" style="vertical-align:-2px"></i> Connessione API</div>
    <div class="field"><label>Base URL API</label><input type="text" name="vt_base_url" value="<?= h($cfg('vt_base_url')) ?>" placeholder="https://.../api/v2"></div>
    <div class="field"><label>Bearer token</label><input type="text" name="vt_token" value="<?= h($cfg('vt_token')) ?>" placeholder="token di accesso"></div>
    <button class="btn ghost" type="submit" name="action" value="test"><i class="ti ti-refresh"></i> Salva URL/token e testa connessione</button>
  </div>

  <div class="card">
    <div class="sec" style="margin-top:0"><i class="ti ti-forms" style="vertical-align:-2px"></i> Form collegati</div>
    <label style="font-weight:400;font-size:14px;display:block;margin-bottom:8px"><input type="checkbox" name="vt_form_contatti" value="1" <?= $cfg('vt_form_contatti','1')==='1'?'checked':'' ?> style="width:auto"> Form <strong>Contatti</strong></label>
    <label style="font-weight:400;font-size:14px;display:block"><input type="checkbox" name="vt_form_guida" value="1" <?= $cfg('vt_form_guida','1')==='1'?'checked':'' ?> style="width:auto"> Landing <strong>Guida</strong></label>
  </div>

  <div class="card">
    <div class="sec" style="margin-top:0"><i class="ti ti-git-branch" style="vertical-align:-2px"></i> Opportunità (trattativa)</div>
    <div class="row">
      <div class="field"><label>Pipeline ID</label><input type="number" name="vt_pipeline_id" value="<?= h($cfg('vt_pipeline_id','1')) ?>"><div style="font-size:12px;color:#8a9184;margin-top:4px">Salva per aggiornare l'elenco dei punti qui a fianco.</div></div>
      <div class="field"><label>Punto pipeline iniziale</label>
        <?php if ($punti): ?><select name="vt_pipeline_punto_id"><?= $selOpts($punti, $cfg('vt_pipeline_punto_id','1')) ?></select>
        <?php else: ?><input type="number" name="vt_pipeline_punto_id" value="<?= h($cfg('vt_pipeline_punto_id','1')) ?>"><?php endif; ?>
      </div>
    </div>
    <div class="row">
      <div class="field"><label>Stato trattativa</label>
        <?php if ($statiTratt): ?><select name="vt_tratt_stato_id"><?= $selOpts($statiTratt, $cfg('vt_tratt_stato_id')) ?></select>
        <?php else: ?><input type="text" name="vt_tratt_stato_id" value="<?= h($cfg('vt_tratt_stato_id')) ?>" placeholder="id stato (facoltativo)"><?php endif; ?>
      </div>
      <div class="field"><label>Importo di default</label><input type="text" name="vt_importo" value="<?= h($cfg('vt_importo')) ?>" placeholder="vuoto = nessuno"></div>
    </div>
    <label style="font-weight:400;font-size:14px"><input type="checkbox" name="vt_opportunita" value="1" <?= $cfg('vt_opportunita','1')==='1'?'checked':'' ?> style="width:auto"> Segna la trattativa come opportunità</label>
  </div>

  <div class="card">
    <div class="sec" style="margin-top:0"><i class="ti ti-user-plus" style="vertical-align:-2px"></i> Nuova anagrafica (bozza)</div>
    <p style="font-size:12.5px;color:#8a9184;margin:0 0 14px">Usati solo quando l'email <em>non</em> esiste già e va creata una bozza. Il nome dal form viene diviso in nome/cognome; l'eventuale “azienda” finisce nella descrizione.</p>
    <div class="row">
      <div class="field"><label>Stato anagrafica</label>
        <?php if ($statiAnag): ?><select name="vt_anag_stato_id"><?= $selOpts($statiAnag, $cfg('vt_anag_stato_id')) ?></select>
        <?php else: ?><input type="text" name="vt_anag_stato_id" value="<?= h($cfg('vt_anag_stato_id')) ?>" placeholder="id stato (facoltativo)"><?php endif; ?>
      </div>
      <div class="field"><label>Campagna ID</label><input type="text" name="vt_campagna_id" value="<?= h($cfg('vt_campagna_id')) ?>" placeholder="facoltativo"></div>
    </div>
    <div class="row">
      <div class="field"><label>Categoria</label><input type="text" name="vt_categoria" value="<?= h($cfg('vt_categoria')) ?>" placeholder="facoltativo"></div>
      <div class="field"><label>Autore ID <span style="color:#8a9184;font-weight:400">(proprietario del lead)</span></label><input type="text" name="vt_autore_id" value="<?= h($cfg('vt_autore_id')) ?>" placeholder="facoltativo"></div>
    </div>
    <div class="field"><label>Utente ID assegnatario trattativa</label><input type="text" name="vt_utente_id" value="<?= h($cfg('vt_utente_id')) ?>" placeholder="facoltativo"></div>
  </div>

  <div style="display:flex;gap:10px;position:sticky;bottom:0;background:#f4f6f3;padding:12px 0">
    <button class="btn" type="submit" name="action" value="save"><i class="ti ti-device-floppy"></i> Salva impostazioni</button>
  </div>
</form>

<?php
  $logs = [];
  try { $logs = db()->query('SELECT * FROM cms_vt_log ORDER BY id DESC LIMIT 25')->fetchAll(); } catch (Throwable $e) {}
?>
<div class="card">
  <div class="sec" style="margin-top:0"><i class="ti ti-history" style="vertical-align:-2px"></i> Ultimi invii al CRM</div>
  <?php if (!$logs): ?>
    <p style="color:#8a9184;margin:0">Nessun invio ancora registrato.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Quando</th><th>Form</th><th>Email</th><th>Anagrafica</th><th>Trattativa</th><th>Esito</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
      <tr>
        <td style="white-space:nowrap"><?= h(date('d/m H:i', strtotime($l['created_at']))) ?></td>
        <td><?= h($l['form']) ?></td>
        <td style="color:#6a7266"><?= h($l['email']) ?></td>
        <td><?= $l['azienda_id'] ? '#' . h($l['azienda_id']) . ($l['created_new'] ? ' <span style="color:#1F7A3D;font-size:11px">(nuova)</span>' : ' <span style="color:#8a9184;font-size:11px">(esistente)</span>') : '—' ?></td>
        <td><?= $l['trattativa_id'] ? '#' . h($l['trattativa_id']) : '—' ?></td>
        <td><?= $l['ok'] ? '<span style="color:#1F7A3D">✓</span>' : '<span style="color:#b23a3a" title="' . h($l['error']) . '">✗ ' . h(mb_strimwidth((string)$l['error'], 0, 40, '…')) . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php nc_admin_bottom();
