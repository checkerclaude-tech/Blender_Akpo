<?php
declare(strict_types=1);
// /api/invites (admin: list/create/revoke)  and  /api/invite/{token} (public: look up / complete sign-up)
require_once __DIR__ . '/campcore.php';
$roles = ['super_admin' => 'Super Admin', 'founder' => 'Founder', 'manager' => 'Manager', 'employee' => 'Employee'];
if ($seg[0] === 'invites') {
    $u = need_role(['super_admin']); $b = $method === 'GET' ? [] : body();
    if ($method === 'GET' && !isset($seg[1])) out(array_map(fn($r) => $r + ['status' => $r['used_at'] ? 'used' : (strtotime($r['expires_at']) < time() ? 'expired' : 'pending')],
        all('SELECT i.id,i.email,i.role,i.department_ids,i.job_title,i.created_at,i.expires_at,i.used_at,u.name AS created_by_name FROM invites i LEFT JOIN users u ON u.id=i.created_by ORDER BY i.id DESC LIMIT 50')));
    if ($method === 'POST') {
        $email = strtolower(s($b['email'] ?? '', 190)); if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address');
        if (one('SELECT id FROM users WHERE email=?', [$email])) fail('Someone with that email already has an account');
        $role = array_key_exists((string)($b['role'] ?? ''), $roles) ? $b['role'] : 'employee';
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($b['department_ids'] ?? [])))));
        if ($ids) $ids = array_map('intval', array_column(all('SELECT id FROM departments WHERE id IN (' . implode(',', $ids) . ')'), 'id'));
        q('DELETE FROM invites WHERE email=? AND used_at IS NULL', [$email]);
        $tok = bin2hex(random_bytes(20));
        q('INSERT INTO invites (token_hash,email,role,department_ids,job_title,created_by,expires_at) VALUES (?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 7 DAY))',
          [hash('sha256', $tok), $email, $role, implode(',', $ids), s($b['job_title'] ?? '', 120) ?: null, $u['id']]);
        log_act((int)$u['id'], 'invite_created', $email);
        out(['link' => camp_base() . '/#/join/' . $tok, 'email' => $email, 'expires_days' => 7], 201);
    }
    if ($method === 'DELETE' && isset($seg[1]) && ctype_digit($seg[1])) { q('DELETE FROM invites WHERE id=? AND used_at IS NULL', [(int)$seg[1]]); out(['ok' => true]); }
    fail('Not found', 404);
}
// public
$ip = $_SERVER['REMOTE_ADDR'] ?? '0'; $tok = preg_replace('/[^a-f0-9]/', '', (string)($seg[1] ?? ''));
$n = one('SELECT COUNT(*) c FROM login_attempts WHERE ip=? AND created_at > (NOW() - INTERVAL 15 MINUTE)', [$ip]); if ($n['c'] >= 20) fail('Too many attempts. Try again later.', 429);
$inv = strlen($tok) === 40 ? one('SELECT * FROM invites WHERE token_hash=?', [hash('sha256', $tok)]) : null;
if (!$inv || $inv['used_at'] || strtotime($inv['expires_at']) < time()) { q('INSERT INTO login_attempts (email,ip) VALUES (?,?)', ['invite', $ip]); fail('This invitation link is no longer valid. Ask your administrator for a new one.', 410); }
if ($method === 'GET') out(['email' => $inv['email'], 'role' => $roles[$inv['role']] ?? 'Employee']);
if ($method !== 'POST') fail('Not found', 404);
$b = body(); $name = s($b['name'] ?? '', 120); $pw = (string)($b['password'] ?? '');
if ($name === '') fail('Please enter your full name'); if (strlen($pw) < 10) fail('Choose a password of at least 10 characters');
if (one('SELECT id FROM users WHERE email=?', [$inv['email']])) fail('An account with this email already exists. Try signing in.');
q('INSERT INTO users (name,email,password_hash,role,job_title,must_change_password) VALUES (?,?,?,?,?,0)', [$name, $inv['email'], password_hash($pw, PASSWORD_DEFAULT), $inv['role'], $inv['job_title']]);
$nid = (int)db()->lastInsertId(); sync_user_depts($nid, ['department_ids' => array_filter(explode(',', (string)$inv['department_ids']))]);
q('UPDATE invites SET used_at=NOW() WHERE id=?', [$inv['id']]); log_act($nid, 'joined_by_invite', $inv['email']);
start_session(); session_regenerate_id(true); $_SESSION['uid'] = $nid; unset($_SESSION['csrf']); q('UPDATE users SET last_seen_at=NOW() WHERE id=?', [$nid]);
out(['user' => me(), 'csrf' => csrf_token()], 201);
