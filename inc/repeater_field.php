<?php
// VelociBuilder LITE — campo "ripetitore" riusabile: lista di sotto-elementi con propri campi
// (es. slide di uno slider, foto di una galleria). Reso lato client (aggiungi/rimuovi/trascina),
// serializzato in un unico input hidden come JSON — nessuna tabella nuova per il componente stesso.
// Uso: echo vb_repeater_field('f_slides', $itemFieldsSchema, $valoreAttuale);
//      poi, una sola volta a fine pagina: vb_repeater_assets();
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

function vb_repeater_field($name, array $itemFields, $value) {
  $items = is_array($value) ? $value : (json_decode((string)$value, true) ?: []);
  if (!is_array($items)) $items = [];
  ob_start(); ?>
  <div class="vb-repeater" data-schema='<?= h(json_encode($itemFields, JSON_UNESCAPED_UNICODE)) ?>' data-value='<?= h(json_encode(array_values($items), JSON_UNESCAPED_UNICODE)) ?>'>
    <div class="vb-rep-rows"></div>
    <button type="button" class="btn sm ghost vb-rep-add"><i class="ti ti-plus"></i> Aggiungi elemento</button>
    <input type="hidden" name="<?= h($name) ?>" class="vb-rep-hidden">
  </div>
  <?php return ob_get_clean();
}

// CSS/JS del componente + SortableJS: da stampare UNA VOLTA per pagina admin, DOPO tutti i
// vb_repeater_field() (stesso ordine già usato per vb_richtext_assets()). Richiede VBpickMedia
// (da nc_media_picker(), già incluso nelle pagine che usano immagini).
function vb_repeater_assets() {
  static $done = false; if ($done) return; $done = true;
  ?>
  <style>
  .vb-repeater{border:1px solid #e3e7de;border-radius:10px;padding:10px;background:#fafbf9}
  .vb-rep-row{display:flex;align-items:flex-start;gap:8px;padding:10px;border:1px solid #e3e7de;border-radius:9px;background:#fff;margin-bottom:8px}
  .vb-rep-handle{cursor:grab;color:#b0b5a8;padding-top:6px;flex-shrink:0}
  .vb-rep-fields{display:flex;gap:8px;flex-wrap:wrap;flex:1;min-width:0}
  .vb-rep-f{flex:1 1 150px;min-width:0}
  .vb-rep-f[data-type="textarea"]{flex:1 1 100%}
  .vb-rep-f label{font-size:10.5px;color:#8a9184;font-weight:600;display:block;margin-bottom:3px}
  .vb-rep-f input[type=text],.vb-rep-f textarea,.vb-rep-f select{width:100%;font-size:12.5px;padding:7px 9px;border:1px solid #d5dacf;border-radius:7px;font-family:inherit}
  .vb-rep-f textarea{min-height:52px;resize:vertical}
  .vb-rep-imgwrap{display:flex;gap:6px;align-items:center}
  .vb-rep-imgprev{width:44px;height:34px;object-fit:cover;border:1px solid #e3e7de;border-radius:6px;background:#fff;flex-shrink:0}
  .vb-rep-del{flex-shrink:0;margin-top:2px}
  </style>
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
  <script>
  (function(){
    // escape sicuro anche per i valori dentro attributi (deve neutralizzare apici e virgolette)
    function esc(s){ var d=document.createElement('div'); d.textContent=String(s==null?'':s); return d.innerHTML.replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
    document.querySelectorAll('.vb-repeater').forEach(function(box){
      if (box._vbRepInit) return; box._vbRepInit = true;
      var schema, items;
      try { schema = JSON.parse(box.dataset.schema || '[]'); } catch(e) { schema = []; }
      try { items = JSON.parse(box.dataset.value || '[]'); } catch(e) { items = []; }
      var rows = box.querySelector('.vb-rep-rows');
      var hidden = box.querySelector('.vb-rep-hidden');

      function fieldHtml(f, val) {
        val = val == null ? '' : val;
        if (f.type === 'textarea') return '<textarea data-k="' + f.key + '" placeholder="' + esc(f.label||'') + '">' + esc(val) + '</textarea>';
        if (f.type === 'select') {
          var opts = ''; var options = f.options || {};
          Object.keys(options).forEach(function(ov){ opts += '<option value="' + esc(ov) + '"' + (String(ov) === String(val) ? ' selected' : '') + '>' + esc(options[ov]) + '</option>'; });
          return '<select data-k="' + f.key + '">' + opts + '</select>';
        }
        if (f.type === 'image') {
          return '<div class="vb-rep-imgwrap">'
            + '<img class="vb-rep-imgprev" src="' + esc(val ? '../' + val : '../assets/product-placeholder.png') + '">'
            + '<input type="hidden" data-k="' + f.key + '" value="' + esc(val) + '">'
            + '<button type="button" class="btn sm ghost vb-rep-pick"><i class="ti ti-photo"></i></button>'
            + '</div>';
        }
        return '<input type="text" data-k="' + f.key + '" placeholder="' + esc(f.label||'') + '" value="' + esc(val) + '">';
      }

      function addRow(data) {
        data = data || {};
        var row = document.createElement('div'); row.className = 'vb-rep-row';
        var html = '<span class="vb-rep-handle" title="trascina"><i class="ti ti-grip-vertical"></i></span><div class="vb-rep-fields">';
        schema.forEach(function(f){ html += '<div class="vb-rep-f" data-type="' + f.type + '"><label>' + esc(f.label||'') + '</label>' + fieldHtml(f, data[f.key]) + '</div>'; });
        html += '</div><button type="button" class="btn sm danger vb-rep-del"><i class="ti ti-trash"></i></button>';
        row.innerHTML = html;
        rows.appendChild(row);
        var pickBtn = row.querySelector('.vb-rep-pick');
        if (pickBtn) pickBtn.addEventListener('click', function(){
          var wrap = pickBtn.closest('.vb-rep-imgwrap'), inp = wrap.querySelector('input'), img = wrap.querySelector('img');
          if (typeof VBpickMedia === 'function') VBpickMedia(function(p){ inp.value = p; img.src = '../' + p; sync(); });
        });
        row.querySelector('.vb-rep-del').addEventListener('click', function(){ row.remove(); sync(); });
        row.querySelectorAll('input,textarea,select').forEach(function(el){ el.addEventListener('input', sync); el.addEventListener('change', sync); });
      }

      function sync() {
        var out = [];
        rows.querySelectorAll('.vb-rep-row').forEach(function(row){
          var o = {}; row.querySelectorAll('[data-k]').forEach(function(el){ o[el.dataset.k] = el.value; });
          out.push(o);
        });
        hidden.value = JSON.stringify(out);
      }

      items.forEach(addRow);
      sync();
      box.querySelector('.vb-rep-add').addEventListener('click', function(){ addRow({}); sync(); });
      if (typeof Sortable !== 'undefined') new Sortable(rows, { handle: '.vb-rep-handle', animation: 150, onEnd: sync });
      var form = box.closest('form'); if (form) form.addEventListener('submit', sync);
    });
  })();
  </script>
  <?php
}
