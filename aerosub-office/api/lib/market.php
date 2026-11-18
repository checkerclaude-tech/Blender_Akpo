<?php
declare(strict_types=1);
// Routes: /api/competitors/*  and  /api/trade-events/*
$u = need_user(); $uid = (int)$u['id']; $role = $u['role']; $b = $method === 'GET' ? [] : body();
$mgr = in_array($role, ['super_admin', 'manager'], true); $ro = $role === 'founder';
$rid = isset($seg[1]) && ctype_digit($seg[1]) ? (int)$seg[1] : 0; $sub = $rid ? ($seg[2] ?? '') : ($seg[1] ?? ''); $sid = isset($seg[3]) && ctype_digit($seg[3]) ? (int)$seg[3] : 0;
function mk_s($v, int $max = 2000): ?string { $v = trim((string)($v ?? '')); return $v === '' ? null : mb_substr($v, 0, $max); }
function mk_url($v): ?string { $v = trim((string)($v ?? '')); if ($v === '') return null; if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v; return filter_var($v, FILTER_VALIDATE_URL) ? mb_substr($v, 0, 500) : null; }
function mk_d($v): ?string { $v = trim((string)($v ?? '')); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; }
function mk_n($v): ?float { return ($v === null || $v === '' || !is_numeric($v)) ? null : round((float)$v, 2); }
function mk_write(bool $ok): void { if (!$ok) fail('You do not have permission to do that', 403); }

if ($seg[0] === 'competitors') {
    if (!$rid && $method === 'GET') {
        $rows = all('SELECT c.*, (SELECT COUNT(*) FROM competitor_intel i WHERE i.competitor_id=c.id) AS intel_count, (SELECT MAX(created_at) FROM competitor_intel i WHERE i.competitor_id=c.id) AS last_intel FROM competitors c ORDER BY FIELD(c.threat,"high","medium","low"), c.name');
        out(['competitors' => $rows, 'can_edit' => $mgr, 'can_note' => !$ro]);
    }
    $fields = function (array $b): array { $thr = in_array($b['threat'] ?? '', ['low', 'medium', 'high'], true) ? $b['threat'] : 'medium';
        $name = mk_s($b['name'] ?? '', 160); if (!$name) fail('Name is required');
        return [$name, mk_url($b['website'] ?? ''), mk_s($b['category'] ?? '', 80), $thr, mk_s($b['offering'] ?? ''), mk_s($b['pricing'] ?? ''), mk_s($b['strengths'] ?? ''), mk_s($b['weaknesses'] ?? ''), mk_s($b['our_edge'] ?? '')]; };
    if (!$rid && $method === 'POST') { mk_write($mgr); $f = $fields($b);
        q('INSERT INTO competitors (name,website,category,threat,offering,pricing,strengths,weaknesses,our_edge,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)', array_merge($f, [$uid]));
        out(['id' => (int)db()->lastInsertId()], 201); }
    if (!$rid) fail('Not found', 404);
    $c = one('SELECT * FROM competitors WHERE id=?', [$rid]); if (!$c) fail('Not found', 404);
    if ($sub === '' && $method === 'GET') {
        $c['intel'] = all('SELECT i.*, u.name AS user_name, co.name AS company_name FROM competitor_intel i LEFT JOIN users u ON u.id=i.user_id LEFT JOIN companies co ON co.id=i.company_id WHERE i.competitor_id=? ORDER BY i.created_at DESC, i.id DESC', [$rid]);
        $c['can_edit'] = $mgr; $c['can_note'] = !$ro; out($c);
    }
    if ($sub === '' && $method === 'PATCH') { mk_write($mgr); $f = $fields($b + $c);
        q('UPDATE competitors SET name=?,website=?,category=?,threat=?,offering=?,pricing=?,strengths=?,weaknesses=?,our_edge=? WHERE id=?', array_merge($f, [$rid])); out(['ok' => true]); }
    if ($sub === '' && $method === 'DELETE') { mk_write($mgr); q('DELETE FROM competitors WHERE id=?', [$rid]); out(['ok' => true]); }
    if ($sub === 'intel' && $method === 'POST') { mk_write(!$ro); $n = mk_s($b['note'] ?? '', 4000); if (!$n) fail('Write a note first');
        $kind = in_array($b['kind'] ?? '', ['note', 'pricing', 'product', 'win', 'loss', 'news'], true) ? $b['kind'] : 'note';
        $co = !empty($b['company_id']) && one('SELECT id FROM companies WHERE id=?', [(int)$b['company_id']]) ? (int)$b['company_id'] : null;
        q('INSERT INTO competitor_intel (competitor_id,user_id,kind,note,source_url,company_id) VALUES (?,?,?,?,?,?)', [$rid, $uid, $kind, $n, mk_url($b['source_url'] ?? ''), $co]);
        $nid = (int)db()->lastInsertId(); q('UPDATE competitors SET updated_at=NOW() WHERE id=?', [$rid]); out(['id' => $nid], 201); }
    if ($sub === 'intel' && $sid && $method === 'DELETE') { $i = one('SELECT * FROM competitor_intel WHERE id=? AND competitor_id=?', [$sid, $rid]); if (!$i) fail('Not found', 404);
        mk_write($mgr || (int)$i['user_id'] === $uid); q('DELETE FROM competitor_intel WHERE id=?', [$sid]); out(['ok' => true]); }
    fail('Not found', 404);
}

// ---- trade events ----
$people = fn(int $id) => all('SELECT u.id,u.name FROM trade_event_people p JOIN users u ON u.id=p.user_id WHERE p.event_id=? ORDER BY u.name', [$id]);
if (!$rid && $method === 'GET') {
    $rows = all('SELECT e.*, (SELECT COUNT(*) FROM trade_event_leads l WHERE l.event_id=e.id) AS lead_count, (SELECT COUNT(*) FROM trade_event_leads l WHERE l.event_id=e.id AND l.followed_up=0) AS open_leads,
        (SELECT GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ", ") FROM trade_event_people p JOIN users u ON u.id=p.user_id WHERE p.event_id=e.id) AS people_names,
        EXISTS(SELECT 1 FROM trade_event_people p WHERE p.event_id=e.id AND p.user_id=?) AS i_attend FROM trade_events e ORDER BY e.starts_on DESC', [$uid]);
    out(['events' => $rows, 'can_edit' => $mgr]);
}
$efields = function (array $b): array { $st = in_array($b['status'] ?? '', ['considering', 'registered', 'attending', 'done', 'skipped'], true) ? $b['status'] : 'considering';
    $name = mk_s($b['name'] ?? '', 200); if (!$name) fail('Name is required'); $s = mk_d($b['starts_on'] ?? ''); if (!$s) fail('Start date is required'); $e = mk_d($b['ends_on'] ?? ''); if ($e && $e < $s) $e = $s;
    return [$name, mk_s($b['kind'] ?? 'trade show', 40) ?: 'trade show', mk_s($b['location'] ?? '', 200), mk_url($b['url'] ?? ''), $s, $e, $st, mk_n($b['budget'] ?? null), mk_n($b['actual_cost'] ?? null), mk_s($b['goals'] ?? ''), mk_s($b['notes'] ?? ''), mk_s($b['recap'] ?? '')]; };
$setPeople = function (int $id, $ids) { if (!is_array($ids)) return; q('DELETE FROM trade_event_people WHERE event_id=?', [$id]);
    foreach (array_unique(array_map('intval', $ids)) as $x) if (one('SELECT id FROM users WHERE id=? AND active=1', [$x])) q('INSERT IGNORE INTO trade_event_people (event_id,user_id) VALUES (?,?)', [$id, $x]); };
if (!$rid && $method === 'POST') { mk_write($mgr); $f = $efields($b);
    q('INSERT INTO trade_events (name,kind,location,url,starts_on,ends_on,status,budget,actual_cost,goals,notes,recap,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($f, [$uid]));
    $id = (int)db()->lastInsertId(); $setPeople($id, $b['people'] ?? []);
    foreach ($people($id) as $p) if ((int)$p['id'] !== $uid) notify((int)$p['id'], 'event_assigned', 'You are going to: ' . $f[0], $f[4] . ($f[2] ? ' · ' . $f[2] : ''), '#/events/' . $id);
    out(['id' => $id], 201); }
if (!$rid) fail('Not found', 404);
$e = one('SELECT * FROM trade_events WHERE id=?', [$rid]); if (!$e) fail('Not found', 404);
$attends = (bool)one('SELECT 1 FROM trade_event_people WHERE event_id=? AND user_id=?', [$rid, $uid]);
if ($sub === '' && $method === 'GET') { $e['people'] = $people($rid); $e['leads'] = all('SELECT l.*, u.name AS user_name FROM trade_event_leads l LEFT JOIN users u ON u.id=l.user_id WHERE l.event_id=? ORDER BY l.followed_up, l.id DESC', [$rid]);
    $e['can_edit'] = $mgr; $e['can_lead'] = !$ro && ($mgr || $attends); out($e); }
if ($sub === '' && $method === 'PATCH') { mk_write($mgr); $f = $efields($b + $e);
    q('UPDATE trade_events SET name=?,kind=?,location=?,url=?,starts_on=?,ends_on=?,status=?,budget=?,actual_cost=?,goals=?,notes=?,recap=? WHERE id=?', array_merge($f, [$rid]));
    if (array_key_exists('people', $b)) { $before = array_column($people($rid), 'id'); $setPeople($rid, $b['people']);
        foreach ($people($rid) as $p) if (!in_array($p['id'], $before) && (int)$p['id'] !== $uid) notify((int)$p['id'], 'event_assigned', 'You are going to: ' . $f[0], $f[4] . ($f[2] ? ' · ' . $f[2] : ''), '#/events/' . $rid); }
    out(['ok' => true]); }
if ($sub === '' && $method === 'DELETE') { mk_write($mgr); q('DELETE FROM trade_events WHERE id=?', [$rid]); out(['ok' => true]); }
if ($sub === 'leads' && $method === 'POST') { mk_write(!$ro && ($mgr || $attends)); $n = mk_s($b['name'] ?? '', 160); if (!$n) fail('Name is required');
    $em = mk_s($b['email'] ?? '', 190); if ($em && !filter_var($em, FILTER_VALIDATE_EMAIL)) fail('That email address does not look right');
    q('INSERT INTO trade_event_leads (event_id,name,company,email,phone,note,user_id) VALUES (?,?,?,?,?,?,?)', [$rid, $n, mk_s($b['company'] ?? '', 160), $em, mk_s($b['phone'] ?? '', 60), mk_s($b['note'] ?? '', 2000), $uid]);
    out(['id' => (int)db()->lastInsertId()], 201); }
if ($sub === 'leads' && $sid) { $l = one('SELECT * FROM trade_event_leads WHERE id=? AND event_id=?', [$sid, $rid]); if (!$l) fail('Not found', 404); mk_write(!$ro && ($mgr || (int)$l['user_id'] === $uid));
    if ($method === 'PATCH') { q('UPDATE trade_event_leads SET followed_up=? WHERE id=?', [!empty($b['followed_up']) ? 1 : 0, $sid]); out(['ok' => true]); }
    if ($method === 'DELETE') { q('DELETE FROM trade_event_leads WHERE id=?', [$sid]); out(['ok' => true]); } }
fail('Not found', 404);
