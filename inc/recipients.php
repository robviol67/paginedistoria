<?php
// VelociBuilder LITE — destinatario predefinito delle richieste (moduli, assistente AI).
// Regola: chi riceve è l'admin del pannello. Con un solo admin è lui; con più admin è quello
// scelto in Utenti (setting `notify_admin`). Ultima spiaggia: MAIL_REPLYTO di mail-config.php.
// Un destinatario specifico (campo "Destinatari" del modulo, email dell'agente) vince sempre.
require_once __DIR__ . '/settings.php';
if (!defined('MAIL_REPLYTO') && is_file(__DIR__ . '/mail-config.php')) require_once __DIR__ . '/mail-config.php';

// Email degli utenti admin (lo username è l'email; chi non ha un'email valida non può ricevere).
function nc_admin_emails() {
  try { $rows = db()->query("SELECT username FROM cms_users WHERE role = 'admin' ORDER BY id")->fetchAll(); }
  catch (Throwable $e) { return []; }
  $out = [];
  foreach ($rows as $r) {
    $u = strtolower(trim((string)$r['username']));
    if (filter_var($u, FILTER_VALIDATE_EMAIL) && !in_array($u, $out, true)) $out[] = $u;
  }
  return $out;
}

// Destinatario predefinito + da dove arriva (per spiegarlo nel pannello).
function nc_default_recipient_info() {
  $admins = nc_admin_emails();
  $chosen = strtolower(trim((string)setting_get('notify_admin', '')));
  if ($chosen !== '' && in_array($chosen, $admins, true)) return ['email' => $chosen, 'source' => 'scelto'];
  if (count($admins) === 1) return ['email' => $admins[0], 'source' => 'unico'];
  $fallback = defined('MAIL_REPLYTO') ? (string)MAIL_REPLYTO : '';
  return ['email' => filter_var($fallback, FILTER_VALIDATE_EMAIL) ? $fallback : '', 'source' => 'mail-config'];
}
function nc_default_recipient() { return nc_default_recipient_info()['email']; }

// Lista "a, b" → indirizzi validi; vuota = destinatario predefinito.
function nc_resolve_recipients($list) {
  $out = [];
  foreach (preg_split('/[,;\s]+/', (string)$list) as $e) {
    $e = trim($e);
    if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
  }
  if (!$out && ($d = nc_default_recipient()) !== '') $out[] = $d;
  return array_values(array_unique($out));
}
