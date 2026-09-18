<?php
// VelociBuilder LITE — migration base: utenti + messaggi. Idempotente.
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../inc/db.php';
try {
  db()->exec(
    'CREATE TABLE IF NOT EXISTS cms_users (
       id INT AUTO_INCREMENT PRIMARY KEY,
       username  VARCHAR(64)  NOT NULL UNIQUE,
       pass_hash VARCHAR(255) NOT NULL,
       name      VARCHAR(120),
       role      VARCHAR(20)  NOT NULL DEFAULT "admin",
       created_at DATETIME    NOT NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
  );
  echo "OK  cms_users\n";

  db()->exec(
    'CREATE TABLE IF NOT EXISTS cms_messages (
       id INT AUTO_INCREMENT PRIMARY KEY,
       form      VARCHAR(32)  NOT NULL,
       nome      VARCHAR(190),
       email     VARCHAR(190),
       tipo      VARCHAR(120),
       messaggio TEXT,
       ip        VARCHAR(64),
       is_read   TINYINT      NOT NULL DEFAULT 0,
       created_at DATETIME    NOT NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
  );
  echo "OK  cms_messages\n";

  // Pagine di Storia: qui il motore creava SEMPRE tre amministratori con una
  // password comune scritta in questo file — e il repository è pubblico. Chi
  // lo leggeva poteva entrare nel pannello. Tolto: gli utenti si creano dal
  // pannello (Utenti), e a tabella vuota vale CMS_ADMIN_PASS di config.php,
  // che nel repository non c'è. Da riportare al motore.
  echo "Utenti: nessuno creato in automatico (si creano da /admin/utenti.php).\n";
  echo "\nFatto.\n";
} catch (Throwable $e) {
  http_response_code(500);
  echo "ERRORE: " . $e->getMessage() . "\n";
}
