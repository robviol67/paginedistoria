<?php
// VelociBuilder LITE — engine CMS multi-pagina (manifest + token ⟦fN⟧ -> contenuti DB).
require_once __DIR__ . '/db.php';

// Password unica di bootstrap (usata da auth.php se non ci sono utenti nel DB)
if (!defined('CMS_ADMIN_PASS')) define('CMS_ADMIN_PASS', 'test');

function cms_manifest() {
  static $m = null;
  if ($m === null) {
    $f = __DIR__ . '/cms-pages.json';
    $m = is_file($f) ? (json_decode(file_get_contents($f), true) ?: ['pages' => []]) : ['pages' => []];
  }
  return $m;
}
function cms_pages() { return cms_manifest()['pages'] ?? []; }
function cms_page($slug) { $p = cms_pages(); return $p[$slug] ?? null; }

// Override salvati nel DB per una pagina (chiavi namespaced: "slug::fN")
function cms_overrides($slug) {
  $o = [];
  try {
    $st = db()->prepare('SELECT ckey, cvalue FROM cms_content WHERE ckey LIKE ?');
    $st->execute([$slug . '::%']);
    foreach ($st as $r) { $o[substr($r['ckey'], strlen($slug) + 2)] = $r['cvalue']; }
  } catch (Throwable $e) {}
  return $o;
}

// Valori correnti = default del manifest sovrascritti dagli override DB
function cms_values($slug) {
  $p = cms_page($slug); if (!$p) return [];
  $vals = [];
  foreach ($p['fields'] as $f) $vals[$f['key']] = $f['default'];
  foreach (cms_overrides($slug) as $k => $v) if (array_key_exists($k, $vals)) $vals[$k] = $v;
  return $vals;
}

function cms_save_page($slug, $data) {
  $p = cms_page($slug); if (!$p) return;
  $st = db()->prepare('INSERT INTO cms_content (ckey, cvalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE cvalue = VALUES(cvalue)');
  foreach ($p['fields'] as $f) {
    if (array_key_exists($f['key'], $data)) $st->execute([$slug . '::' . $f['key'], (string) $data[$f['key']]]);
  }
}

/**
 * Adattamento al telefono delle pagine nate da un Design.
 *
 * ⚠ PERCHÉ ESISTE. I Design producono griglie a colonne fisse (grid-template-columns:
 * 1fr 1fr) e schede con min-width pensato per il desktop. Gli elementi di una griglia
 * hanno min-width:auto: sotto una certa larghezza non si stringono, SFONDANO. Su
 * nuovosostenibile.it il modulo della pagina Contatti finiva a 422px da sinistra su un
 * viewport di 375 — fuori schermo, nascosto dall'overflow del contenitore, di fatto
 * irraggiungibile da telefono. Non era un caso isolato: è come si comportano tutte le
 * pagine generate da un Design, su ogni sito.
 *
 * Si aggancia agli stili in linea perché quel markup non ha classi su cui far presa:
 * riscriverlo vorrebbe dire toccare il convertitore e ricostruire ogni sito.
 *
 * ⚠ Attenzione a una trappola già pagata: azzerare il min-width e basta NON basta.
 * I figli di un flex, senza minimo, si stringono invece di andare a capo — tre schede
 * di confronto finivano affiancate da 110px l'una. Serve imporre la riga intera.
 */
function cms_css_mobile() {
  return "\n<style id=\"vb-mobile-fix\">\n"
    . "@media (max-width:760px){\n"
    . "  [style*=\"grid-template-columns:1fr 1fr\"],[style*=\"grid-template-columns:1.1fr 0.9fr\"]{grid-template-columns:1fr !important;gap:30px !important}\n"
    . "  [style*=\"display:grid\"] > *{min-width:0}\n"
    . "  [style*=\"flex-wrap:wrap\"] > [style*=\"min-width:\"]{flex:1 1 100% !important;min-width:0 !important}\n"
    . "  [style*=\"px 48px\"],[style*=\":0 48px\"]{padding-left:20px !important;padding-right:20px !important}\n"
    . "  [style*=\"padding:32px\"]{padding:22px !important}\n"
    . "  [style*=\"1.2fr 1fr 1fr 1fr\"],[style*=\"1.4fr 1fr 1fr 1fr\"]{font-size:12.5px !important;padding-left:14px !important;padding-right:14px !important}\n"
    . "  /* riquadri appoggiati fuori dal bordo: dentro. Le immagini no: sono decori "
    . "     voluti, e stanno gia dentro un contenitore che li ritaglia. */\n"
    . "  [style*=\"position:absolute\"][style*=\"left:-\"]:not(img){left:0 !important}\n"
    . "  #siteNav{padding-left:16px !important;padding-right:16px !important;gap:10px !important}\n"
    . "  h1{font-size:clamp(28px,8.4vw,44px) !important;line-height:1.12 !important}\n"
    . "  h2{font-size:clamp(23px,6.4vw,34px) !important;line-height:1.2 !important}\n"
    . "}\n</style>\n";
}

/** Inietta il CSS mobile prima di </head>. Idempotente: se c'è già, non lo ripete. */
function cms_inject_mobile_css($html) {
  if (strpos($html, 'vb-mobile-fix') !== false) return $html;
  $i = stripos($html, '</head>');
  return $i === false ? $html : substr($html, 0, $i) . cms_css_mobile() . substr($html, $i);
}

/**
 * Bolla «Chiedi all'assistente», in basso a destra su ogni pagina pubblicata.
 *
 * Compare SOLO se il sito ha davvero la pagina assistente.html: un motore che
 * mostra un pulsante verso una pagina inesistente è peggio che non mostrarlo.
 * Non compare sulla pagina dell'assistente stessa (la riconosce dal fatto che
 * parla con api/assistente/), né due volte sulla stessa pagina.
 *
 * Entra da sola dopo un secondo e mezzo — il tempo di leggere l'inizio della
 * pagina — e poi sta ferma: nessuna animazione perpetua. Chi ha «riduci
 * movimento» attivo la trova già ferma al suo posto.
 */
function cms_assistente_presente() {
  static $c = null;
  if ($c === null) $c = is_file(__DIR__ . '/../assistente.html');
  return $c;
}

function cms_html_assistente() {
  // ⚠ Lo stato nascosto NON va in linea: uno style inline batte le regole del
  // foglio, e la classe che dovrebbe mostrarla non riuscirebbe a farlo (errore
  // già commesso una volta: bolla presente nel DOM e invisibile a schermo).
  // Nascondere è compito del JS: senza JavaScript la bolla si vede subito.
  return "\n<a href=\"assistente.html\" id=\"vb-assistente-bolla\" aria-label=\"Chiedi all'assistente\""
    . " style=\"position:fixed;right:18px;bottom:18px;z-index:60;display:inline-flex;align-items:center;gap:10px;"
    . "padding:13px 20px 13px 15px;border-radius:100px;background:#1F7A3D;color:#fff;text-decoration:none;"
    . "font:600 14.5px 'Public Sans',system-ui,sans-serif;box-shadow:0 12px 30px -10px rgba(0,0,0,.45)\">"
    . "<svg width=\"21\" height=\"21\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\""
    . " stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\">"
    . "<path d=\"M20.4 12.2a8 8 0 0 1-8.6 8 8.6 8.6 0 0 1-3.6-.9L3.6 20.4l1.2-4.4a8 8 0 0 1-1-4 8 8 0 0 1 8-8 8 8 0 0 1 8.6 8.2z\"/>"
    . "<path d=\"M9.4 9.6a2.6 2.6 0 0 1 5 .9c0 1.7-2.5 2.2-2.5 3.6\"/><path d=\"M12 17.3h.01\"/></svg>"
    . "<span class=\"vb-ab-testo\">Chiedi all'assistente</span></a>\n"
    . "<style>#vb-assistente-bolla{transition:opacity .5s ease,transform .5s ease,box-shadow .25s ease}"
    . "#vb-assistente-bolla.vb-ab-attesa{opacity:0;transform:translateY(14px);pointer-events:none}"
    . "#vb-assistente-bolla:hover{box-shadow:0 16px 34px -10px rgba(0,0,0,.5)}"
    . "@media (max-width:520px){#vb-assistente-bolla{right:14px;bottom:14px;padding:14px}.vb-ab-testo{display:none}}"
    . "@media (prefers-reduced-motion:reduce){#vb-assistente-bolla{transition:none}}</style>\n"
    . "<script>(function(){var b=document.getElementById('vb-assistente-bolla');if(!b)return;"
    . "if(window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;"
    . "b.classList.add('vb-ab-attesa');"
    . "setTimeout(function(){b.classList.remove('vb-ab-attesa');},1500);})();</script>\n";
}

/** Aggiunge la bolla prima di </body>. Idempotente, e mai sulla pagina dell'assistente. */
function cms_inject_assistente($html) {
  if (!cms_assistente_presente()) return $html;
  if (strpos($html, 'vb-assistente-bolla') !== false) return $html;
  if (strpos($html, 'api/assistente/') !== false) return $html;
  $i = strripos($html, '</body>');
  return $i === false ? $html : substr($html, 0, $i) . cms_html_assistente() . substr($html, $i);
}

// Rende la pagina: sostituisce i token ⟦fN⟧ con i valori correnti
function cms_render($slug) {
  $p = cms_page($slug); if (!$p) return '';
  $tpl = @file_get_contents(__DIR__ . '/tpl/' . $slug . '.html');
  if ($tpl === false) return '';
  $vals = cms_values($slug);
  // Immagine OG di default globale (SEO & Condivisione): se impostata e la pagina
  // non ha un suo seo_image dedicato, la usa al posto del default del manifest.
  require_once __DIR__ . '/settings.php';
  $globalOg = trim((string) setting_get('seo_og_image', ''));
  if ($globalOg !== '' && isset($vals['seo_image'])) {
    $ov = cms_overrides($slug);
    if (!isset($ov['seo_image'])) $vals['seo_image'] = $globalOg;
  }
  $tpl = preg_replace_callback('/\x{27E6}([a-z0-9_]+)\x{27E7}/u', function ($mm) use ($vals) {
    // opzioni globali (social/maps/data-vb-opt): token ⟦opt_KEY⟧ -> valore dalle Opzioni
    if (strpos($mm[1], 'opt_') === 0) {
      require_once __DIR__ . '/options.php';
      return opt_get(substr($mm[1], 4));
    }
    if (!array_key_exists($mm[1], $vals)) return $mm[0];
    $v = $vals[$mm[1]];
    if ($mm[1] === 'seo_image' && $v !== '' && !preg_match('~^https?://~i', $v) && defined('SITE_DOMAIN')) {
      $v = rtrim(SITE_DOMAIN, '/') . '/' . ltrim($v, '/');
    }
    return $v;
  }, $tpl);
  // menu di navigazione dinamico (tutte le pagine) — con fallback su nav-seed.json
  if (strpos($tpl, '<!--VBNAV-->') !== false || strpos($tpl, '<!--VBNAVMOBILE-->') !== false) {
    require_once __DIR__ . '/nav.php';
    $tpl = str_replace('<!--VBNAV-->', nav_render_html(), $tpl);
    $tpl = str_replace('<!--VBNAVMOBILE-->', nav_render_mobile(), $tpl); // menu mobile a tendina
  }
  // sezione prodotti dinamica (pagina Prodotti)
  if (strpos($tpl, '<!--VBFAMILIES-->') !== false) {
    require_once __DIR__ . '/products.php';
    $tpl = str_replace('<!--VBFAMILIES-->', products_render_section(), $tpl);
  }
  // liste ripetibili dinamiche (fasi, statistiche, ...)
  if (strpos($tpl, '<!--VBLIST:') !== false) {
    require_once __DIR__ . '/lists.php';
    $tpl = preg_replace_callback('/<!--VBLIST:([a-z0-9_]+)-->/', function ($m) { return lists_render($m[1]); }, $tpl);
  }
  // moduli (form gestiti dal pannello) — <!--VBFORM:slug--> reso dal DB
  if (strpos($tpl, '<!--VBFORM:') !== false) {
    require_once __DIR__ . '/forms.php';
    $tpl = preg_replace_callback('/<!--VBFORM:([a-z0-9_]+)-->/', function ($m) {
      $f = function_exists('form_get_by_slug') ? form_get_by_slug($m[1]) : null;
      return $f ? render_form_html($f) : '<!-- modulo "' . $m[1] . '" non ancora creato (lancia migrate_forms) -->';
    }, $tpl);
  }
  // footer condiviso (tutte le pagine) — con fallback su footer-seed.json
  if (strpos($tpl, '<!--VBFOOTER-->') !== false) {
    require_once __DIR__ . '/footer.php';
    $tpl = str_replace('<!--VBFOOTER-->', footer_render_html(), $tpl);
  }
  // dati strutturati JSON-LD (SEO & Condivisione) iniettati prima di </head>
  require_once __DIR__ . '/seo.php';
  $tpl = seo_inject_jsonld($tpl, $slug, $vals['seo_title'] ?? ($p['title'] ?? ''));
  $tpl = cms_inject_mobile_css($tpl);   // vedi cms_css_mobile(): griglie dei Design
  $tpl = cms_inject_assistente($tpl);   // bolla «Chiedi all'assistente», se il sito ce l'ha
  return $tpl;
}

// Pubblica: scrive il file statico reale (con backup .bak)
function cms_publish($slug) {
  $p = cms_page($slug); if (!$p) throw new Exception('pagina non trovata');
  $html = cms_render($slug);
  if ($html === '') throw new Exception('template mancante per ' . $slug . ' — rilancia il build');
  $out = __DIR__ . '/../' . $p['out'];
  @copy($out, $out . '.bak');
  if (file_put_contents($out, $html) === false) throw new Exception('scrittura di ' . $p['out'] . ' non riuscita (permessi?)');
  // Seam per-sito: dopo che il pannello ha riscritto una pagina, il progetto
  // può rimettere a posto ciò che viene dal database (in Pagine di Storia le
  // zone dati della Home, che il modello del pannello non conosce).
  if (function_exists('pds_dopo_pubblicazione')) pds_dopo_pubblicazione($slug);
  return true;
}

// ── vestito per-sito ────────────────────────────────────────────────────────
if (is_file(__DIR__ . '/pds_home.php')) require_once __DIR__ . '/pds_home.php';
