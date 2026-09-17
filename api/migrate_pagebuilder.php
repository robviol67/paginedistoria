<?php
// VelociBuilder LITE — migration Pagine libere (page-builder a blocchi). Idempotente.
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../inc/db.php';
try {
  db()->exec('CREATE TABLE IF NOT EXISTS cms_custom_pages (
      id INT AUTO_INCREMENT PRIMARY KEY,
      slug VARCHAR(190) NOT NULL UNIQUE,
      title VARCHAR(255) NOT NULL,
      seo_title VARCHAR(255),
      seo_desc VARCHAR(500),
      seo_image VARCHAR(255),
      status ENUM(\'draft\',\'published\') NOT NULL DEFAULT \'draft\',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_custom_pages\n";

  db()->exec('CREATE TABLE IF NOT EXISTS cms_page_blocks (
      id INT AUTO_INCREMENT PRIMARY KEY,
      page_id INT NOT NULL,
      block_type VARCHAR(40) NOT NULL,
      sort INT NOT NULL DEFAULT 0,
      data TEXT,
      active TINYINT NOT NULL DEFAULT 1,
      INDEX (page_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_page_blocks\n";
  echo "\nFatto. Ora: /admin/pagine.php -> \"Nuova pagina libera\"\n";
} catch (Throwable $e) { http_response_code(500); echo "ERRORE: " . $e->getMessage() . "\n"; }
