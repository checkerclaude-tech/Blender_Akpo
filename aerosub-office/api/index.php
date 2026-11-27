<?php
declare(strict_types=1);
require __DIR__ . '/lib/core.php';
const TASK_STATUSES = ['todo', 'in_progress', 'blocked', 'done', 'deferred', 'canceled', 'transferred'];

$method = $_SERVER['REQUEST_METHOD'];
$path = trim(preg_replace('#^.*?/api/?#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/');
$path = preg_replace('#^index\.php/?#', '', $path);
$seg = $path === '' ? [] : explode('/', $path);
$r = $seg[0] ?? '';
$id = isset($seg[1]) && ctype_digit($seg[1]) ? (int)$seg[1] : null;
$sub = $seg[2] ?? ($id === null ? ($seg[1] ?? null) : null);

// Only 'agent' (the laptop) skips the office session check: it authenticates with its own token (agent_token.php).
// agent-owner and agent-auth use the office session and this CSRF check like any other route.
if ($method !== 'GET' && $method !== 'OPTIONS' && !($r === 'login' || $r === 'invite' || $r === 'agent' || $r === 'agent-personal' || $r === 'seo-agent' || $r === 'csp-report' || ($r === 'exec' && ($seg[1] ?? '') === 'ingest') || $r === 'forgot' || $r === 'reset')) { cfg(); check_csrf(); }

// Sections the Root Admin can switch off per person are refused here as well as hidden in the menu.
$GATED = ['campaigns' => 'campaigns', 'mail' => 'mail', 'learn' => 'learn', 'courses' => 'learn', 'companies' => 'pipeline', 'contacts' => 'pipeline', 'vendors' => 'pipeline', 'pipeline' => 'pipeline', 'competitors' => 'competition', 'trade-events' => 'events',
          'snapshot' => 'team', 'documents' => 'documents', 'reports' => 'reports', 'share' => 'reports', 'inbox-auto' => 'campaigns', 'prospecting' => 'campaigns', 'mail-test' => 'campaigns', 'myfiles' => 'myfiles', 'seo' => 'campaigns', 'chat' => 'chat'];
if (isset($GATED[$r])) { $gu = me(); if ($gu && !tab_access($gu, $GATED[$r])) fail('You do not have access to this section', 403); }
// "Read only" (set by the Root Admin): the person may open and use the tab (look, search, print, take a course, play) but every change is refused here.
$WRITE_TAB = ['campaigns' => 'campaigns', 'mail' => 'mail', 'learn' => 'learn', 'courses' => 'learn', 'companies' => 'pipeline', 'contacts' => 'pipeline', 'vendors' => 'pipeline', 'pipeline' => 'pipeline', 'competitors' => 'competition', 'trade-events' => 'events', 'events' => 'schedule',
              'finance' => 'finance', 'catalog' => 'products', 'news' => 'news', 'research' => 'research', 'projects' => 'projects', 'reports' => 'reports', 'myfiles' => 'myfiles', 'games' => 'games', 'snapshot' => 'team', 'documents' => 'documents', 'exec' => 'executive', 'seo' => 'campaigns'];
if ($method !== 'GET' && $method !== 'OPTIONS' && isset($WRITE_TAB[$r])) { $wu = me();
    if ($wu && tab_readonly($wu, $WRITE_TAB[$r])) {
        $use = ($r === 'courses' && in_array($seg[2] ?? '', ['start', 'attempts'], true)) || ($r === 'games' && ($seg[1] ?? '') !== 'settings') || ($r === 'reports' && ($seg[1] ?? '') === 'weekly-xlsx') || ($r === 'finance' && ($seg[1] ?? '') === 'signature') || ($r === 'finance' && ($seg[3] ?? '') === 'approval' && ($seg[4] ?? '') === 'act');
        if (!$use) fail('Your access to ' . TAB_DEFS[$WRITE_TAB[$r]][0] . ' is read-only: you can open and use it but not change it', 403); } }
try {
switch ($r) {

case 'health': out(['ok' => true, 'time' => date('c')]);

// The browser posts here when the Content Security Policy would block something (see .htaccess). No login or request token is
// possible for a browser-sent report, so it only writes a short log line, capped so a flood cannot fill the log.
case 'csp-report':
    if ($method !== 'POST') fail('POST only', 405);
    cfg();
    $recent = one("SELECT COUNT(*) c FROM activity_log WHERE action='csp_violation' AND created_at > (NOW() - INTERVAL 10 MINUTE)");
    if ((int)$recent['c'] < 30) {
        $raw = json_decode(substr((string)file_get_contents('php://input'), 0, 4000), true);
        $rep = is_array($raw) ? ($raw['csp-report'] ?? $raw) : [];
        $clean = fn($k) => preg_replace('/[^ -~]/', '', mb_substr((string)($rep[$k] ?? ''), 0, 90));
        log_act(null, 'csp_violation', $clean('violated-directive') . ' | ' . $clean('blocked-uri') . ' | ' . $clean('document-uri'));
    }
    http_response_code(204); exit;

// ---------- AGENT PLATFORM (see office-app-changes/README.md) ----------
case 'agent':
    require __DIR__ . '/lib/agent.php';
    break;

// The Personal agent's laptop (its own token, scope 'personal'): it only moves encrypted text. See lib/agent_relay.php.
case 'agent-personal':
    require __DIR__ . '/lib/agent_relay.php';
    break;

case 'agent-tokens':
    require __DIR__ . '/lib/agent_admin.php';
    break;

case 'agent-owner':
    require __DIR__ . '/lib/agent_owner.php';
    break;

case 'agent-auth':
    require __DIR__ . '/lib/agent_auth.php';
    break;

case 'documents':
case 'research-requests':
case 'agent-ratings':
case 'agent-badges':
    require __DIR__ . '/lib/agent_team.php';
    break;


// ---------- AUTH ----------
case 'login':
    if ($method !== 'POST') fail('POST only', 405);
    $b = body(); $email = strtolower(s($b['email'] ?? '', 190)); $pass = (string)($b['password'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
    $n = one('SELECT COUNT(*) c FROM login_attempts WHERE (email=? OR ip=?) AND created_at > (NOW() - INTERVAL 15 MINUTE)', [$email, $ip]);
    if ($n['c'] >= 8) fail('Too many attempts. Try again in 15 minutes.', 429);
    $u = one('SELECT * FROM users WHERE email=? AND active=1', [$email]);
    if (!$u || !password_verify($pass, $u['password_hash'])) {
        q('INSERT INTO login_attempts (email,ip) VALUES (?,?)', [$email, $ip]);
        fail('Incorrect email or password', 401);
    }
    start_session(); session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id']; unset($_SESSION['csrf']); remember_forget(); remember_issue((int)$u['id']);
    q('UPDATE users SET last_seen_at=NOW() WHERE id=?', [$u['id']]);
    log_act((int)$u['id'], 'login');
    out(['user' => me(), 'csrf' => csrf_token()]);

// Forgotten password: e-mails a one-hour link to the account's address and tells the Super Admins, who can also set a temporary password.
// The reply is always the same, so it cannot be used to find out which addresses have an account.
case 'forgot':
    if ($method !== 'POST') fail('POST only', 405);
    require_once __DIR__ . '/lib/accounts.php';
    $email = strtolower(s(body()['email'] ?? '', 190)); $ip = 'rst:' . ($_SERVER['REMOTE_ADDR'] ?? '0');
    if ((int)one('SELECT COUNT(*) c FROM login_attempts WHERE ip=? AND created_at > (NOW() - INTERVAL 1 HOUR)', [$ip])['c'] >= 6) fail('Too many requests. Try again in an hour, or ask your administrator.', 429);
    q('INSERT INTO login_attempts (email,ip) VALUES (?,?)', ['reset:' . $email, $ip]);
    $generic = ['ok' => true, 'message' => 'If that email belongs to an account, a recovery link is on its way and your administrator has been told.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out($generic);
    $acc = one('SELECT id,name,email FROM users WHERE email=? AND active=1', [$email]); if (!$acc) out($generic);
    $tok = bin2hex(random_bytes(20));
    q('DELETE FROM password_resets WHERE user_id=? AND used_at IS NULL', [$acc['id']]);
    q('INSERT INTO password_resets (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 1 HOUR))', [$acc['id'], hash('sha256', $tok)]);
    require_once __DIR__ . '/lib/campcore.php';
    $link = camp_base() . '/#/reset/' . $tok;
    $txt = "Hello " . $acc['name'] . ",\n\nSomeone asked to reset the password for your Aerosub Office account. Open this link within one hour to choose a new password:\n\n$link\n\nIf this was not you, ignore this email: your password has not changed.\n";
    $html = '<p>Hello ' . htmlspecialchars($acc['name']) . ',</p><p>Someone asked to reset the password for your Aerosub Office account. Open this link within one hour to choose a new password:</p><p><a href="' . htmlspecialchars($link) . '">Choose a new password</a></p><p style="color:#666">If this was not you, ignore this email: your password has not changed.</p>';
    $sent = acct_send_mail($acc['email'], 'Reset your Aerosub Office password', $html, $txt);
    foreach (all("SELECT id FROM users WHERE role='super_admin' AND active=1") as $adm)
        notify((int)$adm['id'], 'password_reset', 'Password reset requested: ' . $acc['name'], ($sent ? 'A recovery link was emailed to them.' : 'The recovery email could not be sent.') . ' You can also set a temporary password: Admin → Edit.', '#/admin');
    log_act((int)$acc['id'], 'password_reset_requested', $sent ? 'email sent' : 'email failed');
    out($generic);

case 'reset':
    $tok = preg_replace('/[^a-f0-9]/', '', (string)($seg[1] ?? '')); $ip = 'rst:' . ($_SERVER['REMOTE_ADDR'] ?? '0');
    if ((int)one('SELECT COUNT(*) c FROM login_attempts WHERE ip=? AND created_at > (NOW() - INTERVAL 15 MINUTE)', [$ip])['c'] >= 25) fail('Too many attempts. Try again later.', 429);
    $pr = strlen($tok) === 40 ? one('SELECT r.*,u.name,u.email FROM password_resets r JOIN users u ON u.id=r.user_id WHERE r.token_hash=? AND u.active=1', [hash('sha256', $tok)]) : null;
    if (!$pr || $pr['used_at'] || strtotime($pr['expires_at']) < time()) { q('INSERT INTO login_attempts (email,ip) VALUES (?,?)', ['reset-token', $ip]); fail('This reset link has expired or was already used. Ask for a new one.', 410); }
    if ($method === 'GET') out(['email' => $pr['email'], 'name' => $pr['name']]);
    if ($method !== 'POST') fail('Not found', 404);
    $pw = (string)(body()['password'] ?? ''); if (strlen($pw) < 10) fail('Choose a password of at least 10 characters');
    q('UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $pr['user_id']]);
    q('UPDATE password_resets SET used_at=NOW() WHERE user_id=?', [$pr['user_id']]); remember_forget((int)$pr['user_id']);
    q('DELETE FROM login_attempts WHERE email=?', [strtolower($pr['email'])]);
    log_act((int)$pr['user_id'], 'password_reset_done');
    out(['ok' => true]);

case 'logout':
    start_session(); remember_forget(); session_destroy(); out(['ok' => true]);

case 'me':
    $u = me();
    if (!$u) out(['user' => null]);
    q('UPDATE users SET last_seen_at=NOW() WHERE id=?', [$u['id']]);
    try { require_once __DIR__ . '/lib/taskfiles.php'; meeting_reminders(); } catch (Throwable $e) {}
    $mc = false; try { $mc = (bool)one('SELECT 1 FROM mail_accounts WHERE user_id=?', [$u['id']]); } catch (Throwable $e) {}
    out(['user' => $u, 'csrf' => csrf_token(), 'mail' => $mc, 'unread' => (int)one('SELECT COUNT(*) c FROM notifications WHERE user_id=? AND read_at IS NULL', [$u['id']])['c']]);

case 'password':
    $u = need_user(); $b = body();
    $full = one('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
    if (!password_verify((string)($b['current'] ?? ''), $full['password_hash'])) fail('Current password is incorrect', 403);
    $new = (string)($b['new'] ?? '');
    if (strlen($new) < 10) fail('New password must be at least 10 characters');
    q('UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    remember_forget((int)$u['id']); remember_issue((int)$u['id']);   // other devices are signed out; this one stays
    log_act((int)$u['id'], 'password_changed');
    out(['ok' => true]);

// ---------- DEPARTMENTS ----------
case 'departments':
    $u = need_user();
    if ($method === 'GET' && !$id) {
        try { out(all('SELECT d.id,d.name,d.head_id,h.name AS head_name,(SELECT COUNT(*) FROM user_departments x WHERE x.department_id=d.id) AS member_count FROM departments d LEFT JOIN users h ON h.id=d.head_id ORDER BY d.name')); }
        catch (Throwable $e) { out(all('SELECT id,name FROM departments ORDER BY name')); } // before /upgrade.php has run
    }
    need_role(['super_admin']);
    if ($method === 'POST' && !$id) {
        $name = s(body()['name'] ?? '', 80); if ($name === '') fail('Name required');
        q('INSERT IGNORE INTO departments (name) VALUES (?)', [$name]); log_act((int)$u['id'], 'department_added', $name);
        out(['ok' => true]);
    }
    if ($method === 'DELETE' && $id && !$sub) { q('DELETE FROM departments WHERE id=?', [$id]); out(['ok' => true]); }
    $dep = $id ? one('SELECT * FROM departments WHERE id=?', [$id]) : null; if (!$dep) fail('Department not found', 404);
    if ($method === 'PATCH' && !$sub) { // set or clear the head of department, or rename it
        $b = body();
        if (array_key_exists('name', $b)) { $nm = s($b['name'], 80); if ($nm === '') fail('Name required'); q('UPDATE departments SET name=? WHERE id=?', [$nm, $id]); }
        if (array_key_exists('head_id', $b)) {
            $hid = (int)($b['head_id'] ?: 0);
            if ($hid) {
                $h = one('SELECT id,name,role FROM users WHERE id=? AND active=1', [$hid]); if (!$h) fail('Choose an active user');
                if ($h['role'] === 'founder') fail('Founder accounts are read-only, so they cannot head a department');
                q('INSERT IGNORE INTO user_departments (user_id,department_id) VALUES (?,?)', [$hid, $id]);
                q('UPDATE users SET department_id=? WHERE id=? AND department_id IS NULL', [$id, $hid]);
                if ((int)($dep['head_id'] ?? 0) !== $hid) notify($hid, 'dept_head', 'You are now head of ' . $dep['name'], 'You can see the daily reports of your department, comment on them and share them.', '#/reports');
            }
            q('UPDATE departments SET head_id=? WHERE id=?', [$hid ?: null, $id]);
            log_act((int)$u['id'], $hid ? 'department_head_set' : 'department_head_cleared', $dep['name']);
        }
        out(['ok' => true]);
    }
    if ($sub === 'members') { // add or remove people in a department
        $b = body(); $uidT = isset($seg[3]) && ctype_digit($seg[3]) ? (int)$seg[3] : 0;
        if ($method === 'POST') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($b['user_ids'] ?? [])))));
            if (!$ids) fail('Choose at least one person');
            foreach ($ids as $x) {
                $r = one('SELECT id,department_id FROM users WHERE id=? AND active=1', [$x]); if (!$r) continue;
                if (q('INSERT IGNORE INTO user_departments (user_id,department_id) VALUES (?,?)', [$x, $id])->rowCount() && $x !== (int)$u['id'])
                    notify($x, 'dept_added', 'You were added to ' . $dep['name'], '', '#/reports');
                if (!$r['department_id']) q('UPDATE users SET department_id=? WHERE id=?', [$id, $x]);
            }
            log_act((int)$u['id'], 'department_members_added', $dep['name']);
            out(['ok' => true]);
        }
        if ($method === 'DELETE' && $uidT) {
            q('DELETE FROM user_departments WHERE user_id=? AND department_id=?', [$uidT, $id]);
            $left = one('SELECT department_id FROM user_departments WHERE user_id=? ORDER BY department_id LIMIT 1', [$uidT]);
            q('UPDATE users SET department_id=? WHERE id=? AND (department_id=? OR department_id IS NULL)', [$left ? (int)$left['department_id'] : null, $uidT, $id]);
            if ((int)($dep['head_id'] ?? 0) === $uidT) q('UPDATE departments SET head_id=NULL WHERE id=?', [$id]);
            log_act((int)$u['id'], 'department_member_removed', $dep['name']);
            out(['ok' => true]);
        }
    }
    fail('Bad request', 404);

// ---------- USERS ----------
case 'users':
    $u = need_user();
    $admin = $u['role'] === 'super_admin';
    if ($method === 'GET' && !$id && !empty($_GET['archived'])) {
        need_role(['super_admin']); require_once __DIR__ . '/lib/accounts.php'; purge_archived_users();
        out(all('SELECT u.id,u.name,u.email,u.role,u.job_title,u.archived_at,DATE_ADD(u.archived_at, INTERVAL ' . ARCHIVE_DAYS . ' DAY) AS delete_on,GREATEST(0,DATEDIFF(DATE_ADD(u.archived_at, INTERVAL ' . ARCHIVE_DAYS . ' DAY), NOW())) AS days_left,a.name AS archived_by_name
                 FROM users u LEFT JOIN users a ON a.id=u.archived_by WHERE u.archived_at IS NOT NULL ORDER BY u.archived_at DESC'));
    }
    if ($method === 'GET' && !$id) {
        $rows = all('SELECT u.id,u.name,u.email,u.role,u.department_id,d.name AS department,u.job_title,u.active,u.last_seen_at
                     FROM users u LEFT JOIN departments d ON d.id=u.department_id WHERE u.archived_at IS NULL ORDER BY u.active DESC,u.name');
        $mem = []; try { foreach (all('SELECT user_id,department_id FROM user_departments') as $m) $mem[(int)$m['user_id']][] = (int)$m['department_id']; } catch (Throwable $e) {}
        foreach ($rows as &$x) { $ids = $mem[(int)$x['id']] ?? []; if ($x['department_id']) $ids[] = (int)$x['department_id']; $x['department_ids'] = array_values(array_unique($ids)); }
        unset($x);
        foreach ($rows as &$x) { if (!$admin && !in_array($u['role'], ['founder', 'manager'], true)) unset($x['email'], $x['last_seen_at']); }
        unset($x);
        out($rows);
    }
    need_role(['super_admin']); $b = body();
    $roles = ['super_admin', 'founder', 'manager', 'employee'];
    if ($method === 'POST' && !$id) {
        $email = strtolower(s($b['email'] ?? '', 190)); $name = s($b['name'] ?? '', 120); $pw = (string)($b['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') fail('Name and a valid email are required');
        if (strlen($pw) < 10) fail('Temporary password must be at least 10 characters');
        if ($ex = one('SELECT id,archived_at FROM users WHERE email=?', [$email])) fail($ex['archived_at'] ? 'That email belongs to an archived account. Restore it from Admin → Archived users.' : 'That email already exists');
        q('INSERT INTO users (name,email,password_hash,role,department_id,job_title,must_change_password) VALUES (?,?,?,?,?,?,1)',
          [$name, $email, password_hash($pw, PASSWORD_DEFAULT), in((string)($b['role'] ?? ''), $roles, 'employee'), ($b['department_id'] ?? null) ?: null, s($b['job_title'] ?? '', 120) ?: null]);
        $nid = (int)db()->lastInsertId(); sync_user_depts($nid, $b);
        log_act((int)$u['id'], 'user_created', $email);
        out(['ok' => true, 'id' => $nid], 201);
    }
    if (($method === 'DELETE' || $method === 'PATCH') && $id && !is_root($u) && (int)(one('SELECT is_root FROM users WHERE id=?', [$id])['is_root'] ?? 0)) fail('Only the Root Admin can change the Root Admin account', 403);
    if ($method === 'DELETE' && $id) { // archive: they lose access now; the account is kept for 60 days and can be restored
        $t = one('SELECT * FROM users WHERE id=? AND archived_at IS NULL', [$id]); if (!$t) fail('Not found', 404);
        if ($id === (int)$u['id']) fail('You cannot archive your own account');
        if ($t['role'] === 'super_admin' && (int)one("SELECT COUNT(*) c FROM users WHERE role='super_admin' AND active=1 AND archived_at IS NULL AND id<>?", [$id])['c'] === 0) fail('This is the only active Super Admin');
        q('UPDATE users SET active=0, archived_at=NOW(), archived_by=? WHERE id=?', [$u['id'], $id]);
        q('UPDATE departments SET head_id=NULL WHERE head_id=?', [$id]);
        $open = (int)one("SELECT COUNT(*) c FROM tasks WHERE assignee_id=? AND status IN ('todo','in_progress','blocked','deferred')", [$id])['c'];
        log_act((int)$u['id'], 'user_archived', $t['email']);
        out(['ok' => true, 'open_tasks' => $open, 'delete_on' => date('Y-m-d', strtotime('+60 days'))]);
    }
    if ($method === 'POST' && $id && $sub === 'restore') {
        $t = one('SELECT * FROM users WHERE id=? AND archived_at IS NOT NULL', [$id]); if (!$t) fail('Not found', 404);
        q('UPDATE users SET active=1, archived_at=NULL, archived_by=NULL WHERE id=?', [$id]); log_act((int)$u['id'], 'user_restored', $t['email']);
        out(['ok' => true]);
    }
    if ($method === 'PATCH' && $id) {
        $t = one('SELECT * FROM users WHERE id=? AND archived_at IS NULL', [$id]); if (!$t) fail('Not found', 404);
        $role = in((string)($b['role'] ?? $t['role']), $roles, $t['role']);
        $active = isset($b['active']) ? (int)(bool)$b['active'] : (int)$t['active'];
        if ($id === (int)$u['id'] && ($role !== 'super_admin' || !$active)) fail('You cannot demote or deactivate yourself');
        q('UPDATE users SET name=?,role=?,department_id=?,job_title=?,active=? WHERE id=?',
          [s($b['name'] ?? $t['name'], 120), $role, array_key_exists('department_id', $b) ? ($b['department_id'] ?: null) : $t['department_id'],
           array_key_exists('job_title', $b) ? (s($b['job_title'], 120) ?: null) : $t['job_title'], $active, $id]);
        if (!empty($b['password'])) {
            if (strlen((string)$b['password']) < 10) fail('Password must be at least 10 characters');
            q('UPDATE users SET password_hash=?, must_change_password=1 WHERE id=?', [password_hash((string)$b['password'], PASSWORD_DEFAULT), $id]);
        }
        if (array_key_exists('department_ids', $b)) sync_user_depts($id, $b);
        log_act((int)$u['id'], 'user_updated', $t['email']);
        out(['ok' => true]);
    }
    fail('Bad request', 404);

// ---------- TASKS ----------
case 'tasks':
    $u = need_user(); $uid = (int)$u['id'];
    $vis = function (array $u): array { // visibility SQL
        if (in_array($u['role'], ['super_admin', 'founder'], true)) return ['1=1', []];
        $sh = 'EXISTS(SELECT 1 FROM task_shares ts WHERE ts.task_id=t.id AND ts.user_id=?)' . (can_finance($u) ? " OR t.task_type='rfq'" : '');
        $md = managed_dept_ids($u);
        if ($md) { $mdl = implode(',', $md); return ['(t.assignee_id=? OR t.creator_id=? OR ' . $sh . ' OR t.department_id IN (' . $mdl . '))', [$u['id'], $u['id'], $u['id']]]; }
        return ['(t.assignee_id=? OR t.creator_id=? OR ' . $sh . ')', [$u['id'], $u['id'], $u['id']]];
    };
    $canEdit = function (array $t) use ($u): bool {
        if ($u['role'] === 'super_admin') return true;
        if ($u['role'] === 'founder') return false;
        if ((int)$t['creator_id'] === (int)$u['id']) return true;
        return $t['department_id'] && in_array((int)$t['department_id'], managed_dept_ids($u), true);
    };
    require_once __DIR__ . '/lib/taskfiles.php';
    $sel = 'SELECT t.*, (SELECT COUNT(*) FROM task_files tf WHERE tf.task_id=t.id) AS file_count, (SELECT COUNT(*) FROM task_shares tsc WHERE tsc.task_id=t.id) AS share_count, a.name AS assignee_name, c.name AS creator_name, d.name AS department, co.name AS company_name, ct.name AS contact_name
            FROM tasks t LEFT JOIN users a ON a.id=t.assignee_id JOIN users c ON c.id=t.creator_id LEFT JOIN departments d ON d.id=t.department_id
            LEFT JOIN companies co ON co.id=t.company_id LEFT JOIN contacts ct ON ct.id=t.contact_id';

    if ($method === 'GET' && !$id) {
        [$w, $p] = $vis($u); $where = [$w];
        foreach (['status', 'priority'] as $f) if (!empty($_GET[$f])) { $where[] = "t.$f=?"; $p[] = $_GET[$f]; }
        if (!empty($_GET['assignee'])) { $where[] = 't.assignee_id=?'; $p[] = $_GET['assignee'] === 'me' ? $uid : (int)$_GET['assignee']; }
        if (!empty($_GET['department'])) { $where[] = 't.department_id=?'; $p[] = (int)$_GET['department']; }
        if (!empty($_GET['company_id'])) { $where[] = 't.company_id=?'; $p[] = (int)$_GET['company_id']; }
        if (!empty($_GET['type'])) { $where[] = 't.task_type=?'; $p[] = $_GET['type']; }
        if (!empty($_GET['shared'])) { $where[] = 'EXISTS(SELECT 1 FROM task_shares tz WHERE tz.task_id=t.id AND tz.user_id=?)'; $p[] = $uid; }
        if (!empty($_GET['creator'])) { $where[] = 't.creator_id=? AND (t.assignee_id IS NULL OR t.assignee_id<>?)'; array_push($p, $uid, $uid); }   // tasks I gave to other people
        if (!empty($_GET['shared_by'])) { $where[] = 'EXISTS(SELECT 1 FROM task_shares tb WHERE tb.task_id=t.id AND tb.shared_by=?)'; $p[] = $uid; }   // tasks I shared with other people
        require_once __DIR__ . '/lib/workweek.php'; $wkStart = rp_week_start(date('Y-m-d')) . ' 00:00:00';   // tasks from earlier weeks that are still open carry over
        if (!empty($_GET['carried'])) { $where[] = "t.created_at < ? AND t.status NOT IN ('done','canceled','transferred')"; $p[] = $wkStart; }
        if (!empty($_GET['meetings'])) { $where[] = "t.task_type='meeting' AND (t.assignee_id=? OR t.creator_id=? OR EXISTS(SELECT 1 FROM task_shares tm WHERE tm.task_id=t.id AND tm.user_id=?))"; array_push($p, $uid, $uid, $uid); }
        $rowsT = all("$sel WHERE " . implode(' AND ', $where) . " ORDER BY t.status IN ('done','canceled','transferred'), t.due_date IS NULL, t.due_date, FIELD(t.priority,'urgent','high','medium','low') LIMIT 500", $p);   // open tasks first, so the limit never hides one
        foreach ($rowsT as &$rt) $rt['carried'] = !in_array($rt['status'], ['done', 'canceled', 'transferred'], true) && (string)$rt['created_at'] < $wkStart; unset($rt);
        out($rowsT);
    }
    if ($method === 'GET' && $id && !in_array($sub, ['files', 'share', 'defer', 'updates', 'report', 'transfer', 'ics', 'ics-links', 'flow', 'steps', 'step', 'subs'], true)) {
        [$w, $p] = $vis($u); $t = one("$sel WHERE t.id=? AND $w", array_merge([$id], $p)); if (!$t) fail('Not found', 404);
        $t['comments'] = all('SELECT c.id,c.body,c.created_at,u.name FROM task_comments c JOIN users u ON u.id=c.user_id WHERE c.task_id=? ORDER BY c.id', [$id]);
        $t['can_edit'] = $canEdit($t);
        $t['files'] = all('SELECT f.id,f.name,f.mime,f.size,f.created_at,f.user_id,u.name AS user_name FROM task_files f LEFT JOIN users u ON u.id=f.user_id WHERE f.task_id=? ORDER BY f.id', [$id]);
        $t['shares'] = all('SELECT s.user_id AS id,u.name FROM task_shares s JOIN users u ON u.id=s.user_id WHERE s.task_id=? ORDER BY u.name', [$id]);
        $t['updates'] = all('SELECT x.id,x.kind,x.data,x.created_at,u.name AS user_name FROM task_updates x LEFT JOIN users u ON u.id=x.user_id WHERE x.task_id=? ORDER BY x.id DESC LIMIT 100', [$id]);
        try { require_once __DIR__ . '/lib/flows.php'; $t += flow_task_extra($t, $uid); } catch (Throwable $e) { $t += ['subs' => [], 'flow_info' => null]; }   // sub-tasks and, for dependent tasks, the flow it belongs to
        out($t);
    }
    if ($method === 'POST' && !$id) {
        if ($u['role'] === 'founder') fail('Founder accounts are read-only', 403);
        $b = body(); if (!empty($b['flow']) && is_array($b['flow'])) { require_once __DIR__ . '/lib/flows.php'; flow_create($b, $u); }   // dependent task: a plan of steps
        $title = s($b['title'] ?? '', 200); if ($title === '') fail('Title required');
        $assignee = !empty($b['assignee_id']) ? (int)$b['assignee_id'] : $uid;
        if ($u['role'] === 'employee' && $assignee !== $uid && !manages_user($u, $assignee)) fail('Employees can only create tasks for themselves', 403);
        $at = one('SELECT id,department_id FROM users WHERE id=? AND active=1', [$assignee]); if (!$at) fail('Assignee not found');
        if ($u['role'] === 'manager' && $assignee !== $uid && !manages_user($u, $assignee)) fail('Managers can assign within their department', 403);
        $dept = !empty($b['department_id']) ? (int)$b['department_id'] : ($at['department_id'] ?: null);
        if (empty($b['department_id']) && $assignee !== $uid && ($u['head_of'] ?? [])) { // a department head giving a task: file it under the department they head
            $shared = array_values(array_intersect(user_dept_ids($assignee, (int)$at['department_id']), $u['head_of'])); if ($shared) $dept = $shared[0]; }
        $ttype = in((string)($b['task_type'] ?? ''), ['general', 'research', 'build', 'operations', 'field', 'rfq', 'meeting'], 'general');
        $st_at = $ttype === 'meeting' ? dt($b['starts_at'] ?? null) : null; $en_at = $ttype === 'meeting' ? dt($b['ends_at'] ?? null) : null; if ($ttype === 'meeting' && !$st_at) fail('Choose when the meeting starts');
        q('INSERT INTO tasks (title,description,status,priority,assignee_id,creator_id,department_id,due_date,company_id,contact_id,task_type,starts_at,ends_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$title, s($b['description'] ?? '', 5000) ?: null, in((string)($b['status'] ?? ''), TASK_STATUSES, 'todo'),
           in((string)($b['priority'] ?? ''), ['low', 'medium', 'high', 'urgent'], 'medium'), $assignee, $uid, $dept, d($b['due_date'] ?? null),
           !empty($b['company_id']) ? (int)$b['company_id'] : null, !empty($b['contact_id']) ? (int)$b['contact_id'] : null, $ttype, $st_at, $en_at]);
        $tid = (int)db()->lastInsertId();
        if ($assignee !== $uid) notify($assignee, 'task_assigned', 'New task: ' . $title, 'Assigned by ' . $u['name'], '#/tasks/' . $tid);
        require_once __DIR__ . '/lib/mentions.php'; mn_apply(['id' => $tid, 'title' => $title, 'assignee_id' => $assignee, 'creator_id' => $uid], $u, (string)($b['description'] ?? ''), '', 'task_new');   // @Person, @Department, @everyone
        log_act($uid, 'task_created', $title);
        if ($ttype === 'meeting') {   // invite the people chosen (a whole department, heads included) and send calendar invites
            require_once __DIR__ . '/lib/ics.php'; if (!empty($b['meeting_place'])) q('UPDATE tasks SET meeting_place=? WHERE id=?', [s($b['meeting_place'], 190), $tid]);
            $mt = ics_task($tid); if ($mt) { ics_invite_people($mt, (array)($b['attendees'] ?? []), $u); $sent = ics_send($mt, ics_people($tid), $u, 'invite'); log_act($uid, 'meeting_invited', $title . ' (' . count(ics_people($tid)) . ' people)'); out(['ok' => true, 'id' => $tid, 'invited' => count(ics_people($tid)), 'emailed' => $sent], 201); }
        }
        out(['ok' => true, 'id' => $tid], 201);
    }
    if ($id) {
        [$w, $p] = $vis($u); $t = one("$sel WHERE t.id=? AND $w", array_merge([$id], $p)); if (!$t) fail('Not found', 404);
        if (in_array($sub, ['flow', 'steps', 'step', 'subs'], true)) { require_once __DIR__ . '/lib/flows.php'; flow_route($t, $u, $method, $sub, $seg, $canEdit); }
        if (in_array($sub, ['files', 'share', 'defer', 'updates', 'report'], true)) { require __DIR__ . '/lib/taskx.php'; }
        if ($sub === 'transfer' && $method === 'POST' && in_array($seg[3] ?? '', ['accept', 'decline', 'withdraw'], true)) { require_once __DIR__ . '/lib/transfers.php'; tr_answer($t, $u, (string)$seg[3], body()); }
        if ($sub === 'transfer' && $method === 'POST') { require_once __DIR__ . '/lib/reports.php'; rp_transfer_task($t, $u, body(), $canEdit($t)); }
        if ($sub === 'comments' && $method === 'POST') {
            $c = s(body()['body'] ?? '', 3000); if ($c === '') fail('Comment is empty');
            q('INSERT INTO task_comments (task_id,user_id,body) VALUES (?,?,?)', [$id, $uid, $c]);
            require_once __DIR__ . '/lib/mentions.php'; $mnTold = mn_apply($t, $u, $c, '', 'comment');
            foreach (array_unique(array_filter([$t['assignee_id'], $t['creator_id']])) as $nid) if ((int)$nid !== $uid)
                notify((int)$nid, 'task_comment', $u['name'] . ' commented on: ' . $t['title'], mb_substr($c, 0, 140), '#/tasks/' . $id);
            out(['ok' => true], 201);
        }
        if ($sub === 'ics' || $sub === 'ics-links') {
            require_once __DIR__ . '/lib/ics.php'; $mt = ics_task($id); if (!$mt) fail('This is not a meeting', 400);
            if ($sub === 'ics-links') out(ics_urls($id, $uid) + ['place' => $mt['meeting_place']]);
            header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: attachment; filename="meeting-' . $id . '.ics"'); header('Cache-Control: no-store'); echo ics_for_task($mt, 'PUBLISH'); exit;
        }
        if ($method === 'PATCH') {
            $b = body(); $isAssignee = (int)$t['assignee_id'] === $uid; $full = $canEdit($t);
            if ($u['role'] === 'founder') fail('Founder accounts are read-only', 403);
            if (($t['transfer_state'] ?? '') === 'pending' && $isAssignee) fail('This task was offered to you. Accept or decline it first.', 409);
            $isMember = $isAssignee || (bool)one('SELECT 1 FROM task_shares WHERE task_id=? AND user_id=?', [$id, $uid]);   // everyone added to the task can update its status
            if (!$full && !$isMember) fail('Not allowed', 403);
            $status = in((string)($b['status'] ?? $t['status']), TASK_STATUSES, $t['status']);
            if ($status !== $t['status'] && !$full) {
                if (($t['kind'] ?? '') === 'flow') fail('This is the plan itself: it finishes by itself when every step is done', 403);
                if (($t['kind'] ?? '') === 'step' && in_array($status, ['in_progress', 'done'], true)) { require_once __DIR__ . '/lib/flows.php'; $wt = flow_waiting_on($id); if ($wt) fail('This step starts after: ' . implode(', ', array_column($wt, 'title')), 409); }
            }
            if ($status !== $t['status']) {
                if ($status === 'transferred') fail('Use Transfer to move a task to someone else', 400);
                if ($status === 'canceled' && !$full) fail('Only the person who gave the task, or a department head, can cancel it', 403);
                if ($t['status'] === 'transferred') fail('This task was transferred. Open the new copy instead', 400);
            }
            $new = ['status' => $status];
            if (array_key_exists('status_reason', $b)) $new['status_reason'] = s($b['status_reason'], 300) ?: null;
            elseif ($status !== $t['status']) $new['status_reason'] = null;
            if ($full) {
                $new += [
                    'title' => s($b['title'] ?? $t['title'], 200) ?: $t['title'],
                    'description' => array_key_exists('description', $b) ? (s($b['description'], 5000) ?: null) : $t['description'],
                    'priority' => in((string)($b['priority'] ?? $t['priority']), ['low', 'medium', 'high', 'urgent'], $t['priority']),
                    'assignee_id' => array_key_exists('assignee_id', $b) ? (($b['assignee_id'] ?: null)) : $t['assignee_id'],
                    'due_date' => array_key_exists('due_date', $b) ? d($b['due_date']) : $t['due_date'],
                    'company_id' => array_key_exists('company_id', $b) ? (($b['company_id'] ?: null)) : $t['company_id'],
                    'contact_id' => array_key_exists('contact_id', $b) ? (($b['contact_id'] ?: null)) : $t['contact_id'],
                ];
                if (array_key_exists('task_type', $b)) $new['task_type'] = in((string)$b['task_type'], ['general', 'research', 'build', 'operations', 'field', 'rfq', 'meeting'], $t['task_type']);
                if (array_key_exists('starts_at', $b)) { $new['starts_at'] = dt($b['starts_at']); $new['remind_sent'] = 0; }
                if (array_key_exists('ends_at', $b)) $new['ends_at'] = dt($b['ends_at']);
                if (array_key_exists('meeting_place', $b)) $new['meeting_place'] = s($b['meeting_place'], 190) ?: null;
                if ($u['role'] === 'employee' && $new['assignee_id'] && (int)$new['assignee_id'] !== $uid && !manages_user($u, (int)$new['assignee_id'])) fail('Employees can only keep tasks assigned to themselves', 403);
            }
            $sets = []; $vals = [];
            foreach ($new as $k => $v) { $sets[] = "$k=?"; $vals[] = $v; }
            $sets[] = 'completed_at=' . ($status === 'done' ? ($t['completed_at'] ? 'completed_at' : 'NOW()') : 'NULL');
            $vals[] = $id;
            q('UPDATE tasks SET ' . implode(',', $sets) . ' WHERE id=?', $vals);
            if ($full && array_key_exists('description', $b)) { require_once __DIR__ . '/lib/mentions.php'; mn_apply($t, $u, (string)($new['description'] ?? ''), (string)($t['description'] ?? ''), 'task'); }   // only people newly tagged are told
            if ($status !== $t['status']) {
                log_act($uid, 'task_' . $status, $t['title']);
                if (!empty($t['kind'])) { try { require_once __DIR__ . '/lib/flows.php'; flow_status_changed($t, $status, $u); } catch (Throwable $e) { error_log('flow: ' . $e->getMessage()); } }
                if ((int)$t['creator_id'] !== $uid) notify((int)$t['creator_id'], 'task_status', $u['name'] . ' marked "' . $t['title'] . '" ' . str_replace('_', ' ', $status), '', '#/tasks/' . $id);
            }
            if (isset($new['assignee_id']) && (int)$new['assignee_id'] && (int)$new['assignee_id'] !== (int)$t['assignee_id'] && (int)$new['assignee_id'] !== $uid)
                notify((int)$new['assignee_id'], 'task_assigned', 'Task assigned to you: ' . $t['title'], 'By ' . $u['name'], '#/tasks/' . $id);
            // meetings: new people are invited, and a change of time, place or title (or cancelling) goes out as an updated calendar invite
            $wasMeet = $t['task_type'] === 'meeting'; $isMeet = ($new['task_type'] ?? $t['task_type']) === 'meeting';
            if ($isMeet) { require_once __DIR__ . '/lib/ics.php'; $mt = ics_task($id);
                if ($mt) { $added = ics_invite_people($mt, (array)($b['attendees'] ?? []), $u); $cancel = $status === 'canceled' && $t['status'] !== 'canceled'; $moved = !$wasMeet || $mt['starts_at'] !== $t['starts_at'] || $mt['ends_at'] !== $t['ends_at'] || $mt['title'] !== $t['title'] || ($mt['meeting_place'] ?? null) !== ($t['meeting_place'] ?? null);
                    if ($cancel || $moved || $added) { if ($cancel || $moved) { q('UPDATE tasks SET ics_seq=ics_seq+1 WHERE id=?', [$id]); $mt['ics_seq'] = (int)$mt['ics_seq'] + 1; }
                        $to = ($moved || $cancel) ? ics_people($id) : $added; ics_send($mt, $to, $u, $cancel ? 'cancel' : (!$wasMeet ? 'invite' : ($moved ? 'update' : 'invite')));
                        foreach ($to as $r) if ((int)$r['id'] !== $uid && ($moved || $cancel)) notify((int)$r['id'], 'meeting_update', ($cancel ? 'Meeting cancelled: ' : 'Meeting changed: ') . $mt['title'], 'Starts ' . date('D j M, H:i', strtotime($mt['starts_at'])), '#/tasks/' . $id); } } }
            out(['ok' => true]);
        }
        if ($method === 'DELETE') { if (!$canEdit($t)) fail('Not allowed', 403);
            if ($t['task_type'] === 'meeting' && $t['starts_at'] && !in_array($t['status'], ['canceled', 'done', 'transferred'], true) && strtotime((string)$t['starts_at']) > time() - 3600) { require_once __DIR__ . '/lib/ics.php'; $mt = ics_task($id); if ($mt) { $mt['ics_seq'] = (int)$mt['ics_seq'] + 1; ics_send($mt, ics_people($id), $u, 'cancel'); } }   // tell everyone it is off
            require_once __DIR__ . '/lib/bulkdel.php'; bd_task_delete($t, $u); out(['ok' => true]); }   // everyone it was shared or assigned to is told and can keep a copy
    }
    fail('Bad request', 404);

// ---------- SCHEDULE (EVENTS) ----------
case 'events':
    $u = need_user(); $uid = (int)$u['id']; $super = $u['role'] === 'super_admin';
    if ($method === 'GET') {
        $from = dt($_GET['from'] ?? '') ?: date('Y-m-01 00:00:00'); $to = dt($_GET['to'] ?? '') ?: date('Y-m-t 23:59:59');
        $sql = 'SELECT e.*, c.name AS creator_name FROM events e JOIN users c ON c.id=e.creator_id
                WHERE e.starts_at <= ? AND e.ends_at >= ? AND (? = 1 OR e.creator_id=? OR e.scope=\'company\'
                  OR (e.scope=\'department\' AND e.department_id IN (' . dept_sql_list($u) . ')) OR EXISTS (SELECT 1 FROM event_attendees a WHERE a.event_id=e.id AND a.user_id=?))
                ORDER BY e.starts_at';
        $rows = all($sql, [$to, $from, $super ? 1 : 0, $uid, $uid]);
        foreach ($rows as &$e) $e['attendees'] = all('SELECT u.id,u.name FROM event_attendees a JOIN users u ON u.id=a.user_id WHERE a.event_id=?', [$e['id']]);
        unset($e);
        out($rows);
    }
    if ($method === 'POST' && !$id) {
        $b = body(); $title = s($b['title'] ?? '', 200); $st = dt($b['starts_at'] ?? ''); $en = dt($b['ends_at'] ?? '');
        if ($title === '' || !$st) fail('Title and start time required'); if (!$en || $en < $st) $en = $st;
        $scope = in((string)($b['scope'] ?? ''), ['private', 'department', 'company'], 'private');
        if ($scope === 'company' && !in_array($u['role'], ['super_admin', 'manager', 'founder'], true)) fail('Only managers and admins can publish company-wide events', 403);
        q('INSERT INTO events (title,description,kind,starts_at,ends_at,all_day,location,scope,department_id,creator_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
          [$title, s($b['description'] ?? '', 3000) ?: null, in((string)($b['kind'] ?? ''), ['meeting', 'deadline', 'travel', 'leave', 'other'], 'meeting'),
           $st, $en, !empty($b['all_day']) ? 1 : 0, s($b['location'] ?? '', 190) ?: null, $scope, $u['department_id'] ?: null, $uid]);
        $eid = (int)db()->lastInsertId();
        foreach (array_unique(array_map('intval', (array)($b['attendees'] ?? []))) as $aid) {
            if (!$aid || !one('SELECT id FROM users WHERE id=? AND active=1', [$aid])) continue;
            q('INSERT IGNORE INTO event_attendees (event_id,user_id) VALUES (?,?)', [$eid, $aid]);
            if ($aid !== $uid) notify($aid, 'event_invite', 'Invited: ' . $title, date('D j M, H:i', strtotime($st)), '#/schedule');
        }
        log_act($uid, 'event_created', $title);
        out(['ok' => true, 'id' => $eid], 201);
    }
    if ($id && $method === 'DELETE') {
        $e = one('SELECT * FROM events WHERE id=?', [$id]); if (!$e) fail('Not found', 404);
        if (!$super && (int)$e['creator_id'] !== $uid) fail('Not allowed', 403);
        q('DELETE FROM events WHERE id=?', [$id]); out(['ok' => true]);
    }
    fail('Bad request', 404);

// ---------- NOTIFICATIONS ----------
case 'notifications':
    $u = need_user(); $uid = (int)$u['id'];
    if ($method === 'GET') out(all('SELECT id,type,title,body,link,read_at,created_at FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 60', [$uid]));
    if ($method === 'POST' && $sub === 'read') { // POST /notifications/read  {id?}
        $nid = (int)(body()['id'] ?? 0);
        if ($nid) q('UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=?', [$nid, $uid]);
        else q('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL', [$uid]);
        out(['ok' => true]);
    }
    fail('Bad request', 404);

// ---------- FOUNDER SNAPSHOT ----------
case 'snapshot':
    need_role(['super_admin', 'founder', 'manager']); $u = me();
    $scope = $u['role'] === 'manager' ? ' AND (u.department_id IN (' . dept_sql_list($u) . ') OR u.id IN (SELECT user_id FROM user_departments WHERE department_id IN (' . dept_sql_list($u) . ')))' : '';
    $people = all("SELECT u.id,u.name,u.job_title,u.role,u.last_seen_at,d.name AS department,
        SUM(t.status IN ('todo','in_progress','blocked')) AS open_tasks,
        SUM(t.status IN ('todo','in_progress','blocked') AND t.due_date < CURDATE()) AS overdue,
        SUM(t.status='blocked') AS blocked,
        SUM(t.status='in_progress') AS in_progress,
        SUM(t.status='done' AND t.completed_at >= (NOW() - INTERVAL 7 DAY)) AS done_7d
        FROM users u LEFT JOIN departments d ON d.id=u.department_id LEFT JOIN tasks t ON t.assignee_id=u.id
        WHERE u.active=1 AND u.role IN ('employee','manager') $scope GROUP BY u.id ORDER BY overdue DESC, open_tasks DESC");
    foreach ($people as &$p) foreach (['open_tasks', 'overdue', 'blocked', 'in_progress', 'done_7d'] as $k) $p[$k] = (int)$p[$k];
    unset($p);
    $tot = ['open' => 0, 'overdue' => 0, 'blocked' => 0, 'done_7d' => 0];
    foreach ($people as $p) { $tot['open'] += $p['open_tasks']; $tot['overdue'] += $p['overdue']; $tot['blocked'] += $p['blocked']; $tot['done_7d'] += $p['done_7d']; }
    $byDept = all("SELECT COALESCE(d.name,'Unassigned') AS department, SUM(t.status IN ('todo','in_progress','blocked')) AS open_tasks, SUM(t.status='done') AS done_tasks
        FROM tasks t LEFT JOIN departments d ON d.id=t.department_id GROUP BY d.id ORDER BY open_tasks DESC");
    $recent = all('SELECT l.action,l.detail,l.created_at,u.name FROM activity_log l LEFT JOIN users u ON u.id=l.user_id WHERE l.action<>\'login\' ORDER BY l.id DESC LIMIT 25');
    $upcoming = all("SELECT t.id,t.title,t.due_date,t.priority,a.name AS assignee FROM tasks t LEFT JOIN users a ON a.id=t.assignee_id
        WHERE t.status IN ('todo','in_progress','blocked') AND t.due_date IS NOT NULL AND t.due_date <= (CURDATE() + INTERVAL 7 DAY) ORDER BY t.due_date LIMIT 15");
    out(['totals' => $tot, 'people' => $people, 'departments' => $byDept, 'recent' => $recent, 'upcoming' => $upcoming]);

// ---------- ACTIVITY ----------
case 'activity': // the logs belong to the Root Admin
    need_root();
    $w = ['1=1']; $p = [];
    if (!empty($_GET['user'])) { $w[] = 'l.user_id=?'; $p[] = (int)$_GET['user']; }
    if (!empty($_GET['action'])) { $w[] = 'l.action LIKE ?'; $p[] = '%' . s($_GET['action'], 40) . '%'; }
    if (!empty($_GET['q'])) { $w[] = '(l.detail LIKE ? OR l.action LIKE ? OR u.name LIKE ?)'; $qq = '%' . s($_GET['q'], 60) . '%'; array_push($p, $qq, $qq, $qq); }
    if ($d1 = d($_GET['from'] ?? null)) { $w[] = 'l.created_at >= ?'; $p[] = $d1 . ' 00:00:00'; }
    if ($d2 = d($_GET['to'] ?? null)) { $w[] = 'l.created_at <= ?'; $p[] = $d2 . ' 23:59:59'; }
    $lim = max(1, min(1000, (int)($_GET['limit'] ?? 200)));
    $logs = all('SELECT l.id,l.user_id,l.action,l.detail,l.created_at,u.name FROM activity_log l LEFT JOIN users u ON u.id=l.user_id WHERE ' . implode(' AND ', $w) . ' ORDER BY l.id DESC LIMIT ' . $lim, $p);
    require_once __DIR__ . '/lib/recycle.php'; bin_ensure();   // a deletion in the log points at its copy in the recycle bin, so it can be restored from there
    try { $bins = all('SELECT id,kind,title,deleted_at,restored_at FROM recycle_bin WHERE deleted_at > (NOW() - INTERVAL ' . BIN_DAYS . ' DAY) ORDER BY id DESC LIMIT 1500'); foreach ($logs as &$lg) { $k = BIN_ACTION[$lg['action']] ?? null; if (!$k) continue; foreach ($bins as $bn) { if ($bn['kind'] === $k && mb_substr($bn['title'], 0, 200) === mb_substr((string)$lg['detail'], 0, 200) && abs(strtotime($bn['deleted_at']) - strtotime($lg['created_at'])) <= 10) { $lg['bin_id'] = (int)$bn['id']; $lg['restored'] = $bn['restored_at'] !== null; break; } } } unset($lg); } catch (Throwable $e) {}
    out($logs);

// Who may open which tab: Root Admin only.   GET access  ·  GET access/{userId}  ·  PATCH access/{userId} {tabs:{performance:true,finance:false,mail:null}}
case 'access':
    $ru = need_root();
    if ($method === 'GET' && !$id) { $rows = all("SELECT u.id,u.name,u.email,u.role,u.is_root,(SELECT d.name FROM departments d WHERE d.id=u.department_id) AS department,(SELECT COUNT(*) FROM user_access a WHERE a.user_id=u.id) AS overrides FROM users u WHERE u.active=1 AND u.archived_at IS NULL ORDER BY u.is_root DESC,u.name"); out(['users' => $rows]); }
    if ($id) {
        $t = one('SELECT id,name,email,role,department_id FROM users WHERE id=? AND archived_at IS NULL', [$id]); if (!$t) fail('Not found', 404);
        $t['department_ids'] = user_dept_ids($id, (int)($t['department_id'] ?? 0)); $t['is_root'] = (int)(one('SELECT is_root FROM users WHERE id=?', [$id])['is_root'] ?? 0);
        if ($method === 'PATCH') {
            if ($t['is_root']) fail('The Root Admin can always open everything', 400); $b = body(); $in = (array)($b['tabs'] ?? []);
            foreach ($in as $k => $v) { if (!isset(TAB_DEFS[$k]) || !TAB_DEFS[$k][1]) continue;
                if ($v === null || $v === '' || $v === 'default') q('DELETE FROM user_access WHERE user_id=? AND tab=?', [$id, $k]);
                else q('INSERT INTO user_access (user_id,tab,allowed,readonly,set_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE allowed=VALUES(allowed), readonly=VALUES(readonly), set_by=VALUES(set_by)', [$id, $k, $v ? 1 : 0, $v === 'view' ? 1 : 0, $ru['id']]); }
            log_act((int)$ru['id'], 'access_changed', $t['email'] . ': ' . implode(', ', array_map(fn($k) => $k . '=' . (($in[$k] === null || $in[$k] === 'default') ? 'default' : ($in[$k] === 'view' ? 'read only' : ($in[$k] ? 'edit' : 'off'))), array_keys($in))));
            notify($id, 'access', 'Your access was updated', 'The Root Admin changed which sections you can open. Refresh the page to see the new menu.', '#/home');
            out(['ok' => true]);
        }
        $target = $t; $ov = tab_overrides($t); $rom = tab_ro_map($t); $rows = [];
        foreach (TAB_DEFS as $k => [$label, $grant]) { if (!$grant) continue; $def = tab_default($t, $k); if ($k === 'myfiles' && $t['role'] === 'super_admin') $def = false;
            $rows[] = ['key' => $k, 'label' => $label, 'default' => $def, 'override' => array_key_exists($k, $ov) ? (!empty($rom[$k]) ? 'view' : $ov[$k]) : null, 'effective' => tab_access($t, $k), 'read_only' => tab_access($t, $k) && !empty($rom[$k]), 'locked' => $k === 'myfiles' && $t['role'] === 'super_admin']; }
        out(['user' => ['id' => $id, 'name' => $t['name'], 'email' => $t['email'], 'role' => $t['role']], 'tabs' => $rows]);
    }
    fail('Bad request', 404);

case 'ics':
    require __DIR__ . '/lib/ics.php';   // personal calendar links: no sign-in (the calendar app cannot sign in); the link itself is the secret
    break;

case 'workweek':
    require __DIR__ . '/lib/workweek.php';
    break;

case 'push':
    require __DIR__ . '/lib/push.php';
    break;

case 'exec':
    require __DIR__ . '/lib/exec.php';
    break;

case 'myfiles':
    require __DIR__ . '/lib/myfiles.php';
    break;

case 'reports':
    require __DIR__ . '/lib/reports.php';
    break;

// Team chat (one-to-one and groups)
case 'chat':
    require __DIR__ . '/lib/chat.php';
    break;

// The floating assistant (Claude)
case 'assistant':
    require __DIR__ . '/lib/assistant.php';
    break;

// The task horn (blow a horn at the person who has your task)
case 'horn':
    require __DIR__ . '/lib/horn.php';
    break;

// SEO autopilot feed (Campaigns): the office app on 'seo', the autopilot with its own token (scope 'seo') on 'seo-agent'.
case 'seo':
case 'seo-agent':
    require __DIR__ . '/lib/seo.php';
    break;

case 'invites':
case 'invite':
    require __DIR__ . '/lib/invite.php';
    break;

case 'finance':
case 'catalog':
case 'news':
    require __DIR__ . '/lib/finance.php';
    break;

case 'research':
case 'projects':
    require_once __DIR__ . '/lib/taskfiles.php';
    require __DIR__ . '/lib/taskx.php';
    break;

case 'competitors':
case 'trade-events':
    require __DIR__ . '/lib/market.php';
    break;

case 'learn':
    require __DIR__ . '/lib/learn.php';
    break;

case 'courses':
    require __DIR__ . '/lib/courses.php';
    break;

case 'inbox-auto':
    require __DIR__ . '/lib/inboxauto.php';
    break;

case 'prospecting':
    require __DIR__ . '/lib/prospect.php';
    break;

case 'mail-test':
    require __DIR__ . '/lib/mailtest.php';
    break;

case 'share':
    require __DIR__ . '/lib/sharemsg.php';
    break;

case 'games':
    require __DIR__ . '/lib/games.php';
    break;

case 'campaigns':
    require __DIR__ . '/lib/campaigns.php';
    break;

case 'avatar':
    $u = need_user();
    if ($method === 'GET') {
        $row = $id ? one('SELECT mime,data FROM user_avatars WHERE user_id=?', [$id]) : null;
        if (!$row) { http_response_code(404); exit; }
        header('Content-Type: ' . $row['mime']); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=86400'); header('Content-Length: ' . strlen($row['data'])); echo $row['data']; exit;
    }
    if ($method === 'DELETE') { q('DELETE FROM user_avatars WHERE user_id=?', [$u['id']]); out(['ok' => true]); }
    if ($method === 'POST') {
        $b = body(); $d = (string)($b['data'] ?? '');
        if (!preg_match('~^data:image/(jpeg|png);base64,([A-Za-z0-9+/=\r\n]+)$~', $d, $mm)) fail('Choose a JPG or PNG picture.');
        $bin = base64_decode($mm[2], true); if ($bin === false || strlen($bin) > 200000) fail('That picture is too large. Try a smaller one.');
        $info = @getimagesizefromstring($bin); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] > 800 || $info[1] > 800) fail('That picture could not be read.');
        $mime = $info[2] === IMAGETYPE_PNG ? 'image/png' : 'image/jpeg';
        q('INSERT INTO user_avatars (user_id,mime,data) VALUES (?,?,?) ON DUPLICATE KEY UPDATE mime=VALUES(mime),data=VALUES(data),updated_at=NOW()', [$u['id'], $mime, $bin]);
        out(['ok' => true, 'av' => time()]);
    }
    fail('Bad request', 405);

case 'mail':
    require __DIR__ . '/lib/mailapi.php';
    break;

case 'companies': case 'contacts': case 'pipeline':
    require __DIR__ . '/lib/pipeline.php';
    break;

case 'recycle':   // deleted items an administrator can bring back
    require __DIR__ . '/lib/recycle.php';
    break;

case 'preview':   // Word / Excel / PowerPoint as readable text
    require __DIR__ . '/lib/preview.php';
    break;

case 'bulk':   // delete one or many; keep a copy of a deleted task
    require __DIR__ . '/lib/bulkdel.php';
    break;

case 'vendors':   // vendors and partners in the Pipeline
    require __DIR__ . '/lib/vendorcrm.php';
    break;

default:
    fail('Not found', 404);
}
} catch (PDOException $e) {
    error_log('Aerosub Office DB error: ' . $e->getMessage());
    if (preg_match('/Unknown column|doesn.t exist/i', $e->getMessage())) fail('Database needs an upgrade: sign in as Super Admin and open /upgrade.php', 500);
    fail('Database error', 500);
}
