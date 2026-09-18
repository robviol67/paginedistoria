<?php
// Pagine di Storia — il file dati che leggono i filtri nel browser, generato
// dal database: assets/dati/atlante.json.
//
// È il §6 dell'handoff: un indice LEGGERO (niente corpi delle schede, che nel
// prototipo pesavano 780 KB), le fonti con il conteggio delle schede, le
// tassonomie e l'indice inverso fonte → schede. Un solo file, una sola
// richiesta: la «danza» dei tre script che si aspettano a vicenda, che il
// prototipo doveva fare, qui non esiste.
//
// Non è una fonte: nessuno lo modifica. Si rigenera a ogni pubblicazione
// (tools/pubblica_atlante.php), e la verità resta nel database.
require_once __DIR__ . '/atlante.php';
require_once __DIR__ . '/settings.php';

function pds_dati_atlante(): array {
  $pdo = db();

  $periodiDi = []; $principaleDi = [];
  foreach ($pdo->query('SELECT scheda_id, periodo_id, principale FROM pds_scheda_periodo ORDER BY periodo_id') as $r) {
    $periodiDi[$r['scheda_id']][] = $r['periodo_id'];
  }
  $temiDi = []; $etichetteDi = [];
  foreach ($pdo->query('SELECT scheda_id, tema, genere FROM pds_scheda_tema ORDER BY tema') as $r) {
    if ($r['genere'] === 'etichetta') $etichetteDi[$r['scheda_id']][] = $r['tema'];
    else $temiDi[$r['scheda_id']][] = $r['tema'];
  }
  $nFonti = []; $usi = [];
  foreach ($pdo->query('SELECT scheda_id, fonte_id FROM pds_scheda_fonte ORDER BY scheda_id, ordine') as $r) {
    $nFonti[$r['scheda_id']] = ($nFonti[$r['scheda_id']] ?? 0) + 1;
    if (!in_array($r['scheda_id'], $usi[$r['fonte_id']] ?? [], true)) $usi[$r['fonte_id']][] = $r['scheda_id'];
  }
  // n_doc: quante citazioni della scheda hanno un documento preciso (un
  // indirizzo all'atto). È ciò che il filtro «Solo con documento preciso» chiede.
  $nDoc = [];
  foreach ($pdo->query("SELECT scheda_id, COUNT(*) n FROM pds_scheda_fonte WHERE url_specifico IS NOT NULL AND url_specifico<>'' GROUP BY scheda_id") as $r) {
    $nDoc[$r['scheda_id']] = (int)$r['n'];
  }

  $indice = [];
  foreach ($pdo->query('SELECT * FROM pds_schede WHERE pubblicata=1 ORDER BY ordine, id') as $s) {
    $indice[] = [
      'id' => $s['id'], 'url' => atlante_url_scheda($s), 'tipologia' => $s['tipologia'], 'titolo' => $s['titolo'],
      'inizio' => $s['data_inizio'], 'fine' => $s['data_fine'],
      'periodi' => $periodiDi[$s['id']] ?? [], 'periodo' => $s['periodo_principale'],
      'temi' => $temiDi[$s['id']] ?? [], 'etichette' => $etichetteDi[$s['id']] ?? [],
      'sintesi' => (string)$s['sintesi'], 'n_fonti' => $nFonti[$s['id']] ?? 0, 'n_doc' => $nDoc[$s['id']] ?? 0,
      'stato' => $s['stato'], 'verdetto' => $s['verdetto'],
    ];
  }

  $fonti = [];
  foreach ($pdo->query('SELECT * FROM pds_fonti ORDER BY id') as $f) {
    $fonti[] = [
      'id' => $f['id'], 'url' => atlante_url_fonte($f), 'titolo' => $f['titolo'], 'autore_ente' => $f['autore_ente'],
      'natura' => $f['natura'], 'categoria' => $f['categoria'], 'ambito' => $f['ambito'], 'paese' => $f['paese'],
      'accesso' => $f['accesso'], 'esito' => $f['esito'], 'riscontrato' => (string)$f['riscontrato'] === '1',
      'editore' => $f['editore'], 'verifica_data' => $f['verifica_data'],
      'n_schede' => count($usi[$f['id']] ?? []),
    ];
  }

  $tass = fn(string $tipo) => atlante_tassonomia($tipo);
  $periodi = array_map(fn($p) => [
    'id' => $p['codice'], 'inizio' => (int)$p['anno_inizio'], 'fine' => (int)$p['anno_fine'], 'titolo' => $p['etichetta'],
    'url' => atlante_url_scheda(atlante_scheda($p['codice']) ?: $p['codice']),
  ], $tass('periodo'));

  // La data della verifica tecnica si legge dai dati (la più recente fra le
  // fonti), non si scrive a mano nel testo come faceva il prototipo.
  $dateVerifica = array_filter(array_column($fonti, 'verifica_data'));
  rsort($dateVerifica);

  return [
    'generato' => date('c'),
    'edizione' => setting_get('atlante_edizione', ''),
    'verifica_tecnica' => $dateVerifica[0] ?? null,
    'tassonomie' => [
      'tipologie' => array_map(fn($t) => ['id' => $t['codice'], 'nome' => $t['etichetta']], $tass('tipologia')),
      'periodi' => $periodi,
      'verdetti' => array_map(fn($v) => $v['codice'], $tass('verdetto')),
    ],
    'indice' => $indice,
    'fonti' => $fonti,
    'usi' => $usi,
  ];
}

function pds_pubblica_dati(): array {
  $dir = realpath(__DIR__ . '/..') . '/assets/dati';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $dati = pds_dati_atlante();
  $json = json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (file_put_contents("$dir/atlante.json", $json) === false) throw new Exception('scrittura di assets/dati/atlante.json non riuscita');
  return ['schede' => count($dati['indice']), 'fonti' => count($dati['fonti']), 'byte' => strlen($json)];
}
