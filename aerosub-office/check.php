<?php
declare(strict_types=1);
// Install check: open /check.php to see which version of the app is on this server (files and database). It shows no passwords or data.
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
$root = __DIR__; $rows = [];
$has = fn(string $f, string $needle): string => is_file("$root/$f") ? (str_contains((string)file_get_contents("$root/$f"), $needle) ? 'yes' : 'NO') : 'MISSING FILE';
$when = fn(string $f): string => is_file("$root/$f") ? date('Y-m-d H:i', (int)filemtime("$root/$f")) . ' · ' . number_format((int)filesize("$root/$f")) . ' bytes' : 'MISSING FILE';
preg_match('/data-build="([^"]*)"/', (string)@file_get_contents("$root/index.html"), $m); $build = $m[1] ?? '(none: old index.html)';
preg_match('/reports\.js\?v=(\d+)/', (string)@file_get_contents("$root/index.html"), $m2); preg_match("/aero-office-v(\d+)/", (string)@file_get_contents("$root/sw.js"), $m3);
$rows[] = ['Build in index.html', $build . '  (the newest is 20261127-3)']; $rows[] = ['reports.js version in index.html', ($m2[1] ?? '?') . '  (the newest is 17 or higher)']; $rows[] = ['Service worker version', 'v' . ($m3[1] ?? '?') . '  (the newest is v57)'];
$rows[] = ['assets/js/reports.js', $when('assets/js/reports.js') . ' · has the edit-request button: ' . $has('assets/js/reports.js', 'Request approval to edit')];
$rows[] = ['assets/js/reports.js: lock banner', $has('assets/js/reports.js', 'so it is locked')];
$rows[] = ['assets/js/reports.js: edit-requests box on the Weekly page', $has('assets/js/reports.js', 'Edit requests waiting for you')];
$rows[] = ['api/lib/reports.php', $when('api/lib/reports.php') . ' · has the edit-request routes: ' . $has('api/lib/reports.php', 'weekly-edit-request')];
$rows[] = ['assets/js/procure.js (Procurement menu)', $when('assets/js/procure.js')]; $rows[] = ['assets/js/preview.js', $when('assets/js/preview.js')]; $rows[] = ['api/lib/recycle.php', $when('api/lib/recycle.php')];
$rows[] = ['PHP', PHP_VERSION]; $rows[] = ['Folder', basename($root)];
try {
    require_once "$root/api/lib/core.php"; $db = db();
    $col = $db->query("SHOW COLUMNS FROM weekly_reports LIKE 'edit_open_until'")->fetch(); $tbl = $db->query("SHOW TABLES LIKE 'weekly_edit_requests'")->fetch(); $n = $tbl ? (int)$db->query('SELECT COUNT(*) FROM weekly_edit_requests')->fetchColumn() : 0;
    $rows[] = ['Database: weekly_reports.edit_open_until', $col ? 'yes' : 'NO (open /upgrade.php as Super Admin)']; $rows[] = ['Database: weekly_edit_requests table', $tbl ? 'yes · ' . $n . ' request(s) so far' : 'NO (open /upgrade.php as Super Admin)'];
    $sub = (int)$db->query("SELECT COUNT(*) FROM weekly_reports WHERE status='submitted'")->fetchColumn(); $rows[] = ['Submitted weekly reports in the database', (string)$sub];
} catch (Throwable $e) { $rows[] = ['Database', 'could not be checked: ' . htmlspecialchars(mb_substr($e->getMessage(), 0, 120))]; }
$bad = array_filter($rows, fn($r) => str_contains($r[1], 'NO') || str_contains($r[1], 'MISSING') || str_contains($r[1], 'old index'));
echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install check</title><style>body{font:15px/1.5 system-ui,sans-serif;max-width:860px;margin:30px auto;padding:0 16px;color:#111}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccd;padding:7px 10px;text-align:left;vertical-align:top}th{background:#eef}.ok{color:#0a7a3d}.bad{color:#b00020;font-weight:700}</style><h1>Aerosub Office: install check</h1>';
echo $bad ? '<p class="bad">Something is missing or out of date on this server (marked in red). Upload the latest zip again, then open /upgrade.php.</p>' : '<p class="ok">All files and tables of this version are in place. If a page still looks old, open the app and go to #/version, or press Ctrl+Shift+R.</p>';
echo '<table><tr><th>What</th><th>Result</th></tr>'; foreach ($rows as [$a, $b]) { $isBad = str_contains($b, 'NO') || str_contains($b, 'MISSING') || str_contains($b, 'old index'); echo '<tr><td>' . htmlspecialchars($a) . '</td><td class="' . ($isBad ? 'bad' : '') . '">' . htmlspecialchars($b) . '</td></tr>'; } echo '</table><p style="color:#667;font-size:13px">Server time ' . date('Y-m-d H:i:s') . '</p>';
