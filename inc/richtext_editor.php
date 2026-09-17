<?php
// VelociBuilder LITE — editor di testo ricco riusabile (funzioni base: grassetto, corsivo,
// sottolineato, titoli, elenchi, link, allineamento). Contenuto salvato come HTML grezzo:
// stesso livello di fiducia dei testi CMS già editabili dal pannello (contenuto di un admin
// autenticato, non input pubblico) — non viene sanificato in output, coerente col resto del motore.
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

function vb_richtext_field($name, $value) {
  static $n = 0; $n++;
  $id = 'vbrte' . $n;
  ob_start(); ?>
  <div class="vb-rte">
    <div class="vb-rte-toolbar">
      <button type="button" data-cmd="bold" title="Grassetto"><b>B</b></button>
      <button type="button" data-cmd="italic" title="Corsivo"><i>I</i></button>
      <button type="button" data-cmd="underline" title="Sottolineato"><u>U</u></button>
      <span class="vb-rte-sep"></span>
      <select class="vb-rte-format" title="Stile paragrafo">
        <option value="p">Paragrafo</option>
        <option value="h2">Titolo</option>
        <option value="h3">Sottotitolo</option>
      </select>
      <span class="vb-rte-sep"></span>
      <button type="button" data-cmd="insertUnorderedList" title="Elenco puntato"><i class="ti ti-list"></i></button>
      <button type="button" data-cmd="insertOrderedList" title="Elenco numerato"><i class="ti ti-list-numbers"></i></button>
      <span class="vb-rte-sep"></span>
      <button type="button" data-cmd="createLink" title="Inserisci link"><i class="ti ti-link"></i></button>
      <button type="button" data-cmd="unlink" title="Rimuovi link"><i class="ti ti-unlink"></i></button>
      <span class="vb-rte-sep"></span>
      <button type="button" data-cmd="justifyLeft" title="Allinea a sinistra"><i class="ti ti-align-left"></i></button>
      <button type="button" data-cmd="justifyCenter" title="Centra"><i class="ti ti-align-center"></i></button>
      <span class="vb-rte-sep"></span>
      <button type="button" data-cmd="removeFormat" title="Pulisci formattazione"><i class="ti ti-clear-formatting"></i></button>
      <span class="vb-rte-sep"></span>
      <button type="button" class="vb-rte-source-btn" title="Codice sorgente HTML">&lt;/&gt;</button>
    </div>
    <div class="vb-rte-area" contenteditable="true"><?= $value ?></div>
    <textarea name="<?= h($name) ?>" class="vb-rte-hidden"><?= h($value) ?></textarea>
  </div>
  <?php return ob_get_clean();
}

// CSS/JS del componente: da stampare UNA VOLTA per pagina admin che usa vb_richtext_field().
function vb_richtext_assets() {
  static $done = false; if ($done) return; $done = true;
  ?>
  <style>
  .vb-rte{border:1px solid #d5dacf;border-radius:9px;overflow:hidden;background:#fff}
  .vb-rte-toolbar{display:flex;align-items:center;gap:2px;padding:6px 8px;background:#f4f6f3;border-bottom:1px solid #e3e7de;flex-wrap:wrap}
  .vb-rte-toolbar button{border:none;background:transparent;padding:6px 9px;border-radius:6px;cursor:pointer;color:#57604f;font-size:13px}
  .vb-rte-toolbar button:hover{background:#e7ece4}
  .vb-rte-toolbar select{width:auto;max-width:140px;border:1px solid #d5dacf;border-radius:6px;padding:5px 7px;font-size:12.5px;background:#fff}
  .vb-rte-sep{width:1px;align-self:stretch;background:#e3e7de;margin:0 4px}
  .vb-rte-area{min-height:120px;padding:12px 14px;font-size:14.5px;line-height:1.6;outline:none}
  .vb-rte-area h2{font-family:'Poppins',sans-serif;font-size:22px;font-weight:700;margin:10px 0}
  .vb-rte-area h3{font-family:'Poppins',sans-serif;font-size:18px;font-weight:700;margin:8px 0}
  .vb-rte-area p{margin:0 0 10px}
  .vb-rte-area a{color:#1F7A3D}
  .vb-rte-hidden{display:none;width:100%;min-height:120px;padding:12px 14px;font:12.5px/1.5 ui-monospace,monospace;border:none;outline:none;resize:vertical}
  .vb-rte.vb-rte-source .vb-rte-area{display:none}
  .vb-rte.vb-rte-source .vb-rte-hidden{display:block}
  .vb-rte-source-btn.active{background:#e7ece4;color:#1F7A3D}
  </style>
  <script>
  (function(){
    document.querySelectorAll('.vb-rte').forEach(function(box){
      var area=box.querySelector('.vb-rte-area'), hidden=box.querySelector('.vb-rte-hidden'), fmt=box.querySelector('.vb-rte-format'), srcBtn=box.querySelector('.vb-rte-source-btn');
      if(srcBtn) srcBtn.addEventListener('click', function(){
        if(box.classList.contains('vb-rte-source')){
          area.innerHTML = hidden.value;
          box.classList.remove('vb-rte-source');
          srcBtn.classList.remove('active');
        } else {
          hidden.value = area.innerHTML;
          box.classList.add('vb-rte-source');
          srcBtn.classList.add('active');
          hidden.focus();
        }
      });
      box.querySelectorAll('[data-cmd]').forEach(function(btn){
        btn.addEventListener('click', function(){
          area.focus();
          var cmd=btn.dataset.cmd;
          if(cmd==='createLink'){ var url=prompt('URL del link (es. https://... oppure pagina.html):',''); if(!url) return; document.execCommand(cmd,false,url); }
          else document.execCommand(cmd,false,null);
          sync();
        });
      });
      if(fmt) fmt.addEventListener('change', function(){ area.focus(); document.execCommand('formatBlock',false,'<'+fmt.value+'>'); sync(); });
      function sync(){ if(!box.classList.contains('vb-rte-source')) hidden.value = area.innerHTML; }
      area.addEventListener('input', sync);
      area.addEventListener('blur', sync);
      var form = box.closest('form'); if (form) form.addEventListener('submit', sync);
    });
  })();
  </script>
  <?php
}
