<?php
// VelociBuilder LITE — Assistente AI: proxy chat (server-side). La API key non arriva MAI al browser.
// Endpoint pubblico e anonimo: i tetti anti-abuso stanno in inc/ai-guard.php (per IP,
// per conversazione, per agente e per sito) — senza, chiunque potrebbe spendere token.
require_once __DIR__ . '/../../inc/assistente.php';
require_once __DIR__ . '/../../inc/ai-guard.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'metodo non consentito']); exit; }
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'richiesta non valida']); exit; }

$agent = preg_replace('/[^a-z0-9\-]/', '', (string)($in['agent'] ?? ''));
if (!in_array($agent, ai_agent_keys(), true)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'agente non valido']); exit; }
if (!ai_is_configured()) { echo json_encode(['ok'=>false,'error'=>'Assistente non ancora configurato: inserire la API key nel Backoffice.']); exit; }

// Cronologia: sanifica ruoli e lunghezze, tieni gli ultimi 40 turni.
$hist = [];
foreach ((array)($in['messages'] ?? []) as $m) {
  $role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
  $content = mb_substr(trim((string)($m['content'] ?? '')), 0, 4000);
  if ($content !== '') $hist[] = ['role' => $role, 'content' => $content];
}
if (count($hist) > 40) $hist = array_slice($hist, -40);
if (!$hist) { echo json_encode(['ok'=>false,'error'=>'nessun messaggio']); exit; }

// Tetti anti-abuso + honeypot + dimensione massima della cronologia.
if (!ai_guard_or_fail($agent, 'chat', $in, $hist)) exit;

try {
  $res = ai_chat_structured($agent, $hist);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>'errore interno']); exit;
}
if (empty($res['ok'])) { echo json_encode(['ok'=>false,'error'=>$res['error'] ?? 'errore LLM']); exit; }

echo json_encode([
  'ok'            => true,
  'reply'         => $res['reply'],
  'quick_replies' => $res['quick_replies'],
  'sufficiente'   => $res['sufficiente'],
  'video'         => $res['video'] ?? null,
], JSON_UNESCAPED_UNICODE);
