<?php
// Pagine di Storia — versa dati/racconti.json nel database: il racconto lungo
// e la cronologia di ogni scheda, i documenti nuovi trovati scrivendoli e le
// fonti nuove del repertorio. Idempotente.
//
//   php tools/importa_racconti.php [--forza]
//   https://…/tools/importa_racconti.php?key=…[&forza=1]
//
// Stessa prudenza di importa_atlante.php: un racconto già presente nel
// database NON si sovrascrive (potrebbe essere stato corretto nel pannello),
// a meno di &forza=1. I documenti si aggiungono in coda alle citazioni della
// scheda, solo se non ci sono già (stessa fonte e stesso indirizzo, oppure
// stessa fonte e stesso localizzatore). Nessun altro campo viene toccato.
declare(strict_types=1);

$daRiga = PHP_SAPI === 'cli';
require_once __DIR__ . '/../inc/db.php';

if (!$daRiga) {
  header('Content-Type: text/plain; charset=utf-8');
  $atteso = defined('MIGRATION_KEY') ? MIGRATION_KEY : '';
  if ($atteso === '' || !hash_equals($atteso, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit("Serve la chiave: ?key=… (MIGRATION_KEY in config.php)\n");
  }
}
$forza = $daRiga ? in_array('--forza', $argv ?? [], true) : isset($_GET['forza']);

$file = __DIR__ . '/../dati/racconti.json';
if (!is_file($file)) exit("✗ manca dati/racconti.json: lancia prima ricerche/racconti/unisci_racconti.py\n");
$D = json_decode(file_get_contents($file), true);
if (!$D) exit("✗ dati/racconti.json non è JSON valido\n");

echo "Racconti: edizione {$D['edizione']} · {$D['generato']} · " . count($D['racconti']) . " schede\n";
echo $forza ? "Modo: SOVRASCRIVO i racconti esistenti\n\n" : "Modo: scrivo solo dove il racconto manca\n\n";

$pdo = db();
foreach ([['racconto', 'MEDIUMTEXT NULL'], ['cronologia', 'TEXT NULL']] as [$c, $def]) db_add_col('pds_schede', $c, $def);

$n = ['scritti' => 0, 'saltati' => 0, 'assenti' => 0, 'doc' => 0, 'fonti' => 0];
try {
  $pdo->beginTransaction();

  // ── fonti nuove ───────────────────────────────────────────────────────────
  $campiF = ['titolo','autore_ente','natura','categoria','ambito','paese','lingua','url',
             'accesso','accesso_nota','limiti','come_usarla','copertura','esito','riscontrato','verifica_data',
             'editore','anno','edizione','isbn','copertura_inizio','copertura_fine'];
  $selF = $pdo->prepare('SELECT 1 FROM pds_fonti WHERE id=?');
  $insF = $pdo->prepare('INSERT INTO pds_fonti (id,' . implode(',', $campiF) . ') VALUES (?' . str_repeat(',?', count($campiF)) . ')');
  foreach ($D['fonti_nuove'] ?? [] as $f) {
    $selF->execute([$f['id']]);
    if ($selF->fetchColumn()) continue;
    $insF->execute(array_merge([$f['id']], array_map(fn($c) => $f[$c] ?? null, $campiF)));
    $n['fonti']++;
  }

  // ── racconti, cronologie e documenti ──────────────────────────────────────
  $sel = $pdo->prepare('SELECT racconto FROM pds_schede WHERE id=?');
  $upd = $pdo->prepare('UPDATE pds_schede SET racconto=?, cronologia=? WHERE id=?');
  $gia = $pdo->prepare('SELECT fonte_id, url_specifico, localizzatore FROM pds_scheda_fonte WHERE scheda_id=?');
  $max = $pdo->prepare('SELECT IFNULL(MAX(ordine),-1)+1 FROM pds_scheda_fonte WHERE scheda_id=?');
  $insSF = $pdo->prepare('INSERT INTO pds_scheda_fonte (scheda_id, fonte_id, ruolo, localizzatore, tipo_documento, data_documento, url_specifico, nota, verificato_il, ordine) VALUES (?,?,?,?,?,?,?,?,?,?)');

  foreach ($D['racconti'] as $r) {
    $sel->execute([$r['scheda_id']]);
    $prima = $sel->fetch();
    if ($prima === false) { $n['assenti']++; echo "  ? {$r['scheda_id']} non è nel database\n"; continue; }
    if (trim((string)$prima['racconto']) !== '' && !$forza) { $n['saltati']++; continue; }

    $cron = array_map(fn($c) => ['data' => $c['data'], 'fatto' => $c['fatto'], 'fonte_id' => $c['fonte_id'] ?? null, 'url' => $c['url'] ?? null],
                      $r['cronologia'] ?? []);
    $upd->execute([$r['racconto'], $cron ? json_encode($cron, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $r['scheda_id']]);
    $n['scritti']++;

    $gia->execute([$r['scheda_id']]);
    $esistenti = $gia->fetchAll();
    $max->execute([$r['scheda_id']]);
    $ordine = (int)$max->fetchColumn();
    foreach ($r['fonti_aggiunte'] ?? [] as $f) {
      $doppio = false;
      foreach ($esistenti as $e)
        if ($e['fonte_id'] === $f['fonte_id'] && (($f['url_specifico'] ?? '') !== '' && $e['url_specifico'] === $f['url_specifico']
            || ($f['localizzatore'] ?? '') !== '' && $e['localizzatore'] === $f['localizzatore'])) { $doppio = true; break; }
      if ($doppio) continue;
      $selF->execute([$f['fonte_id']]);
      if (!$selF->fetchColumn()) throw new RuntimeException("{$r['scheda_id']}: la fonte {$f['fonte_id']} non è nel repertorio");
      $insSF->execute([$r['scheda_id'], $f['fonte_id'], $f['ruolo'] ?? null, $f['localizzatore'] ?? null, $f['tipo_documento'] ?? null,
                       $f['data_documento'] ?? null, $f['url_specifico'] ?? null, $f['nota'] ?? null, $f['verificato_il'] ?? null, $ordine++]);
      $esistenti[] = ['fonte_id' => $f['fonte_id'], 'url_specifico' => $f['url_specifico'] ?? null, 'localizzatore' => $f['localizzatore'] ?? null];
      $n['doc']++;
    }
  }
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  exit("✗ ERRORE, niente è stato scritto: " . $e->getMessage() . "\n");
}

printf("racconti     %d scritti, %d già presenti (saltati), %d schede assenti\n", $n['scritti'], $n['saltati'], $n['assenti']);
printf("documenti    +%d citazioni\n", $n['doc']);
printf("fonti        +%d nuove\n", $n['fonti']);
echo "\nOra ripubblica: tools/pubblica_atlante.php\n";
