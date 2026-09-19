<?php
// Pagine di Storia — migration dell'atlante: schede, fonti, localizzatori,
// tassonomie, relazioni. Idempotente; risponde a ?check=1 senza eseguire nulla.
//
// Impianto: il database è la fonte unica. Le pagine scheda e il file indice
// letto dai filtri si rigenerano da qui alla pubblicazione, come le altre
// pagine del motore.
//
// Una scelta che vale la pena avere in chiaro: i Nessi NON hanno una tabella
// propria. Nel design sono la sesta tipologia di scheda e ne condividono la
// pagina, quindi sono righe di pds_schede con tipologia='Nesso' più i campi
// del verdetto. Una tabella parallela avrebbe significato duplicare fonti,
// localizzatori, periodi e temi per un solo tipo di record.
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../inc/db.php';

$check = isset($_GET['check']);

try {
  $tabelle = ['pds_schede', 'pds_tassonomie', 'pds_scheda_periodo', 'pds_scheda_tema',
              'pds_fonti', 'pds_scheda_fonte', 'pds_scheda_relazione', 'pds_documenti', 'pds_media'];

  if ($check) {
    // Verifica in-process: una query su information_schema, nessun HTTP interno.
    $st = db()->prepare("SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('" . implode("','", $tabelle) . "')");
    $st->execute();
    $presenti = (int)$st->fetchColumn();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['applied' => $presenti === count($tabelle), 'tabelle' => $presenti . '/' . count($tabelle)]);
    exit;
  }

  // ── Le schede: il record editoriale ───────────────────────────────────────
  db()->exec("CREATE TABLE IF NOT EXISTS pds_schede (
      id VARCHAR(12) NOT NULL PRIMARY KEY,
      slug VARCHAR(160) NOT NULL,
      tipologia VARCHAR(32) NOT NULL,
      titolo VARCHAR(255) NOT NULL,
      data_inizio VARCHAR(16) NULL,
      data_fine VARCHAR(16) NULL,
      periodo_principale VARCHAR(12) NULL,
      sintesi TEXT NULL,
      perche_studiarla TEXT NULL,
      cautela TEXT NULL,
      verdetto VARCHAR(48) NULL,
      verdetto_nota TEXT NULL,
      stato VARCHAR(32) NOT NULL DEFAULT 'da_verificare',
      n_doc INT NOT NULL DEFAULT 0,
      pubblicata TINYINT NOT NULL DEFAULT 1,
      ordine INT NOT NULL DEFAULT 0,
      creata_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      aggiornata_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY slug_unico (slug),
      INDEX tipologia (tipologia),
      INDEX periodo_principale (periodo_principale),
      INDEX stato (stato)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  echo "OK  pds_schede\n";

  // ── Le tassonomie: periodi, temi, tipologie, verdetti, nature, accessi ────
  // Una tabella sola con un campo `tipo`: sono elenchi brevi e chiusi, con gli
  // stessi campi. Sei tabelle da dieci righe l'una sarebbero sei volte il
  // lavoro per la stessa cosa.
  db()->exec("CREATE TABLE IF NOT EXISTS pds_tassonomie (
      id INT AUTO_INCREMENT PRIMARY KEY,
      tipo VARCHAR(32) NOT NULL,
      codice VARCHAR(64) NOT NULL,
      etichetta VARCHAR(255) NOT NULL,
      descrizione TEXT NULL,
      anno_inizio SMALLINT NULL,
      anno_fine SMALLINT NULL,
      dati TEXT NULL,
      ordine INT NOT NULL DEFAULT 0,
      UNIQUE KEY tipo_codice (tipo, codice),
      INDEX tipo (tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  echo "OK  pds_tassonomie\n";

  // ── Scheda × periodo e scheda × tema ─────────────────────────────────────
  db()->exec("CREATE TABLE IF NOT EXISTS pds_scheda_periodo (
      scheda_id VARCHAR(12) NOT NULL,
      periodo_id VARCHAR(12) NOT NULL,
      principale TINYINT NOT NULL DEFAULT 0,
      PRIMARY KEY (scheda_id, periodo_id),
      INDEX periodo_id (periodo_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  echo "OK  pds_scheda_periodo\n";

  db()->exec("CREATE TABLE IF NOT EXISTS pds_scheda_tema (
      scheda_id VARCHAR(12) NOT NULL,
      tema VARCHAR(160) NOT NULL,
      genere VARCHAR(16) NOT NULL DEFAULT 'tema',
      PRIMARY KEY (scheda_id, genere, tema),
      INDEX tema (tema)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  echo "OK  pds_scheda_tema\n";

  // ── Le fonti, con la verifica tecnica ────────────────────────────────────
  db()->exec("CREATE TABLE IF NOT EXISTS pds_fonti (
      id VARCHAR(24) NOT NULL PRIMARY KEY,
      titolo VARCHAR(255) NOT NULL,
      autore_ente VARCHAR(255) NULL,
      natura VARCHAR(48) NULL,
      categoria VARCHAR(48) NULL,
      ambito VARCHAR(48) NULL,
      paese VARCHAR(48) NULL,
      lingua VARCHAR(24) NULL,
      url TEXT NULL,
      accesso VARCHAR(48) NULL,
      accesso_nota TEXT NULL,
      limiti TEXT NULL,
      come_usarla TEXT NULL,
      copertura VARCHAR(255) NULL,
      esito VARCHAR(48) NULL,
      riscontrato TEXT NULL,
      verifica_data VARCHAR(16) NULL,
      aggiornata_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX natura (natura),
      INDEX categoria (categoria)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  echo "OK  pds_fonti\n";

  // ── Il localizzatore: l'attribuzione puntuale scheda × fonte ─────────────
  // È il cuore editoriale del progetto: pagina, seduta, sentenza, documento.
  // Una scheda può citare la stessa fonte in due punti diversi, quindi la
  // chiave è la riga, non la coppia.
  db()->exec("CREATE TABLE IF NOT EXISTS pds_scheda_fonte (
      id INT AUTO_INCREMENT PRIMARY KEY,
      scheda_id VARCHAR(12) NOT NULL,
      fonte_id VARCHAR(24) NOT NULL,
      ruolo VARCHAR(48) NULL,
      localizzatore TEXT NULL,
      tipo_documento VARCHAR(160) NULL,
      data_documento VARCHAR(16) NULL,
      url_specifico TEXT NULL,
      nota TEXT NULL,
      verificato_il VARCHAR(16) NULL,
      ordine INT NOT NULL DEFAULT 0,
      INDEX scheda_id (scheda_id),
      INDEX fonte_id (fonte_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  echo "OK  pds_scheda_fonte\n";

  // ── I collegamenti fra schede (compresi i Nessi) ─────────────────────────
  db()->exec("CREATE TABLE IF NOT EXISTS pds_scheda_relazione (
      id INT AUTO_INCREMENT PRIMARY KEY,
      scheda_id VARCHAR(12) NOT NULL,
      verso_id VARCHAR(12) NOT NULL,
      relazione VARCHAR(48) NOT NULL DEFAULT 'collegata',
      nota TEXT NULL,
      ordine INT NOT NULL DEFAULT 0,
      UNIQUE KEY coppia (scheda_id, verso_id, relazione),
      INDEX verso_id (verso_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  echo "OK  pds_scheda_relazione\n";

  // ── I documenti: l'atto preciso dentro una fonte, per una scheda ─────────
  // Nel Design sono una raccolta a parte (AT_LOC): la citazione esatta, il tipo
  // di atto, la data, l'indirizzo e il giorno in cui è stato riscontrato. La
  // pagina di una fonte li elenca tutti; la scheda li usa per il pulsante
  // «Apri il documento». Alla prima importazione erano rimasti fuori.
  db()->exec("CREATE TABLE IF NOT EXISTS pds_documenti (
      id INT AUTO_INCREMENT PRIMARY KEY,
      scheda_id VARCHAR(12) NOT NULL,
      fonte_id VARCHAR(24) NOT NULL,
      descrizione TEXT NULL,
      citazione TEXT NULL,
      tipo_documento VARCHAR(160) NULL,
      data_documento VARCHAR(16) NULL,
      url TEXT NULL,
      verificata_il VARCHAR(16) NULL,
      ordine INT NOT NULL DEFAULT 0,
      INDEX scheda_fonte (scheda_id, fonte_id),
      INDEX fonte_id (fonte_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  echo "OK  pds_documenti\n";

  // ── Il repertorio mediale (pagina Media, §5.8) ────────────────────────────
  // Un momento radiofonico o televisivo: che cosa è andato in onda, dove si
  // trova il documento, che cosa prova. Il rimando a una scheda è facoltativo
  // e deciso a mano (dati/collegamenti-media.json): scheda_prototipo tiene
  // l'id che il Design aveva scritto, sbagliato quasi sempre, per memoria.
  db()->exec("CREATE TABLE IF NOT EXISTS pds_media (
      id VARCHAR(8) NOT NULL PRIMARY KEY,
      ordine INT NOT NULL DEFAULT 0,
      data_testo VARCHAR(64) NULL,
      anno SMALLINT NULL,
      mezzo VARCHAR(48) NULL,
      categoria VARCHAR(64) NULL,
      titolo VARCHAR(255) NOT NULL,
      programma VARCHAR(255) NULL,
      perche TEXT NULL,
      documento TEXT NULL,
      stato VARCHAR(32) NULL,
      scheda_id VARCHAR(12) NULL,
      scheda_prototipo VARCHAR(12) NULL,
      evidenza TINYINT NOT NULL DEFAULT 0,
      evidenza_testo TEXT NULL,
      pubblicata TINYINT NOT NULL DEFAULT 1,
      INDEX categoria (categoria),
      INDEX scheda_id (scheda_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  echo "OK  pds_media\n";

  // ── Campi che alla prima importazione erano rimasti fuori ────────────────
  // Aggiunti a posteriori, quindi con db_add_col: idempotente su MySQL e
  // MariaDB, e un secondo lancio non fa niente.
  $aggiunte = [
    ['pds_schede', 'verifica_data', 'VARCHAR(16) NULL'],
    ['pds_schede', 'verifica_note', 'TEXT NULL'],
    ['pds_schede', 'verifica_requisiti', 'TEXT NULL'],        // JSON: primaria_con_localizzatore, storiografia_con_editore, soddisfatti
    ['pds_schede', 'rilevanza_politica', 'TEXT NULL'],
    ['pds_schede', 'mondo_nel_mondo', 'TEXT NULL'],            // le quattro sezioni delle schede «Accade nel mondo»
    ['pds_schede', 'mondo_risposta', 'TEXT NULL'],
    ['pds_schede', 'mondo_ricadute', 'TEXT NULL'],
    ['pds_schede', 'mondo_cosa_cambia', 'TEXT NULL'],
    ['pds_fonti', 'editore', 'VARCHAR(255) NULL'],
    ['pds_fonti', 'anno', 'VARCHAR(16) NULL'],
    ['pds_fonti', 'edizione', 'VARCHAR(160) NULL'],
    ['pds_fonti', 'isbn', 'VARCHAR(32) NULL'],
    // La copertura nel Design è un oggetto {inizio, fine}: in una colonna di
    // testo PDO lo scriveva come la parola «Array». Due colonne numeriche, e
    // `copertura` resta il testo da mostrare («1943–2003», «1970–oggi»).
    ['pds_fonti', 'copertura_inizio', 'SMALLINT NULL'],
    ['pds_fonti', 'copertura_fine', 'SMALLINT NULL'],
    // Il corpo di un Nesso, con i nomi del Design (pagina Nessi e Scheda
    // nesso). Nel prototipo stava scritto a mano nella pagina Nessi; ora è
    // della scheda, e la pagina lo legge da qui.
    ['pds_schede', 'nesso_arco', 'VARCHAR(32) NULL'],
    ['pds_schede', 'nesso_a_data', 'VARCHAR(64) NULL'],      // corsia A · il fenomeno
    ['pds_schede', 'nesso_a_testo', 'TEXT NULL'],
    ['pds_schede', 'nesso_b_data', 'VARCHAR(64) NULL'],      // corsia B · l'esito politico
    ['pds_schede', 'nesso_b_testo', 'TEXT NULL'],
    ['pds_schede', 'nesso_test', 'TEXT NULL'],               // test cronologico
    ['pds_schede', 'nesso_meccanismo', 'TEXT NULL'],
    ['pds_schede', 'nesso_favore', 'TEXT NULL'],             // prove da cercare a favore
    ['pds_schede', 'nesso_contro', 'TEXT NULL'],             // prove da cercare contro
    ['pds_schede', 'nesso_rischio', 'TEXT NULL'],            // rischio di fallacia
    ['pds_schede', 'nesso_ricadute', 'TEXT NULL'],           // ricadute politiche da verificare
    ['pds_schede', 'nesso_fonti_da_acquisire', 'TEXT NULL'],
    ['pds_schede', 'nesso_provenienza', 'VARCHAR(160) NULL'],// da quale file del Design viene il corpo
    // L'arricchimento del 2026-09: il racconto lungo (paragrafi separati da una
    // riga vuota) e la sua cronologia, in JSON: [{data, fatto, fonte_id, url}].
    ['pds_schede', 'racconto', 'MEDIUMTEXT NULL'],
    ['pds_schede', 'cronologia', 'TEXT NULL'],
  ];
  foreach ($aggiunte as [$t, $c, $def]) {
    echo (db_add_col($t, $c, $def) ? "OK  + $t.$c\n" : "SKIP $t.$c (già presente)\n");
  }

  echo "\nFatto. Le tabelle sono pronte e vuote: i dati li porta dentro\n";
  echo "tools/importa_atlante.php, che si può rilanciare senza fare danni.\n";
} catch (Throwable $e) {
  http_response_code(500);
  echo "ERRORE: " . $e->getMessage() . "\n";
}
