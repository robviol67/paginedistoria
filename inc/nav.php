<?php
// VelociBuilder LITE — Menu di navigazione editabile: dati (CRUD) + rendering del blocco link del nav.
// Supporta due modalità:
//  - PIATTA (default storico): una riga di <a>, stili built-in — retro-compat NuovoSostenibile.
//  - GERARCHICA (se esiste inc/nav-theme.json): menu a 2 livelli con pannelli a tendina, markup e
//    stili presi dal tema del Design (estratti al build). Il motore resta generico: il "look" è
//    tutto nel tema per-sito; il codice qui sotto assembla solo la struttura.
require_once __DIR__ . '/db.php';
if (!function_exists('pesc')) { function pesc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

function nav_has_col($col) {
  static $cols = null;
  if ($cols === null) {
    $cols = [];
    try { foreach (db()->query('SHOW COLUMNS FROM cms_nav') as $c) $cols[$c['Field']] = true; } catch (Throwable $e) {}
  }
  return isset($cols[$col]);
}

function nav_items($activeOnly = false) {
  try {
    $sql = 'SELECT * FROM cms_nav' . ($activeOnly ? ' WHERE active=1' : '') . ' ORDER BY sort, id';
    return db()->query($sql)->fetchAll();
  } catch (Throwable $e) { return []; }
}
function nav_item_get($id) { $st = db()->prepare('SELECT * FROM cms_nav WHERE id=?'); $st->execute([(int)$id]); return $st->fetch(); }
function nav_table_ready() { try { db()->query('SELECT 1 FROM cms_nav LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

function nav_item_save($d) {
  $hasHier = nav_has_col('parent_id');
  $label = trim($d['label']); $href = trim($d['href']); $sort = (int)($d['sort'] ?? 0);
  $isCta = (int)($d['is_cta'] ?? 0); $active = (int)($d['active'] ?? 1);
  $parent = ($d['parent_id'] ?? '') === '' ? null : (int)$d['parent_id'];
  $kind = ($d['kind'] ?? 'link') === 'header' ? 'header' : 'link';
  if (!empty($d['id'])) {
    if ($hasHier) {
      $st = db()->prepare('UPDATE cms_nav SET label=?, href=?, sort=?, is_cta=?, active=?, parent_id=?, kind=? WHERE id=?');
      $st->execute([$label, $href, $sort, $isCta, $active, $parent, $kind, (int)$d['id']]);
    } else {
      $st = db()->prepare('UPDATE cms_nav SET label=?, href=?, sort=?, is_cta=?, active=? WHERE id=?');
      $st->execute([$label, $href, $sort, $isCta, $active, (int)$d['id']]);
    }
    return (int)$d['id'];
  }
  if ($hasHier) {
    $st = db()->prepare('INSERT INTO cms_nav (label, href, sort, is_cta, active, parent_id, kind) VALUES (?,?,?,?,?,?,?)');
    $st->execute([$label, $href, $sort, $isCta, $active, $parent, $kind]);
  } else {
    $st = db()->prepare('INSERT INTO cms_nav (label, href, sort, is_cta, active) VALUES (?,?,?,?,?)');
    $st->execute([$label, $href, $sort, $isCta, $active]);
  }
  return db()->lastInsertId();
}
function nav_item_delete($id) {
  // elimina la voce e, se gerarchia attiva, i suoi figli (niente voci orfane)
  if (nav_has_col('parent_id')) { $st = db()->prepare('DELETE FROM cms_nav WHERE id=? OR parent_id=?'); $st->execute([(int)$id, (int)$id]); }
  else { $st = db()->prepare('DELETE FROM cms_nav WHERE id=?'); $st->execute([(int)$id]); }
}
function nav_reorder($ids) {
  $st = db()->prepare('UPDATE cms_nav SET sort=? WHERE id=?');
  foreach (array_values($ids) as $i => $id) $st->execute([$i, (int)$id]);
}

// Voci correnti: dal DB se disponibili, altrimenti fallback su inc/nav-seed.json (così il menu c'è anche pre-migration).
function nav_items_effective() {
  $items = nav_items(true);
  if ($items) return $items;
  $f = __DIR__ . '/nav-seed.json';
  if (is_file($f)) {
    $seed = json_decode(file_get_contents($f), true) ?: [];
    return array_map(fn($s) => [
      'id' => $s['id'] ?? null, 'parent_id' => $s['parent_id'] ?? null,
      'label' => $s['label'], 'href' => $s['href'] ?? '#',
      'is_cta' => (int)($s['is_cta'] ?? 0), 'kind' => $s['kind'] ?? 'link',
    ], $seed);
  }
  return [];
}

// Tema del menu (per-sito, estratto dal Design al build). Assente = modalità piatta.
function nav_theme() {
  static $t = null;
  if ($t === null) {
    $f = __DIR__ . '/nav-theme.json';
    $t = is_file($f) ? (json_decode(file_get_contents($f), true) ?: false) : false;
  }
  return $t;
}

// Costruisce [voci di primo livello, figli per id-padre] dalle voci effettive.
function nav_tree() {
  $items = nav_items_effective();
  $top = []; $children = [];
  foreach ($items as $it) {
    $pid = $it['parent_id'] ?? null;
    if ($pid) $children[$pid][] = $it; else $top[] = $it;
  }
  return [$top, $children];
}

// Rendering del contenitore link del nav (sostituisce <!--VBNAV--> in cms_render).
function nav_render_html() {
  $theme = nav_theme();
  if ($theme && !empty($theme['desktop'])) return nav_render_desktop_themed($theme['desktop']);

  // --- Modalità piatta (default storico) ---
  $normal = 'font-size:14px;font-weight:500;color:oklch(24% 0.015 260);text-decoration:none;white-space:nowrap';
  $cta = 'padding:11px 22px;background:#1F7A3D;color:#fff;border-radius:100px;font-size:14px;font-weight:600;text-decoration:none;white-space:nowrap;flex-shrink:0';
  $out = '<div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;justify-content:flex-end">';
  foreach (nav_items_effective() as $it) {
    if ((int)($it['is_cta'] ?? 0) === 1) $out .= '<a href="' . pesc($it['href']) . '" class="vb-navcta" style="' . $cta . '">' . pesc($it['label']) . '</a>';
    else $out .= '<a href="' . pesc($it['href']) . '" style="' . $normal . '">' . pesc($it['label']) . '</a>';
  }
  return $out . '</div>';
}

// Desktop a tendina, guidato dal tema. Struttura fissa (generica), stili/classi dal tema.
function nav_render_desktop_themed($d) {
  [$top, $children] = nav_tree();
  $s = fn($k, $def = '') => $d[$k] ?? $def;
  $out = $s('container_open');
  foreach ($top as $it) {
    $id = $it['id'] ?? null;
    $kids = ($id !== null && !empty($children[$id])) ? $children[$id] : [];
    $label = pesc($it['label']); $href = pesc($it['href'] ?? '#');
    if ($kids) {
      $out .= $s('item_open');
      $out .= '<a href="' . $href . '" class="' . pesc($s('top_class')) . '" style="' . $s('top_style') . '">' . $label . $s('caret') . '</a>';
      $out .= $s('panel_open');
      foreach ($kids as $k) {
        if (($k['kind'] ?? 'link') === 'header') $out .= '<div style="' . $s('header_style') . '">' . pesc($k['label']) . '</div>';
        else $out .= '<a href="' . pesc($k['href'] ?? '#') . '" style="' . $s('sublink_style') . '">' . pesc($k['label']) . '</a>';
      }
      $out .= $s('panel_close', '</div>') . $s('item_close', '</div>');
    } else {
      // voce piatta di primo livello (o CTA se marcata)
      if ((int)($it['is_cta'] ?? 0) === 1 && $s('cta_style')) {
        $out .= '<a href="' . $href . '" class="' . pesc($s('cta_class')) . '" style="' . $s('cta_style') . '">' . $label . '</a>';
      } else {
        $out .= '<a href="' . $href . '" class="' . pesc($s('flat_class')) . '" style="' . $s('flat_style') . '">' . $label . '</a>';
      }
    }
  }
  return $out . $s('container_close', '</nav>');
}

// Menu mobile (sostituisce <!--VBNAVMOBILE-->): lista piatta, voci di primo livello in evidenza,
// figli indentati sotto ciascun padre. Stili dal tema (mobile). Assente/senza tema = stringa vuota.
function nav_render_mobile() {
  $theme = nav_theme();
  if (!$theme || empty($theme['mobile'])) return '';
  $m = $theme['mobile'];
  [$top, $children] = nav_tree();
  $topStyle = $m['top_style'] ?? ''; $subStyle = $m['sub_style'] ?? '';
  $out = ($m['wrap_open'] ?? '');
  foreach ($top as $it) {
    $out .= '<a href="' . pesc($it['href'] ?? '#') . '" style="' . $topStyle . '">' . pesc($it['label']) . '</a>';
    $id = $it['id'] ?? null;
    if ($id !== null && !empty($children[$id])) foreach ($children[$id] as $k) {
      if (($k['kind'] ?? 'link') === 'header') continue; // le intestazioni pannello non servono in mobile
      $out .= '<a href="' . pesc($k['href'] ?? '#') . '" style="' . $subStyle . '">' . pesc($k['label']) . '</a>';
    }
  }
  return $out . ($m['wrap_close'] ?? '');
}

// Barra INTERA (contenitore #siteNav + brand a sinistra + link dinamici): per le pagine SENZA Design
// sorgente (page-builder, blog), che non hanno un template da cui ereditare la barra.
function nav_render_bar() {
  static $shell = null;
  if ($shell === null) {
    $f = __DIR__ . '/nav-shell-seed.html';
    $shell = is_file($f) ? file_get_contents($f) : '';
  }
  if ($shell === '') return '';
  $out = str_replace('{{VBNAVLINKS}}', nav_render_html(), $shell);
  if (strpos($out, '<!--VBNAVMOBILE-->') !== false) $out = str_replace('<!--VBNAVMOBILE-->', nav_render_mobile(), $out);
  return $out;
}
