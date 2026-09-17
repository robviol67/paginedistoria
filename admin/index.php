<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/messages.php';
require_once __DIR__ . '/../inc/cms.php';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$G = '#1F7A3D';

$err = '';
if (($_POST['action'] ?? '') === 'login') {
  if (nc_login($_POST['username'] ?? '', $_POST['password'] ?? '')) { header('Location: index.php'); exit; }
  $err = 'Credenziali non valide.';
}
if (($_GET['logout'] ?? '') === '1') { nc_logout(); header('Location: index.php'); exit; }

if (!nc_current_user()) {
  header('Cache-Control: no-store');
  ?><!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>VelociBuilder LITE — accesso</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.6.0/dist/tabler-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Public+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>body{margin:0;background:#f4f6f3;font-family:'Public Sans',system-ui,sans-serif;color:#1e2418;display:flex;min-height:100vh;align-items:center;justify-content:center}
  .box{background:#fff;border:1px solid #e3e7de;border-radius:16px;padding:34px;width:340px;max-width:92vw}
  .bl{display:flex;align-items:center;gap:10px;margin-bottom:22px}
  .bl .lg{width:34px;height:34px;border-radius:9px;background:<?=$G?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:19px}
  .bl b{font-family:'Poppins',sans-serif;font-size:18px}.bl small{color:#8a9184;font-size:11px;font-weight:600;letter-spacing:.05em;display:block}
  label{display:block;font-size:13px;font-weight:600;margin:0 0 7px}
  input{width:100%;box-sizing:border-box;padding:12px 13px;border:1px solid #d5dacf;border-radius:9px;font:15px 'Public Sans';margin-bottom:15px}
  button{width:100%;padding:13px;background:<?=$G?>;color:#fff;border:none;border-radius:100px;font-size:15px;font-weight:700;cursor:pointer}
  .er{background:#fdecec;border:1px solid #f3c2c2;color:#b23a3a;padding:10px 13px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:15px}</style></head>
  <body><form class="box" method="post">
    <div class="bl"><span class="lg"><i class="ti ti-bolt"></i></span><span><b>VelociBuilder</b><small>LITE</small></span></div>
    <?php if ($err): ?><div class="er"><?= h($err) ?></div><?php endif; ?>
    <input type="hidden" name="action" value="login">
    <label>Utente</label><input type="text" name="username" autofocus autocomplete="username">
    <label>Password</label><input type="password" name="password" autocomplete="current-password">
    <button type="submit">Entra</button>
  </form></body></html><?php
  exit;
}

// --- Dashboard ---
$u = nc_current_user();
$cntMsg = nc_messages_all(); $totMsg = is_array($cntMsg) ? count($cntMsg) : 0; $unread = nc_messages_unread();
try { $totCons = (int) db()->query('SELECT COUNT(*) FROM gdpr_consent')->fetchColumn(); } catch (Throwable $e) { $totCons = 0; }
try { $totUsers = (int) db()->query('SELECT COUNT(*) FROM cms_users')->fetchColumn(); } catch (Throwable $e) { $totUsers = 0; }

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('dashboard', 'Dashboard — VelociBuilder LITE');
?>
<div class="hd">
  <div><h1>Ciao, <?= h($u['name'] ?: $u['username']) ?></h1><p class="sub">Sito online · nuovosostenibile.it</p></div>
  <div style="display:flex;gap:8px"><a class="btn ghost" href="../index.html" target="_blank"><i class="ti ti-external-link"></i> Vedi sito</a></div>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
  <a class="card" href="pagine.php" style="text-decoration:none;color:inherit;margin:0"><div style="font-size:22px;color:<?=$G?>"><i class="ti ti-file-text"></i></div><div style="font-weight:600;margin-top:6px">Pagine</div><div style="color:#8a9184;font-size:13px">Testi e sezioni del sito</div></a>
  <a class="card" href="prodotti.php" style="text-decoration:none;color:inherit;margin:0"><div style="font-size:22px;color:<?=$G?>"><i class="ti ti-box"></i></div><div style="font-weight:600;margin-top:6px">Prodotti</div><div style="color:#8a9184;font-size:13px">Gamma, foto, schede</div></a>
  <a class="card" href="messaggi.php" style="text-decoration:none;color:inherit;margin:0"><div style="font-size:22px;color:#3b56c4"><i class="ti ti-mail"></i></div><div style="font-weight:600;margin-top:6px">Messaggi <?= $unread ? '<span style="color:'.$G.'">('.$unread.' nuovi)</span>' : '' ?></div><div style="color:#8a9184;font-size:13px"><?= $totMsg ?> richieste ricevute</div></a>
  <a class="card" href="consensi.php" style="text-decoration:none;color:inherit;margin:0"><div style="font-size:22px;color:#3b56c4"><i class="ti ti-shield-check"></i></div><div style="font-weight:600;margin-top:6px">Consensi</div><div style="color:#8a9184;font-size:13px"><?= $totCons ?> registrati · CSV</div></a>
  <a class="card" href="utenti.php" style="text-decoration:none;color:inherit;margin:0"><div style="font-size:22px;color:#57604f"><i class="ti ti-users"></i></div><div style="font-weight:600;margin-top:6px">Utenti</div><div style="color:#8a9184;font-size:13px"><?= $totUsers ?> accessi al pannello</div></a>
</div>
<?php nc_admin_bottom();
