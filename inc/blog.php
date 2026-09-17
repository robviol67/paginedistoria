<?php
// VelociBuilder LITE — motore Blog/News: categorie + post, rendering pubblico, pubblicazione statica.
// Ogni post = nav (ereditato) + intestazione/corpo + footer (ereditato), stessa filosofia del page-builder.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/footer.php';
require_once __DIR__ . '/pagebuilder.php'; // riusa PB_NAV_CSS/PB_NAV_JS/pb_og_head()
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

const BLOG_PER_PAGE = 9;
const BLOG_DIR = 'blog'; // sottocartella dei singoli post (es. blog/006-titolo.html)

function blog_table_ready() { try { db()->query('SELECT 1 FROM cms_blog_posts LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

/* ---------- Categorie ---------- */
function blog_categories() { try { return db()->query('SELECT * FROM cms_blog_categories ORDER BY sort, id')->fetchAll(); } catch (Throwable $e) { return []; } }
function blog_category_get($id) { $st = db()->prepare('SELECT * FROM cms_blog_categories WHERE id=?'); $st->execute([(int)$id]); return $st->fetch(); }
function blog_slugify($s) { $s = strtolower(trim($s)); $s = preg_replace('/[^a-z0-9]+/', '-', $s); return trim($s, '-') ?: 'categoria'; }
function blog_category_save($id, $name, $sort = 0) {
  $slug = blog_slugify($name);
  if ($id) { $st = db()->prepare('UPDATE cms_blog_categories SET name=?, slug=?, sort=? WHERE id=?'); $st->execute([$name, $slug, (int)$sort, (int)$id]); return (int)$id; }
  $st = db()->prepare('INSERT INTO cms_blog_categories (name, slug, sort) VALUES (?,?,?)'); $st->execute([$name, $slug, (int)$sort]); return db()->lastInsertId();
}
function blog_category_delete($id) {
  db()->prepare('UPDATE cms_blog_posts SET category_id=NULL WHERE category_id=?')->execute([(int)$id]);
  db()->prepare('DELETE FROM cms_blog_categories WHERE id=?')->execute([(int)$id]);
}

/* ---------- Post: CRUD ---------- */
function blog_post_get($id) { $st = db()->prepare('SELECT * FROM cms_blog_posts WHERE id=?'); $st->execute([(int)$id]); return $st->fetch(); }
function blog_post_get_by_slug($slug) { $st = db()->prepare('SELECT * FROM cms_blog_posts WHERE slug=?'); $st->execute([$slug]); return $st->fetch(); }
function blog_post_count() { return (int) db()->query('SELECT COUNT(*) FROM cms_blog_posts')->fetchColumn(); }

// Elenco per l'admin con filtri (pubblicazione/archiviazione/evidenza/categoria/ricerca), come il pannello di riferimento.
function blog_posts_filtered(array $f = []) {
  $where = []; $params = [];
  if (($f['published'] ?? '') === '1') { $where[] = 'published=1'; }
  elseif (($f['published'] ?? '') === '0') { $where[] = 'published=0'; }
  if (($f['archived'] ?? '') !== '1') $where[] = 'archived=0'; // di default nasconde le archiviate
  if (($f['featured'] ?? '') === '1') $where[] = 'featured=1';
  if (!empty($f['category_id'])) { $where[] = 'category_id=?'; $params[] = (int)$f['category_id']; }
  if (!empty($f['q'])) { $where[] = '(title LIKE ? OR subtitle LIKE ?)'; $params[] = '%' . $f['q'] . '%'; $params[] = '%' . $f['q'] . '%'; }
  $sql = 'SELECT * FROM cms_blog_posts' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC';
  $st = db()->prepare($sql); $st->execute($params); return $st->fetchAll();
}
// Post pubblicati e non archiviati, per il sito pubblico (indice + paginazione).
function blog_posts_public($limit = null, $offset = 0) {
  $sql = 'SELECT * FROM cms_blog_posts WHERE published=1 AND archived=0 ORDER BY created_at DESC, id DESC';
  if ($limit !== null) $sql .= ' LIMIT ' . (int)$offset . ',' . (int)$limit;
  return db()->query($sql)->fetchAll();
}
function blog_posts_public_count() { return (int) db()->query('SELECT COUNT(*) FROM cms_blog_posts WHERE published=1 AND archived=0')->fetchColumn(); }

function blog_post_create($title) {
  $title = trim($title) ?: 'Nuova news';
  $st = db()->prepare('INSERT INTO cms_blog_posts (slug, title) VALUES (?, ?)');
  $st->execute(['tmp-' . uniqid(), $title]);
  $id = db()->lastInsertId();
  $slug = sprintf('%03d-%s', $id, blog_slugify($title));
  db()->prepare('UPDATE cms_blog_posts SET slug=? WHERE id=?')->execute([$slug, $id]);
  return $id;
}
// true se la colonna gallery_images esiste (migrate_galleries.php già lanciata) — evita di rompere
// il salvataggio dei post se il deploy dei file precede la migration.
function blog_gallery_ready() {
  static $ok = null; if ($ok !== null) return $ok;
  try { db()->query('SELECT gallery_images FROM cms_blog_posts LIMIT 1'); $ok = true; } catch (Throwable $e) { $ok = false; }
  return $ok;
}
function blog_post_save($id, array $d) {
  $galleryReady = blog_gallery_ready();
  $sql = 'UPDATE cms_blog_posts SET title=?, subtitle=?, eyebrow=?, category_id=?, tags=?, excerpt=?, body=?, cover_image=?'
    . ($galleryReady ? ', gallery_images=?' : '')
    . ', author=?, author_id=?, published=?, featured=?, archived=?, comments_enabled=?, comments_moderation=?, seo_title=?, seo_desc=?, seo_image=?, updated_at=NOW() WHERE id=?';
  $params = [
    trim($d['title'] ?? ''), trim($d['subtitle'] ?? ''), trim($d['eyebrow'] ?? ''),
    !empty($d['category_id']) ? (int)$d['category_id'] : null, trim($d['tags'] ?? ''),
    trim($d['excerpt'] ?? ''), $d['body'] ?? '', trim($d['cover_image'] ?? ''),
  ];
  if ($galleryReady) $params[] = trim($d['gallery_images'] ?? '');
  $params = array_merge($params, [
    trim($d['author'] ?? ''), !empty($d['author_id']) ? (int)$d['author_id'] : null,
    isset($d['published']) ? 1 : 0, isset($d['featured']) ? 1 : 0, isset($d['archived']) ? 1 : 0,
    isset($d['comments_enabled']) ? 1 : 0, isset($d['comments_moderation']) ? 1 : 0,
    trim($d['seo_title'] ?? ''), trim($d['seo_desc'] ?? ''), trim($d['seo_image'] ?? ''), (int)$id,
  ]);
  db()->prepare($sql)->execute($params);
}

// Nome autore da mostrare: utente collegato (author_id) ha priorità sul testo libero (autori esterni).
function blog_author_name($post) {
  if (!empty($post['author_id'])) {
    try { $st = db()->prepare('SELECT name, username FROM cms_users WHERE id=?'); $st->execute([(int)$post['author_id']]); $u = $st->fetch(); if ($u) return $u['name'] ?: $u['username']; }
    catch (Throwable $e) {}
  }
  return $post['author'] ?? '';
}
function blog_post_delete($id) {
  $p = blog_post_get($id); if (!$p) return;
  @unlink(__DIR__ . '/../' . BLOG_DIR . '/' . $p['slug'] . '.html');
  db()->prepare('DELETE FROM cms_blog_posts WHERE id=?')->execute([(int)$id]);
}

/* ---------- Commenti sulle news (con moderazione opzionale per post) ---------- */
function blog_comments_table_ready() { try { db()->query('SELECT 1 FROM cms_blog_comments LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

function blog_comments_for_post($postId, $approvedOnly = true) {
  $sql = 'SELECT * FROM cms_blog_comments WHERE post_id=?' . ($approvedOnly ? ' AND approved=1' : '') . ' ORDER BY created_at ASC';
  $st = db()->prepare($sql); $st->execute([(int)$postId]); return $st->fetchAll();
}

function blog_comment_create($postId, $name, $email, $body, $ip = '') {
  $post = blog_post_get($postId);
  if (!$post || empty($post['comments_enabled'])) throw new Exception('Commenti non abilitati per questo articolo.');
  $name = trim($name); $email = trim($email); $body = trim($body);
  if ($name === '' || $email === '' || $body === '') throw new Exception('Compila nome, email e commento.');
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Email non valida.');
  $approved = empty($post['comments_moderation']) ? 1 : 0;
  $st = db()->prepare('INSERT INTO cms_blog_comments (post_id, name, email, body, approved, ip) VALUES (?,?,?,?,?,?)');
  $st->execute([(int)$postId, $name, $email, $body, $approved, $ip]);
  if (function_exists('nc_log_consent')) nc_log_consent('commento-blog', $name, $email);
  return ['approved' => $approved];
}
function blog_comment_set_approved($id, $approved) { db()->prepare('UPDATE cms_blog_comments SET approved=? WHERE id=?')->execute([$approved ? 1 : 0, (int)$id]); }
function blog_comment_delete($id) { db()->prepare('DELETE FROM cms_blog_comments WHERE id=?')->execute([(int)$id]); }
function blog_comments_pending_count() { try { return (int) db()->query('SELECT COUNT(*) FROM cms_blog_comments WHERE approved=0')->fetchColumn(); } catch (Throwable $e) { return 0; } }
// Coda di moderazione (in attesa) con titolo/slug del post, per l'admin.
function blog_comments_pending_all() {
  try { return db()->query('SELECT c.*, p.title AS post_title, p.slug AS post_slug FROM cms_blog_comments c JOIN cms_blog_posts p ON p.id = c.post_id WHERE c.approved = 0 ORDER BY c.created_at ASC')->fetchAll(); }
  catch (Throwable $e) { return []; }
}
// Tutti i commenti (approvati compresi), opzionalmente filtrati per post — per lo storico admin.
function blog_comments_all_admin($postId = null) {
  $sql = 'SELECT c.*, p.title AS post_title, p.slug AS post_slug FROM cms_blog_comments c JOIN cms_blog_posts p ON p.id = c.post_id';
  $params = [];
  if ($postId) { $sql .= ' WHERE c.post_id=?'; $params[] = (int)$postId; }
  $sql .= ' ORDER BY c.created_at DESC';
  try { $st = db()->prepare($sql); $st->execute($params); return $st->fetchAll(); } catch (Throwable $e) { return []; }
}

// Sezione commenti (lista approvati + form d'invio), inserita nella pagina pubblica del post se comments_enabled.
function blog_comments_section_html($post) {
  if (empty($post['comments_enabled'])) return '';
  $comments = blog_comments_for_post($post['id'], true);
  $count = count($comments);
  $inputCss = 'padding:11px 13px;border:1px solid oklch(88% 0.01 260);border-radius:9px;font:15px \'Public Sans\',sans-serif';
  $out = '<div style="max-width:760px;margin:0 auto;padding:0 32px 56px">';
  $out .= '<h2 style="font-family:\'Poppins\',sans-serif;font-size:22px;font-weight:700;margin:0 0 20px">Commenti (' . $count . ')</h2>';
  if ($comments) {
    foreach ($comments as $c) {
      $out .= '<div style="border-top:1px solid oklch(90% 0.01 260);padding:16px 0">'
        . '<div style="font-weight:700;font-size:14px">' . h($c['name']) . '</div>'
        . '<div style="font-size:12px;color:oklch(58% 0.02 260);margin-bottom:8px">' . h(date('d/m/Y H:i', strtotime($c['created_at']))) . '</div>'
        . '<div style="font-size:14.5px;line-height:1.6;white-space:pre-line">' . h($c['body']) . '</div>'
        . '</div>';
    }
  } else {
    $out .= '<p style="color:oklch(58% 0.02 260);font-size:14px">Nessun commento, per ora.</p>';
  }
  $out .= '<div style="border-top:1px solid oklch(90% 0.01 260);margin-top:8px;padding-top:24px">'
    . '<h3 style="font-family:\'Poppins\',sans-serif;font-size:17px;font-weight:700;margin:0 0 14px">Lascia un commento</h3>'
    . '<form method="post" action="blog-comment.php" style="display:flex;flex-direction:column;gap:12px;max-width:480px">'
    . '<input type="hidden" name="post_id" value="' . (int)$post['id'] . '">'
    . '<input type="text" name="name" placeholder="Nome" required style="' . $inputCss . '">'
    . '<input type="email" name="email" placeholder="Email (non pubblicata)" required style="' . $inputCss . '">'
    . '<textarea name="body" placeholder="Il tuo commento" required style="' . $inputCss . ';min-height:90px;resize:vertical"></textarea>'
    . '<label style="font-size:13px;color:oklch(45% 0.02 260);display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="consent" value="1" required style="margin-top:3px"> Acconsento al trattamento dei miei dati per la pubblicazione del commento (informativa privacy).</label>'
    . '<button type="submit" style="align-self:flex-start;padding:11px 24px;background:#1F7A3D;color:#fff;border:none;border-radius:100px;font-weight:700;font-size:14px;cursor:pointer">Invia commento</button>'
    . (!empty($post['comments_moderation']) ? '<p style="font-size:12px;color:oklch(58% 0.02 260);margin:0">Il commento sarà visibile dopo l\'approvazione.</p>' : '')
    . '</form></div></div>';
  return $out;
}

/* ---------- Tag (CSV su cms_blog_posts.tags — niente tabelle dedicate, volumi piccoli) ---------- */
function blog_parse_tags($csv) { return array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$csv))))); }

// Tutti i tag usati (in bozze comprese), per l'autocomplete admin — non filtrato per stato.
function blog_all_tags_admin() {
  try { $rows = db()->query('SELECT tags FROM cms_blog_posts')->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { return []; }
  $set = [];
  foreach ($rows as $csv) foreach (blog_parse_tags($csv) as $t) $set[$t] = true;
  $tags = array_keys($set); sort($tags, SORT_FLAG_CASE | SORT_STRING);
  return $tags;
}

// Tag -> numero di post pubblici che li usano (per la tag cloud pubblica).
function blog_public_tags() {
  $counts = [];
  foreach (blog_posts_public() as $p) foreach (blog_parse_tags($p['tags']) as $t) $counts[$t] = ($counts[$t] ?? 0) + 1;
  ksort($counts, SORT_FLAG_CASE | SORT_STRING);
  return $counts;
}
function blog_tag_label($slug) { foreach (blog_public_tags() as $tag => $c) if (blog_slugify($tag) === $slug) return $tag; return $slug; }
function blog_posts_public_by_tag($slug) {
  return array_values(array_filter(blog_posts_public(), function ($p) use ($slug) {
    foreach (blog_parse_tags($p['tags']) as $t) if (blog_slugify($t) === $slug) return true;
    return false;
  }));
}
function blog_tag_cloud_html($activeSlug = null) {
  $tags = blog_public_tags(); if (!$tags) return '';
  $pill = function ($label, $href, $on, $count = null) {
    return '<a href="' . h($href) . '" style="padding:7px 14px;border-radius:100px;font-size:13px;font-weight:600;text-decoration:none;'
      . ($on ? 'background:#1F7A3D;color:#fff' : 'background:#fff;border:1px solid oklch(90% 0.01 260);color:oklch(30% 0.02 260)') . '">'
      . h($label) . ($count !== null ? ' <span style="opacity:.65">' . (int)$count . '</span>' : '') . '</a>';
  };
  $out = '<div style="max-width:1240px;margin:0 auto;padding:0 48px 24px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">';
  $out .= $pill('Tutte', 'blog.html', !$activeSlug);
  foreach ($tags as $tag => $count) { $slug = blog_slugify($tag); $out .= $pill('#' . $tag, 'blog-tag.php?slug=' . $slug, $activeSlug === $slug, $count); }
  $out .= '</div>';
  return $out;
}

/* ---------- Rendering pubblico ---------- */
function blog_route($post) { return BLOG_DIR . '/' . $post['slug'] . '.html'; }

function blog_card_html($post) {
  $cat = $post['category_id'] ? blog_category_get($post['category_id']) : null;
  $img = $post['cover_image'] ?: 'assets/product-placeholder.png';
  $date = date('d/m/Y', strtotime($post['created_at']));
  // blog_card_html() è usata solo nelle pagine indice, che vivono nella ROOT del sito (blog.html) — niente prefisso "../".
  return '<a href="' . h(blog_route($post)) . '" style="text-decoration:none;color:inherit;display:block;border:1px solid oklch(90% 0.01 260);border-radius:16px;overflow:hidden;background:#fff">'
    . '<div style="aspect-ratio:16/10;background:#f4f6f3"><img src="' . h($img) . '" alt="" style="width:100%;height:100%;object-fit:cover"></div>'
    . '<div style="padding:20px">'
    . ($cat ? '<div style="font-size:12px;font-weight:700;color:#1F7A3D;text-transform:uppercase;letter-spacing:.03em;margin-bottom:8px">' . h($cat['name']) . '</div>' : '')
    . '<div style="font-family:\'Poppins\',sans-serif;font-size:18px;font-weight:700;margin-bottom:8px;line-height:1.3">' . h($post['title']) . '</div>'
    . ($post['excerpt'] ? '<div style="font-size:14px;color:oklch(45% 0.02 260);line-height:1.6;margin-bottom:10px">' . h(mb_strimwidth($post['excerpt'], 0, 130, '…')) . '</div>' : '')
    . '<div style="font-size:12px;color:oklch(58% 0.02 260)">' . h($date) . '</div>'
    . '</div></a>';
}

function blog_post_render_doc($post) {
  $title = $post['seo_title'] ?: $post['title'];
  $cat = $post['category_id'] ? blog_category_get($post['category_id']) : null;
  $date = date('d/m/Y', strtotime($post['created_at']));
  // percorsi SENZA "../": la pagina vive in blog/, ma <base> nell'head la fa risolvere come se fosse in root
  // (stessa convenzione root-relativa usata da nav_render_bar()/footer_render_full() — niente da riscrivere lì).
  $cover = $post['cover_image'] ? '<div style="max-width:900px;margin:0 auto;padding:0 32px"><img src="' . h($post['cover_image']) . '" alt="" style="width:100%;height:auto;border-radius:18px;margin-top:24px"></div>' : '';
  $header = '<div style="max-width:760px;margin:0 auto;padding:40px 32px 0">'
    . ($post['eyebrow'] ? '<div style="display:inline-block;padding:7px 16px;background:oklch(90% 0.05 152);color:oklch(34% 0.10 152);border-radius:100px;font-size:13px;font-weight:600;margin-bottom:16px">' . h($post['eyebrow']) . '</div>' : '')
    . '<h1 style="font-family:\'Poppins\',sans-serif;font-size:32px;line-height:1.2;font-weight:700;margin:0 0 12px">' . h($post['title']) . '</h1>'
    . ($post['subtitle'] ? '<p style="font-size:17px;color:oklch(45% 0.02 260);margin:0 0 16px">' . h($post['subtitle']) . '</p>' : '')
    . '<div style="font-size:13px;color:oklch(58% 0.02 260);display:flex;gap:10px;align-items:center;flex-wrap:wrap">'
    . ($cat ? '<span style="color:#1F7A3D;font-weight:700">' . h($cat['name']) . '</span> · ' : '')
    . (($an = blog_author_name($post)) ? h($an) . ' · ' : '') . h($date) . '</div></div>';
  $body = '<div class="vb-richtext" style="max-width:760px;margin:0 auto;padding:28px 32px 56px">' . $post['body'] . '</div>';
  $gallery = '';
  if (blog_gallery_ready() && trim($post['gallery_images'] ?? '') !== '') {
    require_once __DIR__ . '/gallery.php';
    $images = json_decode($post['gallery_images'], true) ?: [];
    $grid = vb_gallery_grid_html($images, ['columns' => 3]);
    if ($grid !== '') $gallery = '<div style="max-width:1040px;margin:0 auto;padding:0 32px 56px">' . $grid . '</div>';
  }
  $comments = blog_comments_table_ready() ? blog_comments_section_html($post) : '';
  $back = '<div style="max-width:760px;margin:0 auto;padding:0 32px 56px"><a href="blog.html" style="color:#1F7A3D;font-weight:600;text-decoration:none">&larr; Torna al blog</a></div>';

  $bodyHtml = nav_render_bar() . $header . $cover . $body . $gallery . $comments . $back . footer_render_full();
  $base = defined('SITE_DOMAIN') ? '<base href="' . h(rtrim(SITE_DOMAIN, '/') . '/') . "\">\n" : '';
  return cms_inject_assistente("<!DOCTYPE html>\n<html lang=\"it\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
    . $base // il post vive in blog/: <base> fa risolvere i link root-relativi del nav/footer come se fossimo in root
    . '<title>' . h($title) . "</title>\n"
    . pb_og_head($title, $post['seo_desc'] ?: $post['excerpt'], $post['seo_image'] ?: $post['cover_image'], blog_route($post))
    . "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n"
    . "<link href=\"https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Public+Sans:wght@400;500;600;700&display=swap\" rel=\"stylesheet\">\n"
    . "<style>body{margin:0;background:oklch(98% 0.008 95);font-family:'Public Sans',system-ui,sans-serif;color:oklch(24% 0.015 260)}" . PB_NAV_CSS . "</style>\n"
    . cms_css_mobile()   // adattamento al telefono, come per le pagine da Design
    . "</head>\n<body>\n" . $bodyHtml . "\n" . PB_NAV_JS . "\n</body>\n</html>\n");
}

// Nome file dell'indice per numero di pagina (1 = blog.html, N = blog-N.html)
function blog_index_file($page) { return $page <= 1 ? 'blog.html' : 'blog-' . (int)$page . '.html'; }

function blog_index_render_doc($page = 1) {
  $total = blog_posts_public_count();
  $pages = max(1, (int)ceil($total / BLOG_PER_PAGE));
  $page = max(1, min($page, $pages));
  $posts = blog_posts_public(BLOG_PER_PAGE, ($page - 1) * BLOG_PER_PAGE);

  $cards = '';
  foreach ($posts as $p) $cards .= blog_card_html($p);
  $grid = $posts
    ? '<div style="max-width:1240px;margin:0 auto;padding:8px 48px 40px;display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:24px">' . $cards . '</div>'
    : '<div style="max-width:1240px;margin:0 auto;padding:40px 48px;text-align:center;color:oklch(58% 0.02 260)">Nessuna news pubblicata.</div>';

  $pagination = '';
  if ($pages > 1) {
    $links = '';
    for ($i = 1; $i <= $pages; $i++) {
      $active = $i === $page;
      $links .= '<a href="' . h(blog_index_file($i)) . '" style="padding:9px 15px;border-radius:100px;text-decoration:none;font-size:14px;font-weight:600;' . ($active ? 'background:#1F7A3D;color:#fff' : 'background:#fff;border:1px solid oklch(90% 0.01 260);color:oklch(30% 0.02 260)') . '">' . $i . '</a>';
    }
    $pagination = '<div style="max-width:1240px;margin:0 auto;padding:0 48px 56px;display:flex;gap:8px;justify-content:center">' . $links . '</div>';
  }

  $heading = '<div style="max-width:1240px;margin:0 auto;padding:48px 48px 24px"><h1 style="font-family:\'Poppins\',sans-serif;font-size:34px;font-weight:700;margin:0">Blog</h1></div>';
  $bodyHtml = nav_render_bar() . $heading . blog_tag_cloud_html() . $grid . $pagination . footer_render_full();
  $title = 'Blog' . ($page > 1 ? ' — pagina ' . $page : '');

  $base = defined('SITE_DOMAIN') ? '<base href="' . h(rtrim(SITE_DOMAIN, '/') . '/') . "\">\n" : '';
  return cms_inject_assistente("<!DOCTYPE html>\n<html lang=\"it\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
    . $base // vive già in root, ma coerente con blog_post_render_doc() e robusto a futuri cambi
    . '<title>' . h($title) . "</title>\n"
    . pb_og_head($title, '', '', blog_index_file($page))
    . "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n"
    . "<link href=\"https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Public+Sans:wght@400;500;600;700&display=swap\" rel=\"stylesheet\">\n"
    . "<style>body{margin:0;background:oklch(98% 0.008 95);font-family:'Public Sans',system-ui,sans-serif;color:oklch(24% 0.015 260)}" . PB_NAV_CSS . "</style>\n"
    . cms_css_mobile()   // adattamento al telefono, come per le pagine da Design
    . "</head>\n<body>\n" . $bodyHtml . "\n" . PB_NAV_JS . "\n</body>\n</html>\n");
}

// Indice filtrato per tag — pagina dinamica (blog-tag.php), non pregenerata: i tag sono liberi e
// pregenerare una pagina statica per ognuno complicherebbe pubblicazione/pulizia senza bisogno reale ai volumi attuali.
function blog_tag_index_render_doc($slug, $page = 1) {
  $label = blog_tag_label($slug);
  $matched = blog_posts_public_by_tag($slug);
  $total = count($matched);
  $pages = max(1, (int)ceil($total / BLOG_PER_PAGE));
  $page = max(1, min($page, $pages));
  $slice = array_slice($matched, ($page - 1) * BLOG_PER_PAGE, BLOG_PER_PAGE);

  $cards = ''; foreach ($slice as $p) $cards .= blog_card_html($p);
  $grid = $slice
    ? '<div style="max-width:1240px;margin:0 auto;padding:8px 48px 40px;display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:24px">' . $cards . '</div>'
    : '<div style="max-width:1240px;margin:0 auto;padding:40px 48px;text-align:center;color:oklch(58% 0.02 260)">Nessuna news con questo tag.</div>';

  $pagination = '';
  if ($pages > 1) {
    $links = '';
    for ($i = 1; $i <= $pages; $i++) {
      $active = $i === $page;
      $links .= '<a href="blog-tag.php?slug=' . h($slug) . '&page=' . $i . '" style="padding:9px 15px;border-radius:100px;text-decoration:none;font-size:14px;font-weight:600;' . ($active ? 'background:#1F7A3D;color:#fff' : 'background:#fff;border:1px solid oklch(90% 0.01 260);color:oklch(30% 0.02 260)') . '">' . $i . '</a>';
    }
    $pagination = '<div style="max-width:1240px;margin:0 auto;padding:0 48px 56px;display:flex;gap:8px;justify-content:center">' . $links . '</div>';
  }

  $heading = '<div style="max-width:1240px;margin:0 auto;padding:48px 48px 24px"><h1 style="font-family:\'Poppins\',sans-serif;font-size:34px;font-weight:700;margin:0">Blog — #' . h($label) . '</h1></div>';
  $bodyHtml = nav_render_bar() . $heading . blog_tag_cloud_html($slug) . $grid . $pagination . footer_render_full();
  $title = 'Blog — #' . $label . ($page > 1 ? ' — pagina ' . $page : '');

  $base = defined('SITE_DOMAIN') ? '<base href="' . h(rtrim(SITE_DOMAIN, '/') . '/') . "\">\n" : '';
  return cms_inject_assistente("<!DOCTYPE html>\n<html lang=\"it\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
    . $base
    . '<title>' . h($title) . "</title>\n"
    . pb_og_head($title, '', '', 'blog-tag.php?slug=' . $slug)
    . "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n"
    . "<link href=\"https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Public+Sans:wght@400;500;600;700&display=swap\" rel=\"stylesheet\">\n"
    . "<style>body{margin:0;background:oklch(98% 0.008 95);font-family:'Public Sans',system-ui,sans-serif;color:oklch(24% 0.015 260)}" . PB_NAV_CSS . "</style>\n"
    . cms_css_mobile()   // adattamento al telefono, come per le pagine da Design
    . "</head>\n<body>\n" . $bodyHtml . "\n" . PB_NAV_JS . "\n</body>\n</html>\n");
}

/* ---------- Pubblicazione statica ---------- */
function blog_index_publish_all() {
  $total = blog_posts_public_count();
  $pages = max(1, (int)ceil($total / BLOG_PER_PAGE));
  for ($i = 1; $i <= $pages; $i++) {
    $html = blog_index_render_doc($i);
    file_put_contents(__DIR__ . '/../' . blog_index_file($i), $html);
  }
  // rimuove pagine indice in eccesso rimaste da una lista che si è accorciata
  for ($i = $pages + 1; $i <= $pages + 20; $i++) {
    $f = __DIR__ . '/../' . blog_index_file($i);
    if (is_file($f)) @unlink($f); else break;
  }
}
function blog_publish_post($id) {
  $post = blog_post_get($id); if (!$post) throw new Exception('Post non trovato.');
  $dir = __DIR__ . '/../' . BLOG_DIR;
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $html = blog_post_render_doc($post);
  $out = $dir . '/' . $post['slug'] . '.html';
  @copy($out, $out . '.bak');
  if (file_put_contents($out, $html) === false) throw new Exception('Scrittura non riuscita (permessi?).');
  blog_index_publish_all();
  return true;
}
// Ripubblica tutti i post pubblicati + gli indici — usato quando cambia il menu/footer condiviso.
function blog_publish_all() {
  foreach (blog_posts_public() as $p) {
    $dir = __DIR__ . '/../' . BLOG_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    file_put_contents($dir . '/' . $p['slug'] . '.html', blog_post_render_doc($p));
  }
  blog_index_publish_all();
}
