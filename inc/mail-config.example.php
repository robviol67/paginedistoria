<?php
// ============================================================================
// VelociBuilder LITE — config email per-sito. COPIA in "mail-config.php" e
// compila. mail-config.php è gitignored (contiene la API key: fuori dal repo).
// Senza SENDGRID_API_KEY il mailer usa la funzione mail() di PHP come fallback.
// ============================================================================

define('SENDGRID_API_KEY', '');                       // API key SendGrid (vuoto = usa mail())
define('MAIL_FROM',      'noreply@esempio.it');       // mittente autorizzato
define('MAIL_FROM_NAME', 'Nome del sito');
define('MAIL_REPLYTO',   'info@esempio.it');
define('MAIL_BCC',       '');                          // copia interna delle richieste (vuoto = off)

// Allegato opzionale per la landing "guida" (se presente)
define('GUIDE_FILE', __DIR__ . '/../files/guida.pdf');
define('GUIDE_PUBLIC_URL', 'https://www.esempio.it/files/guida.pdf');
