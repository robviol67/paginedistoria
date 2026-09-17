<?php
// VelociBuilder LITE — connessione DB. Le credenziali stanno in config.php (per-sito, non nel repo).
require_once __DIR__ . '/../config.php';

function db() {
  static $pdo = null;
  if ($pdo === null) {
    $pdo = new PDO(
      'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
      DB_USER, DB_PASS,
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
  }
  return $pdo;
}

// --- Helper migration portabili MySQL + MariaDB ---------------------------------
// MySQL non supporta "ALTER TABLE ... ADD COLUMN/INDEX IF NOT EXISTS" (solo MariaDB).
// Questi helper controllano information_schema prima di aggiungere, così le migration
// restano idempotenti su entrambi i motori (Tophost=MySQL, Aruba=MariaDB).
function db_col_exists($table, $col) {
  $st = db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $st->execute([$table, $col]);
  return (int)$st->fetchColumn() > 0;
}
function db_add_col($table, $col, $definition) {
  if (db_col_exists($table, $col)) return false;
  db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
  return true;
}
function db_index_exists($table, $index) {
  $st = db()->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
  $st->execute([$table, $index]);
  return (int)$st->fetchColumn() > 0;
}
function db_add_index($table, $index, $cols) {
  if (db_index_exists($table, $index)) return false;
  db()->exec("ALTER TABLE `$table` ADD INDEX `$index` ($cols)");
  return true;
}
