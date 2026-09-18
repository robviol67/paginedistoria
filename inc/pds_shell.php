<?php
// Pagine di Storia — il guscio delle pagine GENERATE (Taccuino, e più avanti
// schede e fonti): testa, intestazione, piè di pagina, uguali a quelli del
// Design.
//
// Perché non li riscrive: intestazione e piè di pagina sono identici in tutte
// le pagine del Design, quindi si leggono da index.html, che il build produce
// dal prototipo. Riscriverli a mano qui significherebbe avere due versioni
// dello stesso markup, e una delle due invecchierebbe in silenzio: alla
// prossima consegna del Design il sito avrebbe due intestazioni diverse.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/nav.php';
if (!function_exists('pesc')) { function pesc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

function pds_index_html(): string {
  static $html = null;
  if ($html === null) $html = (string)@file_get_contents(__DIR__ . '/../index.html');
  return $html;
}

// L'intestazione del Design, con la voce attiva segnata e — se il pannello ha
// un menu — i link presi dal database invece che dal Design.
function pds_header(string $paginaAttiva = ''): string {
  static $grezza = null;
  if ($grezza === null) {
    $grezza = preg_match('/<header[\s\S]*?<\/header>/i', pds_index_html(), $m) ? $m[0] : '';
  }
  if ($grezza === '') return '';
  $out = $grezza;

  $voci = function_exists('nav_items_effective') ? nav_items_effective() : [];
  if ($voci) {
    $link = '';
    foreach ($voci as $v) {
      $href = (string)($v['href'] ?? '#');
      $attiva = $paginaAttiva !== '' && $href === $paginaAttiva;
      $link .= '<a href="' . pesc($href) . '"' . ($attiva ? ' aria-current="page"' : '') . '>' . pesc($v['label']) . '</a>';
    }
    $out = preg_replace('/(<nav class="pds-nav"[^>]*>)[\s\S]*?(<\/nav>)/i', '$1' . str_replace('$', '\$', $link) . '$2', $out, 1);
  } elseif ($paginaAttiva !== '') {
    // Senza menu nel database resta quello del Design: si sposta solo il segno
    // della pagina corrente, che nel prototipo è fisso sulla Home.
    $out = str_replace(' aria-current="page"', '', $out);
    $out = preg_replace('/<a href="' . preg_quote($paginaAttiva, '/') . '"/', '<a href="' . $paginaAttiva . '" aria-current="page"', $out, 1);
  }
  return $out;
}

function pds_footer(): string {
  static $grezzo = null;
  if ($grezzo === null) {
    $grezzo = preg_match('/<footer[\s\S]*?<\/footer>/i', pds_index_html(), $m) ? $m[0] : '';
  }
  return $grezzo;
}

// La testa: gli stessi fogli di stile del Design, nello stesso ordine — pds.css
// per ultimo, perché rimappa i token del design system (sta scritto nella nota
// di consegna, e invertirlo spegne l'identità).
function pds_head(string $titolo, string $descrizione = '', string $percorso = '', string $immagine = ''): string {
  $dominio = defined('SITE_DOMAIN') ? rtrim(SITE_DOMAIN, '/') : '';
  $canonico = $dominio && $percorso ? $dominio . '/' . ltrim($percorso, '/') : '';
  $og = $immagine !== '' ? $immagine : (defined('SITE_OG_IMAGE') ? SITE_OG_IMAGE : '');

  // Il foglio del design system ha un nome con identificativo: si legge da
  // index.html invece di inchiodarlo qui, così una consegna nuova non rompe
  // le pagine generate.
  // ⚠ Con l'impronta (?v=…) nell'indirizzo, la vecchia ricerca «[^"]+\.css"»
  // non trovava più niente, e tutte le pagine generate uscivano SENZA il
  // design system. Si cerca il percorso fino a .css, impronta facoltativa.
  $ds = preg_match('/href="(assets\/_ds\/[^"?]+\.css)(?:\?[^"]*)?"/', pds_index_html(), $m) ? $m[1] : '';
  if ($ds === '') error_log('pds_head: foglio del design system non trovato in index.html');

  $h  = "<!DOCTYPE html>\n<html lang=\"it\">\n<head>\n<meta charset=\"utf-8\">\n";
  $h .= "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
  // Le pagine dei post vivono in blog/: <base> fa risolvere come in radice i
  // percorsi di intestazione, piè di pagina e asset, che sono tutti relativi.
  if ($dominio) $h .= '<base href="' . pesc($dominio) . "/\">\n";
  $h .= '<title>' . pesc($titolo) . "</title>\n";
  if ($descrizione !== '') $h .= '<meta name="description" content="' . pesc($descrizione) . "\">\n";
  if ($canonico) $h .= '<link rel="canonical" href="' . pesc($canonico) . "\">\n";
  $h .= '<meta property="og:type" content="article">' . "\n";
  $h .= '<meta property="og:title" content="' . pesc($titolo) . "\">\n";
  if ($descrizione !== '') $h .= '<meta property="og:description" content="' . pesc($descrizione) . "\">\n";
  if ($canonico) $h .= '<meta property="og:url" content="' . pesc($canonico) . "\">\n";
  if ($og) $h .= '<meta property="og:image" content="' . pesc($og) . "\">\n";
  $h .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
  if ($ds) $h .= '<link rel="stylesheet" href="' . pesc(pds_asset($ds)) . "\">\n";
  $h .= "<link rel=\"stylesheet\" href=\"" . pds_asset('assets/atlante.css') . "\">\n";
  $h .= "<link rel=\"stylesheet\" href=\"" . pds_asset('assets/pds.css') . "\">\n";
  $h .= "<link rel=\"stylesheet\" href=\"" . pds_asset('assets/pds-generate.css') . "\">\n";
  $h .= "<link rel=\"icon\" href=\"" . pds_asset('assets/favicon/favicon.svg') . "\">\n";
  $h .= "<script src=\"" . pds_asset('assets/tema.js') . "\" defer></script>\n";
  $h .= "</head>\n<body>\n";
  return $h;
}

function pds_chiudi(): string { return "\n</body>\n</html>\n"; }

// L'indirizzo di un file statico con l'impronta del suo contenuto.
// Senza, il browser tiene la copia vecchia di un foglio di stile anche dopo
// che è cambiato: è successo con pds-generate.css, e le schede si vedevano
// senza impaginazione. Cambia il file, cambia l'indirizzo, niente cache vecchia.
function pds_asset(string $percorso): string {
  static $cache = [];
  if (!isset($cache[$percorso])) {
    $f = __DIR__ . '/../' . $percorso;
    $cache[$percorso] = is_file($f) ? $percorso . '?v=' . substr(md5_file($f), 0, 10) : $percorso;
  }
  return $cache[$percorso];
}
