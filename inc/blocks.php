<?php
// VelociBuilder LITE — motore Blocchi: libreria di blocchi curati (stile "Elementor essenziale")
// per comporre pagine senza un Design sorgente. Ogni blocco ha uno schema di campi (come le Liste)
// e una funzione di rendering fedele allo stile del sito.
require_once __DIR__ . '/db.php';
if (!function_exists('pesc')) { function pesc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

const VB_ACCENT = '#1F7A3D';

/* ---------- Registro tipi di blocco ---------- */
function blocks_registry() {
  static $r = null;
  if ($r !== null) return $r;
  $r = [
    'hero' => [
      'label' => 'Hero (apertura)', 'icon' => 'ti-flag-3',
      'fields' => [
        ['key' => 'eyebrow',   'label' => 'Etichetta (sopra il titolo)', 'type' => 'text'],
        ['key' => 'title',     'label' => 'Titolo',                     'type' => 'text'],
        ['key' => 'subtitle',  'label' => 'Sottotitolo',                'type' => 'textarea'],
        ['key' => 'image',     'label' => 'Immagine',                   'type' => 'image'],
        ['key' => 'cta_label', 'label' => 'Testo pulsante',             'type' => 'text'],
        ['key' => 'cta_link',  'label' => 'Link pulsante',              'type' => 'text'],
      ],
      'render' => 'block_render_hero',
    ],
    'testo' => [
      'label' => 'Testo', 'icon' => 'ti-align-left',
      'fields' => [
        ['key' => 'title', 'label' => 'Titolo (facoltativo)', 'type' => 'text'],
        ['key' => 'body',  'label' => 'Testo',                'type' => 'textarea'],
      ],
      'render' => 'block_render_testo',
    ],
    'html' => [
      'label' => 'Testo libero (HTML)', 'icon' => 'ti-typography',
      'fields' => [
        ['key' => 'body', 'label' => 'Contenuto', 'type' => 'html'],
      ],
      'render' => 'block_render_html',
    ],
    'immagine' => [
      'label' => 'Immagine', 'icon' => 'ti-photo',
      'fields' => [
        ['key' => 'image', 'label' => 'Immagine', 'type' => 'image'],
        ['key' => 'alt',   'label' => 'Testo alternativo (alt)', 'type' => 'text'],
        ['key' => 'link',  'label' => 'Link (facoltativo)', 'type' => 'text'],
        ['key' => 'width', 'label' => 'Larghezza', 'type' => 'select', 'options' => ['normal' => 'Contenuta', 'pieno' => 'A tutta larghezza']],
      ],
      'render' => 'block_render_immagine',
    ],
    'testo_immagine' => [
      'label' => 'Testo + immagine', 'icon' => 'ti-layout-sidebar',
      'fields' => [
        ['key' => 'title',          'label' => 'Titolo',        'type' => 'text'],
        ['key' => 'body',           'label' => 'Testo',         'type' => 'textarea'],
        ['key' => 'image',          'label' => 'Immagine',      'type' => 'image'],
        ['key' => 'image_position', 'label' => 'Immagine a',    'type' => 'select', 'options' => ['destra' => 'Destra', 'sinistra' => 'Sinistra']],
        ['key' => 'cta_label',      'label' => 'Testo link',    'type' => 'text'],
        ['key' => 'cta_link',       'label' => 'Link',          'type' => 'text'],
      ],
      'render' => 'block_render_testo_immagine',
    ],
    'cta' => [
      'label' => 'Banner CTA', 'icon' => 'ti-click',
      'fields' => [
        ['key' => 'title',        'label' => 'Titolo',              'type' => 'text'],
        ['key' => 'subtitle',     'label' => 'Sottotitolo',          'type' => 'textarea'],
        ['key' => 'button_label', 'label' => 'Testo pulsante',       'type' => 'text'],
        ['key' => 'button_link',  'label' => 'Link pulsante',        'type' => 'text'],
        ['key' => 'bg_color',     'label' => 'Colore di sfondo',     'type' => 'color'],
      ],
      'render' => 'block_render_cta',
    ],
    'colonne' => [
      'label' => 'Colonne (caratteristiche)', 'icon' => 'ti-columns',
      'fields' => [
        ['key' => 'count',      'label' => 'Numero colonne', 'type' => 'select', 'options' => ['2' => '2', '3' => '3']],
        ['key' => 'col1_title', 'label' => 'Colonna 1 — titolo', 'type' => 'text'],
        ['key' => 'col1_text',  'label' => 'Colonna 1 — testo',  'type' => 'textarea'],
        ['key' => 'col2_title', 'label' => 'Colonna 2 — titolo', 'type' => 'text'],
        ['key' => 'col2_text',  'label' => 'Colonna 2 — testo',  'type' => 'textarea'],
        ['key' => 'col3_title', 'label' => 'Colonna 3 — titolo', 'type' => 'text'],
        ['key' => 'col3_text',  'label' => 'Colonna 3 — testo',  'type' => 'textarea'],
      ],
      'render' => 'block_render_colonne',
    ],
    'form' => [
      'label' => 'Modulo (form)', 'icon' => 'ti-forms',
      'fields' => [
        // 'dynamic'=>'forms': le opzioni si calcolano a runtime dall'elenco form (vedi admin/builder.php)
        ['key' => 'form_id', 'label' => 'Modulo da mostrare', 'type' => 'select', 'dynamic' => 'forms'],
      ],
      'render' => 'block_render_form',
    ],
    'slider' => [
      'label' => 'Slider (immagini + testo)', 'icon' => 'ti-carousel-horizontal',
      'fields' => [
        ['key' => 'slides', 'label' => 'Slide', 'type' => 'repeater', 'item_fields' => [
          ['key' => 'image',      'label' => 'Immagine',            'type' => 'image'],
          ['key' => 'eyebrow',    'label' => 'Etichetta (facolt.)', 'type' => 'text'],
          ['key' => 'title',      'label' => 'Titolo',              'type' => 'text'],
          ['key' => 'subtitle',   'label' => 'Sottotitolo',          'type' => 'textarea'],
          ['key' => 'cta_label',  'label' => 'Testo pulsante',       'type' => 'text'],
          ['key' => 'cta_link',   'label' => 'Link pulsante',        'type' => 'text'],
          ['key' => 'position',   'label' => 'Posizione testo',      'type' => 'select', 'options' => ['left' => 'Sinistra', 'center' => 'Centro', 'right' => 'Destra']],
        ]],
        ['key' => 'transition', 'label' => 'Transizione',      'type' => 'select', 'options' => ['fade' => 'Dissolvenza', 'slide' => 'Scorrimento']],
        ['key' => 'autoplay',   'label' => 'Avanzamento auto', 'type' => 'select', 'options' => ['1' => 'Sì', '0' => 'No']],
        ['key' => 'interval',   'label' => 'Intervallo (secondi)', 'type' => 'text'],
        ['key' => 'height',     'label' => 'Altezza',          'type' => 'select', 'options' => ['50vh' => 'Bassa', '70vh' => 'Media', '100vh' => 'Schermo intero']],
      ],
      'render' => 'block_render_slider',
    ],
    'galleria' => [
      'label' => 'Galleria fotografica', 'icon' => 'ti-photo-share',
      'fields' => [
        ['key' => 'images', 'label' => 'Foto', 'type' => 'repeater', 'item_fields' => [
          ['key' => 'image',   'label' => 'Immagine',              'type' => 'image'],
          ['key' => 'alt',     'label' => 'Testo alternativo',     'type' => 'text'],
          ['key' => 'caption', 'label' => 'Didascalia (facolt.)',  'type' => 'text'],
        ]],
        ['key' => 'columns', 'label' => 'Colonne', 'type' => 'select', 'options' => ['2' => '2', '3' => '3', '4' => '4']],
      ],
      'render' => 'block_render_galleria',
    ],
  ];
  return $r;
}
function block_def($type) { $r = blocks_registry(); return $r[$type] ?? null; }

/* ---------- CRUD elementi (istanze di blocco su una pagina) ---------- */
function blocks_table_ready() { try { db()->query('SELECT 1 FROM cms_page_blocks LIMIT 1'); return true; } catch (Throwable $e) { return false; } }
function blocks_of_page($pageId, $activeOnly = false) {
  try {
    $sql = 'SELECT * FROM cms_page_blocks WHERE page_id=?' . ($activeOnly ? ' AND active=1' : '') . ' ORDER BY sort, id';
    $st = db()->prepare($sql); $st->execute([(int)$pageId]);
    return array_map(function ($r) { $r['data'] = json_decode($r['data'], true) ?: []; return $r; }, $st->fetchAll());
  } catch (Throwable $e) { return []; }
}
function block_get($id) {
  $st = db()->prepare('SELECT * FROM cms_page_blocks WHERE id=?'); $st->execute([(int)$id]);
  $r = $st->fetch(); if ($r) $r['data'] = json_decode($r['data'], true) ?: []; return $r;
}
function block_add($pageId, $type) {
  if (!block_def($type)) throw new Exception('Tipo di blocco sconosciuto.');
  $mx = db()->prepare('SELECT COALESCE(MAX(sort),-1)+1 FROM cms_page_blocks WHERE page_id=?'); $mx->execute([(int)$pageId]); $sort = (int)$mx->fetchColumn();
  $st = db()->prepare('INSERT INTO cms_page_blocks (page_id, block_type, sort, data, active) VALUES (?,?,?,?,1)');
  $st->execute([(int)$pageId, $type, $sort, json_encode(new stdClass())]);
  return db()->lastInsertId();
}
function block_save_data($id, array $data, $active = 1) {
  $st = db()->prepare('UPDATE cms_page_blocks SET data=?, active=? WHERE id=?');
  $st->execute([json_encode($data, JSON_UNESCAPED_UNICODE), (int)$active, (int)$id]);
}
function block_delete($id) { $st = db()->prepare('DELETE FROM cms_page_blocks WHERE id=?'); $st->execute([(int)$id]); }
function blocks_reorder($ids) { $st = db()->prepare('UPDATE cms_page_blocks SET sort=? WHERE id=?'); foreach (array_values($ids) as $i => $id) $st->execute([$i, (int)$id]); }

/* ---------- Rendering della pagina (sequenza di blocchi attivi) ---------- */
function blocks_render_page($pageId) {
  $out = '';
  foreach (blocks_of_page($pageId, true) as $b) {
    $def = block_def($b['block_type']); if (!$def) continue;
    $fn = $def['render'];
    if (function_exists($fn)) $out .= $fn($b['data']);
  }
  return $out;
}

/* ---------- Renderer (uno stile coerente col sito: Poppins/Public Sans, verde #1F7A3D) ---------- */
function block_img($path, $alt, $style) {
  if (trim((string)$path) === '') return '';
  return '<img src="' . pesc($path) . '" alt="' . pesc($alt) . '" style="' . $style . '">';
}
// URL sicuro per un contesto CSS url('...') dentro style="": neutralizza apici, parentesi, spazi e
// a-capo che potrebbero terminare url() o iniettare regole. pesc() da solo NON basta: il browser
// decodifica le entità HTML prima di passare la stringa al parser CSS.
function block_css_url($path) {
  return str_replace(["'", '"', '(', ')', ' ', "\n", "\r", "\t", '\\'], ['%27', '%22', '%28', '%29', '%20', '', '', '', ''], (string)$path);
}
function block_paragraphs($text, $style) {
  $parts = preg_split('/\n\s*\n/', trim((string)$text));
  $out = '';
  foreach ($parts as $p) { $p = trim($p); if ($p === '') continue; $out .= '<p style="' . $style . '">' . nl2br(pesc($p)) . '</p>'; }
  return $out;
}

function block_render_hero($d) {
  $eyebrow = trim($d['eyebrow'] ?? ''); $title = trim($d['title'] ?? ''); $sub = trim($d['subtitle'] ?? '');
  $img = trim($d['image'] ?? ''); $ctaL = trim($d['cta_label'] ?? ''); $ctaH = trim($d['cta_link'] ?? '');
  $hasImg = $img !== '';
  $textCol = '<div>'
    . ($eyebrow !== '' ? '<div style="display:inline-block;padding:7px 16px;background:oklch(90% 0.05 152);color:oklch(34% 0.10 152);border-radius:100px;font-size:13px;font-weight:600;margin-bottom:18px">' . pesc($eyebrow) . '</div>' : '')
    . ($title !== '' ? '<h1 style="font-family:\'Poppins\',sans-serif;font-size:38px;line-height:1.15;font-weight:700;margin:0 0 18px">' . pesc($title) . '</h1>' : '')
    . ($sub !== '' ? '<p style="font-size:17px;line-height:1.65;color:oklch(45% 0.02 260);margin:0 0 26px;max-width:560px">' . nl2br(pesc($sub)) . '</p>' : '')
    . ($ctaL !== '' ? '<a href="' . pesc($ctaH ?: '#') . '" class="vb-navcta" style="display:inline-block;padding:15px 30px;background:' . VB_ACCENT . ';color:#fff;border-radius:100px;font-size:15px;font-weight:700;text-decoration:none">' . pesc($ctaL) . '</a>' : '')
    . '</div>';
  $imgCol = $hasImg ? '<div>' . block_img($img, $title, 'width:100%;height:auto;border-radius:20px;object-fit:cover') . '</div>' : '';
  $cols = $hasImg ? '1.05fr .95fr' : '1fr';
  return '<div style="max-width:1240px;margin:0 auto;padding:64px 48px;display:grid;grid-template-columns:' . $cols . ';gap:56px;align-items:center">' . $textCol . $imgCol . '</div>';
}
function block_render_testo($d) {
  $title = trim($d['title'] ?? ''); $body = trim($d['body'] ?? '');
  return '<div style="max-width:760px;margin:0 auto;padding:48px 32px">'
    . ($title !== '' ? '<h2 style="font-family:\'Poppins\',sans-serif;font-size:28px;font-weight:700;margin:0 0 18px">' . pesc($title) . '</h2>' : '')
    . block_paragraphs($body, 'font-size:16px;line-height:1.75;color:oklch(40% 0.02 260);margin:0 0 14px')
    . '</div>';
}
function block_render_testo_immagine($d) {
  $title = trim($d['title'] ?? ''); $body = trim($d['body'] ?? ''); $img = trim($d['image'] ?? '');
  $ctaL = trim($d['cta_label'] ?? ''); $ctaH = trim($d['cta_link'] ?? '');
  $imgRight = ($d['image_position'] ?? 'destra') !== 'sinistra';
  $textCol = '<div>'
    . ($title !== '' ? '<h2 style="font-family:\'Poppins\',sans-serif;font-size:26px;font-weight:700;margin:0 0 16px">' . pesc($title) . '</h2>' : '')
    . block_paragraphs($body, 'font-size:15.5px;line-height:1.7;color:oklch(45% 0.02 260);margin:0 0 12px')
    . ($ctaL !== '' ? '<a href="' . pesc($ctaH ?: '#') . '" style="display:inline-block;margin-top:8px;font-weight:600;color:' . VB_ACCENT . ';text-decoration:none;border-bottom:1.5px solid oklch(90% 0.05 152)">' . pesc($ctaL) . ' →</a>' : '')
    . '</div>';
  $imgCol = '<div>' . block_img($img, $title, 'width:100%;height:auto;border-radius:18px;object-fit:cover') . '</div>';
  $order = $imgRight ? $textCol . $imgCol : $imgCol . $textCol;
  return '<div style="max-width:1240px;margin:0 auto;padding:56px 48px;display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center">' . $order . '</div>';
}
function block_render_cta($d) {
  $bg = preg_match('/^#[0-9A-Fa-f]{6}$/', trim($d['bg_color'] ?? '')) ? $d['bg_color'] : VB_ACCENT;
  $title = trim($d['title'] ?? ''); $sub = trim($d['subtitle'] ?? '');
  $btnL = trim($d['button_label'] ?? ''); $btnH = trim($d['button_link'] ?? '');
  return '<div style="background:' . pesc($bg) . ';padding:64px 32px;text-align:center;color:#fff">'
    . ($title !== '' ? '<h2 style="font-family:\'Poppins\',sans-serif;font-size:28px;font-weight:700;margin:0 0 12px">' . pesc($title) . '</h2>' : '')
    . ($sub !== '' ? '<p style="font-size:16px;color:oklch(92% 0.02 152);margin:0 0 28px;max-width:560px;margin-left:auto;margin-right:auto">' . nl2br(pesc($sub)) . '</p>' : '')
    . ($btnL !== '' ? '<a href="' . pesc($btnH ?: '#') . '" style="display:inline-block;padding:15px 30px;background:#fff;color:' . pesc($bg) . ';border-radius:100px;font-size:15px;font-weight:700;text-decoration:none">' . pesc($btnL) . '</a>' : '')
    . '</div>';
}
function block_render_colonne($d) {
  $count = (int)($d['count'] ?? 3); if ($count < 2 || $count > 3) $count = 3;
  $cols = '';
  for ($i = 1; $i <= $count; $i++) {
    $t = trim($d['col' . $i . '_title'] ?? ''); $x = trim($d['col' . $i . '_text'] ?? '');
    if ($t === '' && $x === '') continue;
    $cols .= '<div style="flex:1;min-width:200px">'
      . ($t !== '' ? '<div style="font-family:\'Poppins\',sans-serif;font-size:19px;font-weight:700;margin-bottom:10px">' . pesc($t) . '</div>' : '')
      . ($x !== '' ? '<div style="font-size:14.5px;line-height:1.65;color:oklch(45% 0.02 260)">' . nl2br(pesc($x)) . '</div>' : '')
      . '</div>';
  }
  return '<div style="max-width:1240px;margin:0 auto;padding:56px 48px;display:flex;gap:40px;flex-wrap:wrap">' . $cols . '</div>';
}
function block_render_form($d) {
  $id = (int)($d['form_id'] ?? 0); if (!$id) return '';
  require_once __DIR__ . '/forms.php';
  $form = form_get($id);
  if (!$form || !(int)$form['active']) return '';
  return '<div style="max-width:900px;margin:0 auto;padding:8px 32px">'
    . '<div style="background:#fff;border:1px solid oklch(90% 0.01 260);border-radius:20px">' . render_form_html($form) . '</div></div>';
}
// Testo libero: contenuto HTML grezzo (dall'editor rich-text admin) — NON escaped, stesso
// livello di fiducia degli altri testi CMS (contenuto di un admin autenticato).
function block_render_html($d) {
  $body = trim($d['body'] ?? ''); if ($body === '') return '';
  return '<div class="vb-richtext" style="max-width:760px;margin:0 auto;padding:32px">' . $body . '</div>';
}
function block_render_immagine($d) {
  $img = trim($d['image'] ?? ''); if ($img === '') return '';
  $alt = trim($d['alt'] ?? ''); $link = trim($d['link'] ?? '');
  $full = ($d['width'] ?? 'normal') === 'pieno';
  $tag = '<img src="' . pesc($img) . '" alt="' . pesc($alt) . '" style="width:100%;height:auto;display:block' . ($full ? '' : ';border-radius:16px') . '">';
  if ($link !== '') $tag = '<a href="' . pesc($link) . '">' . $tag . '</a>';
  return $full ? '<div>' . $tag . '</div>' : '<div style="max-width:1240px;margin:0 auto;padding:24px 48px">' . $tag . '</div>';
}

/* ---------- Slider (immagini + testo in overlay + transizioni) ---------- */
function block_render_slider($d) {
  $slides = json_decode($d['slides'] ?? '[]', true) ?: [];
  $slides = array_values(array_filter($slides, fn($s) => trim($s['image'] ?? '') !== ''));
  if (!$slides) return '';
  $transition = ($d['transition'] ?? 'fade') === 'slide' ? 'slide' : 'fade';
  $autoplay = ($d['autoplay'] ?? '1') !== '0';
  $interval = max(2, (int)($d['interval'] ?? 5)) * 1000;
  $height = in_array($d['height'] ?? '70vh', ['50vh', '70vh', '100vh'], true) ? $d['height'] : '70vh';

  $slidesHtml = '';
  foreach ($slides as $i => $s) {
    $pos = in_array($s['position'] ?? 'left', ['left', 'center', 'right'], true) ? $s['position'] : 'left';
    $align = $pos === 'center' ? 'center' : ($pos === 'right' ? 'flex-end' : 'flex-start');
    $textAlign = $pos === 'center' ? 'center' : ($pos === 'right' ? 'right' : 'left');
    $eyebrow = trim($s['eyebrow'] ?? ''); $title = trim($s['title'] ?? ''); $sub = trim($s['subtitle'] ?? '');
    $ctaL = trim($s['cta_label'] ?? ''); $ctaH = trim($s['cta_link'] ?? '');
    $overlay = '<div style="max-width:560px;text-align:' . $textAlign . '">'
      . ($eyebrow !== '' ? '<div style="display:inline-block;padding:7px 16px;background:rgba(255,255,255,.18);color:#fff;border-radius:100px;font-size:13px;font-weight:600;margin-bottom:16px">' . pesc($eyebrow) . '</div>' : '')
      . ($title !== '' ? '<h2 style="font-family:\'Poppins\',sans-serif;font-size:34px;line-height:1.15;font-weight:700;margin:0 0 14px;color:#fff">' . pesc($title) . '</h2>' : '')
      . ($sub !== '' ? '<p style="font-size:16px;line-height:1.6;color:rgba(255,255,255,.92);margin:0 0 22px">' . nl2br(pesc($sub)) . '</p>' : '')
      . ($ctaL !== '' ? '<a href="' . pesc($ctaH ?: '#') . '" class="vb-navcta" style="display:inline-block;padding:14px 28px;background:' . VB_ACCENT . ';color:#fff;border-radius:100px;font-size:15px;font-weight:700;text-decoration:none">' . pesc($ctaL) . '</a>' : '')
      . '</div>';
    $slidesHtml .= '<div class="vb-slide' . ($i === 0 ? ' vb-slide-active' : '') . '" style="background-image:url(\'' . pesc(block_css_url($s['image'])) . '\')">'
      . '<div class="vb-slide-scrim"></div>'
      . '<div class="vb-slide-content" style="display:flex;align-items:flex-end;justify-content:' . $align . '">' . $overlay . '</div>'
      . '</div>';
  }

  $dots = '';
  foreach ($slides as $i => $s) $dots .= '<button type="button" class="vb-slider-dot' . ($i === 0 ? ' on' : '') . '" data-i="' . $i . '" aria-label="Vai alla slide ' . ($i + 1) . '"></button>';
  $multi = count($slides) > 1;

  $out = '<div class="vb-slider" data-transition="' . $transition . '" data-autoplay="' . ($autoplay ? '1' : '0') . '" data-interval="' . $interval . '" style="height:' . $height . '">'
    . '<div class="vb-slider-track">' . $slidesHtml . '</div>'
    . ($multi ? '<button type="button" class="vb-slider-arrow vb-slider-prev" aria-label="Slide precedente">&#8249;</button>'
      . '<button type="button" class="vb-slider-arrow vb-slider-next" aria-label="Slide successiva">&#8250;</button>'
      . '<div class="vb-slider-dots">' . $dots . '</div>' : '')
    . '</div>';

  return $out . block_slider_assets();
}
function block_slider_assets() {
  static $done = false; if ($done) return ''; $done = true;
  return '<style>
.vb-slider{position:relative;overflow:hidden;width:100%}
.vb-slider-track{position:relative;width:100%;height:100%}
.vb-slide{position:absolute;inset:0;background-size:cover;background-position:center;opacity:0;visibility:hidden;transition:opacity .8s ease}
.vb-slide-active{opacity:1;visibility:visible;z-index:1}
.vb-slider[data-transition="slide"] .vb-slide{opacity:1;visibility:visible;transition:transform .7s ease;transform:translateX(100%)}
.vb-slider[data-transition="slide"] .vb-slide.vb-slide-active{transform:translateX(0)}
.vb-slide-scrim{position:absolute;inset:0;background:linear-gradient(0deg,rgba(0,0,0,.55),rgba(0,0,0,.05) 55%)}
.vb-slide-content{position:relative;height:100%;padding:56px}
.vb-slider-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:2;width:42px;height:42px;border-radius:50%;border:none;background:rgba(255,255,255,.85);color:#1e2418;font-size:22px;line-height:1;cursor:pointer}
.vb-slider-prev{left:18px}
.vb-slider-next{right:18px}
.vb-slider-dots{position:absolute;bottom:18px;left:50%;transform:translateX(-50%);z-index:2;display:flex;gap:8px}
.vb-slider-dot{width:9px;height:9px;border-radius:50%;border:none;background:rgba(255,255,255,.5);cursor:pointer;padding:0}
.vb-slider-dot.on{background:#fff}
@media(max-width:720px){.vb-slide-content{padding:32px 24px}}
</style>
<script>
(function(){
  document.querySelectorAll(".vb-slider").forEach(function(box){
    if (box._vbInit) return; box._vbInit = true;
    var slides = [].slice.call(box.querySelectorAll(".vb-slide"));
    if (slides.length < 2) return;
    var dots = [].slice.call(box.querySelectorAll(".vb-slider-dot"));
    var idx = 0, timer = null, isSlide = box.dataset.transition === "slide";
    function go(n) {
      idx = (n + slides.length) % slides.length;
      slides.forEach(function(s, i){
        s.classList.toggle("vb-slide-active", i === idx);
        if (isSlide) s.style.transform = i === idx ? "translateX(0)" : (i < idx ? "translateX(-100%)" : "translateX(100%)");
      });
      dots.forEach(function(d, i){ d.classList.toggle("on", i === idx); });
    }
    box.querySelectorAll(".vb-slider-prev").forEach(function(b){ b.addEventListener("click", function(){ go(idx - 1); restart(); }); });
    box.querySelectorAll(".vb-slider-next").forEach(function(b){ b.addEventListener("click", function(){ go(idx + 1); restart(); }); });
    dots.forEach(function(d, i){ d.addEventListener("click", function(){ go(i); restart(); }); });
    function restart(){ if (timer) clearInterval(timer); if (box.dataset.autoplay === "1") timer = setInterval(function(){ go(idx + 1); }, parseInt(box.dataset.interval, 10) || 5000); }
    if (isSlide) slides.forEach(function(s, i){ s.style.transform = i === 0 ? "translateX(0)" : "translateX(100%)"; });
    restart();
  });
})();
</script>';
}

/* ---------- Galleria fotografica (blocco pagina) ---------- */
function block_render_galleria($d) {
  require_once __DIR__ . '/gallery.php';
  $images = json_decode($d['images'] ?? '[]', true) ?: [];
  $images = array_values(array_filter($images, fn($im) => trim($im['image'] ?? '') !== ''));
  if (!$images) return '';
  $cols = in_array((string)($d['columns'] ?? '3'), ['2', '3', '4'], true) ? (int)$d['columns'] : 3;
  return '<div style="max-width:1240px;margin:0 auto;padding:32px 48px">' . vb_gallery_grid_html($images, ['columns' => $cols]) . '</div>';
}
