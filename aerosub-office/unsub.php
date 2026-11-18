<?php
// Public unsubscribe page. Works from the link in every marketing email and for one-click unsubscribe (POST).
declare(strict_types=1);
require __DIR__ . '/api/lib/core.php';
$t = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$c = preg_match('/^[a-f0-9]{32}$/', $t) ? one('SELECT * FROM camp_contacts WHERE token=?', [$t]) : null;
header('X-Robots-Tag: noindex'); header('Cache-Control: no-store'); header('Content-Type: text/html; charset=utf-8');
$oneClick = $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['List-Unsubscribe'] ?? '') === 'One-Click');
$confirm = $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['confirm'] ?? '') === '1');
$state = 'ask';
if (!$c) $state = 'bad';
elseif ($oneClick || $confirm) {
    q("INSERT INTO camp_suppress (email, reason) VALUES (?, 'unsubscribed') ON DUPLICATE KEY UPDATE reason=reason", [$c['email']]);
    q("UPDATE camp_contacts SET status='unsubscribed' WHERE email=?", [$c['email']]);
    $ek = (int)($_REQUEST['k'] ?? 0); $es = (int)($_REQUEST['s'] ?? 0);
    if (($ek || $es) && !one("SELECT 1 FROM camp_events WHERE kind='unsub' AND contact_id=? AND campaign_id=? AND step_id=?", [$c['id'], $ek, $es])) q("INSERT INTO camp_events (kind,campaign_id,step_id,contact_id) VALUES ('unsub',?,?,?)", [$ek, $es, $c['id']]);
    $state = 'done'; if ($oneClick) { http_response_code(200); echo 'OK'; exit; }
}
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unsubscribe</title>
<style>body{margin:0;font:16px/1.6 Arial,Helvetica,sans-serif;background:#eef2f8;color:#1f2937;display:grid;place-items:center;min-height:100vh;padding:16px}.c{background:#fff;border-radius:16px;padding:32px;max-width:440px;width:100%;box-shadow:0 8px 30px rgba(12,31,97,.1);text-align:center}h1{color:#0c1f61;font-size:22px;margin:0 0 8px}button{background:#00a3de;color:#fff;border:0;border-radius:999px;padding:13px 28px;font-size:15px;font-weight:600;cursor:pointer;margin-top:14px}.s{color:#6b7280;font-size:13px;margin-top:18px}</style></head><body><div class="c">
<?php if ($state === 'bad'): ?><h1>Link not recognised</h1><p>This unsubscribe link is invalid or has expired. If you keep getting emails you do not want, reply to one of them and we will remove you.</p>
<?php elseif ($state === 'done'): ?><h1>You are unsubscribed</h1><p><?= $e($c['email']) ?> will not receive any more marketing emails from Aerosub.</p>
<?php else: ?><h1>Unsubscribe?</h1><p>Stop sending marketing emails to <b><?= $e($c['email']) ?></b>?</p><form method="post"><input type="hidden" name="t" value="<?= $e($t) ?>"><input type="hidden" name="confirm" value="1"><input type="hidden" name="k" value="<?= (int)($_GET['k'] ?? 0) ?>"><input type="hidden" name="s" value="<?= (int)($_GET['s'] ?? 0) ?>"><button>Yes, unsubscribe me</button></form><p class="s">Changed your mind? Just close this page.</p>
<?php endif; ?></div></body></html>
