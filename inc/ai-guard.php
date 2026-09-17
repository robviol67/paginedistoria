<?php
// VelociBuilder LITE — Assistente AI: protezione degli endpoint pubblici.
//
// Gli endpoint api/assistente/*.php sono ANONIMI per natura (il cliente chatta senza
// registrarsi) e ogni chiamata spende token sulla chiave del sito. Senza tetti, uno
// script che martella chat.php produce una bolletta. Qui stanno i limiti:
//   · per IP        — al minuto / all'ora / al giorno
//   · per sessione  — per conversazione (cookie) al giorno + intervallo minimo fra messaggi
//   · per agente    — tetto giornaliero di chiamate LLM
//   · per sito      — tetto giornaliero complessivo (rete di sicurezza sulla spesa)
// più un honeypot e un tetto sulla dimensione della cronologia inviata.
//
// Tutti i valori sono configurabili dal Backoffice (cms_settings, prefisso ai_rl_*).
// Contabilità su cms_ai_usage: una riga per chiamata, con un "costo" (la proposta pesa
// più di un messaggio di chat). Le righe vecchie vengono ripulite da sole.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/consent.php'; // nc_client_ip()

// ---------------------------------------------------------------- configurazione
// Default pensati per un sito vetrina: generosi per una persona vera, stretti per uno script.
function ai_rl_defaults() {
  return [
    'ai_rl_enabled'   => 1,    // 0 = disattiva del tutto i tetti
    'ai_rl_ip_min'    => 10,   // costo max per IP negli ultimi 60 secondi
    'ai_rl_ip_hour'   => 90,   // costo max per IP nell'ultima ora
    'ai_rl_ip_day'    => 250,  // costo max per IP nella giornata
    'ai_rl_sid_day'   => 120,  // costo max per singola conversazione (cookie) nella giornata
    'ai_rl_agent_day' => 600,  // costo max per agente nella giornata
    'ai_rl_site_day'  => 1200, // costo max complessivo del sito nella giornata
    'ai_rl_min_gap'   => 2,    // secondi minimi fra due chiamate della stessa sessione
    'ai_rl_max_chars' => 60000,// dimensione massima della cronologia accettata (caratteri)
  ];
}
function ai_rl_conf($k) {
  $d = ai_rl_defaults();
  $v = setting_get($k, '');
  if ($v === '' || !is_numeric($v)) return (int)($d[$k] ?? 0);
  return (int)$v;
}
// Costo di una chiamata: la proposta è un giro LLM lungo (know-how + tutta la conversazione).
function ai_rl_cost($kind) {
  switch ($kind) {
    case 'proposta': return 4;
    case 'lead':     return 1;
    default:         return 1; // chat
  }
}

// ---------------------------------------------------------------- sessione (cookie)
// Identifica la CONVERSAZIONE, non la persona: cookie tecnico, nessun dato personale.
// Se il client lo cancella restano comunque i tetti per IP.
function ai_sid() {
  static $sid = null;
  if ($sid !== null) return $sid;
  $c = isset($_COOKIE['vb_ai_sid']) ? preg_replace('/[^a-f0-9]/', '', (string)$_COOKIE['vb_ai_sid']) : '';
  if (strlen($c) === 32) { $sid = $c; return $sid; }
  try { $sid = bin2hex(random_bytes(16)); } catch (Throwable $e) { $sid = md5(uniqid('', true)); }
  if (!headers_sent()) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    setcookie('vb_ai_sid', $sid, [
      'expires'  => time() + 86400,
      'path'     => '/',
      'secure'   => $secure,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
  return $sid;
}

// ---------------------------------------------------------------- tabella contatori
// Creazione pigra e idempotente: così la protezione funziona anche su un sito dove la
// migration non è ancora stata rilanciata (l'alternativa — fallire — spegnerebbe la chat).
function ai_rl_table_ready() {
  static $ok = null;
  if ($ok !== null) return $ok;
  try {
    db()->exec('CREATE TABLE IF NOT EXISTS cms_ai_usage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_key VARCHAR(40) NOT NULL DEFAULT "",
        kind      VARCHAR(16) NOT NULL DEFAULT "chat",
        cost      TINYINT UNSIGNED NOT NULL DEFAULT 1,
        ip        VARCHAR(45) NOT NULL DEFAULT "",
        sid       VARCHAR(32) NOT NULL DEFAULT "",
        created_at DATETIME NOT NULL,
        KEY idx_created (created_at),
        KEY idx_ip (ip, created_at),
        KEY idx_sid (sid, created_at),
        KEY idx_agent (agent_key, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $ok = true;
  } catch (Throwable $e) { $ok = false; }
  return $ok;
}

// ---------------------------------------------------------------- controllo
// Ritorna null se la chiamata può procedere, altrimenti
// ['error' => messaggio per il cliente, 'retry' => secondi, 'limit' => quale tetto].
function ai_rl_check($agent, $kind = 'chat') {
  if (!ai_rl_conf('ai_rl_enabled')) return null;
  if (!ai_rl_table_ready()) return null; // DB non disponibile: non bloccare il cliente
  $cost = ai_rl_cost($kind);
  $ip   = substr(nc_client_ip(), 0, 45);
  $sid  = ai_sid();
  try {
    $st = db()->prepare(
      'SELECT
         COALESCE(SUM(CASE WHEN ip = ?  AND created_at >= NOW() - INTERVAL 60 SECOND THEN cost END),0) AS ip_min,
         COALESCE(SUM(CASE WHEN ip = ?  AND created_at >= NOW() - INTERVAL 1 HOUR   THEN cost END),0) AS ip_hour,
         COALESCE(SUM(CASE WHEN ip = ?  AND created_at >= CURDATE()                 THEN cost END),0) AS ip_day,
         COALESCE(SUM(CASE WHEN sid = ? AND created_at >= CURDATE()                 THEN cost END),0) AS sid_day,
         COALESCE(SUM(CASE WHEN agent_key = ? AND created_at >= CURDATE()           THEN cost END),0) AS agent_day,
         COALESCE(SUM(CASE WHEN created_at >= CURDATE()                             THEN cost END),0) AS site_day,
         MAX(CASE WHEN sid = ? THEN created_at END) AS last_sid
       FROM cms_ai_usage
       WHERE created_at >= CURDATE() - INTERVAL 1 DAY'
    );
    $st->execute([$ip, $ip, $ip, $sid, $agent, $sid]);
    $r = $st->fetch();
  } catch (Throwable $e) { return null; }
  if (!$r) return null;

  // intervallo minimo fra due chiamate della stessa conversazione (anti-flood immediato)
  $gap = ai_rl_conf('ai_rl_min_gap');
  if ($gap > 0 && !empty($r['last_sid'])) {
    $elapsed = time() - strtotime($r['last_sid']);
    if ($elapsed >= 0 && $elapsed < $gap) {
      return ['error' => 'Un attimo: stai andando troppo veloce. Riprova fra un istante.', 'retry' => $gap - $elapsed, 'limit' => 'gap'];
    }
  }
  $tetti = [
    ['ip_min',    'ai_rl_ip_min',    60,    'Troppi messaggi in poco tempo. Riprova fra un minuto.'],
    ['ip_hour',   'ai_rl_ip_hour',   3600,  'Hai raggiunto il limite di messaggi di questa ora. Riprova più tardi.'],
    ['ip_day',    'ai_rl_ip_day',    3600,  'Hai raggiunto il limite di messaggi per oggi. Riprova domani o scrivici dal modulo contatti.'],
    ['sid_day',   'ai_rl_sid_day',   3600,  'Questa conversazione ha raggiunto il limite di messaggi. Scrivici dal modulo contatti e ti richiamiamo.'],
    ['agent_day', 'ai_rl_agent_day', 3600,  'L’assistente ha raggiunto il numero massimo di conversazioni per oggi. Riprova domani o scrivici dal modulo contatti.'],
    ['site_day',  'ai_rl_site_day',  3600,  'L’assistente non è disponibile in questo momento. Riprova più tardi o scrivici dal modulo contatti.'],
  ];
  foreach ($tetti as $t) {
    list($campo, $chiave, $retry, $msg) = $t;
    $max = ai_rl_conf($chiave);
    if ($max > 0 && ((int)$r[$campo] + $cost) > $max) {
      return ['error' => $msg, 'retry' => $retry, 'limit' => $campo];
    }
  }
  return null;
}

// Registra la chiamata (da fare SOLO quando si sta per spendere davvero).
function ai_rl_hit($agent, $kind = 'chat') {
  if (!ai_rl_conf('ai_rl_enabled')) return;
  if (!ai_rl_table_ready()) return;
  try {
    $st = db()->prepare('INSERT INTO cms_ai_usage (agent_key, kind, cost, ip, sid, created_at) VALUES (?,?,?,?,?,NOW())');
    $st->execute([(string)$agent, (string)$kind, ai_rl_cost($kind), substr(nc_client_ip(), 0, 45), ai_sid()]);
    // pulizia sporadica: la contabilità serve solo per la giornata corrente
    if (mt_rand(1, 50) === 1) db()->exec('DELETE FROM cms_ai_usage WHERE created_at < NOW() - INTERVAL 3 DAY');
  } catch (Throwable $e) {}
}

// ---------------------------------------------------------------- consenso privacy
// L'assistente raccoglie dati di contatto e archivia l'intera conversazione: serve un
// consenso esplicito, come per qualsiasi altro modulo del sito. Riusiamo il registro
// consensi già presente nel motore (inc/consent.php → tabella gdpr_consent, visibile in
// Backoffice → Consensi), così la prova del consenso sta tutta in un posto solo.
function ai_consent_required() { return setting_get('ai_consent_required', '1') === '1'; }
function ai_privacy_url() {
  $u = trim((string) setting_get('ai_privacy_url', ''));
  if ($u !== '') return $u;
  return defined('PRIVACY_URL') ? PRIVACY_URL : 'privacy.html';
}
function ai_consent_text() {
  $t = trim((string) setting_get('ai_consent_text', ''));
  return $t !== '' ? $t : (defined('CONSENT_TEXT') ? CONSENT_TEXT : 'Acconsento al trattamento dei miei dati personali.');
}
// Registra il consenso prestato in chat. Non deve mai bloccare il salvataggio del lead.
function ai_consent_log($agent, $nome, $email) {
  if (!function_exists('nc_log_consent')) return false;
  return nc_log_consent(substr('assistente-ai:' . $agent, 0, 32), $nome, $email);
}

// ---------------------------------------------------------------- honeypot + dimensione
// Il widget invia sempre il campo 'website' vuoto: se arriva pieno è un bot che compila
// tutto quello che trova. Rispondiamo 200 con un messaggio innocuo, senza chiamare l'LLM.
function ai_rl_honeypot($in) {
  return trim((string)($in['website'] ?? '')) !== '';
}
// Somma dei caratteri della cronologia: evita payload enormi costruiti a mano.
function ai_rl_too_big($messages) {
  $max = ai_rl_conf('ai_rl_max_chars');
  if ($max <= 0) return false;
  $n = 0;
  foreach ((array)$messages as $m) $n += mb_strlen((string)($m['content'] ?? ''));
  return $n > $max;
}

// Applica tutto in un colpo solo agli endpoint pubblici: se ritorna false ha già
// scritto la risposta JSON e l'endpoint deve solo fare `exit`.
function ai_guard_or_fail($agent, $kind, $in, $messages = null) {
  if (ai_rl_honeypot($in)) {
    echo json_encode(['ok' => false, 'error' => 'Richiesta non valida.'], JSON_UNESCAPED_UNICODE);
    return false;
  }
  if ($messages !== null && ai_rl_too_big($messages)) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Conversazione troppo lunga: ricaricala e ricomincia.'], JSON_UNESCAPED_UNICODE);
    return false;
  }
  $rl = ai_rl_check($agent, $kind);
  if ($rl) {
    http_response_code(429);
    header('Retry-After: ' . (int)$rl['retry']);
    echo json_encode(['ok' => false, 'error' => $rl['error'], 'rate_limited' => true], JSON_UNESCAPED_UNICODE);
    return false;
  }
  ai_rl_hit($agent, $kind);
  return true;
}
