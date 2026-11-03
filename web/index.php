<?php
require __DIR__ . '/lib/app.php';
if (user_count() === 0) redirect('setup.php');
$user = current_user();
page_start('SchoolWeb – Download', 'home');
?>
  <nav class="top">
    <a class="brand" href="<?= h(url('')) ?>"><img class="sw-logo" src="<?= h(url('assets/logo.svg')) ?>" alt="" width="30" height="30"><span>School<b>Web</b></span></a>
    <span class="spacer"></span>
    <?php if ($user): ?>
      <span class="muted">Hallo <b><?= h($user['username']) ?></b></span>
      <a href="<?= h(url('support.php')) ?>">Hilfe</a>
      <?php if ($user['is_admin']): ?><a href="<?= h(url('admin/')) ?>">Admin</a><?php endif; ?>
      <a href="<?= h(url('logout.php')) ?>">Abmelden</a>
    <?php else: ?>
      <a href="<?= h(url('login.php')) ?>">Anmelden</a>
      <?php if (setting('registration_open', '1') === '1'): ?><a class="pill" href="<?= h(url('register.php')) ?>">Registrieren</a><?php endif; ?>
    <?php endif; ?>
  </nav>

  <header class="hero">
    <img class="hero-logo sw-logo" src="<?= h(url('assets/logo.svg')) ?>" alt="SchoolWeb-Logo" width="168" height="168">
    <h1>School<span class="grad">Web</span></h1>
    <p class="lead">Dein Browser mit der gleichen Engine wie Microsoft Edge und Google Chrome.
      Alle Websites, die dort laufen, laufen auch hier. Mit Favoritenleiste, Tabs und eigener Startseite.</p>
    <?php if (!$user): ?><p class="muted small">Zum Benutzen brauchst du ein Konto.</p><?php endif; ?>
  </header>

  <main>
    <section class="card primary">
      <h2>Für Windows herunterladen</h2>
      <p>Version {{VERSION}} · Windows 10/11 (64-Bit) · {{SIZE}}</p>
      <a class="button" href="SchoolWeb-Windows.zip" download>Herunterladen</a>
      <ol>
        <li><b>Vor dem Entpacken:</b> Rechtsklick auf die ZIP-Datei → <b>Eigenschaften</b> → unten bei „Sicherheit“
          ein Häkchen bei <b>Zulassen</b> → <b>OK</b>. Dann kommt keine Windows-Warnung.</li>
        <li>Rechtsklick → <b>Alle extrahieren</b>, dann im Ordner <b>SchoolWeb.exe</b> starten.</li>
        <li>Kommt trotzdem „Der Computer wurde durch Windows geschützt“: <b>Weitere Informationen</b> → <b>Trotzdem ausführen</b>.</li>
        <li>Mit deinem Konto anmelden. Server-Adresse:
          <code class="copy" title="Klicken zum Kopieren"><?= h(base_url()) ?></code></li>
      </ol>
    </section>

    <section class="card">
      <h2>Online benutzen</h2>
      <p>Ohne Installation direkt hier im Browser. Einfache Seiten funktionieren gut, große Seiten wie Google,
        YouTube oder Spiele-Seiten oft nicht. Dafür gibt es die Windows-Version.</p>
      <a class="button secondary" href="<?= h(url('online/')) ?>">Online-Version öffnen</a>
    </section>
  </main>

  <a class="hexa" href="https://hexa.da-rtx.ch" target="_blank" rel="noopener">
    <span class="hexa-icon" aria-hidden="true"></span>
    <span><b>Entdecke Hexa</b><br><span class="muted">Mehr Projekte gibt es auf hexa.da-rtx.ch</span></span>
    <span class="arrow">→</span>
  </a>

  <section class="features">
    <div><h3>Favoritenleiste</h3><p>Lieblingsseiten mit dem Stern speichern, sie sind auf jedem Gerät da, wo du dich anmeldest.</p></div>
    <div><h3>Tabs &amp; Suche</h3><p>Mehrere Seiten gleichzeitig. Adresse oder Suchbegriff einfach eintippen.</p></div>
    <div><h3>Hilfe bei Problemen</h3><p>Direkt aus dem Browser eine Nachricht an den Admin schicken.</p></div>
  </section>

  <footer>
    Besuchte Seiten und Suchanfragen werden mit deinem Benutzernamen gespeichert und sind für den Administrator sichtbar.<br>
    <a href="https://hexa.da-rtx.ch" target="_blank" rel="noopener">hexa.da-rtx.ch</a> · <span class="sig" title="✦">crafted by D.</span>
  </footer>
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
