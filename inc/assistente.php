<?php
// VelociBuilder LITE — Assistente Commerciale AI.
// Helper generici (motore): config agenti da DB, system prompt, client LLM, calcolatore.
// Nessun contenuto per-sito qui: persona/know-how stanno in cms_ai_config (seed da ai-seed.json).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ai-tools.php'; // registro degli strumenti deterministici

// ---------------------------------------------------------------- config agenti (DB)
function ai_agent_keys() {
  try {
    $rows = db()->query("SELECT DISTINCT agent_key FROM cms_ai_config ORDER BY agent_key")->fetchAll();
    return array_column($rows, 'agent_key');
  } catch (Throwable $e) { return []; }
}
function ai_section($agent, $section, $default = '') {
  $st = db()->prepare('SELECT content FROM cms_ai_config WHERE agent_key = ? AND section = ? LIMIT 1');
  $st->execute([$agent, $section]);
  $v = $st->fetchColumn();
  return $v === false ? $default : $v;
}
function ai_sections_all($agent) {
  $st = db()->prepare('SELECT section, content FROM cms_ai_config WHERE agent_key = ?');
  $st->execute([$agent]);
  $out = [];
  foreach ($st->fetchAll() as $r) $out[$r['section']] = $r['content'];
  return $out;
}
function ai_save_section($agent, $section, $content) {
  $st = db()->prepare('INSERT INTO cms_ai_config (agent_key, section, content, updated_at) VALUES (?,?,?,NOW())
                       ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = NOW()');
  $st->execute([$agent, $section, (string)$content]);
}
function ai_agent_meta($agent) {
  $raw = ai_section($agent, 'meta', '');
  $m = $raw ? json_decode($raw, true) : null;
  return is_array($m) ? $m : ['name' => $agent, 'branch' => '', 'role' => '', 'accent' => '#da291c', 'avatar' => '', 'tone' => []];
}
// Valore "di fabbrica" di una sezione dal seed per-sito (inc/ai-seed.json), o null se assente.
// Usato dal backoffice per "Ripristina dal seed" (D · re-sync .md/seed → DB).
function ai_seed_section_value($agent, $section) {
  $f = __DIR__ . '/ai-seed.json';
  if (!is_file($f)) return null;
  $seed = json_decode((string) file_get_contents($f), true);
  $v = $seed['agents'][$agent]['sections'][$section] ?? null;
  return is_string($v) ? $v : null;
}
// Stima approssimata dei token di un testo (IT ~3.8 char/token). Solo indicativa (per il backoffice).
function ai_estimate_tokens($text) { return (int) ceil(mb_strlen((string)$text) / 3.8); }
// Dimensione del "cervello" iniettato nel system prompt (persona+metodo+intervista+knowhow).
function ai_prompt_brain_tokens($agent) {
  $n = 0;
  foreach (['persona', 'metodo', 'intervista', 'knowhow'] as $s) $n += ai_estimate_tokens(ai_section($agent, $s, ''));
  return $n;
}

// ---------------------------------------------------------------- video in conversazione
// Sezione 'video': mappa JSON chiave => "URL/ID YouTube" oppure {"youtube":"...","titolo":"..."}.
// L'agente può proporre SOLO una di queste chiavi: l'ID reale lo risolve il server
// (nessun rischio che il modello si inventi un video).
function ai_youtube_id($u) {
  $u = trim((string)$u);
  if ($u === '') return '';
  if (preg_match('~(?:youtu\.be/|v=|embed/|shorts/)([A-Za-z0-9_-]{6,})~', $u, $m)) return $m[1];
  if (preg_match('~^[A-Za-z0-9_-]{6,}$~', $u)) return $u; // già un ID
  return '';
}
function ai_video_map($agent) {
  $raw = ai_section($agent, 'video', '');
  $m = $raw ? json_decode($raw, true) : null;
  $out = [];
  if (is_array($m)) foreach ($m as $k => $v) {
    $k = trim((string)$k); if ($k === '' || $k[0] === '_') continue;
    $src = is_array($v) ? ($v['youtube'] ?? '') : $v;
    $id  = ai_youtube_id($src);
    if ($id === '') continue;
    $out[$k] = ['id' => $id, 'titolo' => is_array($v) ? (string)($v['titolo'] ?? $k) : $k];
  }
  return $out;
}

// Campionario: galleria di esempi di lavori realizzabili, usata come contropartita
// concreta nella proposta e nell'email. Sta nella sezione 'immagini' sotto la chiave
// speciale "_campionario": ["uploads/a.jpg", "uploads/b.jpg", …].
function ai_campionario($agent) {
  $map = json_decode(ai_section($agent, 'immagini', ''), true);
  $list = is_array($map) ? ($map['_campionario'] ?? []) : [];
  $out = [];
  foreach ((array)$list as $p) { $p = trim((string)$p); if ($p !== '') $out[] = $p; }
  return $out;
}
// Titolo e testo della galleria: personalizzabili con le chiavi speciali
// "_campionario_titolo" e "_campionario_testo" nella stessa sezione 'immagini'.
function ai_campionario_meta($agent) {
  $map = json_decode(ai_section($agent, 'immagini', ''), true);
  $t = is_array($map) ? trim((string)($map['_campionario_titolo'] ?? '')) : '';
  $d = is_array($map) ? trim((string)($map['_campionario_testo']  ?? '')) : '';
  return [
    'titolo' => $t !== '' ? $t : 'Esempi di lavori realizzabili',
    'testo'  => $d !== '' ? $d : 'Alcuni esempi di ciò che si può realizzare con queste soluzioni. Chiedi al consulente il campionario completo.',
  ];
}

// ---------------------------------------------------------------- config API (settings)
// Config PER-PROVIDER: ogni provider ha la sua chiave/modello/endpoint/temperatura
// (chiavi cms_settings ai_key_{p}, ai_model_{p}, ai_endpoint_{p}, ai_temp_{p}).
// ai_provider = provider ATTIVO usato dagli agenti. Retro-compat con le vecchie chiavi
// globali (ai_api_key/ai_model/ai_base_url/ai_temp) per il provider attivo.
function ai_all_providers() { return ['openai', 'claude', 'gemini', 'deepseek', 'lmstudio', 'ollama']; }
function ai_provider_labels() { return ['openai'=>'OpenAI', 'claude'=>'Anthropic Claude', 'gemini'=>'Google Gemini', 'deepseek'=>'DeepSeek', 'lmstudio'=>'LM Studio (locale)', 'ollama'=>'Ollama (locale)']; }
function ai_provider_is_local($p) { return in_array($p, ['lmstudio', 'ollama'], true); }
function ai_active_provider() { return setting_get('ai_provider', 'openai'); }
// Le vecchie chiavi globali (ai_api_key/ai_model/ai_base_url) erano di OpenAI:
// il fallback legacy vale SOLO per 'openai' (mai per un altro provider attivo).
function ai_provider_key($p) {
  $k = setting_get('ai_key_' . $p, '');
  if ($k === '' && $p === 'openai') $k = setting_get('ai_api_key', '');
  return $k;
}
function ai_provider_model($p) {
  $m = setting_get('ai_model_' . $p, '');
  if ($m === '' && $p === 'openai') $m = setting_get('ai_model', '');
  return $m;
}
function ai_provider_endpoint($p) {
  $e = setting_get('ai_endpoint_' . $p, '');
  if ($e === '' && $p === 'openai') $e = setting_get('ai_base_url', '');
  return $e;
}
function ai_provider_temp($p) {
  $v = setting_get('ai_temp_' . $p, '');
  return $v !== '' ? (float)$v : (float) setting_get('ai_temp', '0.4');
}
function ai_provider_conn($p) { return ['model' => ai_provider_model($p), 'api_key' => ai_provider_key($p), 'endpoint' => ai_provider_endpoint($p)]; }
function ai_provider_configured($p) { return ai_provider_is_local($p) ? (ai_provider_endpoint($p) !== '') : (ai_provider_key($p) !== ''); }

function ai_api_config() {
  $p = ai_active_provider();
  return [
    'provider' => $p,
    'model'    => ai_provider_model($p) ?: ($p === 'openai' ? 'gpt-4o' : ''),
    'base_url' => ai_provider_endpoint($p),
    'temp'     => ai_provider_temp($p),
    'key'      => ai_provider_key($p),
    'voice'    => setting_get('ai_voice_enabled', '1') === '1',
  ];
}
function ai_is_configured() { return ai_provider_configured(ai_active_provider()); }

// ---------------------------------------------------------------- catalogo modelli (curato per provider)
// Come BookBuilder: elenco curato per provider + opzione "Altro (digita)" per casi non in lista.
// Niente fetch live (fragile): il menu funziona sempre, e il custom copre qualsiasi modello.
function ai_model_catalog() {
  return [
    'claude'   => [
      ['id'=>'claude-opus-4-8',           'label'=>'Opus 4.8 (qualità top)'],
      ['id'=>'claude-sonnet-5',           'label'=>'Sonnet 5 (equilibrio)'],
      ['id'=>'claude-haiku-4-5-20251001', 'label'=>'Haiku 4.5 (economico)'],
    ],
    'openai'   => [
      ['id'=>'gpt-4o',       'label'=>'GPT-4o (equilibrio)'],
      ['id'=>'gpt-4o-mini',  'label'=>'GPT-4o mini (economico)'],
      ['id'=>'gpt-4.1',      'label'=>'GPT-4.1'],
      ['id'=>'gpt-4.1-mini', 'label'=>'GPT-4.1 mini'],
      ['id'=>'o3',           'label'=>'o3 (ragionamento)'],
    ],
    'gemini'   => [
      ['id'=>'gemini-2.5-flash', 'label'=>'2.5 Flash (economico)'],
      ['id'=>'gemini-2.5-pro',   'label'=>'2.5 Pro (qualità)'],
      ['id'=>'gemini-2.0-flash', 'label'=>'2.0 Flash'],
    ],
    'deepseek' => [
      ['id'=>'deepseek-chat',     'label'=>'Chat'],
      ['id'=>'deepseek-reasoner', 'label'=>'Reasoner (ragionamento)'],
    ],
    'lmstudio' => [], // locale: nessun catalogo, solo testo libero
    'ollama'   => [], // locale: nessun catalogo, solo testo libero
  ];
}
// Menu a tendina del modello: <select> curato + input "Altro" nascosto + hidden col valore reale.
// $provider valorizzato → opzioni di quel provider; null → tutti in <optgroup>.
function ai_model_select($fieldId, $name, $current, $provider = null, $opts = []) {
  $cat = ai_model_catalog();
  $emptyLabel = $opts['empty_label'] ?? '— predefinito —';
  $h = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES); };
  $o = '<option value="">' . $h($emptyLabel) . '</option>';
  $render = function($models) use (&$o, $h) {
    foreach ($models as $m) $o .= '<option value="' . $h($m['id']) . '">' . $h($m['label']) . ' · ' . $h($m['id']) . '</option>';
  };
  if ($provider !== null) { $render($cat[$provider] ?? []); }
  else { foreach ($cat as $prov => $models) { if (!$models) continue; $o .= '<optgroup label="' . $h(ucfirst($prov)) . '">'; $render($models); $o .= '</optgroup>'; } }
  $o .= '<option value="__custom__">✏️ Altro (digita)</option>';
  return
      '<select class="ai-model-sel" data-field="' . $h($fieldId) . '" id="' . $h($fieldId) . '__sel" onchange="aiModelSel(this.dataset.field)">' . $o . '</select>'
    . '<input type="text" class="ai-model-custom" id="' . $h($fieldId) . '__custom" placeholder="nome modello…" style="display:none;margin-top:6px">'
    . '<input type="hidden" name="' . $h($name) . '" id="' . $h($fieldId) . '" value="' . $h($current) . '">';
}
// JS condiviso per ai_model_select(): sincronizza select ↔ input custom ↔ hidden.
function ai_model_select_js() {
  return <<<'HTML'
<script>
function aiModelSel(id){
  var sel=document.getElementById(id+'__sel'),cu=document.getElementById(id+'__custom'),hi=document.getElementById(id);
  if(!sel||!hi)return;
  if(sel.value==='__custom__'){cu.style.display='';hi.value=cu.value;}else{cu.style.display='none';hi.value=sel.value;}
}
document.querySelectorAll('select.ai-model-sel').forEach(function(sel){
  var id=sel.dataset.field,hi=document.getElementById(id),cu=document.getElementById(id+'__custom');
  if(!hi)return;var v=hi.value,found=false;
  Array.prototype.forEach.call(sel.options,function(o){if(o.value===v)found=true;});
  if(v&&!found){sel.value='__custom__';cu.style.display='';cu.value=v;}else{sel.value=v;}
  cu.addEventListener('input',function(){if(sel.value==='__custom__')hi.value=cu.value;});
});
</script>
HTML;
}

// ---------------------------------------------------------------- config per-agente (amichevole + retro-compat)
// Il config è salvato come JSON nella sezione 'config'. Qui lo decodifichiamo con default
// e mappiamo le vecchie chiavi (usa_calcolatore -> strumento_calcolatore, modello_override -> modello).
// Dati di contatto che si possono rendere obbligatori prima della stima/proposta.
function ai_contact_fields() {
  return ['azienda' => 'Nome azienda', 'referente' => 'Referente', 'email' => 'Email', 'telefono' => 'Cellulare / telefono'];
}
function ai_agent_config($agent) {
  $raw = ai_section($agent, 'config', '');
  $c = $raw ? json_decode($raw, true) : null;
  if (!is_array($c)) $c = [];
  // dati obbligatori: se non configurati, default azienda+referente+email (telefono facoltativo)
  $req = $c['dati_obbligatori'] ?? null;
  if (!is_array($req)) $req = ['azienda', 'referente', 'email'];
  $req = array_values(array_intersect($req, array_keys(ai_contact_fields())));
  // strumenti deterministici abilitati; retro-compat col vecchio flag booleano del calcolatore
  $tools = $c['strumenti'] ?? null;
  if (!is_array($tools)) {
    $tools = (!empty($c['strumento_calcolatore']) || !empty($c['usa_calcolatore'])) ? ['canone'] : [];
  }
  $tools = array_values(array_filter(array_map('strval', $tools)));
  // Instradamenti: come trattare un interlocutore di un certo tipo (chi lo ricontatta, a
  // quale indirizzo interno, con quale oggetto). Vuoto = comportamento standard.
  // Forma: {"rivenditore": {"etichetta":"…","contatto":"…","email":"…","oggetto":"…","nota":"…","extra":"…"}}
  $instr = is_array($c['instradamenti'] ?? null) ? $c['instradamenti'] : [];
  return [
    'dati_obbligatori'      => $req,
    'strumenti'             => $tools,
    'instradamenti'         => $instr,
    'titolo_alternativa'    => trim((string)($c['titolo_alternativa'] ?? '')),
    'email_notifiche'       => trim((string)($c['email_notifiche'] ?? '')),
    'modello'               => (string)($c['modello'] ?? $c['modello_override'] ?? ''),
    'temperatura'           => isset($c['temperatura']) ? (float)$c['temperatura'] : null, // null = usa globale
    'suggerisci_domande'    => array_key_exists('suggerisci_domande', $c) ? !empty($c['suggerisci_domande']) : true,
    'voce'                  => array_key_exists('voce', $c) ? !empty($c['voce']) : null,    // null = usa globale
    'messaggio_apertura'    => (string)($c['messaggio_apertura'] ?? ''),
    'strumento_calcolatore' => in_array('canone', $tools, true), // legacy, per il vecchio codice
  ];
}
// Voce effettiva per un agente (per-agente se impostata, altrimenti flag globale).
function ai_agent_voice($agent) {
  $v = ai_agent_config($agent)['voce'];
  return $v === null ? ai_api_config()['voice'] : $v;
}

// ---------------------------------------------------------------- system prompt
function ai_build_system_prompt($agent) {
  $meta = ai_agent_meta($agent);
  $persona    = ai_section($agent, 'persona');
  $metodo     = ai_section($agent, 'metodo');
  $intervista = ai_section($agent, 'intervista');
  $knowhow    = ai_section($agent, 'knowhow');
  $cfg = ai_agent_config($agent);
  $tools       = ai_tools_prompt_block($agent);   // strumenti deterministici abilitati
  $usaCalc     = $tools['campi'] !== '';
  $suggerisci  = $cfg['suggerisci_domande'];
  $apertura    = $cfg['messaggio_apertura'];

  $out  = "Sei {$meta['name']}" . ($meta['role'] ? ", " . $meta['role'] : '') . ".\n";
  $out .= "Parli in italiano. Conduci una conversazione reale seguendo la persona e il metodo qui sotto.\n\n";
  $out .= "===== PERSONA =====\n$persona\n\n";
  $out .= "===== METODO =====\n$metodo\n\n";
  $out .= "===== INTERVISTA (checklist nascosta) =====\n$intervista\n\n";
  $out .= "===== KNOW-HOW (unica fonte per ciò che proponi) =====\n$knowhow\n\n";
  $out .= "===== REGOLE DI OUTPUT (TASSATIVE) =====\n";
  $out .= "1. Rispondi SEMPRE e SOLO con un oggetto JSON valido, senza testo fuori dal JSON, con questa forma:\n";
  $out .= "{\n";
  $out .= '  "reply": "il tuo messaggio al cliente (naturale, breve, come da persona)",' . "\n";
  $out .= '  "quick_replies": [],' . "\n";
  $out .= '  "sufficiente": false,' . "  // true quando hai raccolto abbastanza per la proposta\n";
  // Campi degli strumenti deterministici (uno per strumento abilitato). Il modello li
  // POPOLA per invocare lo strumento, ma il risultato lo produce sempre il server.
  $out .= $usaCalc ? $tools['campi'] : '  "calc": null' . "  // per questo agente resta sempre null: NON dai prezzi\n";
  $out .= "}\n";
  $out .= "1-bis. Nel JSON usa SOLO virgolette dritte (\"): con quelle curve (“ ”) il JSON non si legge e il cliente vede l'impalcatura invece della tua risposta.\n";
  $out .= "2. Una sola domanda per messaggio. Non inventare mai prezzi, modelli o dati fuori dal KNOW-HOW.\n";
  $lbl = ai_contact_fields();
  $reqList = [];
  foreach ($cfg['dati_obbligatori'] as $k) if (isset($lbl[$k])) $reqList[] = $lbl[$k];
  if ($reqList) {
    $out .= "3. ⛔ NESSUNA CIFRA PRIMA DEI DATI RICHIESTI. Non fornire stime, canoni, prezzi, importi né \"ordini di grandezza\" finché il cliente non ti ha lasciato: **" . implode(', ', $reqList) . "**. Chiedi SOLO questi dati (non altri): se te ne mancano, chiedili uno alla volta spiegando che servono per la stima e per farlo ricontattare. Vale anche se il cliente insiste.\n";
  } else {
    $out .= "3. Non è richiesto alcun dato di contatto obbligatorio prima della stima; raccoglilo comunque con garbo verso la fine.\n";
  }
  if ($usaCalc) {
    $out .= "4. STRUMENTI (li esegue il sistema, non tu). Finché mancano i dati richiesti i campi degli strumenti restano null.\n" . $tools['regole'];
  } else {
    $out .= "4. NON dare prezzi né importi: proponi la configurazione giusta e lascia la quotazione al consulente/referente.\n";
  }
  if ($suggerisci) {
    $out .= "5. In \"quick_replies\" metti fino a 4 risposte rapide brevi e pertinenti (opzioni plausibili per il cliente). Può essere [] quando non ha senso.\n";
  } else {
    $out .= "5. \"quick_replies\" deve essere SEMPRE [] (per questo agente le risposte rapide sono disattivate).\n";
  }
  $vids = ai_video_map($agent);
  if ($vids) {
    $out .= "6. VIDEO: puoi mostrare un breve video di presentazione aggiungendo al JSON il campo \"video\" con UNA di queste chiavi esatte: "
          . implode(' | ', array_keys($vids)) . ". Usalo SOLO dopo aver chiesto al cliente se vuole approfondire e aver ricevuto un sì. "
          . "Nel \"reply\" introducilo a parole (es. \"Ti faccio vedere questo video di pochi minuti, poi dimmi se ti sembra la soluzione giusta\") e nel turno successivo chiedigli cosa ne pensa. "
          . "⛔ NON scrivere MAI il video dentro il testo del \"reply\" come link, titolo o segnaposto tra parentesi quadre (es. \"[video di presentazione ...]\" o \"Ecco il video: [...]\"): l'UNICO modo per mostrarlo è il campo JSON \"video\" con la chiave. Il player compare da solo. "
          . "Se non serve, ometti il campo o mettilo a null. Non inventare mai link o titoli: usa solo le chiavi elencate.\n";
  }
  if ($apertura !== '') {
    $out .= "7. Il tuo primissimo messaggio della conversazione deve essere esattamente: \"" . str_replace('"', "'", $apertura) . "\" — anche in questo turno però popola \"quick_replies\" con le opzioni di risposta corrispondenti alla domanda posta.\n";
  }
  return $out;
}

// ---------------------------------------------------------------- calcolatore noleggio (strumento deterministico)
// Motore di calcolo del listino a scaglioni: gli SCAGLIONI non stanno più qui dentro, si
// configurano (cms_settings 'ai_tool_canone_listino' — vedi ai_canone_listino()), così lo
// stesso strumento serve qualunque listino. Default verificato: 2A4/1A3/3000/1000 = 170.
function ai_stima_canone($n4, $n3, $vbn, $vcol) {
  $n4 = (int)$n4; $n3 = (int)$n3; $vbn = (int)$vbn; $vcol = (int)$vcol;
  if ($n4 < 0 || $n3 < 0) return null;
  $L = ai_canone_listino();
  $maxTipo = (int)($L['max_per_tipo'] ?? 5);
  if ($maxTipo > 0 && ($n4 > $maxTipo || $n3 > $maxTipo)) return ['adhoc' => true]; // oltre: analisi del parco → consulente
  $tier = function($t, $v) { foreach ($t as $row) { if ($v <= $row[0]) return $row[1]; } return $t[count($t)-1][1]; };
  // Canone di una flotta a due formati, coi volumi ripartiti per numero di dispositivi
  // (riproduce noleggio.html/Excel). $fmtA = formato dei dispositivi "n4", $fmtB quello di "n3".
  $calc = function($n4, $n3, $vbn, $vcol, $fmtA, $fmtB) use ($tier) {
    $tot = $n4 + $n3;
    $cDev = $n4 * $tier($fmtA['rata'], $n4) + $n3 * $tier($fmtB['rata'], $n3);
    $s4 = $tot ? $n4 / $tot : 0; $s3 = $tot ? $n3 / $tot : 0;
    $bn4 = $vbn * $s4; $bn3 = $vbn * $s3; $col4 = $vcol * $s4; $col3 = $vcol * $s3;
    $cBn  = $tot ? round($bn4 * $tier($fmtA['bn'], $bn4)  + $bn3 * $tier($fmtB['bn'], $bn3))   : 0;
    $cCol = $tot ? round($col4 * $tier($fmtA['col'], $col4) + $col3 * $tier($fmtB['col'], $col3)) : 0;
    return ['canone' => (int) round($cDev + $cBn + $cCol), 'dispositivi' => (int)$cDev, 'bn' => (int)$cBn, 'colore' => (int)$cCol];
  };
  $A4 = $L['A4']; $A3 = $L['A3'];
  $out = ['adhoc' => false] + $calc($n4, $n3, $vbn, $vcol, $A4, $A3);

  // INSTRADAMENTO deterministico: in base ai volumi, quale macchina proporre (soglie dal listino).
  $sColMcc = (int)($L['vol_colore_max_a3'] ?? 0); // colore oltre → versione MCC
  $sTotA4  = (int)($L['vol_totale_max_a4'] ?? 0); // volume totale oltre → A3 (flotte solo A4)
  $mcc = $L['MCC'] ?? null;
  if ($sColMcc > 0 && $vcol > $sColMcc && is_array($mcc)) {
    // la macchina colore va portata alla versione MCC (rata MCC sul lato A3; se non c'era A3, 1 MCC)
    $r = $calc($n4, $n3 > 0 ? $n3 : 1, $vbn, $vcol, $A4, $mcc);
    $out['consiglio_tipo']   = 'MCC';
    $out['consiglio_canone'] = $r['canone'];
    $out['consiglio_nota']   = 'Volume colore elevato: proponi la versione MCC (contatore colore — le copie con copertura colore sotto il 3% costano la metà). La stima è a colore pieno, quindi prudenziale: sull\'uso reale il colore costa meno.';
  } elseif ($sTotA4 > 0 && ($vbn + $vcol) > $sTotA4 && $n3 === 0 && $n4 > 0) {
    // flotta solo A4 con volume totale alto: a questi volumi l'A3 è più adatto
    $r = $calc(0, 1, $vbn, $vcol, $A4, $A3);
    $out['consiglio_tipo']   = 'A3';
    $out['consiglio_canone'] = $r['canone'];
    $out['consiglio_nota']   = 'Volume totale elevato per un A4: a questi volumi conviene un A3, più robusto e con costo copia migliore.';
  }
  return $out;
}

// ---------------------------------------------------------------- client LLM (dispatcher nativo per-provider)
// Ispirato a BookBuilder/lib/ai.php: ogni provider con la sua API NATIVA, così non serve
// indovinare il base URL. openai/deepseek/lmstudio/ollama -> /chat/completions (OpenAI-compat);
// claude -> Anthropic /v1/messages ; gemini -> Google generateContent.
function ai_http_post($url, $body, $headers = [], &$diag = null) {
  $h = array_merge(['Content-Type: application/json'], $headers);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_HTTPHEADER => $h,
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
    // segui i redirect (es. ngrok http->https 307) mantenendo il POST
    CURLOPT_FOLLOWLOCATION => true, CURLOPT_POSTREDIR => 7,
  ]);
  $raw = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  $diag = ['http_code' => $code, 'error' => $err];
  return json_decode($raw ?: '{}', true) ?: [];
}
// Endpoint nativo cloud per provider; per i locali usa l'endpoint configurato.
function ai_provider_base($provider, $conn) {
  switch ($provider) {
    case 'openai':   return 'https://api.openai.com/v1';
    case 'deepseek': return 'https://api.deepseek.com/v1';
    case 'lmstudio': case 'ollama':
      $u = trim((string)($conn['endpoint'] ?? ''));
      if ($u === '') return $provider === 'ollama' ? 'http://localhost:11434/v1' : 'http://localhost:1234/v1';
      // aggiungi lo schema se manca: tunnel/domini -> https, localhost -> http
      if (!preg_match('#^https?://#i', $u)) $u = (preg_match('#^(localhost|127\.0\.0\.1)#i', $u) ? 'http://' : 'https://') . $u;
      $u = rtrim($u, '/');
      if (!preg_match('#/v1$#', $u)) $u .= '/v1';
      return $u;
    default: return 'https://api.openai.com/v1';
  }
}
// Estrae un messaggio d'errore leggibile dalla risposta (gestisce le varie forme:
// OpenAI {error:{message}}, bridge locali {error:"stringa"}, {message:"…"}).
function ai_err_from($res, $diag, $code) {
  if (is_array($res)) {
    if (isset($res['error']['message'])) return (string)$res['error']['message'];
    if (isset($res['error']) && is_string($res['error']) && $res['error'] !== '') return $res['error'];
    if (isset($res['message']) && is_string($res['message']) && $res['message'] !== '') return $res['message'];
  }
  $cerr = $diag['error'] ?? '';
  return $cerr !== '' ? $cerr : ('HTTP ' . $code);
}
// Dispatcher. Ritorna ['ok'=>bool, 'content'=>str, 'error'=>str].
function ai_llm_call($provider, $conn, $system, $messages, $opts = []) {
  switch ($provider) {
    case 'claude': return ai_call_claude($conn, $system, $messages, $opts);
    case 'gemini': return ai_call_gemini($conn, $system, $messages, $opts);
    default:       return ai_call_openai_compat($provider, $conn, $system, $messages, $opts);
  }
}
function ai_call_openai_compat($provider, $conn, $system, $messages, $opts) {
  $baseV1 = ai_provider_base($provider, $conn);
  $local = ai_provider_is_local($provider);
  $model = $conn['model'] ?: ($local ? 'local-model' : 'gpt-4o');
  $wantJson = !empty($opts['json']) && !$local;
  // DeepSeek "reasoner": inadatto alla chat JSON turn-by-turn (no JSON nativo, content spesso
  // vuoto) → per il path JSON lo sostituiamo con deepseek-chat (stesso provider/chiave).
  $isReasoner = ($provider === 'deepseek' && stripos($model, 'reason') !== false);
  if ($isReasoner && $wantJson) { $model = 'deepseek-chat'; $isReasoner = false; }
  $msgsBase = $system !== '' ? array_merge([['role'=>'system','content'=>$system]], $messages) : $messages;
  $headers = [];
  if ($local) $headers[] = 'ngrok-skip-browser-warning: true'; // passa l'interstitial ngrok free
  if (($conn['api_key'] ?? '') !== '') $headers[] = 'Authorization: Bearer ' . $conn['api_key'];

  // Strategia per forzare il JSON (A · "prefill"):
  //  - DeepSeek → PREFIX COMPLETION (endpoint /beta + messaggio assistant con prefix "{"): forza
  //    l'inizio del JSON all'origine ed elimina il content-vuoto del json_object (bug noto DeepSeek).
  //    È lo stesso trucco del prefill "{" già usato su Claude.
  //  - OpenAI cloud → response_format json_object (affidabile, nessun bug).
  //  - locali/reasoner → nessuna forzatura: istruzioni JSON nel prompt + parser tollerante.
  // Escalation: su 200-vuoto o 400 si passa a 'none' e si riprova.
  $jsonMode = 'none';
  if ($wantJson && !$isReasoner) $jsonMode = ($provider === 'deepseek') ? 'prefix' : 'format';

  $res = []; $diag = null; $code = 0;
  for ($a = 0; $a < 4; $a++) {
    $usePrefix = ($jsonMode === 'prefix');
    $base = $usePrefix ? preg_replace('#/v1$#', '/beta', $baseV1) : $baseV1; // prefix richiede /beta
    $msgs = $usePrefix ? array_merge($msgsBase, [['role'=>'assistant', 'content'=>'{', 'prefix'=>true]]) : $msgsBase;
    $payload = ['model' => $model, 'messages' => $msgs, 'temperature' => (float)($opts['temp'] ?? 0.4)];
    if ($isReasoner) unset($payload['temperature']); // il reasoner ignora/rifiuta la temperature
    if ($jsonMode === 'format') $payload['response_format'] = ['type' => 'json_object'];
    if (!empty($opts['max_tokens'])) $payload['max_tokens'] = (int)$opts['max_tokens'];

    $res = ai_http_post($base . '/chat/completions', $payload, $headers, $diag);
    $code = (int)($diag['http_code'] ?? 0);
    $transport = (($diag['error'] ?? '') !== '');
    if ($code >= 200 && $code < 300) {
      // Nota: NON usare reasoning_content come fallback — è la catena di ragionamento (non il JSON).
      $content = (string)($res['choices'][0]['message']['content'] ?? '');
      if ($usePrefix && trim($content) !== '') $content = '{' . $content; // riattacca il prefill
      if (trim($content) !== '' && $content !== '{') return ['ok' => true, 'content' => $content];
      if ($a >= 3) return ['ok' => false, 'error' => 'Il provider (' . $provider . ') ha restituito una risposta vuota. Riprova tra un istante.'];
      if ($jsonMode !== 'none') $jsonMode = 'none'; // 200 vuoto → togli la forzatura JSON e riprova
      usleep(600000); continue;
    }
    if ($code === 400 && $jsonMode !== 'none') { $jsonMode = 'none'; usleep(200000); continue; } // format/prefix non gradito
    if (($code === 429 || $code >= 500 || $transport) && $a < 3) { usleep(600000); continue; }
    return ['ok' => false, 'error' => ai_err_from($res, $diag, $code)];
  }
  return ['ok' => false, 'error' => ai_err_from($res, $diag, $code)];
}
function ai_call_claude($conn, $system, $messages, $opts) {
  $json = !empty($opts['json']);
  $msgs = $messages;
  if ($json) $msgs[] = ['role' => 'assistant', 'content' => '{']; // prefill: forza output JSON pulito
  $payload = [
    'model' => $conn['model'] ?: 'claude-sonnet-5',
    'max_tokens' => (int)($opts['max_tokens'] ?? 4096),
    'temperature' => (float)($opts['temp'] ?? 0.4),
    'messages' => $msgs,
  ];
  // B · prompt caching: il system prompt (persona+metodo+intervista+know-how, ~12K token) è
  // identico a ogni turno → lo marchiamo cacheable (blocco text con cache_control ephemeral).
  // ~90% di sconto sui token cached e meno latenza. Caching GA: nessun header beta necessario.
  if ($system !== '') $payload['system'] = [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]];
  $headers = ['x-api-key: ' . ($conn['api_key'] ?? ''), 'anthropic-version: 2023-06-01'];
  // Retry su 429/5xx/errori-rete e su 200-vuoto (robustezza uniforme a tutti i provider).
  $diag = null; $res = []; $code = 0;
  for ($a = 0; $a < 3; $a++) {
    $res = ai_http_post('https://api.anthropic.com/v1/messages', $payload, $headers, $diag);
    $code = (int)($diag['http_code'] ?? 0);
    $transport = (($diag['error'] ?? '') !== '');
    if ($code >= 200 && $code < 300) {
      $text = '';
      foreach (($res['content'] ?? []) as $blk) if (($blk['type'] ?? '') === 'text') $text .= (string)($blk['text'] ?? '');
      if ($json) $text = '{' . $text; // riattacca il prefill
      $text = trim($text);
      if ($text !== '' && $text !== '{') return ['ok' => true, 'content' => $text];
      if ($a < 2) { usleep(800000); continue; } // 200 ma vuoto → riprova
      return ['ok' => false, 'error' => 'Il provider (claude) ha restituito una risposta vuota. Riprova tra un istante.'];
    }
    if (($code === 429 || $code >= 500 || $transport) && $a < 2) { usleep(800000); continue; }
    return ['ok' => false, 'error' => ai_err_from($res, $diag, $code)];
  }
  return ['ok' => false, 'error' => ai_err_from($res, $diag, $code)];
}
function ai_call_gemini($conn, $system, $messages, $opts) {
  $model = $conn['model'] ?: 'gemini-2.5-flash';
  $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($conn['api_key'] ?? '');
  $contents = [];
  foreach ($messages as $m) $contents[] = ['role' => ($m['role'] === 'assistant' ? 'model' : 'user'), 'parts' => [['text' => $m['content']]]];
  $useJson = !empty($opts['json']);
  // Robustezza uniforme (come DeepSeek/OpenAI): retry su 429/5xx/errori-rete e su 200-vuoto
  // (es. finishReason SAFETY/MAX_TOKENS o JSON mode che torna vuoto) → al 1° vuoto droppa
  // responseMimeType e riprova. Concatena TUTTE le parts (Gemini può spezzare il testo).
  $diag = null; $res = []; $code = 0;
  for ($a = 0; $a < 4; $a++) {
    $payload = ['contents' => $contents];
    if ($system !== '') $payload['system_instruction'] = ['parts' => [['text' => $system]]];
    $gc = ['temperature' => (float)($opts['temp'] ?? 0.4)];
    if ($useJson)                    $gc['responseMimeType'] = 'application/json';
    if (!empty($opts['max_tokens'])) $gc['maxOutputTokens'] = (int)$opts['max_tokens'];
    $payload['generationConfig'] = $gc;
    $res = ai_http_post($url, $payload, [], $diag);
    $code = (int)($diag['http_code'] ?? 0);
    $transport = (($diag['error'] ?? '') !== '');
    if ($code >= 200 && $code < 300) {
      $content = '';
      foreach ((array)($res['candidates'][0]['content']['parts'] ?? []) as $p) $content .= (string)($p['text'] ?? '');
      if (trim($content) !== '') return ['ok' => true, 'content' => $content];
      if ($a >= 3) return ['ok' => false, 'error' => 'Il provider (gemini) ha restituito una risposta vuota. Riprova tra un istante.'];
      if ($useJson) $useJson = false; // 200 vuoto in JSON → droppa responseMimeType e riprova
      usleep(600000); continue;
    }
    if (($code === 429 || $code >= 500 || $transport) && $a < 3) { usleep(600000); continue; }
    return ['ok' => false, 'error' => ai_err_from($res, $diag, $code)];
  }
  return ['ok' => false, 'error' => ai_err_from($res, $diag, $code)];
}
// Compat: interfaccia usata da ai_chat_structured / proposta / test. Estrae l'eventuale
// system in testa e chiama il dispatcher nativo del provider attivo.
function ai_llm_chat_raw($messages, $opts = []) {
  $system = '';
  if (isset($messages[0]) && ($messages[0]['role'] ?? '') === 'system') { $system = (string)$messages[0]['content']; $messages = array_slice($messages, 1); }
  $cfg = ai_api_config();
  $conn = ['model' => $opts['model'] ?? $cfg['model'], 'api_key' => $cfg['key'], 'endpoint' => $cfg['base_url']];
  if (!isset($opts['temp'])) $opts['temp'] = $cfg['temp'];
  return ai_llm_call($cfg['provider'], $conn, $system, $messages, $opts);
}

// Chat "strutturata": ritorna array decodificato {reply, quick_replies, sufficiente, calc}.
// Gestisce internamente il secondo giro per la stima canone deterministica (agente con calcolatore).
// Gate deterministico: i contatti sono stati lasciati? (segnale affidabile = un'email
// nei messaggi del cliente). Serve a impedire QUALSIASI cifra prima dei dati di contatto.
function ai_history_has_contacts($history, $required = ['email']) {
  // Il controllo deterministico è possibile solo sull'email: se non è fra i dati
  // obbligatori dell'agente, il gate resta affidato alle regole del prompt.
  if (!in_array('email', (array)$required, true)) return true;
  foreach ($history as $m) {
    if (($m['role'] ?? '') !== 'user') continue;
    if (preg_match('/[\w.+-]+@[\w-]+\.[\w.]{2,}/', (string)($m['content'] ?? ''))) return true;
  }
  return false;
}

// Salvataggio video: se il modello ha scritto il video come segnaposto testuale nel reply
// (es. "Ecco il video: [video di presentazione DocuShare Go]") invece di popolare il campo
// JSON "video", prova a mapparlo sulla mappa dell'agente e a ripulire il testo (l'embed
// comparirà comunque sotto). Ritorna [videoRisolto|null, replyRipulito].
function ai_video_from_reply($reply, $vm) {
  if (!$vm || !preg_match('/\[[^\]]*\]/u', (string)$reply, $mm)) return [null, $reply];
  // normalizzazione con piega degli accenti (sostenibilità -> sostenibilita) per matchare
  // le chiavi scritte senza accento; poi tiene solo a-z0-9.
  $fold = function ($s) {
    $s = strtr(strtolower((string)$s), ['à'=>'a','á'=>'a','â'=>'a','è'=>'e','é'=>'e','ê'=>'e','ì'=>'i','í'=>'i','î'=>'i','ò'=>'o','ó'=>'o','ô'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ç'=>'c']);
    return preg_replace('/[^a-z0-9]/', '', $s);
  };
  $needle = $fold($mm[0]); // contenuto del segnaposto, normalizzato
  if ($needle === '') return [null, $reply];
  $hit = null;
  foreach ($vm as $vk => $vv) {
    // token "forti" della chiave (segmenti lunghi >=4, es. docushare-go -> ["docushare"]):
    // match se il segnaposto li contiene tutti (robusto a "di/della" nel testo).
    $tokens = array_filter(array_map($fold, preg_split('/[-_\s]+/', (string)$vk)), function ($t) { return strlen($t) >= 4; });
    if (!$tokens) $tokens = array_filter([$fold($vk)]);
    if (!$tokens) continue;
    $all = true;
    foreach ($tokens as $t) { if (strpos($needle, $t) === false) { $all = false; break; } }
    if ($all) { $hit = $vv; break; }
  }
  if ($hit === null) return [null, $reply];
  // ripulisci: togli l'eventuale "Ecco il video:" e QUALSIASI segnaposto [...]
  $clean = preg_replace('/(?:ecco\s+il\s+video\s*[:\-–]?\s*)?\[[^\]]*\]/iu', '', (string)$reply);
  $clean = preg_replace('/[ \t]{2,}/', ' ', $clean);
  $clean = preg_replace("/\n{3,}/", "\n\n", $clean);
  return [$hit, trim($clean)];
}

function ai_chat_structured($agent, $history) {
  $cfg = ai_agent_config($agent);
  $opts = ['json' => true];
  if ($cfg['modello'] !== '') $opts['model'] = $cfg['modello'];
  if ($cfg['temperatura'] !== null) $opts['temp'] = $cfg['temperatura'];
  $sys = ['role' => 'system', 'content' => ai_build_system_prompt($agent)];
  $messages = array_merge([$sys], $history);
  $r = ai_llm_chat_raw($messages, $opts);
  if (!$r['ok']) return ['ok' => false, 'error' => $r['error']];
  $parsed = ai_parse_json($r['content']);
  if ($parsed === null) {
    $raw = trim((string)$r['content']);
    // Niente JSON e nessun testo: non mandare una bolla vuota al widget.
    if ($raw === '') return ['ok' => false, 'error' => 'Risposta vuota dal modello. Riprova.'];
    // Il modello ha provato a fare il JSON e l'ha rotto: al cliente NON si mostra
    // l'impalcatura. Si pesca la frase; se non c'è, meglio un errore ritentabile.
    if (ai_pare_json($raw)) {
      $solo = ai_reply_da_grezzo($raw);
      if ($solo === '') return ['ok' => false, 'error' => 'Risposta non leggibile dal modello. Riprova.'];
      return ['ok' => true, 'reply' => $solo, 'quick_replies' => [], 'sufficiente' => false];
    }
    // Testo normale, senza velleità di JSON: passa così com'è.
    return ['ok' => true, 'reply' => $raw, 'quick_replies' => [], 'sufficiente' => false];
  }

  // Secondo giro DETERMINISTICO: se il modello ha invocato uno strumento, il risultato
  // lo calcola il server e glielo rimanda perché riscriva la risposta con il dato ufficiale.
  if (ai_tools_gated($agent, $parsed) || ai_tools_run($agent, $parsed) !== null) {
    // GATE: nessuna cifra finché il cliente non ha lasciato i contatti.
    if (ai_tools_gated($agent, $parsed) && !ai_history_has_contacts($history, $cfg['dati_obbligatori'])) {
      $messages[] = ['role' => 'assistant', 'content' => $r['content']];
      $lbl = ai_contact_fields(); $req = [];
      foreach ($cfg['dati_obbligatori'] as $k) if (isset($lbl[$k])) $req[] = $lbl[$k];
      $messages[] = ['role' => 'system', 'content' =>
        "STOP: non hai ancora i dati richiesti. NON fornire alcuna cifra, stima, canone o ordine "
        . "di grandezza. Riscrivi la tua risposta SENZA importi: spiega che la stima gliela prepari "
        . "subito e chiedi SOLO questi dati: " . implode(', ', $req) . ". Metti \"calc\" a null."];
      $r2 = ai_llm_chat_raw($messages, $opts);
      if ($r2['ok']) { $p2 = ai_parse_json($r2['content']); if ($p2 !== null) $parsed = $p2; }
      return [
        'ok' => true,
        'reply' => (string)($parsed['reply'] ?? ''),
        'quick_replies' => array_values(array_filter((array)($parsed['quick_replies'] ?? []), 'is_string')),
        'sufficiente' => false,
      ];
    }
    $hit = ai_tools_run($agent, $parsed);
    if ($hit !== null) {
      $messages[] = ['role' => 'assistant', 'content' => $r['content']];
      $messages[] = ['role' => 'system', 'content' => $hit['fact']];
      $r2 = ai_llm_chat_raw($messages, $opts);
      if ($r2['ok']) { $p2 = ai_parse_json($r2['content']); if ($p2 !== null) $parsed = $p2; }
    }
  }
  // video proposto dall'agente: la chiave viene risolta lato server sulla mappa dell'agente
  $video = null;
  $vm = ai_video_map($agent);
  $vkey = is_string($parsed['video'] ?? null) ? trim($parsed['video']) : '';
  if ($vkey !== '' && isset($vm[$vkey])) $video = $vm[$vkey];

  $reply = trim((string)($parsed['reply'] ?? ''));
  // Salvataggio: a volte il modello NON popola il campo "video" e scrive invece un segnaposto
  // testuale (es. "Ecco il video: [video di presentazione DocuShare Go]"). Se c'è un segnaposto
  // ma nessun video risolto, prova a mapparlo sulla mappa dell'agente e ripulisci il testo.
  if ($video === null) { list($vSalv, $reply) = ai_video_from_reply($reply, $vm); if ($vSalv) $video = $vSalv; }
  // JSON valido ma reply vuoto (raro): meglio un errore ritentabile che una bolla vuota.
  if ($reply === '') return ['ok' => false, 'error' => 'Risposta vuota dal modello. Riprova.'];
  return [
    'ok' => true,
    'reply' => $reply,
    'quick_replies' => array_values(array_filter((array)($parsed['quick_replies'] ?? []), 'is_string')),
    'sufficiente' => !empty($parsed['sufficiente']),
    'video' => $video,
  ];
}

function ai_parse_json($s) {
  $s = trim((string)$s);
  // togli eventuali fence ```json ... ```
  if (strpos($s, '```') !== false) {
    $s = preg_replace('/^```(?:json)?\s*/i', '', $s);
    $s = preg_replace('/\s*```$/', '', $s);
  }
  // Due giri: il testo come arriva, poi con le virgolette raddrizzate.
  foreach ([$s, ai_json_virgolette_dritte($s)] as $t) {
    $d = json_decode($t, true);
    if (is_array($d)) return $d;
    // fallback: estrai il primo blocco { ... }
    $a = strpos($t, '{'); $b = strrpos($t, '}');
    if ($a !== false && $b !== false && $b > $a) {
      $d = json_decode(substr($t, $a, $b - $a + 1), true);
      if (is_array($d)) return $d;
    }
  }
  return null;
}

/**
 * Raddrizza le virgolette curve in virgolette dritte.
 *
 * Succede davvero: il modello scrive {“reply”: “Buongiorno…”} e il JSON diventa
 * illeggibile, così al cliente compare l'impalcatura invece della risposta.
 * È un ULTIMO tentativo — se anche il testo del reply contenesse virgolette curve
 * il risultato resterebbe illeggibile, ma quel caso era già perso comunque.
 */
function ai_json_virgolette_dritte($s) {
  return str_replace(["\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x9E", "\xE2\x80\x9F"], '"', (string)$s);
}

/** Il testo somiglia al JSON dell'agente, anche se non si lascia decodificare? */
function ai_pare_json($s) {
  $t = ltrim(preg_replace('/^```(?:json)?\s*/i', '', trim((string)$s)));
  return $t !== '' && $t[0] === '{'
      && preg_match('/["\x{201C}\x{201D}]reply["\x{201C}\x{201D}]\s*:/u', $t) === 1;
}

/**
 * Pesca il solo testo del "reply" da un JSON che non si decodifica: meglio la
 * frase giusta senza le risposte rapide che le parentesi graffe in faccia al
 * cliente. Ritorna '' se non si trova niente di sensato.
 */
function ai_reply_da_grezzo($s) {
  $t = ai_json_virgolette_dritte((string)$s);
  if (!preg_match('/"reply"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/su', $t, $m)) return '';
  // Le sequenze di escape vanno sciolte a mano: qui non c'è un decoder che lo faccia.
  $v = strtr($m[1], ['\\n' => "\n", '\\r' => '', '\\t' => ' ', '\\"' => '"', '\\/' => '/', '\\\\' => '\\']);
  $v = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn($x) => mb_chr(hexdec($x[1]), 'UTF-8'), $v);
  return trim($v);
}

// ================================================================ COSTRUTTORE AGENTI
// Sezioni del "cervello" di un agente, nell'ordine in cui vanno mostrate/editate.
function ai_section_order() { return ['persona', 'metodo', 'intervista', 'knowhow', 'proposta', 'immagini', 'video', 'email', 'config']; }
function ai_section_labels() {
  return ['persona'=>'Persona', 'metodo'=>'Metodo', 'intervista'=>'Intervista', 'knowhow'=>'Know-how', 'proposta'=>'Proposta', 'immagini'=>'Immagini prodotti', 'video'=>'Video', 'email'=>'Email (HTML)', 'config'=>'Configurazione'];
}

// ---------------------------------------------------------------- immagine prodotto per la proposta
function ai_norm_name($s) { return preg_replace('/[^a-z0-9]/', '', strtolower((string)$s)); }
// Risolve l'immagine da mostrare per il prodotto proposto: prima la mappa esplicita
// della sezione 'immagini' (JSON "nome prodotto" => "uploads/file.jpg"), poi un
// auto-match sui file in uploads/ per token di modello (es. c9200, px500).
function ai_device_image($agent, $deviceName) {
  $d = ai_norm_name($deviceName);
  if ($d === '') return '';
  $map = json_decode(ai_section($agent, 'immagini', ''), true);
  if (is_array($map)) {
    foreach ($map as $k => $v) {
      if (!is_string($v) || (isset($k[0]) && $k[0] === '_')) continue; // chiavi speciali (es. _campionario)
      $nk = ai_norm_name($k);
      if ($nk !== '' && (strpos($d, $nk) !== false || strpos($nk, $d) !== false)) return (string)$v;
    }
  }
  $dir = __DIR__ . '/../uploads';
  if (!is_dir($dir)) return '';
  if (!preg_match_all('/[a-z]{1,2}\d{3,4}/', $d, $m)) return '';
  foreach (scandir($dir) as $f) {
    if (!preg_match('/\.(png|jpe?g|webp|gif)$/i', $f)) continue;
    $nf = ai_norm_name($f);
    foreach ($m[0] as $tok) if (strpos($nf, $tok) !== false) return 'uploads/' . $f;
  }
  return '';
}
// Guida breve mostrata nel pannello "cosa scrivere qui".
function ai_section_guides() {
  return [
    'persona'    => "Chi è l'agente: nome, ruolo, tono, carattere e le REGOLE D'ORO (cosa non deve mai fare, es. non inventare prezzi/prodotti). Scrivi in seconda persona (\"Sei…\"). È l'identità con cui parla al cliente.",
    'metodo'     => "COME conduce la conversazione: il metodo, come apre, come approfondisce, come chiude. Principio guida: prima si crea relazione, poi si raccolgono dati — conversa, non interrogare.",
    'intervista' => "La CHECKLIST NASCOSTA: le informazioni che l'agente deve raccogliere, organizzate in sezioni. Il cliente non la vede; l'agente la \"spunta\" mentre conversa e orienta le domande verso i buchi.",
    'knowhow'    => "Il CATALOGO: l'unica fonte di ciò che l'agente può proporre. Prodotti/servizi con caratteristiche, a chi servono e quando proporli. L'agente NON proporrà nulla che non sia qui.",
    'proposta'   => "La STRUTTURA della proposta finale che l'agente genera: quali blocchi deve contenere (riepilogo, criticità, soluzione, prossimo passo). Solo elementi presenti nel know-how.",
    'immagini'   => "Mappa JSON prodotto → immagine, usata per la foto nella card della proposta. Formato: {\"Nome prodotto\": \"uploads/file.jpg\"}. Il nome viene confrontato col prodotto proposto (basta che sia contenuto). Metti le voci PIÙ SPECIFICHE prima. Sono ammessi anche URL assoluti (https://…).",
    'video'      => "Video che l'agente può proporre in conversazione. Mappa JSON chiave => link YouTube, es. {\"docushare-go\": {\"youtube\":\"https://youtu.be/XXXX\", \"titolo\":\"DocuShare Go\"}}. L'agente sceglie solo fra queste chiavi e il video appare dentro la chat; poi la conversazione riprende.",
    'email'      => "Template HTML dell'email inviata al cliente, con CSS inline (massima compatibilità). Personalizzalo liberamente: i segnaposto {{MAIUSCOLI}} vengono sostituiti coi dati della proposta. Usa il pulsante Anteprima per vederlo. Lascia vuoto per usare il template predefinito.",
    'config'     => "Impostazioni dell'agente: modello e temperatura, se suggerire domande rapide, se attivare la voce, messaggio di apertura e strumenti opzionali. Si modifica dal form qui sotto.",
  ];
}
// Config di default (schema amichevole).
function ai_default_config() {
  return ['modello'=>'', 'temperatura'=>0.4, 'suggerisci_domande'=>true, 'voce'=>true, 'messaggio_apertura'=>'',
          'strumenti'=>[], 'dati_obbligatori'=>['azienda','referente','email'], 'email_notifiche'=>''];
}
// Template generici per un NUOVO agente: scheletro + commenti-guida + esempio realistico
// (modellato sulla struttura dei .md di M.C. System, ma reso generico e da sostituire).
function ai_agent_templates($nome = '[Nome agente]', $azienda = '[la tua azienda]') {
  $persona = <<<MD
# PERSONA — $nome

> Chi è l'agente e come parla. Scrivi in seconda persona ("Sei…").
> Sostituisci il testo d'esempio con la tua realtà.

## Identità
Ti chiami **$nome**. Sei un consulente di **$azienda**. Non sei un modulo da compilare:
accogli il cliente, lo fai raccontare, capisci la sua situazione reale e lo guidi verso la
soluzione più adatta tra quelle presenti nel know-how.

## Carattere
- **Competente ma umano** — parli come una persona, non come un catalogo.
- **Caldo e diretto, mai insistente** — non "spingi", fai emergere il bisogno.
- **Curioso e concreto** — ti interessa come lavora davvero il cliente.

## Come parli
- Italiano naturale, frasi brevi. **Una sola domanda per messaggio.**
- Dai sempre valore prima di chiedere (un aggancio a ciò che ha detto, poi la domanda).
- Risposte corte (2–5 frasi).

## Regole d'oro (NON derogabili)
1. Proponi SOLO ciò che è nel know-how. Se manca, dillo con onestà e proponi l'alternativa
   più vicina. **Non inventare mai** prezzi, modelli, tempi o disponibilità.
2. Non interrogare: conversa (vedi Metodo).
3. Resta in tema; se il bisogno è di un altro ambito, indirizza con garbo.

## Obiettivo
Capire la situazione reale del cliente e arrivare a una proposta specifica e coerente.
MD;

  $metodo = <<<MD
# METODO — Come condurre la conversazione

## Il principio
**Prima si crea relazione, poi si raccolgono dati.** Ciò che il cliente racconta
spontaneamente vale di più. Il tuo compito è ascoltare e, quando serve, approfondire.

## Le 3 mosse (a ogni turno)
1. **ASCOLTARE** — lascia raccontare, non interrompere.
2. **AGGANCIARE** — riprendi una parola o un concetto che ha appena detto.
3. **APPROFONDIRE** — una domanda mirata che trasforma il racconto in informazione utile.

## Aperture naturali (esempi da adattare)
- "Complimenti, mi racconta com'è organizzata la vostra realtà?"
- "Da quanto siete sul mercato? Come avete iniziato?"

## La domanda-pivot (dal tecnico al business)
Quando emerge un limite, trasformalo in tema economico:
"Questo vi crea solo un problema tecnico o vi fa perdere anche opportunità?"

## La chiusura
Non chiudere con "le mando un'offerta". Riepiloga: "Da quello che mi ha detto, i punti sono…",
poi "prima di proporle qualcosa vorrei verificare…", poi annuncia la proposta.
MD;

  $intervista = <<<MD
# INTERVISTA — Checklist nascosta

> L'agenda SEGRETA dell'agente. Il cliente non la vede: l'agente conversa in modo naturale e
> intanto "spunta" queste voci, orientando le domande verso ciò che ancora manca.

## Come usarla
Considera l'intervista sufficiente per la proposta quando hai coperto i punti chiave (almeno
una criticità e un'opportunità). Allora passa alla chiusura e proponi la proposta.

## Le sezioni (adatta al tuo settore)
### 1. Profilo / struttura
- Tipo di attività, dimensione, da quanto sul mercato
### 2. Situazione attuale
- Come lavorano oggi, cosa usano, come sono organizzati
### 3. Bisogni e criticità
- Cosa non funziona, cosa vorrebbero migliorare, cosa esternalizzano o rifiutano
### 4. Vincoli
- Budget, tempi, logistica, chi decide
### 5. Opportunità
- Dove possiamo dare valore, cosa proporre
### 6. Prossimi passi
- Contatti (azienda, referente, email), azione successiva
MD;

  $knowhow = <<<MD
# KNOW-HOW — Cosa l'agente può proporre

> L'UNICA fonte di ciò che l'agente propone. Se non è scritto qui, l'agente non lo propone.

## Come scriverlo
- Una scheda per prodotto/servizio: nome, a chi serve, caratteristiche chiave, quando proporlo.
- Aggiungi la logica commerciale: "se il cliente ha il bisogno X → proponi Y".
- Prezzi definitivi solo se vuoi darli; altrimenti la quotazione la fa il referente.

## Esempio (da sostituire)
### [Prodotto/Servizio A]
- **Per chi:** [profilo cliente]. **Caratteristiche:** [le 2-3 cose che contano].
- **Proponilo quando:** il cliente esprime [bisogno].
### [Prodotto/Servizio B]
- **Per chi:** … **Caratteristiche:** … **Proponilo quando:** …

## Logica di scelta
| Cosa emerge nell'intervista | Cosa proporre |
|---|---|
| [bisogno 1] | [Prodotto A] |
| [bisogno 2] | [Prodotto B] |
MD;

  $proposta = <<<MD
# PROPOSTA — Struttura dell'output finale

> Cosa deve contenere la proposta generata a fine conversazione.

## Struttura mostrata al cliente
1. **Riepilogo** della situazione (2–4 righe)
2. **Criticità / opportunità** individuate
3. **Soluzione proposta** — prodotti/servizi dal know-how, agganciati ai bisogni emersi
4. **Perché conviene**
5. **Prossimo passo** (e richiesta dei contatti)

## Regole
- Solo elementi presenti nel know-how. Nessun dato inventato.
- Collega ogni proposta a una criticità reale emersa nella conversazione.
MD;

  return [
    'persona' => $persona, 'metodo' => $metodo, 'intervista' => $intervista,
    'knowhow' => $knowhow, 'proposta' => $proposta,
    'immagini' => json_encode(['[Nome prodotto A]' => 'uploads/nome-file.jpg'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    'video'    => json_encode(['[chiave-soluzione]' => ['youtube' => '', 'titolo' => '']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    'config'  => json_encode(ai_default_config(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
  ];
}

// ================================================================ EMAIL HTML DELLA PROPOSTA
// URL assoluto (necessario nelle email): lascia intatti gli http(s), altrimenti antepone il dominio.
function ai_abs_url($u) {
  $u = trim((string)$u);
  if ($u === '' || preg_match('#^https?://#i', $u)) return $u;
  $base = defined('SITE_DOMAIN') ? rtrim(SITE_DOMAIN, '/') : '';
  return $base . '/' . ltrim($u, '/');
}
// Template HTML predefinito: tabelle + CSS inline + icone SVG (le SVG sono decorative:
// se un client le ignora il testo resta leggibile).
// Segnaposto disponibili nel template email (usati dall'editor per il menu "Segnaposto").
function ai_email_placeholders() {
  return [
    'TITLE'             => 'Titolo della proposta',
    'INTRO'             => 'Riepilogo della chiacchierata',
    'COMPOSIZIONE_BLOCK'=> 'Riquadro "La proposta comprende" (n° e tipo di apparecchiature)',
    'AGENT_NAME'        => 'Nome dell\'agente',
    'DEVICE_NAME'       => 'Nome del prodotto proposto',
    'DEVICE_TAG'        => 'Etichetta del prodotto',
    'DEVICE_WHY'        => 'Perché è adatto',
    'DEVICE_IMAGE_CELL' => 'Cella con la foto del prodotto',
    'CONFIG_LIST'       => 'Elenco "Configurazione"',
    'INCLUDES_LIST'     => 'Elenco "Cosa include"',
    'FORMULA_BLOCK'     => 'Riquadro "Formula consigliata"',
    'STEPS_LIST'        => 'Elenco "Prossimi passi"',
    'ALTERNATIVA_BLOCK' => 'Blocco "soluzione alternativa da valutare col consulente"',
    'XEROX_BLOCK'       => 'Alias storico di ALTERNATIVA_BLOCK (stesso contenuto)',
    'CAMPIONARIO_BLOCK' => 'Galleria di esempi (dalla chiave _campionario in Immagini prodotti)',
    'CONTACT_BLOCK'     => 'Riquadro "Prossimo contatto" (chi ricontatta e a quali recapiti)',
    'ACCENT'            => 'Colore dell\'agente',
    'SITE_NAME'         => 'Nome del sito',
    'SITE_URL'          => 'URL del sito',
  ];
}

// Template predefinito dell'email. La versione del SITO, se c'è, sta in
// inc/ai-email-template.html (intestazione, footer, dati aziendali: roba per-sito, non
// del motore). Senza quel file si usa il template generico qui sotto.
function ai_email_default_template() {
  $f = __DIR__ . '/ai-email-template.html';
  if (is_file($f)) {
    $tpl = (string) file_get_contents($f);
    if (trim($tpl) !== '') return $tpl;
  }
  return ai_email_generic_template();
}
function ai_email_generic_template() {
  return <<<'HTML'
<table width="100%" cellpadding="0" cellspacing="0" style="background:#edeae5;padding:24px 12px;font-family:Arial,Helvetica,sans-serif">
<tr><td align="center">
  <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e3ded7">
    <tr><td style="padding:20px 28px;border-bottom:1px solid #eee8e0">
      <div style="font-size:15px;font-weight:bold;color:#1a1817">{{SITE_NAME}}</div>
    </td></tr>
    <tr><td style="background:{{ACCENT}};padding:24px 28px">
      <div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#ffffff;opacity:.85">Proposta preparata da {{AGENT_NAME}}</div>
      <div style="font-size:23px;font-weight:bold;color:#ffffff;margin-top:4px">{{TITLE}}</div>
    </td></tr>
    <tr><td style="padding:26px 28px 8px">
      <p style="margin:0 0 20px;font-size:15px;line-height:1.55;color:#4a443e">{{INTRO}}</p>
      {{COMPOSIZIONE_BLOCK}}
      <table width="100%" cellpadding="0" cellspacing="0"><tr>
        {{DEVICE_IMAGE_CELL}}
        <td valign="top">
          <div style="display:inline-block;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:{{ACCENT}};background:#f7f0ef;padding:3px 9px;border-radius:20px">{{DEVICE_TAG}}</div>
          <div style="font-size:19px;font-weight:bold;color:#1a1817;margin:7px 0 6px">{{DEVICE_NAME}}</div>
          <div style="font-size:14px;line-height:1.55;color:#5a534b">{{DEVICE_WHY}}</div>
        </td>
      </tr></table>
    </td></tr>
    <tr><td style="padding:22px 28px 0">
      <div style="font-size:11px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#a89f95;margin-bottom:10px">Configurazione</div>
      {{CONFIG_LIST}}
      <div style="font-size:11px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#a89f95;margin:20px 0 10px">Cosa include</div>
      {{INCLUDES_LIST}}
    </td></tr>
    {{FORMULA_BLOCK}}
    <tr><td style="padding:22px 28px 0">
      <div style="font-size:11px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#a89f95;margin-bottom:10px">Prossimi passi</div>
      {{STEPS_LIST}}
    </td></tr>
    {{XEROX_BLOCK}}
    {{CAMPIONARIO_BLOCK}}
    {{CONTACT_BLOCK}}
    <tr><td style="padding:22px 28px 26px">
      <div style="padding:11px 14px;background:#f7f5f1;border:1px dashed #d8d0c6;border-radius:8px;font-size:11.5px;color:#8a827a">
        Solo prodotti a catalogo &middot; nessun prezzo o disponibilità inventati. Stima indicativa e non vincolante.
      </div>
    </td></tr>
    <tr><td style="background:#faf8f5;padding:20px 28px;border-top:1px solid #eee8e0;font-size:12.5px;color:#6b635b;line-height:1.65">
      <div style="font-weight:bold;color:#1a1817;font-size:13.5px;margin-bottom:5px">{{SITE_NAME}}</div>
      <a href="{{SITE_URL}}" style="color:{{ACCENT}};text-decoration:none">{{SITE_URL}}</a>
    </td></tr>
  </table>
</td></tr></table>
HTML;
}
// Compone l'HTML dell'email dalla proposta, usando il template dell'agente (o quello predefinito).
function ai_email_render($agent, $proposal, $extra = []) {
  $tpl = ai_section($agent, 'email', '');
  if (trim($tpl) === '') $tpl = ai_email_default_template();
  $meta   = ai_agent_meta($agent);
  $accent = $meta['accent'] ?? '#da291c';
  $e = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

  $tick = '<svg width="13" height="13" viewBox="0 0 16 16" style="vertical-align:-1px"><path d="M6.2 11.6 3.4 8.8l1.1-1.1 1.7 1.7 5-5 1.1 1.1z" fill="' . $accent . '"/></svg>';
  $mkList = function($arr) use ($tick, $e) {
    $out = '';
    foreach ((array)$arr as $v) {
      if (!is_string($v) || trim($v) === '') continue;
      $out .= '<div style="font-size:14px;line-height:1.5;color:#3a352f;margin:0 0 8px">' . $tick . ' &nbsp;' . $e($v) . '</div>';
    }
    return $out ?: '<div style="font-size:14px;color:#8a827a">—</div>';
  };
  $steps = '';
  foreach ((array)($proposal['steps'] ?? []) as $s) {
    $n = (int)($s['n'] ?? 0); $t = (string)($s['text'] ?? '');
    if ($t === '') continue;
    $steps .= '<table cellpadding="0" cellspacing="0" style="margin:0 0 9px"><tr>'
            . '<td width="26" valign="top"><div style="width:22px;height:22px;border-radius:11px;background:' . $accent . ';color:#fff;font-size:12px;font-weight:bold;text-align:center;line-height:22px">' . $n . '</div></td>'
            . '<td style="font-size:14px;line-height:1.5;color:#3a352f;padding-left:8px">' . $e($t) . '</td></tr></table>';
  }

  $img = ai_abs_url($proposal['image'] ?? '');
  $imgCell = $img !== ''
    ? '<td width="150" valign="top" style="padding-right:16px"><img src="' . $e($img) . '" alt="' . $e($proposal['deviceName'] ?? '') . '" width="134" style="width:134px;max-width:134px;border:1px solid #e3ded7;border-radius:10px;background:#fff"></td>'
    : '';

  $formula = trim((string)($proposal['formula'] ?? ''));
  $formulaBlock = $formula === '' ? '' :
      '<tr><td style="padding:20px 28px 0"><div style="border:1px solid ' . $accent . '55;background:#fdf6f5;border-radius:10px;padding:14px 16px">'
    . '<div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#8a827a;margin-bottom:3px">Formula consigliata</div>'
    . '<div style="font-size:16px;font-weight:bold;color:' . $accent . '">' . $e($formula) . '</div></div></td></tr>';

  // Blocco 2: soluzione alternativa da approfondire col consulente (senza prezzi).
  // Il titolo arriva dalla proposta (configurabile per agente); 'xerox' è il vecchio nome del campo.
  $x = is_array($proposal['alternativa'] ?? null) ? $proposal['alternativa'] : (is_array($proposal['xerox'] ?? null) ? $proposal['xerox'] : []);
  $xName = trim((string)($x['deviceName'] ?? ''));
  $xerox = '';
  if ($xName !== '') {
    $xImg = ai_abs_url($x['image'] ?? '');
    $xTit = trim((string)($x['titolo'] ?? '')) ?: 'Da valutare insieme al consulente';
    $xerox = '<tr><td style="padding:24px 28px 0"><table width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #eee8e0;padding-top:18px"><tr><td>'
      . '<div style="font-size:14px;font-weight:bold;color:#1a1817;margin:16px 0 12px">' . $e($xTit) . '</div>'
      . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#faf8f5;border:1px solid #eee8e0;border-radius:10px"><tr>'
      . ($xImg !== '' ? '<td width="118" valign="top" style="padding:12px"><img src="' . $e($xImg) . '" alt="' . $e($xName) . '" width="94" style="width:94px;max-width:94px;border-radius:8px;background:#fff"></td>' : '')
      . '<td valign="top" style="padding:14px 14px 14px ' . ($xImg !== '' ? '0' : '14px') . '">'
      . ($x['deviceTag'] ?? '' ? '<div style="font-size:10.5px;letter-spacing:1px;text-transform:uppercase;color:#8a827a">' . $e($x['deviceTag']) . '</div>' : '')
      . '<div style="font-size:16px;font-weight:bold;color:#1a1817;margin:3px 0 5px">' . $e($xName) . '</div>'
      . '<div style="font-size:13.5px;line-height:1.5;color:#5a534b">' . $e($x['why'] ?? '') . '</div>'
      . '</td></tr></table></td></tr></table></td></tr>';
  }

  // Blocco "prossimo contatto": chi ricontatta e a quali riferimenti (raccolti in chat).
  // Chi sia — un consulente generico o una persona specifica per quel tipo di interlocutore —
  // arriva dall'instradamento configurato sull'agente ($extra['rotta']), non dal codice.
  $ct     = is_array($extra['contatti'] ?? null) ? $extra['contatti'] : [];
  $ctMail = trim((string)($ct['email'] ?? ''));
  $ctTel  = trim((string)($ct['telefono'] ?? ''));
  $ctRef  = trim((string)($ct['referente'] ?? ''));
  $ctAz   = trim((string)($ct['azienda'] ?? ''));
  $rotta = is_array($extra['rotta'] ?? null) ? $extra['rotta'] : [];
  $recapiti = [];
  if ($ctMail !== '') $recapiti[] = $e($ctMail);
  if ($ctTel  !== '') $recapiti[] = $e($ctTel);
  $contactBlock = '';
  if ($recapiti || $ctAz !== '' || $ctRef !== '') {
    $sito = defined('SITE_NAME') ? SITE_NAME : '';
    $chi  = trim((string)($rotta['contatto'] ?? ''));
    $who  = $chi !== ''
      ? $chi . ' ti contatterà'                                    // es. "Letizia, responsabile della rete indiretta"
      : ('Un consulente' . ($sito !== '' ? ' ' . $e($sito) : '') . ' ti ricontatterà');
    $extraR = trim((string)($rotta['extra'] ?? ''));
    if ($extraR !== '') $extraR = ' ' . $extraR;
    $anag = trim($ctAz . ($ctRef !== '' ? ($ctAz !== '' ? ' — ' : '') . $ctRef : ''));
    $contactBlock =
        '<tr><td style="padding:20px 28px 0"><div style="border:1px solid ' . $accent . '55;background:#fdf6f5;border-radius:10px;padding:14px 16px">'
      . '<div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#8a827a;margin-bottom:5px">Prossimo contatto</div>'
      . '<div style="font-size:14px;line-height:1.55;color:#3a352f">' . $who
      . ($recapiti ? ' ai riferimenti che ci hai lasciato: <b>' . implode('</b> &middot; <b>', $recapiti) . '</b>' : '')
      . '.' . $extraR . '</div>'
      . ($anag !== '' ? '<div style="font-size:12.5px;color:#8a827a;margin-top:7px">Dati registrati: ' . $e($anag) . '</div>' : '')
      . '</div></td></tr>';
  }

  // Composizione: a quante/quali apparecchiature si riferisce la stima
  $comp = trim((string)($proposal['composizione'] ?? ''));
  $compBlock = $comp === '' ? '' :
      '<table width="100%" cellpadding="0" cellspacing="0" style="background:#faf8f5;border:1px solid #eee8e0;border-radius:10px;margin:0 0 20px"><tr><td style="padding:13px 15px">'
    . '<div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#a89f95;margin-bottom:4px">La proposta comprende</div>'
    . '<div style="font-size:15px;font-weight:bold;color:#1a1817;line-height:1.45">' . $e($comp) . '</div>'
    . '<div style="font-size:12px;color:#8a827a;font-style:italic;margin-top:5px">Qui sotto, a titolo di esempio, una delle possibili apparecchiature della proposta.</div>'
    . '</td></tr></table>';

  // Campionario: galleria di esempi di lavori realizzabili (contropartita concreta)
  $camp = ai_campionario($agent);
  $campMeta = ai_campionario_meta($agent);
  $campBlock = '';
  if ($camp) {
    $cells = '';
    foreach (array_slice($camp, 0, 6) as $i => $src) {
      if ($i % 3 === 0) $cells .= ($i ? '</tr><tr>' : '');
      $cells .= '<td width="33%" style="padding:4px"><img src="' . $e(ai_abs_url($src)) . '" width="168" alt="' . $e($campMeta['titolo']) . '" style="width:100%;max-width:168px;border-radius:8px;border:1px solid #e3ded7;display:block"></td>';
    }
    $campBlock =
        '<tr><td style="padding:24px 28px 0"><div style="border-top:1px solid #eee8e0;padding-top:18px">'
      . '<div style="font-size:14px;font-weight:bold;color:#1a1817;margin-bottom:4px">' . $e($campMeta['titolo']) . '</div>'
      . '<div style="font-size:13.5px;line-height:1.5;color:#5a534b;margin-bottom:12px">' . $e($campMeta['testo']) . '</div>'
      . '<table width="100%" cellpadding="0" cellspacing="0"><tr>' . $cells . '</tr></table>'
      . '</div></td></tr>';
  }

  return strtr($tpl, [
    '{{CAMPIONARIO_BLOCK}}'  => $campBlock,
    '{{COMPOSIZIONE_BLOCK}}' => $compBlock,
    '{{CONTACT_BLOCK}}'     => $contactBlock,
    '{{ACCENT}}'            => $accent,
    '{{AGENT_NAME}}'        => $e($meta['name'] ?? ''),
    '{{TITLE}}'             => $e($proposal['title'] ?? 'La tua proposta'),
    '{{INTRO}}'             => $e($extra['intro'] ?? ($proposal['why'] ?? '')),
    '{{DEVICE_IMAGE_CELL}}' => $imgCell,
    '{{DEVICE_TAG}}'        => $e($proposal['deviceTag'] ?? ''),
    '{{DEVICE_NAME}}'       => $e($proposal['deviceName'] ?? ''),
    '{{DEVICE_WHY}}'        => $e($proposal['why'] ?? ''),
    '{{CONFIG_LIST}}'       => $mkList($proposal['config'] ?? []),
    '{{INCLUDES_LIST}}'     => $mkList($proposal['includes'] ?? []),
    '{{FORMULA_BLOCK}}'     => $formulaBlock,
    '{{STEPS_LIST}}'        => $steps ?: '',
    '{{ALTERNATIVA_BLOCK}}' => $xerox,
    '{{XEROX_BLOCK}}'       => $xerox, // alias storico
    '{{SITE_NAME}}'         => $e(defined('SITE_NAME') ? SITE_NAME : ''),
    '{{SITE_URL}}'          => $e(defined('SITE_DOMAIN') ? SITE_DOMAIN : ''),
  ]);
}

// Crea un nuovo agente: meta + sezioni (dai template se $withTemplates).
function ai_create_agent($key, $meta, $withTemplates = true) {
  $key = preg_replace('/[^a-z0-9\-]/', '', strtolower($key));
  if ($key === '') throw new Exception('Identificativo agente non valido.');
  $exists = db()->prepare('SELECT COUNT(*) FROM cms_ai_config WHERE agent_key = ?');
  $exists->execute([$key]);
  if ((int)$exists->fetchColumn() > 0) throw new Exception('Esiste già un agente con questo identificativo.');
  ai_save_section($key, 'meta', json_encode($meta, JSON_UNESCAPED_UNICODE));
  if ($withTemplates) {
    foreach (ai_agent_templates($meta['name'] ?? $key) as $sec => $content) ai_save_section($key, $sec, $content);
  } else {
    ai_save_section($key, 'config', json_encode(ai_default_config(), JSON_UNESCAPED_UNICODE));
  }
  return $key;
}
function ai_delete_agent($key) {
  $st = db()->prepare('DELETE FROM cms_ai_config WHERE agent_key = ?');
  $st->execute([$key]);
}
function ai_duplicate_agent($src, $dstKey, $dstName) {
  $dstKey = preg_replace('/[^a-z0-9\-]/', '', strtolower($dstKey));
  if ($dstKey === '' || $dstKey === $src) throw new Exception('Identificativo di destinazione non valido.');
  $exists = db()->prepare('SELECT COUNT(*) FROM cms_ai_config WHERE agent_key = ?');
  $exists->execute([$dstKey]);
  if ((int)$exists->fetchColumn() > 0) throw new Exception('Esiste già un agente con questo identificativo.');
  foreach (ai_sections_all($src) as $sec => $content) {
    if ($sec === 'meta') {
      $m = json_decode($content, true); if (!is_array($m)) $m = [];
      $m['name'] = $dstName ?: (($m['name'] ?? $src) . ' (copia)');
      $content = json_encode($m, JSON_UNESCAPED_UNICODE);
    }
    ai_save_section($dstKey, $sec, $content);
  }
  return $dstKey;
}
