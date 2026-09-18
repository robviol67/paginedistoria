<?php
// Pagine di Storia — pannello: il repertorio delle fonti (elenco ed editor).
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/pds_admin.php';

$id = strtoupper(trim($_GET['id'] ?? ''));
$nuova = isset($_GET['nuova']);
$msg = ''; $tipo = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!pds_csrf_ok()) throw new RuntimeException('Sessione scaduta o modulo non valido: ricarica la pagina e riprova. Le modifiche non sono state salvate.');
    $id = pds_salva_fonte($nuova ? null : $id, $_POST['f'] ?? []);
    $e = pds_pubblica_tutto();
    header('Location: fonti.php?id=' . rawurlencode($id) . '&salvata=' . rawurlencode((string)$e['secondi'])); exit;
  } catch (Throwable $e) { $tipo = 'err'; $msg = $e->getMessage(); }
}
if (isset($_GET['salvata'])) $msg = 'Fonte salvata e sito ripubblicato (' . (float)$_GET['salvata'] . ' s).';

require_once __DIR__ . '/../inc/admin_layout.php';

// ── elenco ──────────────────────────────────────────────────────────────────
if ($id === '' && !$nuova) {
  nc_admin_top('fonti', 'Fonti — Pagine di Storia');
  $q = trim($_GET['q'] ?? '');
  $sql = 'SELECT f.*, (SELECT COUNT(DISTINCT sf.scheda_id) FROM pds_scheda_fonte sf WHERE sf.fonte_id=f.id) AS n_schede FROM pds_fonti f';
  $par = [];
  if ($q !== '') { $sql .= ' WHERE f.id LIKE ? OR f.titolo LIKE ? OR f.autore_ente LIKE ?'; $par = ["%$q%", "%$q%", "%$q%"]; }
  $st = db()->prepare($sql . ' ORDER BY f.natura, f.titolo'); $st->execute($par);
  $fonti = $st->fetchAll();
  echo '<div class="hd"><div><h1>Fonti</h1><p class="sub">Il repertorio: natura, accesso, limiti e verifica di ogni fonte. Le citazioni puntuali (pagina, seduta, sentenza) si scrivono nelle schede.</p></div><a class="btn" href="fonti.php?nuova=1">Nuova fonte</a></div>';
  if ($msg) echo '<div class="msg ' . $tipo . '">' . h($msg) . '</div>';
  echo '<form class="card" method="get" style="display:flex;gap:10px;align-items:end"><div style="flex:1"><label>Cerca</label><input name="q" value="' . h($q) . '" placeholder="sigla, titolo o ente"></div><button class="btn ghost sm">Cerca</button></form>';
  echo '<div class="card" style="padding:0;overflow:auto"><table><thead><tr><th>Sigla</th><th>Fonte</th><th>Natura</th><th>Accesso</th><th>Verifica</th><th>Schede</th></tr></thead><tbody>';
  foreach ($fonti as $f) {
    echo '<tr><td style="font-weight:600">' . h($f['id']) . '</td><td><a class="lnk" href="fonti.php?id=' . rawurlencode($f['id']) . '">' . h($f['titolo']) . '</a><div style="font-size:12px;color:#8a9184">' . h((string)$f['autore_ente']) . '</div></td>'
       . '<td>' . h((string)$f['natura']) . '</td><td>' . h((string)$f['accesso']) . '</td>'
       . '<td>' . h((string)$f['esito']) . ((string)$f['riscontrato'] === '1' ? '' : ' <span style="color:#b26a00">· solo tecnica</span>') . '</td>'
       . '<td>' . (int)$f['n_schede'] . ((int)$f['n_schede'] === 0 ? ' <span title="Non citata da nessuna scheda" style="color:#b26a00">orfana</span>' : '') . '</td></tr>';
  }
  echo '</tbody></table></div><p class="sub" style="color:#8a9184;font-size:13px">' . count($fonti) . ' fonti.</p>';
  nc_admin_bottom(); exit;
}

// ── editor ──────────────────────────────────────────────────────────────────
$f = $nuova ? ['id' => ''] : atlante_fonte($id);
if (!$f) { header('Location: fonti.php'); exit; }
if ($tipo === 'err' && !empty($_POST['f'])) $f = array_merge($f, $_POST['f']);
nc_admin_top('fonti', ($nuova ? 'Nuova fonte' : $f['id'] . ' · ' . $f['titolo']) . ' — Pagine di Storia');

$v = fn($k) => h((string)($f[$k] ?? ''));
$riga = fn($k, $lab, $ph = '') => '<div class="field"><label>' . $lab . '</label><input name="f[' . $k . ']" value="' . $v($k) . '" placeholder="' . h($ph) . '"></div>';
$testo = fn($k, $lab, $righe = 3) => '<div class="field"><label>' . $lab . '</label><textarea name="f[' . $k . ']" rows="' . $righe . '">' . $v($k) . '</textarea></div>';
$scelta = function ($k, $lab, array $opz) use ($f) {
  $o = '<div class="field"><label>' . $lab . '</label><select name="f[' . $k . ']"><option value="">—</option>';
  $presente = false;
  foreach ($opz as $x) { $sel = ((string)($f[$k] ?? '')) === $x; $presente = $presente || $sel; $o .= '<option' . ($sel ? ' selected' : '') . '>' . h($x) . '</option>'; }
  // Un valore dei dati che non è nell'elenco si tiene: non si perde salvando.
  if (!$presente && !empty($f[$k])) $o .= '<option selected>' . h($f[$k]) . '</option>';
  return $o . '</select></div>';
};

echo '<div class="hd"><div><h1>' . ($nuova ? 'Nuova fonte' : h($f['id'] . ' · ' . $f['titolo'])) . '</h1><p class="sub"><a class="lnk" href="fonti.php">← tutte le fonti</a>'
   . (!$nuova ? ' · <a class="lnk" href="../' . h(atlante_url_fonte($f)) . '" target="_blank">apri la pagina pubblica ↗</a>' : '') . '</p></div></div>';
if ($msg) echo '<div class="msg ' . $tipo . '">' . h($msg) . '</div>';

$categorie = array_column(db()->query("SELECT DISTINCT categoria FROM pds_fonti WHERE categoria IS NOT NULL AND categoria<>'' ORDER BY categoria")->fetchAll(), 'categoria');
echo '<form method="post">' . pds_csrf_campo() . '<div class="card"><div class="sec" style="margin-top:0">La fonte</div>';
echo $nuova ? $riga('id', 'Sigla (non si cambia dopo)', 'es. CAM, ASBI, TRE') : '';
echo $riga('titolo', 'Titolo') . $riga('autore_ente', 'Autore o ente');
echo '<div class="row">' . $scelta('natura', 'Natura', ['primaria', 'storiografica', 'strumento', 'divulgazione']) . $scelta('categoria', 'Materiale', $categorie) . '</div>';
echo '<div class="row">' . $scelta('ambito', 'Ambito', ['italiana', 'internazionale']) . '<div class="row">' . $riga('paese', 'Paese', 'IT, US, UK…') . $riga('lingua', 'Lingua', 'it, en…') . '</div></div>';
echo '<div class="row">' . $riga('url', 'Indirizzo del repertorio', 'https://…') . $scelta('accesso', 'Accesso', array_merge(['libero'], array_column(atlante_tassonomia('avviso_accesso'), 'codice'))) . '</div>';
echo $riga('accesso_nota', 'Nota sull’accesso');
echo '<div class="row">' . $riga('copertura_inizio', 'Copertura dal', 'anno') . $riga('copertura_fine', 'Copertura al', 'vuoto = oggi') . '</div>';
echo '</div><div class="card"><div class="sec" style="margin-top:0">Per chi la usa</div>' . $testo('limiti', 'Limiti') . $testo('come_usarla', 'Come usarla') . '</div>';
echo '<div class="card"><div class="sec" style="margin-top:0">Libri</div><div class="row">' . $riga('editore', 'Editore') . $riga('anno', 'Anno') . '</div><div class="row">' . $riga('edizione', 'Edizione') . $riga('isbn', 'ISBN') . '</div></div>';
echo '<div class="card"><div class="sec" style="margin-top:0">Verifica</div><div class="row">' . $scelta('esito', 'Esito del controllo tecnico', ['raggiungibile', 'spostata', 'irraggiungibile', 'a pagamento']) . $riga('verifica_data', 'Data della verifica', 'AAAA-MM-GG') . '</div>'
   . '<input type="hidden" name="f[riscontrato]" value="0"><label style="display:flex;gap:8px;font-weight:600"><input type="checkbox" style="width:auto" name="f[riscontrato]" value="1"' . ((string)($f['riscontrato'] ?? '') === '1' ? ' checked' : '') . '> Contenuto riscontrato (non solo raggiungibile)</label></div>';
echo '<div style="position:sticky;bottom:0;background:#f4f6f3;padding:14px 0;border-top:1px solid #e3e7de"><button class="btn">Salva e ripubblica</button></div></form>';

if (!$nuova) {
  $usi = atlante_schede_che_usano($f['id']);
  echo '<div class="card"><div class="sec" style="margin-top:0">Schede che la citano (' . count($usi) . ')</div>';
  if (!$usi) echo '<p style="color:#8a9184;margin:0">Nessuna: la fonte è orfana.</p>';
  else {
    echo '<table><tbody>';
    foreach ($usi as $u) echo '<tr><td style="font-weight:600;white-space:nowrap">' . h($u['id']) . '</td><td><a class="lnk" href="scheda.php?id=' . rawurlencode($u['id']) . '">' . h($u['titolo']) . '</a><div style="font-size:12px;color:#8a9184">' . h((string)$u['localizzatore']) . '</div></td></tr>';
    echo '</tbody></table>';
  }
  echo '</div>';
}
nc_admin_bottom();
