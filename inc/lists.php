<?php
// VelociBuilder LITE — Liste ripetibili: elementi (CRUD) + rendering dal DB nel marker <!--VBLIST:KEY-->.
// Una "lista" può essere di tre tipi (campo `render` del manifest, seminato dal build):
//   - assente/'list' : l'itemTemplate del Design con i token ⟦field⟧ sostituiti (comportamento storico)
//   - 'slider'       : resa da block_render_slider() (slider immagini+overlay+transizioni)
//   - 'gallery'      : resa da block_render_galleria() (griglia + lightbox)
// Slider/galleria arrivano dal Design via data-vb-slider / data-vb-gallery (vedi build.js).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cms.php'; // per cms_manifest()
require_once __DIR__ . '/settings.php'; // per override config slider/galleria (cms_settings)
if (!function_exists('pesc')) { function pesc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

function lists_defs() { $m = cms_manifest(); return $m['lists'] ?? []; }
function lists_def($key) { $d = lists_defs(); return $d[$key] ?? null; }
function lists_table_ready() { try { db()->query('SELECT 1 FROM cms_list_items LIMIT 1'); return true; } catch (Throwable $e) { return false; } }

function list_items($key, $activeOnly = false) {
  try {
    $sql = 'SELECT * FROM cms_list_items WHERE list_key=?' . ($activeOnly ? ' AND active=1' : '') . ' ORDER BY sort, id';
    $st = db()->prepare($sql); $st->execute([$key]);
    return array_map(function ($r) { $r['data'] = json_decode($r['data'], true) ?: []; return $r; }, $st->fetchAll());
  } catch (Throwable $e) { return []; }
}
function list_item_get($id) {
  $st = db()->prepare('SELECT * FROM cms_list_items WHERE id=?'); $st->execute([(int)$id]);
  $r = $st->fetch(); if ($r) $r['data'] = json_decode($r['data'], true) ?: []; return $r;
}
function list_item_save($key, $id, array $data, $active = 1) {
  $json = json_encode($data, JSON_UNESCAPED_UNICODE);
  if ($id) { $st = db()->prepare('UPDATE cms_list_items SET data=?, active=? WHERE id=?'); $st->execute([$json, (int)$active, (int)$id]); return (int)$id; }
  $mx = db()->prepare('SELECT COALESCE(MAX(sort),-1)+1 FROM cms_list_items WHERE list_key=?'); $mx->execute([$key]); $sort = (int)$mx->fetchColumn();
  $st = db()->prepare('INSERT INTO cms_list_items (list_key, sort, data, active) VALUES (?,?,?,?)'); $st->execute([$key, $sort, $json, (int)$active]);
  return db()->lastInsertId();
}
function list_item_delete($id) { $st = db()->prepare('DELETE FROM cms_list_items WHERE id=?'); $st->execute([(int)$id]); }
function list_reorder($ids) { $st = db()->prepare('UPDATE cms_list_items SET sort=? WHERE id=?'); foreach (array_values($ids) as $i => $id) $st->execute([$i, (int)$id]); }
function list_count($key) { try { $st = db()->prepare('SELECT COUNT(*) FROM cms_list_items WHERE list_key=?'); $st->execute([$key]); return (int)$st->fetchColumn(); } catch (Throwable $e) { return 0; } }

// Config di una lista slider/galleria: default dal manifest (seminati dal Design) + eventuale
// override salvato dal pannello in cms_settings (chiave JSON "listcfg::KEY").
function lists_config($key, $def) {
  $cfg = $def['config'] ?? [];
  if (function_exists('setting_get')) {
    $raw = setting_get('listcfg::' . $key, '');
    if ($raw !== '') { $ov = json_decode($raw, true); if (is_array($ov)) $cfg = array_merge($cfg, $ov); }
  }
  return $cfg;
}

// Rende la lista secondo il tipo (`render`): slider/galleria coi renderer dei blocchi, altrimenti
// l'itemTemplate storico (⟦field⟧) coi valori degli elementi attivi del DB.
function lists_render($key) {
  $def = lists_def($key); if (!$def) return '';
  $render = $def['render'] ?? 'list';
  $items = list_items($key, true);

  if ($render === 'slider' || $render === 'gallery') {
    require_once __DIR__ . '/blocks.php'; // block_render_slider / block_render_galleria
    $rows = array_values(array_map(fn($it) => $it['data'], $items));
    $cfg = lists_config($key, $def);
    if ($render === 'slider') {
      return block_render_slider(array_merge($cfg, ['slides' => json_encode($rows, JSON_UNESCAPED_UNICODE)]));
    }
    return block_render_galleria(array_merge($cfg, ['images' => json_encode($rows, JSON_UNESCAPED_UNICODE)]));
  }

  $tpl = $def['itemTemplate'] ?? ''; if ($tpl === '') return '';
  $out = '';
  foreach ($items as $it) {
    $data = $it['data'];
    $out .= preg_replace_callback('/\x{27E6}([a-z0-9_]+)\x{27E7}/u', function ($m) use ($data) {
      return array_key_exists($m[1], $data) ? pesc($data[$m[1]]) : '';
    }, $tpl);
  }
  return $out;
}
