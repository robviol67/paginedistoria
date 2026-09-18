<?php
// Pagine di Storia — versa dati/media.json in pds_media. Idempotente.
//   php tools/importa_media.php [--forza]
//   https://…/tools/importa_media.php?key=…[&forza=1]
// Stessa prudenza dell'atlante: senza «forza» i momenti già presenti non si
// toccano, perché una correzione fatta nel pannello vale più del prototipo.
declare(strict_types=1);
require_once __DIR__ . '/../inc/db.php';
if (PHP_SAPI !== 'cli') {
  header('Content-Type: text/plain; charset=utf-8');
  $atteso = defined('MIGRATION_KEY') ? MIGRATION_KEY : '';
  if ($atteso === '' || !hash_equals($atteso, (string)($_GET['key'] ?? ''))) { http_response_code(403); exit("Serve la chiave: ?key=…\n"); }
}
$forza = PHP_SAPI === 'cli' ? in_array('--forza', $argv ?? [], true) : isset($_GET['forza']);
$D = json_decode((string)@file_get_contents(__DIR__ . '/../dati/media.json'), true);
if (!$D || empty($D['voci'])) exit("✗ manca dati/media.json: lancia `node tools/estrai_media.js`\n");

$campi = ['ordine','data_testo','anno','mezzo','categoria','titolo','programma','perche','documento','stato','scheda_id','scheda_prototipo','evidenza','evidenza_testo'];
$sel = db()->prepare('SELECT 1 FROM pds_media WHERE id=?');
$ins = db()->prepare('INSERT INTO pds_media (id,' . implode(',', $campi) . ') VALUES (?' . str_repeat(',?', count($campi)) . ')');
$upd = db()->prepare('UPDATE pds_media SET ' . implode('=?, ', $campi) . '=? WHERE id=?');
$n = ['nuovi' => 0, 'agg' => 0, 'saltati' => 0];
db()->beginTransaction();
foreach ($D['voci'] as $v) {
  $vals = array_map(fn($c) => $v[$c] ?? null, $campi);
  $sel->execute([$v['id']]);
  if (!$sel->fetchColumn()) { $ins->execute(array_merge([$v['id']], $vals)); $n['nuovi']++; }
  elseif ($forza) { $upd->execute(array_merge($vals, [$v['id']])); $n['agg']++; }
  else $n['saltati']++;
}
db()->commit();
$orfani = (int)db()->query('SELECT COUNT(*) FROM pds_media m WHERE m.scheda_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM pds_schede s WHERE s.id=m.scheda_id)')->fetchColumn();
printf("momenti  +%d nuovi, %d aggiornati, %d lasciati stare\n", $n['nuovi'], $n['agg'], $n['saltati']);
printf("rimandi a schede inesistenti: %d\n", $orfani);
