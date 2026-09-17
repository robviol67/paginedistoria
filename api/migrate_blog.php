<?php
// VelociBuilder LITE — migration Blog/News: categorie + post. Idempotente.
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../inc/db.php';
try {
  db()->exec('CREATE TABLE IF NOT EXISTS cms_blog_categories (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(190) NOT NULL,
      slug VARCHAR(190) NOT NULL UNIQUE,
      sort INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_blog_categories\n";

  db()->exec('CREATE TABLE IF NOT EXISTS cms_blog_posts (
      id INT AUTO_INCREMENT PRIMARY KEY,
      slug VARCHAR(220) NOT NULL UNIQUE,
      title VARCHAR(255) NOT NULL,
      subtitle VARCHAR(500),
      eyebrow VARCHAR(190),
      category_id INT,
      tags VARCHAR(500),
      excerpt TEXT,
      body MEDIUMTEXT,
      cover_image VARCHAR(255),
      author VARCHAR(190),
      published TINYINT NOT NULL DEFAULT 0,
      featured TINYINT NOT NULL DEFAULT 0,
      archived TINYINT NOT NULL DEFAULT 0,
      seo_title VARCHAR(255),
      seo_desc VARCHAR(500),
      seo_image VARCHAR(255),
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_blog_posts\n";
  echo "\nFatto. Ora: /admin/blog.php -> \"Nuova news\"\n";
} catch (Throwable $e) { http_response_code(500); echo "ERRORE: " . $e->getMessage() . "\n"; }
