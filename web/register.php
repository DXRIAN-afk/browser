<?php
require __DIR__ . '/lib/app.php';
if (user_count() === 0) redirect('setup.php');
$open = setting('registration_open', '1') === '1';

$error = '';
if ($open && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $name = trim($_POST['username'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    if (too_many_attempts()) $error = 'Zu viele Versuche. Bitte 15 Minuten warten.';
    elseif (!valid_username($name)) $error = 'Der Benutzername muss 3–32 Zeichen lang sein (Buchstaben, Zahlen, . _ -).';
    elseif (find_user($name)) $error = 'Dieser Benutzername ist schon vergeben.';
    elseif (strlen($pass) < 8) $error = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    elseif ($pass !== ($_POST['password2'] ?? '')) $error = 'Die Passwörter stimmen nicht überein.';
    elseif (empty($_POST['privacy'])) $error = 'Bitte bestätige den Hinweis zum Datenschutz.';
    else {
        q('INSERT INTO login_attempts (ip, created_at) VALUES (?, ?)', [client_ip(), time() - 600]); // bremst Massen-Registrierungen
        create_user($name, $pass);
        redirect('login.php?registered=1');
    }
}
page_start('Registrieren', 'center');
?>
<form class="box" method="post">
  <h1>Konto erstellen</h1>
  <?php if (!$open): ?>
    <p class="msg">Die Registrierung ist im Moment geschlossen. Bitte frage den Administrator nach einem Konto.</p>
    <p class="muted small"><a href="<?= h(url('login.php')) ?>">← Zur Anmeldung</a></p>
  <?php else: ?>
    <?php if ($error): ?><p class="msg error"><?= h($error) ?></p><?php endif; ?>
    <?= csrf_field() ?>
    <label>Benutzername<input name="username" required autofocus value="<?= h($_POST['username'] ?? '') ?>" autocomplete="username" pattern="[A-Za-z0-9._\-]{3,32}"></label>
    <label>Passwort (mind. 8 Zeichen)<input name="password" type="password" required minlength="8" autocomplete="new-password"></label>
    <label>Passwort wiederholen<input name="password2" type="password" required minlength="8" autocomplete="new-password"></label>
    <label class="check"><input type="checkbox" name="privacy" value="1" required>
      <span>Ich habe verstanden, dass die besuchten Seiten und Suchanfragen mit meinem Benutzernamen gespeichert werden und für den Administrator sichtbar sind.</span></label>
    <button class="button">Konto erstellen</button>
    <p class="muted small">Schon ein Konto? <a href="<?= h(url('login.php')) ?>">Anmelden</a></p>
  <?php endif; ?>
</form>
<?php page_end();
