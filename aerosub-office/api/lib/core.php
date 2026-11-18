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
function can_campaigns(array $u): bool { return $u['role'] === 'super_admin' || in_dept($u, 'Marketing'); }
function can_finance(array $u): bool { return $u['role'] === 'super_admin' || in_dept($u, 'Finance'); }
function me(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    start_session();
    $id = $_SESSION['uid'] ?? null;
    $u = $id ? one('SELECT u.id,u.name,u.email,u.role,u.department_id,u.job_title,u.must_change_password,d.name AS department
                    FROM users u LEFT JOIN departments d ON d.id=u.department_id WHERE u.id=? AND u.active=1', [$id]) : null;
    if ($u) { $u['department_ids'] = user_dept_ids((int)$u['id'], (int)($u['department_id'] ?? 0));
        $u['head_of'] = head_dept_ids((int)$u['id']); $u['campaigns'] = can_campaigns($u); $u['finance'] = can_finance($u);
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
