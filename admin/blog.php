<?php
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/blog.php';

$msg = ''; $msgType = 'ok';
$act = $_POST['action'] ?? '';
try {
  if ($act === 'new_post') {
    $id = blog_post_create(trim($_POST['title'] ?? '')); header('Location: blog_post.php?id=' . (int)$id); exit;
  } elseif ($act === 'delete_post') {
    blog_post_delete($_POST['id']); blog_index_publish_all(); $msg = 'News eliminata.';
  } elseif ($act === 'toggle') {
    $field = $_POST['field'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if (in_array($field, ['published', 'archived', 'featured'], true) && $id) {
      $p = blog_post_get($id);
      if ($p) {
        $val = (int)$p[$field] ? 0 : 1;
        db()->prepare("UPDATE cms_blog_posts SET $field=? WHERE id=?")->execute([$val, $id]);
        if ($field === 'published' || $field === 'archived') {
          // ricalcola se DOPO il cambiamento il post deve essere visibile pubblicamente (pubblicato E non archiviato)
          $published = $field === 'published' ? $val : (int)$p['published'];
          $archived  = $field === 'archived'  ? $val : (int)$p['archived'];
          if ($published && !$archived) blog_publish_post($id);
          else { @unlink(__DIR__ . '/../' . BLOG_DIR . '/' . $p['slug'] . '.html'); blog_index_publish_all(); }
        }
      }
    }
  } elseif ($act === 'cat_save') {
    blog_category_save($_POST['cat_id'] ?? 0, trim($_POST['name'] ?? ''), (int)($_POST['sort'] ?? 0)); $msg = 'Categoria salvata ✓';
  } elseif ($act === 'cat_delete') {
    blog_category_delete($_POST['cat_id']); $msg = 'Categoria eliminata.';
  }
} catch (Throwable $e) { $msgType = 'err'; $msg = $e->getMessage(); }

$ready = blog_table_ready();
require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('blog', 'Blog — VelociBuilder LITE');

if (!$ready) { echo '<div class="hd"><div><h1>Blog</h1></div></div><div class="msg err">Tabelle non trovate. Lancia prima: <a class="lnk" href="../api/migrate_blog.php" target="_blank">/api/migrate_blog.php</a></div>'; nc_admin_bottom(); exit; }

$categories = blog_categories();
$f = [
  'published' => $_GET['pub'] ?? '', 'archived' => $_GET['arch'] ?? '', 'featured' => $_GET['evid'] ?? '',
  'category_id' => $_GET['cat'] ?? '', 'q' => trim($_GET['q'] ?? ''),
];
$posts = blog_posts_filtered($f);
?>
<div class="hd">
  <div><h1>Blog</h1><p class="sub">News e articoli del sito</p></div>
  <div style="display:flex;gap:8px">
    <?php if (blog_comments_table_ready()): $pending = blog_comments_pending_count(); ?>
    <a class="btn ghost" href="blog_comments.php"><i class="ti ti-message-circle"></i> Commenti<?= $pending ? ' <span class="badge" style="margin-left:6px">' . $pending . '</span>' : '' ?></a>
    <?php endif; ?>
    <a class="btn ghost" href="../blog.html" target="_blank"><i class="ti ti-eye"></i> Vedi il blog</a>
  </div>
</div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px"><div class="sec" style="margin:0">Categorie</div></div>
  <?php foreach ($categories as $c): ?>
    <form method="post" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap">
      <input type="hidden" name="action" value="cat_save"><input type="hidden" name="cat_id" value="<?= (int)$c['id'] ?>">
      <input type="text" name="name" value="<?= h($c['name']) ?>" style="flex:0 0 220px">
      <input type="number" name="sort" value="<?= (int)$c['sort'] ?>" style="width:70px" title="ordine">
      <button class="btn sm ghost" type="submit">Salva</button>
      <button class="btn sm danger" type="submit" name="action" value="cat_delete" onclick="return confirm('Eliminare la categoria? Le news restano, senza categoria.')">Elimina</button>
    </form>
  <?php endforeach; ?>
  <form method="post" style="display:flex;gap:8px;align-items:center;margin-top:8px;border-top:1px solid #eef1ec;padding-top:12px">
    <input type="hidden" name="action" value="cat_save"><input type="hidden" name="cat_id" value="0">
    <input type="text" name="name" placeholder="Nuova categoria" style="flex:0 0 220px" required>
    <button class="btn sm" type="submit"><i class="ti ti-plus"></i> Aggiungi categoria</button>
  </form>
</div>

<div class="card">
  <div class="sec" style="margin-top:0">Filtri</div>
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px">
    <div class="field" style="margin:0;flex:0 0 150px"><label>Pubblicazione</label><select name="pub"><option value="">Tutte</option><option value="1" <?= $f['published'] === '1' ? 'selected' : '' ?>>Pubblicate</option><option value="0" <?= $f['published'] === '0' ? 'selected' : '' ?>>Bozze</option></select></div>
    <div class="field" style="margin:0;flex:0 0 170px"><label>Archiviazione</label><select name="arch"><option value="">Nascondi archiviate</option><option value="1" <?= $f['archived'] === '1' ? 'selected' : '' ?>>Mostra archiviate</option></select></div>
    <div class="field" style="margin:0;flex:0 0 130px"><label>In evidenza</label><select name="evid"><option value="">Tutte</option><option value="1" <?= $f['featured'] === '1' ? 'selected' : '' ?>>Sì</option></select></div>
    <div class="field" style="margin:0;flex:0 0 170px"><label>Categoria</label><select name="cat"><option value="">Tutte</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$f['category_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field" style="margin:0;flex:1 1 200px"><label>Cerca</label><input type="text" name="q" value="<?= h($f['q']) ?>" placeholder="titolo o sottotitolo"></div>
    <button class="btn sm ghost" type="submit">Filtra</button>
  </form>

  <form method="post" style="display:flex;gap:8px;margin-bottom:16px">
    <input type="hidden" name="action" value="new_post">
    <input type="text" name="title" placeholder="Titolo della nuova news" style="flex:1" required>
    <button class="btn" type="submit"><i class="ti ti-plus"></i> Nuova news</button>
  </form>

  <?php if (!$posts): ?><p style="color:#8a9184;text-align:center;padding:20px">Nessuna news trovata con questi filtri.</p><?php else: ?>
  <table>
    <thead><tr><th>Titolo / Autore</th><th>Pubb.</th><th>Arch.</th><th>Evid.</th><th>Categoria</th><th>Data</th><th>Route</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($posts as $p): $cat = $p['category_id'] ? blog_category_get($p['category_id']) : null; ?>
      <tr>
        <td><div style="font-weight:600">#<?= sprintf('%03d', $p['id']) ?> <?= h($p['title']) ?></div><div style="color:#8a9184;font-size:12px"><?= h($p['author']) ?></div></td>
        <td><form method="post" style="margin:0"><input type="hidden" name="action" value="toggle"><input type="hidden" name="field" value="published"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn sm <?= $p['published'] ? '' : 'ghost' ?>" type="submit" title="pubblica/spubblica" style="padding:5px 9px"><i class="ti ti-check"></i></button></form></td>
        <td><form method="post" style="margin:0"><input type="hidden" name="action" value="toggle"><input type="hidden" name="field" value="archived"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn sm ghost" type="submit" title="archivia" style="padding:5px 9px;<?= $p['archived'] ? 'color:#1F7A3D' : '' ?>"><i class="ti ti-archive"></i></button></form></td>
        <td><form method="post" style="margin:0"><input type="hidden" name="action" value="toggle"><input type="hidden" name="field" value="featured"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn sm ghost" type="submit" title="in evidenza" style="padding:5px 9px;<?= $p['featured'] ? 'color:#1F7A3D' : '' ?>"><i class="ti ti-star"></i></button></form></td>
        <td><?= $cat ? h($cat['name']) : '<span style="color:#b0b5a8">—</span>' ?></td>
        <td style="white-space:nowrap;font-size:12px;color:#8a9184"><?= h(date('d/m/Y', strtotime($p['created_at']))) ?></td>
        <td><?php if ($p['published']): ?><a class="lnk" href="../<?= h(blog_route($p)) ?>" target="_blank" style="font-size:12px"><?= h(blog_route($p)) ?></a><?php else: ?><span style="color:#b0b5a8;font-size:12px">non pubblicata</span><?php endif; ?></td>
        <td style="white-space:nowrap">
          <a class="btn sm ghost" href="blog_post.php?id=<?= (int)$p['id'] ?>"><i class="ti ti-edit"></i></a>
          <form method="post" style="display:inline" onsubmit="return confirm('Eliminare questa news?')"><input type="hidden" name="action" value="delete_post"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn sm danger" type="submit"><i class="ti ti-trash"></i></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php nc_admin_bottom();
