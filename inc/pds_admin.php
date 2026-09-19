<?php
// Pagine di Storia — ciò che serve alla sezione Schede del pannello: scrivere
// nel database e ripubblicare. La lettura sta in inc/atlante.php; la resa delle
// pagine in inc/pds_*.php. Qui niente HTML.
require_once __DIR__ . '/atlante.php';
require_once __DIR__ . '/settings.php';

// ── protezione dei moduli ───────────────────────────────────────────────────
// Il pannello del motore non ne ha: un modulo del pannello si potrebbe far
// inviare da un'altra pagina a chi è già entrato. Per le sezioni che scrivono
// dati pubblicati è il minimo: un gettone per sessione, controllato a ogni POST.
function pds_csrf(): string {
  if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
  if (empty($_SESSION['pds_csrf'])) $_SESSION['pds_csrf'] = bin2hex(random_bytes(24));
  return $_SESSION['pds_csrf'];
}
function pds_csrf_campo(): string { return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(pds_csrf(), ENT_QUOTES) . '">'; }
function pds_csrf_ok(): bool {
  return isset($_POST['_csrf'], $_SESSION['pds_csrf']) && hash_equals($_SESSION['pds_csrf'], (string)$_POST['_csrf']);
}

// ── identificativi e slug ───────────────────────────────────────────────────
const PDS_PREFISSI = ['Periodo' => 'P', 'Tema' => 'T', 'Personaggio' => 'B', 'Evento' => 'E', 'Accade nel mondo' => 'M', 'Nesso' => 'N'];

// Il prossimo id libero della tipologia: E86 → E87. Si cerca il massimo, non
// si contano le righe: con una scheda tolta, contare darebbe un id già usato.
function pds_nuovo_id(string $tipologia): string {
  $pre = PDS_PREFISSI[$tipologia] ?? 'X';
  $st = db()->prepare('SELECT id FROM pds_schede WHERE id REGEXP ?');
  $st->execute(['^' . $pre . '[0-9]+$']);
  $max = 0;
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $max = max($max, (int)substr($id, strlen($pre)));
  return $pre . str_pad((string)($max + 1), 2, '0', STR_PAD_LEFT);
}

// Lo slug come quelli dell'edizione v1.15: minuscole, niente accenti, trattini.
function pds_slug(string $t): string {
  $t = mb_strtolower(trim($t));
  $t = strtr($t, ['à'=>'a','á'=>'a','è'=>'e','é'=>'e','ì'=>'i','í'=>'i','ò'=>'o','ó'=>'o','ù'=>'u','ú'=>'u','’'=>'-',"'"=>'-','«'=>'','»'=>'']);
  $t = preg_replace('/[^a-z0-9]+/', '-', $t);
  return trim($t, '-') ?: 'scheda';
}
function pds_slug_libero(string $slug, string $id): string {
  $base = $slug; $n = 2;
  $st = db()->prepare('SELECT 1 FROM pds_schede WHERE slug=? AND id<>?');
  while (true) { $st->execute([$slug, $id]); if (!$st->fetchColumn()) return $slug; $slug = $base . '-' . $n++; }
}

// ── salvataggio di una scheda ───────────────────────────────────────────────
const PDS_CAMPI_SCHEDA = ['tipologia','titolo','slug','data_inizio','data_fine','periodo_principale','sintesi','perche_studiarla',
  'cautela','verdetto','verdetto_nota','stato','pubblicata','verifica_data','verifica_note','rilevanza_politica','racconto','cronologia',
  'mondo_nel_mondo','mondo_risposta','mondo_ricadute','mondo_cosa_cambia',
  'nesso_arco','nesso_a_data','nesso_a_testo','nesso_b_data','nesso_b_testo','nesso_test','nesso_meccanismo',
  'nesso_favore','nesso_contro','nesso_rischio','nesso_ricadute','nesso_fonti_da_acquisire'];

// $d: i campi della scheda; $collegati: periodi, temi, collegamenti, citazioni.
// Tutto in una transazione: una scheda salvata a metà (con i campi nuovi e le
// citazioni vecchie) sarebbe peggio di un salvataggio non riuscito.
function pds_salva_scheda(?string $id, array $d, array $collegati): string {
  // Prima si completano i campi non inviati con quelli salvati, POI si
  // controlla: altrimenti un salvataggio parziale veniva rifiutato per un
  // titolo «vuoto» che nel database c'era.
  $prima = ($id !== null && $id !== '') ? atlante_scheda($id) : null;
  if ($prima) foreach (PDS_CAMPI_SCHEDA as $c) if (!array_key_exists($c, $d)) $d[$c] = $prima[$c];
  $errori = [];
  $d['titolo'] = trim((string)($d['titolo'] ?? ''));
  if ($d['titolo'] === '') $errori[] = 'il titolo è obbligatorio';
  if (!isset(PDS_PREFISSI[$d['tipologia'] ?? ''])) $errori[] = 'tipologia non valida';
  foreach (['data_inizio', 'data_fine'] as $c) {
    $v = trim((string)($d[$c] ?? ''));
    if ($v !== '' && !preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $v)) $errori[] = "$c: si scrive AAAA, AAAA-MM o AAAA-MM-GG";
  }
  // La cronologia nel pannello si scrive una riga per voce, «data | fatto | url»;
  // nel database sta in JSON, che è quello che legge la pagina.
  if (isset($d['cronologia']) && is_string($d['cronologia']) && substr(ltrim($d['cronologia']), 0, 1) !== '[') {
    $righe = [];
    foreach (preg_split('/\R/', $d['cronologia']) as $n => $l) {
      if (trim($l) === '') continue;
      $p = array_map('trim', explode('|', $l));
      if (count($p) < 2 || !preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $p[0])) { $errori[] = 'cronologia, riga ' . ($n + 1) . ': si scrive «AAAA-MM-GG | fatto | url»'; continue; }
      $righe[] = ['data' => $p[0], 'fatto' => $p[1], 'url' => $p[2] ?? ''];
    }
    $d['cronologia'] = $righe ? json_encode($righe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
  }
  if ($errori) throw new InvalidArgumentException('Non salvata: ' . implode('; ', $errori) . '.');

  $pdo = db();
  $pdo->beginTransaction();
  try {
    $nuova = $id === null || $id === '';
    if ($nuova) $id = pds_nuovo_id($d['tipologia']);
    // (I campi non inviati sono già stati completati in cima: il modulo di un
    // Evento non ha i campi dei Nessi, e scriverli vuoti vorrebbe dire
    // cancellare dati che non si stavano nemmeno guardando.)
    $d['slug'] = pds_slug_libero(pds_slug(trim((string)($d['slug'] ?? '')) ?: $d['titolo']), $id);
    $d['pubblicata'] = !empty($d['pubblicata']) ? 1 : 0;
    $vals = [];
    foreach (PDS_CAMPI_SCHEDA as $c) {
      $v = $d[$c] ?? null;
      $vals[] = is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
    }
    if ($nuova) {
      // L'ordine si calcola prima: MySQL non lascia leggere, dentro l'INSERT,
      // la tabella in cui si sta inserendo.
      $ordine = (int)$pdo->query('SELECT IFNULL(MAX(ordine),0)+1 FROM pds_schede')->fetchColumn();
      $pdo->prepare('INSERT INTO pds_schede (id,' . implode(',', PDS_CAMPI_SCHEDA) . ',ordine) VALUES (?' . str_repeat(',?', count(PDS_CAMPI_SCHEDA)) . ',?)')
          ->execute(array_merge([$id], $vals, [$ordine]));
    } else {
      $pdo->prepare('UPDATE pds_schede SET ' . implode('=?, ', PDS_CAMPI_SCHEDA) . '=? WHERE id=?')->execute(array_merge($vals, [$id]));
    }

    // Periodi: il principale è sempre fra quelli collegati.
    $periodi = array_values(array_unique(array_filter($collegati['periodi'] ?? [])));
    if (!empty($d['periodo_principale']) && !in_array($d['periodo_principale'], $periodi, true)) $periodi[] = $d['periodo_principale'];
    $pdo->prepare('DELETE FROM pds_scheda_periodo WHERE scheda_id=?')->execute([$id]);
    $ins = $pdo->prepare('INSERT INTO pds_scheda_periodo (scheda_id, periodo_id, principale) VALUES (?,?,?)');
    foreach ($periodi as $p) $ins->execute([$id, $p, $p === ($d['periodo_principale'] ?? null) ? 1 : 0]);

    $pdo->prepare("DELETE FROM pds_scheda_tema WHERE scheda_id=? AND genere='tema'")->execute([$id]);
    $ins = $pdo->prepare("INSERT INTO pds_scheda_tema (scheda_id, tema, genere) VALUES (?,?,'tema')");
    foreach (array_unique(array_filter($collegati['temi'] ?? [])) as $t) $ins->execute([$id, $t]);

    $pdo->prepare('DELETE FROM pds_scheda_relazione WHERE scheda_id=?')->execute([$id]);
    $ins = $pdo->prepare('INSERT IGNORE INTO pds_scheda_relazione (scheda_id, verso_id, relazione, ordine) VALUES (?,?,?,?)');
    $i = 0;
    foreach ($collegati['collegamenti'] ?? [] as $c) {
      $verso = strtoupper(trim((string)($c['verso_id'] ?? '')));
      if ($verso === '' || !empty($c['togli']) || $verso === $id) continue;
      if (!atlante_scheda($verso)) throw new InvalidArgumentException("Non salvata: il collegamento punta a «{$verso}», che non esiste.");
      $ins->execute([$id, $verso, $c['relazione'] ?: 'contesto', $i++]);
    }

    // Citazioni: la riga con localizzatore è il cuore del metodo. Una fonte
    // che non esiste nel repertorio non si accetta: la scheda la mostrerebbe
    // come un titolo vuoto.
    $pdo->prepare('DELETE FROM pds_scheda_fonte WHERE scheda_id=?')->execute([$id]);
    $ins = $pdo->prepare('INSERT INTO pds_scheda_fonte (scheda_id, fonte_id, ruolo, localizzatore, tipo_documento, data_documento, url_specifico, nota, verificato_il, ordine) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $righe = array_values(array_filter($collegati['citazioni'] ?? [], fn($c) => trim((string)($c['fonte_id'] ?? '')) !== '' && empty($c['togli'])));
    usort($righe, fn($a, $b) => (int)($a['ordine'] ?? 0) <=> (int)($b['ordine'] ?? 0));
    foreach ($righe as $n => $c) {
      $f = strtoupper(trim((string)$c['fonte_id']));
      if (!atlante_fonte($f)) throw new InvalidArgumentException("Non salvata: la fonte «{$f}» non è nel repertorio.");
      $u = trim((string)($c['url_specifico'] ?? ''));
      if ($u !== '' && !preg_match('#^https?://#i', $u)) throw new InvalidArgumentException("Non salvata: l'indirizzo della citazione di $f deve cominciare con http:// o https://.");
      $vuoto = fn($k) => trim((string)($c[$k] ?? '')) === '' ? null : trim((string)$c[$k]);
      $ins->execute([$id, $f, $vuoto('ruolo'), $vuoto('localizzatore'), $vuoto('tipo_documento'), $vuoto('data_documento'), $vuoto('url_specifico'), $vuoto('nota'), $vuoto('verificato_il'), $n]);
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
  // Slug cambiato: la pagina col vecchio indirizzo non deve restare online
  // con il contenuto di prima. (I link nel sito si rifanno alla ripubblicazione.)
  if ($prima && $prima['slug'] !== $d['slug']) @unlink(realpath(__DIR__ . '/..') . '/' . atlante_url_scheda($prima));
  return $id;
}

// Togliere una scheda: prima si vede chi la cita. Una scheda collegata da
// altre, o citata dai Nessi della Home o dal repertorio Media, non si toglie
// senza dirlo; si può sempre mettere «non pubblicata».
function pds_chi_cita(string $id): array {
  $out = [];
  $st = db()->prepare('SELECT scheda_id FROM pds_scheda_relazione WHERE verso_id=?'); $st->execute([$id]);
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $s) $out[] = "scheda $s (collegamento)";
  try { $st = db()->prepare('SELECT id FROM pds_media WHERE scheda_id=?'); $st->execute([$id]); foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $m) $out[] = "momento $m (Media)"; } catch (Throwable $e) {}
  if (in_array($id, array_map('trim', explode(',', setting_get('home_nessi_evidenza', 'N01,N02'))), true)) $out[] = 'la Home (Nessi in evidenza)';
  return $out;
}

function pds_togli_scheda(string $id): void {
  $r = atlante_scheda($id);
  if (!$r) return;
  $pdo = db();
  $pdo->beginTransaction();
  foreach (['pds_scheda_periodo', 'pds_scheda_tema', 'pds_scheda_fonte', 'pds_documenti'] as $t) $pdo->prepare("DELETE FROM $t WHERE scheda_id=?")->execute([$id]);
  $pdo->prepare('DELETE FROM pds_scheda_relazione WHERE scheda_id=? OR verso_id=?')->execute([$id, $id]);
  try { $pdo->prepare('UPDATE pds_media SET scheda_id=NULL WHERE scheda_id=?')->execute([$id]); } catch (Throwable $e) {}
  $pdo->prepare('DELETE FROM pds_schede WHERE id=?')->execute([$id]);
  $pdo->commit();
  @unlink(realpath(__DIR__ . '/..') . '/' . atlante_url_scheda($r));
}

// ── fonti ───────────────────────────────────────────────────────────────────
const PDS_CAMPI_FONTE = ['titolo','autore_ente','natura','categoria','ambito','paese','lingua','url','accesso','accesso_nota',
  'limiti','come_usarla','esito','riscontrato','verifica_data','editore','anno','edizione','isbn','copertura_inizio','copertura_fine'];

function pds_salva_fonte(?string $id, array $d): string {
  $d['titolo'] = trim((string)($d['titolo'] ?? ''));
  $nuova = $id === null || $id === '';
  $id = strtoupper(trim((string)($nuova ? ($d['id'] ?? '') : $id)));
  if ($d['titolo'] === '') throw new InvalidArgumentException('Non salvata: il titolo è obbligatorio.');
  if (!preg_match('/^[A-Z0-9]{2,24}$/', $id)) throw new InvalidArgumentException('Non salvata: la sigla va scritta con lettere maiuscole e cifre, da 2 a 24 caratteri (es. CAM, ASBI).');
  if ($nuova && atlante_fonte($id)) throw new InvalidArgumentException("Non salvata: la sigla $id è già usata.");
  if (!empty($d['url']) && !preg_match('#^https?://#i', trim($d['url']))) throw new InvalidArgumentException("Non salvata: l'indirizzo deve cominciare con http:// o https://.");
  $d['riscontrato'] = !empty($d['riscontrato']) ? '1' : '0';
  // La copertura da leggere si ricava dai due anni: non si scrive due volte.
  $ci = trim((string)($d['copertura_inizio'] ?? '')); $cf = trim((string)($d['copertura_fine'] ?? ''));
  $copertura = $ci !== '' ? $ci . '–' . ($cf !== '' ? $cf : 'oggi') : null;
  $vals = array_map(fn($c) => (is_string($d[$c] ?? null) && trim($d[$c]) === '') ? null : (is_string($d[$c] ?? null) ? trim($d[$c]) : ($d[$c] ?? null)), PDS_CAMPI_FONTE);
  if ($nuova) {
    db()->prepare('INSERT INTO pds_fonti (id,' . implode(',', PDS_CAMPI_FONTE) . ',copertura) VALUES (?' . str_repeat(',?', count(PDS_CAMPI_FONTE)) . ',?)')
        ->execute(array_merge([$id], $vals, [$copertura]));
  } else {
    db()->prepare('UPDATE pds_fonti SET ' . implode('=?, ', PDS_CAMPI_FONTE) . '=?, copertura=? WHERE id=?')->execute(array_merge($vals, [$copertura, $id]));
  }
  return $id;
}

// ── ripubblicazione ─────────────────────────────────────────────────────────
// Dopo un salvataggio si ripubblica tutto ciò che dipende dai dati: le schede
// (una scheda cambiata cambia i collegamenti delle altre e gli elenchi delle
// fonti), le fonti, il file dei filtri, Nessi, Media e le zone della Home. Ci
// vuole un secondo o due: meno del tempo per capire quale pagina si è
// dimenticata.
function pds_pubblica_tutto(): array {
  require_once __DIR__ . '/pds_scheda.php';
  require_once __DIR__ . '/pds_fonte.php';
  require_once __DIR__ . '/pds_dati.php';
  require_once __DIR__ . '/pds_nessi.php';
  require_once __DIR__ . '/pds_media.php';
  require_once __DIR__ . '/pds_home.php';
  $t0 = microtime(true);
  // Una scheda messa «non pubblicata» sparisce dal sito: la sua pagina si toglie.
  $radice = realpath(__DIR__ . '/..');
  foreach (db()->query('SELECT id, slug FROM pds_schede WHERE pubblicata=0')->fetchAll() as $r) @unlink($radice . '/' . atlante_url_scheda($r));
  $esito = ['schede' => pds_pubblica_schede(), 'fonti' => pds_pubblica_fonti(), 'dati' => pds_pubblica_dati()];
  foreach (['nessi' => 'pds_pubblica_nessi', 'media' => 'pds_pubblica_media', 'home' => 'pds_pubblica_home'] as $k => $f) {
    try { $esito[$k] = $f(); } catch (Throwable $e) { $esito[$k] = ['errore' => $e->getMessage()]; }
  }
  $esito['secondi'] = round(microtime(true) - $t0, 1);
  return $esito;
}
