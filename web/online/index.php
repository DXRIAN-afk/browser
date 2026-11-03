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
  <title>Browser – Online</title>
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
    <input id="url" type="text" spellcheck="false" autocomplete="off" placeholder="Suchen oder Adresse eingeben">
    <div id="account">
      <button id="account-btn" title="Konto"><?= h($user['username']) ?></button>
      <div id="account-menu" hidden>
        <?php if ($user['is_admin']): ?><a href="<?= h(url('admin/')) ?>" target="_blank">Admin-Bereich</a><?php endif; ?>
        <a href="<?= h(url('')) ?>">Startseite</a>
        <a href="<?= h(url('logout.php')) ?>">Abmelden</a>
      </div>
    </div>
  </div>
  <div id="progress"></div>
  <div id="views">
    <div id="start">
      <h1>Browser</h1>
      <form id="start-form">
        <input id="start-input" type="text" autocomplete="off" placeholder="Im Web suchen oder Adresse eingeben">
      </form>
      <div id="quick">
        <button data-url="https://de.wikipedia.org">Wikipedia</button>
        <button data-url="https://html.duckduckgo.com/html/">DuckDuckGo</button>
        <button data-url="https://news.ycombinator.com">Hacker News</button>
        <button data-url="https://www.srf.ch">SRF</button>
      </div>
      <p class="note">
        Online-Version: Websites werden über diesen Server geladen. Einfache Seiten funktionieren gut,
        große Seiten wie Google, YouTube oder Seiten mit Login oft nicht.
        Für alle Websites gibt es die <a href="<?= h(url('')) ?>" target="_top">Windows-Version zum Herunterladen</a>.
        Besuchte Seiten und Suchanfragen werden gespeichert.
      </p>
    </div>
  </div>
  <script>window.BROWSER = <?= $config ?>;</script>
  <script src="app.js"></script>
</body>
</html>
