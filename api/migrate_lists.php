<?php
// VelociBuilder LITE — migration Liste ripetibili: tabella elementi + seed dal Design. Idempotente.
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../inc/db.php';
try {
  db()->exec('CREATE TABLE IF NOT EXISTS cms_list_items (
      id INT AUTO_INCREMENT PRIMARY KEY,
      list_key VARCHAR(64) NOT NULL,
      sort INT NOT NULL DEFAULT 0,
      data TEXT,
      active TINYINT NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (list_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_list_items\n";

  $seedFile = __DIR__ . '/../inc/lists-seed.json';
  $seed = is_file($seedFile) ? (json_decode(file_get_contents($seedFile), true) ?: []) : [];
  $chk = db()->prepare('SELECT COUNT(*) FROM cms_list_items WHERE list_key=?');
  $ins = db()->prepare('INSERT INTO cms_list_items (list_key, sort, data, active) VALUES (?,?,?,1)');
  foreach ($seed as $key => $items) {
    $chk->execute([$key]);
    if ((int)$chk->fetchColumn() > 0) { echo "  = $key già presente (nessun seed)\n"; continue; }
    $i = 0; foreach ($items as $it) { $ins->execute([$key, $i++, json_encode($it, JSON_UNESCAPED_UNICODE)]); }
    echo "  + $key: " . count($items) . " elementi\n";
  }
  echo "\nFatto. Ora: /admin/liste.php\n";
} catch (Throwable $e) { http_response_code(500); echo "ERRORE: " . $e->getMessage() . "\n"; }
