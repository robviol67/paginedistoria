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

  // Utenti admin di default VelociBuilder LITE — creati SEMPRE (INSERT IGNORE:
  // idempotente, li aggiunge anche se esistono già altri utenti, senza duplicare
  // né sovrascrivere le password di utenti esistenti). Login: username = email.
  $defaults = ['robviol@insertsrl.com', 'diealp@gmail.com', 'supporto@marketingstart.it'];
  $ins = db()->prepare('INSERT IGNORE INTO cms_users (username, pass_hash, name, role, created_at) VALUES (?,?,?,?,NOW())');
  $created = 0;
  foreach ($defaults as $email) {
    $ins->execute([$email, password_hash('testlite', PASSWORD_DEFAULT), ucfirst(explode('@', $email)[0]), 'admin']);
    if ($ins->rowCount() > 0) { $created++; echo "OK  utente admin: $email  (password: testlite)\n"; }
    else echo "utente admin già presente: $email\n";
  }
  echo "$created nuovi utenti admin creati (password comune: testlite).\n";
  echo "\nFatto.\n";
} catch (Throwable $e) {
  http_response_code(500);
  echo "ERRORE: " . $e->getMessage() . "\n";
}
