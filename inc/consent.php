<?php
// Registrazione consenso GDPR (data, ora, IP) su tabella gdpr_consent.
require_once __DIR__ . '/db.php';

// Testo standard del consenso: viene salvato insieme a ogni consenso prestato.
if (!defined('CONSENT_TEXT')) define('CONSENT_TEXT',
  'Dichiaro di aver letto l’informativa sulla privacy e acconsento al trattamento dei miei dati personali per le finalità indicate.');
if (!defined('PRIVACY_URL')) define('PRIVACY_URL', 'privacy.html');

function nc_client_ip() {
  foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
    if (!empty($_SERVER[$k])) {
      $ip = trim(explode(',', $_SERVER[$k])[0]);
      if ($ip !== '') return $ip;
    }
  }
  return '';
}

// Registra il consenso; ritorna l'id (o false). Non deve bloccare l'invio email se fallisce.
function nc_log_consent($form, $nome, $email) {
  try {
    $st = db()->prepare(
      'INSERT INTO gdpr_consent (form, nome, email, consent_text, ip, user_agent, created_at)
       VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $st->execute([
      $form, $nome, $email, CONSENT_TEXT,
      nc_client_ip(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
    return db()->lastInsertId();
  } catch (Throwable $e) {
    return false;
  }
}
