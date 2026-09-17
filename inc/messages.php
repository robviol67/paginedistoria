<?php
// VelociBuilder LITE — archiviazione messaggi dei form (per la sezione "Messaggi").
require_once __DIR__ . '/db.php';

function nc_save_message($form, $nome, $email, $tipo, $messaggio, $ip = '') {
  try {
    $st = db()->prepare(
      'INSERT INTO cms_messages (form, nome, email, tipo, messaggio, ip, created_at)
       VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $st->execute([$form, $nome, $email, $tipo, $messaggio, $ip]);
    return db()->lastInsertId();
  } catch (Throwable $e) { return false; } // non bloccare l'invio email se il DB fallisce
}

function nc_messages_all($limit = 500) {
  try { return db()->query('SELECT * FROM cms_messages ORDER BY id DESC LIMIT ' . (int) $limit)->fetchAll(); }
  catch (Throwable $e) { return null; } // null = tabella assente
}
function nc_messages_unread() {
  try { return (int) db()->query('SELECT COUNT(*) FROM cms_messages WHERE is_read = 0')->fetchColumn(); }
  catch (Throwable $e) { return 0; }
}
function nc_messages_mark_read($id) {
  try { $st = db()->prepare('UPDATE cms_messages SET is_read = 1 WHERE id = ?'); $st->execute([(int) $id]); } catch (Throwable $e) {}
}

/**
 * Elimina un messaggio dall'archivio. Ritorna true se una riga è sparita davvero.
 *
 * ⚠ NON tocca `gdpr_consent`: la prova del consenso è un obbligo di legge e deve
 * sopravvivere alla pulizia della casella. Qui si butta via la richiesta, non
 * la registrazione di chi ha acconsentito e quando.
 */
function nc_message_delete($id) {
  try {
    $st = db()->prepare('DELETE FROM cms_messages WHERE id = ?');
    $st->execute([(int) $id]);
    return $st->rowCount() > 0;
  } catch (Throwable $e) { return false; }
}
