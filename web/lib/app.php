<?php
// Kern des Browser-Servers: Datenbank, Anmeldung, Hilfsfunktionen.
require __DIR__ . '/config.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Europe/Zurich');

const SEARCH_ENGINES = [
    // Host-Endung => [Pfad-Anfang, Such-Parameter, Name]
    'google.'        => ['/search', 'q', 'Google'],
    'bing.com'       => ['/search', 'q', 'Bing'],
    'duckduckgo.com' => ['/', 'q', 'DuckDuckGo'],
    'ecosia.org'     => ['/search', 'q', 'Ecosia'],
    'search.yahoo.com' => ['/search', 'p', 'Yahoo'],
    'startpage.com'  => ['/', 'query', 'Startpage'],
    'search.brave.com' => ['/search', 'q', 'Brave'],
    'youtube.com'    => ['/results', 'search_query', 'YouTube'],
    'wikipedia.org'  => ['/', 'search', 'Wikipedia'],
];

// ---- Datenbank ---------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    if (DB_DSN !== '') {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS);
    } else {
        $dir = dirname(__DIR__) . '/data';
        // Zufälliger Dateiname, damit niemand die Datenbank erraten und herunterladen kann
        $keyFile = "$dir/secret.php";
        if (!is_file($keyFile)) {
            if (!is_writable($dir)) fatal('Der Ordner "data" ist nicht beschreibbar. Bitte per FTP die Rechte auf 755 oder 775 setzen.');
            file_put_contents($keyFile, "<?php return '" . bin2hex(random_bytes(16)) . "';\n");
        }
        $key = require $keyFile;
        $pdo = new PDO("sqlite:$dir/db-$key.sqlite");
        $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    migrate($pdo);
    return $pdo;
}

function is_mysql(): bool { return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }

function migrate(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $txt = $mysql ? 'VARCHAR(191)' : 'TEXT';
    $long = $mysql ? 'TEXT' : 'TEXT';
    $end = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $tables = [
        "CREATE TABLE IF NOT EXISTS users (
            id $id, username $txt NOT NULL UNIQUE, password_hash $txt NOT NULL,
            is_admin INT NOT NULL DEFAULT 0, is_blocked INT NOT NULL DEFAULT 0,
            created_at INT NOT NULL, last_seen INT NOT NULL DEFAULT 0)$end",
        "CREATE TABLE IF NOT EXISTS tokens (
            token_hash CHAR(64) PRIMARY KEY, user_id INT NOT NULL, client $txt NOT NULL,
            created_at INT NOT NULL, last_used INT NOT NULL)$end",
        "CREATE TABLE IF NOT EXISTS visits (
            id $id, user_id INT NOT NULL, url $long NOT NULL, domain $txt NOT NULL,
            title $long, client $txt NOT NULL, created_at INT NOT NULL)$end",
        "CREATE TABLE IF NOT EXISTS searches (
            id $id, user_id INT NOT NULL, query $txt NOT NULL, engine $txt NOT NULL,
            created_at INT NOT NULL)$end",
        "CREATE TABLE IF NOT EXISTS blocked (
            id $id, domain $txt NOT NULL UNIQUE, note $txt, created_at INT NOT NULL)$end",
        "CREATE TABLE IF NOT EXISTS settings (name $txt PRIMARY KEY, value $long)$end",
        "CREATE TABLE IF NOT EXISTS login_attempts (ip $txt NOT NULL, created_at INT NOT NULL)$end",
    ];
    // Nur einmal pro Version ausführen
    try {
        $v = $pdo->query("SELECT value FROM settings WHERE name = 'schema'")->fetchColumn();
        if ($v === '1') return;
    } catch (Throwable $e) {}
    foreach ($tables as $sql) $pdo->exec($sql);
    foreach ([
        'CREATE INDEX idx_visits_time ON visits (created_at)',
        'CREATE INDEX idx_visits_user ON visits (user_id, created_at)',
        'CREATE INDEX idx_searches_time ON searches (created_at)',
        'CREATE INDEX idx_tokens_user ON tokens (user_id)',
    ] as $sql) { try { $pdo->exec($sql); } catch (Throwable $e) {} }
    $pdo->exec("DELETE FROM settings WHERE name = 'schema'");
    $pdo->exec("INSERT INTO settings (name, value) VALUES ('schema', '1')");
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function setting(string $name, $default = null)
{
    $v = q('SELECT value FROM settings WHERE name = ?', [$name])->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting(string $name, string $value): void
{
    q('DELETE FROM settings WHERE name = ?', [$name]);
    q('INSERT INTO settings (name, value) VALUES (?, ?)', [$name, $value]);
}

function user_count(): int { return (int)q('SELECT COUNT(*) FROM users')->fetchColumn(); }

// ---- Benutzer & Anmeldung --------------------------------------------------------
function valid_username(string $u): bool { return (bool)preg_match('/^[A-Za-z0-9._\-]{3,32}$/', $u); }

function create_user(string $username, string $password, bool $admin = false): int
{
    q('INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, ?, ?)',
      [$username, password_hash($password, PASSWORD_DEFAULT), $admin ? 1 : 0, time()]);
    return (int)db()->lastInsertId();
}

function find_user(string $username): ?array
{
    $u = q('SELECT * FROM users WHERE LOWER(username) = LOWER(?)', [$username])->fetch();
    return $u ?: null;
}

function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 64); }

function too_many_attempts(): bool
{
    q('DELETE FROM login_attempts WHERE created_at < ?', [time() - 900]);
    return (int)q('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [client_ip()])->fetchColumn() >= 10;
}

/** Prüft Name und Passwort. Gibt den Benutzer oder einen Fehlertext zurück. */
function check_login(string $username, string $password)
{
    if (too_many_attempts()) return 'Zu viele Fehlversuche. Bitte 15 Minuten warten.';
    $u = find_user($username);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        q('INSERT INTO login_attempts (ip, created_at) VALUES (?, ?)', [client_ip(), time()]);
        return 'Benutzername oder Passwort ist falsch.';
    }
    if ($u['is_blocked']) return 'Dein Konto wurde vom Administrator gesperrt.';
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    return $u;
}

/** Neuer Zugangsschlüssel für App/Online-Version. Gespeichert wird nur der Hash. */
function create_token(int $userId, string $client): string
{
    $raw = bin2hex(random_bytes(16));
    q('INSERT INTO tokens (token_hash, user_id, client, created_at, last_used) VALUES (?, ?, ?, ?, ?)',
      [hash('sha256', $raw), $userId, $client, time(), time()]);
    q('UPDATE users SET last_seen = ? WHERE id = ?', [time(), $userId]);
    return $raw;
}

function user_by_token(string $raw): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $raw)) return null;
    $u = q('SELECT u.*, t.client AS token_client, t.last_used AS token_used FROM tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ?',
           [hash('sha256', $raw)])->fetch();
    if (!$u || $u['is_blocked']) return null;
    if ($u['token_used'] < time() - 60) { // nicht bei jeder Anfrage schreiben
        q('UPDATE tokens SET last_used = ? WHERE token_hash = ?', [time(), hash('sha256', $raw)]);
        q('UPDATE users SET last_seen = ? WHERE id = ?', [time(), $u['id']]);
    }
    return $u;
}

function delete_token(string $raw): void { q('DELETE FROM tokens WHERE token_hash = ?', [hash('sha256', $raw)]); }

// ---- Web-Sitzung (login.php, Admin, Online-Version) -----------------------
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('browser_session');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => base_path() . '/', 'httponly' => true,
        'secure' => is_https(), 'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    start_session();
    if (empty($_SESSION['web_token'])) return null;
    $u = user_by_token($_SESSION['web_token']);
    if (!$u) { unset($_SESSION['web_token']); return null; }
    return $u;
}

function login_session(array $user): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['web_token'] = create_token((int)$user['id'], 'web');
}

function require_login(): array
{
    if (user_count() === 0) redirect('setup.php');
    $u = current_user();
    if (!$u) redirect('login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if (!$u['is_admin']) { http_response_code(403); page_start('Kein Zugriff'); echo '<div class="box"><h1>Kein Zugriff</h1><p>Diese Seite ist nur für Administratoren.</p><p><a href="' . url('') . '">Zurück</a></p></div>'; page_end(); exit; }
    return $u;
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function check_csrf(): void
{
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Ungültiges Formular. Bitte Seite neu laden.'); }
}

// ---- Besuche, Suchen, Sperrliste ---------------------------------------
function host_of(string $url): string
{
    $h = strtolower((string)parse_url($url, PHP_URL_HOST));
    return preg_replace('/^www\./', '', $h);
}

function normalize_domain(string $d): string
{
    $d = strtolower(trim($d));
    if (strpos($d, '://') !== false) $d = (string)parse_url($d, PHP_URL_HOST);
    $d = preg_replace('#[/:?].*$#', '', $d);
    return preg_replace('/^(\*\.|www\.)/', '', trim($d, '.'));
}

/** Ist die Adresse gesperrt? Sperrt auch alle Subdomains (z. B. m.example.com). */
function is_blocked_url(string $url): bool
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') return false;
    $parts = explode('.', $host);
    $candidates = [];
    for ($i = 0; $i < count($parts); $i++) $candidates[] = implode('.', array_slice($parts, $i));
    $in = implode(',', array_fill(0, count($candidates), '?'));
    return (bool)q("SELECT 1 FROM blocked WHERE domain IN ($in)", $candidates)->fetchColumn();
}

function blocked_domains(): array { return q('SELECT domain FROM blocked ORDER BY domain')->fetchAll(PDO::FETCH_COLUMN); }

/** Erkennt eine Suche in der Adresse (z. B. google.com/search?q=...). */
function detect_search(string $url): ?array
{
    $p = parse_url($url);
    $host = strtolower($p['host'] ?? '');
    parse_str($p['query'] ?? '', $query);
    foreach (SEARCH_ENGINES as $suffix => [$path, $param, $name]) {
        $match = substr($suffix, -1) === '.' ? (bool)preg_match('/(^|\.)' . preg_quote($suffix, '/') . '[a-z.]+$/', $host)
                                           : ($host === $suffix || substr($host, -strlen($suffix) - 1) === ".$suffix");
        if ($match && strpos($p['path'] ?? '/', $path) === 0 && isset($query[$param]) && is_string($query[$param])) {
            $term = trim(preg_replace('/\s+/', ' ', $query[$param]));
            if ($term !== '') return [mb_substr($term, 0, 190), $name];
        }
    }
    return null;
}

function log_visit(array $user, string $url, string $title, string $client): void
{
    if (!preg_match('#^https?://#i', $url) || strlen($url) > 4000) return;
    $title = mb_substr(trim($title), 0, 300);
    // Gleiche Seite innerhalb 30 Sekunden = nur Titel aktualisieren
    $last = q('SELECT id, url FROM visits WHERE user_id = ? AND created_at > ? ORDER BY id DESC LIMIT 1', [$user['id'], time() - 30])->fetch();
    if ($last && $last['url'] === $url) {
        if ($title !== '') q('UPDATE visits SET title = ? WHERE id = ?', [$title, $last['id']]);
        return;
    }
    q('UPDATE users SET last_seen = ? WHERE id = ?', [time(), $user['id']]);
    q('INSERT INTO visits (user_id, url, domain, title, client, created_at) VALUES (?, ?, ?, ?, ?, ?)',
      [$user['id'], $url, host_of($url), $title, $client, time()]);
    if ($s = detect_search($url)) {
        q('INSERT INTO searches (user_id, query, engine, created_at) VALUES (?, ?, ?, ?)', [$user['id'], $s[0], $s[1], time()]);
    }
    // Alte Einträge löschen (Datenschutz), falls in den Einstellungen aktiviert
    $days = (int)setting('retention_days', '0');
    if ($days > 0 && mt_rand(1, 50) === 1) {
        q('DELETE FROM visits WHERE created_at < ?', [time() - $days * 86400]);
        q('DELETE FROM searches WHERE created_at < ?', [time() - $days * 86400]);
    }
}

// ---- Adressen & Ausgabe -------------------------------------------------------
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

/** Pfad des Browser-Ordners auf dem Server, z. B. "/browser" (ohne / am Ende) */
function base_path(): string
{
    $root = realpath(dirname(__DIR__));
    $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    $depth = $script ? substr_count(substr(dirname($script), strlen($root)), DIRECTORY_SEPARATOR) : 0;
    $p = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    for ($i = 0; $i < $depth; $i++) $p = dirname($p);
    return rtrim(str_replace('\\', '/', $p), '/');
}

function base_url(): string
{
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    return (is_https() ? 'https' : 'http') . '://' . $host . base_path();
}

function url(string $path): string { return base_path() . '/' . ltrim($path, '/'); }
function redirect(string $path): void { header('Location: ' . (preg_match('#^(/|https?:)#', $path) ? $path : url($path))); exit; }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fatal(string $msg): void
{
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Fehler</title><body style="font:16px system-ui;padding:40px;max-width:640px;margin:auto"><h2>Problem bei der Einrichtung</h2><p>' . h($msg) . '</p></body>';
    exit;
}

function page_start(string $title, string $bodyClass = ''): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: SAMEORIGIN');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . h($title) . '</title><link rel="stylesheet" href="' . url('style.css') . '"></head><body class="' . h($bodyClass) . '">';
}
function page_end(): void { echo '</body></html>'; }

function ago(int $t): string
{
    if (!$t) return 'nie';
    $d = time() - $t;
    if ($d < 60) return 'gerade eben';
    if ($d < 3600) return 'vor ' . floor($d / 60) . ' Min.';
    if ($d < 86400) return 'vor ' . floor($d / 3600) . ' Std.';
    if ($d < 7 * 86400) return 'vor ' . floor($d / 86400) . ' Tagen';
    return date('d.m.Y', $t);
}

set_exception_handler(function (Throwable $e) {
    error_log((string)$e);
    if ($e instanceof PDOException) fatal('Datenbankfehler: ' . $e->getMessage());
    fatal('Es ist ein Fehler aufgetreten.');
});
