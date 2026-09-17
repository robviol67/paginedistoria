<?php
require_once __DIR__ . '/../inc/auth.php';
nc_require_login();
require_once __DIR__ . '/../inc/messages.php';

$act = $_POST['action'] ?? '';
if ($act === 'read' && !empty($_POST['id'])) { nc_messages_mark_read($_POST['id']); header('Location: messaggi.php'); exit; }
// Si torna in GET dopo l'eliminazione: così un aggiornamento della pagina non
// ripropone la POST, e l'esito si dice con ?esito= invece che con una variabile.
if ($act === 'delete' && !empty($_POST['id'])) {
  $fatto = nc_message_delete($_POST['id']);
  header('Location: messaggi.php?esito=' . ($fatto ? 'eliminato' : 'errore')); exit;
}

$esito = $_GET['esito'] ?? '';
$rows = nc_messages_all();
require_once __DIR__ . '/../inc/admin_layout.php';
nc_admin_top('messaggi', 'Messaggi — VelociBuilder LITE');
?>
<div class="hd"><div><h1>Messaggi</h1><p class="sub">Richieste ricevute dai form del sito</p></div></div>
<?php if ($esito === 'eliminato'): ?><div class="msg ok">Messaggio eliminato.</div>
<?php elseif ($esito === 'errore'): ?><div class="msg err">Non è stato possibile eliminare il messaggio.</div><?php endif; ?>
<?php if ($rows === null): ?>
  <div class="msg err">Tabella non trovata. Lancia prima: /api/migrate_velocibuilder.php</div>
<?php elseif (!$rows): ?>
  <div class="card" style="text-align:center;color:#8a9184;padding:40px">Nessun messaggio ricevuto per ora.</div>
<?php else: ?>
  <div class="card" style="padding:0;overflow:hidden">
    <table>
      <thead><tr><th>Data</th><th>Form</th><th>Nome</th><th>Email</th><th>Messaggio</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr style="<?= $r['is_read'] ? '' : 'background:#f5faf6' ?>">
          <td style="white-space:nowrap;color:#6a7266"><?= h($r['created_at']) ?></td>
          <td><span style="font-size:11.5px;font-weight:700;color:#3b56c4"><?= h($r['form']) ?></span></td>
          <td><?= h($r['nome']) ?><?= $r['tipo'] ? '<br><span style="color:#8a9184;font-size:12px">'.h($r['tipo']).'</span>' : '' ?></td>
          <td><a class="lnk" href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a></td>
          <td style="max-width:340px;color:#4a5145"><?= nl2br(h($r['messaggio'])) ?></td>
          <td style="white-space:nowrap">
            <?php if (!$r['is_read']): ?>
              <form method="post" style="display:inline"><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm ghost" type="submit">Segna letto</button></form>
            <?php else: ?>
              <span style="color:#b0b5a8;font-size:12px"><i class="ti ti-check"></i></span>
            <?php endif; ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Eliminare questo messaggio? Non si può recuperare.')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm danger" type="submit" title="Elimina"><i class="ti ti-trash"></i></button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php nc_admin_bottom();
