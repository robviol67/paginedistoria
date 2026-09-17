<?php
// VelociBuilder LITE — Dashboard Migrazioni.
// Elenca le migrazioni, le esegue (auto-flag come completate) e nasconde quelle già fatte.
// Esecuzione: richiesta HTTP interna all'endpoint /api/migrate_*.php (processo isolato),
// così un eventuale fatal resta confinato e viene registrato come errore.
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/admin_layout.php';

// Tabella di tracciamento (auto-creata; niente migration per lei stessa).
try {
  db()->exec('CREATE TABLE IF NOT EXISTS cms_migrations (
      name    VARCHAR(120) PRIMARY KEY,
      status  VARCHAR(16)  NOT NULL DEFAULT "ok",
      run_at  DATETIME     NOT NULL,
      log     TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) { /* mostrata sotto */ }

// Ordine consigliato (dipendenze) + descrizioni. I file non elencati vengono aggiunti in coda.
$ORDER = [
  'migrate_velocibuilder.php'  => 'Base — utenti admin del pannello + messaggi dei moduli',
  'migrate_cms.php'            => 'Testi editabili delle pagine (override CMS)',
  'migrate_consent.php'        => 'Registro consensi GDPR (moduli/contatti)',
  'migrate_media.php'          => 'Libreria immagini (Media)',
  'migrate_nav.php'            => 'Menu di navigazione editabile',
  'migrate_lists.php'          => 'Liste ripetibili (slider, gallerie, schede, tabelle…)',
  'migrate_products.php'       => 'Catalogo prodotti (famiglie + prodotti)',
  'migrate_forms.php'          => 'Costruttore moduli (form builder)',
  'migrate_pagebuilder.php'    => 'Pagine libere (page builder a blocchi)',
  'migrate_blog.php'           => 'Blog/News (categorie + articoli)',
  'migrate_blog_authors.php'   => 'Blog — autori collegati agli utenti',
  'migrate_blog_comments.php'  => 'Blog — commenti + moderazione',
  'migrate_galleries.php'      => 'Gallerie allegate a prodotti/articoli',
  'migrate_velocitracker.php'  => 'Integrazione CRM VelociTracker',
  'migrate_assistente.php'     => 'Assistente AI — configuratore commerciale (agenti + lead)',
];

$apidir = realpath(__DIR__ . '/../api');
$files = $apidir ? array_map('basename', glob($apidir . '/migrate_*.php') ?: []) : [];
// migrate_all.php non è una migrazione: è l'orchestratore che le lancia tutte.
// Eseguito in-process da qui chiamerebbe exit() sul controllo della chiave,
// uccidendo la pagina del pannello, e registrerebbe un proprio shutdown handler.
// Il "lancia tutte" il pannello ce l'ha già, ed è il ciclo qui sotto.
$files = array_values(array_diff($files, ['migrate_all.php']));
$ordered = [];
foreach ($ORDER as $f => $d) if (in_array($f, $files, true)) $ordered[$f] = $d;
foreach ($files as $f) if (!isset($ordered[$f])) $ordered[$f] = '(migrazione aggiuntiva)';

function mig_done_map() {
  $done = [];
  try { foreach (db()->query('SELECT name, run_at, status FROM cms_migrations') as $r) $done[$r['name']] = $r; }
  catch (Throwable $e) {}
  return $done;
}
/**
 * Esegue una migration IN-PROCESS, con un include isolato.
 *
 * ⚠ Prima questa funzione faceva una richiesta HTTP del server verso il proprio
 * dominio. Su hosting condiviso (Aruba) quella richiesta non torna mai: il
 * processo padre tiene occupato l'unico worker disponibile e aspetta sé stesso,
 * finché non viene ucciso — e il pannello mostra un 500 secco su OGNI voce,
 * mentre le stesse migrazioni aperte per via diretta funzionano benissimo.
 *
 * È lo stesso difetto che `api/migrate_all.php` aveva già chiuso con
 * run_migration_inproc(): la correzione non era mai arrivata fin qui.
 * Vedi CONVENZIONI.md §2 — «verifica in-process, zero HTTP interni».
 *
 * Lo scope è isolato in una closure; @ silenzia i warning di header() emessi dal
 * figlio a output già iniziato; lo status torna a 200 perché una migration può
 * averlo impostato a 500 per un proprio errore.
 */
function mig_run($name) {
  $file = realpath(__DIR__ . '/../api/' . basename($name));
  if (!$file || !is_file($file)) return [false, 0, "File non trovato: $name"];

  // Le migrazioni protette da MIGRATION_KEY la leggono da $_GET. Qui dentro si
  // arriva solo da loggati — un controllo più forte della chiave — quindi gliela
  // si passa, invece di far fallire il lancio dal pannello.
  $keyPrec = $_GET['key'] ?? null;
  if (defined('MIGRATION_KEY')) $_GET['key'] = MIGRATION_KEY;

  ob_start();
  $threw = null;
  try { (function ($__f) { @include $__f; })($file); }
  catch (Throwable $e) { $threw = get_class($e) . ': ' . $e->getMessage(); }
  $body = ob_get_clean();
  http_response_code(200);
  if ($keyPrec === null) unset($_GET['key']); else $_GET['key'] = $keyPrec;

  if ($threw !== null) $body = trim($body . "\n" . $threw);
  $bad = $threw !== null
      || stripos($body, 'ERRORE') !== false || stripos($body, 'Fatal error') !== false
      || stripos($body, 'Parse error') !== false || stripos($body, 'Uncaught') !== false;
  return [!$bad, $bad ? 500 : 200, $body];
}

$done = mig_done_map();
$flash = ''; $flashok = true; $logs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['action'] ?? '';
  if ($act === 'reset' && isset($_POST['name'])) {
    try { $st = db()->prepare('DELETE FROM cms_migrations WHERE name = ?'); $st->execute([$_POST['name']]); } catch (Throwable $e) {}
    header('Location: migrazioni.php'); exit;
  }
  $targets = [];
  if ($act === 'run' && isset($_POST['name']) && isset($ordered[$_POST['name']])) {
    $targets = [$_POST['name']];
  } elseif ($act === 'run_all') {
    foreach ($ordered as $f => $d) if (!isset($done[$f])) $targets[] = $f; // solo le mancanti, in ordine
  }
  $ins = db()->prepare('INSERT INTO cms_migrations (name, status, run_at, log) VALUES (?,?,NOW(),?)
                        ON DUPLICATE KEY UPDATE status=VALUES(status), run_at=VALUES(run_at), log=VALUES(log)');
  $okc = 0; $errc = 0;
  foreach ($targets as $t) {
    [$ok, $code, $body] = mig_run($t);
    $short = trim(mb_substr($body, 0, 4000));
    if ($ok) { try { $ins->execute([$t, 'ok', $short]); } catch (Throwable $e) {} $okc++; $logs[] = "✓ $t\n" . $short; }
    else { $errc++; $logs[] = "✗ $t  (HTTP $code)\n" . $short; }
  }
  $done = mig_done_map();
  $flashok = ($errc === 0 && $okc > 0);
  $flash = $errc === 0 ? "$okc migrazione/i completate con successo." : "$okc ok · $errc con errori (vedi log sotto).";
}

$pending = []; $completed = [];
foreach ($ordered as $f => $d) { if (isset($done[$f])) $completed[$f] = $d; else $pending[$f] = $d; }

nc_admin_top('migrazioni', 'Migrazioni — VelociBuilder LITE');
?>
<div class="hd">
  <div>
    <h1>Migrazioni database</h1>
    <p class="sub">Esegui le migrazioni una volta sola. Quelle completate vengono flaggate e spostate in fondo.</p>
  </div>
  <?php if ($pending): ?>
  <form method="post" onsubmit="return confirm('Eseguo tutte le <?= count($pending) ?> migrazioni mancanti?');">
    <input type="hidden" name="action" value="run_all">
    <button class="btn" type="submit"><i class="ti ti-player-play"></i> Esegui tutte le mancanti (<?= count($pending) ?>)</button>
  </form>
  <?php endif; ?>
</div>

<?php if ($flash): ?><div class="msg <?= $flashok ? 'ok' : 'err' ?>"><?= h($flash) ?></div><?php endif; ?>

<div class="card">
  <div class="sec" style="margin-top:0">Da eseguire (<?= count($pending) ?>)</div>
  <?php if (!$pending): ?>
    <p style="color:#5a6255;font-size:14px;margin:6px 0 0"><i class="ti ti-circle-check" style="color:#1F7A3D"></i> Tutte le migrazioni disponibili sono state eseguite.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Migrazione</th><th>Cosa fa</th><th style="width:220px">Azione</th></tr></thead>
    <tbody>
    <?php foreach ($pending as $f => $d): ?>
      <tr>
        <td><code><?= h($f) ?></code></td>
        <td><?= h($d) ?></td>
        <td>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="run">
            <input type="hidden" name="name" value="<?= h($f) ?>">
            <button class="btn sm" type="submit"><i class="ti ti-player-play"></i> Esegui</button>
          </form>
          <a class="lnk" style="margin-left:8px;font-size:12.5px" href="../api/<?= h($f) ?>" target="_blank" rel="noopener">apri diretta ↗</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($completed): ?>
<details class="card" <?= $pending ? '' : 'open' ?>>
  <summary style="cursor:pointer;font-family:'Poppins',sans-serif;font-weight:700;font-size:15px;color:#1F7A3D">Già eseguite (<?= count($completed) ?>)</summary>
  <table style="margin-top:14px">
    <thead><tr><th>Migrazione</th><th>Eseguita il</th><th style="width:120px">Stato</th><th style="width:120px"></th></tr></thead>
    <tbody>
    <?php foreach ($completed as $f => $d): $r = $done[$f]; ?>
      <tr>
        <td><code><?= h($f) ?></code><br><span style="color:#8a9184;font-size:12px"><?= h($d) ?></span></td>
        <td style="color:#5a6255"><?= h($r['run_at']) ?></td>
        <td><span style="color:#1F7A3D;font-weight:700"><i class="ti ti-circle-check"></i> fatta</span></td>
        <td>
          <form method="post" onsubmit="return confirm('Riproporre <?= h($f) ?> tra le migrazioni da eseguire?');">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="name" value="<?= h($f) ?>">
            <button class="btn sm ghost" type="submit">riproponi</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</details>
<?php endif; ?>

<?php if ($logs): ?>
<div class="card">
  <div class="sec" style="margin-top:0">Log ultima esecuzione</div>
  <pre style="white-space:pre-wrap;background:#f7f9f6;border:1px solid #e3e7de;border-radius:10px;padding:14px;font:12.5px ui-monospace,monospace;color:#2b3327;max-height:420px;overflow:auto"><?= h(implode("\n\n──────────\n\n", $logs)) ?></pre>
</div>
<?php endif; ?>

<?php nc_admin_bottom(); ?>
