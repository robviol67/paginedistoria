<?php
// Pagine di Storia — rigenera le pagine statiche dell'atlante dal database:
// una pagina per scheda (scheda-<id>-<slug>.html) e una per fonte
// (fonte-<id>.html), alla radice del sito.
//
//   php tools/pubblica_atlante.php [ID ID …]
//   https://…/tools/pubblica_atlante.php?key=…[&id=E03,M24]
//
// Senza id le rigenera tutte. È idempotente: riscrive i file, non tocca il
// database.
declare(strict_types=1);
@set_time_limit(300);
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/pds_scheda.php';
require_once __DIR__ . '/../inc/pds_fonte.php';
require_once __DIR__ . '/../inc/pds_dati.php';
require_once __DIR__ . '/../inc/pds_nessi.php';

$daRiga = PHP_SAPI === 'cli';
if (!$daRiga) {
  header('Content-Type: text/plain; charset=utf-8');
  $atteso = defined('MIGRATION_KEY') ? MIGRATION_KEY : '';
  if ($atteso === '' || !hash_equals($atteso, (string)($_GET['key'] ?? ''))) {
    http_response_code(403); exit("Serve la chiave: ?key=…\n");
  }
}
$ids = $daRiga ? array_slice($argv ?? [], 1) : array_filter(explode(',', (string)($_GET['id'] ?? '')));
$ids = $ids ? array_map('strtoupper', $ids) : null;

$t0 = microtime(true);
$e = pds_pubblica_schede($ids);
printf("schede scritte: %d in %.1f s\n", $e['scritte'], microtime(true) - $t0);
if ($e['errori']) { echo "errori:\n"; foreach ($e['errori'] as $x) echo "  ✗ $x\n"; }

// Il file dati dei filtri si rigenera SEMPRE: basta una scheda cambiata
// perché ricerca e conteggi mentano.
$d = pds_pubblica_dati();
printf("dati filtri:    %d schede, %d fonti (%.0f KB)\n", $d['schede'], $d['fonti'], $d['byte'] / 1024);

// La pagina Nessi si ricompone sempre: il suo elenco sono schede.
try { $n = pds_pubblica_nessi(); printf("pagina Nessi:   %d candidati\n", $n['nessi']); }
catch (Throwable $e) { echo "  ✗ pagina Nessi: " . $e->getMessage() . "\n"; }

// Le fonti si rigenerano tutte quando si rigenera tutto: una scheda cambiata
// cambia l'elenco «Schede che la usano» di ogni fonte che cita.
if ($ids === null) {
  $t0 = microtime(true);
  $f = pds_pubblica_fonti();
  printf("fonti scritte:  %d in %.1f s\n", $f['scritte'], microtime(true) - $t0);
  if ($f['errori']) { echo "errori:\n"; foreach ($f['errori'] as $x) echo "  ✗ $x\n"; }
}
