<?php
// VelociBuilder LITE — SEO & Condivisione: immagine OG di default, sitemap, robots,
// dati strutturati JSON-LD (LocalBusiness/Organization). Generico per ogni sito.
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/settings.php';
require_once __DIR__ . '/../inc/cms.php';
require_once __DIR__ . '/../inc/seo.php';

try { db()->exec('CREATE TABLE IF NOT EXISTS cms_settings (skey VARCHAR(64) PRIMARY KEY, svalue TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); } catch (Throwable $e) {}

$FIELDS = ['og_image','biz_name','phone','email','street','city','postal','province','country','geo_lat','geo_lng','hours'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $q = [];
  if (in_array($action, ['save','save_publish'], true)) {
    foreach ($FIELDS as $f) if (isset($_POST['seo'][$f])) setting_set('seo_' . $f, trim((string) $_POST['seo'][$f]));
    setting_set('seo_jsonld_on', isset($_POST['seo']['jsonld_on']) ? '1' : '');
    $q['ok'] = 1;
    if ($action === 'save_publish') {
      $pub = 0; $errs = [];
      foreach (array_keys(cms_pages()) as $slug) { try { cms_publish($slug); $pub++; } catch (Throwable $e) { $errs[] = $slug; } }
      $q['pub'] = $pub;
      // dopo la ripubblicazione, riallinea anche la sitemap
      list($sok, $scnt) = seo_generate_sitemap();
      if ($sok) $q['sm'] = $scnt;
    }
  } elseif ($action === 'gen_sitemap') {
    list($ok, $cnt, $info) = seo_generate_sitemap();
    if ($ok) $q['sm'] = $cnt; else $q['smerr'] = rawurlencode($info);
  } elseif ($action === 'gen_robots') {
    list($ok, $info) = seo_generate_robots();
    if ($ok) $q['rb'] = 1; else $q['rberr'] = rawurlencode($info);
  }
  header('Location: seo.php?' . http_build_query($q)); exit;
}

require_once __DIR__ . '/../inc/media_picker.php';
require_once __DIR__ . '/../inc/admin_layout.php';
$g = function ($k, $d = '') { return h(setting_get('seo_' . $k, $d)); };
$on = setting_get('seo_jsonld_on', '') === '1';
$ogPrev = seo_og_default();
$smPath = seo_webroot() . '/sitemap.xml';
$smExists = is_file($smPath);
$robots = seo_current_robots();

nc_admin_top('seo', 'SEO & Condivisione — VelociBuilder LITE');
?>
<div class="hd">
  <div>
    <h1>SEO &amp; Condivisione</h1>
    <p class="sub">Immagine di condivisione, sitemap, robots e dati strutturati. Titolo, descrizione e immagine di <em>ogni singola pagina</em> si modificano invece da <a class="lnk" href="pagine.php">Pagine</a>.</p>
  </div>
</div>

<?php if (isset($_GET['ok'])): ?><div class="msg ok">Impostazioni salvate.<?= isset($_GET['pub']) ? ' ' . (int)$_GET['pub'] . ' pagine ripubblicate.' : '' ?><?= isset($_GET['sm']) ? ' Sitemap: ' . (int)$_GET['sm'] . ' URL.' : '' ?></div><?php endif; ?>
<?php if (isset($_GET['sm']) && !isset($_GET['ok'])): ?><div class="msg ok">Sitemap generata: <?= (int)$_GET['sm'] ?> URL. <a class="lnk" href="<?= h(seo_domain()) ?>/sitemap.xml" target="_blank">Apri sitemap.xml →</a></div><?php endif; ?>
<?php if (isset($_GET['rb'])): ?><div class="msg ok">robots.txt generato. <a class="lnk" href="<?= h(seo_domain()) ?>/robots.txt" target="_blank">Apri robots.txt →</a></div><?php endif; ?>
<?php if (!empty($_GET['smerr'])): ?><div class="msg err">Sitemap non generata: <?= h(rawurldecode($_GET['smerr'])) ?></div><?php endif; ?>
<?php if (!empty($_GET['rberr'])): ?><div class="msg err">robots.txt non generato: <?= h(rawurldecode($_GET['rberr'])) ?></div><?php endif; ?>

<form method="post">
  <div class="card">
    <div class="sec" style="margin-top:0">Immagine di condivisione (default)</div>
    <p class="sub" style="margin-top:0">Usata quando qualcuno condivide un link su WhatsApp, Facebook, LinkedIn… Vale per tutte le pagine che non hanno un'immagine social propria. Consigliata 1200×630px.</p>
    <div class="field">
      <label>URL o file immagine social</label>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <img id="ogPrev" src="<?= h($ogPrev) ?>" onerror="this.style.opacity=.25" style="width:120px;height:63px;object-fit:cover;border:1px solid #e3e7de;border-radius:8px;background:#fff">
        <input type="text" name="seo[og_image]" id="ogInput" value="<?= $g('og_image') ?>" placeholder="uploads/media/… oppure https://…" style="flex:1 1 320px">
        <button type="button" class="btn sm ghost" onclick="VBpickMedia(function(p){document.getElementById('ogInput').value=p;document.getElementById('ogPrev').src='../'+p;document.getElementById('ogPrev').style.opacity=1;})"><i class="ti ti-photo"></i> Scegli</button>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="sec" style="margin-top:0">Dati strutturati (JSON-LD)</div>
    <p class="sub" style="margin-top:0">Aiutano Google a capire chi sei: scheda azienda, contatti e sede (rich result e pannello locale). Compila i dati e attiva l'interruttore.</p>
    <div class="field">
      <label style="display:flex;align-items:center;gap:9px;cursor:pointer">
        <input type="checkbox" name="seo[jsonld_on]" value="1" <?= $on ? 'checked' : '' ?> style="width:17px;height:17px">
        Attiva i dati strutturati (Organization / LocalBusiness / Breadcrumb)
      </label>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="field"><label>Ragione sociale</label><input type="text" name="seo[biz_name]" value="<?= $g('biz_name') ?>" placeholder="<?= h(defined('SITE_NAME')?SITE_NAME:'') ?>"></div>
      <div class="field"><label>Telefono</label><input type="text" name="seo[phone]" value="<?= $g('phone') ?>" placeholder="+39 …"></div>
      <div class="field"><label>Email</label><input type="text" name="seo[email]" value="<?= $g('email') ?>" placeholder="info@…"></div>
      <div class="field"><label>Indirizzo (via e civico)</label><input type="text" name="seo[street]" value="<?= $g('street') ?>" placeholder="Via …, 1"></div>
      <div class="field"><label>Città</label><input type="text" name="seo[city]" value="<?= $g('city') ?>"></div>
      <div class="field"><label>CAP</label><input type="text" name="seo[postal]" value="<?= $g('postal') ?>"></div>
      <div class="field"><label>Provincia (sigla)</label><input type="text" name="seo[province]" value="<?= $g('province') ?>" placeholder="PG"></div>
      <div class="field"><label>Paese (ISO)</label><input type="text" name="seo[country]" value="<?= $g('country', 'IT') ?>" placeholder="IT"></div>
      <div class="field"><label>Latitudine (opz.)</label><input type="text" name="seo[geo_lat]" value="<?= $g('geo_lat') ?>" placeholder="43.30…"></div>
      <div class="field"><label>Longitudine (opz.)</label><input type="text" name="seo[geo_lng]" value="<?= $g('geo_lng') ?>" placeholder="12.33…"></div>
      <div class="field" style="grid-column:1/3"><label>Orari (opz., formato schema.org es. "Mo-Fr 09:00-18:00")</label><input type="text" name="seo[hours]" value="<?= $g('hours') ?>" placeholder="Mo-Fr 09:00-13:00,14:00-18:00"></div>
    </div>
  </div>

  <div style="display:flex;gap:12px;flex-wrap:wrap">
    <button class="btn" type="submit" name="action" value="save_publish"><i class="ti ti-rocket"></i> Salva e ripubblica tutto</button>
    <button class="btn ghost" type="submit" name="action" value="save">Salva soltanto</button>
  </div>
  <p class="sub" style="margin-top:12px">"Salva e ripubblica" applica subito immagine social e dati strutturati a tutte le pagine (rigenera i file statici e la sitemap).</p>
</form>

<div class="card">
  <div class="sec" style="margin-top:0">Sitemap &amp; robots</div>
  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
    <form method="post" style="margin:0"><button class="btn" type="submit" name="action" value="gen_sitemap"><i class="ti ti-sitemap"></i> Genera/aggiorna sitemap.xml</button></form>
    <form method="post" style="margin:0"><button class="btn ghost" type="submit" name="action" value="gen_robots"><i class="ti ti-robot"></i> Genera robots.txt corretto</button></form>
    <?php if ($smExists): ?><a class="lnk" href="<?= h(seo_domain()) ?>/sitemap.xml" target="_blank">sitemap.xml attuale →</a><?php endif; ?>
  </div>
  <p class="sub" style="margin-top:0">La sitemap raccoglie tutte le pagine pubblicate (esclude quelle noindex). Il robots sblocca immagini e PDF e indica la sitemap ai motori.</p>
  <?php if ($robots !== ''): ?><label style="font-size:12px;color:#8a9184">robots.txt attuale</label><pre style="background:#12160f;color:#cfe3c0;padding:12px 14px;border-radius:8px;overflow:auto;font:12px/1.5 ui-monospace,monospace;margin:4px 0 0;max-height:200px"><?= h($robots) ?></pre><?php endif; ?>
</div>

<?php nc_media_picker(); ?>
<?php nc_admin_bottom(); ?>
