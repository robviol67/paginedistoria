<?php
// VelociBuilder LITE — picker riusabile della Media Library.
// Uso: include una volta nella pagina admin, poi da un bottone: onclick="VBpickMedia(function(path,alt){ ... })"
require_once __DIR__ . '/media.php';

function nc_media_picker() {
  static $done = false; if ($done) return; $done = true;
  $items = media_all();
  ?>
  <div id="vbMediaModal" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(20,26,18,.55);align-items:center;justify-content:center;padding:24px">
    <div style="background:#fff;border-radius:16px;max-width:820px;width:100%;max-height:82vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.3)">
      <div style="display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid #e3e7de">
        <strong style="font-family:'Poppins',sans-serif;font-size:16px">Libreria media</strong>
        <div style="position:relative;flex:1">
          <input id="vbMediaSearch" type="text" placeholder="Cerca per nome…" style="padding:8px 12px 8px 34px;border:1px solid #d5dacf;border-radius:8px;width:100%;font:14px 'Public Sans',sans-serif">
          <i class="ti ti-search" style="position:absolute;left:11px;top:9px;color:#8a9184"></i>
        </div>
        <a class="btn sm ghost" href="media.php" target="_blank" title="Carica nuove immagini"><i class="ti ti-upload"></i></a>
        <button type="button" class="btn sm ghost" onclick="VBcloseMedia()"><i class="ti ti-x"></i></button>
      </div>
      <div id="vbMediaGrid" style="padding:16px 20px;overflow:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px">
        <?php if (!$items): ?>
          <div style="grid-column:1/-1;text-align:center;color:#8a9184;padding:30px">
            Nessuna immagine in libreria. <a class="lnk" href="media.php" target="_blank">Caricane una →</a>
          </div>
        <?php endif; ?>
        <?php foreach ($items as $m): $lbl = $m['alt'] ?: $m['original']; ?>
          <button type="button" class="vb-mtile" data-path="<?= pesc($m['path']) ?>" data-alt="<?= pesc($m['alt']) ?>" data-search="<?= pesc(mb_strtolower(($m['original'] ?? '') . ' ' . ($m['alt'] ?? ''))) ?>"
            style="border:1px solid #e3e7de;border-radius:10px;background:#fff;padding:0;cursor:pointer;overflow:hidden;text-align:left">
            <div style="aspect-ratio:4/3;display:flex;align-items:center;justify-content:center;background:#f4f6f3"><img src="../<?= pesc($m['path']) ?>" alt="" style="max-width:100%;max-height:100%;object-fit:contain"></div>
            <div style="padding:6px 8px;font-size:11px;color:#57604f;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= pesc($lbl) ?></div>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <script>
  (function(){
    var cb=null, modal=document.getElementById('vbMediaModal');
    window.VBpickMedia=function(callback){ cb=callback; modal.style.display='flex'; var s=document.getElementById('vbMediaSearch'); s.value=''; s.dispatchEvent(new Event('input')); s.focus(); };
    window.VBcloseMedia=function(){ modal.style.display='none'; cb=null; };
    modal.addEventListener('click',function(e){ if(e.target===modal) VBcloseMedia(); });
    document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&modal.style.display==='flex') VBcloseMedia(); });
    document.querySelectorAll('.vb-mtile').forEach(function(t){
      t.addEventListener('click',function(){ if(cb) cb(t.dataset.path, t.dataset.alt||''); VBcloseMedia(); });
    });
    var srch=document.getElementById('vbMediaSearch');
    srch.addEventListener('input',function(){ var q=srch.value.toLowerCase().trim();
      document.querySelectorAll('.vb-mtile').forEach(function(t){ t.style.display=(!q||t.dataset.search.indexOf(q)>-1)?'':'none'; }); });
  })();
  </script>
  <?php
}
