<?php
// VelociBuilder LITE — migration Menu di navigazione: tabella + gerarchia + seed dal design. Idempotente.
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../inc/db.php';
try {
  db()->exec('CREATE TABLE IF NOT EXISTS cms_nav (
      id INT AUTO_INCREMENT PRIMARY KEY,
      label VARCHAR(120) NOT NULL,
      href VARCHAR(255) NOT NULL,
      sort INT NOT NULL DEFAULT 0,
      is_cta TINYINT NOT NULL DEFAULT 0,
      active TINYINT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_nav\n";

  // --- Evoluzione schema: colonne gerarchia (idempotente, siti già migrati) ---
  // parent_id: voce padre (NULL = voce di primo livello). kind: 'link' | 'header'
  // ('header' = etichetta non cliccabile dentro un pannello a tendina, es. "Catalogo").
  $cols = [];
  foreach (db()->query('SHOW COLUMNS FROM cms_nav') as $c) $cols[$c['Field']] = true;
  if (!isset($cols['parent_id'])) { db()->exec('ALTER TABLE cms_nav ADD COLUMN parent_id INT NULL DEFAULT NULL'); echo "OK  + colonna parent_id\n"; }
  if (!isset($cols['kind']))      { db()->exec("ALTER TABLE cms_nav ADD COLUMN kind VARCHAR(12) NOT NULL DEFAULT 'link'"); echo "OK  + colonna kind\n"; }

  $n = (int) db()->query('SELECT COUNT(*) FROM cms_nav')->fetchColumn();
  if ($n === 0) {
    $seedFile = __DIR__ . '/../inc/nav-seed.json';
    $seed = is_file($seedFile) ? (json_decode(file_get_contents($seedFile), true) ?: []) : [];
    // Il seed può essere piatto o gerarchico. Formato gerarchico: ogni voce ha un
    // "id" sintetico e un "parent_id" che referenzia un id del seed. Rimappiamo i
    // parent_id sintetici sugli id auto-increment reali inserendo prima i padri.
    $ins = db()->prepare('INSERT INTO cms_nav (label, href, sort, is_cta, active, parent_id, kind) VALUES (?,?,?,?,1,?,?)');
    $idMap = []; // seedId -> dbId
    $c = 0;
    foreach ([true, false] as $topLevelPass) { // due passate: prima i padri, poi i figli
      foreach ($seed as $i => $it) {
        $isTop = empty($it['parent_id']);
        if ($isTop !== $topLevelPass) continue;
        $pid = $isTop ? null : ($idMap[$it['parent_id']] ?? null);
        $ins->execute([$it['label'], $it['href'] ?? '#', $i, (int)($it['is_cta'] ?? 0), $pid, $it['kind'] ?? 'link']);
        $newId = (int) db()->lastInsertId();
        if (isset($it['id'])) $idMap[$it['id']] = $newId;
        $c++;
      }
    }
    $tops = count(array_filter($seed, fn($x) => empty($x['parent_id'])));
    echo "OK  seed: $c voci di menu ($tops di primo livello)\n";
  } else {
    echo "voci già presenti: $n (nessun seed)\n";
  }
  echo "\nFatto. Ora: /admin/menu.php\n";
} catch (Throwable $e) { http_response_code(500); echo "ERRORE: " . $e->getMessage() . "\n"; }
