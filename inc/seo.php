<?php
// ============================================================================
// VelociBuilder LITE — SEO & Condivisione (generico, per QUALSIASI sito vblite)
// Sitemap.xml + robots.txt + dati strutturati JSON-LD + immagine OG di default.
// Le impostazioni stanno in cms_settings (chiavi "seo_*"), editabili da admin/seo.php.
// I default vengono dai costanti per-sito SITE_DOMAIN / SITE_NAME (config.php).
// ============================================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/cms.php';

function seo_domain() {
  $d = defined('SITE_DOMAIN') ? SITE_DOMAIN : (setting_get('seo_domain', '') ?: '');
  return rtrim($d, '/');
}
function seo_site_name() {
  return setting_get('seo_biz_name', '') ?: (defined('SITE_NAME') ? SITE_NAME : 'Sito');
}
// URL assoluto dell'immagine di condivisione di default (fallback per le pagine senza seo_image proprio)
function seo_og_default() {
  $v = trim((string) setting_get('seo_og_image', ''));
  if ($v === '') $v = seo_domain() . '/assets/social.png';
  if ($v !== '' && !preg_match('~^https?://~i', $v)) $v = seo_domain() . '/' . ltrim($v, '/');
  return $v;
}
function seo_webroot() { return realpath(__DIR__ . '/..'); }

// --- elenco URL indicizzabili: scansiona gli .html nella root, esclude noindex/backup ---
function seo_index_urls() {
  $root = seo_webroot();
  $dom = seo_domain();
  $urls = [];
  foreach (glob($root . '/*.html') as $file) {
    $name = basename($file);
    if (preg_match('/\.bak$|^tpl-|^_/', $name)) continue;
    $head = @file_get_contents($file, false, null, 0, 4000) ?: '';
    if (stripos($head, 'noindex') !== false) continue;                 // rispetta il noindex
    $rel = ($name === 'index.html') ? '' : $name;
    $urls[$dom . '/' . $rel] = @filemtime($file) ?: time();
  }
  // le pagine CMS del manifest (se un file non fosse ancora stato pubblicato)
  foreach (cms_pages() as $p) {
    if (!empty($p['noindex'])) continue;
    $out = $p['out'] ?? '';
    if ($out === '') continue;
    $rel = ($out === 'index.html') ? '' : $out;
    $u = $dom . '/' . $rel;
    if (!isset($urls[$u])) $urls[$u] = time();
  }
  return $urls;
}

// --- genera e SCRIVE sitemap.xml nella web root. Ritorna [ok, count, path|errore] ---
function seo_generate_sitemap() {
  $urls = seo_index_urls();
  $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
  $x .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
  foreach ($urls as $u => $mtime) {
    $pri = (rtrim($u, '/') === rtrim(seo_domain(), '/')) ? '1.0' : '0.7';
    $x .= '  <url><loc>' . htmlspecialchars($u, ENT_XML1) . '</loc>'
        . '<lastmod>' . date('Y-m-d', $mtime) . '</lastmod>'
        . '<changefreq>weekly</changefreq><priority>' . $pri . '</priority></url>' . "\n";
  }
  $x .= '</urlset>' . "\n";
  $path = seo_webroot() . '/sitemap.xml';
  if (@file_put_contents($path, $x) === false) return [false, 0, 'scrittura non riuscita (permessi su ' . $path . '?)'];
  return [true, count($urls), $path];
}

// --- genera e SCRIVE robots.txt sano (sblocca immagini/PDF, referenzia la sitemap) ---
function seo_generate_robots() {
  $sm = seo_domain() . '/sitemap.xml';
  $r = "User-agent: *\n"
     . "Allow: /\n"
     . "Disallow: /admin/\n"
     . "Disallow: /api/\n"
     . "Disallow: /inc/\n"
     . "Disallow: /build/\n"
     . "Crawl-delay: 2\n\n"
     . "Sitemap: " . $sm . "\n";
  $path = seo_webroot() . '/robots.txt';
  if (@file_put_contents($path, $r) === false) return [false, 'scrittura non riuscita (permessi su ' . $path . '?)'];
  return [true, $path];
}
function seo_current_robots() {
  $p = seo_webroot() . '/robots.txt';
  return is_file($p) ? (@file_get_contents($p) ?: '') : '';
}

// --- dati strutturati JSON-LD per una pagina (Organization + LocalBusiness in home,
//     BreadcrumbList altrove). Guidato dalle impostazioni; niente hardcoding. ---
function seo_jsonld($slug, $pageTitle = '') {
  if (setting_get('seo_jsonld_on', '') !== '1') return '';
  $dom = seo_domain();
  $name = seo_site_name();
  $phone = trim((string) setting_get('seo_phone', ''));
  $email = trim((string) setting_get('seo_email', ''));
  $og = seo_og_default();
  $isHome = ($slug === 'home' || $slug === 'index');

  $graph = [];
  // Organization (sempre)
  $org = [
    '@type' => 'Organization',
    '@id'   => $dom . '/#org',
    'name'  => $name,
    'url'   => $dom . '/',
    'logo'  => $og,
  ];
  if ($email) $org['email'] = $email;
  if ($phone) $org['telephone'] = $phone;
  // profili social: opt_get legge override DB oppure default dal Design (options-seed).
  // Whitelist per HOST esatto (non substring: "x.com" NON deve matchare "xerox.com").
  require_once __DIR__ . '/options.php';
  $allow = ['facebook.com','instagram.com','linkedin.com','youtube.com','youtu.be','twitter.com','x.com','tiktok.com'];
  $social = [];
  foreach (['social_facebook','social_instagram','social_linkedin','social_youtube','social_twitter','social_tiktok'] as $sk) {
    $u = trim((string) opt_get($sk));
    if ($u === '') continue;
    $host = strtolower((string) parse_url($u, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);
    foreach ($allow as $d) {
      if ($host === $d || substr($host, -strlen('.' . $d)) === '.' . $d) { $social[] = $u; break; }
    }
  }
  if ($social) $org['sameAs'] = array_values(array_unique($social));
  $graph[] = $org;

  // LocalBusiness (solo home, se ci sono i dati minimi indirizzo)
  $street = trim((string) setting_get('seo_street', ''));
  $city   = trim((string) setting_get('seo_city', ''));
  if ($isHome && $street && $city) {
    $lb = [
      '@type' => 'LocalBusiness',
      '@id'   => $dom . '/#localbusiness',
      'name'  => $name,
      'url'   => $dom . '/',
      'image' => $og,
      'address' => array_filter([
        '@type'           => 'PostalAddress',
        'streetAddress'   => $street,
        'addressLocality' => $city,
        'postalCode'      => trim((string) setting_get('seo_postal', '')),
        'addressRegion'   => trim((string) setting_get('seo_province', '')),
        'addressCountry'  => trim((string) setting_get('seo_country', '')) ?: 'IT',
      ]),
    ];
    if ($phone) $lb['telephone'] = $phone;
    if ($email) $lb['email'] = $email;
    $lat = trim((string) setting_get('seo_geo_lat', ''));
    $lng = trim((string) setting_get('seo_geo_lng', ''));
    if ($lat !== '' && $lng !== '') $lb['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $lng];
    $hours = trim((string) setting_get('seo_hours', ''));
    if ($hours) $lb['openingHours'] = $hours;
    $graph[] = $lb;
  }

  // BreadcrumbList (pagine interne)
  if (!$isHome && $pageTitle !== '') {
    $p = cms_page($slug);
    $out = $p['out'] ?? ($slug . '.html');
    $graph[] = [
      '@type' => 'BreadcrumbList',
      'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $dom . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $pageTitle, 'item' => $dom . '/' . $out],
      ],
    ];
  }

  $data = ['@context' => 'https://schema.org', '@graph' => $graph];
  return '<script type="application/ld+json">'
       . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
       . '</script>';
}

// --- inietta il JSON-LD prima di </head> (usato da cms_render) ---
function seo_inject_jsonld($html, $slug, $pageTitle = '') {
  $ld = seo_jsonld($slug, $pageTitle);
  if ($ld === '' || stripos($html, '</head>') === false) return $html;
  return preg_replace('~</head>~i', $ld . '</head>', $html, 1);
}
