<?php
// VelociBuilder LITE — Assistente AI: config pubblica per il widget cliente.
// Espone SOLO i metadati agente + flag (mai la API key).
require_once __DIR__ . '/../../inc/assistente.php';
require_once __DIR__ . '/../../inc/ai-guard.php'; // consenso privacy (testo + link informativa)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cfg = ai_api_config();
$agents = [];
foreach (ai_agent_keys() as $k) {
  if ($k === '') continue;
  $m = ai_agent_meta($k);
  $agents[] = [
    'key'    => $k,
    'name'   => $m['name']   ?? $k,
    'branch' => $m['branch'] ?? '',
    'role'   => $m['role']   ?? '',
    'accent' => $m['accent'] ?? '#da291c',
    'avatar' => $m['avatar'] ?? '',
    'tone'   => array_values((array)($m['tone'] ?? [])),
    'voce'   => ai_agent_voice($k),
    'desc'   => (string)($m['desc'] ?? ''),
  ];
}
echo json_encode([
  'ok'         => true,
  'agents'     => $agents,
  'voice'      => $cfg['voice'],
  'provider'   => $cfg['provider'],
  'configured' => ai_is_configured(),
  // consenso privacy: il widget lo chiede prima di generare/registrare la proposta
  'consenso'   => [
    'richiesto'   => ai_consent_required(),
    'testo'       => ai_consent_text(),
    'privacy_url' => ai_privacy_url(),
  ],
], JSON_UNESCAPED_UNICODE);
