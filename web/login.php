<?php
require __DIR__ . '/lib/app.php';
if (user_count() === 0) redirect('setup.php');

$next = (string)($_GET['next'] ?? $_POST['next'] ?? '');
if (!preg_match('#^/[^/\\\\]#', $next)) $next = url(''); // nur Adressen auf dieser Seite
if (current_user()) redirect($next);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $r = check_login(trim($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
    if (is_array($r)) { login_session($r); redirect($next); }
    $error = $r;
}
page_start('Anmelden', 'center');
?>
<form class="box" method="post">
  <h1>Anmelden</h1>
  <?php if (isset($_GET['registered']) && !$error): ?><p class="msg ok">Dein Konto wurde erstellt. Du kannst dich jetzt anmelden.</p><?php endif; ?>
  <?php if ($error): ?><p class="msg error"><?= h($error) ?></p><?php endif; ?>
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= h($next) ?>">
  <label>Benutzername<input name="username" required autofocus value="<?= h($_POST['username'] ?? '') ?>" autocomplete="username"></label>
  <label>Passwort<input name="password" type="password" required autocomplete="current-password"></label>
  <button class="button">Anmelden</button>
  <?php if (setting('registration_open', '1') === '1'): ?>
    <p class="muted small">Noch kein Konto? <a href="<?= h(url('register.php')) ?>">Jetzt registrieren</a></p>
  <?php endif; ?>
  <p class="muted small"><a href="<?= h(url('')) ?>">← Zur Startseite</a></p>
</form>
<?php page_end();
