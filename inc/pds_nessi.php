<?php
// Pagine di Storia — la pagina Nessi, composta dal database.
//
// Nel prototipo l'elenco era un array scritto a mano dentro la pagina (§5.7:
// «il data switch più invasivo»). Ora i Nessi sono schede della sesta
// tipologia, con il loro corpo nel database; la pagina si compone così:
//   modelli/nessi.html  (il Design, con #pds-nessi vuoto — build/preprocess.js)
//   + l'elenco dei Nessi dal database
//   = nessi.html
// I filtri per tema e verdetto sono select vere che scrivono ?tema= e
// ?verdetto= nell'indirizzo (assets/atlante.js): l'elenco è già tutto nella
// pagina, i filtri lo restringono. Senza JavaScript si legge tutto.
require_once __DIR__ . '/pds_scheda.php';

function pds_nessi_sezione(): array {
  $nessi = db()->query("SELECT * FROM pds_schede WHERE tipologia='Nesso' AND pubblicata=1 ORDER BY id")->fetchAll();
  $etVerdetto = [];
  foreach (atlante_tassonomia('verdetto') as $i => $v) $etVerdetto[$v['codice']] = [$i + 1, mb_strtoupper(mb_substr($v['etichetta'], 0, 1)) . mb_substr($v['etichetta'], 1)];

  $temi = []; $verdetti = [];
  $voci = '';
  foreach ($nessi as $r) {
    $tn = atlante_temi_di_scheda($r['id']);
    foreach ($tn as $t) $temi[$t] = true;
    if ($r['verdetto']) $verdetti[$r['verdetto']] = true;
    $arco = $r['nesso_arco'] ?: pds_anni($r);
    // Un Nesso senza verdetto non deve sembrarne uno che ce l'ha (§5.7).
    $badge = $r['verdetto'] && isset($etVerdetto[$r['verdetto']])
      ? 'Verdetto provvisorio · ' . sprintf('%02d', $etVerdetto[$r['verdetto']][0]) . ' · ' . $etVerdetto[$r['verdetto']][1]
      : 'Verdetto non ancora assegnato';

    $voci .= '<article data-print-block class="pds-nessi-voce" data-temi="' . pesc(implode('|', $tn)) . '" data-verdetto="' . pesc((string)$r['verdetto']) . '">'
      . '<div class="pds-nessi-testa"><p class="num pds-nessi-id">' . pesc($r['id'] . ' · ' . $arco) . '</p>'
      . ($tn ? '<span class="tag tag-outline" style="font-size:10px">' . pesc($tn[0]) . '</span>' : '')
      . '<p class="num pds-nessi-verdetto">' . pesc($badge) . '</p></div>'
      . '<h3><a href="' . pesc(atlante_url_scheda($r)) . '">' . pesc($r['titolo']) . '</a></h3>'
      . pds_nesso_corpo($r, false)
      . '</article>';
  }

  $totale = count($nessi);
  $daIstruire = count(array_filter($nessi, fn($r) => $r['stato'] !== 'verificata'));
  $stato = $daIstruire === $totale ? 'tutti allo stato «da istruire»' : $daIstruire . ' allo stato «da istruire»';

  ksort($temi, SORT_LOCALE_STRING);
  $opzTemi = '<option value="">Tutti i temi</option>';
  foreach (array_keys($temi) as $t) $opzTemi .= '<option>' . pesc($t) . '</option>';
  $opzVer = '<option value="">Tutti i verdetti</option>';
  foreach ($etVerdetto as $cod => [$n, $et]) if (isset($verdetti[$cod])) $opzVer .= '<option value="' . pesc($cod) . '">' . pesc(sprintf('%02d', $n) . ' · ' . $et) . '</option>';

  $h = '<div style="display:flex;flex-wrap:wrap;gap:var(--space-3);align-items:center;border-bottom:1px solid var(--color-text);padding-bottom:var(--space-3);margin-bottom:var(--space-4)">'
     . '<h2 style="margin:0;font-size:13px;letter-spacing:0.1em;text-transform:uppercase;color:var(--color-neutral-700)">Candidati</h2>'
     . '<p class="num" style="margin:0;font-size:12px;color:var(--color-neutral-700)" data-nessi-conto aria-live="polite">' . $totale . ' su ' . $totale . ' · ' . pesc($stato) . '</p>'
     . '<div class="pds-nessi-filtri" data-print-hide>'
     . '<div class="field"><label for="ntema">Tema</label><select class="input" id="ntema">' . $opzTemi . '</select></div>'
     . '<div class="field"><label for="nverdetto">Verdetto</label><select class="input" id="nverdetto">' . $opzVer . '</select></div>'
     . '<a class="btn btn-secondary" href="segnala.html" style="text-decoration:none">Proponi una prova</a>'
     . '</div></div>'
     . '<p data-nessi-vuoto hidden style="border:1px solid var(--color-text);padding:var(--space-4);font-size:15px">Nessun Nesso con questi criteri: prova ad azzerare i filtri.</p>'
     . '<div class="pds-nessi-elenco">' . $voci . '</div>';

  return ['html' => $h, 'totale' => $totale, 'stato' => $stato];
}

function pds_pubblica_nessi(): array {
  $radice = realpath(__DIR__ . '/..');
  $modello = @file_get_contents("$radice/modelli/nessi.html");
  if ($modello === false) throw new Exception('manca modelli/nessi.html: va caricato il build');
  if (!preg_match('/<section id="pds-nessi"[^>]*>/', $modello, $m, PREG_OFFSET_CAPTURE)) throw new Exception('in modelli/nessi.html non c\'è #pds-nessi');

  $sez = pds_nessi_sezione();
  $apertura = $m[0][0];
  $inizio = $m[0][1] + strlen($apertura);
  $fine = strpos($modello, '</section>', $inizio);
  $html = substr($modello, 0, $inizio) . $sez['html'] . substr($modello, $fine);

  // Il cappello del Design dice «Dieci candidati»: il numero ora lo sanno i
  // dati. Se il testo è stato riscritto dal pannello, la frase non combacia e
  // resta quella scritta a mano, come è giusto.
  $parole = [1 => 'Un', 'Due', 'Tre', 'Quattro', 'Cinque', 'Sei', 'Sette', 'Otto', 'Nove', 'Dieci', 'Undici', 'Dodici', 'Tredici', 'Quattordici', 'Quindici'];
  $html = str_replace('Dieci candidati', ($parole[$sez['totale']] ?? $sez['totale']) . ' candidati', $html);

  // Il modello si crede in modelli/: canonico e og:url lo dicono. La pagina
  // vera sta alla radice, e un motore di ricerca deve saperlo.
  $html = str_replace('modelli/nessi.html', 'nessi.html', $html);

  // Il modello è una pagina del Design: carica i fogli del kit ma non quello
  // delle parti generate, dove stanno gli stili delle voci dei Nessi.
  if (strpos($html, 'pds-generate.css') === false) {
    $html = str_replace('</head>', '<link rel="stylesheet" href="' . pds_asset('assets/pds-generate.css') . "\">\n</head>", $html);
  }

  // La pagina vive alla radice: il modello, scritto per stare in modelli/,
  // non ha percorsi da correggere perché sono tutti relativi alla radice.
  if (file_put_contents("$radice/nessi.html", $html) === false) throw new Exception('scrittura di nessi.html non riuscita');
  return ['nessi' => $sez['totale']];
}
