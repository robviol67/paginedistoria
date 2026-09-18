<?php
// Pagine di Storia — pannello: l'elenco delle schede dell'atlante.
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/pds_admin.php';

$msg = ''; $tipo = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!pds_csrf_ok()) throw new RuntimeException('Sessione scaduta o modulo non valido: ricarica la pagina e riprova.');
    $azione = $_POST['azione'] ?? '';
    if ($azione === 'nuova') {
      $t = $_POST['tipologia'] ?? '';
      if (!isset(PDS_PREFISSI[$t])) throw new InvalidArgumentException('Scegli una tipologia.');
      header('Location: scheda.php?nuova=' . rawurlencode($t)); exit;
    } elseif ($azione === 'pubblica') {
      $e = pds_pubblica_tutto();
      $msg = sprintf('Ripubblicato: %d schede, %d fonti, file dei filtri, Nessi, Media e Home, in %s s.', $e['schede']['scritte'], $e['fonti']['scritte'], $e['secondi']);
      $err = array_merge($e['schede']['errori'], $e['fonti']['errori']);
      if ($err) { $tipo = 'err'; $msg .= ' Errori: ' . implode('; ', array_slice($err, 0, 5)); }
    } elseif ($azione === 'home_nessi') {
      $ids = array_filter(array_map(fn($x) => strtoupper(trim($x)), explode(',', (string)($_POST['home_nessi'] ?? ''))));
      foreach ($ids as $id) { $r = atlante_scheda($id); if (!$r || $r['tipologia'] !== 'Nesso') throw new InvalidArgumentException("$id non è una scheda Nesso."); }
      setting_set('home_nessi_evidenza', implode(',', $ids));
      require_once __DIR__ . '/../inc/pds_home.php';
      pds_pubblica_home();
      $msg = 'Nessi in evidenza aggiornati e Home ripubblicata.';
    }
  } catch (Throwable $e) { $tipo = 'err'; $msg = $e->getMessage(); }
}

$f = ['q' => trim($_GET['q'] ?? ''), 'tipologia' => $_GET['tipologia'] ?? '', 'stato' => $_GET['stato'] ?? '',
      'periodo' => $_GET['periodo'] ?? '', 'pubblicata' => $_GET['pubblicata'] ?? ''];
$schede = atlante_pronto() ? atlante_schede_filtrate($f) : [];
$conti = atlante_conta();

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('schede', 'Schede — Pagine di Storia');

echo '<div class="hd"><div><h1>Schede</h1><p class="sub">La fonte unica dell’atlante: ciò che si salva qui diventa la pagina pubblica, il file dei filtri, Nessi, Media e Home.</p></div>';
echo '<form method="post" style="display:flex;gap:8px;align-items:center">' . pds_csrf_campo() . '<input type="hidden" name="azione" value="nuova"><select name="tipologia" style="width:auto">';
foreach (array_keys(PDS_PREFISSI) as $t) echo '<option>' . h($t) . '</option>';
echo '</select><button class="btn">Nuova scheda</button></form></div>';

if ($msg) echo '<div class="msg ' . $tipo . '">' . h($msg) . '</div>';
if (!atlante_pronto()) { echo '<div class="msg err">Tabelle dell’atlante assenti: lancia <a class="lnk" href="../api/migrate_atlante.php" target="_blank">/api/migrate_atlante.php</a>.</div>'; nc_admin_bottom(); exit; }

echo '<div class="card" style="display:flex;flex-wrap:wrap;gap:28px;align-items:center">';
foreach (['schede' => 'Schede', 'fonti' => 'Fonti', 'citazioni' => 'Citazioni', 'da_verificare' => 'Non verificate'] as $k => $l)
  echo '<div><div style="font-size:12px;color:#8a9184;text-transform:uppercase;letter-spacing:.04em">' . $l . '</div><div style="font:700 24px Poppins,sans-serif">' . (int)($conti[$k] ?? 0) . '</div></div>';
echo '<form method="post" style="margin-left:auto">' . pds_csrf_campo() . '<input type="hidden" name="azione" value="pubblica"><button class="btn ghost" title="Riscrive tutte le pagine che vengono dal database">Ripubblica tutto</button></form></div>';

// filtri
echo '<form class="card" method="get" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr auto;gap:10px;align-items:end">';
echo '<div><label>Cerca</label><input name="q" value="' . h($f['q']) . '" placeholder="titolo, sintesi o id"></div>';
echo '<div><label>Tipologia</label><select name="tipologia"><option value="">Tutte</option>';
foreach (array_keys(PDS_PREFISSI) as $t) echo '<option' . ($f['tipologia'] === $t ? ' selected' : '') . '>' . h($t) . '</option>';
echo '</select></div><div><label>Stato</label><select name="stato"><option value="">Tutti</option>';
foreach (['verificata', 'in_revisione', 'da_verificare', 'documentazione_insufficiente'] as $s) echo '<option value="' . $s . '"' . ($f['stato'] === $s ? ' selected' : '') . '>' . h(str_replace('_', ' ', $s)) . '</option>';
echo '</select></div><div><label>Periodo</label><select name="periodo"><option value="">Tutti</option>';
foreach (atlante_tassonomia('periodo') as $p) echo '<option value="' . h($p['codice']) . '"' . ($f['periodo'] === $p['codice'] ? ' selected' : '') . '>' . h($p['codice'] . ' · ' . $p['anno_inizio'] . '–' . $p['anno_fine']) . '</option>';
echo '</select></div><div><label>Pubblicata</label><select name="pubblicata"><option value="">Tutte</option><option value="1"' . ($f['pubblicata'] === '1' ? ' selected' : '') . '>Sì</option><option value="0"' . ($f['pubblicata'] === '0' ? ' selected' : '') . '>No</option></select></div>';
echo '<button class="btn ghost sm">Filtra</button></form>';

echo '<div class="card" style="padding:0;overflow:auto"><table><thead><tr><th>Id</th><th>Titolo</th><th>Tipologia</th><th>Anni</th><th>Stato</th><th>Fonti</th><th>Sul sito</th></tr></thead><tbody>';
foreach ($schede as $s) {
  $anni = $s['data_inizio'] . ($s['data_fine'] && $s['data_fine'] !== $s['data_inizio'] ? '–' . $s['data_fine'] : '');
  $stato = $s['stato'] === 'verificata' ? '<span style="color:#1F7A3D;font-weight:600">verificata</span>' : '<span style="color:#b26a00;font-weight:600">' . h(str_replace('_', ' ', $s['stato'])) . '</span>';
  echo '<tr><td style="white-space:nowrap;font-weight:600">' . h($s['id']) . '</td>'
     . '<td><a class="lnk" href="scheda.php?id=' . rawurlencode($s['id']) . '">' . h($s['titolo']) . '</a></td>'
     . '<td>' . h($s['tipologia']) . '</td><td style="white-space:nowrap">' . h($anni) . '</td><td>' . $stato . '</td>'
     . '<td>' . (int)$s['n_fonti'] . ((int)$s['n_fonti'] === 0 ? ' <span title="Senza fonti la scheda è un segnaposto" style="color:#b23a3a">!</span>' : '') . '</td>'
     . '<td>' . ((int)$s['pubblicata'] ? '<a class="lnk" href="../' . h(atlante_url_scheda($s)) . '" target="_blank">apri ↗</a>' : '<span style="color:#8a9184">non pubblicata</span>') . '</td></tr>';
}
if (!$schede) echo '<tr><td colspan="7" style="color:#8a9184">Nessuna scheda con questi filtri.</td></tr>';
echo '</tbody></table></div>';
echo '<p class="sub" style="color:#8a9184;font-size:13px">' . count($schede) . ' schede mostrate.</p>';

// la scelta editoriale della Home
echo '<form class="card" method="post">' . pds_csrf_campo() . '<input type="hidden" name="azione" value="home_nessi">'
   . '<div class="sec" style="margin-top:0">Home · Nessi in evidenza</div>'
   . '<div class="field"><label>Identificativi, separati da virgola, nell’ordine in cui compaiono</label><input name="home_nessi" value="' . h(setting_get('home_nessi_evidenza', 'N01,N02')) . '"></div>'
   . '<button class="btn ghost sm">Salva e ripubblica la Home</button></form>';

nc_admin_bottom();
