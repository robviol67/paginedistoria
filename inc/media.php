<?php
// VelociBuilder LITE — Media Library: archivio immagini centralizzato riusabile tra pagine/prodotti.
require_once __DIR__ . '/db.php';
if (!function_exists('pesc')) { function pesc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

const MEDIA_EXTS = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'];
const MEDIA_DIR  = 'uploads/media';

function media_all() {
  try { return db()->query('SELECT * FROM cms_media ORDER BY created_at DESC, id DESC')->fetchAll(); }
  catch (Throwable $e) { return []; }
}
function media_get($id) {
  try { $st = db()->prepare('SELECT * FROM cms_media WHERE id=?'); $st->execute([(int)$id]); return $st->fetch(); }
  catch (Throwable $e) { return null; }
}
function media_table_ready() {
  try { db()->query('SELECT 1 FROM cms_media LIMIT 1'); return true; } catch (Throwable $e) { return false; }
}

// Salva un file caricato ($_FILES entry) nell'archivio. Ritorna il path relativo (uploads/media/...).
function media_store($file, $alt = '') {
  if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) throw new Exception('Nessun file ricevuto.');
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, MEDIA_EXTS, true)) throw new Exception('Formato non valido (ammessi: ' . implode(', ', MEDIA_EXTS) . ').');
  $dir = __DIR__ . '/../' . MEDIA_DIR;
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $fn = 'm' . date('YmdHis') . '-' . mt_rand(1000, 9999) . '.' . $ext;
  if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $fn)) throw new Exception('Upload non riuscito (permessi cartella uploads/).');
  $path = MEDIA_DIR . '/' . $fn;
  $st = db()->prepare('INSERT INTO cms_media (path, original, mime, bytes, alt) VALUES (?,?,?,?,?)');
  $st->execute([$path, $file['name'], $file['type'] ?? '', (int)($file['size'] ?? 0), trim($alt)]);
  return $path;
}
function media_set_alt($id, $alt) {
  $st = db()->prepare('UPDATE cms_media SET alt=? WHERE id=?'); $st->execute([trim($alt), (int)$id]);
}
// Sovrascrive il file di un'immagine esistente della libreria (stesso percorso -> i riferimenti restano validi).
function media_overwrite($path, $file) {
  $path = ltrim((string)$path, '/');
  if (strpos($path, MEDIA_DIR . '/') !== 0 || strpos($path, '..') !== false) throw new Exception('Percorso non valido.');
  $st = db()->prepare('SELECT * FROM cms_media WHERE path=?'); $st->execute([$path]); $row = $st->fetch();
  if (!$row) throw new Exception('Immagine non presente in libreria.');
  $abs = __DIR__ . '/../' . $path;
  if (!is_file($abs)) throw new Exception('File originale mancante.');
  if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) throw new Exception('Nessun file ricevuto.');
  if (!move_uploaded_file($file['tmp_name'], $abs)) throw new Exception('Sovrascrittura non riuscita (permessi).');
  $bytes = @filesize($abs) ?: 0;
  $up = db()->prepare('UPDATE cms_media SET bytes=? WHERE id=?'); $up->execute([$bytes, (int)$row['id']]);
  return $path;
}
function media_delete($id) {
  $m = media_get($id); if (!$m) return;
  // cancella il file solo se dentro uploads/ e non più usato altrove nell'archivio
  if (!empty($m['path']) && strpos($m['path'], 'uploads/') === 0) @unlink(__DIR__ . '/../' . $m['path']);
  $st = db()->prepare('DELETE FROM cms_media WHERE id=?'); $st->execute([(int)$id]);
}
