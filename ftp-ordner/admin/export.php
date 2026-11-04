<?php
// CSV-Export für Excel (Strichpunkt getrennt, UTF-8 mit BOM)
require __DIR__ . '/../lib/app.php';
require_admin();

$type = $_GET['type'] ?? '';
$rows = []; $head = []; $name = 'export';
switch ($type) {
    case 'history':
        $uid = (int)($_GET['user'] ?? 0); $text = trim((string)($_GET['q'] ?? ''));
        $where = []; $args = [];
        if ($uid) { $where[] = 'v.user_id = ?'; $args[] = $uid; }
        if ($text !== '') { $where[] = '(v.url LIKE ? OR v.title LIKE ?)'; $args[] = "%$text%"; $args[] = "%$text%"; }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $head = ['Zeit', 'Benutzer', 'Titel', 'Adresse', 'Website', 'Variante'];
        foreach (q("SELECT v.*, u.username FROM visits v LEFT JOIN users u ON u.id = v.user_id $w ORDER BY v.id DESC LIMIT 100000", $args) as $r)
            $rows[] = [date('d.m.Y H:i:s', $r['created_at']), $r['username'], $r['title'], $r['url'], $r['domain'], $r['client'] === 'app' ? 'App' : 'Online'];
        $name = 'verlauf';
        break;
    case 'searches':
        $range = (int)($_GET['range'] ?? 0);
        $from = $range > 0 ? strtotime('today') - ($range - 1) * 86400 : 0;
        $head = ['Zeit', 'Benutzer', 'Suchbegriff', 'Suchmaschine'];
        foreach (q('SELECT s.*, u.username FROM searches s LEFT JOIN users u ON u.id = s.user_id WHERE s.created_at >= ? ORDER BY s.id DESC LIMIT 100000', [$from]) as $r)
            $rows[] = [date('d.m.Y H:i:s', $r['created_at']), $r['username'], $r['query'], $r['engine']];
        $name = 'suchanfragen';
        break;
    case 'users':
        $head = ['Benutzer', 'Registriert', 'Zuletzt aktiv', 'Seitenaufrufe', 'Suchanfragen', 'Admin', 'Gesperrt'];
        foreach (q('SELECT u.*, (SELECT COUNT(*) FROM visits v WHERE v.user_id = u.id) AS visits, (SELECT COUNT(*) FROM searches s WHERE s.user_id = u.id) AS searches FROM users u ORDER BY u.username') as $r)
            $rows[] = [$r['username'], date('d.m.Y', $r['created_at']), $r['last_seen'] ? date('d.m.Y H:i', $r['last_seen']) : 'nie', $r['visits'], $r['searches'], $r['is_admin'] ? 'ja' : 'nein', $r['is_blocked'] ? 'ja' : 'nein'];
        $name = 'benutzer';
        break;
    default:
        http_response_code(400); exit('Unbekannter Export');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="schoolweb-' . $name . '-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
// Zellen, die mit = + - @ beginnen, würde Excel als Formel ausführen
$safe = fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
fputcsv($out, $head, ';');
foreach ($rows as $r) fputcsv($out, array_map($safe, $r), ';');
fclose($out);
