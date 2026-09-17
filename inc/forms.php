<?php
// VelociBuilder LITE — Form builder: form (destinatari + autorisposta) + campi + submission.
// I form creati qui sono selezionabili come blocco "form" nel page-builder (inc/blocks.php).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/consent.php';
if (!function_exists('pesc')) { function pesc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!defined('FORM_ACCENT')) define('FORM_ACCENT', '#1F7A3D');       // colore pulsante/link (override in config.php)
if (!defined('FORM_FONT'))   define('FORM_FONT', "'Public Sans',sans-serif");

const FORM_FIELD_TYPES = ['text' => 'Testo', 'email' => 'Email', 'tel' => 'Telefono', 'textarea' => 'Testo lungo', 'select' => 'Menu a tendina', 'checkbox' => 'Checkbox (facoltativo)', 'consent' => 'Consenso privacy (obbligatorio)'];
const FORM_VT_MAP = ['' => '— (nessuno)', 'email' => 'Email', 'nome' => 'Nome', 'azienda' => 'Azienda', 'telefono' => 'Telefono', 'messaggio' => 'Messaggio', 'tipo' => 'Tipo/oggetto'];

/* ---------- CRUD form ---------- */
function forms_table_ready() { try { db()->query('SELECT 1 FROM cms_forms LIMIT 1'); return true; } catch (Throwable $e) { return false; } }
function forms_all() { try { return db()->query('SELECT * FROM cms_forms ORDER BY id DESC')->fetchAll(); } catch (Throwable $e) { return []; } }
function form_get($id) { $st = db()->prepare('SELECT * FROM cms_forms WHERE id=?'); $st->execute([(int)$id]); return $st->fetch(); }
function form_get_by_slug($slug) { $st = db()->prepare('SELECT * FROM cms_forms WHERE slug=? AND active=1'); $st->execute([$slug]); return $st->fetch(); }

function forms_slugify($s) { $s = strtolower(trim($s)); $s = preg_replace('/[^a-z0-9]+/', '-', $s); return trim($s, '-') ?: 'modulo'; }
function forms_unique_slug($base, $excludeId = 0) {
  $slug = $base; $i = 2;
  while (true) {
    $st = db()->prepare('SELECT id FROM cms_forms WHERE slug=? AND id<>?'); $st->execute([$slug, (int)$excludeId]);
    if (!$st->fetch()) break;
    $slug = $base . '-' . $i++;
  }
  return $slug;
}
function form_create($name) {
  $slug = forms_unique_slug(forms_slugify($name ?: 'nuovo-modulo'));
  $st = db()->prepare('INSERT INTO cms_forms (name, slug, subject, success_message) VALUES (?,?,?,?)');
  $st->execute([trim($name) ?: 'Nuovo modulo', $slug, 'Nuova richiesta dal sito', 'Grazie! Ti risponderemo al più presto.']);
  return db()->lastInsertId();
}
function form_save($id, array $d) {
  $slug = forms_unique_slug(forms_slugify($d['slug'] ?? $d['name'] ?? ''), $id);
  $st = db()->prepare('UPDATE cms_forms SET name=?, slug=?, recipients=?, subject=?, success_message=?, autoreply_enabled=?, autoreply_subject=?, autoreply_body=?, vt_enabled=?, active=? WHERE id=?');
  $st->execute([
    trim($d['name'] ?? ''), $slug, trim($d['recipients'] ?? ''), trim($d['subject'] ?? ''), trim($d['success_message'] ?? ''),
    isset($d['autoreply_enabled']) ? 1 : 0, trim($d['autoreply_subject'] ?? ''), trim($d['autoreply_body'] ?? ''),
    isset($d['vt_enabled']) ? 1 : 0, isset($d['active']) ? 1 : 0, (int)$id,
  ]);
  // colonne aggiunte dopo (migrate_forms): si scrivono solo se la migration è già passata
  if (forms_has_extra_cols()) {
    $att = trim(str_replace('\\', '/', (string)($d['autoreply_attachment'] ?? '')), " /");
    if (strpos($att, '..') !== false) $att = '';
    db()->prepare('UPDATE cms_forms SET success_title=?, autoreply_attachment=? WHERE id=?')
      ->execute([trim($d['success_title'] ?? ''), $att, (int)$id]);
  }
  return $slug;
}
function forms_has_extra_cols() {
  static $has = null;
  if ($has === null) { try { $has = db_col_exists('cms_forms', 'autoreply_attachment') && db_col_exists('cms_forms', 'success_title'); } catch (Throwable $e) { $has = false; } }
  return $has;
}
function form_delete($id) {
  db()->prepare('DELETE FROM cms_form_fields WHERE form_id=?')->execute([(int)$id]);
  db()->prepare('DELETE FROM cms_form_submissions WHERE form_id=?')->execute([(int)$id]);
  db()->prepare('DELETE FROM cms_forms WHERE id=?')->execute([(int)$id]);
}

/* ---------- CRUD campi ---------- */
function fields_of_form($formId) { $st = db()->prepare('SELECT * FROM cms_form_fields WHERE form_id=? ORDER BY sort, id'); $st->execute([(int)$formId]); return $st->fetchAll(); }
function field_get($id) { $st = db()->prepare('SELECT * FROM cms_form_fields WHERE id=?'); $st->execute([(int)$id]); return $st->fetch(); }
function field_add($formId, $type) {
  if (!isset(FORM_FIELD_TYPES[$type])) throw new Exception('Tipo di campo sconosciuto.');
  $mx = db()->prepare('SELECT COALESCE(MAX(sort),-1)+1 FROM cms_form_fields WHERE form_id=?'); $mx->execute([(int)$formId]); $sort = (int)$mx->fetchColumn();
  $defaults = ['consent' => ['field_key' => 'privacy', 'label' => 'Consenso privacy'], 'checkbox' => ['field_key' => 'opt_in', 'label' => 'Iscrivimi alla newsletter']];
  $fk = $defaults[$type]['field_key'] ?? ('campo_' . ($sort + 1));
  $lb = $defaults[$type]['label'] ?? 'Nuovo campo';
  $st = db()->prepare('INSERT INTO cms_form_fields (form_id, sort, field_key, label, type, required) VALUES (?,?,?,?,?,?)');
  $st->execute([(int)$formId, $sort, $fk, $lb, $type, $type === 'consent' ? 1 : 0]);
  return db()->lastInsertId();
}
function field_save($id, array $d) {
  // il consenso privacy è SEMPRE obbligatorio, a prescindere da cosa arriva dal form
  // (la sua checkbox "obbligatorio" in admin è disabilitata quindi il browser non la invia mai:
  // se leggessimo solo $_POST qui, ogni salvataggio lo declasserebbe a facoltativo).
  $existing = field_get($id);
  $type = $existing['type'] ?? '';
  $required = ($type === 'consent') ? 1 : (isset($d['required']) ? 1 : 0);
  $key = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($d['field_key'] ?? ''))) ?: 'campo';
  $st = db()->prepare('UPDATE cms_form_fields SET field_key=?, label=?, required=?, options=?, placeholder=?, vt_map=? WHERE id=?');
  $st->execute([$key, trim($d['label'] ?? ''), $required, trim($d['options'] ?? ''), trim($d['placeholder'] ?? ''), $d['vt_map'] ?? '', (int)$id]);
}
function field_delete($id) { db()->prepare('DELETE FROM cms_form_fields WHERE id=?')->execute([(int)$id]); }
function fields_reorder($ids) { $st = db()->prepare('UPDATE cms_form_fields SET sort=? WHERE id=?'); foreach (array_values($ids) as $i => $id) $st->execute([$i, (int)$id]); }

/* ---------- Submission ---------- */
function submissions_of_form($formId, $limit = 50) {
  $st = db()->prepare('SELECT * FROM cms_form_submissions WHERE form_id=? ORDER BY id DESC LIMIT ' . (int)$limit);
  $st->execute([(int)$formId]);
  return array_map(function ($r) { $r['data'] = json_decode($r['data'], true) ?: []; return $r; }, $st->fetchAll());
}
function submission_count($formId) { $st = db()->prepare('SELECT COUNT(*) FROM cms_form_submissions WHERE form_id=?'); $st->execute([(int)$formId]); return (int)$st->fetchColumn(); }

/* ---------- Rendering pubblico del form (embeddato dal blocco "form") ---------- */
function form_select_options($optionsText) {
  $out = [];
  foreach (preg_split('/\r\n|\r|\n/', (string)$optionsText) as $line) {
    $line = trim($line); if ($line === '') continue;
    if (strpos($line, '|') !== false) { [$v, $l] = array_map('trim', explode('|', $line, 2)); }
    else { $v = $l = $line; }
    $out[$v] = $l;
  }
  return $out;
}
function render_form_html($form) {
  $fields = fields_of_form($form['id']);
  $inputStyle = 'width:100%;box-sizing:border-box;padding:13px 14px;border-radius:10px;border:1px solid oklch(88% 0.01 260);font-size:15px;font-family:' . FORM_FONT;
  $out = '<form method="post" action="forms.php" style="display:flex;flex-direction:column;gap:16px;max-width:560px;margin:0 auto;padding:32px">'
    . '<input type="hidden" name="form_slug" value="' . pesc($form['slug']) . '">';
  foreach ($fields as $f) {
    $req = (int)$f['required'] === 1;
    $label = '<label style="display:block;font-size:13px;font-weight:600;margin-bottom:7px">' . pesc($f['label']) . ($req ? ' *' : '') . '</label>';
    if ($f['type'] === 'consent') {
      $out .= '<label style="display:flex;gap:10px;align-items:flex-start;font-size:12.5px;color:oklch(45% 0.02 260);line-height:1.5">'
        . '<input type="checkbox" name="' . pesc($f['field_key']) . '" required value="1" style="margin-top:3px;flex-shrink:0">'
        . 'Ho letto l\'<a href="privacy.html" target="_blank" rel="noopener" style="color:' . FORM_ACCENT . '">informativa privacy</a> e acconsento al trattamento dei miei dati personali per rispondere alla mia richiesta (art. 6 GDPR).</label>';
    } elseif ($f['type'] === 'checkbox') {
      $out .= '<label style="display:flex;gap:10px;align-items:flex-start;font-size:13px;color:oklch(40% 0.02 260)"><input type="checkbox" name="' . pesc($f['field_key']) . '" value="1"' . ($req ? ' required' : '') . '> ' . pesc($f['label']) . '</label>';
    } elseif ($f['type'] === 'select') {
      $out .= '<div>' . $label . '<select name="' . pesc($f['field_key']) . '"' . ($req ? ' required' : '') . ' style="' . $inputStyle . '">';
      foreach (form_select_options($f['options']) as $v => $l) $out .= '<option value="' . pesc($v) . '">' . pesc($l) . '</option>';
      $out .= '</select></div>';
    } elseif ($f['type'] === 'textarea') {
      $out .= '<div>' . $label . '<textarea name="' . pesc($f['field_key']) . '"' . ($req ? ' required' : '') . ' placeholder="' . pesc($f['placeholder']) . '" style="' . $inputStyle . ';min-height:110px;resize:vertical"></textarea></div>';
    } else {
      $type = in_array($f['type'], ['email', 'tel'], true) ? $f['type'] : 'text';
      $out .= '<div>' . $label . '<input type="' . $type . '" name="' . pesc($f['field_key']) . '"' . ($req ? ' required' : '') . ' placeholder="' . pesc($f['placeholder']) . '" style="' . $inputStyle . '"></div>';
    }
  }
  $out .= '<button type="submit" style="margin-top:4px;padding:15px 28px;background:' . FORM_ACCENT . ';color:#fff;border:none;border-radius:100px;font-size:15px;font-weight:700;cursor:pointer;font-family:' . FORM_FONT . '">Invia</button>';
  return $out . '</form>';
}

/* ---------- Elaborazione invio (chiamata da forms.php) ---------- */
function forms_handle_submit($form, array $post) {
  $fields = fields_of_form($form['id']);
  $data = []; $vtIn = [];
  foreach ($fields as $f) {
    $key = $f['field_key'];
    if ($f['type'] === 'consent' || $f['type'] === 'checkbox') {
      $val = isset($post[$key]) ? '1' : '';
      if ((int)$f['required'] === 1 && $val === '') throw new Exception('Devi accettare “' . $f['label'] . '” per procedere.');
    } else {
      $val = trim($post[$key] ?? '');
      if ((int)$f['required'] === 1 && $val === '') throw new Exception('Il campo “' . $f['label'] . '” è obbligatorio.');
      if ($f['type'] === 'email' && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) throw new Exception('Inserisci un indirizzo email valido per “' . $f['label'] . '”.');
    }
    $data[$key] = $val;
    if (!empty($f['vt_map'])) $vtIn[$f['vt_map']] = $val;
  }

  // archivia la submission
  $ip = nc_client_ip();
  $st = db()->prepare('INSERT INTO cms_form_submissions (form_id, data, ip) VALUES (?,?,?)');
  $st->execute([(int)$form['id'], json_encode($data, JSON_UNESCAPED_UNICODE), $ip]);

  require_once __DIR__ . '/mailer.php';
  require_once __DIR__ . '/recipients.php';
  require_once __DIR__ . '/messages.php';
  $senderEmail = (!empty($vtIn['email']) && filter_var($vtIn['email'], FILTER_VALIDATE_EMAIL)) ? $vtIn['email'] : '';
  $hasConsent = (bool) array_filter($fields, fn($f) => $f['type'] === 'consent');

  // allegato dell'auto-risposta (es. PDF da scaricare): percorso relativo alla root del sito
  $attRel = forms_has_extra_cols() ? trim((string)($form['autoreply_attachment'] ?? '')) : '';
  if (strpos($attRel, '..') !== false) $attRel = '';
  $attPath = $attRel !== '' ? dirname(__DIR__) . '/' . $attRel : '';
  $attUrl = $attRel !== '' ? (defined('SITE_DOMAIN') ? rtrim(SITE_DOMAIN, '/') . '/' : '') . str_replace('%2F', '/', rawurlencode($attRel)) : '';

  // segnaposto {{campo}} nei testi, più {{link_allegato}}
  $vars = $data + ['link_allegato' => $attUrl];
  $fill = function ($s) use ($vars) { foreach ($vars as $k => $v) $s = str_replace('{{' . $k . '}}', (string)$v, $s); return $s; };

  // corpo per chi riceve: tutti i campi, la pagina di provenienza e la prova del consenso
  $body = '';
  foreach ($fields as $f) { if ($f['type'] !== 'consent') $body .= $f['label'] . ': ' . ($data[$f['field_key']] !== '' ? $data[$f['field_key']] : '-') . "\n"; }
  $page = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
  if ($page !== '') $body .= "\nPagina: " . $page . "\n";
  if ($attRel !== '' && (int)$form['autoreply_enabled'] === 1) $body .= "Documento inviato al richiedente: " . basename($attRel) . "\n";
  if ($hasConsent) {
    $body .= "\n-- Consenso privacy --\nPrestato: SI\nData/ora: " . date('d/m/Y H:i:s') . "\nIP: " . $ip
      . (defined('CONSENT_TEXT') ? "\nTesto: " . CONSENT_TEXT : '') . "\n";
  }

  // oggetto: con segnaposto {{campo}} si compila; senza, si accoda il nome (comportamento storico)
  $subjectTpl = $form['subject'] ?: 'Nuova richiesta';
  $subject = strpos($subjectTpl, '{{') !== false
    ? $fill($subjectTpl)
    : $subjectTpl . (($vtIn['nome'] ?? '') !== '' ? ' — ' . $vtIn['nome'] : '');

  // destinatari del modulo; vuoto = destinatario predefinito (admin, vedi inc/recipients.php).
  // reply-to = email del mittente, così si risponde direttamente a lui
  $recipients = nc_resolve_recipients($form['recipients'] ?? '');
  $sent = false;
  foreach ($recipients as $to) { if (nc_send_mail($to, $subject, $body, [], $senderEmail ?: null)) $sent = true; }
  if (!$sent) error_log('forms: notifica del modulo "' . $form['slug'] . '" non inviata (destinatari: ' . (implode(', ', $recipients) ?: 'nessuno') . ')');

  // casella Messaggi del pannello (la stessa dei form storici e dell'assistente AI)
  $tipo = $vtIn['tipo'] ?? ($vtIn['telefono'] ?? ($vtIn['azienda'] ?? ''));
  nc_save_message($form['slug'], $vtIn['nome'] ?? '', $senderEmail, $tipo, $body, $ip);

  // consenso GDPR (se il form ha un campo consent)
  if ($hasConsent) nc_log_consent($form['slug'], $vtIn['nome'] ?? '', $senderEmail);

  // auto-risposta al mittente (solo con un'email valida); le risposte tornano al primo destinatario
  $autoreply = (int)$form['autoreply_enabled'] === 1 && $senderEmail !== '';
  if ($autoreply) {
    $subj = $fill($form['autoreply_subject'] ?: 'Abbiamo ricevuto la tua richiesta');
    $txt = $fill($form['autoreply_body'] ?: 'Grazie per averci contattato, ti risponderemo al più presto.');
    $att = [];
    // oltre il tetto (MAIL_ATTACH_MAX, 1,5 MB) il file non si allega: pesa ~3 volte in memoria e
    // gonfia l'email; resta il link, che c'è sempre. Il tetto vale anche coi mailer vecchi senza controllo.
    $attMax = defined('MAIL_ATTACH_MAX') ? (int)MAIL_ATTACH_MAX : 1500000;
    if ($attPath !== '' && is_readable($attPath) && filesize($attPath) <= $attMax) {
      $mime = function_exists('mime_content_type') ? (mime_content_type($attPath) ?: 'application/octet-stream') : 'application/octet-stream';
      $att[] = ['path' => $attPath, 'name' => basename($attRel), 'type' => $mime];
    }
    // un allegato troppo pesante il mailer lo salta: il link deve esserci comunque
    if ($attUrl !== '' && strpos((string)$form['autoreply_body'], '{{link_allegato}}') === false) $txt .= "\n\nPuoi scaricarlo anche qui: " . $attUrl;
    nc_send_mail($senderEmail, $subj, $txt, $att, $recipients[0] ?? null);
  }

  return [
    'success_message' => $fill($form['success_message'] ?: 'Grazie! Ti risponderemo al più presto.'),
    'success_title'   => (forms_has_extra_cols() ? trim((string)($form['success_title'] ?? '')) : '') ?: 'Richiesta inviata',
    'download_url'    => ($autoreply && $attRel !== '') ? $attRel : '',
    'sent'            => $sent,
    // VelociTracker (facoltativo, per-form): lo esegue il chiamante DOPO aver risposto (forms_push_crm)
    'vt'              => ((int)$form['vt_enabled'] === 1 && $senderEmail !== '') ? $vtIn : null,
  ];
}
function forms_push_crm($form, $result) {
  if (empty($result['vt'])) return;
  try { require_once __DIR__ . '/velocitracker.php'; vt_push($form['slug'], $result['vt']); } catch (Throwable $e) {}
}
