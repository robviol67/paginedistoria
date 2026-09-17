<?php
// VelociBuilder LITE — Footer condiviso: testi dal DB (cms_settings) con fallback su footer-seed.json.
// Rende SOLO il contenuto della riga footer (i due <span>); il contenitore resta nel template per-pagina.
require_once __DIR__ . '/settings.php';
if (!function_exists('pesc')) { function pesc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

function footer_seed() {
  static $s = null;
  if ($s === null) {
    $f = __DIR__ . '/footer-seed.json';
    $s = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
  }
  return $s;
}
function footer_values() {
  $seed = footer_seed();
  return [
    'left'  => setting_get('footer_left',  $seed['left']  ?? ''),
    'right' => setting_get('footer_right', $seed['right'] ?? ''),
    'logo'  => $seed['logo'] ?? '', // il logo resta dal seed (per-sito, non editabile dal pannello)
  ];
}
function footer_render_html() {
  $v = footer_values();
  return '<span>' . pesc($v['left']) . ($v['logo'] !== '' ? ' ' . $v['logo'] : '') . '</span>'
       . '<span>' . pesc($v['right']) . '</span>';
}
// Footer COMPLETO (contenitore + contenuto): per le pagine senza Design sorgente (page-builder, blog).
function footer_render_full() {
  $seed = footer_seed();
  // Se il Design non ha prodotto un footer-seed (footer a classi non riconosciuto dalla firma),
  // eredita il <footer> reale da index.html: così le pagine libere hanno il footer del sito.
  if (empty($seed)) {
    $idx = @file_get_contents(__DIR__ . '/../index.html');
    if ($idx !== false && preg_match('/<footer[\s\S]*?<\/footer>/i', $idx, $m)) return $m[0];
  }
  $container = $seed['container'] ?? '<div style="max-width:1240px;margin:0 auto;display:flex;justify-content:space-between;font-size:13px;color:oklch(90% 0.03 152);flex-wrap:wrap;gap:10px">';
  return $container . footer_render_html() . '</div>';
}
