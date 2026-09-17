<?php
// VelociBuilder LITE — migration: commenti sulle news (con moderazione opzionale). Idempotente.
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../inc/db.php';
try {
  try { $a = db_add_col('cms_blog_posts', 'comments_enabled', 'TINYINT NOT NULL DEFAULT 0 AFTER archived'); echo ($a ? "OK  colonna comments_enabled\n" : "SKIP comments_enabled (già presente)\n"); }
  catch (Throwable $e) { echo "SKIP comments_enabled: " . $e->getMessage() . "\n"; }
  try { $a = db_add_col('cms_blog_posts', 'comments_moderation', 'TINYINT NOT NULL DEFAULT 1 AFTER comments_enabled'); echo ($a ? "OK  colonna comments_moderation\n" : "SKIP comments_moderation (già presente)\n"); }
  catch (Throwable $e) { echo "SKIP comments_moderation: " . $e->getMessage() . "\n"; }

  db()->exec('CREATE TABLE IF NOT EXISTS cms_blog_comments (
      id INT AUTO_INCREMENT PRIMARY KEY,
      post_id INT NOT NULL,
      name VARCHAR(190) NOT NULL,
      email VARCHAR(190) NOT NULL,
      body TEXT NOT NULL,
      approved TINYINT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      ip VARCHAR(64),
      INDEX (post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_blog_comments\n";
  echo "\nFatto. In /admin/blog_post.php ora si può abilitare 'Commenti' per singolo post (con o senza moderazione); la coda di moderazione e' in /admin/blog_comments.php\n";
} catch (Throwable $e) { http_response_code(500); echo "ERRORE: " . $e->getMessage() . "\n"; }
