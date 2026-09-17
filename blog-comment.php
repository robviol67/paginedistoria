<?php
// VelociBuilder LITE — handler pubblico per l'invio di un commento a una news.
require_once __DIR__ . '/inc/blog.php';
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$postId = (int)($_POST['post_id'] ?? 0);
$post = ($_SERVER['REQUEST_METHOD'] === 'POST' && $postId) ? blog_post_get($postId) : null;

$ok = false; $err = ''; $successMsg = '';
try {
  if (!$post) throw new Exception('Articolo non trovato.');
  if (empty($_POST['consent'])) throw new Exception('Devi acconsentire al trattamento dei dati per pubblicare un commento.');
  $r = blog_comment_create($postId, $_POST['name'] ?? '', $_POST['email'] ?? '', $_POST['body'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '');
  $ok = true;
  $successMsg = $r['approved'] ? 'Il tuo commento è stato pubblicato.' : 'Il tuo commento è stato inviato ed è in attesa di approvazione.';
} catch (Throwable $e) { $err = $e->getMessage(); }

$back = $post ? blog_route($post) : 'blog.html';
?><!DOCTYPE html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Commento — invio</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&family=Public+Sans:wght@400;600&display=swap" rel="stylesheet">
<style>body{margin:0;background:oklch(98% 0.008 95);font-family:'Public Sans',system-ui,sans-serif;color:oklch(24% 0.015 260)}</style></head>
<body>
<div style="max-width:640px;margin:0 auto;padding:100px 24px;text-align:center">
<?php if ($ok): ?>
  <div style="width:64px;height:64px;border-radius:50%;background:#1F7A3D;color:#fff;display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 24px">&#10003;</div>
  <h1 style="font-family:'Poppins',sans-serif;font-size:28px;margin:0 0 12px">Grazie!</h1>
  <p style="font-size:16px;color:oklch(52% 0.02 260)"><?= h($successMsg) ?></p>
<?php else: ?>
  <h1 style="font-family:'Poppins',sans-serif;font-size:26px;margin:0 0 12px">Ops</h1>
  <p style="font-size:16px;color:oklch(52% 0.02 260)"><?= h($err) ?></p>
<?php endif; ?>
  <p style="margin-top:28px"><a href="<?= h($back) ?>" style="color:#1F7A3D;font-weight:600;text-decoration:none">&larr; Torna all'articolo</a></p>
</div>
</body></html>
