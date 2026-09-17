<?php
// VelociBuilder LITE — migration Gallerie: aggiunge la colonna gallery_images (JSON) ad articoli
// blog e prodotti, per allegare una galleria fotografica. Idempotente.
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../inc/db.php';
try {
  try { $a = db_add_col('cms_blog_posts', 'gallery_images', 'TEXT NULL'); echo ($a ? "OK  cms_blog_posts.gallery_images\n" : "SKIP cms_blog_posts.gallery_images (già presente)\n"); }
  catch (Throwable $e) { echo "cms_blog_posts.gallery_images: " . $e->getMessage() . "\n"; }

  try { $a = db_add_col('cms_products', 'gallery_images', 'TEXT NULL'); echo ($a ? "OK  cms_products.gallery_images\n" : "SKIP cms_products.gallery_images (già presente)\n"); }
  catch (Throwable $e) { echo "cms_products.gallery_images: " . $e->getMessage() . "\n"; }

  echo "\nFatto. Ora: /admin/blog.php e /admin/prodotti.php mostrano il campo Galleria fotografica.\n";
} catch (Throwable $e) { http_response_code(500); echo "ERRORE: " . $e->getMessage() . "\n"; }
