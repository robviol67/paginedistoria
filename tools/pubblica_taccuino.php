<?php
// Pagine di Storia — ripubblica tutti i post e gli indici del Taccuino.
// Serve dopo una modifica al vestito (inc/pds_blog.php, assets/pds-generate.css)
// o all'intestazione del sito: i post sono file statici, e finché non si
// riscrivono restano com'erano.
//
//   php tools/pubblica_taccuino.php
//   https://…/tools/pubblica_taccuino.php?key=…
declare(strict_types=1);
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/blog.php';

if (PHP_SAPI !== 'cli') {
  header('Content-Type: text/plain; charset=utf-8');
  $atteso = defined('MIGRATION_KEY') ? MIGRATION_KEY : '';
  if ($atteso === '' || !hash_equals($atteso, (string)($_GET['key'] ?? ''))) {
    http_response_code(403); exit("Serve la chiave: ?key=…\n");
  }
}

$n = 0;
foreach (blog_posts_public() as $p) { blog_publish_post((int)$p['id']); $n++; echo "  · " . $p['slug'] . "\n"; }
blog_index_publish_all();
require_once __DIR__ . '/../inc/pds_home.php';
$h = pds_pubblica_home();
echo "\nripubblicati $n post + gli indici; Home: {$h['zone']} zone dai dati ({$h['cambiate']} cambiate)\n";
echo "resa: " . (function_exists('pds_post_render_doc') ? "vestito del Design (inc/pds_blog.php)" : "impianto del motore") . "\n";
