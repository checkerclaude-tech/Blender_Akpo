<?php
declare(strict_types=1);
// SEO autopilot feed for the Campaigns team: ranking updates, changes made to aerosub.co, weekly reports and approval requests.
//
// Two doors, kept apart like the rest of the agent platform:
//   /api/seo-agent/...  called only by the SEO autopilot with its own X-Agent-Token (scope 'seo'). No office session, no CSRF.
//   /api/seo/...        the office app (signed-in session + CSRF), gated by the Campaigns tab.
//
// Agent routes
//   POST seo-agent/entries            {kind, title, body?, url?, data?, ext_id?}  add one entry (ext_id makes a retry harmless)
//   GET  seo-agent/decisions?since=N  approval entries decided after decision id N (approved or rejected, with the comment)
//   POST seo-agent/entries/{id}/done  {note?}  the autopilot applied an approved change
//   GET  seo-agent/settings           competitors, daily run time, weekly report day (read at the start of every run)
// Office routes
//   GET  seo/entries?kind=&status=&limit=   newest first
//   GET  seo/summary                        counts for the header cards
//   POST seo/entries/{id}/decide            {decision:'approve'|'reject', comment?}
//   GET  seo/settings  |  PUT seo/settings  {competitors:[{name,url}], run_time:'HH:MM', report_day:'mon'..'sun'}
// Uses $method, $seg from the router.

const SEO_KINDS = ['ranking', 'change', 'approval', 'report'];

function seo_row(array $r): array {
    $r['id'] = (int)$r['id'];
    $r['data'] = $r['data'] !== null ? json_decode($r['data'], true) : null;
    return $r;
}

const SEO_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const SEO_DEFAULTS = ['competitors' => [], 'run_time' => '07:45', 'report_day' => 'mon', 'timezone' => 'Africa/Lagos'];

function seo_settings(): array {
    $s = SEO_DEFAULTS;
    foreach (all('SELECT k, v, updated_at FROM seo_settings') as $r) { if (array_key_exists($r['k'], $s)) $s[$r['k']] = json_decode($r['v'], true); }
    $s['updated_at'] = one('SELECT MAX(updated_at) t FROM seo_settings')['t'] ?? null;
    return $s;
}

/** Checks and tidies what the office app sends. Fails with a plain message the person can act on. */
function seo_clean_settings(array $b): array {
    $out = [];
    if (array_key_exists('competitors', $b)) {
        if (!is_array($b['competitors'])) fail('competitors must be a list');
        if (count($b['competitors']) > 25) fail('At most 25 competitors');
        $seen = []; $out['competitors'] = [];
        foreach ($b['competitors'] as $c) {
            $name = s(is_array($c) ? ($c['name'] ?? '') : $c, 80);
            $url = s(is_array($c) ? ($c['url'] ?? '') : '', 200);
            if ($name === '') continue;
            if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
            if ($url !== '' && !preg_match('#^https?://[a-z0-9.-]+\.[a-z]{2,}(/[^\s]*)?$#i', $url)) fail('That website does not look right: ' . $url);
            $key = strtolower($name); if (isset($seen[$key])) continue; $seen[$key] = 1;
            $out['competitors'][] = ['name' => $name, 'url' => $url];
        }
    }
    if (array_key_exists('run_time', $b)) {
        $t = (string)$b['run_time'];
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $t)) fail('Run time must look like 07:45');
        $out['run_time'] = $t;
    }
    if (array_key_exists('report_day', $b)) {
        if (!in_array($b['report_day'], SEO_DAYS, true)) fail('Pick a weekday for the report');
        $out['report_day'] = $b['report_day'];
    }
    return $out;
}

function seo_can_decide(array $u): bool {
    return in_array($u['role'] ?? '', ['super_admin', 'founder', 'manager'], true) && !tab_readonly($u, 'campaigns');
}

$what = $seg[1] ?? '';
$eid = isset($seg[2]) && ctype_digit($seg[2]) ? (int)$seg[2] : null;
$act = $seg[3] ?? null;

/* ---------------- the autopilot's door ---------------- */
if ($r === 'seo-agent') {
    require_once __DIR__ . '/agent_token.php';
    $tok = agent_authenticate('seo');

    if ($method === 'POST' && $what === 'entries' && $eid === null) {
        $b = body();
        $kind = (string)($b['kind'] ?? '');
        if (!in_array($kind, SEO_KINDS, true)) fail('kind must be one of: ' . implode(', ', SEO_KINDS));
        $title = s($b['title'] ?? '', 200);
        if ($title === '') fail('title required');
        $text = mb_substr((string)($b['body'] ?? ''), 0, 20000);
        $url = s($b['url'] ?? '', 500);
        if ($url !== '' && !preg_match('#^https?://[^\s]+$#i', $url)) fail('url must start with http(s)://');
        $data = isset($b['data']) ? json_encode($b['data']) : null;
        if ($data !== null && strlen($data) > 20000) fail('data too large');
        $ext = s($b['ext_id'] ?? '', 80);
        if ($ext !== '') {
            $prev = one('SELECT * FROM seo_entries WHERE ext_id = ?', [$ext]);
            if ($prev) out(['replayed' => true] + seo_row($prev));
        }
        q('INSERT INTO seo_entries (kind, title, body, url, data, status, ext_id, token_id) VALUES (?,?,?,?,?,?,?,?)',
          [$kind, $title, $text, $url ?: null, $data, $kind === 'approval' ? 'pending' : 'info', $ext ?: null, (int)$tok['id']]);
        out(seo_row(one('SELECT * FROM seo_entries WHERE id = ?', [(int)db()->lastInsertId()])), 201);
    }
    if ($method === 'GET' && $what === 'decisions') {
        $since = max(0, (int)($_GET['since'] ?? 0));
        out(array_map('seo_row', all("SELECT e.*, u.name AS decided_by_name FROM seo_entries e LEFT JOIN users u ON u.id = e.decided_by
            WHERE e.kind = 'approval' AND e.status IN ('approved','rejected') AND e.decision_seq > ? ORDER BY e.decision_seq LIMIT 100", [$since])));
    }
    if ($method === 'GET' && $what === 'settings') out(seo_settings());
    if ($method === 'POST' && $what === 'entries' && $eid && $act === 'done') {
        $e = one("SELECT * FROM seo_entries WHERE id = ? AND kind = 'approval'", [$eid]);
        if (!$e) fail('Not found', 404);
        if ($e['status'] !== 'approved') fail('Only an approved request can be marked done', 409);
        q("UPDATE seo_entries SET status = 'done', done_note = ?, done_at = NOW() WHERE id = ?", [s(body()['note'] ?? '', 500), $eid]);
        out(seo_row(one('SELECT * FROM seo_entries WHERE id = ?', [$eid])));
    }
    fail('Bad request', 404);
}

/* ---------------- the office app's door ---------------- */
$u = need_user();
if (!tab_access($u, 'campaigns')) fail('You do not have access to this section', 403);

if ($method === 'GET' && $what === 'entries') {
    $where = []; $p = [];
    $k = (string)($_GET['kind'] ?? '');
    if ($k !== '' && in_array($k, SEO_KINDS, true)) { $where[] = 'e.kind = ?'; $p[] = $k; }
    $st = (string)($_GET['status'] ?? '');
    if (in_array($st, ['pending', 'approved', 'rejected', 'done', 'info'], true)) { $where[] = 'e.status = ?'; $p[] = $st; }
    $lim = min(200, max(1, (int)($_GET['limit'] ?? 60)));
    $rows = all('SELECT e.*, u.name AS decided_by_name FROM seo_entries e LEFT JOIN users u ON u.id = e.decided_by'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY (e.status = 'pending') DESC, e.id DESC LIMIT $lim", $p);
    out(['can_decide' => seo_can_decide($u), 'items' => array_map('seo_row', $rows)]);
}
if ($method === 'GET' && $what === 'summary') {
    $c = one("SELECT SUM(kind='approval' AND status='pending') pending,
                     SUM(kind='change' AND created_at > NOW() - INTERVAL 7 DAY) changes7,
                     SUM(kind='ranking' AND created_at > NOW() - INTERVAL 7 DAY) rankings7,
                     MAX(created_at) last_at FROM seo_entries");
    out(['pending' => (int)$c['pending'], 'changes7' => (int)$c['changes7'], 'rankings7' => (int)$c['rankings7'], 'last_at' => $c['last_at']]);
}
if ($what === 'settings' && $method === 'GET') out(seo_settings() + ['can_edit' => seo_can_decide($u)]);
if ($what === 'settings' && $method === 'PUT') {
    if (!seo_can_decide($u)) fail('Only a manager, the Founder or the Super Admin can change SEO settings', 403);
    $clean = seo_clean_settings(body());
    foreach ($clean as $k => $v) q('REPLACE INTO seo_settings (k, v, updated_by) VALUES (?,?,?)', [$k, json_encode($v), (int)$u['id']]);
    log_act((int)$u['id'], 'seo_settings', implode(',', array_keys($clean)));
    out(seo_settings() + ['can_edit' => true]);
}
if ($method === 'POST' && $what === 'entries' && $eid && $act === 'decide') {
    if (!seo_can_decide($u)) fail('Only a manager, the Founder or the Super Admin can approve SEO changes', 403);
    $b = body();
    $d = (string)($b['decision'] ?? '');
    if (!in_array($d, ['approve', 'reject'], true)) fail('decision must be approve or reject');
    $comment = s($b['comment'] ?? '', 1000);
    if ($d === 'reject' && $comment === '') fail('Say briefly why, so the autopilot can do better next time');
    $pdo = db(); $pdo->beginTransaction();
    try {
        $e = one("SELECT * FROM seo_entries WHERE id = ? AND kind = 'approval' FOR UPDATE", [$eid]);
        if (!$e) { $pdo->rollBack(); fail('Not found', 404); }
        if ($e['status'] !== 'pending') { $pdo->rollBack(); fail('Already decided', 409); }
        $seq = (int)(one('SELECT COALESCE(MAX(decision_seq), 0) + 1 n FROM seo_entries')['n']);
        q('UPDATE seo_entries SET status = ?, decided_by = ?, decided_at = NOW(), decision_comment = ?, decision_seq = ? WHERE id = ?',
          [$d === 'approve' ? 'approved' : 'rejected', (int)$u['id'], $comment ?: null, $seq, $eid]);
        $pdo->commit();
    } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $ex; }
    log_act((int)$u['id'], 'seo_' . $d, 'entry ' . $eid);
    out(seo_row(one('SELECT e.*, u.name AS decided_by_name FROM seo_entries e LEFT JOIN users u ON u.id = e.decided_by WHERE e.id = ?', [$eid])));
}
fail('Bad request', 404);
