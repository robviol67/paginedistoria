<?php
// Pagine di Storia — la sezione Dossier: l'indice a card (dossier.html) e la
// pagina di ogni dossier avviato (dossier-<slug>.html), con le sue puntate.
//
// Da dove vengono i dati: dati/dossier.json, scritto da
// ricerche/dossier/genera_dossier.py. Lì ci sono TUTTI i dossier approvati e,
// per quelli avviati, TUTTE le puntate dell'indice. Una puntata è «uscita» se
// porta il titolo del suo post e quel post è pubblicato nel blog: solo allora
// la card è un link. Il resto si vede, in grigio, come «in preparazione»: il
// lettore sa che cosa arriva, e non trova link che non portano a niente.
//
// Quando si riscrivono: dopo l'import delle puntate (tools/importa_dossier.php)
// e a ogni ripubblicazione del Taccuino (tools/pubblica_taccuino.php), così
// seguono anche i cambi di intestazione e di menu.
require_once __DIR__ . '/pds_shell.php';
require_once __DIR__ . '/blog.php';

const DOSSIER_INTRO = 'Inchieste a puntate sui temi che attraversano più periodi dell’atlante. Ogni puntata risponde a una domanda, tiene le date in una cronologia a parte e dichiara i suoi documenti.';

function pds_dossier_dati(): array {
  $f = __DIR__ . '/../dati/dossier.json';
  $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
  return $d['dossier'] ?? [];
}

// Un'icona del kit, presa dallo sprite del sito e messa in linea (come fanno
// le pagine del Design): così eredita il colore del testo e non chiede un file.
function pds_dossier_icona(string $id, int $lato = 24): string {
  static $sprite = null;
  if ($sprite === null) $sprite = (string)@file_get_contents(__DIR__ . '/../assets/icone/sprite.svg');
  if (!preg_match('/<symbol id="' . preg_quote($id, '/') . '"[^>]*>([\s\S]*?)<\/symbol>/', $sprite, $m)) return '';
  return '<svg width="' . $lato . '" height="' . $lato . '" viewBox="0 0 24 24" aria-hidden="true" style="flex:none">' . $m[1] . '</svg>';
}

// L'indirizzo del post di una puntata, se è pubblicato.
function pds_dossier_url_post(?string $titolo): string {
  if (!$titolo || !blog_table_ready()) return '';
  $st = db()->prepare('SELECT * FROM cms_blog_posts WHERE title=? AND published=1 AND archived=0 LIMIT 1');
  $st->execute([$titolo]);
  $p = $st->fetch();
  return $p ? blog_route($p) : '';
}

function pds_dossier_uscite(array $d): int {
  $n = 0;
  foreach ($d['puntate'] as $p) if (pds_dossier_url_post($p['post'] ?? null) !== '') $n++;
  return $n;
}

function pds_dossier_file(array $d): string { return 'dossier-' . $d['slug'] . '.html'; }

// Una card di dossier: attiva (link) o in attesa (grigia). Serve all'indice e alla Home.
function pds_dossier_card(array $d): string {
  $uscite = pds_dossier_uscite($d);
  $icona = pds_dossier_icona($d['icona'] ?? 'archiviata', 28);
  if ($uscite === 0) {
    return '<article class="pds-dossier-card is-attesa" aria-disabled="true">'
         . '<p class="pds-dossier-stato">' . $icona . '<span>In preparazione</span></p>'
         . '<h3>' . pesc($d['titolo']) . '</h3>'
         . '<p>' . pesc($d['descrizione']) . '</p></article>';
  }
  $tot = count($d['puntate']);
  return '<article class="pds-dossier-card">'
       . '<p class="pds-dossier-stato">' . $icona . '<span>' . $uscite . ($uscite === 1 ? ' puntata' : ' puntate') . ' su ' . $tot . '</span></p>'
       . '<h3><a href="' . pesc(pds_dossier_file($d)) . '">' . pesc($d['titolo']) . '</a></h3>'
       . '<p>' . pesc($d['descrizione']) . '</p>'
       . '<p class="pds-dossier-apri"><a href="' . pesc(pds_dossier_file($d)) . '">Apri il dossier →</a></p></article>';
}

// ── dossier.html ───────────────────────────────────────────────────────────
function pds_dossier_index_doc(): string {
  $h  = pds_head('Dossier — Pagine di Storia', DOSSIER_INTRO, 'dossier.html');
  $h .= pds_header('dossier.html');
  $h .= '<main class="pds-elenco pds-apertura" style="--pds-cover:url(/assets/immagini/copertina-dossier.svg)">' . "\n";
  $h .= "<h1>Dossier</h1>\n";
  $h .= '<p class="pds-occhiello" style="max-width:60ch">' . pesc(DOSSIER_INTRO) . "</p>\n";
  $h .= '<div class="pds-dossier-griglia">' . "\n";
  foreach (pds_dossier_dati() as $d) $h .= pds_dossier_card($d) . "\n";
  $h .= "</div>\n</main>\n" . pds_footer() . pds_chiudi();
  return $h;
}

// ── dossier-<slug>.html ────────────────────────────────────────────────────
function pds_dossier_pagina_doc(array $d): string {
  $h  = pds_head($d['titolo'] . ' — Dossier | Pagine di Storia', $d['descrizione'], pds_dossier_file($d));
  $h .= pds_header('dossier.html');
  $h .= '<main class="pds-elenco">' . "\n";
  $h .= '<nav class="pds-percorso" aria-label="Percorso"><a href="dossier.html">Dossier</a><span>›</span><span>' . pesc($d['titolo']) . "</span></nav>\n";
  $h .= '<h1 style="display:flex;align-items:center;gap:12px">' . pds_dossier_icona($d['icona'] ?? 'archiviata', 34) . pesc($d['titolo']) . "</h1>\n";
  if (!empty($d['premessa'])) $h .= '<p class="pds-dossier-premessa">' . pesc($d['premessa']) . "</p>\n";
  $h .= '<h2 class="pds-dossier-titoletto">Le puntate</h2>' . "\n" . '<ol class="pds-puntate">' . "\n";
  foreach ($d['puntate'] as $p) {
    $url = pds_dossier_url_post($p['post'] ?? null);
    $testa = '<p class="pds-puntata-n num">Puntata ' . (int)$p['n'] . ' · ' . pesc($p['arco']) . ($url === '' ? ' · in preparazione' : '') . '</p>';
    $h .= $url !== ''
      ? '<li class="pds-puntata">' . $testa . '<h3><a href="' . pesc($url) . '">' . pesc($p['titolo']) . '</a></h3><p>' . pesc($p['domanda']) . '</p><p class="pds-dossier-apri"><a href="' . pesc($url) . '">Leggi la puntata →</a></p></li>' . "\n"
      : '<li class="pds-puntata is-attesa" aria-disabled="true">' . $testa . '<h3>' . pesc($p['titolo']) . '</h3><p>' . pesc($p['domanda']) . '</p></li>' . "\n";
  }
  $h .= "</ol>\n";
  $h .= '<p style="margin-top:var(--space-8)"><a href="dossier.html">← Tutti i dossier</a></p>' . "\n";
  $h .= "</main>\n" . pds_footer() . pds_chiudi();
  return $h;
}

// Scrive l'indice e le pagine dei dossier che hanno almeno una puntata uscita.
function pds_pubblica_dossier(): array {
  $radice = realpath(__DIR__ . '/..');
  $dati = pds_dossier_dati();
  if (!$dati) return ['pagine' => 0, 'attivi' => 0];
  $pagine = 0; $attivi = 0;
  foreach ($dati as $d) {
    if (pds_dossier_uscite($d) === 0) continue;
    if (file_put_contents($radice . '/' . pds_dossier_file($d), pds_dossier_pagina_doc($d)) === false) throw new Exception('scrittura non riuscita: ' . pds_dossier_file($d));
    $pagine++; $attivi++;
  }
  if (file_put_contents($radice . '/dossier.html', pds_dossier_index_doc()) === false) throw new Exception('scrittura non riuscita: dossier.html');
  return ['pagine' => $pagine + 1, 'attivi' => $attivi];
}

// Il dossier di un post (dal tag di serie), per il percorso in cima alla puntata.
function pds_dossier_di_post(array $post): ?array {
  $serie = trim((string)($post['tags'] ?? ''));
  if ($serie === '') return null;
  foreach (pds_dossier_dati() as $d) if ($d['titolo'] === $serie) return $d;
  return null;
}

// La zona della Home: le card dei dossier, prima gli avviati.
function pds_home_dossier(): string {
  $dati = pds_dossier_dati();
  if (!$dati) return '';
  usort($dati, fn($a, $b) => (pds_dossier_uscite($b) > 0) <=> (pds_dossier_uscite($a) > 0));
  $h = '';
  // una riga sola: gli altri stanno nell'indice, a un clic
  foreach (array_slice($dati, 0, 4) as $d) $h .= pds_dossier_card($d);
  return $h;
}
