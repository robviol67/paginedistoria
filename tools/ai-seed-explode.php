<?php
/**
 * VelociBuilder LITE — verso inverso del compilatore: ai-seed.json → file .md
 *
 * Serve a riportare sul disco ciò che è stato modificato dal Backoffice. Senza, la copia
 * versionata invecchia a ogni modifica fatta dal pannello e la fonte di verità si sdoppia
 * (nell'altra direzione rispetto al problema che risolve ai-seed-build.php).
 *
 *   Backoffice → admin/ai-export.php  →  seed  → questo script → agenti-ai/<agente>/*.md
 *
 * USO
 *   php tools/ai-seed-explode.php --seed=export.json --dst=../MIOSITO/agenti-ai --check
 *   php tools/ai-seed-explode.php --seed=export.json --dst=…                       # scrive
 *   php tools/ai-seed-explode.php --seed=… --dst=… --only=meta,config              # solo agente.json
 *   php tools/ai-seed-explode.php --seed=… --dst=… --skip=persona,intervista       # tutto tranne quelle
 *
 * ⚠️ Attenzione al verso: se una sezione sul DISCO è più aggiornata del DB (perché l'hai
 * corretta nei .md e non l'hai ancora portata sul sito), esplodere la sovrascrive. Lancia
 * SEMPRE prima con --check, e usa --only/--skip per scegliere le sezioni.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo da riga di comando.\n"); }

$opt = ['seed' => '', 'dst' => '', 'check' => false, 'only' => [], 'skip' => [], 'quiet' => false];
foreach (array_slice($argv, 1) as $a) {
  if (preg_match('/^--seed=(.+)$/', $a, $m))      $opt['seed'] = $m[1];
  elseif (preg_match('/^--dst=(.+)$/', $a, $m))   $opt['dst']  = $m[1];
  elseif (preg_match('/^--only=(.+)$/', $a, $m))  $opt['only'] = array_filter(array_map('trim', explode(',', $m[1])));
  elseif (preg_match('/^--skip=(.+)$/', $a, $m))  $opt['skip'] = array_filter(array_map('trim', explode(',', $m[1])));
  elseif ($a === '--check') $opt['check'] = true;
  elseif ($a === '--quiet') $opt['quiet'] = true;
  else { fwrite(STDERR, "Argomento sconosciuto: $a\n"); exit(2); }
}
if ($opt['seed'] === '' || !is_file($opt['seed'])) { fwrite(STDERR, "Serve --seed=/percorso/seed.json\n"); exit(2); }
if ($opt['dst']  === '' || !is_dir($opt['dst']))   { fwrite(STDERR, "Serve --dst=/percorso/agenti-ai (cartella esistente)\n"); exit(2); }

$seed = json_decode((string) file_get_contents($opt['seed']), true);
if (!is_array($seed['agents'] ?? null)) { fwrite(STDERR, "Seed non valido: manca \"agents\".\n"); exit(1); }

function say($s) { global $opt; if (!$opt['quiet']) echo $s . "\n"; }
function vuoi($sezione) {
  global $opt;
  if ($opt['only'] && !in_array($sezione, $opt['only'], true)) return false;
  if ($opt['skip'] && in_array($sezione, $opt['skip'], true)) return false;
  return true;
}

$FILE_SECTIONS = ['persona' => 'persona.md', 'metodo' => 'metodo.md', 'intervista' => 'intervista.md', 'proposta' => 'proposta.md'];
$JSON_SECTIONS = ['immagini' => 'immagini.json', 'video' => 'video.json'];

$scritti = 0; $cambiati = 0;
// Scrive un file solo se il contenuto è diverso; ritorna lo stato per il report.
function metti($path, $content) {
  global $opt, $scritti, $cambiati;
  $old = is_file($path) ? (string) file_get_contents($path) : null;
  if ($old === $content) return 'invariato';
  $cambiati++;
  if (!$opt['check']) {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (file_put_contents($path, $content) === false) { fwrite(STDERR, "Scrittura fallita: $path\n"); exit(1); }
    $scritti++;
  }
  if ($old === null) return 'NUOVO';
  $d = mb_strlen($content) - mb_strlen($old);
  return sprintf('SOVRASCRITTO (%+d car.)', $d);
}

say('Seed:  ' . $opt['seed']);
say('Disco: ' . $opt['dst'] . ($opt['check'] ? '   [--check: non scrivo nulla]' : ''));
if ($opt['only']) say('Solo sezioni: ' . implode(', ', $opt['only']));
if ($opt['skip']) say('Salto sezioni: ' . implode(', ', $opt['skip']));

foreach ($seed['agents'] as $key => $a) {
  $key = preg_replace('/[^a-z0-9\-]/', '', (string) $key);
  if ($key === '') continue;
  $dir = rtrim($opt['dst'], '/') . '/' . $key;
  say("\n▸ $key — " . ($a['meta']['name'] ?? $key));
  $S = (array)($a['sections'] ?? []);

  // agente.json = meta + config (le impostazioni fatte dal pannello vivono qui)
  if (vuoi('meta') || vuoi('config')) {
    $ag = ['meta' => $a['meta'] ?? [], 'config' => json_decode((string)($S['config'] ?? ''), true) ?: new stdClass()];
    // se si è chiesta una sola delle due, conserva l'altra dal file esistente
    $cur = is_file($dir . '/agente.json') ? json_decode((string) file_get_contents($dir . '/agente.json'), true) : null;
    if (is_array($cur)) {
      if (!vuoi('meta')   && isset($cur['meta']))   $ag['meta']   = $cur['meta'];
      if (!vuoi('config') && isset($cur['config'])) $ag['config'] = $cur['config'];
    }
    $st = metti($dir . '/agente.json', json_encode($ag, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    say(sprintf('   %-24s %s', 'agente.json', $st));
  }

  foreach ($FILE_SECTIONS as $sec => $fn) {
    if (!isset($S[$sec]) || !vuoi($sec)) continue;
    say(sprintf('   %-24s %s', $fn, metti($dir . '/' . $fn, rtrim((string)$S[$sec]) . "\n")));
  }

  // know-how: si ri-spezza sui marker <!-- file: NOME --> messi dal compilatore
  if (isset($S['knowhow']) && vuoi('knowhow')) {
    $kh = (string) $S['knowhow'];
    if (preg_match_all('/<!-- file: (.+?) -->\n/', $kh, $mm, PREG_OFFSET_CAPTURE)) {
      $n = count($mm[0]);
      for ($i = 0; $i < $n; $i++) {
        $nome = basename(trim($mm[1][$i][0]));
        if (!preg_match('/^[\w.\- ]+\.md$/u', $nome)) { say("   ⚠  nome scheda sospetto, salto: $nome"); continue; }
        $start = $mm[0][$i][1] + strlen($mm[0][$i][0]);
        $end   = ($i + 1 < $n) ? $mm[0][$i+1][1] : strlen($kh);
        $body  = rtrim(substr($kh, $start, $end - $start)) . "\n";
        say(sprintf('   %-24s %s', 'knowhow/' . $nome, metti($dir . '/knowhow/' . $nome, $body)));
      }
    } else {
      // nessun marker (know-how scritto a mano dal pannello): un file unico, senza perdere nulla
      say(sprintf('   %-24s %s', 'knowhow/00-knowhow.md', metti($dir . '/knowhow/00-knowhow.md', rtrim($kh) . "\n")));
      say('   ⚠  il know-how non ha i marker <!-- file: … -->: salvato come scheda unica');
    }
  }

  foreach ($JSON_SECTIONS as $sec => $fn) {
    if (!isset($S[$sec]) || !vuoi($sec)) continue;
    $d = json_decode((string)$S[$sec], true);
    if (!is_array($d)) { say("   ⚠  $fn non è JSON valido nel DB, salto"); continue; }
    say(sprintf('   %-24s %s', $fn, metti($dir . '/' . $fn, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n")));
  }

  if (isset($S['email']) && trim((string)$S['email']) !== '' && vuoi('email')) {
    say(sprintf('   %-24s %s', 'email.html', metti($dir . '/email.html', (string)$S['email'])));
  }
}

// config globale del sito (provider, voce, consenso): utile per ricostruire un sito da zero
if (!empty($seed['config']) && vuoi('config')) {
  $st = metti(rtrim($opt['dst'], '/') . '/config.json', json_encode($seed['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
  say(sprintf("\n   %-24s %s", 'config.json', $st));
}

say("\n" . ($opt['check']
  ? ($cambiati ? "$cambiati file cambierebbero (--check: non ho scritto nulla)." : 'Disco già allineato al DB.')
  : ($scritti ? "✓ $scritti file aggiornati sul disco." : 'Disco già allineato: nessuna scrittura.')));
exit($opt['check'] && $cambiati ? 1 : 0);
