<?php
// VelociBuilder LITE — Invio email: chiave SendGrid, mittente, verifica e prova d'invio.
// La chiave la incolla chi gestisce il sito; si salva in cms_settings e vince su mail-config.php.
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/../inc/mailer.php';
require_once __DIR__ . '/../inc/recipients.php';

try { db()->exec('CREATE TABLE IF NOT EXISTS cms_settings (skey VARCHAR(64) PRIMARY KEY, svalue TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); } catch (Throwable $e) {}

$act = $_POST['action'] ?? '';
if ($act === 'save') {
  $newKey = trim((string)($_POST['sendgrid_api_key'] ?? ''));
  if (!empty($_POST['remove_key'])) setting_set('sendgrid_api_key', '');
  elseif ($newKey !== '') {
    if (!preg_match('/^SG\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $newKey)) { header('Location: email.php?err=' . rawurlencode('La chiave non ha il formato SendGrid (SG.xxxx.yyyy): non salvata.')); exit; }
    setting_set('sendgrid_api_key', $newKey);
  }
  $from = trim((string)($_POST['mail_from'] ?? ''));
  if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) { header('Location: email.php?err=' . rawurlencode('Mittente non valido: non salvato.')); exit; }
  setting_set('mail_from', $from);
  setting_set('mail_from_name', trim((string)($_POST['mail_from_name'] ?? '')));
  header('Location: email.php?ok=save'); exit; // ricarica: le impostazioni hanno una cache per richiesta
}

$res = null; // esito di verifica / prova
if ($act === 'verify') {
  if (nc_mail_key() === '') $res = ['err', 'Nessuna chiave SendGrid impostata.'];
  else {
    // sandbox_mode: SendGrid controlla chiave e mittente ma NON consegna nulla
    [$code, $body] = nc_sendgrid_post([
      'personalizations' => [['to' => [['email' => nc_default_recipient() ?: nc_mail_from()]]]],
      'from' => ['email' => nc_mail_from(), 'name' => nc_mail_from_name()],
      'subject' => 'Verifica configurazione', 'content' => [['type' => 'text/plain', 'value' => 'sandbox']],
      'mail_settings' => ['sandbox_mode' => ['enable' => true]],
    ], nc_mail_key());
    if ($code >= 200 && $code < 300) $res = ['ok', 'SendGrid accetta la chiave e il mittente ' . nc_mail_from() . '. Le email partiranno da qui.'];
    elseif ($code === 401) $res = ['err', 'SendGrid rifiuta la chiave (401): è sbagliata, incompleta o revocata.'];
    elseif ($code === 403) $res = ['err', 'SendGrid rifiuta il mittente ' . nc_mail_from() . ' (403): va verificato in SendGrid → Settings → Sender Authentication, oppure scegli qui un mittente già verificato. Dettaglio: ' . substr($body, 0, 250)];
    else $res = ['err', 'Risposta SendGrid ' . $code . ': ' . substr((string)$body, 0, 300)];
  }
} elseif ($act === 'test') {
  $to = trim((string)($_POST['test_to'] ?? '')) ?: nc_default_recipient();
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) $res = ['err', 'Indirizzo di prova non valido.'];
  else {
    $site = defined('SITE_NAME') ? SITE_NAME : 'sito';
    $ok = nc_send_mail($to, 'Prova invio email — ' . $site, "Questa è un'email di prova inviata dal pannello (Invio email) il " . date('d/m/Y H:i') . ".\nSe la leggi, l'invio funziona.");
    $via = nc_mail_pronto() ? 'SendGrid' : 'invio diretto del server (mail)';
    $res = $ok ? ['ok', 'Email di prova inviata a ' . $to . ' via ' . $via . '. Controlla la casella (anche lo spam).'] : ['err', 'Invio non riuscito via ' . $via . '. ' . nc_mail_last_error()];
  }
}

$src = nc_mail_key_source();
$key = nc_mail_key();
$phpmail = defined('MAIL_USE_PHPMAIL') && MAIL_USE_PHPMAIL;
$def = nc_default_recipient_info();

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('email', 'Invio email — VelociBuilder LITE');
?>
<div class="hd"><div><h1>Invio email</h1><p class="sub">Come partono le email del sito: moduli, auto-risposte, lead dell'Assistente AI</p></div></div>

<div class="card" style="background:#f7f9f5">
  <div class="sec" style="margin-top:0"><i class="ti ti-route" style="vertical-align:-2px"></i> Come funziona</div>
  <ol style="margin:0;padding-left:20px;font-size:13.5px;line-height:1.6;color:#3f463b">
    <li><b>Il sito prepara l'email</b> quando qualcuno compila un <a class="lnk" href="forms.php">Modulo</a> o conclude una conversazione con l'<a class="lnk" href="assistente.php">Assistente AI</a>.</li>
    <li><b>La consegna a SendGrid</b>, il servizio che la spedisce davvero, usando la <b>chiave API</b> incollata qui sotto. Senza chiave qui vale quella di <code>inc/mail-config.php</code>; senza nessuna chiave parte l'invio diretto del server (se abilitato), che finisce più facilmente nello spam.</li>
    <li><b>SendGrid la spedisce a nome del Mittente</b>. Il mittente deve essere <b>verificato nel tuo account SendGrid</b> (Settings → Sender Authentication: il singolo indirizzo o l'intero dominio), altrimenti SendGrid rifiuta l'invio.</li>
    <li><b>Chi la riceve</b>: i destinatari scritti nel Modulo o nell'agente; se mancano, il destinatario predefinito scelto in <a class="lnk" href="utenti.php">Utenti</a>. Le auto-risposte vanno a chi ha compilato, e se risponde la sua email arriva a voi.</li>
    <li><b>Per controllare</b>: "Verifica" chiede a SendGrid se chiave e mittente vanno bene senza spedire nulla; "Invia email di prova" manda un'email vera. Se un invio fallisce la richiesta non si perde: resta sempre in <a class="lnk" href="messaggi.php">Messaggi</a>.</li>
  </ol>
</div>
<?php if (($_GET['ok'] ?? '') === 'save'): ?><div class="msg ok">Impostazioni salvate ✓ — usa "Verifica" per controllare chiave e mittente.</div><?php endif; ?>
<?php if (!empty($_GET['err'])): ?><div class="msg err"><?= h($_GET['err']) ?></div><?php endif; ?>
<?php if ($res): ?><div class="msg <?= $res[0] ?>"><?= h($res[1]) ?></div><?php endif; ?>

<div class="card">
  <div class="sec" style="margin-top:0"><i class="ti ti-activity" style="vertical-align:-2px"></i> Stato</div>
  <p style="margin:0 0 6px;font-size:14px">
    <?php if ($key !== ''): ?>
      <span style="color:#1F7A3D;font-weight:600">● Chiave SendGrid impostata</span> — usa "Verifica" per controllarla · chiave …<?= h(substr($key, -4)) ?> (da <?= $src === 'pannello' ? 'questa pagina' : 'inc/mail-config.php' ?>)
    <?php elseif ($phpmail): ?>
      <span style="color:#b7791f;font-weight:600">● Invio diretto del server</span> — nessuna chiave SendGrid: le email partono con mail() e possono finire nello spam
    <?php else: ?>
      <span style="color:#b23a3a;font-weight:600">● Nessun invio attivo</span> — senza chiave SendGrid le email NON partono
    <?php endif; ?>
  </p>
  <p style="margin:0;font-size:13px;color:#6a7266">Mittente: <b><?= h(nc_mail_from_name()) ?> &lt;<?= h(nc_mail_from()) ?>&gt;</b> · Destinatario predefinito delle richieste: <b><?= h($def['email'] ?: 'nessuno') ?></b> (<a class="lnk" href="utenti.php">Utenti</a>, <a class="lnk" href="forms.php">Moduli</a>)</p>
</div>

<div class="card">
  <div class="sec" style="margin-top:0"><i class="ti ti-key" style="vertical-align:-2px"></i> SendGrid</div>
  <form method="post" autocomplete="off">
    <input type="hidden" name="action" value="save">
    <div class="field"><label>Chiave API SendGrid</label>
      <input type="password" name="sendgrid_api_key" value="" placeholder="<?= $src === 'pannello' ? 'impostata (…' . h(substr($key, -4)) . ') — lascia vuoto per non cambiarla' : 'incolla qui la chiave SG.…' ?>" autocomplete="new-password" spellcheck="false">
      <p style="color:#8a9184;font-size:12.5px;margin:5px 0 0">Si crea in SendGrid → Settings → API Keys (permesso "Mail Send"). Viene salvata sul server e qui se ne vedono solo le ultime 4 cifre.<?= $src === 'mail-config' ? ' Oggi è impostata anche in inc/mail-config.php: quella incollata qui ha la precedenza.' : '' ?></p>
      <?php if ($src === 'pannello'): ?><label style="font-weight:400;font-size:13px;margin-top:8px;display:block"><input type="checkbox" name="remove_key" value="1" style="width:auto"> Rimuovi la chiave salvata qui</label><?php endif; ?>
    </div>
    <div class="row">
      <div class="field"><label>Mittente (email)</label><input type="email" name="mail_from" value="<?= h(nc_mail_setting('mail_from')) ?>" placeholder="<?= h(defined('MAIL_FROM') ? MAIL_FROM : '') ?>">
        <p style="color:#8a9184;font-size:12.5px;margin:5px 0 0">Deve essere un mittente verificato su SendGrid. Vuoto = <?= h(defined('MAIL_FROM') ? MAIL_FROM : '—') ?>.</p></div>
      <div class="field"><label>Nome mittente</label><input type="text" name="mail_from_name" value="<?= h(nc_mail_setting('mail_from_name')) ?>" placeholder="<?= h(defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '') ?>"></div>
    </div>
    <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Salva</button>
  </form>
</div>

<div class="card">
  <div class="sec" style="margin-top:0"><i class="ti ti-circle-check" style="vertical-align:-2px"></i> Controlli</div>
  <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end">
    <form method="post" style="margin:0">
      <input type="hidden" name="action" value="verify">
      <button class="btn ghost" type="submit"><i class="ti ti-shield-check"></i> Verifica chiave e mittente</button>
      <p style="color:#8a9184;font-size:12.5px;margin:6px 0 0;max-width:300px">Chiede a SendGrid se accetta chiave e mittente, senza consegnare nessuna email.</p>
    </form>
    <form method="post" style="margin:0;display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap">
      <input type="hidden" name="action" value="test">
      <div><input type="email" name="test_to" placeholder="<?= h($def['email'] ?: 'email di prova') ?>" style="min-width:240px">
        <p style="color:#8a9184;font-size:12.5px;margin:6px 0 0">Vuoto = destinatario predefinito.</p></div>
      <button class="btn ghost" type="submit"><i class="ti ti-send"></i> Invia email di prova</button>
    </form>
  </div>
</div>
<?php nc_admin_bottom();
