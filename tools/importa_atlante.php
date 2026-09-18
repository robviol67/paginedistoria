<?php
// Pagine di Storia — versa dati/atlante.json nel database. Idempotente.
//
//   php tools/importa_atlante.php                    (da riga di comando)
//   https://…/tools/importa_atlante.php?key=…        (dal browser)
//
// Con --nuove (riga di comando) o &nuove=1 (browser) legge invece
// dati/schede-nuove.json: le schede scritte dopo l'edizione v1.15, con le loro
// fonti, i documenti e i momenti della pagina Media che vi rimandano.
//
// Regola di prudenza, e non è un dettaglio: di default questo script NON tocca
// i record già presenti. Dopo l'importazione iniziale la fonte unica è il
// database, e le correzioni fatte nel pannello valgono più del file di
// partenza: sovrascriverle in silenzio sarebbe il modo migliore per perdere
// una giornata di lavoro editoriale. Per sovrascrivere davvero serve &forza=1,
// che va chiesto a voce alta.
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

$forza = $daRiga
  ? in_array('--forza', $argv ?? [], true)
  : isset($_GET['forza']);

$nuove = $daRiga
  ? in_array('--nuove', $argv ?? [], true)
  : isset($_GET['nuove']);

$file = __DIR__ . '/../dati/' . ($nuove ? 'schede-nuove.json' : 'atlante.json');
if (!is_file($file)) exit('✗ manca dati/' . basename($file) . ($nuove ? "\n" : ": lancia prima `node tools/estrai_dati.js`\n"));
$D = json_decode(file_get_contents($file), true);
if (!$D) exit('✗ dati/' . basename($file) . " non è JSON valido\n");
$D += ['tassonomie' => [], 'fonti' => [], 'schede' => []];

echo "Edizione dati {$D['edizione']} · estratta il {$D['generato']}\n";
echo $forza ? "Modo: SOVRASCRIVO i record esistenti\n\n" : "Modo: aggiungo i mancanti, non tocco gli esistenti\n\n";

$pdo = db();
$n = ['tass_nuove' => 0, 'tass_agg' => 0, 'fonti_nuove' => 0, 'fonti_agg' => 0,
      'schede_nuove' => 0, 'schede_agg' => 0, 'saltate' => 0, 'loc' => 0, 'rel' => 0, 'doc' => 0, 'media' => 0];
$inserite = [];   // le schede nate in questo giro: i loro documenti vanno scritti comunque

try {
  $pdo->beginTransaction();

  // ── tassonomie ────────────────────────────────────────────────────────────
  $selT = $pdo->prepare('SELECT id FROM pds_tassonomie WHERE tipo=? AND codice=?');
  $insT = $pdo->prepare('INSERT INTO pds_tassonomie (tipo, codice, etichetta, descrizione, anno_inizio, anno_fine, dati, ordine) VALUES (?,?,?,?,?,?,?,?)');
  $updT = $pdo->prepare('UPDATE pds_tassonomie SET etichetta=?, descrizione=?, anno_inizio=?, anno_fine=?, dati=?, ordine=? WHERE id=?');
  foreach ($D['tassonomie'] as $t) {
    if (!$t['codice']) continue;
    $dati = isset($t['dati']) && $t['dati'] ? json_encode($t['dati'], JSON_UNESCAPED_UNICODE) : null;
    $selT->execute([$t['tipo'], $t['codice']]);
    $id = $selT->fetchColumn();
    if ($id === false) {
      $insT->execute([$t['tipo'], $t['codice'], $t['etichetta'] ?? $t['codice'], $t['descrizione'] ?? null,
                      $t['anno_inizio'] ?? null, $t['anno_fine'] ?? null, $dati, $t['ordine'] ?? 0]);
      $n['tass_nuove']++;
    } elseif ($forza) {
      $updT->execute([$t['etichetta'] ?? $t['codice'], $t['descrizione'] ?? null,
                      $t['anno_inizio'] ?? null, $t['anno_fine'] ?? null, $dati, $t['ordine'] ?? 0, $id]);
      $n['tass_agg']++;
    }
  }

  // ── fonti ─────────────────────────────────────────────────────────────────
  $campiF = ['titolo','autore_ente','natura','categoria','ambito','paese','lingua','url',
             'accesso','accesso_nota','limiti','come_usarla','copertura','esito','riscontrato','verifica_data',
             'editore','anno','edizione','isbn','copertura_inizio','copertura_fine'];
  $selF = $pdo->prepare('SELECT 1 FROM pds_fonti WHERE id=?');
  $insF = $pdo->prepare('INSERT INTO pds_fonti (id,' . implode(',', $campiF) . ') VALUES (?' . str_repeat(',?', count($campiF)) . ')');
  $updF = $pdo->prepare('UPDATE pds_fonti SET ' . implode('=?, ', $campiF) . '=? WHERE id=?');
  foreach ($D['fonti'] as $f) {
    $vals = array_map(fn($c) => $f[$c] ?? null, $campiF);
    $selF->execute([$f['id']]);
    if (!$selF->fetchColumn()) { $insF->execute(array_merge([$f['id']], $vals)); $n['fonti_nuove']++; }
    elseif ($forza) { $updF->execute(array_merge($vals, [$f['id']])); $n['fonti_agg']++; }
  }

  // ── schede, con periodi, temi, etichette e citazioni ──────────────────────
  $campiS = ['slug','tipologia','titolo','data_inizio','data_fine','periodo_principale',
             'sintesi','perche_studiarla','cautela','verdetto','verdetto_nota','stato','n_doc','ordine',
             'verifica_data','verifica_note','verifica_requisiti','rilevanza_politica',
             'mondo_nel_mondo','mondo_risposta','mondo_ricadute','mondo_cosa_cambia',
             'nesso_arco','nesso_a_data','nesso_a_testo','nesso_b_data','nesso_b_testo','nesso_test',
             'nesso_meccanismo','nesso_favore','nesso_contro','nesso_rischio','nesso_ricadute',
             'nesso_fonti_da_acquisire','nesso_provenienza'];
  $selS = $pdo->prepare('SELECT 1 FROM pds_schede WHERE id=?');
  $insS = $pdo->prepare('INSERT INTO pds_schede (id,' . implode(',', $campiS) . ') VALUES (?' . str_repeat(',?', count($campiS)) . ')');
  $updS = $pdo->prepare('UPDATE pds_schede SET ' . implode('=?, ', $campiS) . '=? WHERE id=?');

  $delP = $pdo->prepare('DELETE FROM pds_scheda_periodo WHERE scheda_id=?');
  $insP = $pdo->prepare('INSERT INTO pds_scheda_periodo (scheda_id, periodo_id, principale) VALUES (?,?,?)');
  $delT2 = $pdo->prepare('DELETE FROM pds_scheda_tema WHERE scheda_id=?');
  $insT2 = $pdo->prepare('INSERT INTO pds_scheda_tema (scheda_id, tema, genere) VALUES (?,?,?)');
  $delSF = $pdo->prepare('DELETE FROM pds_scheda_fonte WHERE scheda_id=?');
  $insSF = $pdo->prepare('INSERT INTO pds_scheda_fonte (scheda_id, fonte_id, ruolo, localizzatore, tipo_documento, data_documento, url_specifico, nota, verificato_il, ordine) VALUES (?,?,?,?,?,?,?,?,?,?)');

  $delR = $pdo->prepare('DELETE FROM pds_scheda_relazione WHERE scheda_id=?');
  $insR = $pdo->prepare('INSERT IGNORE INTO pds_scheda_relazione (scheda_id, verso_id, relazione, ordine) VALUES (?,?,?,?)');

  foreach ($D['schede'] as $s) {
    if (isset($s['verifica_requisiti']) && is_array($s['verifica_requisiti']))
      $s['verifica_requisiti'] = json_encode($s['verifica_requisiti'], JSON_UNESCAPED_UNICODE);
    $vals = array_map(fn($c) => $s[$c] ?? null, $campiS);
    $selS->execute([$s['id']]);
    $esiste = (bool)$selS->fetchColumn();
    if ($esiste && !$forza) { $n['saltate']++; continue; }

    if ($esiste) { $updS->execute(array_merge($vals, [$s['id']])); $n['schede_agg']++; }
    else { $insS->execute(array_merge([$s['id']], $vals)); $n['schede_nuove']++; $inserite[$s['id']] = true; }

    // I collegati si riscrivono in blocco: sono la fotografia della scheda,
    // non righe con vita propria.
    $delP->execute([$s['id']]);
    foreach (array_unique($s['periodi'] ?? []) as $p)
      $insP->execute([$s['id'], $p, $p === ($s['periodo_principale'] ?? null) ? 1 : 0]);

    $delT2->execute([$s['id']]);
    foreach (array_unique($s['temi'] ?? []) as $t) $insT2->execute([$s['id'], $t, 'tema']);
    foreach (array_unique($s['etichette'] ?? []) as $e) $insT2->execute([$s['id'], $e, 'etichetta']);

    $delR->execute([$s['id']]);
    foreach ($s['collegamenti'] ?? [] as $k) { $insR->execute([$s['id'], $k['verso_id'], $k['relazione'], $k['ordine']]); $n['rel']++; }

    $delSF->execute([$s['id']]);
    foreach ($s['fonti'] ?? [] as $f) {
      $insSF->execute([$s['id'], $f['fonte_id'], $f['ruolo'], $f['localizzatore'], $f['tipo_documento'],
                       $f['data_documento'], $f['url_specifico'], $f['nota'], $f['verificato_il'], $f['ordine']]);
      $n['loc']++;
    }
  }

  // ── documenti (AT_LOC) ────────────────────────────────────────────────────
  // Stessa prudenza delle schede: si scrivono se la tabella è vuota (prima
  // importazione) o se si chiede di sovrascrivere. Altrimenti restano quelli
  // del database, che potrebbero essere stati corretti nel pannello. Fanno
  // eccezione le schede appena inserite: i loro documenti non esistono ancora.
  $senzaDocumenti = $pdo->query('SELECT COUNT(*) FROM pds_documenti')->fetchColumn() == 0;
  $tutti = $forza || $senzaDocumenti;
  if ($tutti || $inserite) {
    $delD = $pdo->prepare('DELETE FROM pds_documenti WHERE scheda_id=?');
    $daScrivere = array_filter($D['documenti'] ?? [], fn($d) => $tutti || isset($inserite[$d['scheda_id']]));
    foreach (array_unique(array_column($daScrivere, 'scheda_id')) as $sid) $delD->execute([$sid]);
    $insD = $pdo->prepare('INSERT INTO pds_documenti (scheda_id, fonte_id, descrizione, citazione, tipo_documento, data_documento, url, verificata_il, ordine) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($daScrivere as $d) {
      $insD->execute([$d['scheda_id'], $d['fonte_id'], $d['descrizione'], $d['citazione'], $d['tipo_documento'],
                      $d['data_documento'], $d['url'], $d['verificata_il'], $d['ordine']]);
      $n['doc']++;
    }
  }

  // ── momenti della pagina Media che ora hanno una scheda ──────────────────
  // Si riempie solo un rimando vuoto: un collegamento scelto nel pannello non
  // si sovrascrive, nemmeno con «forza».
  if (!empty($D['media'])) {
    $updM = $pdo->prepare('UPDATE pds_media SET scheda_id=? WHERE id=? AND scheda_id IS NULL AND EXISTS (SELECT 1 FROM pds_schede WHERE id=?)');
    foreach ($D['media'] as $m) { $updM->execute([$m['scheda_id'], $m['id'], $m['scheda_id']]); $n['media'] += $updM->rowCount(); }
  }
  // Correzioni puntuali ai momenti (date, affermazioni smentite dai documenti):
  // un campo cambia solo se contiene ancora il testo di partenza.
  foreach ($D['media_correzioni'] ?? [] as $c) {
    if (!in_array($c['campo'], ['data_testo', 'perche', 'programma', 'evidenza_testo'], true)) continue;
    $updC = $pdo->prepare("UPDATE pds_media SET {$c['campo']}=? WHERE id=? AND {$c['campo']}=?");
    $updC->execute([$c['dopo'], $c['id'], $c['prima']]);
    $n['media_corr'] = ($n['media_corr'] ?? 0) + $updC->rowCount();
  }

  // La data dell'istantanea Wayback è un valore solo: sta nelle impostazioni
  // del motore (cms_settings, colonne skey/svalue), non in una tabella nuova.
  if (!empty($D['wayback_data'])) {
    require_once __DIR__ . '/../inc/settings.php';
    setting_set('atlante_wayback_data', $D['wayback_data']);
  }
  // I meta dell'edizione (titolo, versione, metodo, avvertenze, conteggi):
  // servono alle pagine e al pannello, e sono un blocco solo.
  if (!empty($D['meta'])) {
    require_once __DIR__ . '/../inc/settings.php';
    setting_set('atlante_meta', json_encode($D['meta'], JSON_UNESCAPED_UNICODE));
    setting_set('atlante_edizione', (string)($D['edizione'] ?? ''));
  }

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  exit("✗ ERRORE, niente è stato scritto: " . $e->getMessage() . "\n");
}

printf("tassonomie   +%d nuove, %d aggiornate\n", $n['tass_nuove'], $n['tass_agg']);
printf("fonti        +%d nuove, %d aggiornate\n", $n['fonti_nuove'], $n['fonti_agg']);
printf("schede       +%d nuove, %d aggiornate, %d lasciate stare\n", $n['schede_nuove'], $n['schede_agg'], $n['saltate']);
printf("citazioni    %d righe scheda×fonte\n", $n['loc']);
printf("collegamenti %d fra schede\n", $n['rel']);
printf("documenti    %d atti localizzati\n", $n['doc']);
if (!empty($D['media'])) printf("media        %d momenti collegati a una scheda\n", $n['media']);
if (!empty($D['media_correzioni'])) printf("media        %d correzioni applicate su %d\n", $n['media_corr'] ?? 0, count($D['media_correzioni']));
echo "\nFatto. Da qui in avanti la fonte è il database: le pagine si rigenerano\n";
echo "dal pannello, e questo file torna utile solo alla prossima edizione dati.\n";
