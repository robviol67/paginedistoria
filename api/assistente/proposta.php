<?php
// VelociBuilder LITE — Assistente AI: GENERA la proposta (LLM) e la restituisce.
// La persistenza (email + lead) è separata in lead.php per non rigenerare via LLM.
// Endpoint pubblico: vale la stessa protezione della chat (qui il giro LLM è più lungo,
// quindi pesa di più sui tetti — vedi ai_rl_cost()).
require_once __DIR__ . '/../../inc/assistente.php';
require_once __DIR__ . '/../../inc/ai-guard.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'metodo non consentito']); exit; }
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'richiesta non valida']); exit; }

$agent = preg_replace('/[^a-z0-9\-]/', '', (string)($in['agent'] ?? ''));
if (!in_array($agent, ai_agent_keys(), true)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'agente non valido']); exit; }
if (!ai_is_configured()) { echo json_encode(['ok'=>false,'error'=>'Assistente non configurato.']); exit; }

// Cronologia della conversazione.
$hist = [];
foreach ((array)($in['messages'] ?? []) as $m) {
  $role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
  $content = mb_substr(trim((string)($m['content'] ?? '')), 0, 4000);
  if ($content !== '') $hist[] = ['role' => $role, 'content' => $content];
}
if (count($hist) > 40) $hist = array_slice($hist, -40);

// Tetti anti-abuso (la proposta costa più di un messaggio di chat).
if (!ai_guard_or_fail($agent, 'proposta', $in, $hist)) exit;

$meta    = ai_agent_meta($agent);
$knowhow = ai_section($agent, 'knowhow');
$propSpec= ai_section($agent, 'proposta');
$cfg     = ai_agent_config($agent);
$usaCalc = !empty($cfg['strumenti']);
// L'azienda che propone: dai meta dell'agente, altrimenti dal nome del sito. Nessun
// nome cablato nel motore: le regole di dominio stanno nella sezione "proposta" dell'agente.
$azienda = trim((string)($meta['azienda'] ?? '')) ?: (defined('SITE_NAME') ? SITE_NAME : '');

$sys  = "Sei {$meta['name']}, " . ($meta['role'] ?: 'consulente') . ($azienda !== '' ? " di {$azienda}" : '') . ".\n";
$sys .= "Dalla conversazione qui sotto genera una PROPOSTA commerciale. Rispondi SOLO con un JSON valido, senza testo fuori dal JSON, con ESATTAMENTE questi campi:\n";
$sys .= '{ "title": "...", "composizione": "a COSA si riferisce la proposta, es. \"3 multifunzione A4 + 1 multifunzione A3\" (numero e tipo di elementi previsti, non il singolo modello)", '
      . '"deviceTag": "etichetta breve", "deviceName": "prodotto/soluzione principale proposta", "why": "motivazione 1-2 frasi", '
      . '"config": ["punto","punto"], "includes": ["punto","punto"], "formula": "...", '
      . '"steps": [{"n":1,"text":"..."}], '
      . '"alternativa": {"deviceName":"eventuale seconda soluzione da approfondire col consulente","deviceTag":"etichetta breve","why":"perché guardarla, 1 frase, SENZA prezzi"}, '
      . '"tipo_contatto": "cliente", '
      . '"contatti": {"azienda":"","referente":"","email":"","telefono":""}, '
      . '"riepilogo": "riepilogo della chiacchierata in 2-4 frasi, rivolto al cliente (dagli del tu come in chat): cosa ci ha raccontato e cosa abbiamo capito", '
      . '"email_text": "versione testuale completa e ordinata della proposta per email", "sintesi": "una frase per il CRM" }' . "\n";
$sys .= "In \"contatti\" riporta ESATTAMENTE i dati che il cliente ha fornito nella conversazione; lascia vuoto ciò che non ha detto — non inventare. ";
$sys .= "Regole TASSATIVE: usa SOLO prodotti/soluzioni presenti nel KNOW-HOW; non inventare modelli. ";
$sys .= "Compila \"alternativa\" solo se la STRUTTURA PROPOSTA qui sotto lo prevede, e comunque SENZA prezzi: è un approfondimento da vedere col consulente. ";
$sys .= $usaCalc
  ? "Eventuali importi solo come stima indicativa/non vincolante (mai definitivi). "
  : "NON indicare prezzi né importi: proponi la configurazione e lascia la quotazione al consulente. ";
$sys .= "\n\n===== STRUTTURA PROPOSTA (regole di questo agente: seguile alla lettera) =====\n$propSpec\n\n===== KNOW-HOW =====\n$knowhow\n";

$messages = array_merge(
  [['role' => 'system', 'content' => $sys]],
  $hist,
  [['role' => 'user', 'content' => 'Genera adesso la proposta nel formato JSON richiesto.']]
);

try {
  $r = ai_llm_chat_raw($messages, ['json' => true, 'temp' => 0.3, 'timeout' => 60]);
} catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>'errore interno']); exit; }
if (empty($r['ok'])) { echo json_encode(['ok'=>false,'error'=>$r['error'] ?? 'errore LLM']); exit; }

$p = ai_parse_json($r['content']);
if (!is_array($p)) { echo json_encode(['ok'=>false,'error'=>'proposta non interpretabile']); exit; }

// Normalizza i campi attesi dal frontend.
$proposal = [
  'title'      => (string)($p['title'] ?? $meta['name']),
  'composizione' => (string)($p['composizione'] ?? ''),
  'deviceTag'  => (string)($p['deviceTag'] ?? ''),
  'deviceName' => (string)($p['deviceName'] ?? ''),
  'why'        => (string)($p['why'] ?? ''),
  'config'     => array_values(array_filter((array)($p['config'] ?? []), 'is_string')),
  'includes'   => array_values(array_filter((array)($p['includes'] ?? []), 'is_string')),
  'formula'    => (string)($p['formula'] ?? ''),
  'steps'      => array_map(function($s, $i){ return ['n' => (int)($s['n'] ?? $i+1), 'text' => (string)($s['text'] ?? '')]; }, (array)($p['steps'] ?? []), array_keys((array)($p['steps'] ?? []))),
];
// tipo di interlocutore: 'cliente' (utilizzatore finale) o 'rivenditore' (rete indiretta)
$proposal['tipo_contatto'] = (($p['tipo_contatto'] ?? '') === 'rivenditore') ? 'rivenditore' : 'cliente';
// foto del prodotto per la card (mappa 'immagini' dell'agente + auto-match su uploads/)
$proposal['image'] = ai_device_image($agent, $proposal['deviceName']);
// blocco 2: soluzione alternativa da approfondire col consulente (mai prezzi).
// 'xerox' è il vecchio nome del campo: lo accettiamo ancora per non rompere i prompt esistenti.
$x = is_array($p['alternativa'] ?? null) ? $p['alternativa'] : (is_array($p['xerox'] ?? null) ? $p['xerox'] : []);
$xName = trim((string)($x['deviceName'] ?? ''));
if ($xName !== '') {
  $proposal['alternativa'] = [
    'deviceName' => $xName,
    'deviceTag'  => (string)($x['deviceTag'] ?? ''),
    'why'        => (string)($x['why'] ?? ''),
    'image'      => ai_device_image($agent, $xName),
    // titolo del blocco, configurabile per agente (default generico)
    'titolo'     => $cfg['titolo_alternativa'] !== '' ? $cfg['titolo_alternativa'] : 'Da valutare insieme al consulente',
  ];
  $proposal['xerox'] = $proposal['alternativa']; // compat con widget/email non aggiornati
}
$emailText = (string)($p['email_text'] ?? '');
$sintesi   = (string)($p['sintesi'] ?? ($proposal['deviceName'] . ' — ' . $proposal['why']));

// contatti raccolti in chat (l'agente li chiede PRIMA di proporre)
$ct = is_array($p['contatti'] ?? null) ? $p['contatti'] : [];
$contatti = [
  'azienda'   => mb_substr(trim((string)($ct['azienda']   ?? '')), 0, 180),
  'referente' => mb_substr(trim((string)($ct['referente'] ?? '')), 0, 180),
  'email'     => filter_var(trim((string)($ct['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
  'telefono'  => mb_substr(trim((string)($ct['telefono']  ?? '')), 0, 60),
];

echo json_encode([
  'ok'         => true,
  'proposal'   => $proposal,
  'email_text' => $emailText,
  'sintesi'    => $sintesi,
  'riepilogo'  => mb_substr(trim((string)($p['riepilogo'] ?? '')), 0, 2000),
  'contatti'   => $contatti,
  'campionario'=> ai_campionario($agent),
  'campionario_meta' => ai_campionario_meta($agent), // titolo/testo della galleria (per-agente)
], JSON_UNESCAPED_UNICODE);
