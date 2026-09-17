<?php
// VelociBuilder LITE — anteprima live di una news (stato corrente del DB, anche non pubblicata).
// Usata nella tab "Anteprima" di admin/blog_post.php (dentro un <iframe>).
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/blog.php';

$post = blog_table_ready() ? blog_post_get((int)($_GET['id'] ?? 0)) : null;
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!$post) { http_response_code(404); echo 'News non trovata.'; exit; }
echo blog_post_render_doc($post);
