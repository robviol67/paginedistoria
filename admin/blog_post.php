<?php
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/blog.php';
require_once __DIR__ . '/../inc/richtext_editor.php';
require_once __DIR__ . '/../inc/media_picker.php';
require_once __DIR__ . '/../inc/repeater_field.php';

const BLOG_GALLERY_FIELDS = [
  ['key' => 'image',   'label' => 'Immagine',             'type' => 'image'],
  ['key' => 'alt',     'label' => 'Testo alternativo',    'type' => 'text'],
  ['key' => 'caption', 'label' => 'Didascalia (facolt.)', 'type' => 'text'],
];

$id = (int)($_GET['id'] ?? 0);
$post = blog_table_ready() ? blog_post_get($id) : null;

$msg = ''; $msgType = 'ok';
$act = $_POST['action'] ?? '';
try {
  if ($act === 'save' && $post) {
    blog_post_save($id, $_POST); $msg = 'Salvato ✓'; $post = blog_post_get($id);
  } elseif ($act === 'save_publish' && $post) {
    blog_post_save($id, array_merge($_POST, ['published' => '1'])); blog_publish_post($id);
    $msg = 'Salvato e pubblicato ✓ — online su ' . h(blog_route(blog_post_get($id))); $post = blog_post_get($id);
  }
} catch (Throwable $e) { $msgType = 'err'; $msg = $e->getMessage(); }

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('blog', 'News — VelociBuilder LITE');

if (!blog_table_ready()) { echo '<div class="hd"><div><h1>News</h1></div></div><div class="msg err">Tabelle non trovate. Lancia prima: <a class="lnk" href="../api/migrate_blog.php" target="_blank">/api/migrate_blog.php</a></div>'; nc_admin_bottom(); exit; }
if (!$post) { echo '<div class="hd"><div><h1>News</h1></div></div><div class="msg err">News non trovata. <a class="lnk" href="blog.php">Torna al blog</a></div>'; nc_admin_bottom(); exit; }

$categories = blog_categories();
?>
<style>
.pb-tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid #e3e7de}
.pb-tab{border:none;background:transparent;padding:10px 18px;font:600 14px "Public Sans",sans-serif;color:#8a9184;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px}
.pb-tab.on{color:#1F7A3D;border-bottom-color:#1F7A3D}
</style>
<div class="hd">
  <div><h1>#<?= sprintf('%03d', $post['id']) ?> <?= h($post['title']) ?></h1><p class="sub"><?= $post['published'] ? '<span style="color:#1F7A3D">pubblicata</span>' : '<span style="color:#8a9184">bozza</span>' ?> · <?= h(blog_route($post)) ?></p></div>
  <div style="display:flex;gap:8px">
    <a class="btn ghost" href="blog.php"><i class="ti ti-arrow-left"></i> Blog</a>
    <?php if ($post['published']): ?><a class="btn ghost" href="../<?= h(blog_route($post)) ?>" target="_blank"><i class="ti ti-eye"></i> Vedi online</a><?php endif; ?>
  </div>
</div>
<?php if ($msg): ?><div class="msg <?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<div class="pb-tabs">
  <button type="button" class="pb-tab on" data-tab="struttura">Struttura</button>
  <button type="button" class="pb-tab" data-tab="anteprima">Anteprima</button>
</div>

<div id="tabStruttura">
<form method="post">
  <div class="card">
    <div class="sec" style="margin-top:0">Contenuto</div>
    <div class="row">
      <div class="field"><label>Titolo</label><input type="text" name="title" value="<?= h($post['title']) ?>"></div>
      <div class="field"><label>Occhiello <span style="color:#8a9184;font-weight:400">(etichetta sopra il titolo)</span></label><input type="text" name="eyebrow" value="<?= h($post['eyebrow']) ?>"></div>
    </div>
    <div class="field"><label>Sottotitolo</label><input type="text" name="subtitle" value="<?= h($post['subtitle']) ?>"></div>
    <div class="row">
      <div class="field"><label>Categoria</label><select name="category_id"><option value="">— nessuna —</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$post['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field">
        <label>Tag <span style="color:#8a9184;font-weight:400">(separati da virgola — clic su un tag esistente per aggiungerlo)</span></label>
        <input type="text" name="tags" id="tagsInput" value="<?= h($post['tags']) ?>">
        <?php $existingTags = blog_all_tags_admin(); if ($existingTags): ?>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
          <?php foreach ($existingTags as $t): ?><button type="button" class="btn sm ghost vb-tagpick" data-tag="<?= h($t) ?>" style="padding:4px 10px;font-size:12px"><?= h($t) ?></button><?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="field"><label>Testo per anteprima <span style="color:#8a9184;font-weight:400">(mostrato nell'elenco blog)</span></label><textarea name="excerpt" style="min-height:70px"><?= h($post['excerpt']) ?></textarea></div>
    <div class="field"><label>Testo dell'articolo</label><?= vb_richtext_field('body', $post['body']) ?></div>
  </div>

  <div class="card">
    <div class="sec" style="margin-top:0">Immagine e autore</div>
    <div class="field">
      <label>Immagine di copertina</label>
      <div style="display:flex;gap:8px;align-items:center">
        <img src="<?= $post['cover_image'] ? '../' . h($post['cover_image']) : '../assets/product-placeholder.png' ?>" style="width:70px;height:52px;object-fit:cover;border:1px solid #e3e7de;border-radius:8px;background:#fff" class="vb-imgprev">
        <input type="text" name="cover_image" value="<?= h($post['cover_image']) ?>" placeholder="percorso immagine" style="flex:1" class="vb-imginput">
        <button type="button" class="btn sm ghost" onclick="VBpickMedia((function(inp,img){return function(p){inp.value=p;img.src='../'+p;};})(this.previousElementSibling,this.previousElementSibling.previousElementSibling))"><i class="ti ti-photo"></i> Scegli</button>
      </div>
    </div>
    <div class="row">
      <div class="field"><label>Autore <span style="color:#8a9184;font-weight:400">(utente del pannello)</span></label>
        <select name="author_id"><option value="">— nessuno / autore esterno —</option><?php foreach (nc_users_all() as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)($post['author_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['name'] ?: $u['username']) ?></option><?php endforeach; ?></select>
      </div>
      <div class="field"><label>Autore <span style="color:#8a9184;font-weight:400">(testo libero, per autori esterni — usato solo se sopra è vuoto)</span></label><input type="text" name="author" value="<?= h($post['author']) ?>"></div>
    </div>
  </div>

  <div class="card">
    <div class="sec" style="margin-top:0">Galleria fotografica <span style="color:#8a9184;font-weight:400">(mostrata sotto il testo dell'articolo)</span></div>
    <?php if (!blog_gallery_ready()): ?>
      <div class="msg err">Colonna non trovata. Lancia prima: <a class="lnk" href="../api/migrate_galleries.php" target="_blank">/api/migrate_galleries.php</a></div>
    <?php else: ?>
      <?= vb_repeater_field('gallery_images', BLOG_GALLERY_FIELDS, $post['gallery_images'] ?? '') ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="sec" style="margin-top:0">Stato</div>
    <label style="font-weight:400;font-size:14px;display:block;margin-bottom:10px"><input type="checkbox" name="published" value="1" <?= $post['published'] ? 'checked' : '' ?> style="width:auto"> Pubblicata</label>
    <label style="font-weight:400;font-size:14px;display:block;margin-bottom:10px"><input type="checkbox" name="featured" value="1" <?= $post['featured'] ? 'checked' : '' ?> style="width:auto"> In evidenza</label>
    <label style="font-weight:400;font-size:14px;display:block"><input type="checkbox" name="archived" value="1" <?= $post['archived'] ? 'checked' : '' ?> style="width:auto"> Archiviata</label>
  </div>

  <div class="card">
    <div class="sec" style="margin-top:0">Commenti</div>
    <label style="font-weight:400;font-size:14px;display:block;margin-bottom:10px"><input type="checkbox" name="comments_enabled" value="1" <?= !empty($post['comments_enabled']) ? 'checked' : '' ?> style="width:auto"> Abilita i commenti su questo articolo</label>
    <label style="font-weight:400;font-size:14px;display:block"><input type="checkbox" name="comments_moderation" value="1" <?= !isset($post['comments_moderation']) || $post['comments_moderation'] ? 'checked' : '' ?> style="width:auto"> Richiedi approvazione prima della pubblicazione (moderazione)</label>
    <?php if (blog_comments_table_ready()): $cc = count(blog_comments_all_admin($post['id'])); if ($cc): ?>
      <p style="margin:12px 0 0"><a class="lnk" href="blog_comments.php?post=<?= (int)$post['id'] ?>">Vedi i <?= $cc ?> commenti di questo articolo →</a></p>
    <?php endif; endif; ?>
  </div>

  <div class="card">
    <div class="sec" style="margin-top:0">SEO e condivisione</div>
    <div class="field"><label>Titolo SEO <span style="color:#8a9184;font-weight:400">(vuoto = usa il titolo)</span></label><input type="text" name="seo_title" value="<?= h($post['seo_title']) ?>"></div>
    <div class="field"><label>Descrizione SEO <span style="color:#8a9184;font-weight:400">(vuoto = usa il testo anteprima)</span></label><textarea name="seo_desc"><?= h($post['seo_desc']) ?></textarea></div>
    <div class="field"><label>Immagine condivisione social <span style="color:#8a9184;font-weight:400">(vuoto = usa la copertina)</span></label><input type="text" name="seo_image" value="<?= h($post['seo_image']) ?>"></div>
  </div>

  <div style="display:flex;gap:10px;position:sticky;bottom:0;background:#f4f6f3;padding:12px 0">
    <button class="btn ghost" type="submit" name="action" value="save">Salva bozza</button>
    <button class="btn" type="submit" name="action" value="save_publish"><i class="ti ti-cloud-upload"></i> Salva e pubblica</button>
  </div>
</form>
</div>

<div id="tabAnteprima" style="display:none">
  <iframe id="blogPreviewFrame" style="width:100%;height:78vh;border:1px solid #e3e7de;border-radius:12px;background:#fff" title="Anteprima news"></iframe>
</div>

<script>
(function(){
  document.querySelectorAll('.vb-tagpick').forEach(function(btn){
    btn.addEventListener('click', function(){
      var input = document.getElementById('tagsInput');
      var cur = input.value.split(',').map(function(s){return s.trim();}).filter(Boolean);
      if (cur.indexOf(btn.dataset.tag) === -1) { cur.push(btn.dataset.tag); input.value = cur.join(', '); }
    });
  });
  var POST_ID = <?= (int)$id ?>;
  var tabs=document.querySelectorAll('.pb-tab'), panelStruttura=document.getElementById('tabStruttura'), panelAnteprima=document.getElementById('tabAnteprima'), frame=document.getElementById('blogPreviewFrame');
  tabs.forEach(function(t){ t.addEventListener('click',function(){
    tabs.forEach(function(x){x.classList.remove('on')}); t.classList.add('on');
    if(t.dataset.tab==='anteprima'){ panelStruttura.style.display='none'; panelAnteprima.style.display='block'; frame.src='blog_post_preview.php?id='+POST_ID+'&t='+Date.now(); }
    else { panelAnteprima.style.display='none'; panelStruttura.style.display='block'; }
  });});
})();
</script>

<?php nc_media_picker(); vb_richtext_assets(); vb_repeater_assets(); nc_admin_bottom(); ?>
