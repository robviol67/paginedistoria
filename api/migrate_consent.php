<?php
// Migration: tabella registro consensi GDPR. Idempotente.
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../inc/db.php';
try {
  db()->exec(
    'CREATE TABLE IF NOT EXISTS gdpr_consent (
       id           INT AUTO_INCREMENT PRIMARY KEY,
       form         VARCHAR(32)  NOT NULL,
       nome         VARCHAR(190),
       email        VARCHAR(190),
       consent_text TEXT,
       ip           VARCHAR(64),
       user_agent   VARCHAR(255),
       created_at   DATETIME     NOT NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
  );
  echo "OK  tabella gdpr_consent pronta\n";
} catch (Throwable $e) {
  http_response_code(500);
  echo "ERRORE: " . $e->getMessage() . "\n";
}
