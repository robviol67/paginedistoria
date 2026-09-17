<?php
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/forms.php';

// Riordino drag&drop campi (AJAX)
if (($_POST['action'] ?? '') === 'reorder') {
  header('Content-Type: application/json');
  try { fields_reorder(array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')))); echo json_encode(['ok' => true]); }
  catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
  exit;
}

$formId = (int)($_GET['form'] ?? 0);
$ready = forms_table_ready();

$msg = ''; $msgType = 'ok';
$act = $_POST['action'] ?? '';
try {
  if ($act === 'new_form' && $ready) {
    $id = form_create(trim($_POST['name'] ?? '')); header('Location: forms.php?form=' . (int)$id); exit;
  } elseif ($act === 'delete_form') {
    form_delete($_POST['id']); header('Location: forms.php'); exit;
  } elseif ($act === 'form_save' && $formId) {
    $slug = form_save($formId, $_POST); $msg = 'Impostazioni salvate ✓ (slug: ' . h($slug) . ')';
  } elseif ($act === 'field_add' && $formId) {
    field_add($formId, $_POST['type'] ?? ''); $msg = 'Campo aggiunto — compilalo qui sotto.';
  } elseif ($act === 'field_save') {
    field_save($_POST['id'], $_POST); $msg = 'Campo salvato ✓.';
  } elseif ($act === 'field_delete') {
    field_delete($_POST['id']); $msg = 'Campo eliminato.';
  }
} catch (Throwable $e) { $msgType = 'err'; $msg = $e->getMessage(); }

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('forms', 'Moduli — VelociBuilder LITE');

if (!$ready) { echo '<div class="hd"><div><h1>Moduli</h1></div></div><div class="msg err">Tabelle non trovate. Lancia prima: <a class="lnk" href="../api/migrate_forms.php" target="_blank">/api/migrate_forms.php</a></div>'; nc_admin_bottom(); exit; }

$form = $formId ? form_get($formId) : null;
require_once __DIR__ . '/../inc/recipients.php';
$def = nc_default_recipient_info();
$defLabel = $def['email'] !== ''
  ? $def['email'] . ' (' . ['scelto' => 'admin scelto in Utenti', 'unico' => 'unico admin', 'mail-config' => 'indirizzo di mail-config.php'][$def['source']] . ')'
  : 'nessuno — scegli un admin in Utenti';
$extra = forms_has_extra_cols();

if (!$form):
  $forms = forms_all();
?>
<div class="hd"><div><h1>Moduli</h1><p class="sub">I form del sito: chi riceve le richieste, conferma, auto-risposta. Usabili anche come blocco "Modulo" nell'editor pagine</p></div></div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>
<p style="font-size:13px;color:#6a7266;margin:0 0 12px">Destinatario predefinito (moduli senza destinatari): <b><?= h($defLabel) ?></b> · <a class="lnk" href="utenti.php">cambia in Utenti</a></p>
<div class="card" style="padding:8px">
  <?php if (!$forms): ?><p style="color:#8a9184;text-align:center;padding:18px;margin:0">Nessun modulo ancora. Creane uno qui sotto.</p><?php endif; ?>
  <?php foreach ($forms as $f): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-bottom:1px solid #eef1ec">
      <div><div style="font-weight:600"><?= h($f['name']) ?></div><div style="color:#8a9184;font-size:12.5px"><?= submission_count($f['id']) ?> invii ricevuti · <?= $f['active'] ? '<span style="color:#1F7A3D">attivo</span>' : '<span style="color:#8a9184">disattivo</span>' ?> · <i class="ti ti-mail" style="vertical-align:-2px"></i> <?= h(trim((string)$f['recipients']) !== '' ? $f['recipients'] : 'predefinito: ' . ($def['email'] ?: 'nessuno')) ?></div></div>
      <div style="display:flex;gap:6px">
        <a class="btn sm" href="forms.php?form=<?= (int)$f['id'] ?>"><i class="ti ti-edit"></i> Modifica</a>
        <form method="post" style="margin:0" onsubmit="return confirm('Eliminare questo modulo, i suoi campi e gli invii ricevuti?')"><input type="hidden" name="action" value="delete_form"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn sm danger" type="submit"><i class="ti ti-trash"></i></button></form>
      </div>
    </div>
  <?php endforeach; ?>
  <form method="post" style="display:flex;gap:8px;padding:12px 14px">
    <input type="hidden" name="action" value="new_form">
    <input type="text" name="name" placeholder="Nome del nuovo modulo (es. Richiedi preventivo)" style="flex:1" required>
    <button class="btn sm" type="submit"><i class="ti ti-plus"></i> Nuovo modulo</button>
  </form>
</div>
<?php
else:
  $fields = fields_of_form($formId);
  $subs = submissions_of_form($formId, 20);
?>
<div class="hd">
  <div><h1><?= h($form['name']) ?></h1><p class="sub">Modulo · slug <code><?= h($form['slug']) ?></code> · usalo dal blocco "Modulo" nell'editor pagine</p></div>
  <a class="btn ghost" href="forms.php"><i class="ti ti-arrow-left"></i> Moduli</a>
</div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<div class="card">
  <div class="sec" style="margin-top:0"><i class="ti ti-settings" style="vertical-align:-2px"></i> Impostazioni</div>
  <form method="post">
    <input type="hidden" name="action" value="form_save">
    <div class="row">
      <div class="field"><label>Nome</label><input type="text" name="name" value="<?= h($form['name']) ?>"></div>
      <div class="field"><label>Slug <span style="color:#b23a3a;font-weight:400">(le pagine lo usano: cambiandolo il form smette di funzionare)</span></label><input type="text" name="slug" value="<?= h($form['slug']) ?>"></div>
    </div>
    <div class="field"><label>Destinatari (email, separate da virgola)</label><input type="text" name="recipients" value="<?= h($form['recipients']) ?>" placeholder="vuoto = <?= h($def['email'] ?: 'destinatario predefinito') ?>">
      <p style="color:#8a9184;font-size:12.5px;margin:5px 0 0">Lasciato vuoto, le richieste vanno al destinatario predefinito: <b><?= h($defLabel) ?></b>.</p></div>
    <div class="field"><label>Oggetto email destinatari <span style="color:#8a9184;font-weight:400">(puoi usare i segnaposto dei campi, es. {{azienda}}; senza segnaposto si aggiunge il nome)</span></label><input type="text" name="subject" value="<?= h($form['subject']) ?>"></div>
    <?php if ($extra): ?>
    <div class="field"><label>Titolo della pagina di conferma</label><input type="text" name="success_title" value="<?= h($form['success_title'] ?? '') ?>" placeholder="Richiesta inviata"></div>
    <?php endif; ?>
    <div class="field"><label>Messaggio di conferma (mostrato dopo l'invio)</label><textarea name="success_message"><?= h($form['success_message']) ?></textarea></div>
    <label style="font-weight:400;font-size:14px;display:block;margin-bottom:14px"><input type="checkbox" name="active" value="1" <?= $form['active'] ? 'checked' : '' ?> style="width:auto"> Modulo attivo (accetta invii)</label>

    <div class="sec">Auto-risposta al mittente</div>
    <label style="font-weight:400;font-size:14px;display:block;margin-bottom:10px"><input type="checkbox" name="autoreply_enabled" value="1" <?= $form['autoreply_enabled'] ? 'checked' : '' ?> style="width:auto"> Invia un'email automatica a chi compila (richiede un campo mappato su "Email")</label>
    <div class="field"><label>Oggetto auto-risposta</label><input type="text" name="autoreply_subject" value="<?= h($form['autoreply_subject']) ?>" placeholder="Abbiamo ricevuto la tua richiesta"></div>
    <div class="field"><label>Testo auto-risposta <span style="color:#8a9184;font-weight:400">(segnaposto: <?= implode(', ', array_map(fn($f) => '{{' . h($f['field_key']) . '}}', $fields)) ?: '{{nome_campo}}' ?>)</span></label><textarea name="autoreply_body" style="min-height:100px"><?= h($form['autoreply_body']) ?></textarea></div>
    <?php if ($extra): ?>
    <div class="field"><label>Allegato dell'auto-risposta <span style="color:#8a9184;font-weight:400">(facoltativo)</span></label><input type="text" name="autoreply_attachment" value="<?= h($form['autoreply_attachment'] ?? '') ?>" placeholder="es. assets/guida.pdf">
      <p style="color:#8a9184;font-size:12.5px;margin:5px 0 0">Percorso del file sul sito, a partire dalla cartella principale. Viene allegato all'auto-risposta, il link va nel testo con <code>{{link_allegato}}</code> (se non lo metti si aggiunge in fondo) e la pagina di conferma mostra "Scarica ora il PDF". Oltre 1,5 MB il file non si allega e resta solo il link.
      <?php $ap = trim((string)($form['autoreply_attachment'] ?? '')); if ($ap !== ''): ?>
        <?= (strpos($ap, '..') === false && is_readable(__DIR__ . '/../' . $ap)) ? '<span style="color:#1F7A3D">✓ file trovato (' . round(filesize(__DIR__ . '/../' . $ap) / 1024) . ' KB)</span>' : '<span style="color:#b23a3a">✗ file non trovato sul sito</span>' ?>
      <?php endif; ?></p>
    </div>
    <?php endif; ?>

    <div class="sec">CRM</div>
    <label style="font-weight:400;font-size:14px;display:block;margin-bottom:14px"><input type="checkbox" name="vt_enabled" value="1" <?= $form['vt_enabled'] ? 'checked' : '' ?> style="width:auto"> Invia i lead a VelociTracker (richiede un campo mappato su "Email"; configurazione connessione in VelociTracker)</label>

    <button class="btn sm" type="submit"><i class="ti ti-device-floppy"></i> Salva impostazioni</button>
  </form>
</div>

<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
    <div class="sec" style="margin:0">Campi <span style="color:#8a9184;font-weight:400;font-size:13px">(<?= count($fields) ?>)</span></div>
    <span style="font-size:12px;color:#8a9184"><i class="ti ti-arrows-move" style="vertical-align:-2px"></i> trascina per riordinare</span>
  </div>
  <p style="font-size:12.5px;color:#8a9184;margin:0 0 12px">Se il modulo è un form disegnato dentro una pagina del sito, i campi qui servono a controllare i dati e a comporre l'email: la <b>chiave</b> deve restare uguale al campo della pagina, e aggiungere un campo qui non lo aggiunge alla pagina.</p>
  <div id="fieldList">
  <?php foreach ($fields as $f): ?>
    <div style="border:1px solid #e3e7de;border-radius:10px;margin-bottom:10px" data-id="<?= (int)$f['id'] ?>">
      <div style="display:flex;align-items:center;gap:8px;padding:9px 12px;background:#f4f6f3;border-bottom:1px solid #e3e7de;border-radius:10px 10px 0 0">
        <span class="vb-drag" style="cursor:grab;color:#b0b5a8" title="trascina"><i class="ti ti-grip-vertical"></i></span>
        <strong style="font-size:13px"><?= h(FORM_FIELD_TYPES[$f['type']] ?? $f['type']) ?></strong>
        <code style="font-size:11px;color:#8a9184">{{<?= h($f['field_key']) ?>}}</code>
        <form method="post" style="margin-left:auto"><input type="hidden" name="action" value="field_delete"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn sm danger" type="submit" onclick="return confirm('Eliminare questo campo?')"><i class="ti ti-trash"></i></button></form>
      </div>
      <form method="post" style="padding:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="action" value="field_save"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
        <div style="flex:1 1 140px"><label style="font-size:11px;color:#8a9184;font-weight:600;display:block;margin-bottom:3px">Etichetta</label><input type="text" name="label" value="<?= h($f['label']) ?>"></div>
        <?php if (!in_array($f['type'], ['consent'], true)): ?>
        <div style="flex:1 1 120px"><label style="font-size:11px;color:#8a9184;font-weight:600;display:block;margin-bottom:3px">Chiave</label><input type="text" name="field_key" value="<?= h($f['field_key']) ?>"></div>
        <?php else: ?><input type="hidden" name="field_key" value="<?= h($f['field_key']) ?>"><?php endif; ?>
        <?php if (in_array($f['type'], ['text', 'textarea', 'email', 'tel'], true)): ?>
        <div style="flex:1 1 140px"><label style="font-size:11px;color:#8a9184;font-weight:600;display:block;margin-bottom:3px">Placeholder</label><input type="text" name="placeholder" value="<?= h($f['placeholder']) ?>"></div>
        <?php endif; ?>
        <?php if ($f['type'] === 'select'): ?>
        <div style="flex:1 1 100%"><label style="font-size:11px;color:#8a9184;font-weight:600;display:block;margin-bottom:3px">Opzioni (una per riga, "valore|Etichetta" oppure solo "Etichetta")</label><textarea name="options" style="min-height:60px"><?= h($f['options']) ?></textarea></div>
        <?php endif; ?>
        <?php if (!in_array($f['type'], ['consent', 'checkbox'], true)): ?>
        <div style="flex:0 0 150px"><label style="font-size:11px;color:#8a9184;font-weight:600;display:block;margin-bottom:3px">Campo CRM</label>
          <select name="vt_map"><?php foreach (FORM_VT_MAP as $mv => $ml): ?><option value="<?= h($mv) ?>" <?= $f['vt_map'] === $mv ? 'selected' : '' ?>><?= h($ml) ?></option><?php endforeach; ?></select>
        </div>
        <?php endif; ?>
        <label style="font-weight:400;font-size:13px;margin:0 0 10px"><input type="checkbox" name="required" value="1" <?= $f['required'] ? 'checked' : '' ?> <?= $f['type'] === 'consent' ? 'disabled checked' : '' ?>> obbligatorio</label>
        <button class="btn sm ghost" type="submit">Salva campo</button>
      </form>
    </div>
  <?php endforeach; ?>
  </div>
  <div style="border-top:1px solid #eef1ec;padding-top:14px">
    <div style="font-size:12.5px;color:#8a9184;font-weight:600;margin-bottom:8px">+ Aggiungi campo</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach (FORM_FIELD_TYPES as $type => $label): ?>
      <form method="post" style="margin:0"><input type="hidden" name="action" value="field_add"><input type="hidden" name="type" value="<?= h($type) ?>"><button class="btn sm ghost" type="submit"><?= h($label) ?></button></form>
    <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="sec" style="margin-top:0"><i class="ti ti-inbox" style="vertical-align:-2px"></i> Ultimi invii <span style="color:#8a9184;font-weight:400;font-size:13px">(<?= submission_count($formId) ?> totali)</span></div>
  <?php if (!$subs): ?><p style="color:#8a9184;margin:0">Nessun invio ricevuto ancora.</p><?php else: ?>
  <?php foreach ($subs as $s): ?>
    <div style="border-bottom:1px solid #eef1ec;padding:10px 0;font-size:13px">
      <div style="color:#8a9184;font-size:11.5px;margin-bottom:4px"><?= h(date('d/m/Y H:i', strtotime($s['created_at']))) ?></div>
      <?php foreach ($s['data'] as $k => $v): if ($v === '') continue; ?><div><strong><?= h($k) ?>:</strong> <?= h($v) ?></div><?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<div id="vbToast" style="display:none;position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:#1e2418;color:#fff;padding:10px 18px;border-radius:100px;font-size:13px;font-weight:600;z-index:3000;box-shadow:0 8px 24px rgba(0,0,0,.2)"></div>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(function(){
  function toast(t,ok){var e=document.getElementById('vbToast');e.textContent=t;e.style.background=ok?'#1F7A3D':'#b23a3a';e.style.display='block';clearTimeout(e._t);e._t=setTimeout(function(){e.style.display='none';},2000);}
  if(typeof Sortable==='undefined')return;
  var list=document.getElementById('fieldList');
  if(list)new Sortable(list,{handle:'.vb-drag',animation:150,onEnd:function(){
    var ids=[].map.call(list.querySelectorAll('[data-id]'),function(f){return f.dataset.id;});
    var b=new URLSearchParams();b.append('action','reorder');b.append('ids',ids.join(','));
    fetch('forms.php?form=<?= (int)$formId ?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b})
      .then(function(r){return r.json();}).then(function(j){toast(j.ok?'Ordine salvato ✓':'Errore',j.ok);})
      .catch(function(){toast('Errore di rete',false);});
  }});
})();
</script>
<?php endif; ?>
<?php nc_admin_bottom();
