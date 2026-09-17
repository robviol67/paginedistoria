<?php
// VelociBuilder LITE — Assistente AI: STRUMENTI DETERMINISTICI.
//
// L'idea, in una riga: uno strumento è una funzione server registrata che il modello può
// INVOCARE popolando un campo del JSON di risposta, ma di cui non produce MAI il risultato.
//
//   il modello scrive:  { "reply": "…", "calc": {"n4":3,"n3":1,"vbn":3000,"vcol":1000} }
//   il server calcola:  ai_stima_canone(3,1,3000,1000) → €170
//   il server rilancia: "DATO UFFICIALE: €170/mese. Riscrivi la risposta includendo la cifra."
//
// Così il numero è sempre nostro (verificabile, coerente col listino) e il modello si limita
// a raccogliere i parametri e a raccontare il risultato. Il calcolatore del canone è UNA
// istanza: un preventivatore, un configuratore, una verifica di disponibilità funzionano
// identici — cambia solo la funzione registrata.
//
// Ogni sito può aggiungere i propri strumenti creando inc/ai-tools-site.php (vedi in fondo).
require_once __DIR__ . '/settings.php';

// ---------------------------------------------------------------- registro
function &ai_tools_registry() { static $reg = []; return $reg; }

/**
 * Registra uno strumento.
 *   campo        chiave JSON che il modello popola (deve restare stabile: è nel prompt)
 *   label        nome mostrato nel Backoffice
 *   descrizione  una riga: cosa fa, per il Backoffice
 *   schema       forma dei parametri, mostrata nel prompt (es. {"n4":int,"n3":int})
 *   istruzioni   regola per il system prompt: quando usarlo e come presentarne l'esito
 *   gate         true = niente risultato finché non ci sono i dati di contatto obbligatori
 *   run          callable(array $args, string $agent): array|null — il calcolo vero
 *   fact         callable(array $esito, string $agent): string — il "dato ufficiale" da reiniettare
 */
function ai_tool_register($name, $spec) {
  $reg = &ai_tools_registry();
  $reg[$name] = array_merge([
    'campo'       => $name,
    'label'       => $name,
    'descrizione' => '',
    'schema'      => '{}',
    'istruzioni'  => '',
    'gate'        => true,
    'run'         => null,
    'fact'        => null,
  ], $spec);
}
function ai_tools() { ai_tools_boot(); $reg = &ai_tools_registry(); return $reg; }
function ai_tool($name) { $all = ai_tools(); return $all[$name] ?? null; }

// Strumenti abilitati per un agente (dalla sua configurazione).
// Retro-compatibilità: il vecchio flag booleano 'strumento_calcolatore' vale ['canone'].
function ai_agent_tools($agent) {
  $cfg = ai_agent_config($agent);
  $names = (array)($cfg['strumenti'] ?? []);
  $out = [];
  foreach (ai_tools() as $n => $t) if (in_array($n, $names, true)) $out[$n] = $t;
  return $out;
}

// ---------------------------------------------------------------- blocco per il system prompt
// Genera le regole sugli strumenti abilitati: campi del JSON + istruzioni d'uso.
function ai_tools_prompt_block($agent) {
  $tools = ai_agent_tools($agent);
  if (!$tools) return ['campi' => '', 'regole' => ''];
  $campi = ''; $regole = '';
  foreach ($tools as $t) {
    $campi .= '  "' . $t['campo'] . '": null'
            . '  // oppure ' . $t['schema'] . " — SOLO quando stai per usare questo strumento\n";
    $ist = $t['istruzioni'];
    if (is_callable($ist)) $ist = call_user_func($ist, $agent);
    $regole .= '- **' . $t['label'] . "** — " . trim((string)$ist) . "\n";
  }
  $regole = "Non calcolare MAI a mente il risultato di uno strumento: popola il campo e riceverai il dato ufficiale dal sistema, poi presentalo.\n" . $regole;
  return ['campi' => $campi, 'regole' => $regole];
}

// ---------------------------------------------------------------- esecuzione
// Cerca nel JSON del modello un campo-strumento popolato ed esegue la funzione registrata.
// Ritorna ['tool'=>spec, 'fact'=>string] oppure null se non c'è niente da eseguire.
function ai_tools_run($agent, $parsed) {
  foreach (ai_agent_tools($agent) as $t) {
    $args = $parsed[$t['campo']] ?? null;
    if (empty($args) || !is_array($args)) continue;
    if (!is_callable($t['run'])) continue;
    $esito = call_user_func($t['run'], $args, $agent);
    if ($esito === null) continue;
    $fact = is_callable($t['fact']) ? (string) call_user_func($t['fact'], $esito, $agent) : '';
    if ($fact === '') continue;
    return ['tool' => $t, 'fact' => $fact];
  }
  return null;
}
// Qualche strumento abilitato è "gated" (non può produrre cifre senza i contatti)?
function ai_tools_gated($agent, $parsed) {
  foreach (ai_agent_tools($agent) as $t) {
    if (empty($t['gate'])) continue;
    if (!empty($parsed[$t['campo']]) && is_array($parsed[$t['campo']])) return true;
  }
  return false;
}

// ================================================================ strumento incluso: canone di noleggio
// È l'esempio di riferimento: listino a scaglioni CONFIGURABILE (cms_settings
// 'ai_tool_canone_listino', JSON) — nessun numero di un cliente specifico dentro il motore.
function ai_canone_listino() {
  $raw = trim((string) setting_get('ai_tool_canone_listino', ''));
  $d = $raw !== '' ? json_decode($raw, true) : null;
  if (is_array($d) && isset($d['A4'], $d['A3'])) return $d;
  return ai_canone_listino_default();
}
// Default: listino "noleggio a costo copia" a due formati (A4/A3) — rata per dispositivo a
// scaglioni di quantità + costo copia a scaglioni di volume, bianco/nero e colore separati.
// [soglia, valore]: vale il primo scaglione con quantità/volume <= soglia.
function ai_canone_listino_default() {
  $INF = 999999999;
  return [
    'max_per_tipo' => 5, // oltre: niente cifra, serve un'analisi del parco
    // soglie di instradamento (quale macchina proporre in base ai volumi):
    'vol_totale_max_a4'  => 2500, // volume totale mese oltre il quale un A4 non basta → A3
    'vol_colore_max_a3'  => 1800, // volume colore mese oltre il quale conviene la versione MCC
    'A4' => [
      'rata' => [[2, 19], [5, 17]],
      'bn'   => [[500, 0.009], [1000, 0.008], [$INF, 0.007]],
      'col'  => [[200, 0.08], [400, 0.07], [600, 0.06], [$INF, 0.05]],
    ],
    'A3' => [
      'rata' => [[1, 58], [2, 49], [3, 43], [5, 36]],
      'bn'   => [[1000, 0.008], [2000, 0.007], [$INF, 0.006]],
      'col'  => [[200, 0.06], [400, 0.055], [600, 0.05], [$INF, 0.048]],
    ],
    // MCC = A3 con l'applicazione "MC Counter": rata più alta, stessi costi copia dell'A3.
    // Il vantaggio (copie colore con copertura <3% a metà prezzo) è un beneficio d'uso reale,
    // NON modellato qui: la stima resta a colore pieno (prudenziale). Vedi il know-how.
    'MCC' => [
      'rata' => [[1, 75], [2, 68], [3, 64], [5, 58]],
      'bn'   => [[1000, 0.008], [2000, 0.007], [$INF, 0.006]],
      'col'  => [[200, 0.06], [400, 0.055], [600, 0.05], [$INF, 0.048]],
    ],
  ];
}

// Registrazione degli strumenti inclusi + di quelli del sito (una volta sola).
function ai_tools_boot() {
  static $done = false;
  if ($done) return;
  $done = true;

  ai_tool_register('canone', [
    'campo'       => 'calc', // storico: il campo si chiama "calc" da sempre, non cambiarlo
    'label'       => 'Calcolatore canone di noleggio',
    'descrizione' => 'Stima il canone mensile da numero di dispositivi e volumi di stampa, sul listino a scaglioni configurato.',
    'schema'      => '{"n4":int,"n3":int,"vbn":int,"vcol":int}',
    'gate'        => true,
    'istruzioni'  => 'quando hai i quattro numeri (dispositivi formato A4, dispositivi A3, volume mensile bianco/nero, volume mensile colore) popola il campo "calc": riceverai la cifra ufficiale e, se i volumi lo richiedono, la macchina consigliata (A4/A3/MCC). Presentala come stima indicativa e non vincolante. Oltre il massimo di dispositivi per tipo non si danno cifre: serve un\'analisi del parco con un consulente.',
    'run'         => function ($args, $agent) {
      return ai_stima_canone($args['n4'] ?? 0, $args['n3'] ?? 0, $args['vbn'] ?? 0, $args['vcol'] ?? 0);
    },
    'fact'        => function ($stima, $agent) {
      if (!empty($stima['adhoc'])) {
        return "Il calcolatore non produce una cifra con questo numero di dispositivi: spiega che serve un'analisi del parco con un consulente, senza dare numeri.";
      }
      $msg = "DATO UFFICIALE dal calcolatore: canone indicativo €{$stima['canone']}/mese (IVA esclusa, NON vincolante). ";
      if (!empty($stima['consiglio_tipo'])) {
        $msg .= "MACCHINA CONSIGLIATA in base ai volumi: {$stima['consiglio_tipo']}";
        if (isset($stima['consiglio_canone'])) $msg .= ", canone indicativo €{$stima['consiglio_canone']}/mese";
        $msg .= ". " . trim((string)($stima['consiglio_nota'] ?? '')) . " Presenta QUESTA come la proposta principale (usa il suo canone), citando il perché. ";
      }
      $msg .= "Riscrivi la tua risposta includendo la cifra in modo naturale; non rifare calcoli, non citare altre cifre.";
      return $msg;
    },
  ]);

  // Strumenti specifici del sito: preventivatori, configuratori, verifiche di disponibilità…
  // Il file non è del motore (gitignorato per-sito) e registra con ai_tool_register().
  $site = __DIR__ . '/ai-tools-site.php';
  if (is_file($site)) require_once $site;
}
