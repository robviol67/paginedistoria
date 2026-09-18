<?php
// Pagine di Storia — la pagina Media, composta dal database (§5.8).
//   modelli/media.html (il Design, con #pds-media vuoto) + pds_media = media.html
// Nel prototipo il repertorio era un array scritto a mano, con rimandi alle
// schede sbagliati quasi tutti; «In evidenza» era markup fisso. Ora entrambi
// vengono da pds_media, e i tre filtri (categoria, mezzo, solo in revisione)
// sono controlli veri con ?categoria=&mezzo=&revisione=1 (assets/atlante.js).
require_once __DIR__ . '/pds_scheda.php';

const PDS_CATEGORIE_MEDIA = ['Politica e istituzioni', 'Stragi e terrorismo', 'Catastrofi', 'Sport', 'Mondo', 'Società'];

function pds_media_rimando(array $m): string {
  if (!$m['scheda_id']) return '<span title="Nessuna scheda nell’edizione dati v1.15">scheda non ancora in archivio</span>';
  $s = atlante_scheda($m['scheda_id']);
  return $s ? 'collegato a <a href="' . pesc(atlante_url_scheda($s)) . '">' . pesc($s['id'] . ' · ' . $s['titolo']) . '</a>' : '';
}

function pds_media_sezione(): string {
  $voci = db()->query('SELECT * FROM pds_media WHERE pubblicata=1 ORDER BY ordine, id')->fetchAll();
  $h = '';

  // ── in evidenza ──
  $evid = array_values(array_filter($voci, fn($m) => (int)$m['evidenza'] === 1));
  if ($evid) {
    $h .= '<section style="margin-bottom:96px"><h2 style="font-size:13px;letter-spacing:0.1em;text-transform:uppercase;color:var(--color-neutral-700);margin:0 0 var(--space-4)">In evidenza</h2><div class="pds-media-evidenza">';
    foreach ($evid as $m) {
      // Il riquadro resta vuoto finché non c'è un fotogramma con licenza
      // verificata: lo dice la pagina stessa. Dentro, il rimando all'archivio.
      $h .= '<article><div class="pds-media-fotogramma" data-print-hide><span class="num">Fotogramma non riprodotto · ' . pesc($m['documento']) . '</span></div>'
          . '<div><p class="num pds-media-kicker">' . pesc($m['data_testo'] . ' · ' . $m['mezzo']) . '</p>'
          . '<h3>' . pesc($m['titolo']) . '</h3><p>' . pesc($m['evidenza_testo'] ?: $m['perche']) . '</p></div>'
          . '<p class="num pds-media-piede">' . pds_media_rimando($m) . '</p></article>';
    }
    $h .= '</div></section>';
  }

  // ── repertorio ──
  $conta = array_fill_keys(PDS_CATEGORIE_MEDIA, 0);
  foreach ($voci as $m) if (isset($conta[$m['categoria']])) $conta[$m['categoria']]++;
  $mezzi = ['Radio', 'Televisione', 'Cinegiornale', 'Stampa'];

  $h .= '<section style="margin-bottom:96px"><div class="pds-media-barra">'
      . '<h2 style="margin:0;font-size:13px;letter-spacing:0.1em;text-transform:uppercase;color:var(--color-neutral-700)">Repertorio dei momenti mediali</h2>'
      . '<p class="num" style="margin:0;font-size:12px;color:var(--color-neutral-700)" data-media-conto aria-live="polite">' . count($voci) . ' voci su ' . count($voci) . ' · ordinate per data di trasmissione; le voci di periodo alla loro apertura</p>'
      . '<div class="pds-nessi-filtri" data-print-hide>'
      . '<div class="field"><label for="mcat">Categoria</label><select class="input" id="mcat"><option value="">Tutte</option>'
      . implode('', array_map(fn($c) => '<option>' . pesc($c) . '</option>', PDS_CATEGORIE_MEDIA)) . '</select></div>'
      . '<div class="field"><label for="mmezzo">Mezzo</label><select class="input" id="mmezzo"><option value="">Tutti</option>'
      . implode('', array_map(fn($c) => '<option>' . pesc($c) . '</option>', $mezzi)) . '</select></div>'
      . '<label style="display:flex;align-items:center;gap:8px;font-size:14px;align-self:center"><input type="checkbox" id="mrev"> Solo in revisione</label>'
      . '<button class="btn btn-secondary" type="button" data-media-esporta>Esporta il repertorio</button>'
      . '</div></div>';
  $h .= '<div class="pds-media-conteggi">';
  foreach ($conta as $c => $n) $h .= '<p class="num"><span>' . pesc($c) . '</span><strong>' . $n . '</strong></p>';
  $h .= '</div><p data-media-vuoto hidden style="border:1px solid var(--color-text);padding:var(--space-4);font-size:15px">Nessun momento con questi criteri: prova ad azzerare i filtri.</p><div class="pds-media-elenco">';
  foreach ($voci as $m) {
    $s = $m['scheda_id'] ? atlante_scheda($m['scheda_id']) : null;
    $h .= '<article data-print-block class="pds-media-voce" data-categoria="' . pesc($m['categoria']) . '" data-mezzo="' . pesc($m['mezzo']) . '" data-stato="' . pesc($m['stato']) . '"'
        . ' data-id="' . pesc($m['id']) . '" data-data="' . pesc($m['data_testo']) . '" data-titolo="' . pesc($m['titolo']) . '" data-programma="' . pesc($m['programma']) . '" data-documento="' . pesc($m['documento']) . '"'
        . ($s ? ' data-scheda="' . pesc(atlante_url_scheda($s)) . '"' : '') . '>'
        . '<div><p class="num pds-media-meta">' . pesc($m['id'] . ' · ' . $m['data_testo'] . ' · ' . $m['mezzo']) . '</p>'
        . '<h3>' . pesc($m['titolo']) . '</h3><p class="num pds-media-programma">' . pesc($m['programma']) . '</p>'
        . '<span class="tag tag-outline" style="font-size:10px">' . pesc($m['categoria']) . '</span></div>'
        . '<p class="pds-media-perche">' . pesc($m['perche']) . '</p>'
        . '<div><p class="pds-nesso-et">Documento</p><p class="num" style="margin:0 0 6px;font-size:13px">' . pesc($m['documento']) . '</p>'
        . '<p class="num pds-media-stato">' . pesc($m['stato']) . ' · ' . pds_media_rimando($m) . '</p></div>'
        . '</article>';
  }
  $h .= '</div></section>';
  return $h;
}

function pds_pubblica_media(): array {
  $radice = realpath(__DIR__ . '/..');
  $modello = @file_get_contents("$radice/modelli/media.html");
  if ($modello === false) throw new Exception('manca modelli/media.html: va caricato il build');
  if (!preg_match('/<section id="pds-media"[^>]*>/', $modello, $m, PREG_OFFSET_CAPTURE)) throw new Exception('in modelli/media.html non c\'è #pds-media');
  $inizio = $m[0][1];
  $fine = strpos($modello, '</section>', $inizio) + strlen('</section>');
  $html = substr($modello, 0, $inizio) . '<div id="pds-media">' . pds_media_sezione() . '</div>' . substr($modello, $fine);
  $html = str_replace('modelli/media.html', 'media.html', $html);
  if (strpos($html, 'pds-generate.css') === false)
    $html = str_replace('</head>', '<link rel="stylesheet" href="' . pds_asset('assets/pds-generate.css') . "\">\n</head>", $html);
  if (file_put_contents("$radice/media.html", $html) === false) throw new Exception('scrittura di media.html non riuscita');
  return ['voci' => (int)db()->query('SELECT COUNT(*) FROM pds_media WHERE pubblicata=1')->fetchColumn()];
}
