<?php
// Pagine di Storia — versa dati/taccuino.json nelle tabelle del blog e
// pubblica i post come pagine statiche. Idempotente.
//
//   php tools/importa_taccuino.php [--forza] [--senza-pubblicare]
//   https://…/tools/importa_taccuino.php?key=…[&forza=1]
//
// Come per l'atlante: di default NON tocca i post già presenti. Un post
// ritoccato nel pannello vale più del prototipo da cui è nato.
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
$argvSicuro = $argv ?? [];
$forza = $daRiga ? in_array('--forza', $argvSicuro, true) : isset($_GET['forza']);
$pubblica = $daRiga ? !in_array('--senza-pubblicare', $argvSicuro, true) : !isset($_GET['senza_pubblicare']);

$file = __DIR__ . '/../dati/taccuino.json';
if (!is_file($file)) exit("✗ manca dati/taccuino.json: lancia prima `node tools/estrai_taccuino.js`\n");
$D = json_decode(file_get_contents($file), true);
if (!$D || empty($D['post'])) exit("✗ dati/taccuino.json non contiene post\n");

if (!blog_table_ready()) exit("✗ le tabelle del blog non ci sono: lancia /api/migrate_blog.php\n");

echo "Post nel file: " . count($D['post']) . "\n";
echo $forza ? "Modo: SOVRASCRIVO i post esistenti\n\n" : "Modo: aggiungo i mancanti, non tocco gli esistenti\n\n";

// ── categorie: si creano una volta, con l'ordine in cui compaiono ───────────
$categorie = [];
foreach (blog_categories() as $c) $categorie[mb_strtolower($c['name'])] = (int)$c['id'];
$ordine = count($categorie);
foreach ($D['post'] as $p) {
  $nome = trim((string)($p['categoria'] ?? ''));
  if ($nome === '' || isset($categorie[mb_strtolower($nome)])) continue;
  blog_category_save(0, $nome, $ordine++);
  foreach (blog_categories() as $c) $categorie[mb_strtolower($c['name'])] = (int)$c['id'];
  echo "  + categoria: $nome\n";
}

// ── i segnaposto [[scheda:ID|etichetta]] diventano indirizzi veri ───────────
//
// ⚠ Non per identificativo. Nei prototipi gli id sono segnaposto del Design e
// NON corrispondono all'edizione v1.15: il collegamento «Referendum
// istituzionale» porta E04, che nel database è «La Costituzione entra in
// vigore». Seguendo l'id si pubblicherebbero rimandi che portano altrove —
// esattamente ciò che il metodo del sito vieta.
//
// Quindi si cerca per TITOLO: prima uguale, poi contenuto. Se non si trova una
// scheda sola e chiara, resta il testo senza link e la mancanza viene
// segnalata: un buco dichiarato vale più di un rimando sbagliato.
function pds_normalizza(string $s): string {
  $s = mb_strtolower(trim($s));
  $s = strtr($s, ['à'=>'a','è'=>'e','é'=>'e','ì'=>'i','ò'=>'o','ù'=>'u','’'=>"'"]);
  return preg_replace('/[^a-z0-9]+/', ' ', $s);
}

// La tabella decisa a mano vince su ogni ricerca automatica: è il posto dove
// sta scritto perché una citazione porta lì, o perché non porta da nessuna
// parte. Le decisioni editoriali si versionano, non si ricalcolano.
function collegamento_deciso(string $etichetta) {
  static $tab = null;
  if ($tab === null) {
    $tab = [];
    $f = __DIR__ . '/../dati/collegamenti-taccuino.json';
    if (is_file($f)) {
      $j = json_decode(file_get_contents($f), true) ?: [];
      foreach ($j['collegamenti'] ?? [] as $c) $tab[pds_normalizza($c['etichetta'])] = $c;
    }
  }
  return $tab[pds_normalizza($etichetta)] ?? null;
}

function trova_scheda(string $etichetta) {
  static $tutte = null;
  if ($tutte === null) $tutte = db()->query('SELECT id, slug, titolo FROM pds_schede')->fetchAll();
  $cercata = pds_normalizza($etichetta);
  if ($cercata === '') return null;

  $deciso = collegamento_deciso($etichetta);
  if ($deciso) {
    if (empty($deciso['scheda'])) return 'dichiarata-assente';
    foreach ($tutte as $s) if ($s['id'] === $deciso['scheda']) return $s;
  }

  foreach ($tutte as $s) if (pds_normalizza($s['titolo']) === $cercata) return $s;

  $parziali = [];
  foreach ($tutte as $s) {
    $t = pds_normalizza($s['titolo']);
    if (str_contains($t, $cercata) || str_contains($cercata, $t)) $parziali[] = $s;
  }
  // Due candidate sono un'ambiguità, non una risposta: meglio nessun link.
  return count($parziali) === 1 ? $parziali[0] : null;
}

function risolvi_schede(string $html, array &$mancanti): string {
  return preg_replace_callback('/\[\[scheda:([A-Z]{1,2}\d{2,3})\|([^\]]*)\]\]/u', function ($m) use (&$mancanti) {
    $etichetta = $m[2] !== '' ? $m[2] : $m[1];
    $s = trova_scheda($etichetta);
    // «dichiarata-assente» non è un fallimento: è una decisione presa in
    // dati/collegamenti-taccuino.json, con la sua ragione scritta accanto.
    if ($s === 'dichiarata-assente') {
      $c = collegamento_deciso($etichetta);
      $mancanti[] = $etichetta . ' — per scelta: ' . mb_substr((string)($c['perche'] ?? ''), 0, 90) . '…';
      return htmlspecialchars($etichetta, ENT_QUOTES, 'UTF-8');
    }
    if (!$s) { $mancanti[] = $etichetta . ' (il prototipo diceva ' . $m[1] . ') — nessuna corrispondenza'; return htmlspecialchars($etichetta, ENT_QUOTES, 'UTF-8'); }
    return '<a href="' . atlante_url_scheda($s) . '">' . htmlspecialchars($etichetta, ENT_QUOTES, 'UTF-8') . '</a>';
  }, $html);
}

// ── i link ai file di prototipazione dentro il testo dei post ───────────────
// Il post scritto nel markup («Taccuino post 2») si porta dietro i link del
// prototipo: Metodo.dc.html, Scheda.dc.html, Taccuino post.dc.html. Stessa
// regola di build/postbuild.js per le pagine: pagina → pagina, record →
// indirizzo del record, e se il record non si sa, la pagina elenco.
const PDS_PAGINE_PROTOTIPO = [
  'Home' => 'index.html', 'Atlante' => 'atlante.html', 'Cronologia' => 'cronologia.html',
  'Fonti' => 'fonti.html', 'Nessi' => 'nessi.html', 'Media' => 'media.html', 'Metodo' => 'metodo.html',
  'Segnala' => 'segnala.html', 'Privacy' => 'privacy.html', 'Taccuino' => 'blog.html',
  'Taccuino categoria' => 'blog.html',
];
function riscrivi_link_prototipo(string $html, array $postPerFile, array &$conti): string {
  return preg_replace_callback('/href="([^"]*\.dc\.html)(\?[^"]*)?"/', function ($m) use ($postPerFile, &$conti) {
    $file = rawurldecode($m[1]);
    $nome = preg_replace('/\.dc\.html$/', '', $file);
    parse_str(ltrim(html_entity_decode($m[2] ?? ''), '?'), $q);
    $conti['link']++;
    if (isset(PDS_PAGINE_PROTOTIPO[$nome])) return 'href="' . PDS_PAGINE_PROTOTIPO[$nome] . '"';
    if (str_starts_with($nome, 'Scheda')) {
      $s = !empty($q['id']) ? atlante_scheda(strtoupper($q['id'])) : null;
      return 'href="' . ($s ? atlante_url_scheda($s) : 'atlante.html') . '"';
    }
    if ($nome === 'Fonte') {
      $f = !empty($q['id']) ? atlante_fonte(strtoupper($q['id'])) : null;
      return 'href="' . ($f ? atlante_url_fonte($f) : 'fonti.html') . '"';
    }
    if (str_starts_with($nome, 'Taccuino post') && isset($postPerFile[$file])) return 'href="' . $postPerFile[$file] . '"';
    $conti['link']--; $conti['non_risolti'][] = $file;
    return $m[0];
  }, $html);
}

$nuovi = 0; $aggiornati = 0; $saltati = 0; $mancanti = [];
$contiLink = ['link' => 0, 'non_risolti' => []];
$pubblicati = 0;

foreach ($D['post'] as $p) {
  $titolo = trim((string)$p['titolo']);
  if ($titolo === '') continue;

  // Riconoscimento per titolo: gli slug del motore portano l'id davanti
  // (007-titolo), che al primo giro non esiste ancora.
  $st = db()->prepare('SELECT id FROM cms_blog_posts WHERE title=? LIMIT 1');
  $st->execute([$titolo]);
  $id = $st->fetchColumn();

  if ($id && !$forza) { $saltati++; continue; }
  if (!$id) { $id = blog_post_create($titolo); $nuovi++; } else { $aggiornati++; }

  $corpo = risolvi_schede((string)$p['corpo'], $mancanti);
  $lettura = trim((string)($p['lettura'] ?? ''));
  $catId = $categorie[mb_strtolower(trim((string)($p['categoria'] ?? '')))] ?? null;

  blog_post_save((int)$id, [
    'title' => $titolo,
    'subtitle' => (string)($p['occhiello'] ?? ''),
    'eyebrow' => $lettura !== '' ? "Lettura: $lettura" : '',
    'category_id' => $catId,
    'excerpt' => (string)($p['occhiello'] ?? ''),
    'body' => $corpo,
    'author' => 'Redazione dell’atlante',
    'published' => 1,
    'seo_title' => $titolo . ' — Taccuino | Pagine di Storia',
    'seo_desc' => mb_substr((string)($p['occhiello'] ?? ''), 0, 180),
  ]);

  // La data del prototipo è quella editoriale: l'ordine del Taccuino è questo,
  // non l'ordine in cui è girata l'importazione.
  if (!empty($p['data_iso'])) {
    db()->prepare('UPDATE cms_blog_posts SET created_at=? WHERE id=?')
        ->execute([$p['data_iso'] . ' 09:00:00', (int)$id]);
  }

  if ($pubblica) { blog_publish_post((int)$id); $pubblicati++; }
  echo "  · " . ($p['data_iso'] ?? '—') . "  " . mb_substr($titolo, 0, 56) . "\n";
}

// Secondo passo: ora che ogni post ha il suo indirizzo, si riscrivono i link
// fra post e verso le pagine. Al primo passo il post di destinazione poteva
// non esistere ancora.
$postPerFile = [];
foreach ($D['post'] as $p) {
  $st = db()->prepare('SELECT * FROM cms_blog_posts WHERE title=? LIMIT 1');
  $st->execute([trim((string)$p['titolo'])]);
  if ($r = $st->fetch()) $postPerFile[$p['file']] = blog_route($r);
}
foreach (db()->query('SELECT id, body FROM cms_blog_posts')->fetchAll() as $r) {
  if (!str_contains((string)$r['body'], '.dc.html')) continue;
  $nuovo = riscrivi_link_prototipo((string)$r['body'], $postPerFile, $contiLink);
  if ($nuovo !== $r['body']) {
    db()->prepare('UPDATE cms_blog_posts SET body=? WHERE id=?')->execute([$nuovo, $r['id']]);
    if ($pubblica) blog_publish_post((int)$r['id']);
  }
}

printf("\npost         +%d nuovi, %d aggiornati, %d lasciati stare\n", $nuovi, $aggiornati, $saltati);
printf("link riscritti nei testi: %d%s\n", $contiLink['link'],
  $contiLink['non_risolti'] ? ' (non risolti: ' . implode(', ', array_unique($contiLink['non_risolti'])) . ')' : '');
printf("categorie    %d in tutto\n", count(blog_categories()));
if ($pubblica) printf("pubblicati   %d post + gli indici (blog.html, blog-2.html…)\n", $pubblicati);
else echo "non pubblicati: lanciare la pubblicazione dal pannello\n";
if ($mancanti) {
  echo "\n⚠ citazioni senza link — il testo resta intero:\n";
  foreach (array_unique($mancanti) as $x) echo "   · $x\n";
  echo "  Le scelte stanno in dati/collegamenti-taccuino.json, con la ragione.\n";
}
