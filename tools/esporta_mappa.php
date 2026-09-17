<?php
// Pagine di Storia — esporta la mappa degli indirizzi: id scheda → pagina, id
// fonte → pagina, titolo del post → pagina del Taccuino.
//
// A che serve: i prototipi del Design si collegano fra loro con i nomi dei
// file di prototipazione («Scheda.dc.html?id=M18»). Il build deve riscriverli
// sugli indirizzi veri, ma gli indirizzi li sa il database, che sta sul
// server. Questo file è il ponte: si scarica in dati/mappa.json prima del
// build (vedi deploy/build-sito.sh).
//
//   https://…/tools/esporta_mappa.php?key=…   > dati/mappa.json
declare(strict_types=1);
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/atlante.php';
require_once __DIR__ . '/../inc/blog.php';

if (PHP_SAPI !== 'cli') {
  $atteso = defined('MIGRATION_KEY') ? MIGRATION_KEY : '';
  if ($atteso === '' || !hash_equals($atteso, (string)($_GET['key'] ?? ''))) {
    http_response_code(403); header('Content-Type: text/plain'); exit("Serve la chiave: ?key=…\n");
  }
  header('Content-Type: application/json; charset=utf-8');
}

$mappa = ['generata' => date('c'), 'schede' => [], 'titoli' => [], 'fonti' => [], 'post' => []];

foreach (db()->query('SELECT id, slug, titolo FROM pds_schede') as $s) {
  $url = atlante_url_scheda($s);
  $mappa['schede'][$s['id']] = $url;
  $mappa['titoli'][$s['titolo']] = $url;   // per i link che portano il titolo e non l'id
}
foreach (db()->query('SELECT id FROM pds_fonti') as $f) {
  $mappa['fonti'][$f['id']] = atlante_url_fonte($f);
}
foreach (blog_posts_public() as $p) {
  $mappa['post'][$p['title']] = blog_route($p);
}

echo json_encode($mappa, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
