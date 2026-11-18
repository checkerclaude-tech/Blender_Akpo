<?php
// Public open/click tracking for marketing emails. Click links are signed, so this can never be used as an open redirect.
declare(strict_types=1);
require __DIR__ . '/api/lib/core.php';
require __DIR__ . '/api/lib/campcore.php';
$t = (string)($_GET['t'] ?? ''); $k = (int)($_GET['k'] ?? 0); $sp = (int)($_GET['s'] ?? 0); $e = (string)($_GET['e'] ?? '');
$c = preg_match('/^[a-f0-9]{32}$/', $t) ? one('SELECT id FROM camp_contacts WHERE token=?', [$t]) : null;
if ($e === 'o') {
    if ($c) q("INSERT INTO camp_events (kind,campaign_id,step_id,contact_id) VALUES ('open',?,?,?)", [$k, $sp, $c['id']]);
    header('Content-Type: image/gif'); header('Cache-Control: no-store, no-cache, must-revalidate'); header('Content-Length: 43');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'); exit;
}
if ($e === 'c') {
    $u = base64_decode(strtr((string)($_GET['u'] ?? ''), '-_', '+/'), true);
    if ($c && $u !== false && preg_match('~^https?://~i', $u) && hash_equals(camp_sig($t . '|' . $k . '|' . $sp . '|' . $u), (string)($_GET['g'] ?? ''))) {
        q("INSERT INTO camp_events (kind,campaign_id,step_id,contact_id,url) VALUES ('click',?,?,?,?)", [$k, $sp, $c['id'], mb_substr($u, 0, 500)]);
        header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('Location: ' . $u, true, 302); exit;
    }
}
http_response_code(404);
