<?php
// VelociBuilder LITE — anteprima live di una pagina libera (stato corrente del DB, anche non pubblicato).
// Usata nella tab "Anteprima" di admin/builder.php (dentro un <iframe>).
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/pagebuilder.php';

$page = pb_page_get((int)($_GET['page'] ?? 0));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (!$page) { http_response_code(404); echo 'Pagina non trovata.'; exit; }
echo pb_render_doc($page);
