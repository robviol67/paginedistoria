<?php
// VelociBuilder LITE — galleria fotografica riusabile: griglia responsive + lightbox (vanilla
// JS/CSS, nessuna libreria esterna). Usata dal blocco "galleria" del page-builder, dagli articoli
// blog e dai prodotti con più foto (vb_gallery_grid_html) — un solo lightbox condiviso per pagina,
// iniettato una volta (vb_gallery_assets) indipendentemente da quante gallerie ci sono.
if (!function_exists('pesc')) { function pesc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

// $images: array di ['image'=>path, 'alt'=>..., 'caption'=>...]. $opts: ['columns'=>2|3|4]
function vb_gallery_grid_html($images, array $opts = []) {
  $images = array_values(array_filter((array)$images, fn($im) => trim($im['image'] ?? '') !== ''));
  if (!$images) return '';
  static $gid = 0; $gid++;
  $cols = (int)($opts['columns'] ?? 3); if ($cols < 2 || $cols > 4) $cols = 3;
  $id = 'vbgal' . $gid;

  $tiles = '';
  foreach ($images as $i => $im) {
    $alt = trim($im['alt'] ?? '');
    $tiles .= '<button type="button" class="vb-gal-tile" data-i="' . $i . '">'
      . '<img src="' . pesc($im['image']) . '" alt="' . pesc($alt) . '" loading="lazy">'
      . '</button>';
  }
  $srcs = array_map(fn($im) => (string)$im['image'], $images);
  $alts = array_map(fn($im) => trim($im['alt'] ?? ''), $images);
  $caps = array_map(fn($im) => trim($im['caption'] ?? ''), $images);

  $out = '<div class="vb-gallery" id="' . $id . '" style="--vb-gal-cols:' . $cols . '"'
    . ' data-images="' . pesc(json_encode($srcs)) . '"'
    . ' data-alts="' . pesc(json_encode($alts, JSON_UNESCAPED_UNICODE)) . '"'
    . ' data-captions="' . pesc(json_encode($caps, JSON_UNESCAPED_UNICODE)) . '">'
    . $tiles . '</div>';

  return $out . vb_gallery_assets();
}

// Attributi data-* per un elemento "trigger" singolo (es. l'immagine di un prodotto) che apre
// la stessa lightbox condivisa senza rendere una griglia — vedi prod_card_html() in products.php.
function vb_gallery_trigger_attrs($images) {
  $images = array_values(array_filter((array)$images, fn($im) => trim($im['image'] ?? '') !== ''));
  if (!$images) return '';
  $srcs = array_map(fn($im) => (string)$im['image'], $images);
  $alts = array_map(fn($im) => trim($im['alt'] ?? ''), $images);
  $caps = array_map(fn($im) => trim($im['caption'] ?? ''), $images);
  return ' class="vb-gal-trigger" data-images="' . pesc(json_encode($srcs)) . '"'
    . ' data-alts="' . pesc(json_encode($alts, JSON_UNESCAPED_UNICODE)) . '"'
    . ' data-captions="' . pesc(json_encode($caps, JSON_UNESCAPED_UNICODE)) . '"';
}

function vb_gallery_assets() {
  static $done = false; if ($done) return ''; $done = true;
  return '<style>
.vb-gallery{display:grid;grid-template-columns:repeat(var(--vb-gal-cols,3),1fr);gap:12px}
.vb-gal-tile{border:none;padding:0;margin:0;background:none;cursor:pointer;border-radius:14px;overflow:hidden;aspect-ratio:4/3}
.vb-gal-tile img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s ease}
.vb-gal-tile:hover img{transform:scale(1.06)}
.vb-gal-trigger{cursor:pointer}
@media(max-width:720px){.vb-gallery{grid-template-columns:repeat(2,1fr)}}
#vbGalLightbox{display:none;position:fixed;inset:0;z-index:4000;background:rgba(10,14,8,.92);align-items:center;justify-content:center;padding:24px}
#vbGalLightbox.on{display:flex}
#vbGalLightbox img{max-width:min(90vw,1100px);max-height:78vh;object-fit:contain;border-radius:10px}
.vb-gal-lb-cap{color:#fff;text-align:center;font-size:14px;margin-top:14px;max-width:700px}
.vb-gal-lb-close,.vb-gal-lb-prev,.vb-gal-lb-next{position:absolute;background:rgba(255,255,255,.12);border:none;color:#fff;cursor:pointer;border-radius:50%;display:flex;align-items:center;justify-content:center}
.vb-gal-lb-close{top:20px;right:20px;width:40px;height:40px;font-size:20px}
.vb-gal-lb-prev,.vb-gal-lb-next{top:50%;transform:translateY(-50%);width:48px;height:48px;font-size:26px}
.vb-gal-lb-prev{left:16px}
.vb-gal-lb-next{right:16px}
</style>
<div id="vbGalLightbox">
  <button type="button" class="vb-gal-lb-close" aria-label="Chiudi">&#10005;</button>
  <button type="button" class="vb-gal-lb-prev" aria-label="Foto precedente">&#8249;</button>
  <div style="display:flex;flex-direction:column;align-items:center">
    <img src="" alt="">
    <div class="vb-gal-lb-cap"></div>
  </div>
  <button type="button" class="vb-gal-lb-next" aria-label="Foto successiva">&#8250;</button>
</div>
<script>
(function(){
  var lb = document.getElementById("vbGalLightbox");
  if (!lb || lb._vbInit) return; lb._vbInit = true;
  var img = lb.querySelector("img"), cap = lb.querySelector(".vb-gal-lb-cap");
  var srcs = [], alts = [], caps = [], idx = 0;
  function show(){ img.src = srcs[idx]; img.alt = alts[idx] || ""; cap.textContent = caps[idx] || ""; cap.style.display = caps[idx] ? "block" : "none"; }
  function open(list, i){
    srcs = list.images; alts = list.alts; caps = list.captions; idx = i; show(); lb.classList.add("on");
    var multi = srcs.length > 1;
    lb.querySelectorAll(".vb-gal-lb-prev,.vb-gal-lb-next").forEach(function(b){ b.style.display = multi ? "" : "none"; });
  }
  function close(){ lb.classList.remove("on"); }
  function nav(n){ idx = (idx + n + srcs.length) % srcs.length; show(); }
  lb.querySelector(".vb-gal-lb-close").addEventListener("click", close);
  lb.querySelector(".vb-gal-lb-prev").addEventListener("click", function(){ nav(-1); });
  lb.querySelector(".vb-gal-lb-next").addEventListener("click", function(){ nav(1); });
  lb.addEventListener("click", function(e){ if (e.target === lb) close(); });
  document.addEventListener("keydown", function(e){
    if (!lb.classList.contains("on")) return;
    if (e.key === "Escape") close();
    else if (e.key === "ArrowLeft") nav(-1);
    else if (e.key === "ArrowRight") nav(1);
  });
  document.querySelectorAll(".vb-gallery").forEach(function(box){
    var images, alts_, captions;
    try { images = JSON.parse(box.dataset.images || "[]"); } catch(e) { images = []; }
    try { alts_ = JSON.parse(box.dataset.alts || "[]"); } catch(e) { alts_ = []; }
    try { captions = JSON.parse(box.dataset.captions || "[]"); } catch(e) { captions = []; }
    box.querySelectorAll(".vb-gal-tile").forEach(function(tile){
      tile.addEventListener("click", function(){ open({images: images, alts: alts_, captions: captions}, parseInt(tile.dataset.i, 10) || 0); });
    });
  });
  document.querySelectorAll(".vb-gal-trigger").forEach(function(el){
    if (el._vbTrigInit) return; el._vbTrigInit = true;
    var images, alts_, captions;
    try { images = JSON.parse(el.dataset.images || "[]"); } catch(e) { images = []; }
    try { alts_ = JSON.parse(el.dataset.alts || "[]"); } catch(e) { alts_ = []; }
    try { captions = JSON.parse(el.dataset.captions || "[]"); } catch(e) { captions = []; }
    if (!images.length) return;
    el.addEventListener("click", function(){ open({images: images, alts: alts_, captions: captions}, 0); });
  });
})();
</script>';
}
