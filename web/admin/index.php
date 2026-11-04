<?php
// Admin-Bereich: Statistik, Benutzer, Verlauf, gesperrte Seiten, Einstellungen
require __DIR__ . '/../lib/app.php';
$me = require_admin();

function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = [$msg, $type]; }
function back(string $query = ''): void { redirect('admin/' . ($query !== '' ? '?' . $query : '')); }
function day_expr(): string { return time_expr('day'); }
function since(int $days): int { return $days > 0 ? strtotime('today') - ($days - 1) * 86400 : 0; }
function n($v): string { return number_format((int)$v, 0, '.', "'"); }

// ---- Aktionen (Formulare) --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $ret = (string)($_POST['return'] ?? '');
    if (!preg_match('/^[a-z0-9=&_%.\-]*$/i', $ret)) $ret = '';

    if ($a === 'block') {
        $d = normalize_domain((string)($_POST['domain'] ?? ''));
        if (!preg_match('/^[a-z0-9\-]+(\.[a-z0-9\-]+)+$/', $d)) flash('Bitte eine gültige Domain eingeben, z. B. example.com.', 'error');
        elseif (q('SELECT 1 FROM blocked WHERE domain = ?', [$d])->fetchColumn()) flash("$d ist schon gesperrt.", 'error');
        else { q('INSERT INTO blocked (domain, note, created_at) VALUES (?, ?, ?)', [$d, mb_substr(trim($_POST['note'] ?? ''), 0, 190), time()]); flash("$d ist jetzt gesperrt (inkl. aller Subdomains)."); }
        back($ret ?: 'page=blocked');
    }
    if ($a === 'unblock') { q('DELETE FROM blocked WHERE id = ?', [$id]); flash('Sperre aufgehoben.'); back('page=blocked'); }

    if ($a === 'user_create') {
        $name = trim($_POST['username'] ?? ''); $pass = (string)($_POST['password'] ?? '');
        if (!valid_username($name)) flash('Benutzername: 3–32 Zeichen (Buchstaben, Zahlen, . _ -).', 'error');
        elseif (find_user($name)) flash('Dieser Benutzername ist schon vergeben.', 'error');
        elseif (strlen($pass) < 8) flash('Das Passwort muss mindestens 8 Zeichen lang sein.', 'error');
        else { create_user($name, $pass, !empty($_POST['is_admin'])); flash("Benutzer $name wurde angelegt."); }
        back('page=users');
    }
    if (in_array($a, ['user_block', 'user_unblock', 'user_admin', 'user_unadmin', 'user_delete', 'user_password', 'user_logout'], true)) {
        $u = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
        if (!$u) { flash('Benutzer nicht gefunden.', 'error'); back('page=users'); }
        if ($id === (int)$me['id'] && $a !== 'user_password') { flash('Das geht nicht mit deinem eigenen Konto.', 'error'); back("page=user&id=$id"); }
        $name = $u['username'];
        switch ($a) {
            case 'user_block': q('UPDATE users SET is_blocked = 1 WHERE id = ?', [$id]); q('DELETE FROM tokens WHERE user_id = ?', [$id]); flash("$name ist gesperrt und wurde überall abgemeldet."); break;
            case 'user_unblock': q('UPDATE users SET is_blocked = 0 WHERE id = ?', [$id]); flash("$name ist wieder freigeschaltet."); break;
            case 'user_admin': q('UPDATE users SET is_admin = 1 WHERE id = ?', [$id]); flash("$name ist jetzt Administrator."); break;
            case 'user_unadmin': q('UPDATE users SET is_admin = 0 WHERE id = ?', [$id]); flash("$name ist kein Administrator mehr."); break;
            case 'user_logout': q('DELETE FROM tokens WHERE user_id = ?', [$id]); flash("$name wurde auf allen Geräten abgemeldet."); break;
            case 'user_password':
                $p = (string)($_POST['password'] ?? '');
                if (strlen($p) < 8) { flash('Das Passwort muss mindestens 8 Zeichen lang sein.', 'error'); break; }
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($p, PASSWORD_DEFAULT), $id]);
                flash("Passwort von $name wurde geändert.");
                break;
            case 'user_delete':
                foreach (['visits', 'searches', 'tokens', 'favorites', 'messages'] as $t) q("DELETE FROM $t WHERE user_id = ?", [$id]);
                q('DELETE FROM users WHERE id = ?', [$id]);
                flash("$name und der ganze Verlauf wurden gelöscht.");
                back('page=users');
        }
        back("page=user&id=$id");
    }
    // Suchverlauf und Verlauf löschen
    if ($a === 'delete_search') { q('DELETE FROM searches WHERE id = ?', [$id]); flash('Suchanfrage gelöscht.'); back($ret ?: 'page=searches'); }
    if ($a === 'delete_search_term') {
        $term = (string)($_POST['term'] ?? '');
        q('DELETE FROM searches WHERE LOWER(query) = LOWER(?)', [$term]);
        flash('Alle Suchen nach „' . $term . '“ wurden gelöscht.');
        back($ret ?: 'page=searches');
    }
    if ($a === 'clear_searches') { q('DELETE FROM searches'); flash('Der gesamte Suchverlauf wurde gelöscht.'); back('page=searches'); }
    if ($a === 'delete_visit') { q('DELETE FROM visits WHERE id = ?', [$id]); flash('Eintrag gelöscht.'); back($ret ?: 'page=history'); }
    if ($a === 'user_clear_searches') { q('DELETE FROM searches WHERE user_id = ?', [$id]); flash('Suchverlauf dieser Person gelöscht.'); back("page=user&id=$id"); }
    if ($a === 'user_clear_visits') { q('DELETE FROM visits WHERE user_id = ?', [$id]); flash('Seitenverlauf dieser Person gelöscht.'); back("page=user&id=$id"); }

    // Nachrichten
    if ($a === 'msg_reply') {
        $reply = trim((string)($_POST['reply'] ?? ''));
        if ($reply === '') flash('Bitte eine Antwort eingeben.', 'error');
        else { q("UPDATE messages SET reply = ?, replied_at = ?, reply_seen = 0, status = 'done' WHERE id = ?", [mb_substr($reply, 0, 4000), time(), $id]); flash('Antwort gesendet. Die Person sieht sie in der App und unter „Hilfe“.'); }
        back('page=messages');
    }
    if ($a === 'msg_done') { q("UPDATE messages SET status = 'done' WHERE id = ?", [$id]); flash('Als erledigt markiert.'); back('page=messages'); }
    if ($a === 'msg_open') { q("UPDATE messages SET status = 'open' WHERE id = ?", [$id]); flash('Wieder offen.'); back('page=messages&show=all'); }
    if ($a === 'msg_delete') { q('DELETE FROM messages WHERE id = ?', [$id]); flash('Nachricht gelöscht.'); back('page=messages&show=all'); }

    // Ankündigungen
    if ($a === 'ann_create') {
        $body = trim((string)($_POST['body'] ?? ''));
        $level = (string)($_POST['level'] ?? 'info');
        $days = (int)($_POST['days'] ?? 0);
        if ($body === '') flash('Bitte einen Text eingeben.', 'error');
        else {
            q('INSERT INTO announcements (body, level, active, expires_at, created_at) VALUES (?, ?, 1, ?, ?)',
              [mb_substr($body, 0, 1000), isset(ANNOUNCEMENT_LEVELS[$level]) ? $level : 'info', $days > 0 ? time() + $days * 86400 : 0, time()]);
            flash('Ankündigung veröffentlicht. Alle sehen sie beim nächsten Öffnen oder spätestens nach einer Minute.');
        }
        back('page=announcements');
    }
    if ($a === 'ann_toggle') { q('UPDATE announcements SET active = 1 - active WHERE id = ?', [$id]); flash('Gespeichert.'); back('page=announcements'); }
    if ($a === 'ann_delete') { q('DELETE FROM announcements WHERE id = ?', [$id]); flash('Ankündigung gelöscht.'); back('page=announcements'); }

    if ($a === 'settings') {
        $engine = (string)($_POST['search_engine'] ?? 'google');
        if (isset(SEARCH_OPTIONS[$engine])) set_setting('search_engine', $engine);
        set_setting('registration_open', empty($_POST['registration_open']) ? '0' : '1');
        set_setting('retention_days', (string)max(0, min(3650, (int)($_POST['retention_days'] ?? 0))));
        flash('Einstellungen gespeichert.');
        back('page=settings');
    }
    if ($a === 'clear_history') {
        $days = (int)($_POST['days'] ?? 0);
        $t = $days > 0 ? time() - $days * 86400 : time() + 1;
        q('DELETE FROM visits WHERE created_at < ?', [$t]);
        q('DELETE FROM searches WHERE created_at < ?', [$t]);
        flash($days > 0 ? "Verlauf älter als $days Tage gelöscht." : 'Der gesamte Verlauf wurde gelöscht.');
        back('page=settings');
    }
    back();
}

// ---- Seiten ----------------------------------------------------------------
$page = $_GET['page'] ?? 'dashboard';
$range = (int)($_GET['range'] ?? 7);
if (!in_array($range, [1, 7, 30, 0], true)) $range = 7;
$ranges = [1 => 'Heute', 7 => '7 Tage', 30 => '30 Tage', 0 => 'Alles'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$openMessages = (int)q("SELECT COUNT(*) FROM messages WHERE status = 'open'")->fetchColumn();
$nav = ['dashboard' => 'Übersicht', 'messages' => 'Nachrichten', 'announcements' => 'Ankündigungen', 'searches' => 'Suchanfragen', 'history' => 'Verlauf', 'users' => 'Benutzer', 'blocked' => 'Gesperrte Seiten', 'settings' => 'Einstellungen'];
$current = $page === 'user' ? 'users' : $page;

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin – SchoolWeb</title>
  <meta name="author" content="Darian">
  <link rel="icon" href="../assets/favicon-32.png">
  <link rel="stylesheet" href="admin.css">
</head>
<body>
<header class="bar">
  <a class="brand" href="./"><img class="sw-logo" src="../assets/logo.svg" alt="" width="30" height="30"><span>School<b>Web</b></span> <span class="muted">Admin</span></a>
  <nav><?php foreach ($nav as $k => $label): ?><a href="?page=<?= $k ?>" class="<?= $current === $k ? 'active' : '' ?>"><?= $label ?><?php if ($k === 'messages' && $openMessages): ?> <span class="badge"><?= $openMessages ?></span><?php endif; ?></a><?php endforeach; ?></nav>
  <form class="gsearch" method="get"><input type="hidden" name="page" value="search"><input type="search" name="q" value="<?= h($page === 'search' ? ($_GET['q'] ?? '') : '') ?>" placeholder="Alles durchsuchen …" aria-label="Alles durchsuchen"></form>
  <div class="me"><span class="muted"><?= h($me['username']) ?></span> <a href="../online/">Online-Browser</a> <a href="../logout.php">Abmelden</a></div>
</header>
<main>
<?php if ($flash): ?><p class="msg <?= h($flash[1]) ?>"><?= h($flash[0]) ?></p><?php endif; ?>

<?php
// Kleine Bausteine
function range_tabs(string $page, int $range, array $ranges): void
{
    echo '<div class="seg">';
    foreach ($ranges as $k => $l) echo '<a href="?page=' . $page . '&range=' . $k . '" class="' . ($k === $range ? 'on' : '') . '">' . $l . '</a>';
    echo '</div>';
}
function bar_list(array $rows, string $labelKey, string $empty, ?callable $extra = null): void
{
    if (!$rows) { echo '<p class="empty">' . $empty . '</p>'; return; }
    $max = max(array_column($rows, 'c')) ?: 1;
    echo '<ol class="bars">';
    foreach ($rows as $r) {
        $w = max(1.5, $r['c'] / $max * 100);
        echo '<li><div class="bl"><span class="label" title="' . h($r[$labelKey]) . '">' . h($r[$labelKey]) . '</span><span class="val">' . n($r['c']) . '</span>'
           . ($extra ? $extra($r) : '') . '</div><div class="track"><div class="fill" style="width:' . round($w, 1) . '%"></div></div></li>';
    }
    echo '</ol>';
}
function block_button(string $domain, string $return = ''): string
{
    if (is_blocked_url('http://' . $domain . '/')) return '<span class="tag red">gesperrt</span>';
    return '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="block"><input type="hidden" name="domain" value="' . h($domain) . '"><input type="hidden" name="return" value="' . h($return) . '">'
         . '<button class="link danger" title="' . h($domain) . ' sperren" onclick="return confirm(\'' . h($domain) . ' für alle sperren?\')">Sperren</button></form>';
}
function del_button(string $action, array $fields, string $label = '×', string $confirm = ''): string
{
    $f = '';
    foreach ($fields as $k => $v) $f .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    return '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="' . h($action) . '">' . $f
         . '<button class="link danger" title="Löschen"' . ($confirm ? ' onclick="return confirm(\'' . h($confirm) . '\')"' : '') . '>' . $label . '</button></form>';
}
function client_tag(string $c): string { return $c === 'app' ? '<span class="tag">App</span>' : '<span class="tag">Online</span>'; }

if ($page === 'dashboard'):
    $from = since($range);
    $stats = [
        'Benutzer' => q('SELECT COUNT(*) FROM users')->fetchColumn(),
        'Aktive Benutzer' => q('SELECT COUNT(DISTINCT user_id) FROM visits WHERE created_at >= ?', [$from])->fetchColumn(),
        'Seitenaufrufe' => q('SELECT COUNT(*) FROM visits WHERE created_at >= ?', [$from])->fetchColumn(),
        'Suchanfragen' => q('SELECT COUNT(*) FROM searches WHERE created_at >= ?', [$from])->fetchColumn(),
    ];
    $topSearch = q('SELECT LOWER(query) AS term, COUNT(*) AS c FROM searches WHERE created_at >= ? GROUP BY LOWER(query) ORDER BY c DESC, term LIMIT 12', [$from])->fetchAll();
    $topDomains = q('SELECT domain, COUNT(*) AS c FROM visits WHERE created_at >= ? GROUP BY domain ORDER BY c DESC, domain LIMIT 12', [$from])->fetchAll();
    $topUsers = q('SELECT u.id, u.username, COUNT(*) AS c FROM visits v JOIN users u ON u.id = v.user_id WHERE v.created_at >= ? GROUP BY u.id, u.username ORDER BY c DESC LIMIT 8', [$from])->fetchAll();
    // Aufrufe pro Tag (letzte 14 bzw. 30 Tage)
    $days = $range === 30 || $range === 0 ? 30 : 14;
    $perDay = [];
    foreach (q('SELECT ' . day_expr() . ' AS d, COUNT(*) AS c FROM visits WHERE created_at >= ? GROUP BY d', [since($days)])->fetchAll() as $r) $perDay[$r['d']] = (int)$r['c'];
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $series[$d] = $perDay[$d] ?? 0; }
    $maxDay = max($series) ?: 1;
    $wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
?>
  <div class="head"><h1>Übersicht</h1><?php range_tabs('dashboard', $range, $ranges); ?></div>
  <?php if ($openMessages): ?><a class="notice" href="?page=messages">✉ <?= $openMessages ?> offene Nachricht<?= $openMessages > 1 ? 'en' : '' ?> von Benutzern <span>Ansehen →</span></a><?php endif; ?>
  <div class="tiles">
    <?php foreach ($stats as $l => $v): ?><div class="tile"><div class="tl"><?= $l ?></div><div class="tv"><?= n($v) ?></div></div><?php endforeach; ?>
  </div>

  <section class="panel">
    <h2>Seitenaufrufe pro Tag <span class="muted">· letzte <?= $days ?> Tage</span></h2>
    <div class="cols" role="img" aria-label="Seitenaufrufe pro Tag">
      <?php $i = 0; foreach ($series as $d => $c): $t = strtotime($d); ?>
        <div class="col" tabindex="0">
          <div class="cbar" style="height:<?= $c ? max(2, round($c / $maxDay * 100, 1)) : 0 ?>%"></div>
          <div class="tip"><b><?= n($c) ?></b> Aufrufe<br><?= $wd[date('w', $t)] . ', ' . date('d.m.', $t) ?></div>
          <div class="cl"><?php $step = $days > 14 ? 5 : 3; ?><?= (($i % $step === 0 && $i < $days - $step + 1) || $i === $days - 1) ? date('d.m.', $t) : '' ?></div>
        </div>
      <?php $i++; endforeach; ?>
    </div>
    <details><summary>Als Tabelle</summary><table class="t small"><tr><th>Tag</th><th class="r">Aufrufe</th></tr>
      <?php foreach (array_reverse($series, true) as $d => $c): ?><tr><td><?= date('d.m.Y', strtotime($d)) ?></td><td class="r"><?= n($c) ?></td></tr><?php endforeach; ?></table></details>
  </section>

  <?php
    $from = since($range);
    $hours = array_fill(0, 24, 0);
    foreach (q('SELECT ' . time_expr('hour') . ' AS hr, COUNT(*) AS c FROM visits WHERE created_at >= ? GROUP BY hr', [$from])->fetchAll() as $r) $hours[(int)$r['hr']] = (int)$r['c'];
    $wdNames = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    $week = array_fill(0, 7, 0);
    foreach (q('SELECT ' . time_expr('weekday') . ' AS wd, COUNT(*) AS c FROM visits WHERE created_at >= ? GROUP BY wd', [$from])->fetchAll() as $r) $week[((int)$r['wd'] + 6) % 7] = (int)$r['c'];
    $col = function (array $vals, callable $label, callable $tip, int $every = 1) {
        $max = max($vals) ?: 1;
        echo '<div class="cols short">';
        foreach ($vals as $i => $c) {
            echo '<div class="col" tabindex="0"><div class="cbar" style="height:' . ($c ? max(2, round($c / $max * 100, 1)) : 0) . '%"></div>'
               . '<div class="tip"><b>' . n($c) . '</b> Aufrufe<br>' . h($tip($i)) . '</div>'
               . '<div class="cl">' . ($i % $every === 0 ? h($label($i)) : '') . '</div></div>';
        }
        echo '</div>';
    };
  ?>
  <div class="grid2">
    <section class="panel">
      <h2>Wann wird gesurft? <span class="muted">· nach Uhrzeit</span></h2>
      <?php $col($hours, fn($i) => sprintf('%02d', $i), fn($i) => sprintf('%02d:00–%02d:59 Uhr', $i, $i), 3); ?>
      <?php $peak = array_search(max($hours), $hours); if (max($hours)): ?><p class="more muted">Am meisten los: <b><?= sprintf('%02d–%02d Uhr', $peak, $peak + 1) ?></b></p><?php endif; ?>
    </section>
    <section class="panel">
      <h2>An welchen Tagen? <span class="muted">· nach Wochentag</span></h2>
      <?php $col($week, fn($i) => $wdNames[$i], fn($i) => $wdNames[$i]); ?>
      <?php $wpeak = array_search(max($week), $week); if (max($week)): ?><p class="more muted">Aktivster Tag: <b><?= $wdNames[$wpeak] ?></b></p><?php endif; ?>
    </section>
  </div>

  <div class="grid2">
    <section class="panel">
      <h2>Häufigste Suchbegriffe</h2>
      <?php bar_list($topSearch, 'term', 'Noch keine Suchanfragen in diesem Zeitraum.'); ?>
      <p class="more"><a href="?page=searches&range=<?= $range ?>">Alle Suchanfragen →</a></p>
    </section>
    <section class="panel">
      <h2>Meistbesuchte Websites</h2>
      <?php bar_list($topDomains, 'domain', 'Noch keine Seitenaufrufe in diesem Zeitraum.', fn($r) => block_button($r['domain'], "page=dashboard&range=$range")); ?>
      <p class="more"><a href="?page=history">Ganzer Verlauf →</a></p>
    </section>
  </div>
  <section class="panel">
    <h2>Aktivste Benutzer</h2>
    <?php bar_list(array_map(fn($r) => $r + ['label' => $r['username']], $topUsers), 'label', 'Noch keine Aktivität.', fn($r) => '<a class="link" href="?page=user&id=' . (int)$r['id'] . '">Details</a>'); ?>
  </section>

<?php elseif ($page === 'searches'):
    $from = since($range);
    $top = q('SELECT LOWER(query) AS term, COUNT(*) AS c, COUNT(DISTINCT user_id) AS u FROM searches WHERE created_at >= ? GROUP BY LOWER(query) ORDER BY c DESC, term LIMIT 50', [$from])->fetchAll();
    $engines = q('SELECT engine, COUNT(*) AS c FROM searches WHERE created_at >= ? GROUP BY engine ORDER BY c DESC', [$from])->fetchAll();
    $recent = q('SELECT s.*, u.username FROM searches s LEFT JOIN users u ON u.id = s.user_id WHERE s.created_at >= ? ORDER BY s.id DESC LIMIT 100', [$from])->fetchAll();
?>
  <div class="head"><h1>Suchanfragen</h1>
    <div class="headr"><?php range_tabs('searches', $range, $ranges); ?>
      <a class="btn" href="export.php?type=searches&range=<?= $range ?>">⤓ CSV</a>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="clear_searches">
        <button class="btn danger" onclick="return confirm('Den gesamten Suchverlauf aller Benutzer löschen?')">Ganzen Suchverlauf löschen</button></form></div></div>
  <div class="grid2">
    <section class="panel">
      <h2>Top 50 Suchbegriffe</h2>
      <?php if (!$top): ?><p class="empty">Noch keine Suchanfragen in diesem Zeitraum.</p><?php else: ?>
      <table class="t"><tr><th>#</th><th>Suchbegriff</th><th class="r">Anzahl</th><th class="r">Personen</th><th></th></tr>
        <?php foreach ($top as $i => $r): ?><tr><td class="muted"><?= $i + 1 ?></td><td><?= h($r['term']) ?></td><td class="r"><?= n($r['c']) ?></td><td class="r"><?= n($r['u']) ?></td>
          <td class="r"><?= del_button('delete_search_term', ['term' => $r['term'], 'return' => "page=searches&range=$range"], '×', 'Alle Suchen nach „' . $r['term'] . '“ löschen?') ?></td></tr><?php endforeach; ?>
      </table><?php endif; ?>
    </section>
    <div>
      <section class="panel">
        <h2>Suchmaschinen</h2>
        <?php bar_list($engines, 'engine', 'Noch keine Daten.'); ?>
      </section>
      <section class="panel">
        <h2>Neueste Suchanfragen</h2>
        <?php if (!$recent): ?><p class="empty">Noch keine.</p><?php else: ?>
        <table class="t small"><tr><th>Zeit</th><th>Benutzer</th><th>Suche</th><th></th></tr>
          <?php foreach ($recent as $r): ?><tr><td class="nowrap muted" title="<?= date('d.m.Y H:i', $r['created_at']) ?>"><?= ago((int)$r['created_at']) ?></td>
            <td><a href="?page=user&id=<?= (int)$r['user_id'] ?>"><?= h($r['username'] ?? '–') ?></a></td><td><?= h($r['query']) ?> <span class="muted">· <?= h($r['engine']) ?></span></td>
            <td class="r"><?= del_button('delete_search', ['id' => $r['id'], 'return' => "page=searches&range=$range"]) ?></td></tr><?php endforeach; ?>
        </table><?php endif; ?>
      </section>
    </div>
  </div>

<?php elseif ($page === 'history'):
    $uid = (int)($_GET['user'] ?? 0);
    $text = trim((string)($_GET['q'] ?? ''));
    $p = max(1, (int)($_GET['p'] ?? 1));
    $where = []; $args = [];
    if ($uid) { $where[] = 'v.user_id = ?'; $args[] = $uid; }
    if ($text !== '') { $where[] = '(v.url LIKE ? OR v.title LIKE ?)'; $args[] = "%$text%"; $args[] = "%$text%"; }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int)q("SELECT COUNT(*) FROM visits v $w", $args)->fetchColumn();
    $per = 100;
    $rows = q("SELECT v.*, u.username FROM visits v LEFT JOIN users u ON u.id = v.user_id $w ORDER BY v.id DESC LIMIT $per OFFSET " . (($p - 1) * $per), $args)->fetchAll();
    $users = q('SELECT id, username FROM users ORDER BY username')->fetchAll();
?>
  <div class="head"><h1>Verlauf</h1><div class="headr"><span class="muted"><?= n($total) ?> Einträge</span>
    <a class="btn" href="export.php?<?= h(http_build_query(['type' => 'history', 'user' => $uid, 'q' => $text])) ?>">⤓ Als CSV exportieren</a></div></div>
  <form class="filters" method="get">
    <input type="hidden" name="page" value="history">
    <select name="user" onchange="this.form.submit()"><option value="0">Alle Benutzer</option>
      <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $uid === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['username']) ?></option><?php endforeach; ?></select>
    <input type="search" name="q" value="<?= h($text) ?>" placeholder="Adresse oder Titel suchen">
    <button class="btn">Filtern</button>
  </form>
  <section class="panel flush">
    <?php if (!$rows): ?><p class="empty">Keine Einträge gefunden.</p><?php else: ?>
    <table class="t"><tr><th>Zeit</th><th>Benutzer</th><th>Seite</th><th>Website</th><th></th></tr>
      <?php foreach ($rows as $r): ?><tr>
        <td class="nowrap muted"><?= date('d.m.Y H:i', $r['created_at']) ?></td>
        <td><a href="?page=user&id=<?= (int)$r['user_id'] ?>"><?= h($r['username'] ?? '–') ?></a></td>
        <td class="page"><div class="pt"><?= h($r['title'] ?: '(ohne Titel)') ?></div><a class="pu" href="<?= h($r['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($r['url']) ?></a></td>
        <td class="nowrap"><?= h($r['domain']) ?> <?= client_tag($r['client']) ?></td>
        <td class="r nowrap"><?= block_button($r['domain'], 'page=history') ?> &nbsp; <?= del_button('delete_visit', ['id' => $r['id'], 'return' => 'page=history']) ?></td>
      </tr><?php endforeach; ?>
    </table><?php endif; ?>
  </section>
  <?php if ($total > $per): $pages = (int)ceil($total / $per); $qs = http_build_query(['page' => 'history', 'user' => $uid, 'q' => $text]); ?>
    <div class="pager"><?php if ($p > 1): ?><a class="btn" href="?<?= $qs ?>&p=<?= $p - 1 ?>">← Neuer</a><?php endif; ?>
      <span class="muted">Seite <?= $p ?> von <?= $pages ?></span>
      <?php if ($p < $pages): ?><a class="btn" href="?<?= $qs ?>&p=<?= $p + 1 ?>">Älter →</a><?php endif; ?></div>
  <?php endif; ?>

<?php elseif ($page === 'users'):
    $users = q('SELECT u.*, (SELECT COUNT(*) FROM visits v WHERE v.user_id = u.id) AS visits,
                       (SELECT COUNT(*) FROM searches s WHERE s.user_id = u.id) AS searches
                FROM users u ORDER BY u.last_seen DESC, u.username')->fetchAll();
?>
  <div class="head"><h1>Benutzer</h1><div class="headr"><span class="muted"><?= count($users) ?> Konten</span><a class="btn" href="export.php?type=users">⤓ Als CSV exportieren</a></div></div>
  <section class="panel flush">
    <table class="t"><tr><th>Benutzer</th><th>Registriert</th><th>Zuletzt aktiv</th><th class="r">Aufrufe</th><th class="r">Suchen</th><th>Status</th></tr>
      <?php foreach ($users as $u): ?><tr class="rowlink" onclick="location='?page=user&id=<?= (int)$u['id'] ?>'">
        <td><a href="?page=user&id=<?= (int)$u['id'] ?>"><b><?= h($u['username']) ?></b></a></td>
        <td class="muted nowrap"><?= date('d.m.Y', $u['created_at']) ?></td>
        <td class="nowrap"><?= ago((int)$u['last_seen']) ?></td>
        <td class="r"><?= n($u['visits']) ?></td><td class="r"><?= n($u['searches']) ?></td>
        <td><?= $u['is_admin'] ? '<span class="tag blue">Admin</span> ' : '' ?><?= $u['is_blocked'] ? '<span class="tag red">gesperrt</span>' : '<span class="tag green">aktiv</span>' ?></td>
      </tr><?php endforeach; ?>
    </table>
  </section>
  <section class="panel narrow">
    <h2>Neuen Benutzer anlegen</h2>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="user_create">
      <label>Benutzername<input name="username" required pattern="[A-Za-z0-9._\-]{3,32}"></label>
      <label>Passwort (mind. 8 Zeichen)<input name="password" type="password" required minlength="8" autocomplete="new-password"></label>
      <label class="check"><input type="checkbox" name="is_admin" value="1"> Administrator</label>
      <button class="btn primary">Anlegen</button>
    </form>
  </section>

<?php elseif ($page === 'user'):
    $id = (int)($_GET['id'] ?? 0);
    $u = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
    if (!$u): ?><p class="empty">Benutzer nicht gefunden.</p><?php else:
    $visits = q('SELECT * FROM visits WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$id])->fetchAll();
    $searches = q('SELECT * FROM searches WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$id])->fetchAll();
    $domains = q('SELECT domain, COUNT(*) AS c FROM visits WHERE user_id = ? GROUP BY domain ORDER BY c DESC LIMIT 10', [$id])->fetchAll();
    $count = q('SELECT COUNT(*) FROM visits WHERE user_id = ?', [$id])->fetchColumn();
    $scount = q('SELECT COUNT(*) FROM searches WHERE user_id = ?', [$id])->fetchColumn();
    $self = $id === (int)$me['id'];
    $act = function (string $action, string $label, string $cls = '', string $confirm = '') use ($id) {
        return '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . $id . '">'
             . '<button class="btn ' . $cls . '"' . ($confirm ? ' onclick="return confirm(\'' . h($confirm) . '\')"' : '') . '>' . $label . '</button></form>';
    };
?>
  <p class="crumb"><a href="?page=users">← Alle Benutzer</a></p>
  <div class="head"><h1><?= h($u['username']) ?></h1>
    <div><?= $u['is_admin'] ? '<span class="tag blue">Admin</span> ' : '' ?><?= $u['is_blocked'] ? '<span class="tag red">gesperrt</span>' : '<span class="tag green">aktiv</span>' ?></div></div>
  <div class="tiles">
    <div class="tile"><div class="tl">Registriert</div><div class="tv sm"><?= date('d.m.Y', $u['created_at']) ?></div></div>
    <div class="tile"><div class="tl">Zuletzt aktiv</div><div class="tv sm"><?= ago((int)$u['last_seen']) ?></div></div>
    <div class="tile"><div class="tl">Seitenaufrufe</div><div class="tv"><?= n($count) ?></div></div>
    <div class="tile"><div class="tl">Suchanfragen</div><div class="tv"><?= n($scount) ?></div></div>
  </div>
  <?php if (!$self): ?>
  <div class="actions">
    <?= $u['is_blocked'] ? $act('user_unblock', 'Entsperren', 'primary') : $act('user_block', 'Konto sperren', 'danger', $u['username'] . ' sperren? Die Person wird sofort abgemeldet.') ?>
    <?= $u['is_admin'] ? $act('user_unadmin', 'Admin-Rechte entfernen') : $act('user_admin', 'Zum Admin machen', '', $u['username'] . ' Admin-Rechte geben?') ?>
    <?= $act('user_logout', 'Überall abmelden') ?>
    <?= $act('user_delete', 'Konto löschen', 'danger', $u['username'] . ' und den ganzen Verlauf endgültig löschen?') ?>
  </div>
  <?php endif; ?>
  <div class="actions">
    <?= $act('user_clear_searches', 'Suchverlauf löschen', 'danger', 'Den ganzen Suchverlauf von ' . $u['username'] . ' löschen?') ?>
    <?= $act('user_clear_visits', 'Seitenverlauf löschen', 'danger', 'Alle besuchten Seiten von ' . $u['username'] . ' löschen?') ?>
  </div>
  <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="action" value="user_password"><input type="hidden" name="id" value="<?= $id ?>">
    <input name="password" type="password" minlength="8" required placeholder="Neues Passwort (mind. 8 Zeichen)" autocomplete="new-password" style="max-width:300px;margin:0">
    <button class="btn">Passwort ändern</button></form>
  <div class="grid2">
    <section class="panel"><h2>Meistbesuchte Websites</h2><?php bar_list($domains, 'domain', 'Noch keine Seitenaufrufe.', fn($r) => block_button($r['domain'], "page=user&id=$id")); ?></section>
    <section class="panel"><h2>Letzte Suchanfragen</h2>
      <?php if (!$searches): ?><p class="empty">Noch keine.</p><?php else: ?><table class="t small">
        <?php foreach ($searches as $s): ?><tr><td class="nowrap muted"><?= date('d.m. H:i', $s['created_at']) ?></td><td><?= h($s['query']) ?> <span class="muted">· <?= h($s['engine']) ?></span></td>
          <td class="r"><?= del_button('delete_search', ['id' => $s['id'], 'return' => "page=user&id=$id"]) ?></td></tr><?php endforeach; ?>
      </table><?php endif; ?></section>
  </div>
  <section class="panel flush"><h2 class="pad">Letzte Seitenaufrufe <a class="small" href="?page=history&user=<?= $id ?>">Alle →</a></h2>
    <?php if (!$visits): ?><p class="empty">Noch keine.</p><?php else: ?><table class="t">
      <?php foreach ($visits as $r): ?><tr><td class="nowrap muted"><?= date('d.m.Y H:i', $r['created_at']) ?></td>
        <td class="page"><div class="pt"><?= h($r['title'] ?: '(ohne Titel)') ?></div><a class="pu" href="<?= h($r['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($r['url']) ?></a></td>
        <td class="nowrap"><?= client_tag($r['client']) ?></td></tr><?php endforeach; ?>
    </table><?php endif; ?></section>
  <?php endif; ?>

<?php elseif ($page === 'messages'):
    $all = ($_GET['show'] ?? '') === 'all';
    $rows = q('SELECT m.*, u.username FROM messages m LEFT JOIN users u ON u.id = m.user_id ' . ($all ? '' : "WHERE m.status = 'open' ") . 'ORDER BY m.id DESC LIMIT 200')->fetchAll();
?>
  <div class="head"><h1>Nachrichten</h1>
    <div class="seg"><a href="?page=messages" class="<?= $all ? '' : 'on' ?>">Offen (<?= $openMessages ?>)</a><a href="?page=messages&show=all" class="<?= $all ? 'on' : '' ?>">Alle</a></div></div>
  <?php if (!$rows): ?><section class="panel"><p class="empty"><?= $all ? 'Noch keine Nachrichten.' : 'Keine offenen Nachrichten. 🎉' ?></p></section><?php endif; ?>
  <?php foreach ($rows as $m): ?>
    <section class="panel msgcard <?= $m['status'] === 'open' ? 'open' : '' ?>">
      <div class="mhead"><a href="?page=user&id=<?= (int)$m['user_id'] ?>"><b><?= h($m['username'] ?? 'gelöschter Benutzer') ?></b></a>
        <span class="muted"><?= date('d.m.Y H:i', $m['created_at']) ?> · <?= ago((int)$m['created_at']) ?></span>
        <?= $m['status'] === 'open' ? '<span class="tag blue">offen</span>' : '<span class="tag green">erledigt</span>' ?></div>
      <div class="mbody"><?= h($m['body']) ?></div>
      <?php if ($m['reply']): ?><div class="mreply"><b>Deine Antwort</b> <span class="muted">· <?= date('d.m.Y H:i', $m['replied_at']) ?></span><br><?= h($m['reply']) ?></div><?php endif; ?>
      <form method="post" class="mform"><?= csrf_field() ?><input type="hidden" name="action" value="msg_reply"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
        <textarea name="reply" rows="2" placeholder="<?= $m['reply'] ? 'Antwort ändern …' : 'Antworten …' ?>"></textarea>
        <div class="actions"><button class="btn primary">Antworten</button>
          <?php if ($m['status'] === 'open'): ?><button class="btn" name="action" value="msg_done" formnovalidate>Erledigt</button>
          <?php else: ?><button class="btn" name="action" value="msg_open" formnovalidate>Wieder öffnen</button><?php endif; ?>
          <button class="btn danger" name="action" value="msg_delete" formnovalidate onclick="return confirm('Nachricht löschen?')">Löschen</button></div>
      </form>
    </section>
  <?php endforeach; ?>

<?php elseif ($page === 'announcements'):
    $rows = q('SELECT * FROM announcements ORDER BY id DESC LIMIT 100')->fetchAll();
?>
  <div class="head"><h1>Ankündigungen</h1></div>
  <section class="panel narrow">
    <h2>Neue Ankündigung</h2>
    <p class="muted small">Erscheint bei allen Benutzern als Banner oben in der App und in der Online-Version. Jede Person kann sie wegklicken.</p>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="ann_create">
      <label>Text<textarea name="body" rows="3" required maxlength="1000" placeholder="z. B. Am Freitag ist SchoolWeb wegen Wartung kurz offline."></textarea></label>
      <div class="form row">
        <select name="level" aria-label="Art"><?php foreach (ANNOUNCEMENT_LEVELS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
        <select name="days" aria-label="Dauer"><option value="0">bis ich sie ausschalte</option><option value="1">1 Tag</option><option value="3">3 Tage</option><option value="7">1 Woche</option><option value="30">1 Monat</option></select>
        <button class="btn primary">Veröffentlichen</button>
      </div>
    </form>
  </section>
  <?php if (!$rows): ?><section class="panel"><p class="empty">Noch keine Ankündigungen.</p></section><?php endif; ?>
  <?php foreach ($rows as $r): $live = $r['active'] && (!$r['expires_at'] || $r['expires_at'] > time()); ?>
    <section class="panel ann ann-<?= h($r['level']) ?> <?= $live ? '' : 'off' ?>">
      <div class="mhead"><span class="tag <?= $r['level'] === 'warning' ? 'red' : ($r['level'] === 'success' ? 'green' : 'blue') ?>"><?= h(ANNOUNCEMENT_LEVELS[$r['level']] ?? 'Info') ?></span>
        <span class="muted"><?= date('d.m.Y H:i', $r['created_at']) ?><?= $r['expires_at'] ? ' · bis ' . date('d.m.Y H:i', $r['expires_at']) : '' ?></span>
        <?= $live ? '<span class="tag green">sichtbar</span>' : '<span class="tag">nicht sichtbar</span>' ?></div>
      <div class="mbody"><?= h($r['body']) ?></div>
      <div class="actions" style="margin:12px 0 0">
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="ann_toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn"><?= $r['active'] ? 'Ausschalten' : 'Wieder einschalten' ?></button></form>
        <?= del_button('ann_delete', ['id' => $r['id']], 'Löschen', 'Ankündigung löschen?') ?>
      </div>
    </section>
  <?php endforeach; ?>

<?php elseif ($page === 'search'):
    $text = trim((string)($_GET['q'] ?? ''));
    $like = '%' . $text . '%';
    $found = $text === '' ? null : [
        'users' => q('SELECT id, username, last_seen, is_blocked FROM users WHERE username LIKE ? ORDER BY username LIMIT 20', [$like])->fetchAll(),
        'searches' => q('SELECT s.*, u.username FROM searches s LEFT JOIN users u ON u.id = s.user_id WHERE s.query LIKE ? ORDER BY s.id DESC LIMIT 30', [$like])->fetchAll(),
        'visits' => q('SELECT v.*, u.username FROM visits v LEFT JOIN users u ON u.id = v.user_id WHERE v.url LIKE ? OR v.title LIKE ? ORDER BY v.id DESC LIMIT 30', [$like, $like])->fetchAll(),
        'messages' => q('SELECT m.*, u.username FROM messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.body LIKE ? OR m.reply LIKE ? ORDER BY m.id DESC LIMIT 20', [$like, $like])->fetchAll(),
        'blocked' => q('SELECT * FROM blocked WHERE domain LIKE ? OR note LIKE ? ORDER BY domain LIMIT 20', [$like, $like])->fetchAll(),
    ];
?>
  <div class="head"><h1>Suche</h1></div>
  <form class="filters" method="get"><input type="hidden" name="page" value="search">
    <input type="search" name="q" value="<?= h($text) ?>" placeholder="Benutzer, Suchbegriff, Website, Nachricht …" autofocus>
    <button class="btn primary">Suchen</button></form>
  <?php if ($found === null): ?><section class="panel"><p class="empty">Gib oben einen Begriff ein. Gesucht wird in Benutzern, Suchanfragen, Verlauf, Nachrichten und gesperrten Seiten.</p></section>
  <?php elseif (!array_filter($found)): ?><section class="panel"><p class="empty">Nichts gefunden für „<?= h($text) ?>“.</p></section>
  <?php else: ?>
    <?php if ($found['users']): ?><section class="panel"><h2>Benutzer (<?= count($found['users']) ?>)</h2><table class="t small">
      <?php foreach ($found['users'] as $u): ?><tr><td><a href="?page=user&id=<?= (int)$u['id'] ?>"><b><?= h($u['username']) ?></b></a></td><td class="muted">zuletzt aktiv: <?= ago((int)$u['last_seen']) ?></td><td><?= $u['is_blocked'] ? '<span class="tag red">gesperrt</span>' : '' ?></td></tr><?php endforeach; ?></table></section><?php endif; ?>
    <?php if ($found['searches']): ?><section class="panel"><h2>Suchanfragen (<?= count($found['searches']) ?>)</h2><table class="t small">
      <?php foreach ($found['searches'] as $r): ?><tr><td class="nowrap muted"><?= date('d.m.Y H:i', $r['created_at']) ?></td><td><a href="?page=user&id=<?= (int)$r['user_id'] ?>"><?= h($r['username'] ?? '–') ?></a></td><td><?= h($r['query']) ?> <span class="muted">· <?= h($r['engine']) ?></span></td></tr><?php endforeach; ?></table></section><?php endif; ?>
    <?php if ($found['visits']): ?><section class="panel"><h2>Verlauf (<?= count($found['visits']) ?>) <a class="small" href="?page=history&q=<?= urlencode($text) ?>">Alle →</a></h2><table class="t small">
      <?php foreach ($found['visits'] as $r): ?><tr><td class="nowrap muted"><?= date('d.m.Y H:i', $r['created_at']) ?></td><td><a href="?page=user&id=<?= (int)$r['user_id'] ?>"><?= h($r['username'] ?? '–') ?></a></td>
        <td class="page"><div class="pt"><?= h($r['title'] ?: '(ohne Titel)') ?></div><a class="pu" href="<?= h($r['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($r['url']) ?></a></td></tr><?php endforeach; ?></table></section><?php endif; ?>
    <?php if ($found['messages']): ?><section class="panel"><h2>Nachrichten (<?= count($found['messages']) ?>) <a class="small" href="?page=messages&show=all">Alle →</a></h2><table class="t small">
      <?php foreach ($found['messages'] as $r): ?><tr><td class="nowrap muted"><?= date('d.m.Y H:i', $r['created_at']) ?></td><td><?= h($r['username'] ?? '–') ?></td><td><?= h(mb_strimwidth($r['body'], 0, 160, '…')) ?></td></tr><?php endforeach; ?></table></section><?php endif; ?>
    <?php if ($found['blocked']): ?><section class="panel"><h2>Gesperrte Seiten (<?= count($found['blocked']) ?>)</h2><table class="t small">
      <?php foreach ($found['blocked'] as $r): ?><tr><td><b><?= h($r['domain']) ?></b></td><td class="muted"><?= h($r['note']) ?></td></tr><?php endforeach; ?></table></section><?php endif; ?>
  <?php endif; ?>

<?php elseif ($page === 'blocked'):
    $rows = q('SELECT * FROM blocked ORDER BY domain')->fetchAll();
?>
  <div class="head"><h1>Gesperrte Seiten</h1><span class="muted"><?= count($rows) ?> gesperrt</span></div>
  <section class="panel narrow">
    <h2>Seite sperren</h2>
    <p class="muted small">Gesperrt wird die Domain mit allen Subdomains: <b>example.com</b> sperrt auch www.example.com und m.example.com.
      Gilt für die Windows-App (innerhalb einer Minute) und die Online-Version (sofort).</p>
    <form method="post" class="form row"><?= csrf_field() ?><input type="hidden" name="action" value="block">
      <input name="domain" required placeholder="z. B. example.com oder https://example.com/seite">
      <input name="note" placeholder="Notiz (optional)">
      <button class="btn danger">Sperren</button>
    </form>
  </section>
  <section class="panel flush">
    <?php if (!$rows): ?><p class="empty">Noch keine Seiten gesperrt.</p><?php else: ?>
    <table class="t"><tr><th>Domain</th><th>Notiz</th><th>Gesperrt seit</th><th></th></tr>
      <?php foreach ($rows as $r): ?><tr><td><b><?= h($r['domain']) ?></b></td><td class="muted"><?= h($r['note']) ?></td><td class="muted nowrap"><?= date('d.m.Y', $r['created_at']) ?></td>
        <td class="r"><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="unblock"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="link">Entsperren</button></form></td></tr><?php endforeach; ?>
    </table><?php endif; ?>
  </section>

<?php elseif ($page === 'settings'): ?>
  <div class="head"><h1>Einstellungen</h1></div>
  <section class="panel narrow">
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
      <label class="check"><input type="checkbox" name="registration_open" value="1" <?= setting('registration_open', '1') === '1' ? 'checked' : '' ?>>
        <span><b>Registrierung offen</b><br>Jeder kann sich selbst ein Konto erstellen. Wenn aus, kannst nur du unter „Benutzer“ Konten anlegen.</span></label>
      <label>Suchmaschine in den Browsern
        <select name="search_engine"><?php foreach (SEARCH_OPTIONS as $k => [$name]): ?><option value="<?= $k ?>" <?= search_option()['key'] === $k ? 'selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?></select></label>
      <p class="muted small" style="margin-top:-6px">Gilt für die Windows-App. Die Online-Version sucht immer mit DuckDuckGo, weil die anderen Suchmaschinen dort nicht funktionieren.</p>
      <label>Verlauf automatisch löschen nach … Tagen (0 = nie)
        <input type="number" name="retention_days" min="0" max="3650" value="<?= (int)setting('retention_days', '0') ?>"></label>
      <button class="btn primary">Speichern</button>
    </form>
  </section>
  <section class="panel narrow">
    <h2>Verlauf löschen</h2>
    <form method="post" class="form row"><?= csrf_field() ?><input type="hidden" name="action" value="clear_history">
      <select name="days"><option value="30">älter als 30 Tage</option><option value="90">älter als 90 Tage</option><option value="365">älter als 1 Jahr</option><option value="0">alles</option></select>
      <button class="btn danger" onclick="return confirm('Verlauf wirklich löschen?')">Löschen</button>
    </form>
  </section>
  <section class="panel narrow">
    <h2>Server-Adresse für die Windows-App</h2>
    <p class="muted small">Diese Adresse tragen die Benutzer beim ersten Start der App ein:</p>
    <p><code><?= h(base_url()) ?></code></p>
    <p class="muted small">Datenbank: <?= is_mysql() ? 'MySQL/MariaDB' : 'SQLite (Ordner data/)' ?></p>
  </section>
<?php endif; ?>
</main>
<footer class="foot"><a href="https://hexa.da-rtx.ch" target="_blank" rel="noopener">hexa.da-rtx.ch</a> · <span class="sig">crafted by D.</span></footer>
<script src="../assets/egg.js"></script>
</body>
</html>
