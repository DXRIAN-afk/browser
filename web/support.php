<?php
// Hilfe: Nachricht an den Admin schreiben und Antworten lesen
require __DIR__ . '/lib/app.php';
$user = require_login();
$error = ''; $sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (send_message((int)$user['id'], (string)($_POST['body'] ?? ''))) { redirect('support.php?sent=1'); }
    $error = 'Die Nachricht ist leer, zu lang (max. 4000 Zeichen) oder du hast in der letzten Stunde schon zu viele gesendet.';
}
$messages = user_messages((int)$user['id']);
mark_replies_seen((int)$user['id']);
page_start('SchoolWeb – Hilfe', 'center');
?>
<div class="box wide">
  <img class="box-logo sw-logo" src="<?= h(url('assets/logo.svg')) ?>" alt="SchoolWeb">
  <h1>Hilfe &amp; Probleme melden</h1>
  <p class="muted">Etwas funktioniert nicht, eine Seite ist falsch gesperrt oder du hast eine Idee? Schreib dem Admin. Die Antwort erscheint hier und in der App.</p>
  <?php if (isset($_GET['sent'])): ?><p class="msg ok">Danke! Deine Nachricht wurde gesendet.</p><?php endif; ?>
  <?php if ($error): ?><p class="msg error"><?= h($error) ?></p><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Deine Nachricht<textarea name="body" required maxlength="4000" placeholder="Beschreibe kurz das Problem, z. B. welche Seite nicht geht"><?= h($_POST['body'] ?? '') ?></textarea></label>
    <button class="button">Nachricht senden</button>
  </form>
  <?php if ($messages): ?>
    <h2 style="font-size:17px;margin:10px 0 0">Deine Nachrichten</h2>
    <ul class="thread">
      <?php foreach ($messages as $m): ?>
        <li>
          <div class="meta"><?= date('d.m.Y H:i', $m['created_at']) ?>
            <?= $m['reply'] ? '<span class="tag ok">beantwortet</span>' : ($m['status'] === 'done' ? '<span class="tag ok">erledigt</span>' : '<span class="tag">offen</span>') ?></div>
          <div class="body"><?= h($m['body']) ?></div>
          <?php if ($m['reply']): ?><div class="reply"><b>Admin:</b> <?= h($m['reply']) ?></div><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <p class="below"><a href="<?= h(url('')) ?>">← Zur Startseite</a> · <a href="<?= h(url('online/')) ?>">Online-Version</a></p>
</div>
<?php page_end();
