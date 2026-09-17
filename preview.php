<?php
// Anteprima dinamica di una pagina (contenuti correnti dal DB, non ancora pubblicati) — VelociBuilder LITE.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/inc/cms.php';
$slug = preg_replace('/[^a-z0-9_-]/', '', $_GET['p'] ?? 'home');
$html = cms_render($slug);
if ($html === '') {
  http_response_code(404);
  echo 'Anteprima non disponibile per "' . htmlspecialchars($slug, ENT_QUOTES) . '". Se manca il template (inc/tpl/' . htmlspecialchars($slug, ENT_QUOTES) . '.html) rilancia il build.';
  exit;
}
// cache-buster lato client (alcuni host cachano gli URL .php)
$buster = '<script>if(!location.search.match(/[?&]v=/)){var u=new URL(location.href);u.searchParams.set("v",Date.now());location.replace(u.toString());}</script>';
$html = str_replace('</head>', $buster . '</head>', $html);
$banner = '<div style="position:fixed;bottom:14px;left:14px;z-index:9999;background:#111;color:#fff;font:600 12px system-ui;padding:8px 14px;border-radius:100px;opacity:.9">Anteprima CMS · <a href="admin/pagina.php?p=' . htmlspecialchars($slug, ENT_QUOTES) . '" style="color:#7cd992">modifica</a></div>';
$html = str_replace('</body>', $banner . '</body>', $html);
echo $html;
