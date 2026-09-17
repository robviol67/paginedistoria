<?php
// VelociBuilder LITE — Opzioni globali del sito (social, maps, e qualsiasi data-vb-opt del Design).
// Default dal Design (inc/options-seed.json), override dal pannello (cms_settings, chiave "opt_<key>").
require_once __DIR__ . '/settings.php';

function opt_manifest() {
  static $m = null;
  if ($m === null) {
    $f = __DIR__ . '/options-seed.json';
    $m = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
  }
  return $m;
}
// Valore corrente di un'opzione (senza prefisso opt_): override DB oppure default dal Design.
function opt_get($key) {
  $m = opt_manifest();
  $def = $m[$key]['default'] ?? '';
  return setting_get('opt_' . $key, $def);
}
function opt_set($key, $value) { setting_set('opt_' . $key, $value); }

// Tutte le opzioni con metadati, raggruppate per "group" (per il pannello).
function opt_all_grouped() {
  $m = opt_manifest();
  $groups = [];
  foreach ($m as $key => $meta) {
    $g = $meta['group'] ?? 'Opzioni';
    $groups[$g][$key] = [
      'label'   => $meta['label'] ?? $key,
      'default' => $meta['default'] ?? '',
      'value'   => setting_get('opt_' . $key, $meta['default'] ?? ''),
    ];
  }
  return $groups;
}
