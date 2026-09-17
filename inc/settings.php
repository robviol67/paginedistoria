<?php
// VelociBuilder LITE — store impostazioni generico (chiave/valore) su cms_settings.
require_once __DIR__ . '/db.php';

function setting_get($key, $default = '') {
  static $cache = null;
  if ($cache === null) {
    $cache = [];
    try { foreach (db()->query('SELECT skey, svalue FROM cms_settings') as $r) $cache[$r['skey']] = $r['svalue']; }
    catch (Throwable $e) { $cache = []; }
  }
  return array_key_exists($key, $cache) ? $cache[$key] : $default;
}
function setting_set($key, $value) {
  $st = db()->prepare('INSERT INTO cms_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
  $st->execute([$key, (string)$value]);
}
function settings_ready() {
  try { db()->query('SELECT 1 FROM cms_settings LIMIT 1'); return true; } catch (Throwable $e) { return false; }
}
