<?php
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/media.php';

// Editor immagini: salva la modifica come nuova copia OPPURE sovrascrive l'originale (fetch) -> JSON.
if (($_POST['action'] ?? '') === 'save_edited') {
  header('Content-Type: application/json');
  try {
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) throw new Exception('Nessun file ricevuto.');
    if (($_POST['mode'] ?? 'copy') === 'overwrite') {
      $path = media_overwrite(trim($_POST['target'] ?? ''), $_FILES['file']);
    } else {
      $path = media_store($_FILES['file'], trim($_POST['alt'] ?? ''));
    }
    echo json_encode(['ok' => true, 'path' => $path]);
  } catch (Throwable $e) { http_response_code(400); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
  exit;
}

$msg = ''; $msgType = 'ok';
$act = $_POST['action'] ?? '';
try {
  if ($act === 'upload') {
    if (empty($_FILES['files']['name'][0])) throw new Exception('Seleziona almeno un file.');
    $n = 0; $errs = [];
    foreach ($_FILES['files']['name'] as $i => $name) {
      if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
      $one = ['name' => $name, 'type' => $_FILES['files']['type'][$i], 'tmp_name' => $_FILES['files']['tmp_name'][$i],
              'size' => $_FILES['files']['size'][$i], 'error' => $_FILES['files']['error'][$i]];
      try { media_store($one); $n++; } catch (Throwable $e) { $errs[] = $name . ': ' . $e->getMessage(); }
    }
    $msg = "$n immagine/i caricata/e ✓" . ($errs ? ' — ' . implode('; ', $errs) : '');
    if ($n === 0 && $errs) $msgType = 'err';
  } elseif ($act === 'delete') {
    media_delete($_POST['id']); $msg = 'Immagine eliminata.';
  } elseif ($act === 'alt') {
    media_set_alt($_POST['id'], $_POST['alt'] ?? ''); $msg = 'Descrizione salvata ✓';
  }
} catch (Throwable $e) { $msgType = 'err'; $msg = $e->getMessage(); }

$ready = media_table_ready();
$items = $ready ? media_all() : [];

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('media', 'Media — VelociBuilder LITE');
?>
<div class="hd">
  <div><h1>Media</h1><p class="sub">Libreria immagini riusabile in pagine e prodotti</p></div>
</div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>
<?php if (!$ready): ?>
  <div class="msg err">Tabella non trovata. Lancia prima: <a class="lnk" href="../api/migrate_media.php" target="_blank">/api/migrate_media.php</a></div>
<?php else: ?>

<form method="post" enctype="multipart/form-data" class="card" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
  <input type="hidden" name="action" value="upload">
  <div style="flex:1;min-width:240px">
    <label style="margin-bottom:6px">Carica immagini <span style="color:#8a9184;font-weight:400">(PNG, JPG, WebP, GIF, SVG — anche più di una)</span></label>
    <input type="file" name="files[]" accept="image/*" multiple required>
  </div>
  <button class="btn" type="submit"><i class="ti ti-upload"></i> Carica</button>
</form>

<div class="card">
  <div class="sec" style="margin-top:0"><i class="ti ti-photo" style="vertical-align:-2px"></i> In libreria <span style="color:#8a9184;font-weight:400;font-size:13px">(<?= count($items) ?>)</span></div>
  <?php if (!$items): ?>
    <p style="color:#8a9184;text-align:center;padding:24px">Nessuna immagine caricata. Usa il riquadro qui sopra.</p>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px">
    <?php foreach ($items as $m): ?>
      <div style="border:1px solid #e3e7de;border-radius:12px;overflow:hidden;display:flex;flex-direction:column">
        <div style="aspect-ratio:4/3;display:flex;align-items:center;justify-content:center;background:#f4f6f3"><img src="../<?= h($m['path']) ?>?v=<?= (int)$m['bytes'] ?>" alt="" style="max-width:100%;max-height:100%;object-fit:contain"></div>
        <div style="padding:10px;display:flex;flex-direction:column;gap:8px">
          <div style="font-size:11px;color:#8a9184;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= h($m['original']) ?>"><?= h($m['original']) ?> · <?= $m['bytes'] ? round($m['bytes']/1024) . ' KB' : '' ?></div>
          <form method="post" style="display:flex;gap:6px">
            <input type="hidden" name="action" value="alt"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <input type="text" name="alt" value="<?= h($m['alt']) ?>" placeholder="descrizione (alt)" style="padding:6px 9px;font-size:12px">
            <button class="btn sm ghost" type="submit" title="salva descrizione"><i class="ti ti-check"></i></button>
          </form>
          <div style="display:flex;gap:6px;align-items:center">
            <input type="text" readonly value="<?= h($m['path']) ?>" onclick="this.select()" style="padding:5px 8px;font-size:11px;color:#57604f;background:#f4f6f3;border-color:#e3e7de" title="percorso — clic per selezionare">
            <button type="button" class="btn sm ghost" title="modifica immagine" onclick="VBedit('<?= h(addslashes($m['path'])) ?>')"><i class="ti ti-adjustments"></i></button>
            <form method="post" style="margin:0" onsubmit="return confirm('Eliminare questa immagine? Le pagine che la usano mostreranno un\'immagine mancante.')">
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <button class="btn sm danger" type="submit" title="elimina"><i class="ti ti-trash"></i></button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ===== Editor immagini (client-side, salva come nuova copia) ===== -->
<style>
#vbEditor{display:none;position:fixed;inset:0;z-index:2500;background:rgba(20,26,18,.6);align-items:center;justify-content:center;padding:16px}
.ed-panel{background:#fff;border-radius:16px;max-width:940px;width:100%;max-height:92vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35)}
.ed-hd{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:1px solid #e3e7de}
.ed-hd b{font-family:"Poppins",sans-serif;font-size:16px}
.ed-body{display:flex;flex:1;min-height:0}
.ed-stage{flex:1;min-width:0;background:#2b322a;display:flex;align-items:center;justify-content:center;padding:16px;overflow:auto}
#edCanvasWrap{position:relative;display:inline-block;line-height:0;touch-action:none}
#edCanvas{display:block;max-width:100%}
#edCrop{position:absolute;border:2px solid #fff;box-shadow:0 0 0 9999px rgba(0,0,0,.4);cursor:move;box-sizing:border-box}
.ed-h{position:absolute;width:14px;height:14px;background:#fff;border:1px solid #1F7A3D;border-radius:50%}
.ed-h.nw{left:-8px;top:-8px;cursor:nwse-resize}.ed-h.ne{right:-8px;top:-8px;cursor:nesw-resize}
.ed-h.sw{left:-8px;bottom:-8px;cursor:nesw-resize}.ed-h.se{right:-8px;bottom:-8px;cursor:nwse-resize}
.ed-side{width:270px;flex-shrink:0;border-left:1px solid #e3e7de;padding:16px;overflow:auto}
.ed-grp{margin-bottom:15px}
.ed-grp>label{display:block;font-size:11.5px;font-weight:700;color:#57604f;text-transform:uppercase;letter-spacing:.02em;margin-bottom:7px}
.ed-btns{display:flex;gap:6px;flex-wrap:wrap}
.ed-chip{border:1px solid #d5dacf;background:#fff;border-radius:8px;padding:7px 11px;font-size:13px;cursor:pointer;font-family:inherit;color:#1e2418;display:inline-flex;align-items:center;gap:5px}
.ed-chip:hover{border-color:#1F7A3D;color:#1F7A3D}.ed-chip.on{background:#e7f4ea;border-color:#1F7A3D;color:#1F7A3D}
.ed-row{display:flex;align-items:center;gap:9px;font-size:12.5px;margin-bottom:9px}
.ed-row>span:first-child{width:74px;color:#57604f}
.ed-row input[type=range]{flex:1;padding:0;min-width:0}
.ed-row .val{width:34px;text-align:right;color:#8a9184;font-variant-numeric:tabular-nums}
.ed-sel{width:100%;padding:8px 10px;border:1px solid #d5dacf;border-radius:8px;font:13px "Public Sans",sans-serif}
.ed-ft{display:flex;justify-content:space-between;gap:10px;padding:12px 18px;border-top:1px solid #e3e7de;align-items:center}
.ed-note{font-size:11.5px;color:#8a9184}
@media(max-width:720px){.ed-body{flex-direction:column}.ed-side{width:auto;border-left:none;border-top:1px solid #e3e7de}}
</style>
<div id="vbEditor">
  <div class="ed-panel">
    <div class="ed-hd"><b>Modifica immagine</b><button type="button" class="btn sm ghost" onclick="VBcloseEditor()"><i class="ti ti-x"></i></button></div>
    <div class="ed-body">
      <div class="ed-stage"><div id="edCanvasWrap"><canvas id="edCanvas"></canvas>
        <div id="edCrop"><span class="ed-h nw" data-h="nw"></span><span class="ed-h ne" data-h="ne"></span><span class="ed-h sw" data-h="sw"></span><span class="ed-h se" data-h="se"></span></div>
      </div></div>
      <div class="ed-side">
        <div class="ed-grp"><label>Rotazione e specchio</label><div class="ed-btns">
          <button type="button" class="ed-chip" onclick="ED.rotate(-90)"><i class="ti ti-rotate-2"></i></button>
          <button type="button" class="ed-chip" onclick="ED.rotate(90)"><i class="ti ti-rotate-clockwise-2"></i></button>
          <button type="button" class="ed-chip" onclick="ED.flip('h')"><i class="ti ti-flip-horizontal"></i></button>
          <button type="button" class="ed-chip" onclick="ED.flip('v')"><i class="ti ti-flip-vertical"></i></button>
        </div></div>
        <div class="ed-grp"><label>Ritaglio (proporzioni)</label><div class="ed-btns">
          <button type="button" class="ed-chip on" data-asp="0" onclick="ED.aspect(0,this)">Libero</button>
          <button type="button" class="ed-chip" data-asp="1" onclick="ED.aspect(1,this)">1:1</button>
          <button type="button" class="ed-chip" data-asp="1.6" onclick="ED.aspect(1.6,this)">16:10</button>
          <button type="button" class="ed-chip" data-asp="1.3333" onclick="ED.aspect(1.3333,this)">4:3</button>
          <button type="button" class="ed-chip" onclick="ED.resetCrop()"><i class="ti ti-maximize"></i></button>
        </div></div>
        <div class="ed-grp"><label>Regolazioni</label>
          <div class="ed-row"><span>Luminosità</span><input type="range" id="edBright" min="50" max="150" value="100"><span class="val" id="edBrightV">100</span></div>
          <div class="ed-row"><span>Contrasto</span><input type="range" id="edContrast" min="50" max="150" value="100"><span class="val" id="edContrastV">100</span></div>
          <div class="ed-row"><span>Saturazione</span><input type="range" id="edSat" min="0" max="200" value="100"><span class="val" id="edSatV">100</span></div>
          <div class="ed-row"><span>Nitidezza</span><input type="range" id="edSharpen" min="0" max="100" value="0"><span class="val" id="edSharpenV">0</span></div>
        </div>
        <div class="ed-grp"><label>Esportazione</label>
          <div class="ed-row"><span>Larghezza max</span><select class="ed-sel" id="edMaxW"><option value="0">Originale</option><option value="1600">1600 px</option><option value="1200">1200 px</option><option value="800">800 px</option></select></div>
          <div class="ed-row"><span>Formato</span><select class="ed-sel" id="edFormat"><option value="image/webp">WebP (leggero)</option><option value="image/jpeg">JPEG</option><option value="image/png">PNG</option></select></div>
          <div class="ed-row"><span>Qualità</span><input type="range" id="edQuality" min="50" max="100" value="85"><span class="val" id="edQualityV">85</span></div>
          <div class="ed-note" id="edInfo"></div>
        </div>
      </div>
    </div>
    <div class="ed-ft">
      <label style="display:flex;align-items:center;gap:7px;font-size:12.5px;color:#57604f;cursor:pointer"><input type="checkbox" id="edCopy" checked style="width:auto"> Crea copia al salvataggio</label>
      <div style="display:flex;align-items:center;gap:12px"><span class="ed-note" id="edSaveNote"></span><button type="button" class="btn ghost" onclick="ED.reset()">Ripristina</button><button type="button" class="btn" id="edSave"><i class="ti ti-device-floppy"></i> Salva</button></div>
    </div>
  </div>
</div>
<script>
(function(){
  var modal=document.getElementById('vbEditor'), canvas=document.getElementById('edCanvas'), ctx=canvas.getContext('2d');
  var wrap=document.getElementById('edCanvasWrap'), box=document.getElementById('edCrop'), info=document.getElementById('edInfo');
  var work=document.createElement('canvas'), wctx=work.getContext('2d');
  var src=null, st=null, curScale=1;
  function clamp(v,a,b){return Math.max(a,Math.min(b,v));}
  function $(id){return document.getElementById(id);}

  var EXT2MIME={png:'image/png',jpg:'image/jpeg',jpeg:'image/jpeg',webp:'image/webp'};
  function origInfo(path){ var ext=(path.split('.').pop()||'').toLowerCase(); return {ext:ext, mime:EXT2MIME[ext]||null, canOver:!!EXT2MIME[ext]}; }
  function setupCopy(){ var cb=$('edCopy'); cb.disabled=!st.canOver; cb.checked=true; applyCopyMode(); if(!st.canOver) $('edSaveNote').textContent='Formato '+(st.origExt||'?')+': solo copia.'; }
  function applyCopyMode(){ var copy=$('edCopy').checked, fmt=$('edFormat'), note=$('edSaveNote'); fmt.disabled=!copy;
    if(copy){ st.format=fmt.value; note.textContent=''; } else { note.textContent='Sovrascrive l\'originale ('+(st.origExt||'')+') — aggiorna le pagine che la usano.'; } }

  window.VBedit=function(path){
    var img=new Image();
    img.onload=function(){ src=img; var oi=origInfo(path);
      st={rot:0,flipH:false,flipV:false,bright:100,contrast:100,sat:100,sharpen:0,aspect:0,maxW:0,format:'image/webp',quality:85,path:path,origExt:oi.ext,origMime:oi.mime,canOver:oi.canOver};
      resetControls(); setupCopy(); buildWork(); modal.style.display='flex'; requestAnimationFrame(layout); };
    img.onerror=function(){ alert('Impossibile caricare l\'immagine.'); };
    img.src='../'+path;
  };
  window.VBcloseEditor=function(){ modal.style.display='none'; src=null; };
  modal.addEventListener('click',function(e){ if(e.target===modal) VBcloseEditor(); });

  function resetControls(){
    ['Bright','Contrast','Sat','Sharpen','Quality'].forEach(function(k){});
    $('edBright').value=100;$('edContrast').value=100;$('edSat').value=100;$('edSharpen').value=0;$('edQuality').value=85;
    $('edBrightV').textContent=100;$('edContrastV').textContent=100;$('edSatV').textContent=100;$('edSharpenV').textContent=0;$('edQualityV').textContent=85;
    $('edMaxW').value='0';$('edFormat').value='image/webp';
    document.querySelectorAll('[data-asp]').forEach(function(b){b.classList.toggle('on',b.dataset.asp==='0');});
  }
  function buildWork(){
    var w=src.naturalWidth,h=src.naturalHeight, rot=((st.rot%360)+360)%360, swap=(rot===90||rot===270);
    work.width=swap?h:w; work.height=swap?w:h;
    wctx.save(); wctx.clearRect(0,0,work.width,work.height);
    wctx.translate(work.width/2,work.height/2); wctx.rotate(rot*Math.PI/180); wctx.scale(st.flipH?-1:1, st.flipV?-1:1);
    wctx.drawImage(src,-w/2,-h/2); wctx.restore();
  }
  function layout(){
    var maxW=Math.min(wrap.parentNode.clientWidth-4,620), maxH=Math.min(window.innerHeight*0.6,460);
    curScale=Math.min(maxW/work.width, maxH/work.height, 1);
    canvas.width=work.width; canvas.height=work.height;
    canvas.style.width=(work.width*curScale)+'px'; canvas.style.height=(work.height*curScale)+'px';
    ctx.clearRect(0,0,canvas.width,canvas.height); ctx.drawImage(work,0,0);
    liveFilter(); resetCrop(); updInfo();
  }
  function liveFilter(){ canvas.style.filter='brightness('+st.bright+'%) contrast('+st.contrast+'%) saturate('+st.sat+'%)'; }
  function fitCrop(){
    var W=wrap.clientWidth, H=wrap.clientHeight, bw=W, bh=H;
    if(st.aspect>0){ bw=W; bh=bw/st.aspect; if(bh>H){bh=H;bw=bh*st.aspect;} }
    box.style.width=bw+'px'; box.style.height=bh+'px'; box.style.left=((W-bw)/2)+'px'; box.style.top=((H-bh)/2)+'px';
    updInfo();
  }
  function resetCrop(){ st.aspect=0; document.querySelectorAll('[data-asp]').forEach(function(b){b.classList.toggle('on',b.dataset.asp==='0');}); fitCrop(); }
  function updInfo(){ if(!st)return; var w=Math.round(box.offsetWidth/curScale), h=Math.round(box.offsetHeight/curScale);
    var ow=w; if(st.maxW>0&&ow>st.maxW){h=Math.round(h*st.maxW/ow);ow=st.maxW;} info.textContent='Uscita ≈ '+ow+'×'+h+' px'; }

  // ---- crop drag/resize ----
  var drag=null;
  box.addEventListener('pointerdown',function(e){ if(e.target.classList.contains('ed-h'))return; start(e,'move'); });
  document.querySelectorAll('.ed-h').forEach(function(hd){ hd.addEventListener('pointerdown',function(e){ e.stopPropagation(); start(e,hd.dataset.h); }); });
  function start(e,mode){ e.preventDefault(); drag={mode:mode,sx:e.clientX,sy:e.clientY,L:box.offsetLeft,T:box.offsetTop,W:box.offsetWidth,H:box.offsetHeight,bw:wrap.clientWidth,bh:wrap.clientHeight};
    window.addEventListener('pointermove',move); window.addEventListener('pointerup',up); }
  function move(e){ if(!drag)return; var dx=e.clientX-drag.sx, dy=e.clientY-drag.sy, L=drag.L,T=drag.T,W=drag.W,H=drag.H;
    if(drag.mode==='move'){ L=clamp(drag.L+dx,0,drag.bw-W); T=clamp(drag.T+dy,0,drag.bh-H); }
    else{
      if(drag.mode.indexOf('e')>=0) W=clamp(drag.W+dx,24,drag.bw-drag.L);
      if(drag.mode.indexOf('w')>=0){ var nw=clamp(drag.W-dx,24,drag.L+drag.W); L=drag.L+(drag.W-nw); W=nw; }
      if(drag.mode.indexOf('s')>=0) H=clamp(drag.H+dy,24,drag.bh-drag.T);
      if(drag.mode.indexOf('n')>=0){ var nh=clamp(drag.H-dy,24,drag.T+drag.H); T=drag.T+(drag.H-nh); H=nh; }
      if(st.aspect>0){ H=W/st.aspect; if(T+H>drag.bh){ H=drag.bh-T; W=H*st.aspect; } }
    }
    box.style.left=L+'px';box.style.top=T+'px';box.style.width=W+'px';box.style.height=H+'px'; updInfo();
  }
  function up(){ drag=null; window.removeEventListener('pointermove',move); window.removeEventListener('pointerup',up); }

  // ---- sliders ----
  function bindSlider(id,key){ var el=$(id),v=$(id+'V'); el.addEventListener('input',function(){ st[key]=+el.value; v.textContent=el.value; if(key==='bright'||key==='contrast'||key==='sat')liveFilter(); }); }
  bindSlider('edBright','bright');bindSlider('edContrast','contrast');bindSlider('edSat','sat');bindSlider('edSharpen','sharpen');
  $('edQuality').addEventListener('input',function(){ st.quality=+this.value; $('edQualityV').textContent=this.value; });
  $('edMaxW').addEventListener('change',function(){ st.maxW=+this.value; updInfo(); });
  $('edFormat').addEventListener('change',function(){ st.format=this.value; });
  $('edCopy').addEventListener('change',applyCopyMode);

  window.ED={
    rotate:function(d){ st.rot+=d; buildWork(); layout(); },
    flip:function(a){ if(a==='h')st.flipH=!st.flipH; else st.flipV=!st.flipV; buildWork(); layout(); },
    aspect:function(a,btn){ st.aspect=a; document.querySelectorAll('[data-asp]').forEach(function(b){b.classList.remove('on');}); if(btn)btn.classList.add('on'); fitCrop(); },
    resetCrop:resetCrop,
    reset:function(){ st.rot=0;st.flipH=false;st.flipV=false; resetControls(); st.bright=100;st.contrast=100;st.sat=100;st.sharpen=0;st.maxW=0;st.format='image/webp';st.quality=85; setupCopy(); buildWork(); layout(); }
  };

  // ---- sharpen (unsharp) applicata al salvataggio ----
  function sharpen(octx,w,h,amount){
    var srcd=octx.getImageData(0,0,w,h), out=octx.createImageData(w,h), d=srcd.data, o=out.data, k=[0,-1,0,-1,5,-1,0,-1,0];
    for(var y=0;y<h;y++)for(var x=0;x<w;x++){ var i=(y*w+x)*4;
      for(var c=0;c<3;c++){ var sum=0,ki=0;
        for(var ky=-1;ky<=1;ky++)for(var kx=-1;kx<=1;kx++){ var yy=clamp(y+ky,0,h-1),xx=clamp(x+kx,0,w-1); sum+=d[(yy*w+xx)*4+c]*k[ki++]; }
        var base=d[i+c]; o[i+c]=clamp(base+(sum-base)*amount,0,255); }
      o[i+3]=d[i+3];
    }
    octx.putImageData(out,0,0);
  }
  function doExport(cb){
    var s=curScale, sx=box.offsetLeft/s, sy=box.offsetTop/s, sw=box.offsetWidth/s, sh=box.offsetHeight/s;
    sw=Math.max(1,Math.min(sw,work.width-sx)); sh=Math.max(1,Math.min(sh,work.height-sy));
    var ow=sw, oh=sh; if(st.maxW>0&&ow>st.maxW){ var r=st.maxW/ow; ow=st.maxW; oh=Math.round(oh*r); }
    var out=document.createElement('canvas'); out.width=Math.round(ow); out.height=Math.round(oh);
    var octx=out.getContext('2d');
    octx.filter='brightness('+st.bright+'%) contrast('+st.contrast+'%) saturate('+st.sat+'%)';
    octx.drawImage(work,sx,sy,sw,sh,0,0,out.width,out.height); octx.filter='none';
    if(st.sharpen>0) sharpen(octx,out.width,out.height,st.sharpen/100);
    var q=(st.format==='image/png')?undefined:st.quality/100;
    out.toBlob(cb,st.format,q);
  }
  $('edSave').addEventListener('click',function(){
    var btn=this, copy=$('edCopy').checked;
    if(!copy && st.canOver) st.format=st.origMime; // sovrascrittura: mantieni il formato originale
    doExport(function(blob){
      if(!blob){ alert('Esportazione non riuscita.'); return; }
      var ext=st.format==='image/webp'?'webp':(st.format==='image/jpeg'?'jpg':(st.format==='image/png'?'png':'img'));
      var fd=new FormData(); fd.append('action','save_edited');
      if(copy){ fd.append('mode','copy'); fd.append('alt',''); fd.append('file',blob,'edit-'+Date.now()+'.'+ext); }
      else { fd.append('mode','overwrite'); fd.append('target',st.path); fd.append('file',blob,st.path.split('/').pop()); }
      var restore='<i class="ti ti-device-floppy"></i> Salva';
      btn.disabled=true; btn.innerHTML='Salvataggio…';
      fetch('media.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
        if(j.ok) location.reload(); else { alert('Errore: '+(j.error||'')); btn.disabled=false; btn.innerHTML=restore; }
      }).catch(function(){ alert('Errore di rete.'); btn.disabled=false; btn.innerHTML=restore; });
    });
  });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&modal.style.display==='flex')VBcloseEditor(); });
})();
</script>
<?php endif; ?>
<?php nc_admin_bottom();
