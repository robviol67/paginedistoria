<?php
// VelociBuilder LITE — migration integrazione VelociTracker (CRM): impostazioni + log invii. Idempotente.
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../inc/db.php';
try {
  db()->exec('CREATE TABLE IF NOT EXISTS cms_settings (
      skey VARCHAR(80) PRIMARY KEY,
      svalue TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_settings\n";

  db()->exec('CREATE TABLE IF NOT EXISTS cms_vt_log (
      id INT AUTO_INCREMENT PRIMARY KEY,
      form VARCHAR(40),
      email VARCHAR(190),
      azienda_id INT,
      trattativa_id INT,
      created_new TINYINT NOT NULL DEFAULT 0,
      ok TINYINT NOT NULL DEFAULT 0,
      http_status INT,
      error TEXT,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_vt_log\n";

  // valori di default (solo se la chiave non esiste ancora)
  $defaults = [
    'vt_enabled'           => '0',
    'vt_base_url'          => '',
    'vt_token'             => '',
    'vt_form_contatti'     => '1',
    'vt_form_guida'        => '1',
    'vt_pipeline_id'       => '1',
    'vt_pipeline_punto_id' => '1',
    'vt_anag_stato_id'     => '',
    'vt_tratt_stato_id'    => '',
    'vt_campagna_id'       => '',
    'vt_categoria'         => '',
    'vt_autore_id'         => '',
    'vt_utente_id'         => '',
    'vt_importo'           => '',
    'vt_opportunita'       => '1',
  ];
  $sel = db()->prepare('SELECT 1 FROM cms_settings WHERE skey=?');
  $ins = db()->prepare('INSERT INTO cms_settings (skey, svalue) VALUES (?, ?)');
  $n = 0;
  foreach ($defaults as $k => $v) { $sel->execute([$k]); if (!$sel->fetch()) { $ins->execute([$k, $v]); $n++; } }
  echo "OK  impostazioni di default: $n inserite (le esistenti non toccate)\n";
  echo "\nFatto. Ora: /admin/velocitracker.php  (l'integrazione parte DISATTIVA: attivala dal pannello)\n";
} catch (Throwable $e) { http_response_code(500); echo "ERRORE: " . $e->getMessage() . "\n"; }
