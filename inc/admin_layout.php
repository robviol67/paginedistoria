<?php
// VelociBuilder LITE — layout condiviso del pannello (sidebar + intestazione).
require_once __DIR__ . '/messages.php';
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

function nc_admin_top($active = '', $title = '') {
  $u = function_exists('nc_current_user') ? nc_current_user() : null;
  $unread = nc_messages_unread();
  $G = '#1F7A3D';
  $items = [
    ['dashboard', 'index.php',    'Dashboard', 'ti-layout-dashboard'],
    ['pagine',    'pagine.php',   'Pagine',    'ti-file-text'],
    ['blog',      'blog.php',     'Blog',      'ti-news'],
    ['menu',      'menu.php',     'Menu',      'ti-menu-2'],
    ['prodotti',  'prodotti.php', 'Prodotti',  'ti-box'],
    ['media',     'media.php',    'Media',     'ti-photo'],
    ['liste',     'liste.php',    'Liste',     'ti-list-details'],
    ['forms',     'forms.php',    'Moduli',    'ti-forms'],
    ['messaggi',  'messaggi.php', 'Messaggi',  'ti-mail'],
    ['email',     'email.php',    'Invio email', 'ti-send'],
    ['consensi',  'consensi.php', 'Consensi',  'ti-shield-check'],
    ['velocitracker', 'velocitracker.php', 'VelociTracker', 'ti-plug-connected'],
    ['assistente', 'assistente.php', 'Assistente AI', 'ti-robot'],
    ['opzioni',   'opzioni.php',  'Opzioni',   'ti-adjustments'],
    ['seo',       'seo.php',      'SEO & Social', 'ti-world-search'],
    ['utenti',    'utenti.php',   'Utenti',    'ti-users'],
    ['migrazioni','migrazioni.php','Migrazioni','ti-database'],
  ];
  header('Cache-Control: no-store, no-cache, must-revalidate');
  echo '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>' . h($title ?: 'VelociBuilder LITE') . '</title>';
  echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.6.0/dist/tabler-icons.min.css">';
  echo '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Public+Sans:wght@400;500;600&display=swap" rel="stylesheet">';
  echo '<style>
    *{box-sizing:border-box}
    body{margin:0;background:#f4f6f3;font-family:"Public Sans",system-ui,sans-serif;color:#1e2418}
    .vb{display:flex;min-height:100vh}
    .sb{width:210px;flex-shrink:0;background:#fff;border-right:1px solid #e3e7de;padding:16px 12px;display:flex;flex-direction:column;gap:3px;position:sticky;top:0;height:100vh}
    .brand{display:flex;align-items:center;gap:9px;padding:4px 8px 16px}
    .brand .logo{width:28px;height:28px;border-radius:8px;background:' . $G . ';color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px}
    .brand b{font-family:"Poppins",sans-serif;font-size:15px;font-weight:700;line-height:1}
    .brand small{color:#8a9184;font-size:11px;font-weight:600;letter-spacing:.04em}
    .nav{display:flex;align-items:center;gap:10px;padding:9px 11px;border-radius:9px;color:#57604f;font-size:14px;font-weight:500;text-decoration:none}
    .nav i{font-size:18px}
    .nav:hover{background:#f0f3ee}
    .nav.on{background:#e7f4ea;color:' . $G . '}
    .badge{margin-left:auto;background:' . $G . ';color:#fff;font-size:11px;font-weight:700;border-radius:100px;padding:1px 7px}
    .foot{margin-top:auto;border-top:1px solid #e3e7de;padding-top:10px}
    .main{flex:1;min-width:0;padding:26px 30px 70px}
    .hd{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:22px}
    .hd h1{font-family:"Poppins",sans-serif;font-size:22px;margin:0}
    .hd .sub{color:#8a9184;font-size:13px;margin:3px 0 0}
    .btn{display:inline-flex;align-items:center;gap:7px;padding:11px 20px;background:' . $G . ';color:#fff;border:none;border-radius:100px;font-size:14px;font-weight:700;cursor:pointer;text-decoration:none;font-family:inherit}
    .btn.ghost{background:#fff;color:#1e2418;border:1px solid #d5dacf}
    .btn.sm{padding:6px 13px;font-size:13px;font-weight:600}
    .btn.danger{background:#fff;color:#b23a3a;border:1px solid #f0c2c2}
    .card{background:#fff;border:1px solid #e3e7de;border-radius:14px;padding:22px;margin-bottom:18px}
    .field{margin-bottom:16px}
    label{display:block;font-size:13px;font-weight:600;margin-bottom:7px}
    input,textarea,select{width:100%;padding:11px 13px;border:1px solid #d5dacf;border-radius:9px;font:15px "Public Sans",sans-serif;background:#fff}
    input[type=color]{height:46px;padding:5px}
    .vb-colorfield{display:flex;gap:8px;align-items:center}
    .vb-colorfield .vb-colorpick{width:44px;height:38px;padding:3px;flex-shrink:0;cursor:pointer}
    .vb-colorfield .vb-colorhex{width:110px;flex:0 0 auto;font-family:ui-monospace,"IBM Plex Mono",monospace;text-transform:uppercase}
    textarea{resize:vertical;min-height:70px}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    .msg{padding:12px 16px;border-radius:10px;margin-bottom:18px;font-weight:600;font-size:14px}
    .msg.ok{background:#e7f4ea;border:1px solid #bfe3c9;color:' . $G . '}
    .msg.err{background:#fdecec;border:1px solid #f3c2c2;color:#b23a3a}
    .sec{font-family:"Poppins",sans-serif;font-weight:700;font-size:15px;margin:22px 0 6px;color:' . $G . '}
    table{width:100%;border-collapse:collapse;font-size:13.5px}
    th,td{text-align:left;padding:11px 12px;border-bottom:1px solid #eef1ec;vertical-align:top}
    th{background:#f0f3ee;font-size:12px;text-transform:uppercase;letter-spacing:.02em;color:#5a6255}
    a.lnk{color:' . $G . ';font-weight:600;text-decoration:none}
    @media(max-width:720px){.sb{width:64px}.brand b,.brand small,.nav span{display:none}.row{grid-template-columns:1fr}}
  </style></head><body><div class="vb">';
  echo '<aside class="sb"><div class="brand"><span class="logo"><i class="ti ti-bolt"></i></span><span><b>VelociBuilder</b><br><small>LITE</small></span></div>';
  foreach ($items as $it) {
    $on = $active === $it[0] ? ' on' : '';
    $badge = ($it[0] === 'messaggi' && $unread > 0) ? '<span class="badge">' . $unread . '</span>' : '';
    echo '<a class="nav' . $on . '" href="' . $it[1] . '"><i class="ti ' . $it[3] . '"></i><span>' . $it[2] . '</span>' . $badge . '</a>';
  }
  echo '<div class="foot"><a class="nav" href="logout.php"><i class="ti ti-logout"></i><span>Esci' . ($u ? ' (' . h($u['username']) . ')' : '') . '</span></a></div>';
  echo '</aside><main class="main">';
}

function nc_admin_bottom() {
  echo '</main></div></body></html>';
}
