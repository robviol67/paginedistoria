<?php
// VelociBuilder LITE — integrazione col CRM VelociTracker.
// Flusso: verifica email su /aziende -> se esiste usa l'anagrafica, altrimenti crea una bozza -> crea la trattativa (opportunità).
require_once __DIR__ . '/settings.php';

function vt_cfg($k, $default = '') { return setting_get($k, $default); }
function vt_enabled() {
  return vt_cfg('vt_enabled') === '1' && trim(vt_cfg('vt_base_url')) !== '' && trim(vt_cfg('vt_token')) !== '';
}

// Chiamata HTTP all'API (bearer). Ritorna ['status','json','error','raw'].
function vt_request($method, $path, $query = [], $body = null) {
  $url = rtrim(vt_cfg('vt_base_url'), '/') . $path;
  if ($query) $url .= '?' . http_build_query($query);
  $headers = ['Authorization: Bearer ' . vt_cfg('vt_token'), 'Accept: application/json'];
  if ($body !== null) $headers[] = 'Content-Type: application/json';
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => $headers,
  ]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
  $raw = curl_exec($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  $json = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
  return ['status' => $status, 'json' => $json, 'error' => $err, 'raw' => $raw];
}

// toglie i campi vuoti/null così non sovrascriviamo con stringhe vuote
function vt_clean(array $a) { return array_filter($a, fn($v) => $v !== '' && $v !== null); }

function vt_split_name($full) {
  $full = trim((string)$full);
  if ($full === '') return ['', ''];
  $p = preg_split('/\s+/', $full, 2);
  return [$p[0], $p[1] ?? ''];
}

function vt_find_azienda_by_email($email) {
  $r = vt_request('GET', '/aziende', ['email' => $email, 'per_page' => 1]);
  $data = $r['json']['data'] ?? [];
  return $data[0] ?? null;
}
function vt_create_azienda(array $fields) { return vt_request('POST', '/aziende', [], vt_clean($fields)); }
function vt_create_trattativa(array $fields) { return vt_request('POST', '/trattative', [], vt_clean($fields)); }

// helper per i menu a tendina del pannello (best-effort)
function vt_stati_anagrafiche() { return vt_request('GET', '/tabelle/stati_anagrafiche')['json']['data'] ?? []; }
function vt_stati_trattative()  { return vt_request('GET', '/tabelle/stati_trattative')['json']['data'] ?? []; }
function vt_pipeline_punti($pid) { return vt_request('GET', '/trattative/punti_pipeline/' . (int)$pid)['json']['data'] ?? []; }
function vt_test_connection() {
  $r = vt_request('GET', '/aziende', ['per_page' => 1]);
  return ['ok' => $r['status'] === 200 && isset($r['json']['data']), 'status' => $r['status'], 'error' => $r['error']];
}

function vt_log(array $row) {
  try {
    $st = db()->prepare('INSERT INTO cms_vt_log (form, email, azienda_id, trattativa_id, created_new, ok, http_status, error) VALUES (?,?,?,?,?,?,?,?)');
    $st->execute([$row['form'] ?? '', $row['email'] ?? '', $row['azienda_id'] ?? null, $row['trattativa_id'] ?? null,
      (int)($row['created_new'] ?? 0), (int)($row['ok'] ?? 0), $row['http_status'] ?? null, $row['error'] ?? null]);
  } catch (Throwable $e) {}
}

// Punto di aggancio dei form: crea/aggiorna l'opportunità sul CRM. NON blocca mai il form (ogni errore è loggato).
// $in: ['email','nome','azienda','telefono','messaggio','tipo','note']
function vt_push($form, array $in) {
  if (!vt_enabled()) return;
  if ($form === 'contatti' && vt_cfg('vt_form_contatti', '1') !== '1') return;
  if ($form === 'guida'    && vt_cfg('vt_form_guida', '1')    !== '1') return;
  $email = trim($in['email'] ?? '');
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

  $log = ['form' => $form, 'email' => $email, 'azienda_id' => null, 'trattativa_id' => null, 'created_new' => 0, 'ok' => 0, 'http_status' => null, 'error' => null];
  try {
    // 1) l'email esiste già su un'anagrafica?
    $az = vt_find_azienda_by_email($email);
    if ($az && !empty($az['id'])) {
      $aziendaId = (int)$az['id'];
    } else {
      // 2) no -> crea una bozza di anagrafica
      [$nome, $cognome] = vt_split_name($in['nome'] ?? '');
      $descr = trim($in['azienda'] ?? '') !== '' ? trim($in['azienda']) : trim($in['nome'] ?? '');
      $r = vt_create_azienda([
        'email' => $email, 'nome' => $nome, 'cognome' => $cognome, 'descrizione' => $descr,
        'telefono' => $in['telefono'] ?? '',
        'note' => 'Bozza da form "' . $form . '" del sito il ' . date('d/m/Y H:i') . '. ' . trim($in['note'] ?? ''),
        'stato_id' => vt_cfg('vt_anag_stato_id'), 'campagna_id' => vt_cfg('vt_campagna_id'),
        'categoria' => vt_cfg('vt_categoria'), 'autore_id' => vt_cfg('vt_autore_id'),
      ]);
      $log['created_new'] = 1; $log['http_status'] = $r['status'];
      $aziendaId = (int)($r['json']['data']['id'] ?? 0);
      if (!$aziendaId) throw new Exception('creazione anagrafica non riuscita (HTTP ' . $r['status'] . ')');
    }
    $log['azienda_id'] = $aziendaId;

    // 3) crea la trattativa (opportunità) collegata all'anagrafica
    $tnome = ($form === 'guida' ? 'Richiesta guida' : 'Contatto dal sito') . ' — ' . (trim($in['nome'] ?? '') ?: $email);
    $t = vt_create_trattativa([
      'azienda_id' => $aziendaId, 'nome' => $tnome, 'opportunita' => vt_cfg('vt_opportunita'),
      'descrizione' => trim($in['messaggio'] ?? '') !== '' ? trim($in['messaggio']) : ($form === 'guida' ? 'Richiesta della guida introduttiva dal sito.' : ''),
      'descrizione_breve' => trim($in['tipo'] ?? '') !== '' ? trim($in['tipo']) : ($form === 'guida' ? 'Guida' : 'Contatto'),
      'pipeline_id' => vt_cfg('vt_pipeline_id', '1'), 'pipeline_punto_id' => vt_cfg('vt_pipeline_punto_id', '1'),
      'stato_id' => vt_cfg('vt_tratt_stato_id'), 'importo' => vt_cfg('vt_importo'),
      'utente_id' => vt_cfg('vt_utente_id'), 'autore_id' => vt_cfg('vt_autore_id'),
      'riferimento' => 'form:' . $form,
    ]);
    $log['http_status'] = $t['status'];
    $log['trattativa_id'] = (int)($t['json']['data']['id'] ?? 0);
    if (!$log['trattativa_id']) throw new Exception('creazione trattativa non riuscita (HTTP ' . $t['status'] . ')');
    $log['ok'] = 1;
  } catch (Throwable $e) {
    $log['error'] = $e->getMessage();
  }
  vt_log($log);
}
