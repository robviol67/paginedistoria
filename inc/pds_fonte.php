<?php
// Pagine di Storia — la pagina di una fonte, generata dal database.
// Traduzione del componente di prototipi/Fonte.dc.html: natura, limiti, scheda
// tecnica, come usarla, i documenti precisi citati e le schede che la usano.
// Dove il dato manca, restano le frasi che il Design stesso prevede per quel
// caso («… da scrivere in revisione»): una mancanza dichiarata, non riempita.
require_once __DIR__ . '/pds_scheda.php';   // riusa pds_wayback, pds_data_breve, lo shell

function pds_fonte_render_doc(string $id): ?string {
  $f = atlante_fonte($id);
  if (!$f) return null;

  $libro = ($f['categoria'] ?? '') === 'Libro';
  $edizione = trim(implode(', ', array_filter([$f['editore'], $f['anno']])) . ($f['isbn'] ? ' · ISBN ' . $f['isbn'] : ''));
  $cop = $f['copertura'] ?: '—';
  $url = (string)$f['url'];
  [$archivio, $dataArchivio] = pds_wayback($url);
  $internazionale = ($f['ambito'] ?? '') === 'internazionale';
  $natura = (string)$f['natura'];
  $esito = $f['esito'] ?: '—';
  $riscontrato = (string)$f['riscontrato'] === '1';

  // documenti precisi di questa fonte, con la scheda in cui compaiono
  $st = db()->prepare('SELECT d.*, s.slug, s.titolo AS s_titolo FROM pds_documenti d
                       LEFT JOIN pds_schede s ON s.id = d.scheda_id
                       WHERE d.fonte_id=? ORDER BY d.data_documento, d.id');
  $st->execute([$id]);
  $documenti = $st->fetchAll();

  // schede che la usano: una riga per scheda, col primo localizzatore
  $st = db()->prepare('SELECT s.id, s.slug, s.titolo, sf.ruolo, sf.localizzatore
                       FROM pds_scheda_fonte sf JOIN pds_schede s ON s.id = sf.scheda_id
                       WHERE sf.fonte_id=? AND s.pubblicata=1 ORDER BY s.ordine, s.id, sf.ordine');
  $st->execute([$id]);
  $usi = [];
  foreach ($st->fetchAll() as $u) if (!isset($usi[$u['id']])) $usi[$u['id']] = $u;
  $docPer = [];
  foreach ($documenti as $d) $docPer[$d['scheda_id']] ??= $d;

  $sotto = implode(' · ', array_filter([$f['autore_ente'], $libro ? $edizione : null, $cop !== '—' ? 'copertura ' . $cop : null]));
  $esempio = $documenti
    ? 'Esempio di localizzatore: ' . ($documenti[0]['citazione'] ?: $documenti[0]['descrizione'])
    : 'Nessun localizzatore accertato: da compilare in revisione.';
  $pagina = atlante_url_fonte($f);

  $h  = pds_head($f['titolo'] . ' — Fonte ' . $f['id'] . ' | Pagine di Storia',
                 mb_substr(trim((string)($f['come_usarla'] ?: $sotto)), 0, 180), $pagina);
  $h  = str_replace('</head>', "<script src=\"assets/scheda.js\" defer></script>\n</head>", $h);
  $h .= pds_header('fonti.html');
  $h .= '<main class="pds-fonte" data-print-urls>' . "\n";
  $h .= '<nav class="pds-percorso" aria-label="Percorso"><a href="fonti.html">Fonti</a><span>›</span><span>'
      . pesc(mb_strtoupper(mb_substr($natura, 0, 1)) . mb_substr($natura, 1)) . '</span><span>›</span><span class="num">' . pesc($f['id']) . "</span></nav>\n";

  $h .= '<div class="pds-fonte-testa"><span class="pds-voce-etichetta">' . pesc($f['categoria'] ?: $natura) . '</span>'
      . '<span class="tag">' . pesc(strtoupper($f['lingua'] ?: 'it')) . '</span>'
      . '<span class="tag">' . ($internazionale ? 'Internazionale' : 'Italiana') . '</span>'
      . '<span class="tag">' . pesc($f['accesso'] ?: '—') . '</span>'
      . '<span class="num pds-marca-id">' . pesc($f['id']) . "</span></div>\n";
  $h .= '<h1>' . pesc($f['titolo']) . "</h1>\n";
  if ($sotto !== '') $h .= '<p class="pds-fonte-sotto">' . pesc($sotto) . "</p>\n";

  $h .= '<div class="pds-fonte-azioni" data-print-hide>';
  if ($url) $h .= '<a class="btn btn-primary" href="' . pesc($url) . '" target="_blank" rel="noopener">Apri la fonte</a>';
  if ($archivio) $h .= '<a class="btn btn-secondary" href="' . pesc($archivio) . '" target="_blank" rel="noopener">Copia archiviata · ' . pesc($dataArchivio) . '</a>';
  $h .= '<button class="btn btn-secondary" type="button" data-cita data-cita-testo="' . pesc($f['titolo'] . ($f['autore_ente'] ? ', ' . $f['autore_ente'] : '') . ($url ? ', ' . $url : '')) . '">Cita</button>';
  $h .= "</div>\n<hr class=\"hr\">\n";

  $affidabile = $libro && $edizione !== ''
    ? 'Opera storiografica con edizione identificata: ' . $edizione . '.'
    : 'Motivazione dell’autorevolezza da scrivere in revisione.';
  $h .= '<div class="pds-fonte-due"><section><h2>Perché è affidabile</h2><p>' . pesc($affidabile) . '</p></section>'
      . '<section><h2>Limiti</h2><p>' . pesc($f['limiti'] ?: 'Limiti da dichiarare in revisione.') . "</p></section></div>\n";

  $h .= '<section class="pds-fonte-blocco"><h2>Scheda tecnica</h2><dl class="num pds-tecnica">'
      . '<dt>Natura</dt><dd>' . pesc($natura . ($f['categoria'] ? ' · ' . mb_strtolower($f['categoria']) : '')) . '</dd>'
      . '<dt>Ambito</dt><dd>' . pesc(($internazionale ? 'Internazionale' : 'Italiana') . ' · ' . ($f['paese'] ?: 'IT') . ' · ' . ($f['lingua'] ?: 'it')) . '</dd>'
      . '<dt>Copertura</dt><dd>' . pesc($cop) . '</dd>'
      . '<dt>Accesso</dt><dd>' . pesc(($f['accesso'] ?: '—') . ($f['accesso_nota'] ? ' — ' . $f['accesso_nota'] : '')) . '</dd>'
      . '<dt>Indirizzo</dt><dd>' . ($url ? '<a href="' . pesc($url) . '" target="_blank" rel="noopener">' . pesc(rtrim(preg_replace('#^https?://#', '', $url), '/')) . '</a>' : '—') . '</dd>'
      . '<dt>Copia archiviata</dt><dd>' . ($archivio ? '<a href="' . pesc($archivio) . '" target="_blank" rel="noopener">web.archive.org · ' . pesc($dataArchivio) . '</a>' : '—') . '</dd>'
      . '<dt>Verifica</dt><dd>' . pesc((pds_data_breve($f['verifica_data']) ?: '—') . ' · ' . $esito . ' · ' . ($riscontrato ? 'contenuto riscontrato' : 'solo controllo tecnico')) . '</dd>'
      . "</dl></section>\n";

  $h .= '<section class="pds-fonte-blocco"><h2>Come usarla</h2><p class="pds-lettura-breve" style="margin:0 0 var(--space-3)">'
      . pesc($f['come_usarla'] ?: 'Indicazioni d’uso da scrivere in revisione.') . '</p>'
      . '<p class="num pds-localizzatore" style="margin:0">' . pesc($esempio) . "</p></section>\n";

  $h .= '<section class="pds-fonte-blocco"><h2>Documenti precisi citati <span class="num">' . count($documenti) . '</span></h2>';
  if (!$documenti) {
    $h .= '<p class="pds-vuoto" style="font-family:var(--font-reading);font-size:15px">Nessun documento preciso è ancora agganciato a questa fonte: nelle schede compare come punto di partenza e non conta per i minimi di verifica.</p>';
  }
  $h .= '<div class="pds-voci">';
  foreach ($documenti as $d) {
    [$arcDoc, $dataArc] = pds_wayback((string)$d['url']);
    $h .= '<div data-print-block class="pds-voce-fonte"><div class="pds-doc-testa">'
        . '<span class="pds-voce-etichetta">' . pesc($d['tipo_documento'] ?: 'documento') . '</span>'
        . '<span class="num data">' . pesc(pds_data_breve($d['data_documento']) ?: '') . '</span>'
        . ($d['slug'] ? '<a class="num" href="' . pesc(atlante_url_scheda(['id' => $d['scheda_id'], 'slug' => $d['slug']])) . '">' . pesc($d['s_titolo']) . '</a>' : '')
        . '</div>'
        . '<p class="num pds-localizzatore">' . pesc($d['citazione'] ?: '—') . '</p>'
        . ($d['descrizione'] ? '<p class="pds-doc-descr">' . pesc($d['descrizione']) . '</p>' : '')
        . '<div class="pds-voce-azioni">'
        . ($d['url'] ? '<a class="btn btn-secondary" href="' . pesc($d['url']) . '" target="_blank" rel="noopener">Apri il documento</a>' : '')
        . ($arcDoc ? '<a class="btn btn-secondary" href="' . pesc($arcDoc) . '" target="_blank" rel="noopener">Copia archiviata · ' . pesc($dataArc) . '</a>' : '')
        . ($d['verificata_il'] ? '<span class="num pds-doc-verificato">verificato il ' . pesc(pds_data_breve($d['verificata_il'])) . '</span>' : '')
        . '</div></div>';
  }
  $h .= "</div></section>\n";

  $h .= '<section><h2>Schede che la usano <span class="num">' . count($usi) . '</span></h2><ul class="pds-usi">';
  foreach ($usi as $u) {
    $doc = $docPer[$u['id']] ?? [];
    $loc = $u['localizzatore'] ?: (($doc['citazione'] ?? '') ?: (($doc['descrizione'] ?? '') ?: ($libro ? $edizione : 'Localizzatore da indicare in revisione')));
    $h .= '<li><span class="num id">' . pesc($u['id']) . '</span><span class="corpo"><a href="' . pesc(atlante_url_scheda($u)) . '">'
        . pesc($u['titolo']) . '</a><span class="num loc">' . pesc($loc) . '</span></span><span class="num ruolo">' . pesc($u['ruolo'] ?: '—') . '</span></li>';
  }
  $h .= "</ul></section>\n";

  $h .= "</main>\n" . pds_footer() . pds_chiudi();
  return $h;
}

function pds_pubblica_fonti(?array $soloId = null): array {
  $radice = realpath(__DIR__ . '/..');
  $ids = $soloId ?? array_column(db()->query('SELECT id FROM pds_fonti ORDER BY id')->fetchAll(), 'id');
  $esito = ['scritte' => 0, 'errori' => []];
  foreach ($ids as $id) {
    try {
      $html = pds_fonte_render_doc($id);
      if ($html === null) { $esito['errori'][] = "$id: non trovata"; continue; }
      if (file_put_contents($radice . '/' . atlante_url_fonte($id), $html) === false) throw new Exception('scrittura non riuscita');
      $esito['scritte']++;
    } catch (Throwable $e) { $esito['errori'][] = "$id: " . $e->getMessage(); }
  }
  return $esito;
}
