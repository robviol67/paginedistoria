<?php
// Pagine di Storia — la pagina di una scheda, generata dal database.
//
// È la traduzione in PHP del componente di prototipi/Scheda.dc.html: stessa
// struttura, stesse regole (raggruppamento delle fonti per ruolo, collegamenti
// per relazione, avvisi di stato), stessi testi. Dove il prototipo mostrava
// contenuti DI ESEMPIO (la cronologia di Mani Pulite, la tabella con
// «Esempio di procedimento A») qui non si copia niente: l'edizione v1.15 quei
// blocchi non li ha, e il Design stesso prevede per quel caso il riquadro
// «Blocco non compilato». Pubblicare l'esempio su 172 schede sarebbe stato
// pubblicare 172 falsi.
require_once __DIR__ . '/atlante.php';
require_once __DIR__ . '/pds_shell.php';
require_once __DIR__ . '/settings.php';

// Le sei marche, con le icone del kit (sprite v1, griglia 24, tratto 1,75).
const PDS_MARCHE = [
  'Periodo' => ['periodo', '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="square" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16"></rect><path d="M3 10h18M7 3v5M17 3v5"></path><path d="M7 15h10M7 18h6"></path></g></svg>'],
  'Tema' => ['tema', '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="square" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect></g></svg>'],
  'Personaggio' => ['personaggio', '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="square" stroke-linejoin="round"><circle cx="12" cy="7" r="4"></circle><path d="M4 21v-2a8 8 0 0 1 16 0v2"></path><path d="M9 20h6"></path></g></svg>'],
  'Evento' => ['evento', '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="square" stroke-linejoin="round"><path d="M6 21V3h13l-3 4 3 4H6"></path><path d="M3 21h9"></path></g></svg>'],
  'Accade nel mondo' => ['mondo', '<svg width="15" height="15" viewBox="0 0 24 24" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="square" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M3 12h18M12 3c-6 6-6 12 0 18M12 3c6 6 6 12 0 18"></path><path d="M6 6h12"></path></g></svg>'],
  'Nesso' => ['nesso', '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="square" stroke-linejoin="round"><path d="M3 5h11M3 19h11M8 5v14M14 5l5 7-5 7"></path><circle cx="19" cy="12" r="2"></circle></g></svg>'],
];

// Che cosa manca, per tipologia, quando il blocco specifico non c'è: sono le
// parole del Design, una per una.
const PDS_BLOCCHI_MANCANTI = [
  'Periodo' => 'snodi, mini-cronologia e domande guida',
  'Tema' => 'sviluppo nel tempo e domande guida',
  'Personaggio' => 'ruoli nel tempo, fase studiata e giudizi storiografici',
  'Evento' => 'svolgimento, risposta delle istituzioni ed esiti giudiziari',
  'Nesso' => 'test cronologico, prove e verdetto',
];

// L'ordine in cui il Design mostra i gruppi di collegamenti.
const PDS_ORDINE_RELAZIONI = ['causa', 'conseguenza', 'contesto', 'protagonista', 'parallelo internazionale', 'approfondisce'];

function pds_slug_tipo(string $t): string {
  return ['Periodo' => 'periodo', 'Tema' => 'tema', 'Personaggio' => 'personaggio', 'Evento' => 'evento',
          'Accade nel mondo' => 'mondo', 'Nesso' => 'nesso'][$t] ?? strtolower($t);
}

function pds_anni(array $r): string {
  $i = (string)($r['data_inizio'] ?? '');
  $f = (string)($r['data_fine'] ?? '');
  // Le graffe non sono decorative: senza, PHP legge il trattino lungo (UTF-8,
  // byte oltre 0x7F) come parte del nome della variabile, cerca «$i–», non la
  // trova e stampa solo l'anno finale. Su tutte le schede con un intervallo.
  return ($f !== '' && $f !== $i) ? "{$i}–{$f}" : $i;
}

function pds_data_breve(?string $iso): string {
  if (!$iso || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) return '';
  return "$m[3]/$m[2]/$m[1]";
}

// L'istantanea Wayback: il Design tiene UNA data e costruisce l'indirizzo.
function pds_wayback(string $url): array {
  $data = setting_get('atlante_wayback_data', '');
  $ts = preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $data, $m) ? "$m[3]$m[2]$m[1]000000" : '2';
  return [$url !== '' ? "https://web.archive.org/web/$ts/$url" : '', $data];
}

// ── le fonti di una scheda, raggruppate come nel Design ─────────────────────
function pds_gruppi_fonti(string $schedaId): array {
  $ruoli = atlante_tassonomia('ruolo_fonte');
  $avvisi = [];
  foreach (atlante_tassonomia('avviso_accesso') as $a) $avvisi[$a['codice']] = $a['etichetta'];

  // I documenti della scheda, per fonte: servono al pulsante «Apri» quando la
  // citazione non porta un indirizzo suo.
  $st = db()->prepare('SELECT * FROM pds_documenti WHERE scheda_id=? ORDER BY ordine, id');
  $st->execute([$schedaId]);
  $docPer = [];
  foreach ($st->fetchAll() as $d) $docPer[$d['fonte_id']][] = $d;

  $st = db()->prepare('SELECT sf.*, f.titolo AS f_titolo, f.autore_ente, f.natura, f.categoria, f.ambito,
                              f.paese, f.lingua, f.url AS f_url, f.accesso, f.editore, f.anno, f.isbn
                       FROM pds_scheda_fonte sf LEFT JOIN pds_fonti f ON f.id = sf.fonte_id
                       WHERE sf.scheda_id=? ORDER BY sf.ordine, sf.id');
  $st->execute([$schedaId]);
  $citazioni = $st->fetchAll();

  $gruppi = [];
  foreach ($ruoli as $r) {
    $voci = [];
    foreach ($citazioni as $c) {
      if (($c['ruolo'] ?? '') !== $r['codice']) continue;
      $docs = $docPer[$c['fonte_id']] ?? [];
      $d = $docs[0] ?? [];
      $url = $c['url_specifico'] ?: ($d['url'] ?? '');
      $libro = ($c['categoria'] ?? '') === 'Libro';
      $edizione = $libro ? trim(implode(', ', array_filter([$c['editore'], $c['anno']])) . ($c['isbn'] ? ' · ISBN ' . $c['isbn'] : '')) : '';
      $loc = $c['localizzatore'] ?: (($d['citazione'] ?? '') ?: (($d['descrizione'] ?? '') ?: $edizione));
      $tipoDoc = ($d['tipo_documento'] ?? '') ?: ($c['tipo_documento'] ?? '');
      [$archivio, $dataArchivio] = pds_wayback($url ?: (string)$c['f_url']);
      $avviso = $avvisi[$c['accesso'] ?? ''] ?? '';
      $voci[] = [
        'fonte_id' => $c['fonte_id'],
        'titolo' => $c['f_titolo'] ?: $c['fonte_id'],
        'etichetta' => $c['categoria'] ?: (($c['natura'] ?? '') === 'storiografica' ? 'Storiografia' : 'Fonte'),
        'lingua' => strtoupper($c['lingua'] ?: 'it'),
        'ambito' => ($c['ambito'] ?? '') === 'internazionale' ? ($c['paese'] ?: 'INT') : 'IT',
        'localizzatore' => $loc ?: 'Localizzatore da indicare in revisione',
        'url' => $url,
        'doc_label' => $tipoDoc ? 'Apri: ' . $tipoDoc : 'Apri il documento',
        'cerca' => (string)$c['f_url'],
        'archivio' => $archivio,
        'data_archivio' => $dataArchivio,
        'avviso' => ($avviso ? $avviso . '. ' : '') . ($url ? '' : 'Documento preciso non ancora accertato: si apre il repertorio.'),
      ];
    }
    if ($voci) {
      $dati = $r['dati'] ? (json_decode($r['dati'], true) ?: []) : [];
      $gruppi[] = ['nome' => $r['etichetta'], 'nota' => $dati['nota'] ?? '', 'voci' => $voci];
    }
  }
  return $gruppi;
}

function pds_gruppi_collegamenti(string $schedaId): array {
  $st = db()->prepare('SELECT r.relazione, s.id, s.slug, s.titolo, s.data_inizio, s.data_fine
                       FROM pds_scheda_relazione r JOIN pds_schede s ON s.id = r.verso_id
                       WHERE r.scheda_id=? AND s.pubblicata=1 ORDER BY r.ordine, r.id');
  $st->execute([$schedaId]);
  $tutti = $st->fetchAll();
  $gruppi = [];
  foreach (PDS_ORDINE_RELAZIONI as $rel) {
    $voci = array_values(array_filter($tutti, fn($c) => $c['relazione'] === $rel));
    if ($voci) $gruppi[] = ['relazione' => mb_strtoupper(mb_substr($rel, 0, 1)) . mb_substr($rel, 1), 'voci' => $voci];
  }
  return $gruppi;
}

function pds_etichetta_stato(array $r): string {
  $data = pds_data_breve($r['verifica_data'] ?? null);
  switch ($r['stato']) {
    case 'verificata':
      return $data ? "Verificata il $data su fonti primarie e storiografia" : 'Verificata su fonti primarie e storiografia';
    case 'in_revisione':
      return 'Scheda in revisione: date e attribuzioni in corso di riscontro';
    case 'documentazione_insufficiente':
      return 'Documentazione insufficiente — segnaposto' . ($data ? ", ultimo controllo $data" : '');
    default:
      return 'Verifica in corso' . ($data ? " — ultimo controllo $data" : '');
  }
}

// JSON-LD: Article sempre; Person o Event secondo la tipologia (handoff §6.7).
function pds_jsonld(array $r, string $url): string {
  $dominio = defined('SITE_DOMAIN') ? rtrim(SITE_DOMAIN, '/') : '';
  $articolo = [
    '@context' => 'https://schema.org', '@type' => 'Article',
    'headline' => $r['titolo'], 'description' => (string)$r['sintesi'],
    'url' => "$dominio/$url", 'inLanguage' => 'it',
    'publisher' => ['@type' => 'Organization', 'name' => 'Pagine di Storia', 'url' => $dominio],
    'isPartOf' => ['@type' => 'WebSite', 'name' => 'Pagine di Storia', 'url' => $dominio],
  ];
  if (!empty($r['verifica_data'])) $articolo['dateModified'] = $r['verifica_data'];
  if ($r['tipologia'] === 'Personaggio') {
    $articolo['about'] = ['@type' => 'Person', 'name' => $r['titolo']];
  } elseif (in_array($r['tipologia'], ['Evento', 'Accade nel mondo'], true)) {
    $ev = ['@type' => 'Event', 'name' => $r['titolo']];
    if (!empty($r['data_inizio'])) $ev['startDate'] = (string)$r['data_inizio'];
    if (!empty($r['data_fine'])) $ev['endDate'] = (string)$r['data_fine'];
    $articolo['about'] = $ev;
  }
  return '<script type="application/ld+json">' . json_encode($articolo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
}

// ── il Nesso: scala dei verdetti e corpo ─────────────────────────────────────
function pds_scala_verdetti(?string $attivo): string {
  $h = '<div class="pds-verdetto-scala">';
  foreach (atlante_tassonomia('verdetto') as $v)
    $h .= '<div' . ($v['codice'] === $attivo ? ' class="attivo" aria-current="true"' : '') . '>'
        . pesc(mb_strtoupper(mb_substr($v['etichetta'], 0, 1)) . mb_substr($v['etichetta'], 1)) . '</div>';
  return $h . '</div>';
}

// Le parti del corpo di un Nesso, con le etichette del Design (pagina Nessi).
const PDS_PARTI_NESSO = [
  'test' => 'Test cronologico', 'meccanismo' => 'Meccanismo ipotizzato',
  'favore' => 'Prove da cercare a favore', 'contro' => 'Prove da cercare contro',
  'rischio' => 'Rischio di fallacia', 'ricadute' => 'Ricadute politiche da verificare',
  'fonti_da_acquisire' => 'Fonti da acquisire',
];

function pds_nesso_corpo(array $r, bool $conProvenienza = true): string {
  $v = fn(string $k) => trim((string)($r['nesso_' . $k] ?? ''));
  $h = '';

  if ($v('a_testo') !== '' || $v('b_testo') !== '') {
    $h .= '<div class="pds-nesso-corsie">'
        . '<div><p class="pds-nesso-et">Corsia A · il fenomeno</p><p class="num pds-nesso-data">' . pesc($v('a_data')) . '</p><p>' . pesc($v('a_testo')) . '</p></div>'
        . '<div><p class="pds-nesso-et">Corsia B · l’esito politico</p><p class="num pds-nesso-data">' . pesc($v('b_data')) . '</p><p>' . pesc($v('b_testo')) . '</p></div>'
        . '</div>';
  }
  $coppia = function (string $a, string $b, string $classe = 'pds-nesso-due') use ($v) {
    $out = '';
    foreach ([$a, $b] as $k) if ($v($k) !== '')
      $out .= '<div class="pds-nesso-' . $k . '"><p class="pds-nesso-et">' . PDS_PARTI_NESSO[$k] . '</p><p>' . pesc($v($k)) . '</p></div>';
    return $out !== '' ? '<div class="' . $classe . '">' . $out . '</div>' : '';
  };
  $h .= $coppia('test', 'meccanismo');
  $h .= $coppia('favore', 'contro', 'pds-nesso-prove');
  $coda = '';
  foreach (['rischio', 'ricadute', 'fonti_da_acquisire'] as $k) if ($v($k) !== '')
    $coda .= '<div><p class="pds-nesso-et">' . PDS_PARTI_NESSO[$k] . '</p><p' . ($k === 'fonti_da_acquisire' ? ' class="num"' : '') . '>' . pesc($v($k)) . '</p></div>';
  if ($coda !== '') $h .= '<div class="pds-nesso-coda">' . $coda . '</div>';

  // Ciò che manca si dichiara, parte per parte: un Nesso a metà non deve
  // sembrare completo.
  $mancano = [];
  foreach (['test', 'meccanismo', 'favore', 'contro'] as $k) if ($v($k) === '') $mancano[] = mb_strtolower(PDS_PARTI_NESSO[$k]);
  if ($mancano) {
    $h .= '<section class="pds-non-compilato"><p>' . ($h === '' ? 'Blocco non compilato' : 'Parti non compilate') . '</p><p>Per questo Nesso l’edizione dati v1.15 non contiene '
        . implode(', ', $mancano) . ': ' . ($h === '' ? 'il blocco resta vuoto' : 'restano vuote') . ' in attesa della revisione.</p></section>';
  }
  if ($conProvenienza && $v('provenienza') !== '') {
    $h .= '<p class="pds-nesso-provenienza">Corpo del Nesso tratto da ' . pesc($v('provenienza')) . '.</p>';
  }
  return $h !== '' ? '<section class="pds-nesso">' . $h . "</section>\n" : '';
}

// ── la pagina ───────────────────────────────────────────────────────────────
function pds_scheda_render_doc(string $id): ?string {
  $r = atlante_scheda($id);
  if (!$r) return null;

  $t = $r['tipologia'];
  $anni = pds_anni($r);
  $url = atlante_url_scheda($r);
  $periodo = null;
  foreach (atlante_tassonomia('periodo') as $p) if ($p['codice'] === $r['periodo_principale']) $periodo = $p;
  $periodoLabel = $t === 'Periodo'
    ? "$anni · {$r['titolo']}"
    : ($periodo ? "{$periodo['anno_inizio']}–{$periodo['anno_fine']} · {$periodo['etichetta']}" : 'periodo da assegnare');
  $temi = atlante_temi_di_scheda($id);
  $gf = pds_gruppi_fonti($id);
  $gc = pds_gruppi_collegamenti($id);
  $nFonti = array_sum(array_map(fn($g) => count($g['voci']), $gf));
  $nColl = array_sum(array_map(fn($g) => count($g['voci']), $gc));
  $statoLabel = pds_etichetta_stato($r);
  $dominio = defined('SITE_DOMAIN') ? rtrim(SITE_DOMAIN, '/') : '';
  [$marcaClasse, $marcaIcona] = PDS_MARCHE[$t] ?? PDS_MARCHE['Evento'];
  $slugTipo = pds_slug_tipo($t);

  $h  = pds_head($r['titolo'] . ' — Scheda ' . $r['id'] . ' | Pagine di Storia',
                 mb_substr((string)$r['sintesi'], 0, 180), $url);
  $h  = str_replace("</head>", pds_jsonld($r, $url) . "<script src=\"" . pds_asset('assets/scheda.js') . "\" defer></script>\n</head>", $h);
  $h .= pds_header('atlante.html');

  $h .= '<main class="pds-scheda">' . "\n";
  $h .= '<div data-print-only class="pds-stampa-testa"><span>Pagine di Storia 1943–2002</span><span class="num">Scheda '
      . pesc($r['id']) . ' · ' . pesc($t) . ' · ' . pesc($anni) . "</span></div>\n";
  $h .= '<nav class="pds-percorso" aria-label="Percorso"><a href="atlante.html">Storia</a><span>›</span>'
      . '<a href="atlante.html?tipo=' . pesc($slugTipo) . '">' . pesc($t) . '</a><span>›</span>'
      . '<a href="cronologia.html' . ($r['periodo_principale'] ? '?periodo=' . pesc($r['periodo_principale']) : '') . '">' . pesc($periodoLabel) . "</a></nav>\n";

  $h .= '<div class="pds-scheda-griglia">' . "\n<article class=\"pds-scheda-corpo\">\n";

  // intestazione
  $h .= '<div class="pds-marca-riga"><span class="pds-marca pds-marca--' . $marcaClasse . '">' . $marcaIcona . '</span>'
      . '<span class="pds-marca-tipo">' . pesc($t) . '</span>'
      . ($anni !== '' ? '<span class="num pds-marca-anni">' . pesc($anni) . '</span>' : '')
      . '<span class="num pds-marca-id">Scheda ' . pesc($r['id']) . "</span></div>\n";
  $h .= '<h1>' . pesc($r['titolo']) . "</h1>\n";
  if ($r['sintesi']) $h .= '<p class="pds-scheda-sintesi">' . pesc($r['sintesi']) . "</p>\n";
  if ($temi) {
    $h .= '<div class="pds-temi">';
    foreach ($temi as $tema) $h .= '<a class="tag tag-neutral" href="atlante.html?tema=' . rawurlencode($tema) . '">' . pesc($tema) . '</a>';
    $h .= "</div>\n";
  }

  // stato
  if (in_array($r['stato'], ['da_verificare', 'in_revisione'], true)) {
    $h .= '<div role="status" class="pds-stato-revisione"><p>Scheda in revisione</p>'
        . "<p>Date e attribuzioni in corso di riscontro sulle fonti. Il testo può cambiare: non citarlo come accertato.</p></div>\n";
  } elseif ($r['stato'] === 'documentazione_insufficiente') {
    $h .= '<div role="status" data-print-block class="pds-stato-insufficiente"><p class="pds-nota-titolo">Documentazione insufficiente</p>'
        . '<p class="pds-lettura-breve">La scheda non raggiunge i minimi del metodo — due fonti primarie e un riscontro storiografico — e resta pubblicata come segnaposto: titolo, date e collegamenti sono provvisori, la sintesi non è accertata.</p>'
        . '<div data-print-hide class="pds-azioni" style="border:0;padding:0"><a class="btn btn-primary" href="segnala.html?scheda=' . pesc($r['id']) . '">Proponi una fonte</a><a class="btn btn-secondary" href="metodo.html">Minimi di verifica</a></div></div>' . "\n";
  }

  $h .= '<hr class="hr" id="corpo">' . "\n";

  // blocco specifico della tipologia
  if ($t === 'Evento') {
    $h .= '<section class="pds-dati-evento"><dl class="num"><dt>Data</dt><dd>' . pesc($anni ?: '—') . '</dd>'
        . '<dt>Luogo</dt><dd class="pds-da-indicare">Luogo da indicare in revisione</dd>'
        . '<dt>Istituzioni</dt><dd class="pds-da-indicare">Istituzioni da indicare in revisione</dd></dl></section>' . "\n";
  }
  if ($t === 'Accade nel mondo') {
    $sezioni = array_filter([
      ['01', 'Accade nel mondo', $r['mondo_nel_mondo']],
      ['02', 'La risposta delle istituzioni italiane', $r['mondo_risposta']],
      ['03', 'Ricadute sulla politica interna', $r['mondo_ricadute']],
      ['04', 'Che cosa cambia per l’Italia', $r['mondo_cosa_cambia']],
    ], fn($x) => trim((string)$x[2]) !== '');
    if ($sezioni) {
      $h .= '<section class="pds-mondo">';
      foreach ($sezioni as [$n, $tit, $testo])
        $h .= '<div><p class="num pds-mondo-titolo">' . $n . ' · ' . pesc($tit) . '</p><p>' . pesc($testo) . '</p></div>';
      $h .= "</section>\n";
    }
  } elseif ($t === 'Nesso') {
    // Il verdetto (dalla scala a sette) e il corpo del Nesso: corsie A e B,
    // test, meccanismo, prove, rischio, ricadute. Lo stesso blocco disegna le
    // voci della pagina Nessi (pds_nesso_corpo), così non ne esistono due.
    if (!empty($r['verdetto'])) {
      $h .= '<section class="pds-verdetto"><h2 class="pds-relazione" style="font-size:13px">Verdetto provvisorio</h2>' . pds_scala_verdetti($r['verdetto']);
      if ($r['verdetto_nota']) $h .= '<p class="pds-lettura-breve">' . pesc($r['verdetto_nota']) . '</p>';
      $h .= "</section>\n";
    }
    $h .= pds_nesso_corpo($r);
  } elseif (isset(PDS_BLOCCHI_MANCANTI[$t])) {
    $h .= '<section class="pds-non-compilato"><p>Blocco non compilato</p><p>Per questa scheda l’edizione dati v1.15 non contiene '
        . PDS_BLOCCHI_MANCANTI[$t] . ': il blocco resta vuoto in attesa della revisione.</p></section>' . "\n";
  }

  if (trim((string)$r['rilevanza_politica']) !== '') {
    $h .= '<section class="pds-sezione" id="rilevanza"><h2>Rilevanza politica</h2><p class="pds-lettura-breve">' . pesc($r['rilevanza_politica']) . "</p></section>\n";
  }

  if ($r['perche_studiarla']) {
    $h .= '<section class="pds-sezione" id="perche"><h2>Perché studiarla</h2><p class="pds-lettura-breve">' . pesc($r['perche_studiarla']) . "</p></section>\n";
  }
  if ($r['cautela']) {
    $h .= '<aside id="cautela" data-print-block class="pds-cautela"><p><svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="square" stroke-linejoin="round"><path d="M12 3 2 21h20Z"></path><path d="M12 9v5M12 18v.1"></path></g></svg> Fatti, atti e interpretazioni: la distinzione da tenere</p>'
        . '<p>' . pesc($r['cautela']) . "</p></aside>\n";
  }

  // collegamenti
  $h .= '<section class="pds-sezione pds-sezione--larga" id="collegamenti"><h2>Collegamenti</h2><div class="pds-collegamenti">';
  foreach ($gc as $g) {
    $h .= '<div><p class="pds-relazione">' . pesc($g['relazione']) . '</p><div class="pds-voci">';
    foreach ($g['voci'] as $c)
      $h .= '<a class="pds-voce-collegata" href="' . pesc(atlante_url_scheda($c)) . '"><span class="num id">' . pesc($c['id']) . '</span>'
          . '<span class="titolo">' . pesc($c['titolo']) . '</span><span class="num anni">' . pesc(pds_anni($c)) . '</span></a>';
    $h .= '</div></div>';
  }
  if (!$gc) $h .= '<p class="pds-vuoto">Nessun collegamento confermato per questa scheda. I collegamenti si aggiungono in revisione, con la relazione dichiarata (causa, conseguenza, contesto, protagonista).</p>';
  $h .= "</div></section>\n";

  // fonti
  $h .= '<section class="pds-sezione pds-sezione--larga" id="fonti" data-print-urls><h2>Fonti</h2>';
  foreach ($gf as $g) {
    $h .= '<div class="pds-gruppo-fonti"><p class="pds-gruppo-nome">' . pesc($g['nome']) . '</p>';
    if ($g['nota']) $h .= '<p class="pds-gruppo-nota">' . pesc($g['nota']) . '</p>';
    $h .= '<div class="pds-voci">';
    foreach ($g['voci'] as $v) {
      $h .= '<div data-print-block class="pds-voce-fonte"><div class="pds-voce-testa">'
          . '<span class="pds-voce-etichetta">' . pesc($v['etichetta']) . '</span>'
          . '<span class="tag">' . pesc($v['lingua']) . '</span><span class="tag">' . pesc($v['ambito']) . '</span></div>'
          . '<p class="pds-voce-titolo"><a href="' . pesc(atlante_url_fonte($v['fonte_id'])) . '">' . pesc($v['titolo']) . '</a></p>'
          . '<p class="num pds-localizzatore">' . pesc($v['localizzatore']) . '</p><div class="pds-voce-azioni">';
      $h .= $v['url']
        ? '<a class="btn btn-secondary" href="' . pesc($v['url']) . '" target="_blank" rel="noopener">' . pesc($v['doc_label']) . '</a>'
        : '<span class="btn btn-secondary" aria-disabled="true" title="Localizzatore non ancora accertato">Documento</span>';
      if ($v['cerca']) $h .= '<a class="btn btn-secondary" href="' . pesc($v['cerca']) . '" target="_blank" rel="noopener">Cerca nel repertorio</a>';
      if ($v['archivio']) $h .= '<a class="btn btn-secondary" href="' . pesc($v['archivio']) . '" target="_blank" rel="noopener">Copia archiviata' . ($v['data_archivio'] ? ' · ' . pesc($v['data_archivio']) : '') . '</a>';
      $h .= '</div>';
      if (!$v['url'] || trim($v['avviso']) !== '') $h .= '<p class="pds-voce-avviso">' . pesc(trim($v['avviso'])) . '</p>';
      $h .= '</div>';
    }
    $h .= '</div></div>';
  }
  if (!$gf) {
    $h .= '<div class="pds-non-compilato"><p class="pds-lettura-breve" style="font-size:15px;margin:0 0 var(--space-2)">Nessuna fonte è ancora collegata a questa scheda: senza localizzatori accertati la scheda resta un segnaposto.</p>'
        . '<div class="pds-voce-azioni"><a class="btn btn-secondary" href="fonti.html">Repertorio delle fonti</a><a class="btn btn-secondary" href="segnala.html?scheda=' . pesc($r['id']) . '">Segnala una fonte</a></div></div>';
  }
  $h .= "</section>\n";

  // azioni: la raccolta arriverà con assets/atlante.js; qui solo ciò che funziona già
  $h .= '<div class="pds-azioni" data-print-hide>'
      . '<button class="btn btn-secondary" type="button" data-cita data-cita-testo="' . pesc('«' . $r['titolo'] . '», scheda ' . $r['id'] . ', in Pagine di Storia, ' . $dominio . '/' . $url) . '">Cita questa scheda</button>'
      . '<a class="btn btn-secondary" href="segnala.html?scheda=' . pesc($r['id']) . '">Segnala una correzione</a>'
      . '<button class="btn btn-secondary" type="button" data-stampa>Stampa</button></div>' . "\n";
  $h .= '<p class="num pds-stato-riga"><svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="square" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M7 12l3 3 7-7"></path></g></svg> ' . pesc($statoLabel) . "</p>\n";
  $h .= '<div data-print-only class="pds-stampa-piede"><span>Scheda ' . pesc($r['id']) . ' · ' . pesc($statoLabel) . '</span>'
      . '<span class="num">' . pesc($dominio . '/' . $url) . ' · le fonti sono stampate con l’indirizzo per esteso</span></div>' . "\n";
  $h .= "</article>\n";

  // colonna laterale
  $h .= '<aside class="pds-lato" data-print-hide><div><p class="pds-lato-titolo">In questa scheda</p><nav>'
      . '<a href="' . pesc($url) . '#corpo">Corpo della scheda</a>'
      . ($r['perche_studiarla'] ? '<a href="' . pesc($url) . '#perche">Perché studiarla</a>' : '')
      . ($r['cautela'] ? '<a href="' . pesc($url) . '#cautela">Cautela</a>' : '')
      . '<a href="' . pesc($url) . '#collegamenti">Collegamenti</a><a href="' . pesc($url) . '#fonti">Fonti</a></nav></div>'
      . '<div><p class="pds-lato-titolo">Periodo</p><a class="num pds-lato-periodo" href="cronologia.html' . ($r['periodo_principale'] ? '?periodo=' . pesc($r['periodo_principale']) : '') . '">' . pesc($periodoLabel) . '</a>'
      . '<hr class="hr"><p class="pds-lato-conti"><span class="num">' . $nFonti . '</span> fonti collegate · <span class="num">' . $nColl . '</span> collegamenti</p></div>'
      . "</aside>\n";

  $h .= "</div>\n</main>\n" . pds_footer() . pds_chiudi();
  return $h;
}

// ── pubblicazione: tutte le schede come file statici alla radice ────────────
function pds_pubblica_schede(?array $soloId = null): array {
  $radice = realpath(__DIR__ . '/..');
  $ids = $soloId ?? array_column(db()->query('SELECT id FROM pds_schede WHERE pubblicata=1 ORDER BY ordine, id')->fetchAll(), 'id');
  $esito = ['scritte' => 0, 'errori' => []];
  foreach ($ids as $id) {
    try {
      $r = atlante_scheda($id);
      if (!$r) { $esito['errori'][] = "$id: non trovata"; continue; }
      $html = pds_scheda_render_doc($id);
      if (file_put_contents($radice . '/' . atlante_url_scheda($r), $html) === false) throw new Exception('scrittura non riuscita (permessi?)');
      $esito['scritte']++;
    } catch (Throwable $e) {
      $esito['errori'][] = "$id: " . $e->getMessage();
    }
  }
  return $esito;
}
