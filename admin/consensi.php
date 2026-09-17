<?php
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/db.php';

// Esportazione CSV
if (($_GET['export'] ?? '') === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="consensi-nuovosostenibile.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['ID', 'Data/ora', 'Form', 'Nome', 'Email', 'IP', 'User-Agent', 'Testo consenso']);
  try {
    foreach (db()->query('SELECT * FROM gdpr_consent ORDER BY id DESC') as $r) {
      fputcsv($out, [$r['id'], $r['created_at'], $r['form'], $r['nome'], $r['email'], $r['ip'], $r['user_agent'], $r['consent_text']]);
    }
  } catch (Throwable $e) {}
  exit;
}

$rows = []; $err = ''; $tot = 0;
try {
  $tot = (int) db()->query('SELECT COUNT(*) FROM gdpr_consent')->fetchColumn();
  $rows = db()->query('SELECT * FROM gdpr_consent ORDER BY id DESC LIMIT 500')->fetchAll();
} catch (Throwable $e) { $err = 'Tabella non trovata. Lancia prima: /api/migrate_consent.php'; }

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('consensi', 'Consensi — VelociBuilder LITE');
?>
<div class="hd">
  <div><h1>Registro consensi</h1><p class="sub"><?= $err ? 'GDPR' : ('Totale: ' . $tot . ($tot > 500 ? ' (ultimi 500)' : '')) ?></p></div>
  <?php if (!$err): ?><a class="btn" href="consensi.php?export=csv"><i class="ti ti-download"></i> Esporta CSV</a><?php endif; ?>
</div>
<?php if ($err): ?>
  <div class="msg err"><?= h($err) ?></div>
<?php elseif (!$rows): ?>
  <div class="card" style="text-align:center;color:#8a9184;padding:40px">Nessun consenso registrato per ora.</div>
<?php else: ?>
  <div class="card" style="padding:0;overflow:hidden">
    <table>
      <thead><tr><th>Data/ora</th><th>Form</th><th>Nome</th><th>Email</th><th>IP</th><th>Testo consenso</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td style="white-space:nowrap"><?= h($r['created_at']) ?></td>
          <td><span style="font-size:11.5px;font-weight:700;color:#3b56c4"><?= h($r['form']) ?></span></td>
          <td><?= h($r['nome']) ?></td>
          <td><?= h($r['email']) ?></td>
          <td style="white-space:nowrap;color:#6a7266"><?= h($r['ip']) ?></td>
          <td style="max-width:300px;color:#6a7266"><?= h($r['consent_text']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php nc_admin_bottom();
