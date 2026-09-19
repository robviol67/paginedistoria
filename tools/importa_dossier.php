<?php
// Pagine di Storia — versa dati/dossier.json nel blog: ogni puntata di un
// Dossier diventa un post della categoria «Dossier», con l'etichetta della
// serie (es. «Mani Pulite») che raccoglie le puntate nella pagina per tag.
//
//   php tools/importa_dossier.php [--forza]
//   https://…/tools/importa_dossier.php?key=…[&forza=1]
//
// Come per il Taccuino: un post già presente NON si sovrascrive (può essere
// stato ritoccato nel pannello), a meno di &forza=1. I segnaposto
// [[scheda:ID|etichetta]] portano id veri dell'atlante e diventano link;
// se la scheda non c'è resta il testo, e lo si dice.
declare(strict_types=1);

$daRiga = PHP_SAPI === 'cli';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/blog.php';
require_once __DIR__ . '/../inc/atlante.php';

if (!$daRiga) {
  header('Content-Type: text/plain; charset=utf-8');
  $atteso = defined('MIGRATION_KEY') ? MIGRATION_KEY : '';
  if ($atteso === '' || !hash_equals($atteso, (string)($_GET['key'] ?? ''))) {
    http_response_code(403); exit("Serve la chiave: ?key=…\n");
  }
}
$forza = $daRiga ? in_array('--forza', $argv ?? [], true) : isset($_GET['forza']);

$file = __DIR__ . '/../dati/dossier.json';
if (!is_file($file)) exit("✗ manca dati/dossier.json: lancia prima ricerche/dossier/genera_dossier.py\n");
$D = json_decode(file_get_contents($file), true);
if (!$D || empty($D['post'])) exit("✗ dati/dossier.json non contiene puntate\n");
if (!blog_table_ready()) exit("✗ le tabelle del blog non ci sono\n");

echo "Puntate nel file: " . count($D['post']) . ($forza ? " · SOVRASCRIVO" : " · aggiungo solo le mancanti") . "\n\n";

// fonti nuove del repertorio (i libri citati dalle puntate)
$campiF = ['titolo','autore_ente','natura','categoria','ambito','paese','lingua','url','accesso','accesso_nota','limiti',
           'come_usarla','copertura','editore','anno','edizione','isbn','copertura_inizio','copertura_fine'];
$selF = db()->prepare('SELECT 1 FROM pds_fonti WHERE id=?');
$insF = db()->prepare('INSERT INTO pds_fonti (id,' . implode(',', $campiF) . ',esito,riscontrato,verifica_data) VALUES (?' . str_repeat(',?', count($campiF)) . ",'raggiungibile','1',?)");
$nf = 0;
foreach ($D['fonti_nuove'] ?? [] as $f) {
  $selF->execute([$f['id']]);
  if ($selF->fetchColumn()) continue;
  $insF->execute(array_merge([$f['id']], array_map(fn($c) => $f[$c] ?? null, $campiF), [$D['generato']]));
  $nf++;
}

$categorie = [];
foreach (blog_categories() as $c) $categorie[mb_strtolower($c['name'])] = (int)$c['id'];
if (!isset($categorie['dossier'])) {
  blog_category_save(0, 'Dossier', count($categorie));
  foreach (blog_categories() as $c) $categorie[mb_strtolower($c['name'])] = (int)$c['id'];
  echo "  + categoria: Dossier\n";
}

$mancanti = []; $nuovi = 0; $agg = 0; $saltati = 0;
foreach ($D['post'] as $p) {
  $st = db()->prepare('SELECT id FROM cms_blog_posts WHERE title=? LIMIT 1');
  $st->execute([$p['titolo']]);
  $id = $st->fetchColumn();
  if ($id && !$forza) { $saltati++; continue; }
  if (!$id) { $id = blog_post_create($p['titolo']); $nuovi++; } else { $agg++; }

  $corpo = preg_replace_callback('/\[\[scheda:([A-Z]{1,2}\d{2,3})\|([^\]]*)\]\]/u', function ($m) use (&$mancanti) {
    $s = atlante_scheda($m[1]);
    if (!$s) { $mancanti[] = $m[1]; return $m[2]; }
    return '<a href="' . atlante_url_scheda($s) . '">' . $m[2] . '</a>';
  }, (string)$p['corpo']);

  blog_post_save((int)$id, [
    'title' => $p['titolo'],
    'subtitle' => $p['occhiello'],
    'eyebrow' => 'Lettura: ' . $p['lettura'],
    'category_id' => $categorie['dossier'],
    'tags' => $p['serie'],
    'excerpt' => $p['occhiello'],
    'body' => $corpo,
    'author' => 'Redazione dell’atlante',
    'published' => 1,
    'seo_title' => $p['titolo'] . ' — Dossier | Pagine di Storia',
    'seo_desc' => mb_substr($p['occhiello'], 0, 180),
    // l'immagine di anteprima (social): la pagina del post non la mostra, la figura sta nel corpo
    'cover_image' => $p['copertina'] ?? '',
    // og:image vuole un indirizzo assoluto
    'seo_image' => !empty($p['copertina']) ? (defined('SITE_DOMAIN') ? rtrim(SITE_DOMAIN, '/') . '/' : '') . $p['copertina'] : '',
  ]);
  db()->prepare('UPDATE cms_blog_posts SET created_at=? WHERE id=?')->execute([$p['data_iso'] . sprintf(' 09:%02d:00', max(0, (int)($p['n'] ?? 1) - 1)), (int)$id]); // stesso giorno: la puntata più alta esce per prima
  blog_publish_post((int)$id);
  $r = blog_post_get((int)$id);
  echo "  · " . $p['titolo'] . "  →  " . blog_route($r) . "\n";
}

require_once __DIR__ . '/../inc/pds_dossier.php';
$ds = pds_pubblica_dossier();
require_once __DIR__ . '/../inc/pds_home.php';
pds_pubblica_home();
blog_index_publish_all();
printf("\nsezione      %d pagine Dossier riscritte, indici del Taccuino e Home riallineati", $ds['pagine']);
printf("\npuntate      +%d nuove, %d aggiornate, %d lasciate stare\n", $nuovi, $agg, $saltati);
printf("fonti        +%d nuove nel repertorio\n", $nf);
if ($mancanti) echo "⚠ schede non trovate (resta il testo senza link): " . implode(', ', array_unique($mancanti)) . "\n";
