<?php
// VelociBuilder LITE — modulo Prodotti: dati (CRUD) + rendering schede per la pagina Prodotti.
require_once __DIR__ . '/db.php';
if (!function_exists('pesc')) { function pesc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

/* ---------- Dati ---------- */
function prod_families() {
  try { return db()->query('SELECT * FROM cms_prod_families ORDER BY sort, id')->fetchAll(); }
  catch (Throwable $e) { return []; }
}
function prod_products($familyId = null) {
  try {
    if ($familyId !== null) { $st = db()->prepare('SELECT * FROM cms_products WHERE family_id = ? ORDER BY sort, id'); $st->execute([(int)$familyId]); return $st->fetchAll(); }
    return db()->query('SELECT * FROM cms_products ORDER BY family_id, sort, id')->fetchAll();
  } catch (Throwable $e) { return []; }
}
function prod_family_get($id) { $st = db()->prepare('SELECT * FROM cms_prod_families WHERE id=?'); $st->execute([(int)$id]); return $st->fetch(); }
function prod_product_get($id) { $st = db()->prepare('SELECT * FROM cms_products WHERE id=?'); $st->execute([(int)$id]); return $st->fetch(); }

function prod_family_save($id, $name, $tagline, $sort = 0) {
  if ($id) { $st = db()->prepare('UPDATE cms_prod_families SET name=?, tagline=?, sort=? WHERE id=?'); $st->execute([$name, $tagline, (int)$sort, (int)$id]); return (int)$id; }
  $st = db()->prepare('INSERT INTO cms_prod_families (name, tagline, sort) VALUES (?,?,?)'); $st->execute([$name, $tagline, (int)$sort]); return db()->lastInsertId();
}
function prod_family_delete($id) {
  $st = db()->prepare('DELETE FROM cms_products WHERE family_id=?'); $st->execute([(int)$id]);
  $st = db()->prepare('DELETE FROM cms_prod_families WHERE id=?'); $st->execute([(int)$id]);
}
// true se la colonna gallery_images esiste (migrate_galleries.php già lanciata) — evita di rompere
// il salvataggio dei prodotti se il deploy dei file precede la migration.
function prod_gallery_ready() {
  static $ok = null; if ($ok !== null) return $ok;
  try { db()->query('SELECT gallery_images FROM cms_products LIMIT 1'); $ok = true; } catch (Throwable $e) { $ok = false; }
  return $ok;
}
function prod_product_save($d) {
  $galleryReady = prod_gallery_ready();
  $cols = [$d['family_id'], $d['model'], $d['category'], $d['speed'], $d['cycle'], $d['bullets'], $d['image'], $d['brochure'] ?? ''];
  if ($galleryReady) $cols[] = trim($d['gallery_images'] ?? '');
  $cols = array_merge($cols, [(int)($d['sort'] ?? 0), (int)($d['active'] ?? 1)]);
  $galleryCol = $galleryReady ? ', gallery_images=?' : '';
  $galleryColIns = $galleryReady ? ', gallery_images' : '';
  $galleryPh = $galleryReady ? ',?' : '';
  if (!empty($d['id'])) {
    $st = db()->prepare('UPDATE cms_products SET family_id=?, model=?, category=?, speed=?, cycle=?, bullets=?, image=?, brochure=?' . $galleryCol . ', sort=?, active=? WHERE id=?');
    $st->execute(array_merge($cols, [(int)$d['id']])); return (int)$d['id'];
  }
  $st = db()->prepare('INSERT INTO cms_products (family_id, model, category, speed, cycle, bullets, image, brochure' . $galleryColIns . ', sort, active) VALUES (?,?,?,?,?,?,?,?' . $galleryPh . ',?,?)');
  $st->execute($cols); return db()->lastInsertId();
}
function prod_file_cleanup($path) { // rimuove un file caricato (solo dentro uploads/, MAI quelli della Media Library condivisa)
  if ($path && strpos($path, 'uploads/') === 0 && strpos($path, 'uploads/media/') !== 0) @unlink(__DIR__ . '/../' . $path);
}
// Riordino via drag&drop: riscrive la colonna sort seguendo l'ordine degli id ricevuti.
function prod_reorder_families($ids) {
  $st = db()->prepare('UPDATE cms_prod_families SET sort=? WHERE id=?');
  foreach (array_values($ids) as $i => $id) $st->execute([$i, (int)$id]);
}
function prod_reorder_products($ids) {
  $st = db()->prepare('UPDATE cms_products SET sort=? WHERE id=?');
  foreach (array_values($ids) as $i => $id) $st->execute([$i, (int)$id]);
}
function prod_product_delete($id) {
  $p = prod_product_get($id);
  if ($p) { prod_file_cleanup($p['image'] ?? ''); prod_file_cleanup($p['brochure'] ?? ''); }
  $st = db()->prepare('DELETE FROM cms_products WHERE id=?'); $st->execute([(int)$id]);
}

/* ---------- Rendering (fedele al design) ---------- */
/**
 * Dove porta «Richiedi scheda tecnica». Se il sito ha una pagina di richiesta
 * per prodotto, il modello viaggia con il link: chi riceve la richiesta sa già
 * di quale macchina si parla, invece di ricominciare da capo. Se quella pagina
 * non c'è, si torna al modulo contatti generico — nessun link rotto.
 */
function prod_link_richiesta($model) {
  static $c = null;
  if ($c === null) $c = is_file(__DIR__ . '/../richiesta.php');
  return $c ? 'richiesta.php?m=' . rawurlencode((string) $model) : 'contatti.html';
}

function prod_card_html($p) {
  $img = $p['image'] ?: 'assets/product-placeholder.png';
  $isPh = empty($p['image']);
  $speed = trim($p['speed'] ?? ''); $cycle = trim($p['cycle'] ?? '');
  $bullets = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $p['bullets'] ?? '')));
  $model = pesc($p['model']);

  $ph = $isPh ? '<span style="position:absolute;font-family:ui-monospace,\'IBM Plex Mono\',monospace;font-size:11px;color:oklch(45% 0.08 152);background:oklch(98% 0.008 95 / 0.85);padding:6px 12px;border-radius:6px">[ foto BiancoDigitale ' . $model . ' ]</span>' : '';

  $stats = '';
  if ($speed !== '') $stats .= '<div><div style="font-family:\'Poppins\',sans-serif;font-size:16px;font-weight:700;color:#1F7A3D">' . pesc($speed) . '</div><div style="font-size:11px;color:oklch(52% 0.02 260)">velocità</div></div>';
  if ($cycle !== '') $stats .= '<div><div style="font-family:\'Poppins\',sans-serif;font-size:16px;font-weight:700;color:#1F7A3D">' . pesc($cycle) . '</div><div style="font-size:11px;color:oklch(52% 0.02 260)">ciclo mensile</div></div>';
  $statsBlock = $stats !== '' ? '<div style="display:flex;gap:20px;padding:14px 0;border-top:1px solid oklch(93% 0.01 260);border-bottom:1px solid oklch(93% 0.01 260)">' . $stats . '</div>' : '';

  $blist = '';
  foreach ($bullets as $b) $blist .= '<div style="display:flex;gap:8px;font-size:13.5px;line-height:1.5;color:oklch(45% 0.02 260)"><span style="color:#1F7A3D;font-weight:700">＋</span><span>' . pesc($b) . '</span></div>';

  $brochure = trim($p['brochure'] ?? '');
  $brLink = $brochure !== '' ? '<a href="' . pesc($brochure) . '" target="_blank" rel="noopener" style="align-self:flex-start;font-size:13px;font-weight:600;color:oklch(45% 0.02 260);text-decoration:none;display:inline-flex;align-items:center;gap:5px">↓ Scarica la brochure (PDF)</a>' : '';

  // Galleria: se il prodotto ha foto multiple si apre quella; altrimenti, se c'è almeno la cover,
  // il click apre comunque una lightbox con la singola immagine (interazione coerente sempre).
  $galleryImages = [];
  if (prod_gallery_ready() && trim($p['gallery_images'] ?? '') !== '') $galleryImages = json_decode($p['gallery_images'], true) ?: [];
  if (!$galleryImages && !$isPh) $galleryImages = [['image' => $p['image'], 'alt' => 'BiancoDigitale ' . $model, 'caption' => '']];
  $trigAttrs = ''; $assets = '';
  if ($galleryImages) { require_once __DIR__ . '/gallery.php'; $trigAttrs = vb_gallery_trigger_attrs($galleryImages); $assets = vb_gallery_assets(); }

  return '<div class="vb-pcard" style="flex:1;min-width:300px;background:#fff;border:1px solid oklch(90% 0.01 260);border-radius:18px;overflow:hidden;display:flex;flex-direction:column">'
    . '<div' . $trigAttrs . ' style="aspect-ratio:16/10;background:#fff;display:flex;align-items:center;justify-content:center;padding:12px;position:relative"><img src="' . pesc($img) . '" alt="BiancoDigitale ' . $model . '" style="width:100%;height:100%;object-fit:contain">' . $ph . '</div>' . $assets
    . '<div style="padding:24px;display:flex;flex-direction:column;gap:14px;flex:1">'
    . '<div><div style="font-family:\'Poppins\',sans-serif;font-size:20px;font-weight:700">BiancoDigitale ' . $model . '</div><div style="font-size:13px;color:oklch(52% 0.02 260);margin-top:2px">' . pesc($p['category']) . '</div></div>'
    . $statsBlock
    . '<div style="display:flex;flex-direction:column;gap:8px;flex:1">' . $blist . '</div>'
    . '<a href="' . pesc(prod_link_richiesta($p['model'])) . '" class="vb-plink" style="align-self:flex-start;font-size:13.5px;font-weight:600;color:#1F7A3D;text-decoration:none;border-bottom:1.5px solid oklch(90% 0.05 152);padding-bottom:1px">Richiedi scheda tecnica →</a>'
    . $brLink
    . '</div></div>';
}
function prod_family_html($fam, $cardsHtml) {
  return '<div style="padding:24px 48px 64px"><div style="max-width:1240px;margin:0 auto">'
    . '<div style="animation:fadeUp .7s cubic-bezier(.2,.8,.2,1) both;display:flex;align-items:baseline;gap:16px;margin-bottom:28px;flex-wrap:wrap">'
    . '<h2 style="font-family:\'Poppins\',sans-serif;font-size:26px;font-weight:700;margin:0">' . pesc($fam['name']) . '</h2>'
    . '<span style="font-size:14px;color:oklch(52% 0.02 260)">' . pesc($fam['tagline']) . '</span></div>'
    . '<div style="display:flex;gap:24px;flex-wrap:wrap">' . $cardsHtml . '</div></div></div>';
}
// Riga sintetica per la vista "Lista"
function prod_list_row_html($p) {
  $model = pesc($p['model']);
  $cat = pesc($p['category'] ?? '');
  $speed = trim($p['speed'] ?? ''); $cycle = trim($p['cycle'] ?? '');
  $meta = trim($speed . ($speed !== '' && $cycle !== '' ? ' · ' : '') . $cycle);
  $img = $p['image'] ?: 'assets/product-placeholder.png';
  return '<div class="vb-lrow">'
    . '<img class="vb-lthumb" src="' . pesc($img) . '" alt="BiancoDigitale ' . $model . '" loading="lazy">'
    . '<div class="vb-lmain"><span class="vb-lmodel">BiancoDigitale ' . $model . '</span>' . ($cat !== '' ? '<span class="vb-lcat">' . $cat . '</span>' : '') . '</div>'
    . ($meta !== '' ? '<span class="vb-lmeta">' . pesc($meta) . '</span>' : '')
    . '<a href="' . pesc(prod_link_richiesta($p['model'])) . '" class="vb-llink">Scheda tecnica →</a>'
    . '</div>';
}

// CSS/JS della barra categorie + toggle vista (self-contained nel body)
const PRODUCTS_TOOLS_CSS = '<style>'
  . '.vb-ptools{max-width:1240px;margin:0 auto;padding:8px 48px 26px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}'
  . '.vb-cats{display:flex;gap:8px;flex-wrap:wrap}'
  . '.vb-cat{border:1px solid oklch(88% 0.01 260);background:#fff;color:oklch(35% 0.02 260);padding:8px 16px;border-radius:100px;font:600 13.5px "Public Sans",sans-serif;cursor:pointer;transition:all .18s ease;white-space:nowrap}'
  . '.vb-cat:hover{border-color:#1F7A3D;color:#1F7A3D}'
  . '.vb-cat.on{background:#1F7A3D;border-color:#1F7A3D;color:#fff}'
  . '.vb-views{display:flex;gap:3px;background:oklch(94% 0.008 260);border-radius:100px;padding:3px;flex-shrink:0}'
  . '.vb-view{border:none;background:transparent;color:oklch(45% 0.02 260);padding:7px 15px;border-radius:100px;font:600 13px "Public Sans",sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:6px}'
  . '.vb-view.on{background:#fff;color:#1F7A3D;box-shadow:0 1px 3px rgba(0,0,0,.12)}'
  . '.vb-fam[hidden],.vb-lgroup[hidden]{display:none}'
  . '.vb-listview{padding:0 48px 64px}.vb-listview-in{max-width:1240px;margin:0 auto}'
  . '.vb-lgroup{margin-bottom:34px}'
  . '.vb-lgroup h3{font-family:"Poppins",sans-serif;font-size:20px;font-weight:700;margin:0 0 6px;display:flex;align-items:baseline;gap:12px;flex-wrap:wrap}'
  . '.vb-lgroup h3 small{font-family:"Public Sans",sans-serif;font-size:13px;font-weight:400;color:oklch(52% 0.02 260)}'
  . '.vb-lrow{display:flex;align-items:center;gap:16px;padding:13px 6px;border-bottom:1px solid oklch(92% 0.01 260)}'
  . '.vb-lthumb{width:56px;height:44px;object-fit:contain;background:#fff;border:1px solid oklch(92% 0.01 260);border-radius:8px;flex-shrink:0;padding:3px}'
  . '.vb-lmain{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}'
  . '.vb-lmodel{font-family:"Poppins",sans-serif;font-size:15.5px;font-weight:700}'
  . '.vb-lcat{font-size:13px;color:oklch(52% 0.02 260)}'
  . '.vb-lmeta{font-size:13px;font-weight:600;color:#1F7A3D;white-space:nowrap}'
  . '.vb-llink{font-size:13.5px;font-weight:600;color:#1F7A3D;text-decoration:none;white-space:nowrap;border-bottom:1.5px solid oklch(90% 0.05 152);padding-bottom:1px}'
  . '@media(max-width:640px){.vb-ptools{padding:8px 20px 20px}.vb-listview{padding:0 20px 48px}.vb-lrow{flex-wrap:wrap;gap:8px}}'
  . '</style>';
const PRODUCTS_TOOLS_JS = '<script>(function(){'
  . 'var cats=document.querySelectorAll(".vb-cat"),fams=document.querySelectorAll(".vb-fam"),lg=document.querySelectorAll(".vb-lgroup");'
  . 'cats.forEach(function(b){b.addEventListener("click",function(){cats.forEach(function(x){x.classList.remove("on")});b.classList.add("on");var c=b.dataset.cat;'
  . 'function f(n){n.forEach(function(e){e.hidden=(c!=="all"&&e.dataset.fam!==c)})}f(fams);f(lg);})});'
  . 'var vs=document.querySelectorAll(".vb-view"),cv=document.querySelector(".vb-cardsview"),lv=document.querySelector(".vb-listview");'
  . 'vs.forEach(function(b){b.addEventListener("click",function(){vs.forEach(function(x){x.classList.remove("on")});b.classList.add("on");var v=b.dataset.view;if(cv)cv.hidden=(v!=="cards");if(lv)lv.hidden=(v!=="list");})});'
  . '})();</script>';

// HTML dell'intera sezione prodotti (usato da cms_render per sostituire <!--VBFAMILIES-->)
function products_render_section() {
  // raccoglie le famiglie con almeno un prodotto visibile
  $groups = [];
  foreach (prod_families() as $fam) {
    $prods = array_values(array_filter(prod_products($fam['id']), fn($p) => $p['active']));
    if ($prods) $groups[] = ['fam' => $fam, 'prods' => $prods];
  }
  if (!$groups) return '';

  // barra categorie + toggle vista
  $cats = '<button type="button" class="vb-cat on" data-cat="all">Tutte</button>';
  foreach ($groups as $g) $cats .= '<button type="button" class="vb-cat" data-cat="f' . $g['fam']['id'] . '">' . pesc($g['fam']['name']) . '</button>';
  $toolbar = '<div class="vb-ptools"><div class="vb-cats">' . $cats . '</div>'
    . '<div class="vb-views"><button type="button" class="vb-view on" data-view="cards">▦ Schede</button><button type="button" class="vb-view" data-view="list">☰ Lista</button></div></div>';

  // vista Schede (attuale), ogni famiglia avvolta per il filtro
  $cardsView = '<div class="vb-cardsview">';
  foreach ($groups as $g) {
    $cards = '';
    foreach ($g['prods'] as $p) $cards .= prod_card_html($p);
    $cardsView .= '<div class="vb-fam" data-fam="f' . $g['fam']['id'] . '">' . prod_family_html($g['fam'], $cards) . '</div>';
  }
  $cardsView .= '</div>';

  // vista Lista (sintetica)
  $listView = '<div class="vb-listview" hidden><div class="vb-listview-in">';
  foreach ($groups as $g) {
    $rows = '';
    foreach ($g['prods'] as $p) $rows .= prod_list_row_html($p);
    $tag = trim($g['fam']['tagline'] ?? '');
    $listView .= '<div class="vb-lgroup" data-fam="f' . $g['fam']['id'] . '"><h3>' . pesc($g['fam']['name']) . ($tag !== '' ? ' <small>' . pesc($tag) . '</small>' : '') . '</h3>' . $rows . '</div>';
  }
  $listView .= '</div></div>';

  return $toolbar . $cardsView . $listView . PRODUCTS_TOOLS_CSS . PRODUCTS_TOOLS_JS;
}
