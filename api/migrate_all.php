<?php
// VelociBuilder LITE — migrazione in blocco senza login, guardata da MIGRATION_KEY.
// Serve a `vb update-all`: dopo aver caricato i file motore su un sito, lancia tutte le
// migration mancanti in ordine di dipendenza (idempotente), tracciandole in cms_migrations.
// Uso: GET /api/migrate_all.php?key=LA_TUA_MIGRATION_KEY   (opz. &force=1 per rilanciare tutte)
header('Content-Type: text/plain; charset=utf-8');
// Diagnostica: mostra i fatal nel body (Aruba altrimenti risponde 500 e nasconde il motivo).
ini_set('display_errors', '1'); ini_set('display_startup_errors', '1'); error_reporting(E_ALL);
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    if (!headers_sent()) http_response_code(200);
    echo "\n\n>>> FATAL: {$e['message']}\n    in {$e['file']}:{$e['line']}\n";
  }
});
require_once __DIR__ . '/../inc/db.php';

if (!defined('MIGRATION_KEY') || MIGRATION_KEY === '' || ($_GET['key'] ?? '') !== MIGRATION_KEY) {
  http_response_code(403); echo "403: key mancante o errata (definisci MIGRATION_KEY in config.php).\n"; exit;
}

try { db()->exec('CREATE TABLE IF NOT EXISTS cms_migrations (name VARCHAR(120) PRIMARY KEY, status VARCHAR(16) NOT NULL DEFAULT "ok", run_at DATETIME NOT NULL, log TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); } catch (Throwable $e) {}

// Ordine di dipendenza (le extra non elencate vengono in coda).
$ORDER = ['migrate_velocibuilder.php','migrate_cms.php','migrate_consent.php','migrate_media.php',
  'migrate_nav.php','migrate_lists.php','migrate_products.php','migrate_forms.php','migrate_pagebuilder.php',
  'migrate_blog.php','migrate_blog_authors.php','migrate_blog_comments.php','migrate_galleries.php','migrate_velocitracker.php',
  'migrate_assistente.php'];

$apidir = __DIR__;
$files = array_map('basename', glob($apidir . '/migrate_*.php') ?: []);
$files = array_filter($files, fn($f) => $f !== 'migrate_all.php');
$ordered = [];
foreach ($ORDER as $f) if (in_array($f, $files, true)) $ordered[] = $f;
foreach ($files as $f) if (!in_array($f, $ordered, true)) $ordered[] = $f;

$done = [];
foreach (db()->query('SELECT name FROM cms_migrations') as $r) $done[$r['name']] = true;
$force = ($_GET['force'] ?? '') === '1';

$ins = db()->prepare('INSERT INTO cms_migrations (name,status,run_at,log) VALUES (?,?,NOW(),?) ON DUPLICATE KEY UPDATE status=VALUES(status),run_at=VALUES(run_at),log=VALUES(log)');

// Esegue una migration IN-PROCESS (include isolato) — niente curl loopback verso il proprio
// dominio: su alcuni hosting (Aruba) la richiesta HTTPS a se stesso è bloccata e va in timeout,
// uccidendo lo script padre con un 500 secco senza spiegazioni. L'include diretto è affidabile
// ovunque. Lo scope è isolato in una closure; @ silenzia i warning header() del figlio; lo
// status HTTP viene riportato a 200 dopo (una migration può impostare 500 su un suo errore).
function run_migration_inproc($file) {
  ob_start();
  $threw = null;
  try { (function ($__f) { @include $__f; })($file); }
  catch (Throwable $e) { $threw = get_class($e) . ': ' . $e->getMessage(); }
  $body = ob_get_clean();
  http_response_code(200);
  return [$threw, $body];
}

$okc = 0; $skip = 0; $err = 0;
foreach ($ordered as $f) {
  if (!$force && isset($done[$f])) { echo "· già fatta: $f\n"; $skip++; continue; }
  [$threw, $body] = run_migration_inproc($apidir . '/' . $f);
  $bad = $threw !== null || stripos($body, 'ERRORE') !== false || stripos($body, 'Fatal error') !== false || stripos($body, 'Parse error') !== false;
  if ($bad) { echo "✗ $f: " . trim($threw ?? mb_substr($body, 0, 300)) . "\n"; $err++; }
  else { try { $ins->execute([$f, 'ok', trim(mb_substr($body, 0, 2000))]); } catch (Throwable $e) {} echo "✓ $f\n"; $okc++; }
}
echo "\nFatto. $okc eseguite, $skip già fatte, $err errori.\n";
if ($err) echo "\n>>> $err MIGRATION IN ERRORE (vedi le righe con ✗ qui sopra per il dettaglio).\n";
// Di default NON impostare 500: su Aruba/Apache lo status 500 fa scartare questo body e
// mostra la pagina d'errore generica, nascondendo QUALE migration è fallita. Con &strict=1
// (uso programmatico/agente) si ripristina lo status d'errore.
if ($err && (($_GET['strict'] ?? '') === '1')) http_response_code(500);
