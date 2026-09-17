<?php
// VelociBuilder LITE — Assistente AI: persiste una proposta già generata → lead + email + CRM.
// Nessuna chiamata LLM qui: riceve la proposta dal client e la salva/inoltra.
require_once __DIR__ . '/../../inc/assistente.php';
require_once __DIR__ . '/../../inc/mailer.php';    // nc_send_mail, MAIL_FROM/MAIL_REPLYTO
require_once __DIR__ . '/../../inc/messages.php';  // nc_save_message
require_once __DIR__ . '/../../inc/consent.php';   // nc_client_ip, nc_log_consent
require_once __DIR__ . '/../../inc/ai-guard.php';  // tetti anti-abuso + consenso
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'metodo non consentito']); exit; }
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'richiesta non valida']); exit; }

$agent = preg_replace('/[^a-z0-9\-]/', '', (string)($in['agent'] ?? ''));
if (!in_array($agent, ai_agent_keys(), true)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'agente non valido']); exit; }
$meta = ai_agent_meta($agent);

// 'sendClient' = invia la proposta anche al cliente (bottone "Invia via email").
$sendClient = !empty($in['send_client']);

$ct = (array)($in['contatti'] ?? []);
$azienda   = mb_substr(trim((string)($ct['azienda']   ?? '')), 0, 180);
$referente = mb_substr(trim((string)($ct['referente'] ?? '')), 0, 180);
$email     = filter_var(trim((string)($ct['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
$telefono  = mb_substr(trim((string)($ct['telefono']  ?? '')), 0, 60);

// Tetti anti-abuso (qui non c'è LLM, ma c'è invio email e scrittura su DB).
if (!ai_guard_or_fail($agent, 'lead', $in)) exit;

// CONSENSO PRIVACY — obbligatorio prima di archiviare contatti e conversazione.
$consenso = !empty($in['consenso']);
if (ai_consent_required() && !$consenso) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'need_consent'=>true, 'error'=>'Per registrare la richiesta serve il consenso al trattamento dei dati.'], JSON_UNESCAPED_UNICODE);
  exit;
}

$proposal  = is_array($in['proposal'] ?? null) ? $in['proposal'] : [];
$emailText = mb_substr(trim((string)($in['email_text'] ?? '')), 0, 12000);
$sintesi   = mb_substr(trim((string)($in['sintesi'] ?? '')), 0, 500);
$riepilogo = mb_substr(trim((string)($in['riepilogo'] ?? '')), 0, 2000);
$transcript= mb_substr(trim((string)($in['transcript'] ?? '')), 0, 24000);
if (!$emailText && $proposal) $emailText = ($proposal['deviceName'] ?? '') . "\n\n" . ($proposal['why'] ?? '');

// 1) Salva il lead.
$leadId = 0;
try {
  $st = db()->prepare('INSERT INTO cms_ai_leads (agent_key, azienda, referente, email, telefono, proposal_json, transcript, created_at) VALUES (?,?,?,?,?,?,?,NOW())');
  $st->execute([$agent, $azienda, $referente, $email, $telefono, json_encode($proposal, JSON_UNESCAPED_UNICODE), $transcript]);
  $leadId = (int) db()->lastInsertId();
} catch (Throwable $e) {}

// 1-bis) Consenso nel registro GDPR (data, ora, IP, testo del consenso).
if ($consenso) { try { ai_consent_log($agent, $referente ?: $azienda, $email); } catch (Throwable $e) {} }

// 2) Inbox Messaggi.
try { nc_save_message('assistente-ai', $referente ?: $azienda, $email, 'Proposta ' . $meta['name'], $emailText ?: $sintesi, nc_client_ip()); } catch (Throwable $e) {}

// 3) Email: notifica interna sempre; al cliente solo se richiesto e con email valida.
// INSTRADAMENTO: un agente può avere più tipi di interlocutore (es. cliente / rivenditore /
// candidatura) e per ciascuno un destinatario interno, un oggetto e una nota diversi. È
// configurazione per-agente (config → instradamenti), non codice: vedi ai_agent_config().
$agCfg  = ai_agent_config($agent);
$tipo   = trim((string)($proposal['tipo_contatto'] ?? 'cliente'));
$rotta  = is_array($agCfg['instradamenti'][$tipo] ?? null) ? $agCfg['instradamenti'][$tipo] : [];
$sito   = defined('SITE_NAME') ? SITE_NAME : '';
$suffix = $sito !== '' ? ' — ' . $sito : '';
$subject = trim((string)($rotta['oggetto'] ?? '')) !== ''
  ? $rotta['oggetto'] . $suffix
  : 'Proposta ' . $meta['name'] . $suffix;
$nota = trim((string)($rotta['nota'] ?? ''));
$intern  = ($nota !== '' ? '⚑ ' . $nota . "\n\n" : "Nuovo lead dall'Assistente AI ({$meta['name']}).\n\n")
  . "Azienda: {$azienda}\nReferente: {$referente}\nEmail: {$email}\nTelefono: {$telefono}\n\n"
  . ($riepilogo !== '' ? "--- Riepilogo della conversazione ---\n{$riepilogo}\n\n" : '')
  . '--- ' . (trim((string)($rotta['etichetta'] ?? '')) ?: 'Proposta') . " ---\n" . ($emailText ?: $sintesi)
  . ($transcript !== '' ? "\n\n--- Conversazione completa ---\n{$transcript}" : '');
$emailedClient = false;
try {
  // destinatari interni: prima l'instradamento (es. la responsabile della rete indiretta),
  // poi l'agente, infine il destinatario predefinito del sito (admin, vedi inc/recipients.php).
  // Ogni livello accetta più indirizzi separati da virgola.
  require_once __DIR__ . '/../../inc/recipients.php';
  $notify = [];
  foreach ([(string)($rotta['email'] ?? ''), (string)$agCfg['email_notifiche']] as $lvl) {
    foreach (preg_split('/[,;\s]+/', $lvl) as $a) if (filter_var(trim($a), FILTER_VALIDATE_EMAIL)) $notify[] = trim($a);
    if ($notify) break;
  }
  if (!$notify) $notify = nc_resolve_recipients('');
  foreach (array_unique($notify) as $to) nc_send_mail($to, $subject . ' (lead)', $intern, [], $email ?: null);
  if ($sendClient && $email) {
    // stesso layout della proposta online: HTML con CSS inline dal template dell'agente
    $html = ai_email_render($agent, $proposal, [
      'intro'    => $riepilogo !== '' ? $riepilogo : $sintesi,
      'contatti' => ['azienda' => $azienda, 'referente' => $referente, 'email' => $email, 'telefono' => $telefono],
      'rotta'    => $rotta, // chi ricontatta e con quale invito (blocco "Prossimo contatto")
    ]);
    nc_send_mail($email, $subject, $emailText ?: $sintesi, [], ($notify[0] ?? (defined('MAIL_REPLYTO') ? MAIL_REPLYTO : null)), $html); // il cliente risponde a chi ha ricevuto il lead
    $emailedClient = true;
  }
} catch (Throwable $e) {}

// 4) CRM VelociTracker (best-effort).
if ($email) {
  try {
    require_once __DIR__ . '/../../inc/velocitracker.php';
    vt_push('assistente-ai', ['email'=>$email, 'nome'=>$referente, 'azienda'=>$azienda, 'telefono'=>$telefono, 'messaggio'=>$sintesi ?: mb_substr($emailText,0,400), 'tipo'=>(trim((string)($rotta['etichetta'] ?? '')) ?: 'Proposta AI ' . $meta['name'])]);
  } catch (Throwable $e) {}
}

echo json_encode(['ok'=>true, 'lead_id'=>$leadId, 'emailed_client'=>$emailedClient], JSON_UNESCAPED_UNICODE);
