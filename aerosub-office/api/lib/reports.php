<?php
declare(strict_types=1);
require_once __DIR__ . '/workweek.php';   // the Super Admin's work week: week start/end and working days
// Daily task reports.
//   GET    /api/reports/dashboard?date=&department=&user=   the report dashboard (scoped to what the signed-in person may see)
//   GET    /api/reports?task=&user=&department=&from=&to=   report history
//   GET    /api/reports/{id}                                one report with its comments and shares
//   POST   /api/reports                                     submit (or update) today's report on a task you are assigned to
//   DELETE /api/reports/{id}                                remove a report (its author, or the Super Admin)
//   POST   /api/reports/{id}/comments   DELETE /api/reports/{id}/comments/{cid}
//   POST   /api/reports/{id}/share      DELETE /api/reports/{id}/share/{userId}
// rp_transfer_task() is also used by POST /api/tasks/{id}/transfer (see index.php).
//
// Who sees what:  Super Admin and Founder see every report. A department head (set by the Super Admin) and a manager see the
// reports of everyone in the departments they head or manage. Everyone sees their own reports and any report shared with them.
// Founders are read-only, so they cannot submit, comment or share.

const RP_ACTIVE = ['todo', 'in_progress', 'blocked'];            // still being worked on
const RP_CLOSED = ['done', 'canceled', 'transferred'];           // finished one way or another
const RP_WORKING = ['in_progress', 'blocked'];                   // a daily report is expected on these

/** Departments whose reports this person may read: only the ones they head (a manager role alone is not enough). */
function rp_heads(array $u): array { return array_map('intval', $u['head_of'] ?? []); }
function rp_heads_user(array $u, int $pid): bool {
    $h = rp_heads($u); if (!$h) return false; $r = one('SELECT department_id FROM users WHERE id=?', [$pid]);
    return (bool)array_intersect(user_dept_ids($pid, (int)($r['department_id'] ?? 0)), $h);
}
function rp_global(array $u): bool { return in_array($u['role'], ['super_admin', 'founder'], true); }
/** A submitted weekly report is locked for its owner. Heads of department (and administrators) edit without approval; everyone else asks their head. */
function rp_ew_ensure(): void { static $d = false; if ($d) return; $d = true; try { require_once __DIR__ . '/migrate.php'; if (!col_exists('weekly_reports', 'edit_open_until') || !one("SHOW TABLES LIKE 'weekly_edit_requests'")) { require_once __DIR__ . '/migrate_fin3.php'; migrate_fin_ext(); } } catch (Throwable $e) { error_log('Weekly edit schema: ' . $e->getMessage()); } }
function rp_edit_exempt(array $u): bool { return rp_global($u) || (bool)rp_heads($u) || !empty($u['is_root']); }
function rp_edit_locked(array $u, ?array $row): bool {
    if (!$row || ($row['kind'] ?? 'personal') !== 'personal' || (int)$row['user_id'] !== (int)$u['id'] || ($row['status'] ?? '') !== 'submitted' || rp_edit_exempt($u)) return false;
    return empty($row['edit_open_until']) || (string)$row['edit_open_until'] <= date('Y-m-d H:i:s');
}
function rp_edit_info(array $u, ?array $row): array {
    rp_ew_ensure(); if (!$row || ($row['kind'] ?? 'personal') !== 'personal' || empty($row['id'])) return ['locked' => false];
    $own = (int)$row['user_id'] === (int)$u['id']; $open = !empty($row['edit_open_until']) && (string)$row['edit_open_until'] > date('Y-m-d H:i:s');
    $o = ['locked' => rp_edit_locked($u, $row), 'exempt' => rp_edit_exempt($u), 'open_until' => $open ? $row['edit_open_until'] : null];
    if ($own) $o['request'] = one('SELECT r.id,r.status,r.reason,r.note,r.created_at,r.decided_at,d.name AS decided_by FROM weekly_edit_requests r LEFT JOIN users d ON d.id=r.decided_by WHERE r.weekly_id=? ORDER BY r.id DESC LIMIT 1', [$row['id']]) ?: null;
    elseif (rp_global($u) || rp_heads_user($u, (int)$row['user_id'])) $o['pending'] = one("SELECT r.id,r.reason,r.created_at,u.name AS who FROM weekly_edit_requests r JOIN users u ON u.id=r.user_id WHERE r.weekly_id=? AND r.status='pending' ORDER BY r.id DESC LIMIT 1", [$row['id']]) ?: null;
    return $o;
}

/** Members of the given departments (primary or additional). */
function rp_member_sql(array $deptIds): string {
    $l = implode(',', array_map('intval', $deptIds)) ?: '0';
    return "(SELECT user_id FROM user_departments WHERE department_id IN ($l) UNION SELECT id FROM users WHERE department_id IN ($l))";
}

/** [sql, params] limiting daily_reports r (joined to tasks t) to what this person may see. */
function rp_scope(array $u): array {
    if (rp_global($u)) return ['1=1', []];
    $parts = ['r.user_id=?', 'EXISTS(SELECT 1 FROM report_shares rs WHERE rs.report_id=r.id AND rs.user_id=?)']; $p = [$u['id'], $u['id']];
    if ($m = rp_heads($u)) { $parts[] = 't.department_id IN (' . implode(',', $m) . ')'; $parts[] = 'r.user_id IN ' . rp_member_sql($m); }
    return ['(' . implode(' OR ', $parts) . ')', $p];
}

/** [sql, params] limiting tasks t to the ones this person's dashboard covers (own tasks, or their departments' tasks). */
function rp_task_scope(array $u): array {
    if (rp_global($u)) return ['1=1', []];
    $parts = ['t.assignee_id=?']; $p = [$u['id']];
    if ($m = rp_heads($u)) { $parts[] = 't.department_id IN (' . implode(',', $m) . ')'; $parts[] = 't.assignee_id IN ' . rp_member_sql($m); }
    return ['(' . implode(' OR ', $parts) . ')', $p];
}

function rp_load(int $id): ?array {
    return one('SELECT r.*, t.title AS task_title, t.status AS task_status, t.department_id, t.assignee_id, t.creator_id, t.due_date, u.name AS user_name, d.name AS department
                FROM daily_reports r JOIN tasks t ON t.id=r.task_id JOIN users u ON u.id=r.user_id LEFT JOIN departments d ON d.id=t.department_id WHERE r.id=?', [$id]);
}
/** Head or manager of the department this report belongs to (or the Super Admin): may share and moderate it. */
function rp_can_manage(array $u, array $r): bool {
    if ($u['role'] === 'super_admin') return true;
    if ($u['role'] === 'founder') return false;
    $m = rp_heads($u); if (!$m) return false;
    return ($r['department_id'] && in_array((int)$r['department_id'], $m, true)) || rp_heads_user($u, (int)$r['user_id']);
}
function rp_can_view(array $u, array $r): bool {
    if (rp_global($u) || (int)$r['user_id'] === (int)$u['id'] || rp_can_manage($u, $r)) return true;
    return (bool)one('SELECT 1 FROM report_shares WHERE report_id=? AND user_id=?', [$r['id'], $u['id']]);
}
function rp_can_share(array $u, array $r): bool { return $u['role'] !== 'founder' && ((int)$r['user_id'] === (int)$u['id'] || rp_can_manage($u, $r)); }

function rp_status_label(string $s): string {
    return ['todo' => 'Not yet started', 'in_progress' => 'Open / ongoing', 'blocked' => 'Blocked', 'done' => 'Completed', 'deferred' => 'Deferred', 'canceled' => 'Canceled', 'transferred' => 'Transferred'][$s] ?? $s;
}

/* ----------------------------------------------------------------------------------------------------------------- */
/* Transfer a task to someone else: the old task is closed as "Transferred" and a fresh copy is opened for the new person. */
function rp_transfer_task(array $t, array $u, array $b, bool $full): never {
    if ($u['role'] === 'founder') fail('Founder accounts are read-only', 403);
    if (!$full) fail('Only the person who gave the task, or a department head, can transfer it', 403);
    if (in_array($t['status'], RP_CLOSED, true)) fail('This task is already ' . strtolower(rp_status_label($t['status'])) . ' and cannot be transferred');
    require_once __DIR__ . '/transfers.php'; tr_ensure(); if ((string)(one('SELECT transfer_state FROM tasks WHERE id=?', [(int)$t['id']])['transfer_state'] ?? '') !== '') fail('This task is already waiting for an answer to a transfer', 409);
    $to = (int)($b['to_user_id'] ?? 0); $note = s($b['note'] ?? '', 300);
    $nu = $to ? one('SELECT id,name,department_id FROM users WHERE id=? AND active=1 AND role<>\'founder\'', [$to]) : null; if (!$nu) fail('Choose who the task goes to');
    if ($to === (int)$t['assignee_id']) fail('That person already has this task');
    if ($u['role'] !== 'super_admin' && $to !== (int)$u['id'] && !manages_user($u, $to)) fail('You can transfer tasks to people in your own department', 403);
    $dept = !empty($b['department_id']) ? (int)$b['department_id'] : ($t['department_id'] ? (int)$t['department_id'] : null);
    if (empty($b['department_id']) && $dept && !in_array($dept, user_dept_ids($to, (int)$nu['department_id']), true)) $dept = $nu['department_id'] ? (int)$nu['department_id'] : $dept; // crossing departments: it moves with the person
    $pdo = db(); $pdo->beginTransaction();
    try {
        q('INSERT INTO tasks (title,description,status,priority,assignee_id,creator_id,department_id,due_date,company_id,contact_id,task_type,project_id,starts_at,ends_at,transferred_from_id,transfer_state)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$t['title'], $t['description'], 'todo', $t['priority'], $to, $t['creator_id'], $dept, $t['due_date'], $t['company_id'], $t['contact_id'], $t['task_type'], $t['project_id'], $t['starts_at'], $t['ends_at'], $t['id'], 'pending']);
        $nid = (int)$pdo->lastInsertId();
        q("UPDATE tasks SET transfer_state='offered', transferred_to_id=? WHERE id=?", [$nid, $t['id']]);   // stays with the current person until the new person accepts
        $info = json_encode(['from' => $t['assignee_name'] ?? null, 'to' => $nu['name'], 'note' => $note, 'old_task' => (int)$t['id'], 'new_task' => $nid], JSON_UNESCAPED_UNICODE);
        q('INSERT INTO task_updates (task_id,user_id,kind,data) VALUES (?,?,?,?)', [$t['id'], $u['id'], 'transfer_offer', $info]);
        q('INSERT INTO task_updates (task_id,user_id,kind,data) VALUES (?,?,?,?)', [$nid, $u['id'], 'transfer_offer', $info]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    foreach (array_unique(array_filter([$to, (int)$t['assignee_id'], (int)$t['creator_id']])) as $n) {
        if ((int)$n === (int)$u['id']) continue;
        if ($n === $to) notify($n, 'task_assigned', '📨 Task offered to you: ' . $t['title'], $u['name'] . ' wants you to take it' . ($note ? ' — ' . $note : '') . '. Accept or decline.', '#/tasks/' . $nid);
        else notify((int)$n, 'task_transferred', $u['name'] . ' offered "' . $t['title'] . '" to ' . $nu['name'], 'Waiting for ' . $nu['name'] . ' to accept' . ($note ? ' — ' . $note : ''), '#/tasks/' . (int)$t['id']);
    }
    log_act((int)$u['id'], 'task_transferred', $t['title'] . ' → ' . $nu['name']);
    out(['ok' => true, 'id' => $nid, 'pending' => true]);
}

/* ----------------------------------------------------------------------------------------------------------------- */
if (!isset($r) || $r !== 'reports') return;   // when only the functions above were wanted

$u = need_user(); $uid = (int)$u['id']; $ro = $u['role'] === 'founder';
$today = date('Y-m-d');
$sub2 = $seg[3] ?? null; $sub2 = ($sub2 !== null && ctype_digit($sub2)) ? (int)$sub2 : null;

/* ---------- dashboard ---------- */
if ($method === 'GET' && $sub === 'dashboard') {
    $date = d($_GET['date'] ?? null) ?: $today;     // the day shown (or the last day of the range)
    $rfrom = d($_GET['from'] ?? null); $rangeMode = $rfrom !== null && $rfrom <= $date; if ($rangeMode && $rfrom < date('Y-m-d', strtotime($date . ' -365 days'))) $rfrom = date('Y-m-d', strtotime($date . ' -365 days'));
    $qs = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60); $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $qs) . '%';
    $prio = in_array($_GET['priority'] ?? '', ['low', 'medium', 'high', 'urgent'], true) ? $_GET['priority'] : ''; $source = in_array($_GET['source'] ?? '', ['tasks', 'weekly'], true) ? $_GET['source'] : 'all';
    $global = rp_global($u); $managed = rp_heads($u); $review = $global || (bool)$managed;
    // Filters the person is allowed to use
    $depts = $global ? all('SELECT id,name FROM departments ORDER BY name') : ($managed ? all('SELECT id,name FROM departments WHERE id IN (' . implode(',', $managed) . ') ORDER BY name') : []);
    $deptIds = array_map('intval', array_column($depts, 'id'));
    $fd = (int)($_GET['department'] ?? 0); if ($fd && !in_array($fd, $deptIds, true)) $fd = 0;
    if ($review) {
        $people = $global ? all("SELECT id,name,job_title FROM users WHERE active=1 AND role<>'founder' ORDER BY name")
                          : all('SELECT id,name,job_title FROM users WHERE active=1 AND id IN ' . rp_member_sql($managed) . ' ORDER BY name');
    } else $people = [['id' => $uid, 'name' => $u['name'], 'job_title' => $u['job_title']]];
    $peopleIds = array_map('intval', array_column($people, 'id'));
    $fu = (int)($_GET['user'] ?? 0); if ($fu && !in_array($fu, $peopleIds, true)) $fu = 0;

    // tasks in scope
    [$ts, $tp] = rp_task_scope($u); $tw = [$ts]; $tpar = $tp;
    if ($fd) { $tw[] = '(t.department_id=? OR t.assignee_id IN ' . rp_member_sql([$fd]) . ')'; $tpar[] = $fd; }
    if ($fu) { $tw[] = 't.assignee_id=?'; $tpar[] = $fu; }
    if ($qs !== '') { $tw[] = '(t.title LIKE ? OR a.name LIKE ?)'; array_push($tpar, $like, $like); }
    if ($prio !== '') { $tw[] = 't.priority=?'; $tpar[] = $prio; }
    if ($source === 'weekly') $tw[] = '1=0';
    $since = ($rangeMode ? $rfrom : date('Y-m-d', strtotime($date . ' -30 days'))) . ' 00:00:00';
    $tasks = all("SELECT t.id,t.title,t.status,t.priority,t.due_date,t.assignee_id,t.department_id,t.updated_at,t.completed_at,a.name AS assignee_name,d.name AS department
                  FROM tasks t LEFT JOIN users a ON a.id=t.assignee_id LEFT JOIN departments d ON d.id=t.department_id
                  WHERE " . implode(' AND ', $tw) . " AND (t.status IN ('todo','in_progress','blocked','deferred') OR (t.updated_at >= ? AND t.updated_at <= ?)) AND t.created_at <= ? ORDER BY t.due_date IS NULL, t.due_date LIMIT 5000",
                 array_merge($tpar, [$since, $date . ' 23:59:59', $date . ' 23:59:59']));

    // reports on the chosen day
    [$rs, $rp] = rp_scope($u); $rw = [$rs, $rangeMode ? 'r.report_date BETWEEN ? AND ?' : 'r.report_date=?']; $rpar = array_merge($rp, $rangeMode ? [$rfrom, $date] : [$date]);
    if ($fd) { $rw[] = '(t.department_id=? OR r.user_id IN ' . rp_member_sql([$fd]) . ')'; $rpar[] = $fd; }
    if ($fu) { $rw[] = 'r.user_id=?'; $rpar[] = $fu; }
    if ($qs !== '') { $rw[] = '(r.summary LIKE ? OR t.title LIKE ? OR u.name LIKE ?)'; array_push($rpar, $like, $like, $like); }
    if ($prio !== '') { $rw[] = 't.priority=?'; $rpar[] = $prio; }
    if ($source === 'weekly') $rw[] = '1=0';
    $reports = all('SELECT r.id,r.task_id,r.user_id,r.report_date,r.status,r.progress,r.hours,r.summary,r.blockers,r.created_at,r.updated_at,t.title AS task_title,t.department_id,u.name AS user_name,d.name AS department,
                    (SELECT COUNT(*) FROM report_comments c WHERE c.report_id=r.id) AS comment_count, (SELECT COUNT(*) FROM report_shares s WHERE s.report_id=r.id) AS share_count
                    FROM daily_reports r JOIN tasks t ON t.id=r.task_id JOIN users u ON u.id=r.user_id LEFT JOIN departments d ON d.id=t.department_id
                    WHERE ' . implode(' AND ', $rw) . ' ORDER BY ' . ($rangeMode ? 'r.report_date DESC, ' : '') . 'u.name, r.updated_at DESC LIMIT 300', $rpar);
    $reported = []; foreach ($reports as $x) $reported[(int)$x['task_id'] . ':' . (int)$x['user_id']] = true;

    // Items written in weekly reports (Delivered / In view / Nil-Lost) that are not on the task list count with the tasks: delivered = completed, in view = open, lost = canceled
    $wkItems = []; $WMAP = ['done' => 'done', 'view' => 'in_progress', 'lost' => 'canceled'];
    if ($source !== 'tasks') {
        $wIds = $fu ? [$fu] : ($review ? $peopleIds : [$uid]); $wSql = 'w.user_id IN (' . ($wIds ? implode(',', array_map('intval', $wIds)) : '0') . ')'; $wPar = [];
        if ($fd && !$fu) { $wSql .= ' AND w.user_id IN ' . rp_member_sql([$fd]); }
        $wFrom = $rangeMode ? $rfrom : date('Y-m-d', strtotime($date . ' -30 days'));
        $own = []; foreach (all('SELECT assignee_id, LOWER(TRIM(title)) t FROM tasks WHERE assignee_id IN (' . ($wIds ? implode(',', array_map('intval', $wIds)) : '0') . ')') as $r0) $own[(int)$r0['assignee_id']][$r0['t']] = 1;
        $seenW = [];
        foreach (all("SELECT w.id,w.user_id,w.week_start,w.data,w.status AS wstatus,u.name FROM weekly_reports w JOIN users u ON u.id=w.user_id WHERE w.kind='personal' AND $wSql AND w.week_start BETWEEN ? AND ? ORDER BY w.week_start", array_merge($wPar, [date('Y-m-d', strtotime($wFrom . ' -6 days')), $date])) as $w) {
            $c = json_decode((string)$w['data'], true); foreach ((array)($c['rows'] ?? []) as $x) { $tt = mb_strtolower(trim((string)($x['task'] ?? ''))); if ($tt === '' || isset($own[(int)$w['user_id']][$tt])) continue;
                $st = array_key_exists($x['status'] ?? '', $WMAP) ? $x['status'] : 'view'; $hay = mb_strtolower(($x['task'] ?? '') . ' ' . ($x['client'] ?? '') . ' ' . ($x['project'] ?? '') . ' ' . $w['name']); if ($qs !== '' && mb_strpos($hay, mb_strtolower($qs)) === false) continue;
                $seenW[(int)$w['user_id'] . '|' . $tt] = ['weekly_id' => (int)$w['id'], 'user_id' => (int)$w['user_id'], 'user_name' => $w['name'], 'task' => (string)$x['task'], 'client' => (string)($x['client'] ?? ''), 'project' => (string)($x['project'] ?? ''), 'status' => $st, 'week_start' => $w['week_start'], 'outstanding' => (string)($x['outstanding'] ?? ''), 'draft' => $w['wstatus'] !== 'submitted']; } }
        $wkItems = array_slice(array_values($seenW), 0, 500);
    }
    // status cards, people table and "not reported yet"
    $counts = ['todo' => 0, 'in_progress' => 0, 'blocked' => 0, 'deferred' => 0, 'canceled' => 0, 'done' => 0, 'transferred' => 0];
    $per = []; $missing = [];
    foreach ($tasks as $t) {
        $st = $t['status']; if (!isset($counts[$st])) continue; $counts[$st]++;
        $aid = (int)($t['assignee_id'] ?? 0);
        if ($aid) {
            $per[$aid] ??= ['id' => $aid, 'name' => $t['assignee_name'], 'counts' => array_fill_keys(array_keys($counts), 0), 'expected' => 0, 'reported' => 0];
            $per[$aid]['counts'][$st]++;
            if (in_array($st, RP_WORKING, true)) { $per[$aid]['expected']++; if (isset($reported[(int)$t['id'] . ':' . $aid])) $per[$aid]['reported']++; elseif ($date <= $today) $missing[] = ['task_id' => (int)$t['id'], 'title' => $t['title'], 'status' => $st, 'user_id' => $aid, 'user_name' => $t['assignee_name'], 'department' => $t['department'], 'due_date' => $t['due_date']]; }
        }
    }
    foreach ($wkItems as $wi) { $st = $WMAP[$wi['status']]; $counts[$st]++; $aid = $wi['user_id'];
        $per[$aid] ??= ['id' => $aid, 'name' => $wi['user_name'], 'counts' => array_fill_keys(array_keys($counts), 0), 'expected' => 0, 'reported' => 0]; $per[$aid]['counts'][$st]++; $per[$aid]['wk'] = ($per[$aid]['wk'] ?? ['done' => 0, 'view' => 0, 'lost' => 0]); $per[$aid]['wk'][$wi['status']]++; }
    usort($missing, fn($a, $b) => strcmp((string)$a['user_name'], (string)$b['user_name']));
    foreach ($per as &$pp) { $pp['wk'] = $pp['wk'] ?? ['done' => 0, 'view' => 0, 'lost' => 0]; } unset($pp); $people_rows = array_values($per); usort($people_rows, fn($a, $b) => ($b['expected'] - $b['reported']) <=> ($a['expected'] - $a['reported']) ?: strcmp((string)$a['name'], (string)$b['name']));

    // 7-day trend
    $from = $rangeMode ? max($rfrom, date('Y-m-d', strtotime($date . ' -30 days'))) : date('Y-m-d', strtotime($date . ' -6 days')); $nDays = (int)round((strtotime($date) - strtotime($from)) / 86400) + 1;
    $trw = [$rs, 'r.report_date BETWEEN ? AND ?']; $trp = array_merge($rp, [$from, $date]);
    if ($fd) { $trw[] = '(t.department_id=? OR r.user_id IN ' . rp_member_sql([$fd]) . ')'; $trp[] = $fd; }
    if ($fu) { $trw[] = 'r.user_id=?'; $trp[] = $fu; }
    $byDay = array_column(all('SELECT r.report_date dte, COUNT(*) c FROM daily_reports r JOIN tasks t ON t.id=r.task_id WHERE ' . implode(' AND ', $trw) . ' GROUP BY r.report_date', $trp), 'c', 'dte');
    $dw = [$ts, "t.status='done'", 'DATE(t.completed_at) BETWEEN ? AND ?']; $dp = array_merge($tp, [$from, $date]);
    if ($fd) { $dw[] = '(t.department_id=? OR t.assignee_id IN ' . rp_member_sql([$fd]) . ')'; $dp[] = $fd; }
    if ($fu) { $dw[] = 't.assignee_id=?'; $dp[] = $fu; }
    $doneDay = array_column(all('SELECT DATE(t.completed_at) dte, COUNT(*) c FROM tasks t WHERE ' . implode(' AND ', $dw) . ' GROUP BY DATE(t.completed_at)', $dp), 'c', 'dte');
    $trend = []; for ($i = 0; $i < $nDays; $i++) { $day = date('Y-m-d', strtotime($from . " +$i days")); $trend[] = ['date' => $day, 'reports' => (int)($byDay[$day] ?? 0), 'completed' => (int)($doneDay[$day] ?? 0)]; }

    // reports other people shared with me
    $shared = all('SELECT r.id,r.report_date,r.status,r.summary,t.title AS task_title,u.name AS user_name,sb.name AS shared_by,rs.note,rs.created_at AS shared_at
                   FROM report_shares rs JOIN daily_reports r ON r.id=rs.report_id JOIN tasks t ON t.id=r.task_id JOIN users u ON u.id=r.user_id LEFT JOIN users sb ON sb.id=rs.shared_by
                   WHERE rs.user_id=? ORDER BY rs.created_at DESC LIMIT 15', [$uid]);

    $isWork = ww_is_workday($date); if (!$isWork) { $missing = []; foreach ($people_rows as &$pr) $pr['expected'] = $pr['reported'] = 0; unset($pr); }   // nobody is expected to report on a day off
    $wkFrom2 = $rangeMode ? $rfrom : date('Y-m-d', strtotime($date . ' -30 days')); $weeksIn = ww_weeks($wkFrom2, $date); $wsub = [];
    $scopeIds = array_map('intval', array_column($people_rows, 'id')); if ($scopeIds && $weeksIn) foreach (all('SELECT user_id, SUM(status=\'submitted\') s, COUNT(*) n FROM weekly_reports WHERE kind=\'personal\' AND user_id IN (' . implode(',', $scopeIds) . ') AND week_start IN (' . implode(',', array_fill(0, count($weeksIn), '?')) . ') GROUP BY user_id', $weeksIn) as $r0) $wsub[(int)$r0['user_id']] = $r0;
    foreach ($people_rows as &$pr) { $pr['weeks'] = ['submitted' => (int)($wsub[$pr['id']]['s'] ?? 0), 'saved' => (int)($wsub[$pr['id']]['n'] ?? 0)]; } unset($pr);
    $mine = 0; foreach ($tasks as $t) if ((int)$t['assignee_id'] === $uid && in_array($t['status'], RP_WORKING, true) && !isset($reported[(int)$t['id'] . ':' . $uid])) $mine++; if (!$isWork) $mine = 0;
    out(['date' => $date, 'today' => $today, 'scope' => $global ? 'all' : ($managed ? 'department' : 'own'), 'review' => $review, 'read_only' => $ro,
         'departments' => $depts, 'people' => $people, 'filter' => ['department' => $fd, 'user' => $fu],
         'perf' => tab_access($u, 'performance'), 'counts' => $counts, 'reports' => $reports, 'missing' => array_slice($missing, 0, 100), 'missing_total' => count($missing), 'people_rows' => $people_rows,
         'trend' => $trend, 'shared' => $shared, 'my_missing' => $mine, 'weekly_items' => $wkItems, 'range' => ['mode' => $rangeMode, 'from' => $rangeMode ? $rfrom : date('Y-m-d', strtotime($date . ' -30 days')), 'to' => $date],
         'filter_more' => ['q' => $qs, 'priority' => $prio, 'source' => $source], 'week' => ww_out(), 'work_day' => $isWork, 'weeks' => count($weeksIn),
         'totals' => ['reports' => count($reports), 'expected' => array_sum(array_column($people_rows, 'expected')), 'missing' => count($missing)]]);
}

/* ---------- weekly report (the ASL-HR-WR-001 "Team weekly report" sheet) ---------- */
const RP_LEGEND = ['lost' => ['NIL/LOST', 'FF0000'], 'view' => ['IN VIEW/AWAITING/REVIEW STATE', 'FFC000'], 'done' => ['AWARDED/DELIVERED/CLOSED OUT', '92D050']];
const RP_LEGEND_SHORT = ['lost' => 'Lost', 'view' => 'In view', 'done' => 'Delivered'];
require_once __DIR__ . '/workweek.php';   // rp_week_start(), rp_week_end(), working days: the Super Admin sets the work week
function rp_can_see_person(array $u, int $pid): bool { return rp_global($u) || $pid === (int)$u['id'] || rp_heads_user($u, $pid); }

function rp_weekly_rows(int $pid, string $mon): array {
    $fri = rp_week_end($mon); $sun = date('Y-m-d', strtotime($mon . ' +6 days'));
    $tasks = all("SELECT t.id,t.title,t.status,t.due_date,t.task_type,t.status_reason,t.updated_at,t.completed_at,t.created_at,co.name AS company,p.name AS project,d.name AS department
                  FROM tasks t LEFT JOIN companies co ON co.id=t.company_id LEFT JOIN projects p ON p.id=t.project_id LEFT JOIN departments d ON d.id=t.department_id
                  WHERE t.assignee_id=? AND ((t.created_at <= ? AND (t.status IN ('todo','in_progress','blocked','deferred') OR (t.status='done' AND t.completed_at > ?))) OR t.updated_at BETWEEN ? AND ? OR t.completed_at BETWEEN ? AND ? OR EXISTS (SELECT 1 FROM daily_reports r WHERE r.task_id=t.id AND r.user_id=? AND r.report_date BETWEEN ? AND ?))
                  ORDER BY co.name IS NULL, co.name, t.id LIMIT 120", [$pid, $sun . ' 23:59:59', $sun . ' 23:59:59', $mon . ' 00:00:00', $sun . ' 23:59:59', $mon . ' 00:00:00', $sun . ' 23:59:59', $pid, $mon, $fri]);
    $reps = []; foreach (all('SELECT task_id,report_date,status,progress,summary,blockers FROM daily_reports WHERE user_id=? AND report_date BETWEEN ? AND ? ORDER BY report_date', [$pid, $mon, $fri]) as $x) $reps[(int)$x['task_id']][] = $x;
    $rows = [];
    foreach ($tasks as $i => $t) {
        $rs = $reps[(int)$t['id']] ?? []; $last = $rs ? end($rs) : null; $st = $t['status'];
        if ($st === 'done' && $t['completed_at'] && $t['completed_at'] > $sun . ' 23:59:59') $st = 'in_progress';   // finished in a later week: it was still open during this one
        $carried = $t['created_at'] < $mon . ' 00:00:00' && !in_array($st, ['done', 'canceled', 'transferred'], true); 
        $cls = $st === 'done' ? 'done' : ($st === 'canceled' ? 'lost' : 'view');
        $out_ = [];
        if (!in_array($st, ['done', 'canceled', 'transferred'], true)) {
            if ($last) $out_[] = (int)$last['progress'] . '% complete';
            if ($t['due_date']) $out_[] = 'due ' . date('j M', strtotime($t['due_date']));
            if ($last && $last['blockers']) $out_[] = 'blocker: ' . $last['blockers'];
            if ($st === 'deferred') $out_[] = 'deferred';
        } elseif ($st === 'transferred') $out_[] = 'Transferred' . ($t['status_reason'] ? ': ' . $t['status_reason'] : '');
        elseif ($st === 'canceled' && $t['status_reason']) $out_[] = 'Canceled: ' . $t['status_reason'];
        if ($carried) array_unshift($out_, '↩ carried over from the week of ' . date('j M', strtotime(rp_week_start(substr((string)$t['created_at'], 0, 10)))));
        $cm = array_map(fn($x) => date('D', strtotime($x['report_date'])) . ': ' . $x['summary'], $rs);
        $rows[] = ['sn' => $i + 1, 'task_id' => (int)$t['id'], 'changed' => (string)($t['completed_at'] ?: $t['updated_at']), 'client' => $t['company'] ?: ($t['department'] ?: ''), 'project' => (string)$t['project'], 'task' => $t['title'], 'status' => $cls, 'carried' => $carried,
                   'outstanding' => mb_substr(implode(' · ', $out_), 0, 600), 'comment' => mb_substr(implode("\n", $cm), 0, 1500), 'review' => ''];
    }
    // tasks this person gave to other people that were completed this week are part of the week too
    $have = array_column($rows, 'task_id');
    foreach (all("SELECT t.id,t.title,t.completed_at,co.name AS company,p.name AS project,d.name AS department,a.name AS assignee FROM tasks t LEFT JOIN companies co ON co.id=t.company_id LEFT JOIN projects p ON p.id=t.project_id LEFT JOIN departments d ON d.id=t.department_id LEFT JOIN users a ON a.id=t.assignee_id
                  WHERE t.creator_id=? AND t.assignee_id<>? AND t.status='done' AND t.completed_at BETWEEN ? AND ? ORDER BY t.completed_at LIMIT 40", [$pid, $pid, $mon . ' 00:00:00', $sun . ' 23:59:59']) as $t) {
        if (in_array((int)$t['id'], $have, true)) continue;
        $rows[] = ['sn' => count($rows) + 1, 'task_id' => (int)$t['id'], 'changed' => (string)$t['completed_at'], 'client' => $t['company'] ?: ($t['department'] ?: ''), 'project' => (string)$t['project'], 'task' => $t['title'], 'status' => 'done',
                   'outstanding' => 'Assigned to ' . $t['assignee'] . ' · delivered ' . date('j M', strtotime($t['completed_at'])), 'comment' => '', 'review' => ''];
    }
    // rows the person left "In view" (awaiting / in review) in their latest earlier sheet come along, whether or not a task sits behind them, until they are changed to Delivered (or Lost)
    $prev = one("SELECT data,week_start FROM weekly_reports WHERE user_id=? AND kind='personal' AND week_start<? ORDER BY week_start DESC LIMIT 1", [$pid, $mon]);
    if ($prev) { $pc = json_decode((string)$prev['data'], true); $ids = []; $names = []; foreach ($rows as $r0) { if (!empty($r0['task_id'])) $ids[(int)$r0['task_id']] = 1; $names[mb_strtolower(trim((string)$r0['task']))] = 1; }
        $wk = date('j M', strtotime((string)$prev['week_start']));
        foreach ((array)($pc['rows'] ?? []) as $r) { if (!is_array($r) || ($r['status'] ?? '') !== 'view') continue; $nm = trim((string)($r['task'] ?? '')); if ($nm === '') continue; $tid = (int)($r['task_id'] ?? 0);
            if (($tid && isset($ids[$tid])) || isset($names[mb_strtolower($nm)])) continue;
            if ($tid) { $tk = one('SELECT status FROM tasks WHERE id=?', [$tid]); if ($tk && in_array($tk['status'], ['done', 'canceled', 'transferred'], true)) continue; if (!$tk) $tid = 0; }
            $old = trim((string)preg_replace('/^↩ carried over from the week of [^·]*(·\s*)?/u', '', (string)($r['outstanding'] ?? '')));
            $rows[] = ['sn' => count($rows) + 1, 'task_id' => $tid, 'changed' => $mon . ' 00:00:00', 'client' => (string)($r['client'] ?? ''), 'project' => (string)($r['project'] ?? ''), 'task' => $nm, 'status' => 'view', 'carried' => true,
                       'outstanding' => mb_substr('↩ carried over from the week of ' . $wk . ($old !== '' ? ' · ' . $old : ''), 0, 800), 'comment' => '', 'review' => '']; $names[mb_strtolower($nm)] = 1; if ($tid) $ids[$tid] = 1; } }
    return $rows;
}

if ($method === 'GET' && $sub === 'weekly' && !(isset($seg[2]) && ctype_digit($seg[2]))) {
    $pid = (int)($_GET['user'] ?? $uid) ?: $uid; if (!rp_can_see_person($u, $pid)) fail('Not allowed', 403);
    $emp = one('SELECT id,name,job_title FROM users WHERE id=?', [$pid]); if (!$emp) fail('Not found', 404);
    $mon = rp_week_start((string)(d($_GET['week'] ?? null) ?: $today)); $fri = rp_week_end($mon); $sun = date('Y-m-d', strtotime($mon . ' +6 days'));
    $rows = rp_weekly_rows($pid, $mon);
    $Wout = (['code' => 'ASL-HR-WR-001', 'rev' => 'Rev 01', 'employee' => $emp['name'], 'designation' => (string)$emp['job_title'], 'week_start' => $mon, 'week_end' => $fri, 'date' => $fri, 'rows' => array_map(function ($r) { unset($r['changed']); return $r; }, $rows),
         'people' => rp_global($u) ? all("SELECT id,name FROM users WHERE active=1 AND role<>'founder' ORDER BY name") : (rp_heads($u) ? all('SELECT id,name FROM users WHERE active=1 AND id IN ' . rp_member_sql(rp_heads($u)) . ' ORDER BY name') : [['id' => $uid, 'name' => $u['name']]]),
         'legend' => array_map(fn($k) => ['key' => $k, 'label' => RP_LEGEND[$k][0], 'short' => RP_LEGEND_SHORT[$k], 'color' => RP_LEGEND[$k][1]], array_keys(RP_LEGEND))]);
    // what the person already wrote for this week (auto-saved draft or submitted report) replaces the freshly built sheet; ?fresh=1 rebuilds it from the tasks
    rp_ew_ensure(); $saved = one("SELECT id,user_id,kind,edit_open_until,data,status,updated_at,submitted_at FROM weekly_reports WHERE user_id=? AND week_start=? AND kind='personal'", [$pid, $mon]); $state = null;
    $mode = $ro ? 'view' : ($pid === $uid ? 'owner' : ((rp_global($u) || rp_heads_user($u, $pid)) ? 'review' : 'view'));
    if ($saved) { $state = ['id' => (int)$saved['id'], 'status' => $saved['status'], 'updated_at' => $saved['updated_at'], 'submitted_at' => $saved['submitted_at']];
        if (empty($_GET['fresh']) && ($pid === $uid || $mode === 'review')) { $c = json_decode((string)$saved['data'], true);
            if (is_array($c) && isset($c['rows'])) { foreach (['code', 'rev', 'employee', 'designation', 'date', 'date_text', 'rows'] as $k) if (array_key_exists($k, $c)) $Wout[$k] = $c[$k]; $Wout['restored'] = true;
                if ($pid === $uid) {   // tasks that changed or were completed after the sheet was last saved are brought in: known rows get the new status, new ones are appended (rows the person deleted stay deleted)
                    $pos = []; foreach ($Wout['rows'] as $ix => $r0) if (!empty($r0['task_id'])) $pos[(int)$r0['task_id']] = $ix; $names = array_map(fn($r) => mb_strtolower(trim((string)($r['task'] ?? ''))), $Wout['rows']); $new = 0;
                    foreach ($rows as $fr) { if ((string)($fr['changed'] ?? '') <= (string)$saved['updated_at'] && empty($fr['carried'])) continue; $tid = (int)$fr['task_id'];   // an unfinished task from an earlier week always carries into the sheet
                        if (isset($pos[$tid])) { if (($Wout['rows'][$pos[$tid]]['status'] ?? '') !== $fr['status']) { $Wout['rows'][$pos[$tid]]['status'] = $fr['status']; $Wout['rows'][$pos[$tid]]['outstanding'] = $fr['outstanding']; $new++; } continue; }
                        if (in_array(mb_strtolower(trim($fr['task'])), $names, true)) continue; unset($fr['changed']); $Wout['rows'][] = $fr; $new++; }
                    if ($new) $Wout['added_from_tasks'] = $new; } } }
    }
    out($Wout + ['edit' => rp_edit_info($u, $saved ?: null), 'state' => $state, 'mode' => $mode, 'owner_id' => $pid, 'can_combine' => !$ro && (rp_global($u) || (bool)rp_heads($u))]);
}

function rp_weekly_clean(array $b, string $today): array {
    $T = fn($v, $n) => mb_substr(trim((string)($v ?? '')), 0, $n);
    $rows = []; foreach (array_slice((array)($b['rows'] ?? []), 0, 120) as $r) { if (!is_array($r)) continue;
        $cls = array_key_exists((string)($r['status'] ?? ''), RP_LEGEND) ? $r['status'] : 'view';
        $rows[] = ['task_id' => max(0, (int)($r['task_id'] ?? 0)), 'client' => $T($r['client'] ?? '', 200), 'project' => $T($r['project'] ?? '', 200), 'task' => $T($r['task'] ?? '', 400), 'status' => $cls, 'outstanding' => $T($r['outstanding'] ?? '', 800), 'comment' => $T($r['comment'] ?? '', 2000), 'review' => $T($r['review'] ?? '', 800)]; }
    $emp = $T($b['employee'] ?? '', 120); $des = $T($b['designation'] ?? '', 120); $code = $T($b['code'] ?? 'ASL-HR-WR-001', 40); $rev = $T($b['rev'] ?? 'Rev 01', 20); $dateTxt = $T($b['date_text'] ?? '', 40);
    $date = d($b['date'] ?? null) ?: $today;
    return ['code' => $code, 'rev' => $rev, 'employee' => $emp, 'designation' => $des, 'date' => $date, 'date_text' => $dateTxt, 'week_start' => (string)(d($b['week_start'] ?? null) ?: ''), 'rows' => $rows];
}
function rp_weekly_xlsx(array $c): string {
    require_once __DIR__ . '/xlsx.php';
    $rows = $c['rows']; $emp = $c['employee']; $des = $c['designation']; $code = $c['code']; $rev = $c['rev']; $dateTxt = $c['date_text'];
    $gray = 'BFBFBF'; $thin = ['border' => 'thin', 'wrap' => true];
    $W = [3.2, 5.3, 31.7, 35, 41.2, 14, 24.1, 30.9, 31.5]; $cells = []; $R = [];
    // header band with the logo
    for ($c = 2; $c <= 9; $c++) $R[1]['cells'][$c] = ['v' => null, 's' => ['fill' => $gray, 'border' => 'thin']];
    $R[1]['cells'][8] = ['v' => $code, 's' => ['b' => true, 'fill' => $gray, 'border' => 'thin', 'h' => 'right']]; $R[1]['cells'][9] = ['v' => $rev, 's' => ['fill' => $gray, 'border' => 'thin', 'h' => 'right']]; $R[1]['h'] = 54;
    for ($r = 2; $r <= 3; $r++) for ($c = 2; $c <= 9; $c++) $R[$r]['cells'][$c] = ['v' => null, 's' => ['border' => 'thin', 'sz' => 12]];
    $R[2]['cells'][2] = ['v' => 'TEAM WEEKLY REPORT', 's' => ['b' => true, 'sz' => 16, 'h' => 'center', 'border' => 'thin']];
    $R[2]['cells'][8] = ['v' => 'Employee name', 's' => ['b' => true, 'sz' => 12, 'border' => 'thin', 'fill' => $gray]]; $R[2]['cells'][9] = ['v' => $emp, 's' => ['b' => true, 'sz' => 12, 'border' => 'thin', 'wrap' => true]];
    $R[3]['cells'][8] = ['v' => 'Date', 's' => ['b' => true, 'sz' => 12, 'border' => 'thin', 'fill' => $gray]]; $R[3]['cells'][9] = ['v' => $dateTxt, 's' => ['b' => true, 'sz' => 12, 'border' => 'thin', 'h' => 'left']];
    $R[2]['h'] = $R[3]['h'] = 22.9;
    $heads = ['SN', 'Client/Category', 'Project', 'Task', 'Status', 'Outstanding', 'Suggestion/Comment', 'Management review'];
    foreach ($heads as $i => $h) $R[5]['cells'][$i + 2] = ['v' => $h, 's' => ['b' => true, 'sz' => 13, 'fill' => $gray, 'border' => 'thin', 'h' => $i === 0 || $i === 4 ? 'center' : 'left', 'wrap' => true]];
    $R[5]['h'] = 26.65;
    $n = max(12, count($rows)); $est = function (string $t, float $w): int { $lines = 0; foreach (explode("\n", $t) as $ln) $lines += max(1, (int)ceil(mb_strlen($ln) / max(4, $w * 0.95))); return $lines; };
    for ($i = 0; $i < $n; $i++) {
        $rn = 6 + $i; $row = $rows[$i] ?? null; $lines = 1;
        $R[$rn]['cells'][2] = ['v' => $row ? $i + 1 : ($i < 8 ? $i + 1 : null), 's' => $thin + ['h' => 'center']];
        foreach (['client' => 3, 'project' => 4, 'task' => 5] as $k => $col) { $R[$rn]['cells'][$col] = ['v' => $row[$k] ?? null, 's' => $thin + ['h' => 'left']]; if ($row) $lines = max($lines, $est($row[$k], $W[$col - 1])); }
        $R[$rn]['cells'][6] = $row ? ['v' => RP_LEGEND_SHORT[$row['status']], 's' => $thin + ['fill' => RP_LEGEND[$row['status']][1], 'h' => 'center', 'b' => true]] : ['v' => null, 's' => $thin];
        foreach (['outstanding' => 7, 'comment' => 8, 'review' => 9] as $k => $col) { $R[$rn]['cells'][$col] = ['v' => $row[$k] ?? null, 's' => $thin + ['h' => 'left']]; if ($row) $lines = max($lines, $est($row[$k], $W[$col - 1])); }
        $R[$rn]['h'] = max(21, min(300, 17 * $lines + 4));
    }
    $lr = 6 + $n + 1; $R[$lr]['cells'][3] = ['v' => '**LEGEND**', 's' => ['b' => true, 'border' => 'medium']]; $R[$lr]['cells'][4] = ['v' => null, 's' => ['border' => 'medium']];
    $k = 1; foreach (RP_LEGEND as $key => [$label, $col]) { $R[$lr + $k]['cells'][3] = ['v' => null, 's' => ['fill' => $col, 'border' => 'thin']]; $R[$lr + $k]['cells'][4] = ['v' => $label, 's' => ['border' => 'thin', 'wrap' => true]]; $k++; }
    $rb = $lr + 5; $R[$rb]['cells'][3] = ['v' => 'Reported by:', 's' => ['border' => 'medium']]; $R[$rb]['cells'][4] = ['v' => $emp, 's' => ['border' => 'medium']];
    $R[$rb + 1]['cells'][3] = ['v' => 'Designation:', 's' => ['border' => 'medium']]; $R[$rb + 1]['cells'][4] = ['v' => $des, 's' => ['border' => 'medium', 'wrap' => true]];
    $logo = (string)@file_get_contents(__DIR__ . '/../../assets/img/aerosub-logo.png'); $dim = @getimagesizefromstring($logo);
    $sheet = ['name' => 'Weekly report', 'cols' => $W, 'rows' => $R, 'merges' => ['B2:G3'], 'landscape' => true];
    if ($logo !== '' && $dim) $sheet['image'] = ['data' => $logo, 'col' => 1, 'row' => 0, 'w' => 190, 'h' => (int)round(190 * $dim[1] / $dim[0])];
    return xlsx_write($sheet);
}
function rp_weekly_filename(array $c): string { return 'Weekly_Report_' . (preg_replace('/[^A-Za-z0-9]+/', '_', $c['employee']) ?: 'Team') . '_' . date('d-M-Y', strtotime($c['date'])) . '.xlsx'; }
if ($method === 'POST' && $sub === 'weekly-xlsx') { // the edited sheet comes back from the page and is written out in the template's layout
    $c = rp_weekly_clean(body(), $today);
    try { $bin = rp_weekly_xlsx($c); } catch (RuntimeException $e) { fail($e->getMessage(), 501); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="' . rp_weekly_filename($c) . '"'); header('Content-Length: ' . strlen($bin)); header('Cache-Control: no-store'); echo $bin; exit;
}

/* ---------- sharing a weekly report ---------- */
function rp_legend_out(): array { return array_map(fn($k) => ['key' => $k, 'label' => RP_LEGEND[$k][0], 'short' => RP_LEGEND_SHORT[$k], 'color' => RP_LEGEND[$k][1]], array_keys(RP_LEGEND)); }
function rp_weekly_access(array $u, array $row): bool {
    if ((int)$row['user_id'] === (int)$u['id']) return true;
    if (($row['kind'] ?? 'personal') === 'combined') return (bool)one('SELECT 1 FROM weekly_shares WHERE weekly_id=? AND user_id=?', [$row['id'], $u['id']]);   // a combined report belongs to its author and the people it was shared with
    if (rp_global($u) || rp_heads_user($u, (int)$row['user_id'])) return true;
    return (bool)one('SELECT 1 FROM weekly_shares WHERE weekly_id=? AND user_id=?', [$row['id'], $u['id']]);
}
if ($method === 'GET' && ($seg[1] ?? '') === 'weekly' && isset($seg[2]) && ctype_digit($seg[2])) { // a saved (shared) weekly report
    $row = one('SELECT * FROM weekly_reports WHERE id=?', [(int)$seg[2]]); if (!$row || !rp_weekly_access($u, $row)) fail('Not found', 404);
    $c = json_decode((string)$row['data'], true) ?: [];
    $sh = one('SELECT s.note,s.created_at,sb.name AS shared_by FROM weekly_shares s LEFT JOIN users sb ON sb.id=s.shared_by WHERE s.weekly_id=? AND s.user_id=?', [$row['id'], $uid]);
    out($c + ['edit' => rp_edit_info($u, $row), 'kind' => $row['kind'], 'people' => [], 'legend' => rp_legend_out(), 'week_end' => rp_week_end($row['week_start']), 'mode' => $ro ? 'view' : ((int)$row['user_id'] === $uid ? 'owner' : ((($row['kind'] ?? 'personal') === 'personal' && (rp_global($u) || rp_heads_user($u, (int)$row['user_id']))) ? 'review' : 'view')),   // a combined report is only ever edited by its author; everyone it was shared with reads it 'owner_id' => (int)$row['user_id'], 'state' => ['id' => (int)$row['id'], 'status' => $row['status'], 'updated_at' => $row['updated_at'], 'submitted_at' => $row['submitted_at']],
        'saved' => ['id' => (int)$row['id'], 'owner_id' => (int)$row['user_id'], 'status' => $row['status'], 'submitted_at' => $row['submitted_at'], 'updated_at' => $row['updated_at'], 'shared_by' => $sh['shared_by'] ?? null, 'note' => $sh['note'] ?? null,
        'recipients' => all('SELECT u.name FROM weekly_shares s JOIN users u ON u.id=s.user_id WHERE s.weekly_id=? ORDER BY u.name', [$row['id']])]]);
}
if ($method === 'GET' && $sub === 'weekly-shared') { // lists for the weekly page
    $with = all('SELECT w.id,w.week_start,w.kind,u.name AS employee,s.note,s.created_at,sb.name AS shared_by FROM weekly_shares s JOIN weekly_reports w ON w.id=s.weekly_id JOIN users u ON u.id=w.user_id LEFT JOIN users sb ON sb.id=s.shared_by WHERE s.user_id=? ORDER BY s.created_at DESC LIMIT 30', [$uid]);
    $mine = all("SELECT w.id,w.week_start,w.updated_at,w.status,w.submitted_at,(SELECT COUNT(*) FROM weekly_shares s WHERE s.weekly_id=w.id) AS shares FROM weekly_reports w WHERE w.user_id=? AND w.kind='personal' ORDER BY w.week_start DESC LIMIT 12", [$uid]);
    $combined = array_map(fn($r) => ['id' => (int)$r['id'], 'week_start' => $r['week_start'], 'updated_at' => $r['updated_at'], 'shares' => (int)$r['shares'], 'title' => (json_decode((string)$r['data'], true)['employee'] ?? 'Combined weekly report')], all("SELECT w.id,w.week_start,w.updated_at,w.data,(SELECT COUNT(*) FROM weekly_shares s WHERE s.weekly_id=w.id) AS shares FROM weekly_reports w WHERE w.user_id=? AND w.kind='combined' ORDER BY w.updated_at DESC LIMIT 12", [$uid])); $team = [];
    if (rp_global($u) || rp_heads($u)) $team = all("SELECT w.id,w.week_start,w.updated_at,w.submitted_at,w.status,u.name AS employee FROM weekly_reports w JOIN users u ON u.id=w.user_id WHERE w.kind='personal' AND w.user_id<>? " . (rp_global($u) ? '' : 'AND w.user_id IN ' . rp_member_sql(rp_heads($u))) . ' ORDER BY COALESCE(w.submitted_at,w.updated_at) DESC LIMIT 30', [$uid]);
    out(['with_me' => $with, 'mine' => $mine, 'combined' => $combined, 'team' => $team]);
}
/** People a head may combine reports from (their department members; administrators: everyone), with the state of their report for the week. */
function rp_combine_people(array $u, string $week): array {
    $uid = (int)$u['id']; $ppl = rp_global($u) ? all("SELECT id,name FROM users WHERE active=1 AND role<>'founder' ORDER BY name") : (rp_heads($u) ? all('SELECT id,name FROM users WHERE active=1 AND id IN ' . rp_member_sql(rp_heads($u)) . ' ORDER BY name') : []);
    $st = []; foreach (all("SELECT user_id,status FROM weekly_reports WHERE week_start=? AND kind='personal'", [$week]) as $r) $st[(int)$r['user_id']] = $r['status'];
    $cnt = []; foreach ($ppl as $p) $cnt[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'status' => $st[(int)$p['id']] ?? 'none'];
    return $cnt;
}
if ($method === 'GET' && $sub === 'weekly-team') {
    if (!(rp_global($u) || rp_heads($u))) fail('Only heads of department can combine reports', 403);
    $week = rp_week_start((string)(d($_GET['week'] ?? null) ?: $today)); out(['week_start' => $week, 'people' => rp_combine_people($u, $week)]);
}
if ($method === 'POST' && $sub === 'weekly-combine') {
    if ($ro) fail('Founder accounts are read-only', 403); if (!(rp_global($u) || rp_heads($u))) fail('Only heads of department can combine reports', 403);
    $b = body(); $week = rp_week_start((string)(d($b['week'] ?? null) ?: $today)); $ids = array_values(array_unique(array_filter(array_map('intval', (array)($b['user_ids'] ?? [])))));
    if (!$ids) fail('Choose at least one person'); if (count($ids) > 40) fail('Choose 40 people or fewer');
    $allowed = array_column(rp_combine_people($u, $week), 'id'); foreach ($ids as $x) if (!in_array($x, $allowed, true)) fail('You can only combine reports of people in your departments', 403);
    $mon = $week; $fri = rp_week_end($mon); $rows = []; $names = [];
    foreach (all('SELECT id,name FROM users WHERE id IN (' . implode(',', $ids) . ') ORDER BY name') as $m) {
        $sv = one("SELECT data FROM weekly_reports WHERE user_id=? AND week_start=? AND kind='personal'", [$m['id'], $mon]); $mr = null;
        if ($sv) { $cc = json_decode((string)$sv['data'], true); if (is_array($cc) && !empty($cc['rows'])) $mr = $cc['rows']; }
        if ($mr === null) $mr = rp_weekly_rows((int)$m['id'], $mon);
        foreach ($mr as $r) { $r['client'] = $m['name'] . ($r['client'] !== '' ? ' · ' . $r['client'] : ''); $rows[] = $r; } $names[] = $m['name']; }
    if (!$rows) fail('The selected people have nothing to report for this week');
    $title = s($b['title'] ?? '', 120) ?: 'Combined weekly report (' . count($names) . ' staff)';
    $c = ['code' => 'ASL-HR-WR-001', 'rev' => 'Rev 01', 'employee' => $title, 'designation' => (string)($u['job_title'] ?: 'Head of Department'), 'date' => $fri, 'date_text' => '', 'week_start' => $week, 'rows' => rp_weekly_clean(['rows' => $rows], $today)['rows'], 'kind' => 'combined', 'members' => $names, 'member_ids' => $ids, 'user_id' => $uid];
    for ($try = 0; $try < 5; $try++) { try { q("INSERT INTO weekly_reports (user_id,week_start,data,status,submitted_at,kind,ukey) VALUES (?,?,?,'submitted',NOW(),'combined',?)", [$uid, $week, json_encode($c, JSON_UNESCAPED_UNICODE), mt_rand(1, 2000000000)]); break; } catch (PDOException $e) { if ($try === 4) throw $e; } }
    $wid = (int)db()->lastInsertId(); log_act($uid, 'weekly_combined', count($names) . ' people ' . $week); out(['id' => $wid, 'rows' => count($rows), 'members' => $names], 201);
}
/** Rows typed into a submitted weekly report that are not on the task list yet become tasks of the person (so they show in tasks, reports and analytics).
 *  Rows that match one of the person's tasks are linked to it. Status changes made in the report follow only for tasks that came from a weekly report. */
function rp_weekly_sync_tasks(int $uid, array &$rows, string $week, array $emp): array {
    $created = 0; $map = ['done' => 'done', 'lost' => 'canceled', 'view' => 'in_progress'];
    $mine = []; $byTitle = []; foreach (all('SELECT id,title,status,source FROM tasks WHERE assignee_id=?', [$uid]) as $t) { $mine[(int)$t['id']] = $t; $byTitle[mb_strtolower(trim($t['title']))] = (int)$t['id']; }
    foreach ($rows as $i => $r) {
        $title = trim((string)($r['task'] ?? '')); if ($title === '') continue; $tid = (int)($r['task_id'] ?? 0); $st = $map[$r['status'] ?? 'view'] ?? 'in_progress';
        if ($tid && !isset($mine[$tid])) { $tid = 0; }                           // the id belongs to someone else's task: ignore it
        if (!$tid && isset($byTitle[mb_strtolower($title)])) $tid = $byTitle[mb_strtolower($title)];
        if (!$tid) {
            $co = !empty($r['client']) ? one('SELECT id FROM companies WHERE LOWER(name)=LOWER(?) LIMIT 1', [$r['client']]) : null; $pr = !empty($r['project']) ? one('SELECT id FROM projects WHERE LOWER(name)=LOWER(?) LIMIT 1', [$r['project']]) : null;
            q("INSERT INTO tasks (title,description,status,priority,assignee_id,creator_id,department_id,company_id,project_id,task_type,source,completed_at) VALUES (?,?,?,'medium',?,?,?,?,?,'general','weekly'," . ($st === 'done' ? 'NOW()' : 'NULL') . ')',
              [mb_substr($title, 0, 200), 'Added from the weekly report for the week of ' . date('j M Y', strtotime($week)) . (!empty($r['outstanding']) ? "\n" . $r['outstanding'] : ''), $st, $uid, $uid, $emp['department_id'] ?: null, $co['id'] ?? null, $pr['id'] ?? null]);
            $tid = (int)db()->lastInsertId(); $mine[$tid] = ['id' => $tid, 'title' => $title, 'status' => $st, 'source' => 'weekly']; $byTitle[mb_strtolower($title)] = $tid; $created++;
        } elseif (($mine[$tid]['source'] ?? '') === 'weekly' && $mine[$tid]['status'] !== $st && in_array($mine[$tid]['status'], ['todo', 'in_progress', 'done', 'canceled'], true)) {
            q('UPDATE tasks SET status=?, completed_at=' . ($st === 'done' ? 'COALESCE(completed_at,NOW())' : 'NULL') . ' WHERE id=?', [$st, $tid]); $mine[$tid]['status'] = $st; }
        $rows[$i]['task_id'] = $tid;
    }
    if ($created) log_act($uid, 'weekly_tasks_created', $created . ' task(s) from the weekly report of ' . $week);
    return ['created' => $created];
}
/* ---------- editing a submitted weekly report: ask, then the head approves ---------- */
if ($method === 'POST' && $sub === 'weekly-edit-request') {
    rp_ew_ensure(); $b = body(); $row = one("SELECT * FROM weekly_reports WHERE id=? AND kind='personal' AND user_id=?", [(int)($b['weekly_id'] ?? 0), $uid]); if (!$row) fail('Not found', 404);
    if (!rp_edit_locked($u, $row)) fail($row['status'] === 'submitted' ? 'You can edit this report right now.' : 'This report has not been submitted, so you can edit it freely.', 409);
    $ex = one("SELECT id FROM weekly_edit_requests WHERE weekly_id=? AND status='pending'", [$row['id']]); if ($ex) out(['ok' => true, 'id' => (int)$ex['id'], 'existing' => true]);
    $reason = mb_substr(trim((string)($b['reason'] ?? '')), 0, 500); if ($reason === '') fail('Say what you need to change');
    q('INSERT INTO weekly_edit_requests (weekly_id,user_id,reason) VALUES (?,?,?)', [$row['id'], $uid, $reason]); $rid = (int)db()->lastInsertId();
    $emp = one('SELECT department_id FROM users WHERE id=?', [$uid]); $depts = user_dept_ids($uid, (int)($emp['department_id'] ?? 0)); $heads = $depts ? array_column(all('SELECT DISTINCT head_id FROM departments WHERE head_id IS NOT NULL AND id IN (' . implode(',', array_map('intval', $depts)) . ')'), 'head_id') : [];
    if (!$heads) $heads = array_column(all("SELECT id FROM users WHERE role='super_admin' AND active=1 AND archived_at IS NULL LIMIT 3"), 'id');   // no head set: an administrator decides
    $told = 0; foreach (array_unique(array_map('intval', $heads)) as $h) { if ($h === $uid) continue; notify($h, 'weekly_edit_request', $u['name'] . ' asks to edit a submitted weekly report', 'Week of ' . date('j M', strtotime($row['week_start'])) . ' · ' . mb_substr($reason, 0, 120), '#/reports/weekly/' . $row['id']); $told++; }
    log_act($uid, 'weekly_edit_requested', $row['week_start']); out(['ok' => true, 'id' => $rid, 'notified' => $told], 201);
}
if ($method === 'POST' && $sub === 'weekly-edit-decide') {
    rp_ew_ensure(); $b = body(); $rq = one("SELECT r.*, w.user_id AS owner, w.week_start FROM weekly_edit_requests r JOIN weekly_reports w ON w.id=r.weekly_id WHERE r.id=?", [(int)($b['id'] ?? 0)]); if (!$rq) fail('Not found', 404);
    if (!(rp_global($u) || rp_heads_user($u, (int)$rq['owner']))) fail('Only the department head can decide this', 403); if ((int)$rq['owner'] === $uid && !rp_edit_exempt($u)) fail('You cannot approve your own request', 403); if ($rq['status'] !== 'pending') fail('This request was already ' . $rq['status'], 409);
    $ok = ($b['decision'] ?? '') === 'approve'; $hours = max(1, min(168, (int)($b['hours'] ?? 24))); $note = mb_substr(trim((string)($b['note'] ?? '')), 0, 300);
    q("UPDATE weekly_edit_requests SET status=?, decided_by=?, decided_at=NOW(), note=? WHERE id=?", [$ok ? 'approved' : 'denied', $uid, $note ?: null, $rq['id']]);
    if ($ok) q('UPDATE weekly_reports SET edit_open_until=DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id=?', [$hours, $rq['weekly_id']]);
    notify((int)$rq['user_id'], 'weekly_edit_decided', $ok ? 'You can edit your weekly report' : 'Your request to edit the weekly report was declined', $u['name'] . ($ok ? ' approved it: you can edit for ' . $hours . ' hour' . ($hours === 1 ? '' : 's') . '.' : ' declined it.') . ($note !== '' ? ' ' . $note : ''), '#/reports/weekly/' . $rq['weekly_id']);
    log_act($uid, $ok ? 'weekly_edit_approved' : 'weekly_edit_denied', $rq['week_start']); out(['ok' => true, 'approved' => $ok, 'hours' => $ok ? $hours : 0]);
}
/** A reviewer (department head, administrator) may only write the Comment and Management review columns: everything else stays as the person wrote it. */
function rp_weekly_review_merge(array $stored, array $incoming): array {
    $rows = $stored['rows'] ?? []; foreach ($rows as $i => $r) { $in = $incoming['rows'][$i] ?? null; if ($in) { $rows[$i]['comment'] = $in['comment'] ?? $r['comment']; $rows[$i]['review'] = $in['review'] ?? $r['review']; } }
    $stored['rows'] = $rows; return $stored;
}
/** Autosave and Submit. The owner writes the whole sheet; a department head (or administrator) can only add comments and the management review to a SUBMITTED report. */
if ($method === 'POST' && $sub === 'weekly-save') {
    if ($ro) fail('Founder accounts are read-only', 403);
    $b = body();
    if (!empty($b['id'])) {   // a combined team report: only its author edits it
        $row = one("SELECT * FROM weekly_reports WHERE id=? AND kind='combined' AND user_id=?", [(int)$b['id'], $uid]); if (!$row) fail('Not found', 404);
        $c = rp_weekly_clean($b, $today); $old = json_decode((string)$row['data'], true) ?: []; $c['week_start'] = $row['week_start']; $c['user_id'] = $uid; $c['kind'] = 'combined'; foreach (['members', 'member_ids'] as $k) if (isset($old[$k])) $c[$k] = $old[$k];
        q('UPDATE weekly_reports SET data=?, updated_at=NOW() WHERE id=?', [json_encode($c, JSON_UNESCAPED_UNICODE), $row['id']]);
        out(['ok' => true, 'state' => one('SELECT id,status,updated_at,submitted_at FROM weekly_reports WHERE id=?', [$row['id']]), 'notified' => 0]);
    }
    $pid = (int)($b['user_id'] ?? 0) ?: $uid; $emp = one('SELECT id,name,department_id FROM users WHERE id=?', [$pid]); if (!$emp) fail('Not found', 404);
    $c = rp_weekly_clean($b, $today); $week = rp_week_start((string)($c['week_start'] ?: $c['date'])); $c['week_start'] = $week; $c['user_id'] = $pid; if ($c['employee'] === '') $c['employee'] = $emp['name'];
    rp_ew_ensure(); $row = one("SELECT * FROM weekly_reports WHERE user_id=? AND week_start=? AND kind='personal'", [$pid, $week]); $submit = !empty($b['submit']);
    if ($pid === $uid && rp_edit_locked($u, $row)) out(['error' => 'This report was submitted. Ask your department head for approval to edit it.', 'locked' => true], 403);
    if ($pid !== $uid) {   // review by a department head / administrator
        if (!(rp_global($u) || rp_heads_user($u, $pid))) fail('You can only comment on reports of people in your departments', 403);
        if (!$row || $row['status'] !== 'submitted') fail($emp['name'] . ' has not submitted this report yet', 409);
        $stored = json_decode((string)$row['data'], true) ?: []; $new = rp_weekly_review_merge($stored, $c); $old = json_encode($stored['rows'] ?? []);
        $new['reviewed_by'] = $u['name']; $new['reviewed_at'] = date('c');
        if (json_encode($new['rows']) !== $old) {
            q('UPDATE weekly_reports SET data=?, updated_at=NOW() WHERE id=?', [json_encode($new, JSON_UNESCAPED_UNICODE), $row['id']]);
            $t = $u['name'] . ' commented on your weekly report'; if (!one("SELECT id FROM notifications WHERE user_id=? AND type='weekly_reviewed' AND title=? AND created_at > (NOW() - INTERVAL 2 HOUR)", [$pid, $t])) notify($pid, 'weekly_reviewed', $t, 'Week of ' . date('j M', strtotime($week)), '#/reports/weekly/' . $row['id']);
            log_act($uid, 'weekly_reviewed', $emp['name'] . ' ' . $week); }
        $r2 = one('SELECT id,status,updated_at,submitted_at FROM weekly_reports WHERE id=?', [$row['id']]); out(['ok' => true, 'state' => $r2]);
    }
    if ($submit && !$c['rows']) fail('There is nothing in the report yet. Add at least one row before submitting.');
    if ($row && $row['status'] === 'submitted') {   // management comments written after submission are kept
        $stored = json_decode((string)$row['data'], true) ?: []; $key = fn($r) => mb_strtolower(($r['client'] ?? '') . '|' . ($r['project'] ?? '') . '|' . ($r['task'] ?? '')); $rv = [];
        foreach ($stored['rows'] ?? [] as $r) if (($r['review'] ?? '') !== '') $rv[$key($r)] = $r['review'];
        foreach ($c['rows'] as $i => $r) if (isset($rv[$key($r)])) $c['rows'][$i]['review'] = $rv[$key($r)];
        foreach (['reviewed_by', 'reviewed_at'] as $k) if (isset($stored[$k])) $c[$k] = $stored[$k];
    }
    $first = !($row && $row['status'] === 'submitted') && $submit; $sync = ['created' => 0];
    if ($submit || ($row && $row['status'] === 'submitted')) $sync = rp_weekly_sync_tasks($uid, $c['rows'], $week, $emp);
    q("INSERT INTO weekly_reports (user_id,week_start,data,status,submitted_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data), status=IF(status='submitted' OR VALUES(status)='submitted','submitted','draft'), submitted_at=IF(VALUES(status)='submitted' AND submitted_at IS NULL, NOW(), submitted_at), updated_at=NOW()",
      [$pid, $week, json_encode($c, JSON_UNESCAPED_UNICODE), $submit ? 'submitted' : 'draft', $submit ? date('Y-m-d H:i:s') : null]);
    $r2 = one("SELECT id,status,updated_at,submitted_at FROM weekly_reports WHERE user_id=? AND week_start=? AND kind='personal'", [$pid, $week]); $told = 0;
    if ($first) {   // tell the head(s) of the person's department(s)
        $depts = user_dept_ids($pid, (int)($emp['department_id'] ?? 0)); $heads = $depts ? array_column(all('SELECT DISTINCT head_id FROM departments WHERE head_id IS NOT NULL AND id IN (' . implode(',', array_map('intval', $depts)) . ')'), 'head_id') : [];
        foreach (array_unique(array_map('intval', $heads)) as $h) { if ($h === $uid) continue; notify($h, 'weekly_submitted', $emp['name'] . ' submitted the weekly report', 'Week of ' . date('j M', strtotime($week)) . ' · ' . count($c['rows']) . ' item' . (count($c['rows']) === 1 ? '' : 's'), '#/reports/weekly/' . $r2['id']); $told++; }
        log_act($uid, 'weekly_submitted', $emp['name'] . ' ' . $week); }
    out(['ok' => true, 'state' => $r2, 'notified' => $told, 'tasks_created' => $sync['created'], 'row_task_ids' => array_map(fn($r) => (int)($r['task_id'] ?? 0), $c['rows'])]);
}
if ($method === 'POST' && $sub === 'weekly-share') {
    if ($ro) fail('Founder accounts are read-only', 403);
    $b = body(); $pid = (int)($b['user_id'] ?? 0) ?: $uid;
    if ($pid !== $uid && !($u['role'] === 'super_admin' || rp_heads_user($u, $pid))) fail('You can only share your own weekly report', 403);
    $emp = one('SELECT id,name FROM users WHERE id=?', [$pid]); if (!$emp) fail('Not found', 404);
    $c = rp_weekly_clean($b, $today); $week = rp_week_start((string)($c['week_start'] ?: $c['date'])); $c['week_start'] = $week; $c['user_id'] = $pid; if ($c['employee'] === '') $c['employee'] = $emp['name'];
    if (!$c['rows']) fail('There is nothing in the report yet');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($b['user_ids'] ?? [])))));  $ids = array_values(array_diff($ids, [$uid, $pid])); if (!$ids) fail('Choose at least one person to share it with');
    $combinedId = 0; if (!empty($b['id'])) { $crow = one("SELECT id,data FROM weekly_reports WHERE id=? AND kind='combined' AND user_id=?", [(int)$b['id'], $uid]); if (!$crow) fail('Not found', 404); $combinedId = (int)$crow['id']; $old = json_decode((string)$crow['data'], true) ?: []; foreach (['members', 'member_ids'] as $k) if (isset($old[$k])) $c[$k] = $old[$k]; $c['kind'] = 'combined'; }
    if ($pid !== $uid && !$combinedId) { $st0 = one("SELECT data,status FROM weekly_reports WHERE user_id=? AND week_start=? AND kind='personal'", [$pid, $week]); if (!$st0 || $st0['status'] !== 'submitted') fail($emp['name'] . ' has not submitted this report yet. Wait until it is submitted, or use "Combine team reports" to build one from tasks.', 409); if ($st0) $c = rp_weekly_review_merge(json_decode((string)$st0['data'], true) ?: [], $c) + ['user_id' => $pid]; }
    if (!$combinedId && $pid === $uid) rp_weekly_sync_tasks($uid, $c['rows'], $week, $emp);
    if ($combinedId) { q('UPDATE weekly_reports SET data=?, updated_at=NOW() WHERE id=?', [json_encode($c, JSON_UNESCAPED_UNICODE), $combinedId]); $wid = $combinedId; }
    else { q("INSERT INTO weekly_reports (user_id,week_start,data,status,submitted_at) VALUES (?,?,?,'submitted',NOW()) ON DUPLICATE KEY UPDATE data=VALUES(data), status='submitted', submitted_at=COALESCE(submitted_at,NOW()), updated_at=NOW()", [$pid, $week, json_encode($c, JSON_UNESCAPED_UNICODE)]);
        $wid = (int)one("SELECT id FROM weekly_reports WHERE user_id=? AND week_start=? AND kind='personal'", [$pid, $week])['id']; }
    $note = s($b['note'] ?? '', 500); $email = !empty($b['email']); $bin = null;
    require_once __DIR__ . '/accounts.php'; require_once __DIR__ . '/campcore.php';
    if ($email) { try { $bin = rp_weekly_xlsx($c); } catch (Throwable $e) { $bin = null; } }
    $link = camp_base() . '/#/reports/weekly/' . $wid; $total = count($c['rows']); $done = count(array_filter($c['rows'], fn($r) => $r['status'] === 'done')); $lost = count(array_filter($c['rows'], fn($r) => $r['status'] === 'lost'));
    $names = []; $sent = 0; $failed = 0;
    require_once __DIR__ . '/sharemsg.php'; $msg = sm_create('weekly', $wid, $u, $b, $ids); if ($msg) $note = mb_substr(preg_replace('/\s+/', ' ', $msg['text']), 0, 500);
    foreach ($ids as $x) {
        $r = one('SELECT id,name,email FROM users WHERE id=? AND active=1', [$x]); if (!$r) continue;
        q('INSERT INTO weekly_shares (weekly_id,user_id,shared_by,note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE shared_by=VALUES(shared_by), note=VALUES(note), created_at=NOW()', [$wid, $x, $uid, $note ?: null]);
        notify($x, 'weekly_shared', $u['name'] . ' shared a weekly report' . ($pid !== $uid ? ' for ' . $emp['name'] : ''), 'Week of ' . date('j M', strtotime($week)) . ' · ' . $total . ' item' . ($total === 1 ? '' : 's') . ($note ? ' — ' . mb_substr($note, 0, 100) : ''), '#/reports/weekly/' . $wid);
        $names[] = $r['name'];
        if ($email && $r['email']) {
            $subj = 'Weekly report: ' . $c['employee'] . ' · week of ' . date('j M Y', strtotime($week));
            $text = "Hello " . $r['name'] . ",\n\n" . $u['name'] . " shared the weekly report of " . $c['employee'] . " (week of " . date('j M Y', strtotime($week)) . ") with you.\n" . $total . " item(s), " . $done . " delivered/closed out, " . $lost . " lost/nil.\n" . ($note ? "\nNote: " . $note . "\n" : '') . "\nOpen it in Aerosub Office: " . $link . "\n" . ($bin ? "The Excel file is attached.\n" : '');
            $html = '<p>Hello ' . htmlspecialchars($r['name']) . ',</p><p><b>' . htmlspecialchars($u['name']) . '</b> shared the weekly report of <b>' . htmlspecialchars($c['employee']) . '</b> (week of ' . date('j M Y', strtotime($week)) . ') with you.</p><p>' . $total . ' item(s) · ' . $done . ' delivered / closed out · ' . $lost . ' lost / nil</p>' . ($note ? '<p style="border-left:3px solid #00A3DE;padding-left:10px;color:#444">' . nl2br(htmlspecialchars($note)) . '</p>' : '') . '<p><a href="' . htmlspecialchars($link) . '">Open the report in Aerosub Office</a>' . ($bin ? ' (the Excel file is attached)' : '') . '</p>';
            $atts = $bin ? [['name' => rp_weekly_filename($c), 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'data' => $bin]] : []; $inl = [];
            if ($msg) { $info = '<p>' . $total . ' item(s) · ' . $done . ' delivered / closed out · ' . $lost . ' lost / nil</p>'; [$html, $text] = sm_mail($msg, $u, $r, 'the weekly report of ' . $c['employee'] . ' (week of ' . date('j M Y', strtotime($week)) . ')', $link, $info); $atts = array_merge($atts, $msg['mail_files']); $inl = array_values($msg['mail_inline']); }
            if (acct_send_mail($r['email'], $subj, $html, $text, $atts, ['from_name' => $u['name'], 'reply_to' => $u['email'], 'inline' => $inl])) { $sent++; q('UPDATE weekly_shares SET emailed=1 WHERE weekly_id=? AND user_id=?', [$wid, $x]); } else $failed++;
        }
    }
    if (!$names) { if ($msg) sm_delete($msg['id']); fail('Choose at least one active person'); }
    log_act($uid, 'weekly_shared', $c['employee'] . ' ' . $week);
    if (!$names && $msg) sm_delete($msg['id']);
    out(['ok' => true, 'id' => $wid, 'shared' => $names, 'emailed' => $sent, 'email_failed' => $failed, 'link' => $link, 'message_id' => $msg['id'] ?? null]);
}

/* ---------- team performance (for heads, managers, admins) ---------- */
if ($method === 'GET' && $sub === 'performance') {
    need_tab($u, 'performance', 'Team performance is only shown to people the Root Admin has given it to');
    $global = rp_global($u); $managed = rp_heads($u);
    // Administrators and the Founder see every department; a department head sees their own; anyone else the Root Admin chose sees everyone
    $everyone = $global || !$managed;
    $days = in_array((int)($_GET['days'] ?? 30), [7, 14, 30, 60, 90], true) ? (int)$_GET['days'] : 30; $to = $today; $from = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
    $cf = d($_GET['from'] ?? null); $ct = d($_GET['to'] ?? null);   // a custom range (up to a year)
    if ($cf) { $to = min($ct ?: $today, $today); $from = min($cf, $to); if ($from < date('Y-m-d', strtotime($to . ' -365 days'))) $from = date('Y-m-d', strtotime($to . ' -365 days')); $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1; }
    $depts = $everyone ? all('SELECT id,name FROM departments ORDER BY name') : all('SELECT id,name FROM departments WHERE id IN (' . implode(',', $managed) . ') ORDER BY name'); $dids = array_map('intval', array_column($depts, 'id'));
    $fd = (int)($_GET['department'] ?? 0); if ($fd && !in_array($fd, $dids, true)) $fd = 0;
    $people = $fd ? all("SELECT id,name,job_title FROM users WHERE active=1 AND role<>'founder' AND id IN " . rp_member_sql([$fd]) . ' ORDER BY name')
        : ($everyone ? all("SELECT id,name,job_title FROM users WHERE active=1 AND role<>'founder' ORDER BY name") : all("SELECT id,name,job_title FROM users WHERE active=1 AND role<>'founder' AND id IN " . rp_member_sql($managed) . ' ORDER BY name'));
    $ids = array_map(fn($x) => (int)$x['id'], $people); $in = $ids ? implode(',', $ids) : '0';
    $rep = []; foreach (all("SELECT user_id, COUNT(*) n, COUNT(DISTINCT report_date) dd, COALESCE(SUM(hours),0) h, COALESCE(AVG(progress),0) pr FROM daily_reports WHERE user_id IN ($in) AND report_date BETWEEN ? AND ? GROUP BY user_id", [$from, $to]) as $r) $rep[(int)$r['user_id']] = $r;
    $dn = []; foreach (all("SELECT assignee_id, COUNT(*) n, SUM(due_date IS NULL OR DATE(completed_at) <= due_date) ontime FROM tasks WHERE status='done' AND assignee_id IN ($in) AND DATE(completed_at) BETWEEN ? AND ? GROUP BY assignee_id", [$from, $to]) as $r) $dn[(int)$r['assignee_id']] = $r;
    $op = []; foreach (all("SELECT assignee_id, SUM(status='todo') todo, SUM(status='in_progress') prog, SUM(status='blocked') blk, SUM(status='deferred') def, SUM(status IN ('todo','in_progress','blocked') AND due_date < CURDATE()) late FROM tasks WHERE assignee_id IN ($in) GROUP BY assignee_id") as $r) $op[(int)$r['assignee_id']] = $r;
    // Items people entered in their weekly reports count alongside tasks: a row with status Delivered counts as completed, In view as in progress, Nil/Lost as lost.
    // Rows that are just a task from the task list are skipped (the task itself is already counted), and the same item repeated in several weeks counts once.
    $titles = []; foreach (all("SELECT assignee_id, LOWER(TRIM(title)) t FROM tasks WHERE assignee_id IN ($in)") as $r) $titles[(int)$r['assignee_id']][$r['t']] = 1;
    $seen = []; foreach (all("SELECT user_id,data FROM weekly_reports WHERE kind='personal' AND user_id IN ($in) AND week_start BETWEEN ? AND ? ORDER BY week_start", [date('Y-m-d', strtotime($from . ' -6 days')), $to]) as $r) {
        $c = json_decode((string)$r['data'], true); foreach ((array)($c['rows'] ?? []) as $x) { $tt = mb_strtolower(trim((string)($x['task'] ?? ''))); if ($tt === '' || isset($titles[(int)$r['user_id']][$tt])) continue; $seen[(int)$r['user_id']][$tt] = (string)($x['status'] ?? 'view'); } }
    $wk = []; foreach ($seen as $uid2 => $items) { $w = ['done' => 0, 'view' => 0, 'lost' => 0]; foreach ($items as $st) { $k = array_key_exists($st, $w) ? $st : 'view'; $w[$k]++; } $wk[$uid2] = $w; }
    $work = max(1, ww_workdays($from, $to)); $weeksIn = ww_weeks($from, $to);   // working days and weeks in the period (set by the Super Admin)
    $wkSub = []; if ($ids && $weeksIn) foreach (all("SELECT user_id, SUM(status='submitted') s, COUNT(*) n FROM weekly_reports WHERE kind='personal' AND user_id IN ($in) AND week_start IN (" . implode(',', array_fill(0, count($weeksIn), '?')) . ') GROUP BY user_id', $weeksIn) as $r0) $wkSub[(int)$r0['user_id']] = $r0;
    $rows = []; $tot = ['reports' => 0, 'done' => 0, 'hours' => 0.0, 'blocked' => 0, 'late' => 0, 'wk_done' => 0, 'wk_view' => 0, 'wk_lost' => 0, 'weeks_submitted' => 0];
    foreach ($people as $p) { $i = (int)$p['id']; $r = $rep[$i] ?? null; $d = $dn[$i] ?? null; $o = $op[$i] ?? null;
        $row = ['id' => $i, 'name' => $p['name'], 'reports' => (int)($r['n'] ?? 0), 'days_reported' => (int)($r['dd'] ?? 0), 'consistency' => round(min(1, (int)($r['dd'] ?? 0) / $work) * 100), 'hours' => round((float)($r['h'] ?? 0), 1), 'avg_progress' => (int)round((float)($r['pr'] ?? 0)),
                'done' => (int)($d['n'] ?? 0), 'on_time' => isset($d['n']) && (int)$d['n'] ? (int)round(((int)$d['ontime']) * 100 / (int)$d['n']) : null, 'todo' => (int)($o['todo'] ?? 0), 'in_progress' => (int)($o['prog'] ?? 0), 'blocked' => (int)($o['blk'] ?? 0), 'deferred' => (int)($o['def'] ?? 0), 'late' => (int)($o['late'] ?? 0)];
        $row['weeks_submitted'] = (int)($wkSub[$i]['s'] ?? 0); $row['weeks_saved'] = (int)($wkSub[$i]['n'] ?? 0); $tot['weeks_submitted'] += $row['weeks_submitted'];
        $w = $wk[$i] ?? ['done' => 0, 'view' => 0, 'lost' => 0]; $row['wk'] = $w; $row['wk_total'] = $w['done'] + $w['view'] + $w['lost']; $row['done'] += $w['done']; $row['in_progress'] += $w['view'];
        $tot['wk_done'] += $w['done']; $tot['wk_view'] += $w['view']; $tot['wk_lost'] += $w['lost'];
        $tot['reports'] += $row['reports']; $tot['done'] += $row['done']; $tot['hours'] += $row['hours']; $tot['blocked'] += $row['blocked']; $tot['late'] += $row['late']; $rows[] = $row; }
    $byDay = array_column(all("SELECT report_date dte, COUNT(*) c FROM daily_reports WHERE user_id IN ($in) AND report_date BETWEEN ? AND ? GROUP BY report_date", [$from, $to]), 'c', 'dte');
    $dDay = array_column(all("SELECT DATE(completed_at) dte, COUNT(*) c FROM tasks WHERE status='done' AND assignee_id IN ($in) AND DATE(completed_at) BETWEEN ? AND ? GROUP BY DATE(completed_at)", [$from, $to]), 'c', 'dte');
    $series = []; for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) { $k = date('Y-m-d', $t); $series[] = ['date' => $k, 'reports' => (int)($byDay[$k] ?? 0), 'done' => (int)($dDay[$k] ?? 0)]; }
    $status = array_map('intval', one("SELECT COALESCE(SUM(status='todo'),0) todo, COALESCE(SUM(status='in_progress'),0) in_progress, COALESCE(SUM(status='blocked'),0) blocked, COALESCE(SUM(status='deferred'),0) deferred, COALESCE(SUM(status='canceled' AND updated_at >= ?),0) canceled, COALESCE(SUM(status='done' AND completed_at >= ?),0) done, COALESCE(SUM(status='transferred' AND updated_at >= ?),0) transferred FROM tasks WHERE assignee_id IN ($in)", [$from . ' 00:00:00', $from . ' 00:00:00', $from . ' 00:00:00']) ?: []);
    $status['done'] += $tot['wk_done']; $status['in_progress'] += $tot['wk_view']; $status['canceled'] += $tot['wk_lost'];
    out(['days' => $days, 'from' => $from, 'to' => $to, 'workdays' => $work, 'weeks' => count($weeksIn), 'week' => ww_out(), 'departments' => $depts, 'department' => $fd, 'people' => $rows, 'series' => $series, 'status' => $status, 'totals' => $tot]);
}

/* ---------- history ---------- */
if ($method === 'GET' && $id === null && $sub === null) {
    [$rs, $rp] = rp_scope($u); $w = [$rs]; $p = $rp;
    if (!empty($_GET['task'])) { $w[] = 'r.task_id=?'; $p[] = (int)$_GET['task']; }
    if (!empty($_GET['user'])) { $w[] = 'r.user_id=?'; $p[] = (int)$_GET['user']; }
    if (!empty($_GET['department'])) { $w[] = '(t.department_id=? OR r.user_id IN ' . rp_member_sql([(int)$_GET['department']]) . ')'; $p[] = (int)$_GET['department']; }
    if ($f = d($_GET['from'] ?? null)) { $w[] = 'r.report_date>=?'; $p[] = $f; }
    if ($f = d($_GET['to'] ?? null)) { $w[] = 'r.report_date<=?'; $p[] = $f; }
    out(all('SELECT r.id,r.task_id,r.user_id,r.report_date,r.status,r.progress,r.hours,r.summary,r.blockers,r.updated_at,t.title AS task_title,u.name AS user_name,
             (SELECT COUNT(*) FROM report_comments c WHERE c.report_id=r.id) AS comment_count
             FROM daily_reports r JOIN tasks t ON t.id=r.task_id JOIN users u ON u.id=r.user_id WHERE ' . implode(' AND ', $w) . ' ORDER BY r.report_date DESC, r.id DESC LIMIT 200', $p));
}

/* ---------- submit / update ---------- */
if ($method === 'POST' && $id === null && $sub === null) {
    if ($ro) fail('Founder accounts are read-only', 403);
    $b = body(); $tid = (int)($b['task_id'] ?? 0);
    $t = $tid ? one('SELECT * FROM tasks WHERE id=?', [$tid]) : null; if (!$t) fail('Choose the task you are reporting on');
    if ((int)$t['assignee_id'] !== $uid) fail('You can only report on tasks assigned to you', 403);
    $date = d($b['report_date'] ?? null) ?: $today;
    if ($date > $today) fail('You cannot report on a future date');
    $existing = one('SELECT id FROM daily_reports WHERE task_id=? AND user_id=? AND report_date=?', [$tid, $uid, $date]);
    if ($date < date('Y-m-d', strtotime('-3 days')) && !$existing) fail('New reports can be added for today or the last 3 days only. You can still edit a report you already wrote.');
    if (in_array($t['status'], RP_CLOSED, true) && !$existing) fail('This task is ' . strtolower(rp_status_label($t['status'])) . ', so it no longer needs daily reports');
    $status = (string)($b['status'] ?? ''); if ($status === '') $status = $t['status'] === 'todo' ? 'in_progress' : $t['status'];
    if (!in_array($status, TASK_STATUSES, true) || $status === 'transferred') fail('Choose a valid status');
    $full = $u['role'] === 'super_admin' || (int)$t['creator_id'] === $uid || ($t['department_id'] && in_array((int)$t['department_id'], managed_dept_ids($u), true));
    if ($status === 'canceled' && !$full) fail('Only the person who gave the task, or a department head, can cancel it. Ask them, or mark it Deferred.', 403);
    $summary = s($b['summary'] ?? '', 3000); if ($summary === '') fail('Write a short summary of the work done');
    $blockers = s($b['blockers'] ?? '', 1500);
    if ($status === 'blocked' && $blockers === '') fail('Say what is blocking you');
    $newDue = d($b['due_date'] ?? null); if ($status === 'deferred' && !$newDue) fail('Choose the new date this task is deferred to');
    $progress = $status === 'done' ? 100 : max(0, min(100, (int)($b['progress'] ?? 0)));
    $hours = ($b['hours'] ?? '') === '' || $b['hours'] === null ? null : max(0, min(24, round((float)$b['hours'], 1)));
    q('INSERT INTO daily_reports (task_id,user_id,report_date,status,progress,hours,summary,blockers) VALUES (?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE status=VALUES(status),progress=VALUES(progress),hours=VALUES(hours),summary=VALUES(summary),blockers=VALUES(blockers)',
      [$tid, $uid, $date, $status, $progress, $hours, $summary, $blockers ?: null]);
    $rid = (int)(one('SELECT id FROM daily_reports WHERE task_id=? AND user_id=? AND report_date=?', [$tid, $uid, $date])['id']);
    // today's report also moves the task to the status it names
    if ($date === $today && $status !== $t['status']) {
        $sets = ['status=?', 'status_reason=?', 'completed_at=' . ($status === 'done' ? 'COALESCE(completed_at,NOW())' : 'NULL')]; $vals = [$status, in_array($status, ['deferred', 'canceled', 'blocked'], true) ? mb_substr($blockers ?: $summary, 0, 300) : null];
        if ($status === 'deferred') { $sets[] = 'due_date=?'; $vals[] = $newDue; $sets[] = 'defer_count=defer_count+1'; }
        $vals[] = $tid; q('UPDATE tasks SET ' . implode(',', $sets) . ' WHERE id=?', $vals);
        log_act($uid, 'task_' . $status, $t['title']);
        if ($status === 'deferred') q('INSERT INTO task_updates (task_id,user_id,kind,data) VALUES (?,?,?,?)', [$tid, $uid, 'defer', json_encode(['from' => $t['due_date'], 'to' => $newDue, 'reason' => mb_substr($summary, 0, 500)], JSON_UNESCAPED_UNICODE)]);
    } elseif ($date === $today && $status === 'deferred' && $newDue) { q('UPDATE tasks SET due_date=? WHERE id=?', [$newDue, $tid]); }
    if (!$existing) { // tell the department head and whoever gave the task, once per report
        $to = [(int)$t['creator_id']];
        if ($t['department_id']) { $h = one('SELECT head_id FROM departments WHERE id=?', [$t['department_id']]); if ($h && $h['head_id']) $to[] = (int)$h['head_id']; }
        foreach (array_unique(array_filter($to)) as $n) if ($n !== $uid) notify($n, 'report_new', $u['name'] . ' reported on: ' . $t['title'], rp_status_label($status) . ' · ' . mb_substr($summary, 0, 120), '#/reports/' . $rid);
        log_act($uid, 'report_submitted', $t['title']);
    }
    out(['ok' => true, 'id' => $rid, 'updated' => (bool)$existing], $existing ? 200 : 201);
}

/* ---------- one report ---------- */
if ($id !== null) {
    $rep = rp_load($id); if (!$rep || !rp_can_view($u, $rep)) fail('Not found', 404);
    if ($method === 'GET' && $sub === null) {
        $rep['comments'] = all('SELECT c.id,c.user_id,c.body,c.created_at,u.name FROM report_comments c JOIN users u ON u.id=c.user_id WHERE c.report_id=? ORDER BY c.id', [$id]);
        $rep['shares'] = all('SELECT s.user_id AS id,u.name,s.note,s.created_at,sb.name AS shared_by FROM report_shares s JOIN users u ON u.id=s.user_id LEFT JOIN users sb ON sb.id=s.shared_by WHERE s.report_id=? ORDER BY u.name', [$id]);
        [$rs, $rp] = rp_scope($u);
        $rep['history'] = all('SELECT r.id,r.report_date,r.status,r.progress,r.summary FROM daily_reports r JOIN tasks t ON t.id=r.task_id WHERE r.task_id=? AND r.id<>? AND ' . $rs . ' ORDER BY r.report_date DESC LIMIT 6', array_merge([$rep['task_id'], $id], $rp));
        $rep['can_share'] = rp_can_share($u, $rep); $rep['can_comment'] = !$ro; $rep['can_delete'] = $u['role'] === 'super_admin' || ((int)$rep['user_id'] === $uid && !$ro);
        $rep['can_edit'] = (int)$rep['user_id'] === $uid && !$ro && !in_array($rep['task_status'], RP_CLOSED, true);
        out($rep);
    }
    if ($method === 'DELETE' && $sub === null) {
        if (!($u['role'] === 'super_admin' || ((int)$rep['user_id'] === $uid && !$ro))) fail('Not allowed', 403);
        q('DELETE FROM daily_reports WHERE id=?', [$id]); out(['ok' => true]);
    }
    if ($sub === 'comments') {
        if ($ro) fail('Founder accounts are read-only', 403);
        if ($method === 'POST') {
            $c = s(body()['body'] ?? '', 3000); if ($c === '') fail('Comment is empty');
            q('INSERT INTO report_comments (report_id,user_id,body) VALUES (?,?,?)', [$id, $uid, $c]);
            $who = [(int)$rep['user_id']];
            foreach (all('SELECT DISTINCT user_id FROM report_comments WHERE report_id=?', [$id]) as $x) $who[] = (int)$x['user_id'];
            foreach (all('SELECT user_id FROM report_shares WHERE report_id=?', [$id]) as $x) $who[] = (int)$x['user_id'];
            foreach (array_unique($who) as $n) if ($n !== $uid) notify($n, 'report_comment', $u['name'] . ' commented on a report: ' . $rep['task_title'], mb_substr($c, 0, 140), '#/reports/' . $id);
            out(['ok' => true], 201);
        }
        if ($method === 'DELETE' && $sub2) {
            $c = one('SELECT user_id FROM report_comments WHERE id=? AND report_id=?', [$sub2, $id]); if (!$c) fail('Not found', 404);
            if ((int)$c['user_id'] !== $uid && $u['role'] !== 'super_admin') fail('Not allowed', 403);
            q('DELETE FROM report_comments WHERE id=?', [$sub2]); out(['ok' => true]);
        }
    }
    if ($sub === 'share') {
        if ($method === 'POST') {
            if (!rp_can_share($u, $rep)) fail('Only the author or the department head can share this report', 403);
            $b = body(); $ids = array_values(array_unique(array_filter(array_map('intval', (array)($b['user_ids'] ?? []))))); if (!$ids) fail('Choose at least one person');
            require_once __DIR__ . '/sharemsg.php'; $ids = array_values(array_diff($ids, [$uid]));
            $msg = sm_create('daily', $id, $u, $b, $ids); $note = $msg ? mb_substr(preg_replace('/\s+/', ' ', $msg['text']), 0, 500) : s($b['note'] ?? '', 500); $names = []; $people = [];
            foreach ($ids as $x) {
                $p = one('SELECT id,name,email FROM users WHERE id=? AND active=1', [$x]); if (!$p) continue;
                $new = q('INSERT INTO report_shares (report_id,user_id,shared_by,note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE note=COALESCE(VALUES(note),note)', [$id, $x, $uid, $note ?: null])->rowCount() === 1;
                if ($new || $msg) notify($x, 'report_shared', $u['name'] . ' shared a report with you: ' . $rep['task_title'], $rep['user_name'] . ' · ' . $rep['report_date'] . ($note ? ' — ' . mb_substr($note, 0, 120) : ''), '#/reports/' . $id);
                $names[] = $p['name']; $people[] = $p;
            }
            if (!$names) { if ($msg) sm_delete($msg['id']); fail('Choose someone other than yourself'); }
            $emailed = 0; $failed = 0;
            if (!empty($b['email'])) { require_once __DIR__ . '/accounts.php'; require_once __DIR__ . '/campcore.php'; $link = camp_base() . '/#/reports/' . $id; $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
                $info = '<p style="font-size:16px"><b>' . $e($rep['task_title']) . '</b><br><span style="color:#667">' . $e($rep['user_name']) . ' · ' . $e(date('D j M Y', strtotime((string)$rep['report_date']))) . ' · ' . (int)$rep['progress'] . '% · ' . $e($rep['status']) . '</span></p><p style="white-space:pre-wrap">' . $e($rep['summary']) . '</p>' . ($rep['blockers'] ? '<p><b>Blockers:</b> ' . $e($rep['blockers']) . '</p>' : '');
                $m2 = $msg ?: ['html' => '', 'text' => '', 'links' => [], 'files' => [], 'mail_inline' => [], 'mail_files' => []];
                foreach ($people as $p) { if (!filter_var($p['email'], FILTER_VALIDATE_EMAIL)) continue; [$html, $text] = sm_mail($m2, $u, $p, 'a report', $link, $info); $text = preg_replace('/\n\nOpen it:/', "\n\n" . $rep['task_title'] . ' (' . $rep['user_name'] . ', ' . $rep['report_date'] . "):\n" . $rep['summary'] . "\n\nOpen it:", $text, 1);
                    if (acct_send_mail($p['email'], 'Report: ' . $rep['task_title'] . ' · ' . $rep['user_name'], $html, $text, $m2['mail_files'], ['from_name' => $u['name'], 'reply_to' => $u['email'], 'inline' => array_values($m2['mail_inline'])])) $emailed++; else $failed++; } }
            log_act($uid, 'report_shared', $rep['task_title']);
            out(['ok' => true, 'shared' => $names, 'emailed' => $emailed, 'email_failed' => $failed, 'message_id' => $msg['id'] ?? null]);
        }
        if ($method === 'DELETE' && $sub2) {
            if (!rp_can_share($u, $rep) && $sub2 !== $uid) fail('Not allowed', 403);
            q('DELETE FROM report_shares WHERE report_id=? AND user_id=?', [$id, $sub2]); out(['ok' => true]);
        }
    }
}
fail('Bad request', 404);
