<?php
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/blog.php';

$postId = (int)($_GET['post'] ?? 0);
$act = $_POST['action'] ?? '';
$msg = ''; $msgType = 'ok';
try {
  if ($act === 'approve') { blog_comment_set_approved($_POST['id'], 1); $msg = 'Commento approvato ✓'; }
  elseif ($act === 'reject') { blog_comment_set_approved($_POST['id'], 0); $msg = 'Commento riportato in attesa.'; }
  elseif ($act === 'delete') { blog_comment_delete($_POST['id']); $msg = 'Commento eliminato.'; }
} catch (Throwable $e) { $msgType = 'err'; $msg = $e->getMessage(); }

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('blog', 'Commenti — VelociBuilder LITE');

if (!blog_comments_table_ready()) { echo '<div class="hd"><div><h1>Commenti</h1></div></div><div class="msg err">Tabella non trovata. Lancia prima: <a class="lnk" href="../api/migrate_blog_comments.php" target="_blank">/api/migrate_blog_comments.php</a></div>'; nc_admin_bottom(); exit; }

$view = ($_GET['view'] ?? 'pending') === 'all' ? 'all' : 'pending';
$rows = $view === 'all' ? blog_comments_all_admin($postId ?: null) : blog_comments_pending_all();
if ($view === 'pending' && $postId) $rows = array_values(array_filter($rows, fn($r) => (int)$r['post_id'] === $postId));
$post = $postId ? blog_post_get($postId) : null;
?>
<div class="hd">
  <div><h1>Commenti<?= $post ? ' — ' . h($post['title']) : '' ?></h1><p class="sub">Moderazione dei commenti sulle news</p></div>
  <a class="btn ghost" href="blog.php"><i class="ti ti-arrow-left"></i> Blog</a>
</div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<div class="card">
  <div style="display:flex;gap:8px;margin-bottom:14px">
    <a class="btn sm <?= $view === 'pending' ? '' : 'ghost' ?>" href="?view=pending<?= $postId ? '&post=' . $postId : '' ?>">In attesa</a>
    <a class="btn sm <?= $view === 'all' ? '' : 'ghost' ?>" href="?view=all<?= $postId ? '&post=' . $postId : '' ?>">Tutti</a>
    <?php if ($postId): ?><a class="btn sm ghost" href="?view=<?= h($view) ?>">Rimuovi filtro articolo</a><?php endif; ?>
  </div>

  <?php if (!$rows): ?>
    <p style="color:#8a9184;text-align:center;padding:28px 10px"><?= $view === 'pending' ? 'Nessun commento in attesa di approvazione.' : 'Nessun commento.' ?></p>
  <?php else: ?>
  <table>
    <thead><tr><th>Data</th><th>Articolo</th><th>Nome / Email</th><th>Commento</th><th>Stato</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): ?>
      <tr>
        <td style="white-space:nowrap;color:#6a7266;font-size:12px"><?= h(date('d/m/Y H:i', strtotime($c['created_at']))) ?></td>
        <td><a class="lnk" href="blog_post.php?id=<?= (int)$c['post_id'] ?>" style="font-size:13px"><?= h($c['post_title']) ?></a></td>
        <td><?= h($c['name']) ?><br><span style="color:#8a9184;font-size:12px"><?= h($c['email']) ?></span></td>
        <td style="max-width:320px;color:#4a5145"><?= nl2br(h($c['body'])) ?></td>
        <td><?= $c['approved'] ? '<span style="color:#1F7A3D;font-weight:600">approvato</span>' : '<span style="color:#8a9184">in attesa</span>' ?></td>
        <td style="white-space:nowrap">
          <?php if (!$c['approved']): ?>
            <form method="post" style="display:inline"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn sm" type="submit" title="approva"><i class="ti ti-check"></i></button></form>
          <?php else: ?>
            <form method="post" style="display:inline"><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn sm ghost" type="submit" title="rimetti in attesa"><i class="ti ti-corner-up-left"></i></button></form>
          <?php endif; ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Eliminare questo commento?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn sm danger" type="submit"><i class="ti ti-trash"></i></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php nc_admin_bottom();
