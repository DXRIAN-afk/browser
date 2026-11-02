<?php
// Ersteinrichtung: legt das erste Administrator-Konto an.
require __DIR__ . '/lib/app.php';
if (user_count() > 0) redirect('login.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $name = trim($_POST['username'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    if (!valid_username($name)) $error = 'Der Benutzername muss 3–32 Zeichen lang sein (Buchstaben, Zahlen, . _ -).';
    elseif (strlen($pass) < 8) $error = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    elseif ($pass !== ($_POST['password2'] ?? '')) $error = 'Die Passwörter stimmen nicht überein.';
    elseif (user_count() > 0) redirect('login.php');
    else {
        $id = create_user($name, $pass, true);
        set_setting('registration_open', '1');
        login_session(find_user($name));
        redirect('admin/');
    }
}
page_start('Einrichtung', 'center');
?>
<form class="box" method="post">
  <h1>Willkommen!</h1>
  <p class="muted">Lege zuerst dein Administrator-Konto an. Damit kannst du später Benutzer, Statistiken und gesperrte Seiten verwalten.</p>
  <?php if ($error): ?><p class="msg error"><?= h($error) ?></p><?php endif; ?>
  <?= csrf_field() ?>
  <label>Benutzername<input name="username" required autofocus value="<?= h($_POST['username'] ?? '') ?>" autocomplete="username"></label>
  <label>Passwort (mind. 8 Zeichen)<input name="password" type="password" required minlength="8" autocomplete="new-password"></label>
  <label>Passwort wiederholen<input name="password2" type="password" required minlength="8" autocomplete="new-password"></label>
  <button class="button">Administrator anlegen</button>
</form>
<?php page_end();
