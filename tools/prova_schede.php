<?php
// Pagine di Storia — prova della logica di scrittura del pannello, sul server,
// senza passare dall'interfaccia: crea una scheda di prova, la modifica, la
// nasconde, la toglie, e controlla database e pagine a ogni passo.
// Lascia il database com'era. Si lancia a mano:
//   https://…/tools/prova_schede.php?key=…
declare(strict_types=1);
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/pds_admin.php';
header('Content-Type: text/plain; charset=utf-8');
$atteso = defined('MIGRATION_KEY') ? MIGRATION_KEY : '';
if ($atteso === '' || !hash_equals($atteso, (string)($_GET['key'] ?? ''))) { http_response_code(403); exit("Serve la chiave\n"); }

$radice = realpath(__DIR__ . '/..');
$ok = 0; $ko = 0;
$prova = function (string $cosa, bool $esito, string $dettaglio = '') use (&$ok, &$ko) {
  $esito ? $ok++ : $ko++;
  echo ($esito ? '  ✓ ' : '  ✗ ') . $cosa . ($dettaglio !== '' ? "  ($dettaglio)" : '') . "\n";
};
$pagina = fn($r) => $radice . '/' . atlante_url_scheda($r);

// Una prova interrotta può aver lasciato la sua scheda: si toglie prima.
foreach (db()->query("SELECT id FROM pds_schede WHERE titolo LIKE 'Scheda di prova%'")->fetchAll(PDO::FETCH_COLUMN) as $vecchio) {
  pds_togli_scheda($vecchio); echo "  · tolta la scheda lasciata da una prova precedente: $vecchio\n";
}

echo "1 · nuova scheda\n";
$id = pds_salva_scheda(null, [
  'tipologia' => 'Evento', 'titolo' => 'Scheda di prova del pannello', 'data_inizio' => '1946', 'data_fine' => '',
  'periodo_principale' => 'P01', 'sintesi' => 'Prova automatica.', 'stato' => 'da_verificare', 'pubblicata' => '1',
], ['periodi' => ['P01'], 'temi' => ['Costituzione e istituzioni'],
    'collegamenti' => [['verso_id' => 'E03', 'relazione' => 'contesto']],
    'citazioni' => [['fonte_id' => 'CAM', 'ruolo' => 'primaria', 'localizzatore' => 'Prova, p. 1', 'ordine' => 0]]]);
$r = atlante_scheda($id);
$prova("id assegnato secondo la tipologia", (bool)preg_match('/^E\d+$/', $id), $id);
$prova("slug dal titolo", $r['slug'] === 'scheda-di-prova-del-pannello', $r['slug']);
$prova("periodo, tema, collegamento, citazione", count(atlante_periodi_di_scheda($id)) === 1 && count(atlante_temi_di_scheda($id)) === 1
  && count(atlante_fonti_di_scheda($id)) === 1 && (int)db()->query("SELECT COUNT(*) FROM pds_scheda_relazione WHERE scheda_id='$id'")->fetchColumn() === 1);
pds_pubblica_tutto();
$prova("la pagina pubblica esiste", is_file($pagina($r)));
$dati = json_decode((string)file_get_contents("$radice/assets/dati/atlante.json"), true);
$prova("è nel file dei filtri", in_array($id, array_column($dati['indice'], 'id'), true));

echo "2 · modifica del titolo: l'indirizzo resta; poi dello slug: la vecchia pagina sparisce\n";
$vecchia = $pagina($r);
pds_salva_scheda($id, ['tipologia' => 'Evento', 'titolo' => 'Scheda di prova rinominata', 'slug' => $r['slug']], ['periodi' => ['P01'], 'temi' => [], 'collegamenti' => [], 'citazioni' => [['fonte_id' => 'CAM', 'ruolo' => 'primaria', 'localizzatore' => 'Prova, p. 2']]]);
$r2 = atlante_scheda($id);
$prova("i campi non inviati restano com'erano", $r2['sintesi'] === 'Prova automatica.' && $r2['data_inizio'] === '1946', 'sintesi e data');
$prova("titolo nuovo, indirizzo stabile", $r2['titolo'] === 'Scheda di prova rinominata' && $r2['slug'] === $r['slug']);
pds_salva_scheda($id, ['slug' => 'scheda-di-prova-con-slug-nuovo'], ['periodi' => ['P01'], 'citazioni' => [['fonte_id' => 'CAM', 'ruolo' => 'primaria']]]);
$r2 = atlante_scheda($id);
$prova("slug cambiato a mano: la vecchia pagina non c'è più", !is_file($vecchia));
pds_pubblica_tutto();
$prova("la pagina col nuovo indirizzo c'è", is_file($pagina($r2)), atlante_url_scheda($r2));

echo "3 · rifiuti\n";
foreach ([
  'fonte inesistente' => fn() => pds_salva_scheda($id, ['tipologia' => 'Evento', 'titolo' => 'x'], ['citazioni' => [['fonte_id' => 'NONESISTE']]]),
  'collegamento inesistente' => fn() => pds_salva_scheda($id, ['tipologia' => 'Evento', 'titolo' => 'x'], ['collegamenti' => [['verso_id' => 'E999', 'relazione' => 'contesto']]]),
  'data malformata' => fn() => pds_salva_scheda($id, ['tipologia' => 'Evento', 'titolo' => 'x', 'data_inizio' => '46'], []),
  'titolo vuoto' => fn() => pds_salva_scheda($id, ['tipologia' => 'Evento', 'titolo' => ' '], []),
] as $cosa => $fn) {
  try { $fn(); $prova("rifiuta: $cosa", false, 'accettata!'); }
  catch (InvalidArgumentException $e) { $prova("rifiuta: $cosa", true); }
}
$r3 = atlante_scheda($id);
$prova("un rifiuto non tocca il database", $r3['titolo'] === 'Scheda di prova rinominata' && count(atlante_fonti_di_scheda($id)) === 1);

echo "4 · non pubblicata\n";
pds_salva_scheda($id, ['pubblicata' => '0'], ['periodi' => ['P01'], 'citazioni' => [['fonte_id' => 'CAM', 'ruolo' => 'primaria']]]);
pds_pubblica_tutto();
$prova("la pagina è tolta", !is_file($pagina($r3)));
$dati = json_decode((string)file_get_contents("$radice/assets/dati/atlante.json"), true);
$prova("non è più nel file dei filtri", !in_array($id, array_column($dati['indice'], 'id'), true));

echo "5 · togliere\n";
pds_togli_scheda($id);
pds_pubblica_tutto();
$prova("sparita dal database", atlante_scheda($id) === null);
$prova("nessuna riga orfana", (int)db()->query("SELECT (SELECT COUNT(*) FROM pds_scheda_fonte WHERE scheda_id='$id')+(SELECT COUNT(*) FROM pds_scheda_periodo WHERE scheda_id='$id')+(SELECT COUNT(*) FROM pds_scheda_relazione WHERE scheda_id='$id' OR verso_id='$id')")->fetchColumn() === 0);
$prova("il conto delle schede è tornato 182", (int)db()->query('SELECT COUNT(*) FROM pds_schede')->fetchColumn() === 182);

echo "\nesito: $ok riuscite, $ko fallite\n";
