<?php
// Pagine di Storia — le tre zone della Home che dipendono dai dati (§5.1):
// la linea del tempo dei periodi, i Nessi in evidenza, gli ultimi post.
//
// La Home non si ricompone da un modello come Nessi e Media: index.html è la
// pagina da cui il motore legge intestazione, piè di pagina e menu, e deve
// esistere sempre. Qui si riscrivono SOLO le tre zone marcate data-pds (da
// build/preprocess.js), dentro la pagina com'è. Il resto — testi, «Da dove
// cominciare», ricerca — resta del Design e del pannello.
//
// Quando si riscrivono: a ogni pubblicazione dell'atlante e del Taccuino, e
// subito dopo che il pannello ripubblica la Home o un post
// (pds_dopo_pubblicazione, chiamata da inc/cms.php e inc/blog.php).
require_once __DIR__ . '/pds_scheda.php';
require_once __DIR__ . '/settings.php';

function pds_home_linea(): string {
  $periodi = atlante_tassonomia('periodo');
  if (!$periodi) return '';
  $primo = (int)$periodi[0]['anno_inizio'];
  $ultimo = (int)end($periodi)['anno_fine'];

  // Fascia: ogni segmento largo quanto il suo periodo (§5.1: «larghezza del
  // segmento proporzionale a fine − inizio»), toni alterni del kit.
  $tacche = '';
  $passo = max(1, (int)round(($ultimo - $primo) / 4));
  for ($a = $primo; $a < $ultimo; $a += $passo) $tacche .= '<span>' . $a . '</span>';
  $tacche .= '<span>' . $ultimo . '</span>';
  $fascia = '<div><div class="num" style="display:flex;justify-content:space-between;font-size:11px;color:var(--color-neutral-700);border-bottom:1px solid var(--color-text);padding-bottom:4px;margin-bottom:2px">' . $tacche . '</div>'
          . '<div style="display:flex;gap:2px;background:var(--color-divider);border:1px solid var(--color-text);border-top:0">';
  foreach ($periodi as $i => $p) {
    $durata = max(1, (int)$p['anno_fine'] - (int)$p['anno_inizio']);
    $et = $p['anno_inizio'] . '–' . $p['anno_fine'] . ' · ' . $p['etichetta'];
    $fascia .= '<a class="pds-segmento" href="cronologia.html#' . pesc($p['codice']) . '" aria-label="' . pesc($et) . '" title="' . pesc($et) . '"'
             . ' style="flex:' . $durata . ' 1 6px;min-width:6px;background:var(--color-neutral-' . ($i % 2 ? '300' : '200') . ');height:46px;display:block"></a>';
  }
  $parole = [10 => 'dieci', 'undici', 'dodici', 'tredici', 'quattordici', 'quindici', 'sedici'];
  $fascia .= '</div><p style="margin:var(--space-2) 0 var(--space-4);font-size:12px;color:var(--color-neutral-700)">La fascia mostra i ' . ($parole[count($periodi)] ?? count($periodi))
           . ' periodi in scala: ogni segmento è proporzionale alla sua durata. Le etichette stanno nell’elenco qui sotto, dove restano leggibili anche su telefono.</p>';
  // L'elenco rigato: le etichette che nella fascia non ci starebbero.
  $fascia .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0 var(--space-6);border-top:1px solid var(--color-divider)">';
  foreach ($periodi as $p) {
    $fascia .= '<a class="num pds-periodo-riga" href="cronologia.html#' . pesc($p['codice']) . '" style="display:flex;gap:8px;align-items:baseline;border-bottom:1px solid var(--color-divider);padding:6px 0;text-decoration:none;color:var(--color-text)">'
             . '<span style="width:82px;flex:none;font-size:12px;color:var(--color-neutral-700)">' . (int)$p['anno_inizio'] . '–' . (int)$p['anno_fine'] . '</span>'
             . '<span style="font-size:13px;font-weight:600">' . pesc($p['etichetta']) . '</span></a>';
  }
  $fascia .= '</div></div>';

  // Verticale: anni, titolo, sottotitolo, durata.
  $vert = '<div style="display:flex;flex-direction:column;border-top:1px solid var(--color-divider)">';
  foreach ($periodi as $p) {
    $durata = (int)$p['anno_fine'] - (int)$p['anno_inizio'];
    $alto = 30 + 16 * max(1, $durata);
    $vert .= '<a class="pds-periodo-riga" href="cronologia.html#' . pesc($p['codice']) . '" style="display:flex;gap:var(--space-4);text-decoration:none;color:var(--color-text);min-height:' . $alto . 'px">'
           . '<span class="num" style="flex:none;width:clamp(62px,17vw,96px);text-align:right;padding-top:2px;font-family:var(--font-heading);font-weight:600;font-size:17px;color:var(--color-text)">' . (int)$p['anno_inizio']
           . '<span style="display:block;font-size:12px;font-weight:400;color:var(--color-neutral-700)">→ ' . (int)$p['anno_fine'] . '</span></span>'
           . '<span style="flex:none;width:2px;background:var(--color-divider);position:relative"><span style="position:absolute;left:-7px;top:8px;width:14px;height:14px;border-radius:50%;background:var(--color-bg);border:1px solid var(--color-text)"></span>'
           . '<span style="position:absolute;left:-3px;top:24px;width:6px;height:' . ($alto - 30) . 'px;background:var(--color-neutral-400)"></span></span>'
           . '<span style="min-width:0;padding:0 0 var(--space-4) var(--space-2)"><span style="display:block;font-family:var(--font-heading);font-weight:600;font-size:19px;line-height:1.15">' . pesc($p['etichetta']) . '</span>'
           . ($p['descrizione'] ? '<span style="display:block;font-size:13px;color:var(--color-neutral-800);margin-top:2px">' . pesc($p['descrizione']) . '</span>' : '')
           . '<span class="num" style="display:inline-block;margin-top:6px;font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-neutral-700)">' . $durata . ($durata === 1 ? ' anno' : ' anni') . '</span></span></a>';
  }
  $vert .= '</div>';

  // Tutte e due in pagina: l'interruttore mostra l'una o l'altra (atlante.js).
  // Senza JavaScript resta la fascia, che è la vista iniziale del Design.
  return '<div data-pds-vista-blocco="fascia">' . $fascia . '</div><div data-pds-vista-blocco="verticale" hidden>' . $vert . '</div>';
}

function pds_home_nessi(): string {
  // Quali Nessi: un'impostazione (N01 e N02 come nel Design), così la scelta
  // editoriale si cambia senza toccare il codice.
  $ids = array_filter(array_map('trim', explode(',', setting_get('home_nessi_evidenza', 'N01,N02'))));
  $etV = [];
  foreach (atlante_tassonomia('verdetto') as $v) $etV[$v['codice']] = mb_strtoupper(mb_substr($v['etichetta'], 0, 1)) . mb_substr($v['etichetta'], 1);
  $h = '';
  foreach ($ids as $id) {
    $r = atlante_scheda($id);
    if (!$r || $r['tipologia'] !== 'Nesso' || !(int)$r['pubblicata']) continue;
    $h .= '<article style="border:1px solid var(--color-accent);border-left:4px solid var(--color-accent);padding:var(--space-4);display:flex;flex-direction:column;gap:var(--space-2)">'
        . '<div style="display:flex;align-items:center;gap:var(--space-2)"><span style="flex:none;width:24px;height:24px;display:grid;place-items:center;background:var(--color-accent);color:#fff">' . PDS_MARCHE['Nesso'][1] . '</span>'
        . '<span style="font-size:10px;letter-spacing:0.1em;text-transform:uppercase;font-weight:600;color:var(--color-accent-700)">Nesso ' . pesc($r['id']) . '</span>'
        . '<span class="num" style="margin-left:auto;font-size:12px;color:var(--color-neutral-700)">' . pesc(pds_anni($r)) . '</span></div>'
        . '<h3 style="margin:0;font-size:21px"><a href="' . pesc(atlante_url_scheda($r)) . '" style="color:var(--color-text);text-decoration:none">' . pesc($r['titolo']) . '</a></h3>'
        . '<p style="margin:0;font-size:13px;color:var(--color-neutral-800)">' . pesc((string)$r['sintesi']) . '</p>'
        . '<p style="margin:0;display:flex;align-items:center;gap:var(--space-2);border:1px solid var(--color-accent);padding:6px var(--space-2);font-size:12px"><span style="font-size:10px;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-neutral-700)">Verdetto</span>'
        . '<strong style="text-transform:uppercase;letter-spacing:0.04em;color:var(--color-accent-700)">' . pesc($r['verdetto'] ? ($etV[$r['verdetto']] ?? $r['verdetto']) : 'da assegnare') . '</strong></p>'
        . '</article>';
  }
  return $h;
}

function pds_home_taccuino(): string {
  require_once __DIR__ . '/blog.php';
  if (!blog_table_ready()) return '';
  $h = '';
  foreach (blog_posts_public(3, 0) as $p) {
    $cat = !empty($p['category_id']) ? (blog_category_get($p['category_id'])['name'] ?? 'Taccuino') : 'Taccuino';
    $h .= '<a href="' . pesc(blog_route($p)) . '" style="background:var(--color-bg);padding:var(--space-4);text-decoration:none;color:var(--color-text);display:flex;flex-direction:column;gap:6px">'
        . '<span class="num" style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-accent-700)">' . pesc($cat . ' · ' . date('d/m', strtotime($p['created_at']))) . '</span>'
        . '<span style="font-family:var(--font-heading);font-weight:600;font-size:19px;line-height:1.15">' . pesc($p['title']) . '</span>'
        . '<span style="font-size:13px;color:var(--color-neutral-800)">' . pesc((string)$p['excerpt']) . '</span></a>';
  }
  return $h;
}

// Sostituisce il CONTENUTO del <div data-pds="nome" …> bilanciando i div
// annidati: una regex pigra si fermerebbe al primo </div> interno.
function pds_home_sostituisci(string $html, string $nome, string $dentro): string {
  if (!preg_match('/<div\b[^>]*\bdata-pds="' . preg_quote($nome, '/') . '"[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE)) return $html;
  $inizio = $m[0][1] + strlen($m[0][0]);
  $prof = 1; $pos = $inizio;
  while ($prof > 0 && preg_match('/<div\b|<\/div>/', $html, $t, PREG_OFFSET_CAPTURE, $pos)) {
    $prof += $t[0][0] === '</div>' ? -1 : 1;
    $pos = $t[0][1] + strlen($t[0][0]);
    if ($prof === 0) return substr($html, 0, $inizio) . $dentro . substr($html, $t[0][1]);
  }
  return $html;
}

function pds_pubblica_home(): array {
  $f = realpath(__DIR__ . '/..') . '/index.html';
  $html = @file_get_contents($f);
  if ($html === false) throw new Exception('index.html non leggibile');
  $prima = $html;
  require_once __DIR__ . '/pds_dossier.php';
  $zone = ['linea-del-tempo' => pds_home_linea(), 'dossier' => pds_home_dossier(), 'nessi-in-evidenza' => pds_home_nessi(), 'taccuino' => pds_home_taccuino()];
  // «zone»: quante zone ci sono e sono state scritte dai dati; «cambiate»:
  // quante sono diverse da prima. Zero cambiate non è un errore: vuol dire che
  // la Home era già allineata.
  $fatte = 0; $cambiate = 0;
  foreach ($zone as $nome => $dentro) {
    if ($dentro === '' || strpos($html, 'data-pds="' . $nome . '"') === false) continue;
    $nuovo = pds_home_sostituisci($html, $nome, $dentro);
    $fatte++;
    if ($nuovo !== $html) { $html = $nuovo; $cambiate++; }
  }
  if ($html !== $prima && file_put_contents($f, $html) === false) throw new Exception('scrittura di index.html non riuscita');
  return ['zone' => $fatte, 'cambiate' => $cambiate];
}

// Chiamata dal motore dopo una pubblicazione dal pannello: se è cambiata la
// Home (testi) o il Taccuino (post), le zone dati si riallineano subito.
function pds_dopo_pubblicazione(string $cosa): void {
  if ($cosa === 'home' || $cosa === 'blog') {
    try { pds_pubblica_home(); if ($cosa === 'blog') { require_once __DIR__ . '/pds_dossier.php'; pds_pubblica_dossier(); } } catch (Throwable $e) { error_log('pds_dopo_pubblicazione: ' . $e->getMessage()); }
  }
}
