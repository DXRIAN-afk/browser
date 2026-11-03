<?php
/*
 * Schnittstelle für die Windows-App und die Online-Version.
 *   POST api.php?action=login      {username, password}      → {token, username}
 *   POST api.php?action=logout
 *   GET  api.php?action=me                                    → {username, is_admin}
 *   GET  api.php?action=blocklist                             → {domains: [...]}
 *   POST api.php?action=log        {url, title}               → Seitenaufruf speichern
 *   GET  api.php?action=config                                → {search_url, search_name}
 *   GET  api.php?action=favorites                             → {favorites: [...]}
 *   POST api.php?action=favorite_add    {url, title}
 *   POST api.php?action=favorite_rename {id, title}
 *   POST api.php?action=favorite_remove {id}
 *   GET  api.php?action=messages                              → eigene Nachrichten + Antworten
 *   POST api.php?action=message_send    {body}
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
        out(['username' => $u['username'], 'is_admin' => (bool)$u['is_admin'], 'unseen_replies' => unseen_replies((int)$u['id'])]);

    case 'config':
        auth();
        $s = search_option();
        out(['search_url' => $s['url'], 'search_name' => $s['name']]);

    case 'favorites':
        $u = auth();
        out(['favorites' => favorites((int)$u['id'])]);

    case 'favorite_add':
        if ($method !== 'POST') out(['error' => 'POST erwartet'], 405);
        $u = auth(); $in = input();
        $f = add_favorite((int)$u['id'], (string)($in['url'] ?? ''), (string)($in['title'] ?? ''));
        if (!$f) out(['error' => 'Dieser Favorit kann nicht gespeichert werden (höchstens 100).'], 400);
        out(['favorite' => $f, 'favorites' => favorites((int)$u['id'])]);

    case 'favorite_rename':
        if ($method !== 'POST') out(['error' => 'POST erwartet'], 405);
        $u = auth(); $in = input();
        rename_favorite((int)$u['id'], (int)($in['id'] ?? 0), (string)($in['title'] ?? ''));
        out(['favorites' => favorites((int)$u['id'])]);

    case 'favorite_remove':
        if ($method !== 'POST') out(['error' => 'POST erwartet'], 405);
        $u = auth(); $in = input();
        remove_favorite((int)$u['id'], (int)($in['id'] ?? 0));
        out(['favorites' => favorites((int)$u['id'])]);

    case 'messages':
        $u = auth();
        $list = user_messages((int)$u['id']);
        mark_replies_seen((int)$u['id']);
        out(['messages' => $list]);

    case 'message_send':
        if ($method !== 'POST') out(['error' => 'POST erwartet'], 405);
        $u = auth(); $in = input();
        if (!send_message((int)$u['id'], (string)($in['body'] ?? ''))) out(['error' => 'Die Nachricht ist leer, zu lang (max. 4000 Zeichen) oder du hast schon zu viele gesendet.'], 400);
        out(['ok' => true, 'messages' => user_messages((int)$u['id'])]);

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
        out(['name' => APP_NAME, 'registration_open' => setting('registration_open', '1') === '1']);

    default:
        out(['error' => 'Unbekannte Aktion'], 404);
}
