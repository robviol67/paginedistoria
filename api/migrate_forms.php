<?php
// VelociBuilder LITE — migration Form builder: form + campi + submission. Idempotente.
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../inc/db.php';
try {
  db()->exec('CREATE TABLE IF NOT EXISTS cms_forms (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(190) NOT NULL,
      slug VARCHAR(190) NOT NULL UNIQUE,
      recipients VARCHAR(500),
      subject VARCHAR(255),
      success_message TEXT,
      autoreply_enabled TINYINT NOT NULL DEFAULT 0,
      autoreply_subject VARCHAR(255),
      autoreply_body TEXT,
      vt_enabled TINYINT NOT NULL DEFAULT 0,
      active TINYINT NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_forms\n";
  // titolo della pagina di conferma + allegato dell'auto-risposta (es. PDF da scaricare)
  if (db_add_col('cms_forms', 'success_title', 'VARCHAR(190) NULL AFTER success_message')) echo "OK  cms_forms.success_title aggiunta\n";
  if (db_add_col('cms_forms', 'autoreply_attachment', 'VARCHAR(255) NULL AFTER autoreply_body')) echo "OK  cms_forms.autoreply_attachment aggiunta\n";

  db()->exec('CREATE TABLE IF NOT EXISTS cms_form_fields (
      id INT AUTO_INCREMENT PRIMARY KEY,
      form_id INT NOT NULL,
      sort INT NOT NULL DEFAULT 0,
      field_key VARCHAR(60) NOT NULL,
      label VARCHAR(190),
      type VARCHAR(20) NOT NULL DEFAULT \'text\',
      required TINYINT NOT NULL DEFAULT 0,
      options TEXT,
      placeholder VARCHAR(190),
      vt_map VARCHAR(20),
      INDEX (form_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_form_fields\n";

  db()->exec('CREATE TABLE IF NOT EXISTS cms_form_submissions (
      id INT AUTO_INCREMENT PRIMARY KEY,
      form_id INT NOT NULL,
      data TEXT,
      ip VARCHAR(64),
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (form_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_form_submissions\n";

  // Seed dei moduli riconosciuti nel Design (inc/forms-seed.json). Idempotente per slug.
  $seedFile = __DIR__ . '/../inc/forms-seed.json';
  if (is_file($seedFile)) {
    $seed = json_decode(file_get_contents($seedFile), true) ?: [];
    foreach ($seed as $key => $form) {
      $slug = $form['slug'] ?? $key;
      $ex = db()->prepare('SELECT id FROM cms_forms WHERE slug=?'); $ex->execute([$slug]);
      if ($ex->fetchColumn()) { echo "modulo già presente: $slug\n"; continue; }
      $st = db()->prepare('INSERT INTO cms_forms (name, slug, recipients, subject, success_message) VALUES (?,?,?,?,?)');
      $st->execute([$form['name'] ?? $key, $slug, $form['recipients'] ?? '', $form['subject'] ?? '', $form['success_message'] ?? '']);
      $fid = (int) db()->lastInsertId();
      $fst = db()->prepare('INSERT INTO cms_form_fields (form_id, sort, field_key, label, type, required, options, placeholder, vt_map) VALUES (?,?,?,?,?,?,?,?,?)');
      $i = 0;
      foreach (($form['fields'] ?? []) as $f) {
        $fst->execute([$fid, $i++, $f['field_key'] ?? ('campo' . $i), $f['label'] ?? '', $f['type'] ?? 'text',
          (int)($f['required'] ?? 0), $f['options'] ?? '', $f['placeholder'] ?? '', $f['vt_map'] ?? '']);
      }
      echo "OK  modulo creato dal Design: $slug ($i campi)\n";
    }
  }
  echo "\nFatto. Gestisci i moduli in /admin/forms.php\n";
} catch (Throwable $e) { http_response_code(500); echo "ERRORE: " . $e->getMessage() . "\n"; }
