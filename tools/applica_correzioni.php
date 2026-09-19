<?php
// Pagine di Storia — applica al database le correzioni approvate in
// dati/correzioni.json. Idempotente e prudente: una correzione si applica
// solo se il campo contiene ANCORA il testo di partenza. Se nel frattempo
// qualcuno l'ha cambiato nel pannello, la correzione si salta e lo si dice.
//
//   php tools/applica_correzioni.php
//   https://…/tools/applica_correzioni.php?key=…
//
// Tre tipi:
//   campo          un campo testuale di pds_schede (sostituzione del passo)
//   localizzatore  il localizzatore di una citazione (scheda + fonte)
//   nota           la nota di una citazione (scheda + fonte)
declare(strict_types=1);

$daRiga = PHP_SAPI === 'cli';
require_once __DIR__ . '/../inc/db.php';

if (!$daRiga) {
  header('Content-Type: text/plain; charset=utf-8');
  $atteso = defined('MIGRATION_KEY') ? MIGRATION_KEY : '';
  if ($atteso === '' || !hash_equals($atteso, (string)($_GET['key'] ?? ''))) {
    http_response_code(403); exit("Serve la chiave: ?key=…\n");
  }
}

$file = __DIR__ . '/../dati/correzioni.json';
if (!is_file($file)) exit("✗ manca dati/correzioni.json\n");
$D = json_decode(file_get_contents($file), true);
if (!$D) exit("✗ dati/correzioni.json non è JSON valido\n");

const CAMPI_CORREGGIBILI = ['titolo','sintesi','perche_studiarla','cautela','rilevanza_politica','racconto',
  'mondo_nel_mondo','mondo_risposta','mondo_ricadute','mondo_cosa_cambia'];

$pdo = db();
$toccate = [];
foreach ($D['correzioni'] as $c) {
  $sid = $c['scheda_id'];
  if ($c['tipo'] === 'campo') {
    if (!in_array($c['campo'], CAMPI_CORREGGIBILI, true)) { echo "  ✗ $sid: campo «{$c['campo']}» non ammesso\n"; continue; }
    $st = $pdo->prepare("SELECT {$c['campo']} FROM pds_schede WHERE id=?");
    $st->execute([$sid]);
    $ora = $st->fetchColumn();
    if ($ora === false) { echo "  ? $sid non esiste\n"; continue; }
    if (str_contains((string)$ora, $c['dopo'])) { echo "  = $sid.{$c['campo']}: già applicata\n"; continue; }
    if (!str_contains((string)$ora, $c['prima'])) { echo "  ! $sid.{$c['campo']}: il testo di partenza non c'è più, salto\n"; continue; }
    $pdo->prepare("UPDATE pds_schede SET {$c['campo']}=? WHERE id=?")->execute([str_replace($c['prima'], $c['dopo'], (string)$ora), $sid]);
    echo "  ✓ $sid.{$c['campo']}\n";
  } elseif ($c['tipo'] === 'localizzatore' || $c['tipo'] === 'nota') {
    $col = $c['tipo'];
    $st = $pdo->prepare("UPDATE pds_scheda_fonte SET $col=? WHERE scheda_id=? AND fonte_id=? AND $col=?");
    $st->execute([$c['dopo'], $sid, $c['fonte_id'], $c['prima']]);
    echo $st->rowCount() ? "  ✓ $sid · {$c['fonte_id']} $col\n" : "  = $sid · {$c['fonte_id']}: già applicata o testo cambiato\n";
    if (!$st->rowCount()) continue;
  } else { echo "  ✗ tipo sconosciuto: {$c['tipo']}\n"; continue; }
  $toccate[$sid] = true;
}
echo "\nSchede toccate: " . (implode(',', array_keys($toccate)) ?: 'nessuna') . "\n";
if ($toccate) echo "Ripubblica: tools/pubblica_atlante.php?id=" . implode(',', array_keys($toccate)) . "\n";
