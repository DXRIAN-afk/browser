<?php
require __DIR__ . '/lib/app.php';
if (user_count() === 0) redirect('setup.php');
$user = current_user();
page_start('Browser – Download');
?>
  <nav class="top">
    <?php if ($user): ?>
      <span class="muted">Angemeldet als <b><?= h($user['username']) ?></b></span>
      <?php if ($user['is_admin']): ?><a href="<?= h(url('admin/')) ?>">Admin</a><?php endif; ?>
      <a href="<?= h(url('logout.php')) ?>">Abmelden</a>
    <?php else: ?>
      <a href="<?= h(url('login.php')) ?>">Anmelden</a>
      <?php if (setting('registration_open', '1') === '1'): ?><a class="pill" href="<?= h(url('register.php')) ?>">Registrieren</a><?php endif; ?>
    <?php endif; ?>
  </nav>
  <header>
    <div class="logo" aria-hidden="true"></div>
    <h1>Browser</h1>
    <p class="lead">Ein schlanker Browser mit der gleichen Engine wie Microsoft Edge und Google Chrome.
      Alle Websites, die dort laufen, laufen auch hier. Zum Benutzen brauchst du ein Konto.</p>
  </header>

  <main>
    <section class="card primary">
      <h2>Für Windows herunterladen</h2>
      <p>Version {{VERSION}} · Windows 10/11 (64-Bit) · {{SIZE}}</p>
      <a class="button" href="Browser-Windows.zip" download>Herunterladen</a>
      <ol>
        <li>ZIP-Datei herunterladen und entpacken (Rechtsklick → <b>Alle extrahieren</b>).</li>
        <li>Im entpackten Ordner <b>Browser.exe</b> starten.</li>
        <li>Falls Windows „Der Computer wurde durch Windows geschützt“ anzeigt:
          auf <b>Weitere Informationen</b> und dann <b>Trotzdem ausführen</b> klicken.</li>
        <li>Mit deinem Konto anmelden. Als Server-Adresse eintragen:
          <code class="copy" title="Klicken zum Kopieren"><?= h(base_url()) ?></code></li>
      </ol>
    </section>

    <section class="card">
      <h2>Online benutzen</h2>
      <p>Ohne Installation direkt hier im Browser. Die Seiten werden über diesen Server geladen,
        darum funktionieren einfache Seiten gut, große Seiten wie Google, YouTube oder Seiten mit Login
        aber oft nicht.</p>
      <a class="button secondary" href="<?= h(url('online/')) ?>">Online-Version öffnen</a>
    </section>
  </main>

  <section class="features">
    <div><h3>Tabs</h3><p>Mehrere Seiten gleichzeitig. Strg+T, Strg+W, Mittelklick zum Schließen.</p></div>
    <div><h3>Suchen und Adressen</h3><p>Einfach eintippen: Adresse öffnet die Seite, alles andere wird gesucht.</p></div>
    <div><h3>Chromium-Engine</h3><p>Moderne Websites, Videos und Web-Apps wie in Edge.</p></div>
  </section>

  <footer>Hinweis: Besuchte Seiten und Suchanfragen werden mit deinem Benutzernamen gespeichert und sind für den Administrator sichtbar.</footer>
  <script>
    document.querySelectorAll('.copy').forEach(function (c) {
      c.addEventListener('click', function () {
        navigator.clipboard && navigator.clipboard.writeText(c.textContent).then(function () {
          var t = c.textContent; c.textContent = 'Kopiert!'; setTimeout(function () { c.textContent = t; }, 1200);
        });
      });
    });
  </script>
<?php page_end();
