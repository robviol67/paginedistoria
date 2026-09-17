<?php
// VelociBuilder LITE — Assistente AI: ESPORTA il cervello degli agenti dal DB.
//
// È il verso opposto del compilatore: quello che si modifica dal Backoffice vive solo in
// cms_ai_config, e senza questo export la copia versionata sul disco (i .md) invecchia.
//
//   .md  ──tools/ai-seed-build.php──▶ ai-seed.json ──migration/"Ripristina dal seed"──▶ DB
//   DB   ──questo file──▶ ai-seed.json ──tools/ai-seed-explode.php──▶ .md
//
// Produce lo stesso formato di inc/ai-seed.json, così i due versi sono simmetrici.
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/assistente.php';

$agents = [];
foreach (ai_agent_keys() as $key) {
  if ($key === '') continue;
  $sections = [];
  foreach (ai_sections_all($key) as $sec => $content) {
    if ($sec === 'meta') continue; // il meta viaggia a parte, come nel seed
    $sections[$sec] = $content;
  }
  // ordine stabile: prima le sezioni note, poi eventuali extra
  $ordered = [];
  foreach (ai_section_order() as $s) if (isset($sections[$s])) { $ordered[$s] = $sections[$s]; unset($sections[$s]); }
  foreach ($sections as $s => $c) $ordered[$s] = $c;
  $agents[$key] = ['meta' => ai_agent_meta($key), 'sections' => $ordered];
}

// impostazioni globali dell'assistente (senza chiavi API: non escono mai dal server)
$cfg = [];
foreach (['ai_provider', 'ai_voice_enabled', 'ai_consent_required', 'ai_consent_text', 'ai_privacy_url'] as $k) {
  $v = setting_get($k, '');
  if ($v !== '') $cfg[$k] = $v;
}

$json = json_encode(['agents' => $agents, 'config' => $cfg], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

if (isset($_GET['inline'])) { header('Content-Type: application/json; charset=utf-8'); echo $json; exit; }
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="ai-seed-' . date('Ymd-Hi') . '.json"');
header('Content-Length: ' . strlen($json));
echo $json;
