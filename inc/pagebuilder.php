<?php
// VelociBuilder LITE — Pagine libere (page-builder): CRUD pagina + assemblaggio documento + pubblicazione statica.
// Una pagina libera = nav (ereditato) + sequenza di blocchi (inc/blocks.php) + footer (ereditato), come le
// pagine da Design ma senza un file .dc.html sorgente.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/footer.php';
require_once __DIR__ . '/blocks.php';
require_once __DIR__ . '/cms.php'; // per cms_pages() (evita collisioni di slug con le pagine da Design)
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

/* ---------- CSS/JS di base (equivalenti a quelli incorporati dal build per le pagine da Design) ---------- */
const PB_NAV_CSS = '
#siteNav{transition:padding .28s ease, box-shadow .28s ease, background-color .28s ease}
#siteNav.nav-shrink{padding-top:10px !important;padding-bottom:10px !important;box-shadow:0 6px 24px rgba(0,0,0,.10);background:oklch(98% 0.008 95 / 0.97) !important}
.vb-navcta{transition:transform .2s ease,box-shadow .2s ease}
.vb-navcta:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(31,122,61,.3)}
@media (prefers-reduced-motion: reduce){#siteNav{transition:none}}
.vb-richtext h2{font-family:\'Poppins\',sans-serif;font-size:26px;font-weight:700;margin:22px 0 12px}
.vb-richtext h3{font-family:\'Poppins\',sans-serif;font-size:20px;font-weight:700;margin:18px 0 10px}
.vb-richtext p{font-size:16px;line-height:1.75;color:oklch(40% 0.02 260);margin:0 0 14px}
.vb-richtext ul,.vb-richtext ol{margin:0 0 14px;padding-left:22px;font-size:16px;line-height:1.75;color:oklch(40% 0.02 260)}
.vb-richtext a{color:#1F7A3D}
';
const PB_NAV_JS = '<script>
(function(){var n=document.getElementById(\'siteNav\');if(!n)return;
function u(){n.classList.toggle(\'nav-shrink\',(window.scrollY||document.documentElement.scrollTop)>24);}
u();window.addEventListener(\'scroll\',u,{passive:true});})();
</script>';

function pb_table_ready() { try { db()->query('SELECT 1 FROM cms_custom_pages LIMIT 1'); return true; } catch (Throwable $e) { return false; } }
function pb_pages_all() { try { return db()->query('SELECT * FROM cms_custom_pages ORDER BY id DESC')->fetchAll(); } catch (Throwable $e) { return []; } }
function pb_page_get($id) { $st = db()->prepare('SELECT * FROM cms_custom_pages WHERE id=?'); $st->execute([(int)$id]); return $st->fetch(); }

function pb_slugify($s) {
  $s = strtolower(trim($s));
  $s = preg_replace('/[^a-z0-9]+/', '-', $s);
  return trim($s, '-') ?: 'pagina';
}
// Slug univoco: non deve collidere con le pagine da Design (out) né con altre pagine libere.
function pb_unique_slug($base, $excludeId = 0) {
  $designOuts = array_map(fn($p) => str_replace('.html', '', $p['out']), cms_pages());
  $slug = $base; $i = 2;
  while (true) {
    if (!in_array($slug, $designOuts, true)) {
      $st = db()->prepare('SELECT id FROM cms_custom_pages WHERE slug=? AND id<>?');
      $st->execute([$slug, (int)$excludeId]);
      if (!$st->fetch()) break;
    }
    $slug = $base . '-' . $i++;
  }
  return $slug;
}
function pb_page_create($title) {
  $slug = pb_unique_slug(pb_slugify($title ?: 'nuova-pagina'));
  $st = db()->prepare('INSERT INTO cms_custom_pages (slug, title, status) VALUES (?,?,\'draft\')');
  $st->execute([$slug, trim($title) ?: 'Nuova pagina']);
  return db()->lastInsertId();
}
function pb_page_save($id, array $d) {
  $slug = pb_unique_slug(pb_slugify($d['slug'] ?? $d['title'] ?? ''), $id);
  $st = db()->prepare('UPDATE cms_custom_pages SET title=?, slug=?, seo_title=?, seo_desc=?, seo_image=?, updated_at=NOW() WHERE id=?');
  $st->execute([trim($d['title'] ?? ''), $slug, trim($d['seo_title'] ?? ''), trim($d['seo_desc'] ?? ''), trim($d['seo_image'] ?? ''), (int)$id]);
  return $slug;
}
function pb_page_delete($id) {
  $p = pb_page_get($id); if (!$p) return;
  $st = db()->prepare('DELETE FROM cms_page_blocks WHERE page_id=?'); $st->execute([(int)$id]);
  $st = db()->prepare('DELETE FROM cms_custom_pages WHERE id=?'); $st->execute([(int)$id]);
  @unlink(__DIR__ . '/../' . $p['slug'] . '.html');
}

/* ---------- OpenGraph/meta (equivalente PHP di ogHead() in build.js) ---------- */
function pb_og_head($title, $desc, $image, $outFile) {
  $domain = defined('SITE_DOMAIN') ? rtrim(SITE_DOMAIN, '/') : '';
  $name = defined('SITE_NAME') ? SITE_NAME : '';
  $url = $domain !== '' ? $domain . '/' . $outFile : '';
  $img = $image ?: (defined('SITE_OG_IMAGE') ? SITE_OG_IMAGE : '');
  if ($img !== '' && !preg_match('~^https?://~i', $img) && $domain !== '') $img = $domain . '/' . ltrim($img, '/');
  $out = '';
  if ($desc !== '') $out .= '<meta name="description" content="' . h($desc) . "\">\n";
  if ($url !== '') $out .= '<link rel="canonical" href="' . h($url) . "\">\n";
  $out .= "<meta property=\"og:type\" content=\"website\">\n";
  if ($name !== '') $out .= '<meta property="og:site_name" content="' . h($name) . "\">\n";
  $out .= "<meta property=\"og:locale\" content=\"it_IT\">\n";
  $out .= '<meta property="og:title" content="' . h($title) . "\">\n";
  if ($desc !== '') $out .= '<meta property="og:description" content="' . h($desc) . "\">\n";
  if ($url !== '') $out .= '<meta property="og:url" content="' . h($url) . "\">\n";
  if ($img !== '') $out .= '<meta property="og:image" content="' . h($img) . "\">\n";
  return $out;
}

/* ---------- Assemblaggio documento + pubblicazione statica ---------- */
// Font + CSS del sito ereditati da una pagina pubblicata (index.html): così le pagine libere
// combaciano col Design (barra nav a classi stilizzata, font corretti). Fallback ai default storici.
function pb_site_head() {
  $idx = @file_get_contents(__DIR__ . '/../index.html');
  if ($idx !== false && $idx !== '') {
    $links = '';
    if (preg_match_all('/<link[^>]+(?:fonts\.googleapis|fonts\.gstatic)[^>]*>/i', $idx, $lm)) $links = implode("\n", $lm[0]);
    if (preg_match_all('/<style>[\s\S]*?<\/style>/i', $idx, $sm)) {
      return $links . "\n" . implode("\n", $sm[0]) . "\n<style>" . PB_NAV_CSS . "</style>";
    }
  }
  return "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n"
    . "<link href=\"https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Public+Sans:wght@400;500;600;700&display=swap\" rel=\"stylesheet\">\n"
    . "<style>body{margin:0;background:oklch(98% 0.008 95);font-family:'Public Sans',system-ui,sans-serif;color:oklch(24% 0.015 260)}" . PB_NAV_CSS . "</style>";
}
function pb_render_doc($page) {
  $title = $page['seo_title'] ?: $page['title'];
  $body = nav_render_bar() . blocks_render_page($page['id']) . footer_render_full();
  return cms_inject_assistente("<!DOCTYPE html>\n<html lang=\"it\">\n<head>\n"
    . "<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
    . '<title>' . h($title) . "</title>\n"
    . pb_og_head($title, $page['seo_desc'] ?? '', $page['seo_image'] ?? '', $page['slug'] . '.html')
    . pb_site_head() . "\n"
    . cms_css_mobile()   // adattamento al telefono, come per le pagine da Design
    . "</head>\n<body>\n" . $body . "\n" . PB_NAV_JS . "\n</body>\n</html>\n");
}
function pb_publish($id) {
  $page = pb_page_get($id); if (!$page) throw new Exception('Pagina non trovata.');
  $html = pb_render_doc($page);
  $out = __DIR__ . '/../' . $page['slug'] . '.html';
  @copy($out, $out . '.bak');
  if (file_put_contents($out, $html) === false) throw new Exception('Scrittura non riuscita (permessi?).');
  $st = db()->prepare("UPDATE cms_custom_pages SET status='published' WHERE id=?"); $st->execute([(int)$id]);
  return true;
}
