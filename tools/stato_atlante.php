<?php
// Pagine di Storia — che cosa c'è dentro l'atlante, in venti righe.
// Serve a rispondere senza aprire phpMyAdmin: quante schede per tipologia,
// quante fonti, quante citazioni, quante schede senza fonti o senza corpo.
//
//   php tools/stato_atlante.php
//   https://…/tools/stato_atlante.php?key=…
declare(strict_types=1);
require_once __DIR__ . '/../inc/db.php';

if (PHP_SAPI !== 'cli') {
  header('Content-Type: text/plain; charset=utf-8');
  $atteso = defined('MIGRATION_KEY') ? MIGRATION_KEY : '';
  if ($atteso === '' || !hash_equals($atteso, (string)($_GET['key'] ?? ''))) {
    http_response_code(403); exit("Serve la chiave: ?key=…\n");
  }
}

$q = fn(string $sql) => db()->query($sql)->fetchAll();
$uno = fn(string $sql) => (int)db()->query($sql)->fetchColumn();

echo "SCHEDE per tipologia\n";
foreach ($q('SELECT tipologia, COUNT(*) n FROM pds_schede GROUP BY tipologia ORDER BY n DESC') as $r)
  printf("  %-20s %3d\n", $r['tipologia'], $r['n']);
printf("  %-20s %3d\n", 'TOTALE', $uno('SELECT COUNT(*) FROM pds_schede'));

echo "\nSTATO editoriale\n";
foreach ($q('SELECT stato, COUNT(*) n FROM pds_schede GROUP BY stato ORDER BY n DESC') as $r)
  printf("  %-20s %3d\n", $r['stato'], $r['n']);

printf("\nFONTI                  %3d\n", $uno('SELECT COUNT(*) FROM pds_fonti'));
printf("CITAZIONI (scheda×fonte) %3d\n", $uno('SELECT COUNT(*) FROM pds_scheda_fonte'));
printf("  di cui con localizzatore %3d\n", $uno("SELECT COUNT(*) FROM pds_scheda_fonte WHERE localizzatore IS NOT NULL AND localizzatore<>''"));
printf("TASSONOMIE             %3d\n", $uno('SELECT COUNT(*) FROM pds_tassonomie'));
foreach ($q('SELECT tipo, COUNT(*) n FROM pds_tassonomie GROUP BY tipo ORDER BY tipo') as $r)
  printf("  %-20s %3d\n", $r['tipo'], $r['n']);
printf("PERIODI collegati      %3d righe\n", $uno('SELECT COUNT(*) FROM pds_scheda_periodo'));
printf("TEMI collegati         %3d righe\n", $uno('SELECT COUNT(*) FROM pds_scheda_tema'));

echo "\nDA GUARDARE\n";
printf("  schede senza fonti          %3d\n", $uno('SELECT COUNT(*) FROM pds_schede s WHERE NOT EXISTS (SELECT 1 FROM pds_scheda_fonte f WHERE f.scheda_id=s.id)'));
printf("  schede senza sintesi        %3d\n", $uno("SELECT COUNT(*) FROM pds_schede WHERE sintesi IS NULL OR sintesi=''"));
printf("  citazioni a fonti assenti   %3d\n", $uno('SELECT COUNT(*) FROM pds_scheda_fonte f WHERE NOT EXISTS (SELECT 1 FROM pds_fonti o WHERE o.id=f.fonte_id)'));
printf("  slug doppi                  %3d\n", $uno('SELECT COUNT(*) FROM (SELECT slug FROM pds_schede GROUP BY slug HAVING COUNT(*)>1) d'));
