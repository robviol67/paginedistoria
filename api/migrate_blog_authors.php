<?php
// VelociBuilder LITE — migration: autori delle news collegati agli utenti del pannello. Idempotente.
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../inc/db.php';
try {
  try { $a = db_add_col('cms_blog_posts', 'author_id', 'INT NULL AFTER author'); echo ($a ? "OK  colonna author_id\n" : "SKIP author_id (già presente)\n"); }
  catch (Throwable $e) { echo "SKIP author_id: " . $e->getMessage() . "\n"; }
  try { $a = db_add_index('cms_blog_posts', 'idx_author_id', 'author_id'); echo ($a ? "OK  indice author_id\n" : "SKIP indice author_id (già presente)\n"); }
  catch (Throwable $e) { echo "SKIP indice author_id: " . $e->getMessage() . "\n"; }
  echo "\nFatto. Il campo Autore in /admin/blog_post.php ora propone gli utenti del pannello (resta anche il testo libero per autori esterni).\n";
} catch (Throwable $e) { http_response_code(500); echo "ERRORE: " . $e->getMessage() . "\n"; }
