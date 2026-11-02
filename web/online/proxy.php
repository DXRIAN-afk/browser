<?php
/*
 * Proxy für die Online-Version des Browsers.
 * Lädt eine Website über deinen Server, damit sie im Browser-Fenster
 * (iframe) angezeigt werden kann.
 *
 * Braucht PHP 7.4+ mit der Erweiterung "curl" (bei fast allen Hostern aktiv).
 * Nur angemeldete Benutzer dürfen ihn verwenden, gesperrte Seiten werden abgewiesen.
 */
require __DIR__ . '/../lib/app.php';

// ---- Einstellungen ---------------------------------------------------------
const MAX_BYTES     = 25 * 1024 * 1024;   // größte erlaubte Antwort (25 MB)
const TIMEOUT       = 25;                 // Sekunden
const ALLOWED_PORTS = [80, 443, 8080, 8443];
// ---------------------------------------------------------------------------

error_reporting(0);
ini_set('display_errors', '0');

// CORS: die Seiten im iframe laufen in einer Sandbox (Origin "null")
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . ($origin === 'null' ? 'null' : $origin));
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS');
    if (!empty($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header('Access-Control-Allow-Headers: ' . $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']);
    }
    header('Access-Control-Max-Age: 600');
    http_response_code(204);
    exit;
}

function fail(int $code, string $msg, string $heading = 'Seite konnte nicht geladen werden'): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $m = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><meta charset=utf-8><title>Fehler</title>"
       . "<body style=\"font:15px system-ui,sans-serif;background:#1e1f22;color:#e3e5e8;padding:60px\">"
       . "<h2>" . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . "</h2><p style=\"color:#9aa0a6\">$m</p>"
       . "<script>parent.postMessage({type:'error',code:$code,message:" . json_encode($msg) . "},'*')</script></body>";
    exit;
}

if (!function_exists('curl_init')) {
    fail(500, 'Auf diesem Server ist die PHP-Erweiterung "curl" nicht aktiviert.');
}

// ---- Parameter -------------------------------------------------------------
// __u = Zieladresse, __s = Sitzungs-ID (für Cookies). Alle anderen Parameter
// stammen aus GET-Formularen und werden an die Zieladresse angehängt.
$target = $_GET['__u'] ?? '';
$token  = $_GET['__s'] ?? '';
$user = user_by_token($token);
if (!$user) fail(401, 'Bitte melde dich an, um den Browser zu benutzen.', 'Nicht angemeldet');
if ($target === '') fail(400, 'Keine Adresse angegeben.');

$extra = [];
foreach (explode('&', $_SERVER['QUERY_STRING'] ?? '') as $pair) {
    if ($pair === '') continue;
    $key = urldecode(explode('=', $pair, 2)[0]);
    if ($key !== '__u' && $key !== '__s') $extra[] = $pair;
}
if ($extra) {
    // Wie im echten Browser: ein GET-Formular ersetzt die Query der Zieladresse
    $target = preg_replace('/[?#].*$/s', '', $target) . '?' . implode('&', $extra);
}
$target = preg_replace('/#.*$/s', '', $target);

// ---- Hilfsfunktionen -------------------------------------------------------
function proxy_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https' : 'http') . '://' . $host . ($_SERVER['SCRIPT_NAME'] ?? '/proxy.php');
}

function proxy_url(string $abs): string
{
    global $token;
    return proxy_base() . '?__s=' . $token . '&__u=' . rawurlencode($abs);
}

function resolve_url(string $base, string $rel): string
{
    $rel = trim($rel);
    if ($rel === '') return $base;
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $rel)) return $rel;
    $b = parse_url($base);
    $scheme = $b['scheme'] ?? 'https';
    $auth = ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
    if (substr($rel, 0, 2) === '//') return $scheme . ':' . $rel;
    $path = $b['path'] ?? '/';
    if ($rel[0] === '?') return "$scheme://$auth$path$rel";
    if ($rel[0] === '#') return preg_replace('/#.*$/s', '', $base) . $rel;
    $p = $rel[0] === '/' ? $rel : preg_replace('#/[^/]*$#', '/', $path) . $rel;
    $suffix = '';
    if (preg_match('/[?#]/', $p, $m, PREG_OFFSET_CAPTURE)) {
        $suffix = substr($p, $m[0][1]);
        $p = substr($p, 0, $m[0][1]);
    }
    $out = [];
    $segs = explode('/', $p);
    foreach ($segs as $i => $seg) {
        if ($seg === '..') { if (count($out) > 1) array_pop($out); if ($i === count($segs) - 1) $out[] = ''; }
        elseif ($seg === '.') { if ($i === count($segs) - 1) $out[] = ''; }
        else $out[] = $seg;
    }
    $p = implode('/', $out);
    if ($p === '' || $p[0] !== '/') $p = '/' . $p;
    return "$scheme://$auth$p$suffix";
}

function is_public_ip(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    $n = ip2long($ip);
    // 100.64.0.0/10 (Carrier-NAT) und 0.0.0.0/8
    if (($n & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) return false;
    if (($n & 0xFF000000) === 0) return false;
    return true;
}

// ---- Zieladresse prüfen (keine internen Adressen des Servers erreichbar) ---
$parts = parse_url($target);
$scheme = strtolower($parts['scheme'] ?? '');
if (!$parts || !in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
    fail(400, 'Ungültige Adresse: ' . $target);
}
$host = strtolower($parts['host']);
$port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
if (!in_array($port, ALLOWED_PORTS, true)) fail(403, "Port $port ist nicht erlaubt.");
if (is_blocked_url($target)) fail(451, "Die Seite $host wurde vom Administrator gesperrt.", 'Seite gesperrt');
if (strpos($host, ':') !== false || $host[0] === '[') fail(400, 'IPv6-Adressen werden nicht unterstützt.');
$ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
if (!$ips) fail(502, "Die Adresse \"$host\" wurde nicht gefunden.");
foreach ($ips as $ip) {
    if (!is_public_ip($ip)) fail(403, 'Interne Adressen sind nicht erlaubt.');
}

// ---- Anfrage weiterleiten --------------------------------------------------
$respHeaders = [];
$ch = curl_init($target);
$reqHeaders = [];
foreach ([
    'HTTP_ACCEPT' => 'Accept',
    'HTTP_ACCEPT_LANGUAGE' => 'Accept-Language',
    'HTTP_RANGE' => 'Range',
    'HTTP_X_REQUESTED_WITH' => 'X-Requested-With',
    'HTTP_AUTHORIZATION' => 'Authorization',
] as $k => $name) {
    if (!empty($_SERVER[$k])) $reqHeaders[] = "$name: " . $_SERVER[$k];
}
$reqHeaders[] = 'Expect:';

$opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => TIMEOUT,
    CURLOPT_ENCODING       => '',
    CURLOPT_USERAGENT      => $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0',
    CURLOPT_RESOLVE        => ["$host:$port:{$ips[0]}"],
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_NOPROGRESS     => false,
    CURLOPT_XFERINFOFUNCTION => function ($c, $dlTotal, $dl) { return ($dl > MAX_BYTES || $dlTotal > MAX_BYTES) ? 1 : 0; },
    CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
        if (preg_match('#^HTTP/#', $line)) $respHeaders = [];
        elseif (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $respHeaders[strtolower(trim($k))][] = trim($v);
        }
        return strlen($line);
    },
];
if ($method === 'HEAD') $opts[CURLOPT_NOBODY] = true;

if (true) {
    $jar = sys_get_temp_dir() . '/webbrowser_cookies_' . hash('sha256', $token) . '.txt';
    $opts[CURLOPT_COOKIEFILE] = $jar;
    $opts[CURLOPT_COOKIEJAR] = $jar;
    if (mt_rand(1, 100) === 1) { // alte Cookie-Dateien aufräumen
        foreach (glob(sys_get_temp_dir() . '/webbrowser_cookies_*.txt') ?: [] as $f) {
            if (filemtime($f) < time() - 2 * 86400) @unlink($f);
        }
    }
}

if (!in_array($method, ['GET', 'HEAD'], true)) {
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'multipart/form-data') === 0) {
        $fields = [];
        foreach ($_POST as $k => $v) if (is_string($v)) $fields[$k] = $v;
        foreach ($_FILES as $k => $f) {
            if (is_string($f['tmp_name']) && is_uploaded_file($f['tmp_name'])) {
                $fields[$k] = new CURLFile($f['tmp_name'], $f['type'], $f['name']);
            }
        }
        $opts[CURLOPT_POSTFIELDS] = $fields;
    } else {
        $opts[CURLOPT_POSTFIELDS] = file_get_contents('php://input');
        if ($ctype !== '') $reqHeaders[] = "Content-Type: $ctype";
    }
}
$opts[CURLOPT_HTTPHEADER] = $reqHeaders;
curl_setopt_array($ch, $opts);

$body = curl_exec($ch);
if ($body === false) {
    $err = curl_errno($ch) === CURLE_ABORTED_BY_CALLBACK ? 'Die Datei ist zu groß.' : curl_error($ch);
    fail(502, "Verbindung fehlgeschlagen: $err");
}
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

// ---- Antwort zurückgeben ---------------------------------------------------
http_response_code($status ?: 200);

if ($status >= 300 && $status < 400 && !empty($respHeaders['location'][0])) {
    header('Location: ' . proxy_url(resolve_url($target, $respHeaders['location'][0])));
    exit;
}

foreach (['content-type', 'content-disposition', 'content-range', 'accept-ranges', 'cache-control', 'expires', 'last-modified'] as $h) {
    if (!empty($respHeaders[$h])) header(ucwords($h, '-') . ': ' . end($respHeaders[$h]));
}

$ctype = strtolower($respHeaders['content-type'][0] ?? '');
if (strpos($ctype, 'text/html') !== false || strpos($ctype, 'application/xhtml') !== false) {
    $body = rewrite_html($body, $target, $ctype);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
}
echo $body;

// ---- HTML anpassen ---------------------------------------------------------
function rewrite_html(string $html, string $url, string $ctype): string
{
    global $token;

    // Zeichensatz nach UTF-8 umwandeln
    $charset = '';
    if (preg_match('/charset=["\']?([\w\-]+)/i', $ctype, $m)) $charset = $m[1];
    elseif (preg_match('/<meta[^>]+charset=["\']?([\w\-]+)/i', substr($html, 0, 4096), $m)) $charset = $m[1];
    if ($charset && strtoupper($charset) !== 'UTF-8' && function_exists('mb_convert_encoding')) {
        $conv = @mb_convert_encoding($html, 'UTF-8', $charset);
        if ($conv !== false) $html = $conv;
    }

    // Eigenes <base> bestimmen (ein vorhandenes <base href> der Seite berücksichtigen)
    $base = $url;
    if (preg_match('/<base\b[^>]*\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $m)) {
        $base = resolve_url($url, html_entity_decode($m[2] . ($m[3] ?? '') . ($m[4] ?? ''), ENT_QUOTES | ENT_HTML5));
    }

    // Skript- und Style-Inhalte nicht anfassen
    @ini_set('pcre.backtrack_limit', '10000000');
    $chunks = preg_split('#(<(script|style|textarea)\b[^>]*>.*?</\2\s*>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($chunks === false) $chunks = [$html];
    $out = '';
    for ($i = 0; $i < count($chunks); $i++) {
        $c = $chunks[$i];
        if ($i % 3 === 2) continue;          // Gruppe 2 (Tag-Name) überspringen
        if ($i % 3 === 1) { $out .= $c; continue; } // Skript/Style unverändert
        // <base> und CSP-Meta-Tags entfernen
        $c = preg_replace('/<base\b[^>]*>/i', '', $c);
        $c = preg_replace('/<meta\b[^>]*http-equiv\s*=\s*["\']?content-security-policy[^>]*>/i', '', $c);
        // Links und eingebettete Frames über den Proxy leiten
        $c = preg_replace_callback('/<(a|area|iframe|frame)\b[^>]*>/i', function ($t) use ($base) {
            $tag = strtolower($t[1]);
            $attr = ($tag === 'iframe' || $tag === 'frame') ? 'src' : 'href';
            $s = preg_replace_callback('/(\s' . $attr . '\s*=\s*)("([^"]*)"|\'([^\']*)\'|([^\s>"\']+))/i', function ($a) use ($base) {
                $val = html_entity_decode(($a[3] ?? '') . ($a[4] ?? '') . ($a[5] ?? ''), ENT_QUOTES | ENT_HTML5);
                $v = trim($val);
                if ($v === '' || $v[0] === '#' || preg_match('#^(javascript|mailto|tel|data|blob|about):#i', $v)) return $a[0];
                $abs = resolve_url($base, $v);
                if (!preg_match('#^https?://#i', $abs)) return $a[0];
                return $a[1] . '"' . htmlspecialchars(proxy_url($abs), ENT_QUOTES, 'UTF-8') . '"';
            }, $t[0]);
            // target="_top"/"_parent" würden den ganzen Browser verlassen
            return preg_replace('/\starget\s*=\s*["\']?_(top|parent)["\']?/i', '', $s);
        }, $c);
        // <meta http-equiv="refresh" content="5; url=...">
        $c = preg_replace_callback('/(<meta\b[^>]*http-equiv\s*=\s*["\']?refresh[^>]*content\s*=\s*["\']\s*\d+\s*;\s*url\s*=\s*)([^"\'>]+)/i', function ($m) use ($base) {
            return $m[1] . htmlspecialchars(proxy_url(resolve_url($base, html_entity_decode(trim($m[2])))), ENT_QUOTES, 'UTF-8');
        }, $c);
        $out .= $c ?? $chunks[$i];
    }

    $config = json_encode([
        'url' => $url,
        'base' => $base,
        'proxy' => proxy_base(),
        'token' => $token,
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    $inject = '<base href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '">'
            . '<script>window.__PROXY__=' . $config . ';' . file_get_contents(__DIR__ . '/inject.js') . '</script>';

    if (preg_match('/<head\b[^>]*>/i', $out, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
    } elseif (preg_match('/<html\b[^>]*>/i', $out, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
    } else {
        $pos = 0;
        if (preg_match('/^\s*<!doctype[^>]*>/i', $out, $m)) $pos = strlen($m[0]);
    }
    return substr($out, 0, $pos) . $inject . substr($out, $pos);
}
