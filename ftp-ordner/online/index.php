<?php
require __DIR__ . '/../lib/app.php';
$user = require_login();
start_session();
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
$config = json_encode([
    'token' => $_SESSION['web_token'],
    'user' => $user['username'],
    'admin' => (bool)$user['is_admin'],
    'home' => url(''),
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SchoolWeb – Online</title>
  <meta name="author" content="Darian">
  <link rel="icon" href="../assets/favicon-32.png">
  <link rel="stylesheet" href="app.css">
</head>
<body>
  <div id="tabbar">
    <div id="tabs"></div>
    <button id="new-tab" title="Neuer Tab">+</button>
  </div>
  <div id="toolbar">
    <button id="back" title="Zurück">&#8592;</button>
    <button id="forward" title="Vor">&#8594;</button>
    <button id="reload" title="Neu laden">&#8635;</button>
    <button id="home" title="Startseite">&#8962;</button>
    <div id="urlwrap">
      <input id="url" type="text" spellcheck="false" autocomplete="off" placeholder="Suchen oder Adresse eingeben">
      <button id="star" title="Zu Favoriten hinzufügen">☆</button>
    </div>
    <div id="account">
      <button id="account-btn" title="Konto"><?= h($user['username']) ?><span id="dot" hidden></span></button>
      <div id="account-menu" hidden>
        <a href="<?= h(url('support.php')) ?>" target="_blank">Hilfe / Problem melden<span id="dot2" hidden> · neue Antwort</span></a>
        <?php if ($user['is_admin']): ?><a href="<?= h(url('admin/')) ?>" target="_blank">Admin-Bereich</a><?php endif; ?>
        <a href="<?= h(url('')) ?>">Startseite</a>
        <a href="<?= h(url('logout.php')) ?>">Abmelden</a>
      </div>
    </div>
  </div>
  <div id="favbar"></div>
  <div id="progress"></div>
  <div id="views">
    <div id="start">
      <img class="start-logo sw-logo" src="../assets/logo.svg" alt="SchoolWeb" width="120" height="120">
      <h1>School<span class="grad">Web</span></h1>
      <form id="start-form">
        <input id="start-input" type="text" autocomplete="off" placeholder="Im Web suchen oder Adresse eingeben">
      </form>
      <div id="tiles"></div>
      <a class="hexa" href="#" data-url="https://hexa.da-rtx.ch"><span class="hexa-icon"></span><span><b>Entdecke Hexa</b><br><span class="muted">hexa.da-rtx.ch</span></span><span class="arrow">→</span></a>
      <p class="note">
        Online-Version: Websites werden über diesen Server geladen. Einfache Seiten funktionieren gut,
        große Seiten wie Google, YouTube oder Seiten mit Login oft nicht.
        Für alle Websites gibt es die <a href="<?= h(url('')) ?>" target="_top">Windows-Version zum Herunterladen</a>.
        Besuchte Seiten und Suchanfragen werden gespeichert.
      </p>
      <p class="sig">crafted by D.</p>
    </div>
  </div>
  <script>window.BROWSER = <?= $config ?>;</script>
  <script src="app.js"></script>
  <script src="../assets/egg.js"></script>
</body>
</html>
