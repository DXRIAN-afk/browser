<?php
/*
 * Schnittstelle für die Windows-App und die Online-Version.
 *   POST api.php?action=login      {username, password}      → {token, username}
 *   POST api.php?action=logout
 *   GET  api.php?action=me                                    → {username, is_admin}
 *   GET  api.php?action=blocklist                             → {domains: [...]}
 *   POST api.php?action=log        {url, title}               → Seitenaufruf speichern
 * Anmeldung über den Header  "Authorization: Bearer <token>".
 */
require __DIR__ . '/lib/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
set_exception_handler(function (Throwable $e) {
    error_log((string)$e);
    http_response_code(500);
    echo json_encode(['error' => 'Serverfehler']);
});

function out(array $data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function input(): array
{
    $j = json_decode(file_get_contents('php://input'), true);
    return is_array($j) ? $j : $_POST;
}
function bearer(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+([a-f0-9]{32})$/i', trim($h), $m)) return strtolower($m[1]);
    return (string)($_SERVER['HTTP_X_BROWSER_TOKEN'] ?? ''); // falls der Hoster "Authorization" verschluckt
}
function auth(): array
{
    $u = user_by_token(bearer());
    if (!$u) out(['error' => 'Nicht angemeldet oder Konto gesperrt.'], 401);
    return $u;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($action) {
    case 'login':
        if ($method !== 'POST') out(['error' => 'POST erwartet'], 405);
        if (user_count() === 0) out(['error' => 'Der Server ist noch nicht eingerichtet.'], 503);
        $in = input();
        $r = check_login(trim((string)($in['username'] ?? '')), (string)($in['password'] ?? ''));
        if (!is_array($r)) out(['error' => $r], 401);
        out(['token' => create_token((int)$r['id'], 'app'), 'username' => $r['username'], 'is_admin' => (bool)$r['is_admin']]);

    case 'logout':
        delete_token(bearer());
        out(['ok' => true]);

    case 'me':
        $u = auth();
        out(['username' => $u['username'], 'is_admin' => (bool)$u['is_admin']]);

    case 'blocklist':
        auth();
        out(['domains' => blocked_domains()]);

    case 'log':
        if ($method !== 'POST') out(['error' => 'POST erwartet'], 405);
        $u = auth();
        $in = input();
        $client = $u['token_client'] === 'web' ? 'online' : 'app';
        log_visit($u, (string)($in['url'] ?? ''), (string)($in['title'] ?? ''), $client);
        out(['ok' => true]);

    case 'info':
        out(['name' => 'Browser-Server', 'registration_open' => setting('registration_open', '1') === '1']);

    default:
        out(['error' => 'Unbekannte Aktion'], 404);
}
