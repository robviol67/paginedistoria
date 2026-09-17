<?php
// VelociBuilder LITE — selettore colore riusabile: codice hex + picker visivo, sincronizzati.
// Uso: echo vb_color_field('nome_campo', $valoreAttuale);
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

function vb_color_field($name, $value) {
  static $n = 0; $n++;
  $id = 'vbcolor' . $n;
  $value = trim((string)$value) !== '' ? trim($value) : '#1F7A3D';
  $hexOk = preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? $value : '#1F7A3D';
  ob_start(); ?>
  <div class="vb-colorfield">
    <input type="color" id="<?= $id ?>_p" value="<?= h($hexOk) ?>" class="vb-colorpick" title="scegli visivamente">
    <input type="text" id="<?= $id ?>_h" name="<?= h($name) ?>" value="<?= h($value) ?>" class="vb-colorhex" placeholder="#1F7A3D" maxlength="7">
  </div>
  <script>
  (function(){
    var p=document.getElementById('<?= $id ?>_p'), t=document.getElementById('<?= $id ?>_h');
    p.addEventListener('input',function(){ t.value=p.value.toUpperCase(); });
    t.addEventListener('input',function(){ if(/^#[0-9A-Fa-f]{6}$/.test(t.value)) p.value=t.value; });
  })();
  </script>
  <?php return ob_get_clean();
}
