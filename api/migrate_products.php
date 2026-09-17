<?php
// VelociBuilder LITE — migration Prodotti: tabelle + seed dai dati del design. Idempotente.
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../inc/db.php';
try {
  db()->exec('CREATE TABLE IF NOT EXISTS cms_prod_families (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(190) NOT NULL,
      tagline VARCHAR(500),
      sort INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_prod_families\n";
  db()->exec('CREATE TABLE IF NOT EXISTS cms_products (
      id INT AUTO_INCREMENT PRIMARY KEY,
      family_id INT NOT NULL,
      model VARCHAR(120) NOT NULL,
      category VARCHAR(255),
      speed VARCHAR(120),
      cycle VARCHAR(120),
      bullets TEXT,
      image VARCHAR(255),
      sort INT NOT NULL DEFAULT 0,
      active TINYINT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_products\n";
  try { $a = db_add_col('cms_products', 'brochure', 'VARCHAR(255) AFTER image'); echo ($a ? "OK  colonna brochure\n" : "SKIP brochure (già presente)\n"); }
  catch (Throwable $e) { echo "brochure: " . $e->getMessage() . "\n"; }

  $nf = (int) db()->query('SELECT COUNT(*) FROM cms_prod_families')->fetchColumn();
  if ($nf === 0) {
    $seed = json_decode(file_get_contents(__DIR__ . '/../inc/products-seed.json'), true) ?: [];
    $insF = db()->prepare('INSERT INTO cms_prod_families (name, tagline, sort) VALUES (?,?,?)');
    $insP = db()->prepare('INSERT INTO cms_products (family_id, model, category, speed, cycle, bullets, image, sort, active) VALUES (?,?,?,?,?,?,?,?,1)');
    $cf = 0; $cp = 0;
    foreach ($seed as $fi => $fam) {
      $insF->execute([$fam['name'], $fam['tagline'], $fi]); $fid = db()->lastInsertId(); $cf++;
      foreach ($fam['products'] as $pi => $p) {
        $insP->execute([$fid, $p['model'], $p['category'], $p['speed'], $p['cycle'], implode("\n", $p['bullets']), $p['image'], $pi]); $cp++;
      }
    }
    echo "OK  seed: $cf famiglie, $cp prodotti\n";
  } else {
    echo "famiglie già presenti: $nf (nessun seed)\n";
  }
  echo "\nFatto. Ora: /admin/prodotti.php\n";
} catch (Throwable $e) { http_response_code(500); echo "ERRORE: " . $e->getMessage() . "\n"; }
