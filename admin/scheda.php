<?php
// Pagine di Storia — pannello: l'editor di una scheda.
// Salvare = scrivere nel database E ripubblicare ciò che ne dipende: la pagina
// pubblica cambia subito, non «alla prossima pubblicazione».
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/pds_admin.php';

$id = strtoupper(trim($_GET['id'] ?? ''));
$tipNuova = $_GET['nuova'] ?? '';
$msg = ''; $tipo = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!pds_csrf_ok()) throw new RuntimeException('Sessione scaduta o modulo non valido: ricarica la pagina e riprova. Le modifiche non sono state salvate.');
    $azione = $_POST['azione'] ?? 'salva';
    if ($azione === 'togli') {
      if (empty($_POST['conferma'])) throw new InvalidArgumentException('Per togliere la scheda spunta la conferma.');
      pds_togli_scheda($id);
      pds_pubblica_tutto();
      header('Location: schede.php'); exit;
    }
    $d = $_POST['s'] ?? [];
    $collegati = [
      'periodi' => $_POST['periodi'] ?? [], 'temi' => $_POST['temi'] ?? [],
      'collegamenti' => $_POST['coll'] ?? [], 'citazioni' => $_POST['cit'] ?? [],
    ];
    $id = pds_salva_scheda($id !== '' ? $id : null, $d, $collegati);
    $e = pds_pubblica_tutto();
    header('Location: scheda.php?id=' . rawurlencode($id) . '&salvata=' . rawurlencode((string)$e['secondi'])); exit;
  } catch (Throwable $e) {
    $tipo = 'err'; $msg = $e->getMessage();
  }
}
// (h() arriva con il layout, più sotto: qui il messaggio resta testo semplice
// e si protegge quando si stampa.)
if (isset($_GET['salvata'])) $msg = 'Scheda salvata e sito ripubblicato (' . (float)$_GET['salvata'] . ' s).';

// Il record da mostrare: dal database, o — dopo un errore — da ciò che si era
// scritto, così un salvataggio rifiutato non fa perdere il lavoro.
$r = $id !== '' ? atlante_scheda($id) : null;
if ($id !== '' && !$r && $tipo !== 'err') { header('Location: schede.php'); exit; }
$r = $r ?: ['id' => '', 'tipologia' => $tipNuova ?: 'Evento', 'titolo' => '', 'slug' => '', 'stato' => 'da_verificare', 'pubblicata' => 0];
if ($tipo === 'err' && !empty($_POST['s'])) $r = array_merge($r, $_POST['s']);
$t = $r['tipologia'];

$periodiScheda = $r['id'] ? array_column(atlante_periodi_di_scheda($r['id']), 'periodo_id') : [];
$temiScheda = $r['id'] ? atlante_temi_di_scheda($r['id']) : [];
$coll = [];
if ($r['id']) { $st = db()->prepare('SELECT r.verso_id, r.relazione, s.titolo FROM pds_scheda_relazione r LEFT JOIN pds_schede s ON s.id=r.verso_id WHERE r.scheda_id=? ORDER BY r.ordine'); $st->execute([$r['id']]); $coll = $st->fetchAll(); }
$cit = $r['id'] ? atlante_fonti_di_scheda($r['id']) : [];
if ($tipo === 'err') {
  $periodiScheda = $_POST['periodi'] ?? $periodiScheda; $temiScheda = $_POST['temi'] ?? $temiScheda;
  $coll = array_values($_POST['coll'] ?? $coll); $cit = array_values($_POST['cit'] ?? $cit);
}

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('schede', ($r['id'] ? $r['id'] . ' · ' . $r['titolo'] : 'Nuova scheda') . ' — Pagine di Storia');

$val = fn($k) => h((string)($r[$k] ?? ''));
$testo = fn($k, $lab, $righe = 3, $aiuto = '') => '<div class="field"><label>' . $lab . '</label><textarea name="s[' . $k . ']" rows="' . $righe . '">' . $val($k) . '</textarea>' . ($aiuto ? '<p style="margin:4px 0 0;font-size:12px;color:#8a9184">' . $aiuto . '</p>' : '') . '</div>';
$riga = fn($k, $lab, $ph = '') => '<div class="field"><label>' . $lab . '</label><input name="s[' . $k . ']" value="' . $val($k) . '" placeholder="' . h($ph) . '"></div>';

echo '<div class="hd"><div><h1>' . ($r['id'] ? h($r['id'] . ' · ' . $r['titolo']) : 'Nuova scheda · ' . h($t)) . '</h1>'
   . '<p class="sub"><a class="lnk" href="schede.php">← tutte le schede</a>'
   . ($r['id'] && (int)$r['pubblicata'] ? ' · <a class="lnk" href="../' . h(atlante_url_scheda($r)) . '" target="_blank">apri la pagina pubblica ↗</a>' : '') . '</p></div></div>';
if ($msg) echo '<div class="msg ' . $tipo . '">' . h($msg) . '</div>';

echo '<form method="post" id="modulo-scheda">' . pds_csrf_campo() . '<input type="hidden" name="azione" value="salva"><input type="hidden" name="s[tipologia]" value="' . h($t) . '">';

// ── identità ──
echo '<div class="card"><div class="sec" style="margin-top:0">Identità</div>';
echo $riga('titolo', 'Titolo');
echo '<div class="row">' . $riga('slug', 'Slug (parte dell’indirizzo)', 'si ricava dal titolo se vuoto')
   . '<div class="field"><label>Periodo principale</label><select name="s[periodo_principale]"><option value="">—</option>';
foreach (atlante_tassonomia('periodo') as $p) echo '<option value="' . h($p['codice']) . '"' . (($r['periodo_principale'] ?? '') === $p['codice'] ? ' selected' : '') . '>' . h($p['codice'] . ' · ' . $p['anno_inizio'] . '–' . $p['anno_fine'] . ' · ' . $p['etichetta']) . '</option>';
echo '</select></div></div>';
echo '<div class="row">' . $riga('data_inizio', 'Inizio', 'AAAA, AAAA-MM o AAAA-MM-GG') . $riga('data_fine', 'Fine', 'vuoto se coincide con l’inizio') . '</div>';
echo '<div class="row"><div class="field"><label>Stato di verifica</label><select name="s[stato]">';
foreach (['verificata' => 'verificata', 'in_revisione' => 'in revisione', 'da_verificare' => 'da verificare', 'documentazione_insufficiente' => 'documentazione insufficiente'] as $k => $l)
  echo '<option value="' . $k . '"' . (($r['stato'] ?? '') === $k ? ' selected' : '') . '>' . $l . '</option>';
echo '</select><p style="margin:4px 0 0;font-size:12px;color:#8a9184">Tutto ciò che non è «verificata» esce con l’avviso di revisione ben visibile.</p></div>'
   . $riga('verifica_data', 'Data dell’ultima verifica', 'AAAA-MM-GG') . '</div>';
echo $testo('verifica_note', 'Note di verifica', 2, 'Per chi lavora: non compaiono sulla pagina.');
// Il campo nascosto viene prima della casella: una casella non spuntata non
// si invia, e senza lo 0 il salvataggio terrebbe il valore di prima.
echo '<input type="hidden" name="s[pubblicata]" value="0">';
echo '<label style="display:flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" name="s[pubblicata]" value="1" style="width:auto"' . ((int)($r['pubblicata'] ?? 0) ? ' checked' : '') . '> Pubblicata sul sito</label>';
echo '</div>';

// ── testi ──
echo '<div class="card"><div class="sec" style="margin-top:0">Testi</div>';
echo $testo('sintesi', 'Sintesi', 4, 'Compare in testa alla scheda, nei risultati di Storia e nelle anteprime.');
echo $testo('perche_studiarla', 'Perché studiarla', 3);
echo $testo('cautela', 'Cautela · fatti, atti e interpretazioni', 3);
echo $testo('rilevanza_politica', 'Rilevanza politica', 3, 'Facoltativo.');
echo '</div>';

if ($t === 'Accade nel mondo') {
  echo '<div class="card"><div class="sec" style="margin-top:0">Accade nel mondo · le quattro sezioni</div>'
     . $testo('mondo_nel_mondo', '01 · Accade nel mondo') . $testo('mondo_risposta', '02 · La risposta delle istituzioni italiane')
     . $testo('mondo_ricadute', '03 · Ricadute sulla politica interna') . $testo('mondo_cosa_cambia', '04 · Che cosa cambia per l’Italia') . '</div>';
}
if ($t === 'Nesso') {
  echo '<div class="card"><div class="sec" style="margin-top:0">Nesso · verdetto e istruttoria</div><div class="row"><div class="field"><label>Verdetto provvisorio</label><select name="s[verdetto]"><option value="">non ancora assegnato</option>';
  foreach (atlante_tassonomia('verdetto') as $i => $v) echo '<option value="' . h($v['codice']) . '"' . (($r['verdetto'] ?? '') === $v['codice'] ? ' selected' : '') . '>' . sprintf('%02d', $i + 1) . ' · ' . h($v['etichetta']) . '</option>';
  echo '</select></div>' . $riga('nesso_arco', 'Arco', 'es. 1984–1994') . '</div>';
  echo $testo('verdetto_nota', 'Motivazione del verdetto', 2);
  echo '<div class="row">' . $riga('nesso_a_data', 'Corsia A · data') . $riga('nesso_b_data', 'Corsia B · data') . '</div>';
  echo '<div class="row">' . $testo('nesso_a_testo', 'Corsia A · il fenomeno') . $testo('nesso_b_testo', 'Corsia B · l’esito politico') . '</div>';
  echo $testo('nesso_test', 'Test cronologico') . $testo('nesso_meccanismo', 'Meccanismo ipotizzato');
  echo '<div class="row">' . $testo('nesso_favore', 'Prove da cercare a favore') . $testo('nesso_contro', 'Prove da cercare contro') . '</div>';
  echo $testo('nesso_rischio', 'Rischio di fallacia', 2) . $testo('nesso_ricadute', 'Ricadute politiche da verificare', 2) . $testo('nesso_fonti_da_acquisire', 'Fonti da acquisire', 2);
  echo '<p style="font-size:12px;color:#8a9184;margin:0">Le parti lasciate vuote compaiono sulla pagina come «non compilate», una per una.</p></div>';
}

// ── periodi e temi ──
echo '<div class="card"><div class="sec" style="margin-top:0">Periodi</div><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:6px">';
foreach (atlante_tassonomia('periodo') as $p)
  echo '<label style="display:flex;gap:8px;font-weight:400"><input type="checkbox" style="width:auto" name="periodi[]" value="' . h($p['codice']) . '"' . (in_array($p['codice'], $periodiScheda, true) ? ' checked' : '') . '> ' . h($p['codice'] . ' · ' . $p['etichetta']) . '</label>';
echo '</div><div class="sec">Temi</div><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:6px">';
foreach (atlante_tassonomia('tema') as $tm)
  echo '<label style="display:flex;gap:8px;font-weight:400"><input type="checkbox" style="width:auto" name="temi[]" value="' . h($tm['etichetta']) . '"' . (in_array($tm['etichetta'], $temiScheda, true) ? ' checked' : '') . '> ' . h($tm['etichetta']) . '</label>';
echo '</div></div>';

// ── collegamenti ──
$relazioni = atlante_tassonomia('relazione');
$rigaColl = function ($i, $c) use ($relazioni) {
  $o = '<tr><td><input name="coll[' . $i . '][verso_id]" value="' . h($c['verso_id'] ?? '') . '" list="elenco-schede" placeholder="es. E48" style="width:110px"></td><td><select name="coll[' . $i . '][relazione]">';
  foreach ($relazioni as $rel) $o .= '<option' . (($c['relazione'] ?? '') === $rel['codice'] ? ' selected' : '') . '>' . h($rel['codice']) . '</option>';
  return $o . '</select></td><td style="color:#8a9184">' . h($c['titolo'] ?? '') . '</td><td><label style="font-weight:400;display:flex;gap:6px"><input type="checkbox" style="width:auto" name="coll[' . $i . '][togli]" value="1"> togli</label></td></tr>';
};
echo '<div class="card"><div class="sec" style="margin-top:0">Collegamenti</div><table id="t-coll"><thead><tr><th>Scheda</th><th>Relazione</th><th></th><th></th></tr></thead><tbody>';
foreach ($coll as $i => $c) echo $rigaColl($i, $c);
echo '</tbody></table><template id="tpl-coll">' . $rigaColl('__N__', []) . '</template><button type="button" class="btn ghost sm" data-aggiungi="coll" style="margin-top:10px">+ Collegamento</button></div>';

// ── citazioni ──
$ruoli = atlante_tassonomia('ruolo_fonte');
$rigaCit = function ($i, $c) use ($ruoli) {
  $n = fn($k) => 'cit[' . $i . '][' . $k . ']';
  $v = fn($k) => h((string)($c[$k] ?? ''));
  $o = '<div class="card" style="background:#fafbf9;padding:14px;margin-bottom:10px" data-cit>'
     . '<div style="display:grid;grid-template-columns:150px 1fr 90px auto;gap:10px;align-items:end">'
     . '<div><label>Fonte</label><input name="' . $n('fonte_id') . '" value="' . $v('fonte_id') . '" list="elenco-fonti" placeholder="sigla"></div>'
     . '<div><label>Ruolo</label><select name="' . $n('ruolo') . '">';
  foreach ($ruoli as $ru) $o .= '<option value="' . h($ru['codice']) . '"' . (($c['ruolo'] ?? '') === $ru['codice'] ? ' selected' : '') . '>' . h($ru['etichetta']) . '</option>';
  $o .= '</select></div><div><label>Ordine</label><input name="' . $n('ordine') . '" value="' . h((string)($c['ordine'] ?? $i)) . '"></div>'
     . '<label style="font-weight:400;display:flex;gap:6px;padding-bottom:12px"><input type="checkbox" style="width:auto" name="' . $n('togli') . '" value="1"> togli</label></div>'
     . '<div class="field" style="margin:8px 0"><label>Localizzatore · pagina, seduta, sentenza, documento</label><textarea name="' . $n('localizzatore') . '" rows="2">' . $v('localizzatore') . '</textarea></div>'
     . '<div style="display:grid;grid-template-columns:1fr 150px 2fr 150px;gap:10px">'
     . '<div><label>Tipo di documento</label><input name="' . $n('tipo_documento') . '" value="' . $v('tipo_documento') . '"></div>'
     . '<div><label>Data del documento</label><input name="' . $n('data_documento') . '" value="' . $v('data_documento') . '" placeholder="AAAA-MM-GG"></div>'
     . '<div><label>Indirizzo del documento</label><input name="' . $n('url_specifico') . '" value="' . $v('url_specifico') . '" placeholder="https://…"></div>'
     . '<div><label>Verificato il</label><input name="' . $n('verificato_il') . '" value="' . $v('verificato_il') . '" placeholder="AAAA-MM-GG"></div></div>'
     . '<div class="field" style="margin:8px 0 0"><label>Nota</label><input name="' . $n('nota') . '" value="' . $v('nota') . '"></div></div>';
  return $o;
};
echo '<div class="card"><div class="sec" style="margin-top:0">Fonti citate</div><p style="font-size:13px;color:#8a9184;margin:0 0 12px">Senza localizzatore la citazione esce come «da indicare in revisione»; senza indirizzo del documento il pulsante «Apri» resta spento e si apre il repertorio.</p><div id="t-cit">';
foreach ($cit as $i => $c) echo $rigaCit($i, $c);
echo '</div><template id="tpl-cit">' . $rigaCit('__N__', ['ruolo' => 'primaria', 'ordine' => 999]) . '</template><button type="button" class="btn ghost sm" data-aggiungi="cit">+ Fonte citata</button></div>';

// elenchi per il completamento
echo '<datalist id="elenco-fonti">';
foreach (db()->query('SELECT id, titolo FROM pds_fonti ORDER BY id') as $f) echo '<option value="' . h($f['id']) . '">' . h($f['titolo']) . '</option>';
echo '</datalist><datalist id="elenco-schede">';
foreach (db()->query('SELECT id, titolo FROM pds_schede ORDER BY id') as $s) echo '<option value="' . h($s['id']) . '">' . h($s['titolo']) . '</option>';
echo '</datalist>';

echo '<div style="position:sticky;bottom:0;background:#f4f6f3;padding:14px 0;display:flex;gap:10px;align-items:center;border-top:1px solid #e3e7de">'
   . '<button class="btn">Salva e ripubblica</button><span style="font-size:13px;color:#8a9184">Il salvataggio riscrive la pagina pubblica e ciò che ne dipende.</span></div>';
echo '</form>';

// ── togliere ──
if ($r['id']) {
  $cita = pds_chi_cita($r['id']);
  echo '<form method="post" class="card" style="border-color:#f0c2c2" onsubmit="return confirm(\'Togliere definitivamente ' . h($r['id']) . '?\')">' . pds_csrf_campo()
     . '<input type="hidden" name="azione" value="togli"><div class="sec" style="margin-top:0;color:#b23a3a">Togliere la scheda</div>'
     . '<p style="font-size:14px;margin:0 0 10px">Si toglie dal database con citazioni, collegamenti e pagina. Per nasconderla soltanto basta togliere la spunta «Pubblicata».</p>'
     . ($cita ? '<p style="font-size:14px;margin:0 0 10px"><b>La citano:</b> ' . h(implode(', ', $cita)) . '. I loro rimandi verranno tolti.</p>' : '')
     . '<label style="display:flex;gap:8px;font-weight:400;margin-bottom:10px"><input type="checkbox" style="width:auto" name="conferma" value="1"> Confermo: togliere ' . h($r['id']) . '</label>'
     . '<button class="btn danger sm">Togli la scheda</button></form>';
}
?>
<script>
// Aggiungere una riga: si clona il modello e si dà un indice che non collida.
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-aggiungi]');
  if (!b) return;
  var tipo = b.getAttribute('data-aggiungi');
  var tpl = document.getElementById('tpl-' + tipo);
  var dove = tipo === 'coll' ? document.querySelector('#t-coll tbody') : document.getElementById('t-cit');
  var n = 'n' + Date.now();
  var tmp = document.createElement(tipo === 'coll' ? 'tbody' : 'div');
  tmp.innerHTML = tpl.innerHTML.replace(/__N__/g, n);
  var nuovo = tmp.firstElementChild;
  dove.appendChild(nuovo);
  var primo = nuovo.querySelector('input'); if (primo) primo.focus();
});
</script>
<?php
nc_admin_bottom();
