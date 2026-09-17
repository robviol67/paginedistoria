<?php
// Mailer condiviso: parla l'API SendGrid. mail() solo se esplicitamente abilitato
// con MAIL_USE_PHPMAIL (vedi nc_send_mail: su Aruba mail() abbatte il processo).
// Supporta allegati. $attachments = [ ['path'=>..., 'name'=>..., 'type'=>...], ... ]
require_once __DIR__ . '/mail-config.php';

/**
 * Vero solo se l'invio via API SendGrid è configurato.
 * Le pagine possono chiederlo PRIMA di promettere un'email all'utente.
 */
function nc_mail_pronto() {
  return nc_mail_key() !== '';
}

/**
 * Chiave, mittente e nome si possono impostare dal pannello (Invio email → cms_settings),
 * così chi gestisce il sito incolla la chiave da sé senza toccare file sul server.
 * Il pannello vince; vuoto = costanti di mail-config.php.
 */
function nc_mail_setting($key) {
  static $cache = [];
  if (!array_key_exists($key, $cache)) {
    $cache[$key] = '';
    try {
      if (is_file(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/settings.php';
        $cache[$key] = trim((string) setting_get($key, ''));
      }
    } catch (Throwable $e) {}
  }
  return $cache[$key];
}
function nc_mail_key() {
  $k = nc_mail_setting('sendgrid_api_key');
  if ($k !== '') return $k;
  return (defined('SENDGRID_API_KEY') && SENDGRID_API_KEY !== '') ? SENDGRID_API_KEY : '';
}
function nc_mail_key_source() {
  if (nc_mail_setting('sendgrid_api_key') !== '') return 'pannello';
  return (defined('SENDGRID_API_KEY') && SENDGRID_API_KEY !== '') ? 'mail-config' : '';
}
function nc_mail_from() {
  $f = nc_mail_setting('mail_from');
  return filter_var($f, FILTER_VALIDATE_EMAIL) ? $f : (defined('MAIL_FROM') ? MAIL_FROM : '');
}
function nc_mail_from_name() {
  $n = nc_mail_setting('mail_from_name');
  return $n !== '' ? $n : (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '');
}
// Ultimo errore di SendGrid (codice + risposta), per il test dal pannello.
function nc_mail_last_error($set = null) {
  static $err = '';
  if ($set !== null) $err = $set;
  return $err;
}

/**
 * ⚠ NON si ripiega su mail() se non lo si chiede a voce alta.
 *
 * Su Aruba `mail()` non fallisce: uccide il processo PHP a metà richiesta.
 * Niente eccezione, niente errore fatale, niente riga di log — solo la
 * pagina 500 di Plesk al posto di tutto l'output. Il 3 settembre 2026 questo
 * ha tenuto giù per giorni TUTTI i form di nuovosostenibile.it (contatti,
 * richiesta prodotto, guida) per una sola causa: una copia vecchia di
 * mail-config.php con la chiave SendGrid vuota. Il ripiego silenzioso ha
 * trasformato un errore di configurazione in un guasto invisibile.
 *
 * Quindi: senza chiave si ritorna false. Chi ha un host dove mail() funziona
 * lo dichiara con define('MAIL_USE_PHPMAIL', true) in mail-config.php.
 * false costa un messaggio di cortesia; il 500 costa i contatti persi.
 *
 * $html (opzionale): corpo HTML alternativo. Se valorizzato l'email è multipart
 * (testo + HTML); omesso, il comportamento resta identico a prima (solo testo).
 */
function nc_send_mail($to, $subject, $text, $attachments = [], $replyTo = null, $html = null) {
  if (nc_mail_pronto()) {
    return nc_send_sendgrid($to, $subject, $text, $attachments, $replyTo, $html);
  }
  if (defined('MAIL_USE_PHPMAIL') && MAIL_USE_PHPMAIL) {
    return nc_send_phpmail($to, $subject, $text, $attachments, $replyTo, $html);
  }
  error_log('nc_send_mail: chiave SendGrid assente (pannello Invio email o inc/mail-config.php) — email non inviata a ' . $to);
  nc_mail_last_error('Nessuna chiave SendGrid impostata e invio diretto (mail) non abilitato.');
  return false;
}

/**
 * Tetto per singolo allegato, in byte. Si supera con MAIL_ATTACH_MAX.
 *
 * ⚠ NON è una preferenza estetica: un allegato costa in memoria circa TRE volte
 * il suo peso (i byte letti, la base64 che è 4/3, la copia dentro il JSON).
 * Su hosting condiviso il processo PHP può avere un tetto reale molto più basso
 * di quello che `memory_limit` dichiara — su Aruba/Plesk nuovosostenibile.it
 * dichiara 512M e viene ucciso oltre i ~2 MB — e allora non c'è errore da
 * intercettare: il processo sparisce e la pagina diventa una 500.
 *
 * Perciò l'allegato troppo grande si SALTA, e l'email parte lo stesso: chi
 * scrive il testo deve sempre metterci anche un link di scaricamento.
 */
function nc_mail_attach_max() {
  return defined('MAIL_ATTACH_MAX') ? (int) MAIL_ATTACH_MAX : 1500000;
}

/** Scarta gli allegati illeggibili o troppo pesanti, annotando nel log perché. */
function nc_mail_allegati_ammessi($attachments) {
  $ok = [];
  foreach ((array) $attachments as $a) {
    if (empty($a['path']) || !is_readable($a['path'])) continue;
    $peso = (int) @filesize($a['path']);
    if ($peso > nc_mail_attach_max()) {
      error_log('nc_send_mail: allegato saltato, ' . $peso . ' byte oltre il tetto di '
              . nc_mail_attach_max() . ' — ' . $a['path']);
      continue;
    }
    $ok[] = $a;
  }
  return $ok;
}

// --- SendGrid (API v3) ---
function nc_send_sendgrid($to, $subject, $text, $attachments, $replyTo = null, $html = null) {
  $payload = [
    'personalizations' => [[
      'to' => [['email' => $to]],
    ]],
    'from' => ['email' => nc_mail_from(), 'name' => nc_mail_from_name()],
    'reply_to' => ['email' => ($replyTo ?: MAIL_REPLYTO)],
    'subject' => $subject,
    'content' => [['type' => 'text/plain', 'value' => $text]],
  ];
  // SendGrid richiede text/plain PRIMA di text/html
  if ($html !== null && $html !== '') $payload['content'][] = ['type' => 'text/html', 'value' => $html];
  if (defined('MAIL_BCC') && MAIL_BCC !== '' && MAIL_BCC !== $to) $payload['personalizations'][0]['bcc'] = [['email' => MAIL_BCC]];
  $atts = [];
  foreach (nc_mail_allegati_ammessi($attachments) as $a) {
    $atts[] = [
      'content' => base64_encode(file_get_contents($a['path'])),
      'filename' => $a['name'] ?? basename($a['path']),
      'type' => $a['type'] ?? 'application/octet-stream',
      'disposition' => 'attachment',
    ];
  }
  if ($atts) $payload['attachments'] = $atts;

  [$code, $resp] = nc_sendgrid_post($payload, nc_mail_key());
  if ($code >= 200 && $code < 300) return true;
  $msg = 'SendGrid ' . $code . ': ' . substr((string) $resp, 0, 300);
  nc_mail_last_error($msg);
  error_log('nc_send_mail: ' . $msg . ' — email non inviata a ' . $to);
  return false;
}
// Chiamata grezza all'API (usata anche dalla verifica in sandbox del pannello): [codice HTTP, risposta].
function nc_sendgrid_post(array $payload, $key) {
  $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $key,
      'Content-Type: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
  ]);
  $resp = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($resp === false) $resp = curl_error($ch);
  curl_close($ch);
  return [$code, $resp];
}

// --- mail() di Aruba con MIME multipart (per allegati) ---
function nc_send_phpmail($to, $subject, $text, $attachments, $replyTo = null, $html = null) {
  $boundary = 'ns_' . md5($subject . $to);
  $altB     = 'alt_' . md5($to . $subject);
  $headers  = 'From: ' . nc_mail_from_name() . ' <' . nc_mail_from() . ">\r\n";
  $headers .= 'Reply-To: ' . ($replyTo ?: MAIL_REPLYTO) . "\r\n";
  if (defined('MAIL_BCC') && MAIL_BCC !== '' && MAIL_BCC !== $to) $headers .= 'Bcc: ' . MAIL_BCC . "\r\n";
  $headers .= "MIME-Version: 1.0\r\n";
  $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . "\"\r\n";

  $body  = '--' . $boundary . "\r\n";
  if ($html !== null && $html !== '') {
    // parte alternativa: testo + HTML (i client scelgono la migliore)
    $body .= 'Content-Type: multipart/alternative; boundary="' . $altB . "\"\r\n\r\n";
    $body .= '--' . $altB . "\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $text . "\r\n";
    $body .= '--' . $altB . "\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $html . "\r\n";
    $body .= '--' . $altB . "--\r\n";
  } else {
    $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $text . "\r\n";
  }
  foreach (nc_mail_allegati_ammessi($attachments) as $a) {
    $data = chunk_split(base64_encode(file_get_contents($a['path'])));
    $name = $a['name'] ?? basename($a['path']);
    $type = $a['type'] ?? 'application/octet-stream';
    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Type: ' . $type . '; name="' . $name . "\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= 'Content-Disposition: attachment; filename="' . $name . "\"\r\n\r\n";
    $body .= $data . "\r\n";
  }
  $body .= '--' . $boundary . "--";

  // encoding dell'oggetto per caratteri non ASCII
  $enc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  return @mail($to, $enc, $body, $headers);
}
