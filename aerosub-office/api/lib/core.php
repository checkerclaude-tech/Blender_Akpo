<?php
declare(strict_types=1);

function cfg(string $k = null) {
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/../config.php';
        if (!is_file($f)) { http_response_code(503); header('Content-Type: application/json'); echo json_encode(['error' => 'Not configured. Copy api/config.sample.php to api/config.php.']); exit; }
        $c = require $f;
        date_default_timezone_set($c['timezone'] ?? 'Africa/Lagos');
    }
    return $k === null ? $c : ($c[$k] ?? null);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // Keep SQL date maths aligned with PHP's timezone
    $pdo->exec("SET time_zone = '" . (new DateTime('now'))->format('P') . "'");
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function one(string $sql, array $p = []) { return q($sql, $p)->fetch() ?: null; }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }

function out($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }

function body(): array {
    static $b = null;
    if ($b === null) { $raw = file_get_contents('php://input'); $b = $raw ? (json_decode($raw, true) ?: []) : []; }
    return $b;
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_name('aeroffice');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 14));
    session_start();
}

function user_dept_ids(int $uid, int $primary = 0): array {
    $ids = [];
    try { foreach (all('SELECT department_id FROM user_departments WHERE user_id=?', [$uid]) as $r) $ids[] = (int)$r['department_id']; } catch (Throwable $e) {}
    if ($primary) $ids[] = $primary;
    return array_values(array_unique($ids));
}
function dept_sql_list(array $u): string { $ids = array_map('intval', $u['department_ids'] ?? []); return $ids ? implode(',', $ids) : '0'; }
function in_dept(array $u, string $name): bool {
    $ids = $u['department_ids'] ?? []; if (!$ids) return false;
    $r = one('SELECT 1 FROM departments WHERE LOWER(name)=LOWER(?) AND id IN (' . dept_sql_list($u) . ')', [$name]); return (bool)$r;
}
// Campaigns (email marketing): only Marketing-department members and the Super Admin.
// Departments this person heads (set by the Super Admin). Empty until /upgrade.php has added the column.
function head_dept_ids(int $uid): array {
    try { return array_map('intval', array_column(all('SELECT id FROM departments WHERE head_id=?', [$uid]), 'id')); } catch (Throwable $e) { return []; }
}
// Departments whose tasks and reports this person manages: a manager's own departments plus any department they head.
function managed_dept_ids(array $u): array {
    $ids = $u['head_of'] ?? [];
    if (($u['role'] ?? '') === 'manager') $ids = array_merge($ids, $u['department_ids'] ?? []);
    return array_values(array_unique(array_map('intval', $ids)));
}
// True when $uid belongs to a department this person manages.
function manages_user(array $u, int $uid): bool {
    $m = managed_dept_ids($u); if (!$m) return false;
    $r = one('SELECT department_id FROM users WHERE id=?', [$uid]);
    return (bool)array_intersect(user_dept_ids($uid, (int)($r['department_id'] ?? 0)), $m);
}
/* ---- Games: the Super Admin decides who sees them and when they are open ---- */
function games_settings(): array {
    static $s = null; if ($s !== null) return $s;
    $d = ['visibility' => 'none', 'mode' => 'always', 'start_time' => '12:00', 'end_time' => '14:00', 'days' => '1,2,3,4,5,6,7', 'games' => 'chess,ludo,scrabble,cards', 'preset' => 'always'];
    try { $s = one('SELECT * FROM game_settings WHERE id=1') ?: $d; } catch (Throwable $e) { $s = $d; } return $s;
}
function games_window_open(array $s): bool {
    if ($s['mode'] !== 'window') return true;
    if (!in_array((string)date('N'), explode(',', (string)$s['days']), true)) return false;
    $now = date('H:i'); $a = (string)$s['start_time']; $b = (string)$s['end_time'];
    return $a <= $b ? ($now >= $a && $now <= $b) : ($now >= $a || $now <= $b);
}
function games_window_text(array $s): string {
    if ($s['mode'] !== 'window') return 'Open all the time';
    $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun']; $days = array_map('intval', explode(',', (string)$s['days']));
    $dt = count($days) === 7 ? 'every day' : (count($days) === 5 && !array_diff([1, 2, 3, 4, 5], $days) ? 'Mon to Fri' : implode(', ', array_map(fn($d) => $names[$d] ?? '', $days)));
    return 'Open ' . $s['start_time'] . ' to ' . $s['end_time'] . ', ' . $dt;
}
/** visible: shows in the menu; open: may play right now. The Super Admin always sees and plays. */
function games_access(array $u): array {
    $s = games_settings(); $open = games_window_open($s); $text = games_window_text($s); $enabled = array_values(array_filter(explode(',', (string)$s['games'])));
    if ($u['role'] === 'super_admin') return ['visible' => true, 'open' => true, 'window_open' => $open, 'text' => $text, 'games' => $enabled, 'visibility' => $s['visibility']];
    $vis = false; $ov = tab_override($u, 'games'); if ($ov === false) return ['visible' => false, 'open' => false, 'window_open' => $open, 'text' => $text, 'games' => $enabled, 'visibility' => $s['visibility']];
    if ($s['visibility'] === 'all' || $ov === true) $vis = true;
    elseif ($s['visibility'] === 'selected') { try { $vis = (bool)one('SELECT 1 FROM game_users WHERE user_id=?', [$u['id']]); } catch (Throwable $e) { $vis = false; } }
    return ['visible' => $vis, 'open' => $vis && $open, 'window_open' => $open, 'text' => $text, 'games' => $enabled, 'visibility' => $s['visibility']];
}
/* ---- Tab privileges: the Root Admin decides who may open which tab ---- */
// key => [label, can the Root Admin switch it per person, where the server enforces it]
const TAB_DEFS = [
    'reports' => ['Reports', 1], 'performance' => ['Team performance', 1], 'pipeline' => ['Pipeline', 1], 'documents' => ['Documents (document checks)', 1], 'mail' => ['Mail', 1], 'schedule' => ['Schedule', 1],
    'games' => ['Games', 1], 'campaigns' => ['Campaigns', 1], 'team' => ['Team / Snapshot', 1], 'finance' => ['Finance', 1], 'learn' => ['Learn', 1], 'competition' => ['Competition', 1], 'events' => ['Events', 1],
    'research' => ['Research', 1], 'projects' => ['Projects', 1], 'products' => ['Products & services', 1], 'news' => ['News', 1], 'myfiles' => ['My Documents', 1], 'executive' => ['Executive dashboard (C-suite)', 1],
    'admin' => ['Admin (users)', 0], 'departments' => ['Departments', 0], 'logs' => ['Logs', 0], 'access' => ['Access control', 0],
];
function tab_overrides(array $u): array {
    static $cache = []; $id = (int)($u['id'] ?? 0); if (isset($cache[$id])) return $cache[$id];
    $m = []; try { foreach (all('SELECT tab,allowed FROM user_access WHERE user_id=?', [$id]) as $r) $m[$r['tab']] = (bool)(int)$r['allowed']; } catch (Throwable $e) {}
    return $cache[$id] = $m;
}
/** Tabs this person may open but not change (set by the Root Admin: "Read only"). */
function tab_ro_map(array $u): array {
    static $cache = []; $id = (int)($u['id'] ?? 0); if (isset($cache[$id])) return $cache[$id];
    $m = []; try { foreach (all('SELECT tab FROM user_access WHERE user_id=? AND allowed=1 AND readonly=1', [$id]) as $r) $m[$r['tab']] = true; } catch (Throwable $e) {}
    return $cache[$id] = $m;
}
function tab_readonly(array $u, string $tab): bool { return empty($u['is_root']) && !empty(tab_ro_map($u)[$tab]); }
function tab_override(array $u, string $tab): ?bool { $m = tab_overrides($u); return array_key_exists($tab, $m) ? $m[$tab] : null; }
/** What a person gets when the Root Admin has not said anything about a tab. */
function tab_default(array $u, string $tab): bool {
    $role = $u['role'] ?? '';
    return match ($tab) {
        'executive' => $role === 'founder',                             // the C-suite view: the Founder / CEO, and anyone the Root Admin adds
        'performance' => false,                                        // only people the Root Admin picks
        'documents' => $role === 'super_admin',
        'myfiles' => $role !== 'super_admin',                          // personal files: never for administrators
        'team' => in_array($role, ['super_admin', 'founder', 'manager'], true),
        'finance' => $role === 'super_admin' || $role === 'founder' || in_dept($u, 'Finance'),
        'campaigns' => $role === 'super_admin' || in_dept($u, 'Marketing'),
        'admin', 'departments' => $role === 'super_admin',
        'logs', 'access' => false,
        default => true,
    };
}
function tab_access(array $u, string $tab): bool {
    if (!empty($u['is_root'])) return $tab !== 'myfiles';                // the Root Admin can open everything except other people's (and their own) personal files tab
    if ($tab === 'myfiles' && ($u['role'] ?? '') === 'super_admin') return false;
    if ($tab === 'logs' || $tab === 'access') return false;
    if ($tab === 'admin' || $tab === 'departments') return ($u['role'] ?? '') === 'super_admin' && (tab_override($u, $tab) ?? true);
    $ov = tab_override($u, $tab); if ($ov !== null) return $ov;
    return tab_default($u, $tab);
}
function tab_map(array $u): array { $m = []; foreach (TAB_DEFS as $k => $_) $m[$k] = tab_access($u, $k); return $m; }
function need_tab(array $u, string $tab, string $msg = 'You do not have access to this section'): void { if (!tab_access($u, $tab)) fail($msg, 403); }
function is_root(?array $u): bool { return !empty($u['is_root']); }
function need_root(): array { $u = need_user(); if (!is_root($u)) fail('Only the Root Admin can do this', 403); return $u; }
function finance_default(array $u): bool { return ($u['role'] ?? '') === 'super_admin' || in_dept($u, 'Finance'); }
function can_campaigns(array $u): bool { $ov = tab_override($u, 'campaigns'); return !empty($u['is_root']) || ($ov ?? (($u['role'] ?? '') === 'super_admin' || in_dept($u, 'Marketing'))); }
function can_finance(array $u): bool { $ov = tab_override($u, 'finance'); return ($u['role'] ?? '') !== 'founder' && (!empty($u['is_root']) || ($ov ?? finance_default($u))); }

function me(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    start_session();
    $id = $_SESSION['uid'] ?? null;
    $u = $id ? one('SELECT u.id,u.name,u.email,u.role,u.department_id,u.job_title,u.must_change_password,d.name AS department
                    FROM users u LEFT JOIN departments d ON d.id=u.department_id WHERE u.id=? AND u.active=1', [$id]) : null;
    if ($u) { $u['department_ids'] = user_dept_ids((int)$u['id'], (int)($u['department_id'] ?? 0));
        $u['head_of'] = head_dept_ids((int)$u['id']); try { $u['is_root'] = (int)(one('SELECT is_root FROM users WHERE id=?', [$u['id']])['is_root'] ?? 0); } catch (Throwable $e) { $u['is_root'] = 0; } try { $ga = games_access($u); $u['games'] = $ga['visible']; $u['games_open'] = $ga['open']; } catch (Throwable $e) { $u['games'] = false; $u['games_open'] = false; } $u['campaigns'] = can_campaigns($u); $u['finance'] = can_finance($u); $u['tabs'] = tab_map($u); try { require_once __DIR__ . '/approvals.php'; $u['approvals'] = ap_pending_count((int)$u['id']); } catch (Throwable $e) { $u['approvals'] = 0; } $u['ro_tabs'] = array_values(array_filter(array_keys(tab_ro_map($u)), fn($k) => !empty($u['tabs'][$k])));
        $u['av'] = 0; try { $r0 = one('SELECT UNIX_TIMESTAMP(updated_at) t FROM user_avatars WHERE user_id=?', [$u['id']]); $u['av'] = $r0 ? (int)$r0['t'] : 0; } catch (Throwable $e) {} }
    if ($id && !$u) { session_destroy(); }
    return $u;
}
function need_user(): array { $u = me(); if (!$u) fail('Not signed in', 401); return $u; }
function need_role(array $roles): array { $u = need_user(); if (!in_array($u['role'], $roles, true)) fail('Not allowed', 403); return $u; }

function csrf_token(): string { start_session(); if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function check_csrf(): void {
    start_session();
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) fail('Session expired, please refresh', 419);
}

function log_act(?int $uid, string $action, string $detail = ''): void {
    q('INSERT INTO activity_log (user_id,action,detail) VALUES (?,?,?)', [$uid, $action, mb_substr($detail, 0, 300)]);
}
function notify(int $uid, string $type, string $title, string $body = '', string $link = ''): void {
    q('INSERT INTO notifications (user_id,type,title,body,link) VALUES (?,?,?,?,?)', [$uid, $type, mb_substr($title, 0, 200), mb_substr($body, 0, 500), $link ?: null]);
    $nid = (int)db()->lastInsertId(); push_try($uid, $title, $body, (string)$link, 'n' . $nid);
}
/** Web Push to the person's phones/browsers (only if they allowed it); never lets a failure break the caller. */
function push_try(int $uid, string $title, string $body, string $link, string $tag): void {
    try { static $loaded = false; if (!$loaded) { require_once __DIR__ . '/push.php'; $loaded = true; } push_notify($uid, $title, $body, $link, $tag); } catch (Throwable $e) {}
}

function s(mixed $v, int $max = 200): string { return mb_substr(trim((string)$v), 0, $max); }
function d(?string $v): ?string { // date Y-m-d or null
    if (!$v) return null;
    $t = DateTime::createFromFormat('Y-m-d', $v);
    return ($t && $t->format('Y-m-d') === $v) ? $v : null;
}
function dt(?string $v): ?string { // datetime
    if (!$v) return null;
    $ts = strtotime($v);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}
function in(string $v, array $allowed, string $default): string { return in_array($v, $allowed, true) ? $v : $default; }

function sync_user_depts(int $uid, array $b): void { // a person can belong to several departments; the first is their primary
    if (!array_key_exists('department_ids', $b)) return;
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$b['department_ids']))));
    if ($ids) { $ok = array_column(all('SELECT id FROM departments WHERE id IN (' . implode(',', $ids) . ')'), 'id'); $ids = array_values(array_intersect($ids, array_map('intval', $ok))); }
    q('DELETE FROM user_departments WHERE user_id=?', [$uid]);
    foreach ($ids as $d) q('INSERT IGNORE INTO user_departments (user_id,department_id) VALUES (?,?)', [$uid, $d]);
    q('UPDATE users SET department_id=? WHERE id=?', [$ids[0] ?? null, $uid]);
}
