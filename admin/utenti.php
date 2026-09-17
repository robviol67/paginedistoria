<?php
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/recipients.php';

$msg = ''; $msgType = 'ok';
$act = $_POST['action'] ?? '';
try {
  if ($act === 'create') {
    $un = trim($_POST['username'] ?? ''); $pw = $_POST['password'] ?? ''; $nm = trim($_POST['name'] ?? '');
    if ($un === '' || strlen($pw) < 4) throw new Exception('Username e password (min 4 caratteri) obbligatori.');
    nc_user_create($un, $pw, $nm ?: $un, 'admin');
    $msg = 'Utente creato ✓';
  } elseif ($act === 'password' && !empty($_POST['id'])) {
    if (strlen($_POST['password'] ?? '') < 4) throw new Exception('Password troppo corta (min 4).');
    nc_user_set_password($_POST['id'], $_POST['password']);
    $msg = 'Password aggiornata ✓';
  } elseif ($act === 'delete' && !empty($_POST['id'])) {
    $me = nc_current_user();
    if ((int)$_POST['id'] === (int)($me['id'] ?? -1)) throw new Exception('Non puoi eliminare l\'utente con cui sei loggato.');
    nc_user_delete($_POST['id']);
    $msg = 'Utente eliminato.';
  } elseif ($act === 'notify_admin') {
    // chi riceve le richieste dei moduli e dell'assistente quando non hanno un destinatario proprio
    $pick = strtolower(trim((string)($_POST['notify_admin'] ?? '')));
    if ($pick !== '' && !in_array($pick, nc_admin_emails(), true)) throw new Exception('Scegli uno degli admin con un indirizzo email valido.');
    setting_set('notify_admin', $pick);
    header('Location: utenti.php?ok=notify'); exit; // ricarica: setting_get tiene una cache per richiesta
  }
} catch (Throwable $e) { $msgType = 'err'; $msg = $e->getMessage(); }
if (!$msg && ($_GET['ok'] ?? '') === 'notify') $msg = 'Destinatario delle richieste salvato ✓';

$users = nc_users_all();
require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('utenti', 'Utenti — VelociBuilder LITE');
?>
<div class="hd"><div><h1>Utenti</h1><p class="sub">Accessi al pannello (password cifrate)</p></div></div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<?php if (nc_users_count() <= 0): ?>
  <div class="msg err">Nessun utente/tabella. Lancia prima: /api/migrate_velocibuilder.php (crea admin / test)</div>
<?php endif; ?>

<div class="card" style="padding:0;overflow:hidden;margin-bottom:18px">
  <table>
    <thead><tr><th>Utente</th><th>Nome</th><th>Ruolo</th><th>Creato</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td style="font-weight:600"><?= h($u['username']) ?></td>
        <td><?= h($u['name']) ?></td>
        <td><?= h($u['role']) ?></td>
        <td style="color:#6a7266;white-space:nowrap"><?= h($u['created_at']) ?></td>
        <td style="white-space:nowrap">
          <form method="post" style="display:inline-flex;gap:6px;align-items:center;margin:0">
            <input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="text" name="password" placeholder="nuova password" style="width:130px;padding:6px 9px">
            <button class="btn sm ghost" type="submit">Cambia</button>
          </form>
          <form method="post" style="display:inline;margin:0" onsubmit="return confirm('Eliminare questo utente?')">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn sm danger" type="submit">Elimina</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php
$adminEmails = nc_admin_emails();
$chosen = strtolower(trim((string)setting_get('notify_admin', '')));
$def = nc_default_recipient_info();
?>
<div class="card" style="margin-bottom:18px">
  <div class="sec" style="margin-top:0"><i class="ti ti-mail-forward" style="vertical-align:-2px"></i> Destinatario delle richieste</div>
  <p style="font-size:13.5px;color:#4a5246;margin:0 0 12px;line-height:1.55">Riceve le richieste dei <a class="lnk" href="forms.php">Moduli</a> e i contatti raccolti dall'<a class="lnk" href="assistente.php">Assistente AI</a> quando non hanno un destinatario proprio. Con un solo admin è lui; con più admin scegli qui quale.</p>
  <?php if (!$adminEmails): ?>
    <div class="msg err" style="margin:0">Nessun admin ha un'email come username: le richieste senza destinatario vanno a <b><?= h($def['email'] ?: 'nessuno') ?></b> (mail-config.php).</div>
  <?php else: ?>
  <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="action" value="notify_admin">
    <select name="notify_admin" style="flex:1;min-width:220px">
      <option value=""><?= count($adminEmails) === 1 ? 'Automatico (unico admin)' : '— scegli un admin —' ?></option>
      <?php foreach ($adminEmails as $ae): ?><option value="<?= h($ae) ?>" <?= $chosen === $ae ? 'selected' : '' ?>><?= h($ae) ?></option><?php endforeach; ?>
    </select>
    <button class="btn sm" type="submit"><i class="ti ti-device-floppy"></i> Salva</button>
  </form>
  <p style="font-size:12.5px;color:#8a9184;margin:8px 0 0">Oggi vanno a: <b><?= h($def['email'] ?: 'nessuno') ?></b><?= $def['source'] === 'mail-config' ? ' (nessun admin scelto: si usa l\'indirizzo di mail-config.php)' : '' ?></p>
  <?php endif; ?>
</div>

<div class="card">
  <div class="sec" style="margin-top:0">Nuovo utente</div>
  <form method="post">
    <input type="hidden" name="action" value="create">
    <div class="row">
      <div class="field"><label>Username</label><input type="text" name="username" required></div>
      <div class="field"><label>Nome</label><input type="text" name="name"></div>
    </div>
    <div class="field"><label>Password</label><input type="text" name="password" required placeholder="min 4 caratteri"></div>
    <button class="btn" type="submit"><i class="ti ti-user-plus"></i> Crea utente</button>
  </form>
</div>
<?php nc_admin_bottom();
