<?php
// VelociBuilder LITE — handler pubblico generico per TUTTI i form gestiti come Modulo.
// Ci arrivano sia i blocchi "form" del page-builder sia i form del Design collegati a un
// modulo (<form action="forms.php"> + hidden form_slug). Destinatari, oggetto, conferma,
// auto-risposta e allegato si decidono in pannello → Moduli, non qui.
require_once __DIR__ . '/inc/forms.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.html'); exit; }
$slug = trim($_POST['form_slug'] ?? '');
$form = $slug !== '' ? form_get_by_slug($slug) : null;
if (!$form) { http_response_code(404); echo 'Modulo non trovato.'; exit; }

$ok = false; $err = ''; $result = [];
try { $result = forms_handle_submit($form, $_POST); $ok = true; }
catch (Throwable $e) { $err = $e->getMessage(); }

// torna alla pagina del form, solo se è dello stesso sito
$back = 'index.html';
$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '')) $back = $ref;

$accent = defined('FORM_ACCENT') ? FORM_ACCENT : '#1F7A3D';
$font = defined('FORM_FONT') ? FORM_FONT : "'Public Sans',sans-serif";
$headFont = defined('FORM_HEAD_FONT') ? FORM_HEAD_FONT : "'Poppins',sans-serif";
$fontsHref = defined('FORM_FONTS_HREF') ? FORM_FONTS_HREF : 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&family=Public+Sans:wght@400;600&display=swap';
$site = defined('SITE_NAME') ? ' — ' . SITE_NAME : '';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $e($ok ? $result['success_title'] : $form['name']) ?><?= $e($site) ?></title>
<link href="<?= $e($fontsHref) ?>" rel="stylesheet">
<style>body{margin:0;background:#fff;font-family:<?= $font ?>;color:#1a1a1a}h1{font-family:<?= $headFont ?>}</style></head>
<body>
<div style="max-width:640px;margin:0 auto;padding:100px 24px;text-align:center">
<?php if ($ok): ?>
  <div style="width:64px;height:64px;border-radius:50%;background:<?= $e($accent) ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 24px">&#10003;</div>
  <h1 style="font-size:28px;margin:0 0 12px"><?= $e($result['success_title']) ?></h1>
  <p style="font-size:16px;color:#5a5a5a;margin:0 0 26px"><?= $e($result['success_message']) ?></p>
  <?php if (!empty($result['download_url'])): ?>
    <a href="<?= $e($result['download_url']) ?>" target="_blank" rel="noopener" style="display:inline-block;background:<?= $e($accent) ?>;color:#fff;text-decoration:none;font-weight:700;font-size:14px;padding:14px 28px;border-radius:10px">Scarica ora il PDF</a>
  <?php endif; ?>
<?php else: ?>
  <h1 style="font-size:26px;margin:0 0 12px">Qualcosa non ha funzionato</h1>
  <p style="font-size:16px;color:#5a5a5a"><?= $e($err) ?></p>
<?php endif; ?>
  <p style="margin-top:28px"><a href="<?= $e($back) ?>" style="color:<?= $e($accent) ?>;font-weight:600;text-decoration:none">&larr; Torna alla pagina</a></p>
</div>
</body></html>
<?php
// CRM dopo aver risposto al visitatore: una VelociTracker lenta non deve bloccare la pagina.
if ($ok && !empty($result['vt'])) {
  if (function_exists('fastcgi_finish_request')) { @ob_end_flush(); @flush(); @fastcgi_finish_request(); }
  forms_push_crm($form, $result);
}
