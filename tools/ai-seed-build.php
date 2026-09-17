<?php
/**
 * VelociBuilder LITE — compilatore del "cervello" degli agenti: .md → inc/ai-seed.json
 *
 * L'anello che mancava nella catena. I contenuti di un agente si scrivono in file .md
 * (versionabili, diffabili, leggibili), il seed è solo il formato di trasporto verso il DB:
 *
 *     agenti-ai/<agente>/*.md  ──(questo script)──▶  inc/ai-seed.json  ──(migration
 *     o "Ripristina dal seed" nel Backoffice)──▶  cms_ai_config  ──▶  system prompt
 *
 * Senza compilatore i .md diventano documentazione morta e la fonte di verità si sdoppia.
 *
 * USO
 *   php tools/ai-seed-build.php --src=../MIOSITO/agenti-ai            # compila in inc/ai-seed.json
 *   php tools/ai-seed-build.php --src=… --out=inc/ai-seed.json
 *   php tools/ai-seed-build.php --src=… --check                       # non scrive: dice cosa cambierebbe
 *   php tools/ai-seed-build.php --src=… --init                        # crea agente.json mancanti dal seed esistente
 *
 * STRUTTURA ATTESA DELLA CARTELLA SORGENTE
 *   agenti-ai/
 *     config.json                 (facoltativo) default globali → seed["config"]
 *     <chiave-agente>/
 *       agente.json               meta (nome, ramo, ruolo, accent, avatar, tone, desc) + config
 *       persona.md metodo.md intervista.md proposta.md
 *       knowhow/*.md              concatenati in ordine alfabetico, con marker <!-- file: … -->
 *       immagini.json video.json  (facoltativi) mappe prodotto→immagine e chiave→video
 *       email.html                (facoltativo) template dell'email
 *
 * Il nome della cartella è la chiave dell'agente (la stessa di cms_ai_config.agent_key).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo da riga di comando.\n"); }

// ---------------------------------------------------------------- argomenti
$opt = ['src' => '', 'out' => __DIR__ . '/../inc/ai-seed.json', 'check' => false, 'init' => false, 'quiet' => false];
foreach (array_slice($argv, 1) as $a) {
  if (preg_match('/^--src=(.+)$/', $a, $m))      $opt['src'] = $m[1];
  elseif (preg_match('/^--out=(.+)$/', $a, $m))  $opt['out'] = $m[1];
  elseif ($a === '--check')  $opt['check'] = true;
  elseif ($a === '--init')   $opt['init']  = true;
  elseif ($a === '--quiet')  $opt['quiet'] = true;
  else { fwrite(STDERR, "Argomento sconosciuto: $a\n"); exit(2); }
}
// Sorgente di default: ./agenti-ai accanto al progetto, poi ../<sito>/agenti-ai.
if ($opt['src'] === '') {
  foreach ([__DIR__ . '/../agenti-ai', __DIR__ . '/../../agenti-ai'] as $c) if (is_dir($c)) { $opt['src'] = $c; break; }
}
if ($opt['src'] === '' || !is_dir($opt['src'])) {
  fwrite(STDERR, "Cartella sorgente non trovata. Usa --src=/percorso/agenti-ai\n");
  exit(2);
}

function say($s) { global $opt; if (!$opt['quiet']) echo $s . "\n"; }
function readf($f) { return is_file($f) ? (string) file_get_contents($f) : null; }

// Sezioni riconosciute e da dove arrivano.
$FILE_SECTIONS = ['persona' => 'persona.md', 'metodo' => 'metodo.md', 'intervista' => 'intervista.md', 'proposta' => 'proposta.md'];
$JSON_SECTIONS = ['immagini' => 'immagini.json', 'video' => 'video.json'];

$outPath = $opt['out'];
$prev = is_file($outPath) ? (json_decode((string) file_get_contents($outPath), true) ?: []) : [];

// ---------------------------------------------------------------- --init: agente.json dal seed esistente
// Comodo la prima volta: i meta/config vivevano solo dentro il seed scritto a mano.
if ($opt['init']) {
  $n = 0;
  foreach (glob(rtrim($opt['src'], '/') . '/*', GLOB_ONLYDIR) as $dir) {
    $key = basename($dir);
    $f = $dir . '/agente.json';
    if (is_file($f)) { say("· $key/agente.json già presente"); continue; }
    $meta = $prev['agents'][$key]['meta'] ?? ['name' => ucfirst($key), 'branch' => '', 'role' => '', 'accent' => '#da291c', 'avatar' => '', 'tone' => []];
    $cfgRaw = $prev['agents'][$key]['sections']['config'] ?? '';
    $cfg = $cfgRaw ? (json_decode($cfgRaw, true) ?: []) : [];
    file_put_contents($f, json_encode(['meta' => $meta, 'config' => $cfg], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    say("✚ scritto $key/agente.json (dal seed esistente)");
    $n++;
  }
  say($n ? "\n$n file agente.json creati. Rilancia senza --init per compilare." : "\nNiente da inizializzare.");
  exit(0);
}

// ---------------------------------------------------------------- compilazione
$agents = [];
$warn = [];
foreach (glob(rtrim($opt['src'], '/') . '/*', GLOB_ONLYDIR) as $dir) {
  $key = basename($dir);
  if ($key === '' || $key[0] === '_' || $key[0] === '.') continue;
  if (!preg_match('/^[a-z0-9\-]+$/', $key)) { $warn[] = "cartella '$key' ignorata (la chiave agente ammette solo a-z 0-9 e trattino)"; continue; }
  $sections = [];

  // meta + config
  $agFile = readf($dir . '/agente.json');
  $ag = $agFile !== null ? json_decode($agFile, true) : null;
  if ($agFile !== null && !is_array($ag)) { fwrite(STDERR, "ERRORE: $key/agente.json non è JSON valido\n"); exit(1); }
  $meta = is_array($ag['meta'] ?? null) ? $ag['meta'] : ['name' => ucfirst($key), 'branch' => '', 'role' => '', 'accent' => '#da291c', 'avatar' => '', 'tone' => []];
  if (isset($ag['config']) && is_array($ag['config'])) {
    $sections['config'] = json_encode($ag['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  if ($agFile === null) $warn[] = "$key: manca agente.json (meta/config di default)";

  // sezioni testuali
  foreach ($FILE_SECTIONS as $sec => $fn) {
    $c = readf($dir . '/' . $fn);
    if ($c === null) { $warn[] = "$key: manca $fn"; continue; }
    $sections[$sec] = rtrim($c) . "\n";
  }

  // know-how: tutti i .md della cartella knowhow/, in ordine, con marker di provenienza
  // (il marker serve a ritrovare la scheda giusta quando si legge il prompt montato).
  $khFiles = glob($dir . '/knowhow/*.md');
  sort($khFiles, SORT_STRING);
  if ($khFiles) {
    $parts = [];
    foreach ($khFiles as $f) $parts[] = '<!-- file: ' . basename($f) . " -->\n" . (string) file_get_contents($f);
    $sections['knowhow'] = trim(implode("\n\n", $parts)) . "\n";
  } else {
    $warn[] = "$key: nessuna scheda in knowhow/";
  }

  // mappe JSON (immagini, video): validate e riscritte formattate
  foreach ($JSON_SECTIONS as $sec => $fn) {
    $c = readf($dir . '/' . $fn);
    if ($c === null) continue;
    $d = json_decode($c, true);
    if (!is_array($d)) { fwrite(STDERR, "ERRORE: $key/$fn non è JSON valido\n"); exit(1); }
    $sections[$sec] = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  // template email (facoltativo)
  $mail = readf($dir . '/email.html');
  if ($mail !== null) $sections['email'] = $mail;

  if (!$sections) { $warn[] = "$key: nessun contenuto, agente saltato"; continue; }
  $agents[$key] = ['meta' => $meta, 'sections' => $sections];
}

if (!$agents) { fwrite(STDERR, "Nessun agente trovato in {$opt['src']}\n"); exit(1); }

// config globale del sito: config.json se c'è, altrimenti si conserva quella del seed precedente
$cfgFile = readf(rtrim($opt['src'], '/') . '/config.json');
if ($cfgFile !== null) {
  $cfg = json_decode($cfgFile, true);
  if (!is_array($cfg)) { fwrite(STDERR, "ERRORE: config.json non è JSON valido\n"); exit(1); }
} else {
  $cfg = $prev['config'] ?? [];
}

$seed = ['agents' => $agents, 'config' => $cfg];
$json = json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

// ---------------------------------------------------------------- report + scrittura
$changed = 0;
say("Sorgente: {$opt['src']}");
foreach ($agents as $key => $a) {
  say("\n▸ $key — " . ($a['meta']['name'] ?? $key));
  $old = $prev['agents'][$key]['sections'] ?? null;
  foreach ($a['sections'] as $sec => $content) {
    $o = $old[$sec] ?? null;
    if ($o === null)            { say(sprintf('   %-11s NUOVO      %6d car.', $sec, mb_strlen($content))); $changed++; }
    elseif ($o !== $content)    { say(sprintf('   %-11s MODIFICATO %6d car. (era %d, %+d)', $sec, mb_strlen($content), mb_strlen($o), mb_strlen($content) - mb_strlen($o))); $changed++; }
    else                        { say(sprintf('   %-11s invariato  %6d car.', $sec, mb_strlen($content))); }
  }
  if ($old) foreach ($old as $sec => $o) if (!isset($a['sections'][$sec])) { say(sprintf('   %-11s RIMOSSO    (era %d car.)', $sec, mb_strlen($o))); $changed++; }
  if (($prev['agents'][$key]['meta'] ?? null) !== $a['meta']) { say('   meta        MODIFICATO'); $changed++; }
}
foreach (array_keys($prev['agents'] ?? []) as $key) if (!isset($agents[$key])) { say("\n▸ $key — NON PIÙ PRESENTE nella sorgente (sparirà dal seed)"); $changed++; }
foreach ($warn as $w) say("⚠  $w");

if ($opt['check']) {
  say("\n" . ($changed ? "$changed sezioni cambierebbero (--check: non ho scritto nulla)." : 'Seed già allineato ai .md.'));
  exit($changed ? 1 : 0);
}
if (!$changed && is_file($outPath)) { say("\nSeed già allineato: nessuna scrittura."); exit(0); }
if (file_put_contents($outPath, $json) === false) { fwrite(STDERR, "Scrittura fallita: $outPath\n"); exit(1); }
say("\n✓ scritto $outPath (" . number_format(strlen($json)) . " byte, $changed sezioni cambiate)");
say("  Sul sito: rilancia /api/migrate_assistente.php (solo se cms_ai_config è vuota) oppure usa");
say("  Backoffice → Assistente AI → sezione → \"Ripristina dal seed\" per portare a bordo le modifiche.");
