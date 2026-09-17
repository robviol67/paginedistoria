<?php
// Migration CMS: crea la tabella cms_content (override dei testi delle pagine).
// Nel CMS generico multi-pagina i DEFAULT vengono dal manifest del Design (cms-pages.json):
// cms_content contiene solo gli override (chiavi namespaced "slug::fN"), quindi qui basta
// creare la tabella. Idempotente.
require_once __DIR__ . '/../inc/db.php';
header('Content-Type: text/plain; charset=utf-8');

try {
  db()->exec(
    'CREATE TABLE IF NOT EXISTS cms_content (
       ckey   VARCHAR(64)  NOT NULL PRIMARY KEY,
       cvalue TEXT         NOT NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
  );
  echo "OK  tabella cms_content pronta\n";
  echo "\nFatto. Gli override dei testi si gestiscono dal pannello (/admin/ -> Pagine).\n";
} catch (Throwable $e) {
  http_response_code(500);
  echo "ERRORE: " . $e->getMessage() . "\n";
}
