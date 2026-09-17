<?php
// VelociBuilder LITE — migration Assistente AI: config agenti + lead + settings. Idempotente.
// Tabelle: cms_ai_config (sezioni editabili per agente), cms_ai_leads (proposte salvate).
// Seed della config dagli .md via inc/ai-seed.json, solo se la tabella è vuota (come cms_nav).
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../inc/db.php';
try {
  db()->exec('CREATE TABLE IF NOT EXISTS cms_ai_config (
      id INT AUTO_INCREMENT PRIMARY KEY,
      agent_key VARCHAR(40) NOT NULL,
      section   VARCHAR(40) NOT NULL,
      content   MEDIUMTEXT,
      updated_at DATETIME NOT NULL,
      UNIQUE KEY uniq_agent_section (agent_key, section)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_ai_config\n";

  db()->exec('CREATE TABLE IF NOT EXISTS cms_ai_leads (
      id INT AUTO_INCREMENT PRIMARY KEY,
      agent_key VARCHAR(40) NOT NULL DEFAULT "",
      azienda   VARCHAR(190) NOT NULL DEFAULT "",
      referente VARCHAR(190) NOT NULL DEFAULT "",
      email     VARCHAR(190) NOT NULL DEFAULT "",
      telefono  VARCHAR(60)  NOT NULL DEFAULT "",
      proposal_json MEDIUMTEXT,
      transcript    MEDIUMTEXT,
      created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_ai_leads\n";

  // Contatori anti-abuso degli endpoint pubblici (una riga per chiamata, vedi inc/ai-guard.php).
  db()->exec('CREATE TABLE IF NOT EXISTS cms_ai_usage (
      id INT AUTO_INCREMENT PRIMARY KEY,
      agent_key VARCHAR(40) NOT NULL DEFAULT "",
      kind      VARCHAR(16) NOT NULL DEFAULT "chat",
      cost      TINYINT UNSIGNED NOT NULL DEFAULT 1,
      ip        VARCHAR(45) NOT NULL DEFAULT "",
      sid       VARCHAR(32) NOT NULL DEFAULT "",
      created_at DATETIME NOT NULL,
      KEY idx_created (created_at),
      KEY idx_ip (ip, created_at),
      KEY idx_sid (sid, created_at),
      KEY idx_agent (agent_key, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  cms_ai_usage (tetti anti-abuso)\n";

  // Registro consensi GDPR: l'assistente ci scrive il consenso raccolto in chat.
  // Idempotente e identica a migrate_consent.php (un sito nuovo potrebbe non averla).
  db()->exec('CREATE TABLE IF NOT EXISTS gdpr_consent (
      id           INT AUTO_INCREMENT PRIMARY KEY,
      form         VARCHAR(32)  NOT NULL,
      nome         VARCHAR(190),
      email        VARCHAR(190),
      consent_text TEXT,
      ip           VARCHAR(64),
      user_agent   VARCHAR(255),
      created_at   DATETIME     NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  echo "OK  gdpr_consent (consenso in chat)\n";

  // cms_settings potrebbe non esistere ancora su un sito nuovo: garantiamola (idempotente).
  db()->exec('CREATE TABLE IF NOT EXISTS cms_settings (
      skey   VARCHAR(190) PRIMARY KEY,
      svalue MEDIUMTEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

  // --- Seed della config agenti dal file per-sito inc/ai-seed.json (solo se vuota) ---
  $n = (int) db()->query('SELECT COUNT(*) FROM cms_ai_config')->fetchColumn();
  if ($n === 0) {
    $seedFile = __DIR__ . '/../inc/ai-seed.json';
    $seed = is_file($seedFile) ? (json_decode(file_get_contents($seedFile), true) ?: []) : [];
    $agents = $seed['agents'] ?? [];
    $ins = db()->prepare('INSERT INTO cms_ai_config (agent_key, section, content, updated_at) VALUES (?,?,?,NOW())');
    $c = 0;
    foreach ($agents as $key => $a) {
      // meta agente come sezione JSON dedicata (nome, ramo, accent, avatar, tono…)
      if (!empty($a['meta'])) { $ins->execute([$key, 'meta', json_encode($a['meta'], JSON_UNESCAPED_UNICODE)]); $c++; }
      foreach (($a['sections'] ?? []) as $section => $content) {
        $ins->execute([$key, $section, (string)$content]); $c++;
      }
    }
    echo "OK  seed: $c sezioni da " . count($agents) . " agenti\n";

    // Config API globale di default: NON sovrascrive chiavi già impostate.
    $cfg = $seed['config'] ?? [];
    $get = db()->prepare('SELECT COUNT(*) FROM cms_settings WHERE skey = ?');
    $set = db()->prepare('INSERT INTO cms_settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue = svalue');
    foreach ($cfg as $k => $v) {
      $get->execute([$k]);
      if ((int)$get->fetchColumn() === 0) { $set->execute([$k, (string)$v]); }
    }
    echo "OK  settings default (ai_provider/base_url/model/temp/voice)\n";
  } else {
    echo "config già presente: $n sezioni (nessun seed)\n";
  }

  echo "\nFatto. Ora: /admin/assistente.php  (inserisci la API key nel tab API)\n";
} catch (Throwable $e) {
  http_response_code(500);
  echo "ERRORE: " . $e->getMessage() . "\n";
}
