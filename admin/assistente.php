<?php
// VelociBuilder LITE — Backoffice Assistente AI: costruttore di agenti + config API.
// Agenti = righe in cms_ai_config (sezioni persona/metodo/intervista/knowhow/proposta/config/meta).
// Config API globale in cms_settings. La API key resta lato-server.
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/assistente.php';
require_once __DIR__ . '/../inc/ai-guard.php'; // tetti anti-abuso + consenso privacy

// Gestione avatar (file upload o URL). Ritorna il path da salvare, o $existing se invariato.
function ai_admin_avatar($key, $existing) {
  if (!empty($_FILES['avatar']['name']) && is_uploaded_file($_FILES['avatar']['tmp_name'] ?? '')) {
    $tmp = $_FILES['avatar']['tmp_name'];
    $info = @getimagesize($tmp);
    if ($info === false) throw new Exception("Il file avatar non è un'immagine valida.");
    $ext = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'][$info['mime']] ?? 'png';
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'ai-' . preg_replace('/[^a-z0-9\-]/', '', strtolower($key)) . '-' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) throw new Exception('Upload avatar fallito (permessi cartella uploads?).');
    return 'uploads/' . $name;
  }
  $url = trim((string)($_POST['avatar_url'] ?? ''));
  if ($url !== '') return $url;
  return $existing;
}
function ai_admin_meta_from_post($key, $existing = []) {
  $tone = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['tone'] ?? '')))));
  return [
    'name'   => trim((string)($_POST['name'] ?? '')) ?: ($existing['name'] ?? $key),
    'branch' => trim((string)($_POST['branch'] ?? '')),
    'role'   => trim((string)($_POST['role'] ?? '')),
    'desc'   => trim((string)($_POST['desc'] ?? '')),
    'accent' => preg_replace('/[^#a-zA-Z0-9]/', '', (string)($_POST['accent'] ?? '#da291c')) ?: '#da291c',
    'tone'   => $tone,
    'avatar' => ai_admin_avatar($key, $existing['avatar'] ?? ''),
  ];
}

$flash = ''; $flashType = 'ok';
$act = $_POST['action'] ?? '';
$redir = null;
try {
  if ($act === 'save_section') {
    $a = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['agent'] ?? ''));
    $s = preg_replace('/[^a-z0-9_\-]/', '', (string)($_POST['section'] ?? ''));
    if ($a && $s) { ai_save_section($a, $s, (string)($_POST['content'] ?? '')); $redir = "?tab=agenti&agent=$a&section=$s&ok=1"; }
  } elseif ($act === 'reseed_section') {
    // D · re-sync: riporta la sezione al valore "di fabbrica" del seed (inc/ai-seed.json).
    $a = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['agent'] ?? ''));
    $s = preg_replace('/[^a-z0-9_\-]/', '', (string)($_POST['section'] ?? ''));
    $seedVal = ($a && $s) ? ai_seed_section_value($a, $s) : null;
    if ($seedVal !== null) { ai_save_section($a, $s, $seedVal); $redir = "?tab=agenti&agent=$a&section=$s&ok=1&reseed=1"; }
    else $redir = "?tab=agenti&agent=$a&section=$s&err=noseed";
  } elseif ($act === 'save_config') {
    $a = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['agent'] ?? ''));
    // Si parte dalla config esistente: le chiavi non presenti nel form (es. instradamenti)
    // NON devono sparire perché si è salvato dal pannello.
    $prev = json_decode(ai_section($a, 'config', ''), true);
    if (!is_array($prev)) $prev = [];
    unset($prev['strumento_calcolatore'], $prev['usa_calcolatore'], $prev['modello_override']); // chiavi legacy
    $cfg = [
      'modello'               => trim((string)($_POST['modello'] ?? '')),
      'temperatura'           => (float)($_POST['temperatura'] ?? 0.4),
      'suggerisci_domande'    => !empty($_POST['suggerisci_domande']),
      'voce'                  => !empty($_POST['voce']),
      'messaggio_apertura'    => trim((string)($_POST['messaggio_apertura'] ?? '')),
      'strumenti'             => array_values(array_intersect((array)($_POST['strumenti'] ?? []), array_keys(ai_tools()))),
      'dati_obbligatori'      => array_values(array_intersect((array)($_POST['dati_obbligatori'] ?? []), array_keys(ai_contact_fields()))),
      'email_notifiche'       => trim((string)($_POST['email_notifiche'] ?? '')),
      'titolo_alternativa'    => trim((string)($_POST['titolo_alternativa'] ?? '')),
    ];
    // degli instradamenti dal pannello si cambia solo l'email interna; il resto viene dal seed
    if (isset($_POST['rotta_email']) && is_array($_POST['rotta_email']) && is_array($prev['instradamenti'] ?? null)) {
      foreach ($_POST['rotta_email'] as $tipo => $em) {
        if (is_array($prev['instradamenti'][$tipo] ?? null)) $prev['instradamenti'][$tipo]['email'] = trim((string)$em);
      }
    }
    ai_save_section($a, 'config', json_encode(array_merge($prev, $cfg), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $redir = "?tab=agenti&agent=$a&section=config&ok=1";
  } elseif ($act === 'save_meta') {
    $a = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['agent'] ?? ''));
    $meta = ai_admin_meta_from_post($a, ai_agent_meta($a));
    ai_save_section($a, 'meta', json_encode($meta, JSON_UNESCAPED_UNICODE));
    $redir = "?tab=agenti&agent=$a&section=" . rawurlencode($_POST['section'] ?? 'persona') . "&ok=1";
  } elseif ($act === 'create_agent') {
    $key = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($_POST['key'] ?? '')));
    $meta = ai_admin_meta_from_post($key);
    $new = ai_create_agent($key, $meta, true);
    $redir = "?tab=agenti&agent=$new&section=persona&ok=1";
  } elseif ($act === 'delete_agent') {
    $a = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['agent'] ?? ''));
    ai_delete_agent($a);
    $redir = "?tab=agenti&ok=1";
  } elseif ($act === 'duplicate_agent') {
    $src = preg_replace('/[^a-z0-9\-]/', '', (string)($_POST['agent'] ?? ''));
    $dst = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($_POST['dst_key'] ?? '')));
    $new = ai_duplicate_agent($src, $dst, trim((string)($_POST['dst_name'] ?? '')));
    $redir = "?tab=agenti&agent=$new&section=persona&ok=1";
  } elseif ($act === 'save_api') {
    $p = preg_replace('/[^a-z]/', '', (string)($_POST['provider'] ?? 'openai'));
    $newKey = trim((string)($_POST['api_key'] ?? ''));
    if ($newKey !== '') setting_set('ai_key_' . $p, $newKey);
    setting_set('ai_model_' . $p, trim((string)($_POST['model'] ?? '')));
    if (ai_provider_is_local($p)) setting_set('ai_endpoint_' . $p, trim((string)($_POST['endpoint'] ?? '')));
    setting_set('ai_temp_' . $p, (string)(float)($_POST['temp'] ?? 0.4));
    if (!empty($_POST['make_active'])) setting_set('ai_provider', $p);
    $redir = "?tab=api&prov=$p&ok=1";
  } elseif ($act === 'test_api') {
    $p = preg_replace('/[^a-z]/', '', (string)($_POST['provider'] ?? 'openai'));
    $newKey = trim((string)($_POST['api_key'] ?? ''));
    if ($newKey !== '') setting_set('ai_key_' . $p, $newKey);
    setting_set('ai_model_' . $p, trim((string)($_POST['model'] ?? '')));
    if (ai_provider_is_local($p)) setting_set('ai_endpoint_' . $p, trim((string)($_POST['endpoint'] ?? '')));
    $labels = ai_provider_labels();
    $t = ai_llm_call($p, ai_provider_conn($p), '', [['role'=>'user','content'=>'Rispondi solo: ok']], ['max_tokens'=>20]);
    if (!empty($t['ok'])) { $flash = 'Connessione ' . ($labels[$p] ?? $p) . ' riuscita ✓ (' . mb_substr(trim($t['content']), 0, 40) . ')'; }
    else { $flashType = 'err'; $flash = 'Test ' . ($labels[$p] ?? $p) . ' fallito: ' . ($t['error'] ?? 'errore'); }
  } elseif ($act === 'save_general') {
    setting_set('ai_voice_enabled', empty($_POST['voice']) ? '0' : '1');
    $flash = 'Impostazioni generali salvate ✓';
  } elseif ($act === 'save_guard') {
    // Tetti anti-abuso: l'endpoint di chat è pubblico e ogni chiamata spende token.
    setting_set('ai_rl_enabled', empty($_POST['rl_enabled']) ? '0' : '1');
    foreach (['ai_rl_ip_min','ai_rl_ip_hour','ai_rl_ip_day','ai_rl_sid_day','ai_rl_agent_day','ai_rl_site_day','ai_rl_min_gap','ai_rl_max_chars'] as $k) {
      if (isset($_POST[$k])) setting_set($k, (string) max(0, (int)$_POST[$k]));
    }
    setting_set('ai_consent_required', empty($_POST['consent_required']) ? '0' : '1');
    setting_set('ai_consent_text', trim((string)($_POST['consent_text'] ?? '')));
    setting_set('ai_privacy_url',  trim((string)($_POST['privacy_url'] ?? '')));
    $flash = 'Limiti e consenso salvati ✓';
  }
} catch (Throwable $e) { $flashType = 'err'; $flash = $e->getMessage(); }

if ($redir) { header('Location: assistente.php' . $redir); exit; }
if (isset($_GET['ok']) && !$flash) $flash = 'Salvato ✓';
if (isset($_GET['err'])) { $flashType = 'err'; $flash = $_GET['err']; }

$tab = (string)($_GET['tab'] ?? 'agenti');
if (!in_array($tab, ['agenti', 'api', 'sicurezza'], true)) $tab = 'agenti';
$agents = ai_agent_keys();
$curAgent = preg_replace('/[^a-z0-9\-]/', '', (string)($_GET['agent'] ?? ''));
if (!in_array($curAgent, $agents, true)) $curAgent = $agents[0] ?? '';
$SEC = ai_section_labels(); $GUIDES = ai_section_guides();
$curSection = (string)($_GET['section'] ?? 'persona');
if (!isset($SEC[$curSection])) $curSection = 'persona';
$apiCfg = ai_api_config(); $keySet = $apiCfg['key'] !== '';

require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('assistente', 'Assistente AI — VelociBuilder LITE');
?>
<div class="hd">
  <div><h1>Assistente AI</h1><p class="sub">Costruisci uno o più agenti conversazionali e configura il motore LLM. La chiave resta lato-server.</p></div>
  <div>
    <a class="btn <?= $tab==='agenti'?'':'ghost' ?> sm" href="?tab=agenti"><i class="ti ti-robot"></i> Agenti</a>
    <a class="btn <?= $tab==='api'?'':'ghost' ?> sm" href="?tab=api"><i class="ti ti-plug"></i> API</a>
    <a class="btn <?= $tab==='sicurezza'?'':'ghost' ?> sm" href="?tab=sicurezza"><i class="ti ti-shield-lock"></i> Limiti &amp; consenso</a>
    <a class="btn ghost sm" href="ai-export.php" title="Scarica il cervello degli agenti come ai-seed.json, per rimetterlo nei .md versionati"><i class="ti ti-download"></i> Esporta</a>
  </div>
</div>
<?php if ($flash): ?><div class="msg <?= $flashType ?>"><?= h($flash) ?></div><?php endif; ?>

<?php if ($tab === 'agenti'): ?>
  <div class="card">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:4px">
      <?php foreach ($agents as $k): $m = ai_agent_meta($k); $on = $k===$curAgent; ?>
        <a class="pill<?= $on?' on':'' ?>" href="?tab=agenti&agent=<?= h($k) ?>&section=<?= h($curSection) ?>" style="--ac:<?= h($m['accent']??'#da291c') ?>">
          <?php if (!empty($m['avatar'])): ?><img src="../<?= h($m['avatar']) ?>" alt="" style="width:20px;height:20px;border-radius:5px;object-fit:cover;vertical-align:middle;margin-right:6px"><?php endif; ?>
          <?= h($m['name'] ?? $k) ?> <small><?= h($m['branch'] ?? '') ?></small>
        </a>
      <?php endforeach; ?>
      <button type="button" class="btn ghost sm" onclick="document.getElementById('newAgent').style.display='block';this.style.display='none'"><i class="ti ti-plus"></i> Nuovo agente</button>
    </div>

    <!-- form NUOVO agente (nascosto finché non richiesto) -->
    <form id="newAgent" method="post" enctype="multipart/form-data" style="display:none;margin-top:16px;padding:18px;border:1px dashed #ccd3c6;border-radius:12px;background:#faf8f5">
      <input type="hidden" name="action" value="create_agent">
      <div class="sec" style="margin-top:0">Nuovo agente</div>
      <div class="row">
        <div class="field"><label>Identificativo (slug, minuscolo)</label><input type="text" name="key" placeholder="es. mario-vendite" required></div>
        <div class="field"><label>Nome mostrato</label><input type="text" name="name" placeholder="es. Mario" required></div>
      </div>
      <div class="row">
        <div class="field"><label>Ramo / area</label><input type="text" name="branch" placeholder="es. Vendite / Supporto"></div>
        <div class="field"><label>Ruolo</label><input type="text" name="role" placeholder="es. Consulente commerciale"></div>
      </div>
      <div class="row">
        <div class="field"><label>Colore</label><input type="color" name="accent" value="#2f7ae4"></div>
        <div class="field"><label>Toni (separati da virgola)</label><input type="text" name="tone" placeholder="es. diretto, cordiale, concreto"></div>
      </div>
      <div class="field"><label>Descrizione breve (card iniziale)</label><input type="text" name="desc" placeholder="Cosa fa questo agente, in una frase"></div>
      <div class="row">
        <div class="field"><label>Avatar — carica un'immagine</label><input type="file" name="avatar" accept="image/*"></div>
        <div class="field"><label>…oppure incolla un URL</label><input type="text" name="avatar_url" placeholder="https://… oppure uploads/…"></div>
      </div>
      <p class="sub" style="margin:0 0 12px">Le sezioni (persona, metodo, ecc.) verranno precompilate con un template-guida da personalizzare.</p>
      <button class="btn" type="submit"><i class="ti ti-robot"></i> Crea agente</button>
    </form>
  </div>

  <?php if ($curAgent): $meta = ai_agent_meta($curAgent); ?>
    <!-- IDENTITÀ agente -->
    <details class="card" <?= isset($_GET['editmeta'])?'open':'' ?>>
      <summary style="cursor:pointer;font-family:'Poppins',sans-serif;font-weight:700;color:#1F7A3D">Identità di <?= h($meta['name']) ?> — nome, avatar, colore…</summary>
      <form method="post" enctype="multipart/form-data" style="margin-top:16px">
        <input type="hidden" name="action" value="save_meta">
        <input type="hidden" name="agent" value="<?= h($curAgent) ?>">
        <input type="hidden" name="section" value="<?= h($curSection) ?>">
        <div style="display:flex;gap:16px;align-items:center;margin-bottom:12px">
          <div style="width:56px;height:56px;border-radius:14px;background:<?= h($meta['accent']) ?> <?= !empty($meta['avatar'])?"url(../".h($meta['avatar']).") center/cover":'' ?>;flex-shrink:0"></div>
          <div class="sub">Identificativo: <code><?= h($curAgent) ?></code></div>
        </div>
        <div class="row">
          <div class="field"><label>Nome</label><input type="text" name="name" value="<?= h($meta['name']) ?>"></div>
          <div class="field"><label>Ramo / area</label><input type="text" name="branch" value="<?= h($meta['branch']) ?>"></div>
        </div>
        <div class="row">
          <div class="field"><label>Ruolo</label><input type="text" name="role" value="<?= h($meta['role']) ?>"></div>
          <div class="field"><label>Colore</label><input type="color" name="accent" value="<?= h($meta['accent']) ?>"></div>
        </div>
        <div class="field"><label>Descrizione breve (card iniziale)</label><input type="text" name="desc" value="<?= h($meta['desc'] ?? '') ?>"></div>
        <div class="field"><label>Toni (separati da virgola)</label><input type="text" name="tone" value="<?= h(implode(', ', (array)($meta['tone'] ?? []))) ?>"></div>
        <div class="row">
          <div class="field"><label>Avatar — carica un'immagine</label><input type="file" name="avatar" accept="image/*"></div>
          <div class="field"><label>…oppure URL</label><input type="text" name="avatar_url" placeholder="lascia vuoto per non cambiare"></div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Salva identità</button>
          <button class="btn ghost sm" type="submit" form="dupForm"><i class="ti ti-copy"></i> Duplica</button>
          <button class="btn danger sm" type="submit" form="delForm" onclick="return confirm('Eliminare definitivamente questo agente e tutte le sue sezioni?')"><i class="ti ti-trash"></i> Elimina</button>
        </div>
      </form>
      <form id="delForm" method="post" style="display:none"><input type="hidden" name="action" value="delete_agent"><input type="hidden" name="agent" value="<?= h($curAgent) ?>"></form>
      <form id="dupForm" method="post" style="display:none" onsubmit="var k=prompt('Identificativo del nuovo agente (slug):');if(!k)return false;this.dst_key.value=k;this.dst_name.value=prompt('Nome del nuovo agente:')||'';return true;">
        <input type="hidden" name="action" value="duplicate_agent"><input type="hidden" name="agent" value="<?= h($curAgent) ?>">
        <input type="hidden" name="dst_key" value=""><input type="hidden" name="dst_name" value="">
      </form>
    </details>

    <!-- SEZIONI -->
    <div class="card">
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">
        <?php foreach ($SEC as $sk => $sl): ?>
          <a class="stab<?= $sk===$curSection?' on':'' ?>" href="?tab=agenti&agent=<?= h($curAgent) ?>&section=<?= h($sk) ?>"><?= h($sl) ?></a>
        <?php endforeach; ?>
      </div>

      <!-- pannello guida -->
      <div style="display:flex;gap:10px;padding:12px 14px;background:#eef5f0;border:1px solid #cfe6d8;border-radius:10px;margin-bottom:16px;font-size:13.5px;color:#2c4636">
        <i class="ti ti-info-circle" style="font-size:18px;color:#1F7A3D;flex-shrink:0"></i>
        <div><b><?= h($SEC[$curSection]) ?>.</b> <?= h($GUIDES[$curSection] ?? '') ?></div>
      </div>

      <?php if ($curSection === 'config'): $c = ai_agent_config($curAgent); ?>
        <!-- FORM configurazione amichevole -->
        <form method="post">
          <input type="hidden" name="action" value="save_config">
          <input type="hidden" name="agent" value="<?= h($curAgent) ?>">
          <div class="field"><label>Modello (override)</label><?= ai_model_select('amodel', 'modello', $c['modello'], $apiCfg['provider'], ['empty_label' => 'Usa il modello globale (' . $apiCfg['model'] . ')']) ?><p class="sub" style="margin:6px 0 0">Scegli dal menu o "Altro (digita)". Vuoto = usa il modello globale.</p></div>
          <div class="field">
            <label>Temperatura: <span id="tlab"><?= h($c['temperatura'] ?? 0.4) ?></span> <span class="sub" style="font-weight:400">— creatività delle risposte</span></label>
            <input type="range" name="temperatura" min="0" max="1" step="0.1" value="<?= h($c['temperatura'] ?? 0.4) ?>" oninput="document.getElementById('tlab').textContent=this.value">
            <p class="sub" style="margin:4px 0 0">0 = risposte precise e coerenti · 1 = più creative e varie. Per un consulente commerciale conviene 0,3–0,5.</p>
          </div>
          <div class="field"><label style="font-weight:600"><input type="checkbox" name="suggerisci_domande" value="1" <?= $c['suggerisci_domande']?'checked':'' ?> style="width:auto;margin-right:8px">Suggerisci domande rapide</label><p class="sub" style="margin:4px 0 0">Mostra i chip di risposta rapida sotto la chat per guidare il cliente.</p></div>
          <div class="field"><label style="font-weight:600"><input type="checkbox" name="voce" value="1" <?= ($c['voce']===null?$apiCfg['voice']:$c['voce'])?'checked':'' ?> style="width:auto;margin-right:8px">Abilita la voce per questo agente</label><p class="sub" style="margin:4px 0 0">Attiva il microfono e la lettura vocale nel widget (Web Speech API del browser).</p></div>
          <div class="field"><label>Messaggio di apertura (opzionale)</label><textarea name="messaggio_apertura" style="min-height:60px" placeholder="Se compilato, sarà il primo messaggio esatto dell'agente. Vuoto = apertura generata dall'AI."><?= h($c['messaggio_apertura']) ?></textarea></div>

          <div class="sec">Dati richiesti al cliente</div>
          <div class="field">
            <label>Obbligatori prima della stima e della proposta</label>
            <div style="display:flex;gap:16px;flex-wrap:wrap;padding:4px 0">
              <?php foreach (ai_contact_fields() as $fk => $fl): ?>
                <label style="font-weight:500;display:inline-flex;align-items:center;gap:7px;margin:0">
                  <input type="checkbox" name="dati_obbligatori[]" value="<?= h($fk) ?>" <?= in_array($fk, $c['dati_obbligatori'], true)?'checked':'' ?> style="width:auto"><?= h($fl) ?>
                </label>
              <?php endforeach; ?>
            </div>
            <p class="sub" style="margin:6px 0 0">L'agente non darà cifre né proposte finché non ha questi dati, e chiederà <b>solo</b> questi. Deseleziona ciò che non vuoi rendere obbligatorio (es. il cellulare). Se togli tutto, non blocca nulla.</p>
          </div>
          <div class="field">
            <label>Email per le notifiche dei contatti raccolti</label>
            <?php require_once __DIR__ . '/../inc/recipients.php'; $defR = nc_default_recipient(); ?>
            <input type="text" name="email_notifiche" value="<?= h($c['email_notifiche']) ?>" placeholder="vuoto = <?= h($defR ?: 'destinatario predefinito del sito') ?>">
            <p class="sub" style="margin:6px 0 0">Ogni volta che si conclude una conversazione, i dati raccolti e la proposta vengono inviati qui (più indirizzi separati da virgola). Vuoto = destinatario predefinito del sito: <b><?= h($defR ?: 'nessuno') ?></b>, l'admin scelto in <a class="lnk" href="utenti.php">Utenti</a>.</p>
          </div>
          <div class="field"><label style="font-weight:600">Strumenti deterministici</label>
            <p class="sub" style="margin:2px 0 8px;line-height:1.5">Funzioni eseguite dal <b>server</b>: l'agente le invoca compilando un campo, ma il risultato (una cifra, una configurazione) lo produce il motore — mai il modello. Lasciali spenti per un agente che non deve dare numeri.</p>
            <?php foreach (ai_tools() as $tn => $t): ?>
              <label style="display:block;font-weight:500;margin-bottom:6px"><input type="checkbox" name="strumenti[]" value="<?= h($tn) ?>" <?= in_array($tn, $c['strumenti'], true)?'checked':'' ?> style="width:auto;margin-right:8px"><b><?= h($t['label']) ?></b> <span class="sub" style="font-weight:400">— <?= h($t['descrizione']) ?></span></label>
            <?php endforeach; ?>
          </div>
          <div class="field">
            <label>Titolo del blocco "soluzione alternativa"</label>
            <input type="text" name="titolo_alternativa" value="<?= h($c['titolo_alternativa']) ?>" placeholder="Da valutare insieme al consulente">
            <p class="sub" style="margin:6px 0 0">Intestazione del secondo blocco della proposta (quello senza prezzi, da approfondire col consulente).</p>
          </div>
          <?php if ($c['instradamenti']): ?>
            <div class="field"><label>Instradamenti configurati</label>
              <p class="sub" style="margin:2px 0 8px">Tipi di interlocutore gestiti: <b><?= h(implode(', ', array_keys($c['instradamenti']))) ?></b>. Per ciascuno puoi indicare un indirizzo interno dedicato; vuoto = l'email per le notifiche qui sopra. Chi ricontatta, oggetto e nota si modificano dal seed dell'agente (<code>agente.json</code>).</p>
              <?php foreach ($c['instradamenti'] as $rt => $rv): if (!is_array($rv)) continue; ?>
                <div style="display:flex;gap:10px;align-items:center;margin-bottom:6px;flex-wrap:wrap">
                  <span style="min-width:170px;font-weight:600;font-size:13px"><?= h($rv['etichetta'] ?? $rt) ?> <span class="sub" style="font-weight:400">(<?= h($rt) ?>)</span></span>
                  <input type="text" name="rotta_email[<?= h($rt) ?>]" value="<?= h($rv['email'] ?? '') ?>" placeholder="vuoto = email per le notifiche" style="flex:1;min-width:220px">
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Salva configurazione</button>
        </form>
        <?= ai_model_select_js() ?>
      <?php else:
        $secContent = ai_section($curAgent, $curSection);
        // l'editor email parte dal template predefinito se la sezione è ancora vuota
        if ($curSection === 'email' && trim($secContent) === '') $secContent = ai_email_default_template();
        $seedHas   = ai_seed_section_value($curAgent, $curSection) !== null;   // D · re-sync disponibile?
        $brainTok  = ai_prompt_brain_tokens($curAgent);                        // E · dimensione cervello
        $inPrompt  = in_array($curSection, ['persona', 'metodo', 'intervista', 'knowhow'], true);
      ?>
        <!-- editor della sezione: testo per i .md, HTML con barra comandi per l'email -->
        <form method="post" id="secForm">
          <input type="hidden" name="action" value="save_section">
          <input type="hidden" name="agent" value="<?= h($curAgent) ?>">
          <input type="hidden" name="section" value="<?= h($curSection) ?>">
          <?php if ($curSection === 'email'): ?>
            <div style="display:flex;gap:6px;align-items:center;margin-bottom:10px;flex-wrap:wrap">
              <button type="button" class="mtab on" id="tabVisual"><i class="ti ti-eye-edit"></i> Visuale</button>
              <button type="button" class="mtab" id="tabCode"><i class="ti ti-code"></i> Codice HTML</button>
              <span class="sub" style="margin-left:auto">I segnaposto <code>{{…}}</code> sono protetti: l'editor non li altera.</span>
            </div>
          <?php endif; ?>
          <textarea name="content" id="secEditor" spellcheck="false" style="min-height:440px;font-family:ui-monospace,'IBM Plex Mono',monospace;font-size:12.5px;line-height:1.55"><?= h($secContent) ?></textarea>
          <div style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="btn" type="submit"><i class="ti ti-device-floppy"></i> Salva <?= h($SEC[$curSection]) ?></button>
            <?php if ($curSection === 'email'): ?>
              <button class="btn ghost" type="button" onclick="aiMailPreview()"><i class="ti ti-eye"></i> Anteprima</button>
              <button class="btn ghost sm" type="button" onclick="aiMailReset()"><i class="ti ti-rotate"></i> Ripristina predefinito</button>
            <?php endif; ?>
            <?php if ($seedHas): ?>
              <button class="btn ghost sm" type="submit" form="reseedForm" onclick="return confirm('Riportare «<?= h($SEC[$curSection]) ?>» al testo iniziale del seed? Le modifiche fatte qui andranno perse.')"><i class="ti ti-rotate-2"></i> Ripristina dal seed</button>
            <?php endif; ?>
            <?php if ($curSection !== 'email'): ?>
              <!-- E · contatore token: dimensione della sezione + cervello totale nel system prompt -->
              <span class="sub" id="tokReadout" style="margin-left:auto;font-variant-numeric:tabular-nums">
                <span id="tokSec">—</span> token in questa sezione<?php if ($inPrompt): ?> · cervello totale nel prompt ≈ <strong id="tokBrain"><?= number_format($brainTok, 0, ',', '.') ?></strong> token<?php endif; ?>
              </span>
            <?php endif; ?>
          </div>
        </form>
        <?php if ($seedHas): ?>
          <form id="reseedForm" method="post" style="display:none">
            <input type="hidden" name="action" value="reseed_section">
            <input type="hidden" name="agent" value="<?= h($curAgent) ?>">
            <input type="hidden" name="section" value="<?= h($curSection) ?>">
          </form>
        <?php endif; ?>
        <?php if ($curSection !== 'email'): ?>
          <script>
          (function(){
            var ta = document.getElementById('secEditor'), out = document.getElementById('tokSec');
            var brain = document.getElementById('tokBrain');
            var base = <?= (int)($brainTok - ai_estimate_tokens($secContent)) ?>; // cervello meno questa sezione
            var inPrompt = <?= $inPrompt ? 'true' : 'false' ?>;
            function fmt(n){ return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
            function upd(){
              var t = Math.ceil([...ta.value].length / 3.8);
              out.textContent = fmt(t);
              if (inPrompt && brain) brain.textContent = fmt(base + t);
            }
            if (ta && out) { ta.addEventListener('input', upd); upd(); }
          })();
          </script>
        <?php endif; ?>
        <?php if ($curSection === 'email'): ?>
          <iframe id="mailPrev" style="display:none;width:100%;height:640px;border:1px solid #e3e7de;border-radius:10px;margin-top:14px;background:#fff"></iframe>
          <style>
            .mtab{border:1px solid #d5dacf;background:#fff;border-radius:8px;padding:6px 12px;font-weight:600;font-size:13px;color:#57604f;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
            .mtab.on{background:#1e2418;color:#fff;border-color:#1e2418}
            .tox-tinymce{border-radius:10px!important;border-color:#d5dacf!important}
          </style>
          <script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
          <script>
          (function(){
            var TA = document.getElementById('secEditor');
            var DEFAULT_TPL = <?= json_encode(ai_email_default_template(), JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
            var PH = <?= json_encode(ai_email_placeholders(), JSON_UNESCAPED_UNICODE) ?>;
            var visual = true, ed = null;

            function phItems(){
              return Object.keys(PH).map(function(k){
                return { type:'menuitem', text:k+' — '+PH[k], onAction:function(){
                  if(ed) ed.insertContent('{{'+k+'}}');
                }};
              });
            }
            var INIT = {
              selector:'#secEditor', license_key:'gpl', language:'it', branding:false, promotion:false,
              menubar:false, statusbar:true, height:560, toolbar_mode:'sliding',
              // --- preservazione dell'HTML: è un template email, non va normalizzato ---
              valid_elements:'*[*]', extended_valid_elements:'*[*]', verify_html:false,
              entity_encoding:'raw', convert_urls:false, cleanup:false,
              protect:[/\{\{[\s\S]*?\}\}/g],   // i segnaposto restano intatti (anche dentro style="")
              plugins:'code link lists table image hr charmap searchreplace fullscreen preview visualblocks',
              toolbar:'undo redo | blocks fontsizeinput | bold italic underline strikethrough | forecolor backcolor |'
                    + ' alignleft aligncenter alignright | bullist numlist outdent indent | link unlink image table hr |'
                    + ' segnaposto | removeformat visualblocks | searchreplace | code preview fullscreen',
              content_style:'body{font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1a1817;background:#edeae5;padding:10px}',
              setup:function(editor){
                ed = editor;
                editor.ui.registry.addMenuButton('segnaposto', {
                  text:'Segnaposto', tooltip:'Inserisci un segnaposto della proposta',
                  fetch:function(cb){ cb(phItems()); }
                });
              }
            };
            if (window.tinymce) tinymce.init(INIT);

            function sync(){ if(window.tinymce && visual && tinymce.get('secEditor')) tinymce.triggerSave(); }
            var f = document.getElementById('secForm'); if(f) f.addEventListener('submit', sync);

            document.getElementById('tabVisual').addEventListener('click', function(){
              if(visual) return; visual = true;
              this.classList.add('on'); document.getElementById('tabCode').classList.remove('on');
              if(window.tinymce && !tinymce.get('secEditor')) tinymce.init(INIT);
            });
            document.getElementById('tabCode').addEventListener('click', function(){
              if(!visual) return; sync(); visual = false;
              this.classList.add('on'); document.getElementById('tabVisual').classList.remove('on');
              if(window.tinymce && tinymce.get('secEditor')) tinymce.get('secEditor').remove();
              TA.style.display='';
            });

            window.aiMailPreview = function(){
              sync();
              var f2=document.getElementById('mailPrev');
              f2.style.display=''; f2.srcdoc=TA.value;
              f2.scrollIntoView({behavior:'smooth',block:'nearest'});
            };
            window.aiMailReset = function(){
              if(!confirm('Ripristinare il template email predefinito? Le modifiche non salvate andranno perse.')) return;
              TA.value = DEFAULT_TPL;
              if(window.tinymce && tinymce.get('secEditor')) tinymce.get('secEditor').setContent(DEFAULT_TPL);
            };
          })();
          </script>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <style>
    .pill{border:1px solid #d5dacf;background:#fff;border-radius:100px;padding:7px 14px;font-weight:700;cursor:pointer;font-size:14px;text-decoration:none;color:#1e2418;display:inline-block}
    .pill small{font-weight:500;color:#8a9184;font-size:11px}
    .pill.on{border-color:var(--ac);color:var(--ac);box-shadow:0 0 0 1px var(--ac) inset}
    .stab{border:none;background:#f0f3ee;border-radius:100px;padding:6px 13px;font-weight:600;font-size:13px;color:#57604f;text-decoration:none}
    .stab.on{background:#1e2418;color:#fff}
    details.card summary::-webkit-details-marker{display:none}
    code{background:#f0f3ee;padding:1px 6px;border-radius:5px;font-size:12px}
  </style>

<?php elseif ($tab === 'api'): /* ---------------- tab API: master-detail per-provider ---------------- */
  $PROV = ai_provider_labels();
  $active = $apiCfg['provider'];
  $selProv = (string)($_GET['prov'] ?? '');
  if (!isset($PROV[$selProv])) $selProv = $active;
  $selKey = ai_provider_key($selProv); $selLocal = ai_provider_is_local($selProv);
?>
  <div class="card" style="display:flex;gap:0;padding:0;overflow:hidden">
    <div style="width:232px;border-right:1px solid #e3e7de;padding:12px 10px;flex-shrink:0;background:#fbfcfb">
      <div class="sub" style="padding:2px 10px 8px;font-weight:700">Provider</div>
      <?php foreach ($PROV as $p => $pl): $conf = ai_provider_configured($p); ?>
        <a class="provitem<?= $p===$selProv?' on':'' ?>" href="?tab=api&prov=<?= $p ?>">
          <span><?= h($pl) ?></span>
          <span style="display:flex;align-items:center;gap:6px;flex-shrink:0">
            <?php if ($conf): ?><i class="ti ti-circle-check" style="color:#1F7A3D" title="configurato"></i><?php endif; ?>
            <?php if ($p===$active): ?><span class="provactive">attivo</span><?php endif; ?>
          </span>
        </a>
      <?php endforeach; ?>
      <p class="sub" style="padding:10px 10px 0;line-height:1.45">Clicca un provider per impostarne i parametri. Quello <b>attivo</b> è usato dagli agenti.</p>
    </div>
    <div style="flex:1;padding:24px;min-width:0">
      <form method="post">
        <input type="hidden" name="provider" value="<?= h($selProv) ?>">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px">
          <div class="sec" style="margin:0"><?= h($PROV[$selProv]) ?></div>
          <?php if ($selProv===$active): ?><span class="provactive">provider attivo</span><?php endif; ?>
        </div>
        <?php if ($selLocal): ?>
          <div class="field"><label>Endpoint locale</label>
            <input type="text" name="endpoint" value="<?= h(ai_provider_endpoint($selProv)) ?>" placeholder="https://…tunnel… oppure http://localhost:1234">
            <p class="sub" style="margin:6px 0 0">Il modello gira in locale/tunnel (Cloudflare, ngrok). Nessuna API key.</p></div>
        <?php else: ?>
          <div class="field"><label>API Key <?= $selKey!=='' ? '<span style="color:#1F7A3D">(impostata)</span>' : '<span style="color:#b23a3a">(non impostata)</span>' ?></label>
            <input type="password" name="api_key" autocomplete="off" placeholder="<?= $selKey!=='' ? '•••••••••••• — lascia vuoto per non cambiare' : 'incolla qui la chiave…' ?>">
            <p class="sub" style="margin:6px 0 0">Salvata lato-server, mai inviata al browser.</p></div>
        <?php endif; ?>
        <div class="field"><label>Modello</label>
          <?= ai_model_select('gmodel', 'model', ai_provider_model($selProv), $selProv) ?>
          <p class="sub" style="margin:6px 0 0">Scegli dal menu del provider, oppure "✏️ Altro (digita)".</p></div>
        <div class="field">
          <label>Temperatura: <span id="tempLab"><?= h(ai_provider_temp($selProv)) ?></span> <span class="sub" style="font-weight:400">— creatività delle risposte</span></label>
          <input type="range" name="temp" min="0" max="1" step="0.1" value="<?= h(ai_provider_temp($selProv)) ?>" oninput="document.getElementById('tempLab').textContent=this.value">
          <p class="sub" style="margin:4px 0 0"><b>0</b> = precisa e coerente · <b>1</b> = più creativa e varia. Per un consulente conviene 0,3–0,5; i singoli agenti possono sovrascriverla.</p>
        </div>
        <div class="field"><label style="font-weight:600"><input type="checkbox" name="make_active" value="1" <?= $selProv===$active?'checked':'' ?> style="width:auto;margin-right:8px">Usa questo provider per gli agenti</label></div>
        <div style="display:flex;gap:10px">
          <button class="btn" type="submit" name="action" value="save_api"><i class="ti ti-device-floppy"></i> Salva</button>
          <button class="btn ghost" type="submit" name="action" value="test_api"><i class="ti ti-plug-connected"></i> Testa connessione</button>
        </div>
        <p class="sub" style="margin:8px 0 0">Il test usa i parametri di <b><?= h($PROV[$selProv]) ?></b> qui sopra.</p>
      </form>
      <?= ai_model_select_js() ?>
    </div>
  </div>

  <form method="post" class="card">
    <div class="sec" style="margin-top:0">Impostazioni generali</div>
    <div class="field"><label style="font-weight:600"><input type="checkbox" name="voice" value="1" <?= $apiCfg['voice']?'checked':'' ?> style="width:auto;margin-right:8px">Voce abilitata di default</label>
      <p class="sub" style="margin:4px 0 0">Microfono e lettura vocale nel widget; i singoli agenti possono sovrascrivere.</p></div>
    <button class="btn sm" type="submit" name="action" value="save_general"><i class="ti ti-device-floppy"></i> Salva</button>
  </form>

  <style>
    .provitem{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:9px 11px;border-radius:9px;text-decoration:none;color:#1e2418;font-size:14px;font-weight:600;margin-bottom:2px}
    .provitem:hover{background:#eef3ee}
    .provitem.on{background:#e7f4ea;color:#1F7A3D}
    .provactive{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#1F7A3D;background:#dcefe1;padding:2px 7px;border-radius:100px}
  </style>

<?php else: /* ---------------- tab SICUREZZA: tetti anti-abuso + consenso ---------------- */
  // Consumo di oggi, per capire se i tetti sono tarati bene.
  $useTot = 0; $useRows = []; $useIp = [];
  try {
    $useTot = (int) db()->query('SELECT COALESCE(SUM(cost),0) FROM cms_ai_usage WHERE created_at >= CURDATE()')->fetchColumn();
    $useRows = db()->query('SELECT agent_key, kind, COUNT(*) n, SUM(cost) c FROM cms_ai_usage WHERE created_at >= CURDATE() GROUP BY agent_key, kind ORDER BY c DESC')->fetchAll();
    $useIp = db()->query('SELECT ip, SUM(cost) c FROM cms_ai_usage WHERE created_at >= CURDATE() GROUP BY ip ORDER BY c DESC LIMIT 5')->fetchAll();
  } catch (Throwable $e) { $useErr = 'Contatori non disponibili: lancia la migration <code>/api/migrate_assistente.php</code>.'; }
?>
  <form method="post" class="card">
    <div class="sec" style="margin-top:0">Tetti anti-abuso</div>
    <p class="sub" style="margin:0 0 14px;line-height:1.5">
      <code>api/assistente/chat.php</code> è pubblico e anonimo: ogni messaggio spende token sulla
      <b>tua</b> chiave. Questi tetti limitano quanto può consumare un singolo visitatore, una
      singola conversazione, un agente e l'intero sito in una giornata. Una proposta pesa 4, un
      messaggio di chat 1. <b>0 = nessun limite</b> su quella voce.
    </p>
    <div class="field"><label style="font-weight:600"><input type="checkbox" name="rl_enabled" value="1" <?= ai_rl_conf('ai_rl_enabled')?'checked':'' ?> style="width:auto;margin-right:8px">Tetti attivi</label></div>
    <div class="row">
      <div class="field"><label>Per IP · al minuto</label><input type="number" min="0" name="ai_rl_ip_min" value="<?= (int)ai_rl_conf('ai_rl_ip_min') ?>"></div>
      <div class="field"><label>Per IP · all'ora</label><input type="number" min="0" name="ai_rl_ip_hour" value="<?= (int)ai_rl_conf('ai_rl_ip_hour') ?>"></div>
    </div>
    <div class="row">
      <div class="field"><label>Per IP · al giorno</label><input type="number" min="0" name="ai_rl_ip_day" value="<?= (int)ai_rl_conf('ai_rl_ip_day') ?>"></div>
      <div class="field"><label>Per conversazione · al giorno</label><input type="number" min="0" name="ai_rl_sid_day" value="<?= (int)ai_rl_conf('ai_rl_sid_day') ?>"></div>
    </div>
    <div class="row">
      <div class="field"><label>Per agente · al giorno</label><input type="number" min="0" name="ai_rl_agent_day" value="<?= (int)ai_rl_conf('ai_rl_agent_day') ?>"></div>
      <div class="field"><label>Per tutto il sito · al giorno</label><input type="number" min="0" name="ai_rl_site_day" value="<?= (int)ai_rl_conf('ai_rl_site_day') ?>"></div>
    </div>
    <div class="row">
      <div class="field"><label>Secondi minimi fra due messaggi</label><input type="number" min="0" name="ai_rl_min_gap" value="<?= (int)ai_rl_conf('ai_rl_min_gap') ?>"></div>
      <div class="field"><label>Caratteri massimi di conversazione</label><input type="number" min="0" name="ai_rl_max_chars" value="<?= (int)ai_rl_conf('ai_rl_max_chars') ?>"></div>
    </div>

    <div class="sec">Consenso privacy</div>
    <p class="sub" style="margin:0 0 14px;line-height:1.5">
      L'assistente raccoglie i dati di contatto e archivia <b>l'intera conversazione</b>. Con il
      consenso attivo il cliente deve spuntarlo prima di generare la proposta, e il consenso
      (data, ora, IP, testo) finisce nel registro <a href="consensi.php">Consensi</a>.
    </p>
    <div class="field"><label style="font-weight:600"><input type="checkbox" name="consent_required" value="1" <?= ai_consent_required()?'checked':'' ?> style="width:auto;margin-right:8px">Consenso obbligatorio</label></div>
    <div class="field"><label>Testo del consenso</label>
      <textarea name="consent_text" rows="2" placeholder="<?= h(defined('CONSENT_TEXT') ? CONSENT_TEXT : '') ?>"><?= h(setting_get('ai_consent_text', '')) ?></textarea>
      <p class="sub" style="margin:6px 0 0">Vuoto = testo standard del sito.</p></div>
    <div class="field"><label>Link informativa privacy</label>
      <input type="text" name="privacy_url" value="<?= h(setting_get('ai_privacy_url', '')) ?>" placeholder="<?= h(defined('PRIVACY_URL') ? PRIVACY_URL : 'privacy.html') ?>">
      <p class="sub" style="margin:6px 0 0">Vuoto = <code><?= h(defined('PRIVACY_URL') ? PRIVACY_URL : 'privacy.html') ?></code>.</p></div>

    <button class="btn" type="submit" name="action" value="save_guard"><i class="ti ti-device-floppy"></i> Salva limiti e consenso</button>
  </form>

  <div class="card">
    <div class="sec" style="margin-top:0">Consumo di oggi</div>
    <?php if (!empty($useErr)): ?>
      <p class="sub"><?= $useErr ?></p>
    <?php else: ?>
      <p class="sub" style="margin:0 0 12px">Totale "costo" consumato oggi: <b><?= (int)$useTot ?></b> (tetto sito: <?= ai_rl_conf('ai_rl_site_day') ?: '∞' ?>).</p>
      <?php if ($useRows): ?>
        <table style="width:100%;border-collapse:collapse;font-size:13.5px">
          <tr style="text-align:left;color:#8a9184"><th style="padding:4px 0">Agente</th><th>Tipo</th><th>Chiamate</th><th>Costo</th></tr>
          <?php foreach ($useRows as $r): ?>
            <tr><td style="padding:4px 0"><?= h($r['agent_key']) ?></td><td><?= h($r['kind']) ?></td><td><?= (int)$r['n'] ?></td><td><?= (int)$r['c'] ?></td></tr>
          <?php endforeach; ?>
        </table>
        <?php if ($useIp): ?>
          <p class="sub" style="margin:14px 0 4px;font-weight:700">IP più attivi oggi</p>
          <?php foreach ($useIp as $r): ?><div class="sub"><code><?= h($r['ip']) ?></code> — <?= (int)$r['c'] ?></div><?php endforeach; ?>
        <?php endif; ?>
      <?php else: ?>
        <p class="sub">Nessuna chiamata oggi.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php nc_admin_bottom(); ?>
