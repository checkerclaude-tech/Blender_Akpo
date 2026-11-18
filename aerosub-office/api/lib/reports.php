<?php
declare(strict_types=1);
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

function rp_global(array $u): bool { return in_array($u['role'], ['super_admin', 'founder'], true); }

/** Members of the given departments (primary or additional). */
function rp_member_sql(array $deptIds): string {
    $l = implode(',', array_map('intval', $deptIds)) ?: '0';
    return "(SELECT user_id FROM user_departments WHERE department_id IN ($l) UNION SELECT id FROM users WHERE department_id IN ($l))";
}

/** [sql, params] limiting daily_reports r (joined to tasks t) to what this person may see. */
function rp_scope(array $u): array {
    if (rp_global($u)) return ['1=1', []];
    $parts = ['r.user_id=?', 'EXISTS(SELECT 1 FROM report_shares rs WHERE rs.report_id=r.id AND rs.user_id=?)']; $p = [$u['id'], $u['id']];
    if ($m = managed_dept_ids($u)) { $parts[] = 't.department_id IN (' . implode(',', $m) . ')'; $parts[] = 'r.user_id IN ' . rp_member_sql($m); }
    return ['(' . implode(' OR ', $parts) . ')', $p];
}

/** [sql, params] limiting tasks t to the ones this person's dashboard covers (own tasks, or their departments' tasks). */
function rp_task_scope(array $u): array {
    if (rp_global($u)) return ['1=1', []];
    $parts = ['t.assignee_id=?']; $p = [$u['id']];
    if ($m = managed_dept_ids($u)) { $parts[] = 't.department_id IN (' . implode(',', $m) . ')'; $parts[] = 't.assignee_id IN ' . rp_member_sql($m); }
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
    $m = managed_dept_ids($u); if (!$m) return false;
    return ($r['department_id'] && in_array((int)$r['department_id'], $m, true)) || manages_user($u, (int)$r['user_id']);
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
    $to = (int)($b['to_user_id'] ?? 0); $note = s($b['note'] ?? '', 300);
    $nu = $to ? one('SELECT id,name,department_id FROM users WHERE id=? AND active=1 AND role<>\'founder\'', [$to]) : null; if (!$nu) fail('Choose who the task goes to');
    if ($to === (int)$t['assignee_id']) fail('That person already has this task');
    if ($u['role'] !== 'super_admin' && $to !== (int)$u['id'] && !manages_user($u, $to)) fail('You can transfer tasks to people in your own department', 403);
    $dept = !empty($b['department_id']) ? (int)$b['department_id'] : ($t['department_id'] ? (int)$t['department_id'] : null);
    if (empty($b['department_id']) && $dept && !in_array($dept, user_dept_ids($to, (int)$nu['department_id']), true)) $dept = $nu['department_id'] ? (int)$nu['department_id'] : $dept; // crossing departments: it moves with the person
    $pdo = db(); $pdo->beginTransaction();
    try {
        q('INSERT INTO tasks (title,description,status,priority,assignee_id,creator_id,department_id,due_date,company_id,contact_id,task_type,project_id,starts_at,ends_at,transferred_from_id)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$t['title'], $t['description'], 'todo', $t['priority'], $to, $t['creator_id'], $dept, $t['due_date'], $t['company_id'], $t['contact_id'], $t['task_type'], $t['project_id'], $t['starts_at'], $t['ends_at'], $t['id']]);
        $nid = (int)$pdo->lastInsertId();
        q("UPDATE tasks SET status='transferred', transferred_to_id=?, status_reason=?, completed_at=NULL WHERE id=?", [$nid, $note ?: ('Transferred to ' . $nu['name']), $t['id']]);
        $info = json_encode(['from' => $t['assignee_name'] ?? null, 'to' => $nu['name'], 'note' => $note, 'old_task' => (int)$t['id'], 'new_task' => $nid], JSON_UNESCAPED_UNICODE);
        q('INSERT INTO task_updates (task_id,user_id,kind,data) VALUES (?,?,?,?)', [$t['id'], $u['id'], 'transfer', $info]);
        q('INSERT INTO task_updates (task_id,user_id,kind,data) VALUES (?,?,?,?)', [$nid, $u['id'], 'transfer', $info]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    foreach (array_unique(array_filter([$to, (int)$t['assignee_id'], (int)$t['creator_id']])) as $n) {
        if ((int)$n === (int)$u['id']) continue;
        if ($n === $to) notify($n, 'task_assigned', 'Task transferred to you: ' . $t['title'], 'By ' . $u['name'] . ($note ? ' — ' . $note : ''), '#/tasks/' . $nid);
        else notify((int)$n, 'task_transferred', $u['name'] . ' transferred "' . $t['title'] . '" to ' . $nu['name'], $note, '#/tasks/' . $nid);
    }
    log_act((int)$u['id'], 'task_transferred', $t['title'] . ' → ' . $nu['name']);
    out(['ok' => true, 'id' => $nid]);
}

/* ----------------------------------------------------------------------------------------------------------------- */
if (!isset($r) || $r !== 'reports') return;   // when only the functions above were wanted

$u = need_user(); $uid = (int)$u['id']; $ro = $u['role'] === 'founder';
$today = date('Y-m-d');
$sub2 = $seg[3] ?? null; $sub2 = ($sub2 !== null && ctype_digit($sub2)) ? (int)$sub2 : null;

/* ---------- dashboard ---------- */
if ($method === 'GET' && $sub === 'dashboard') {
    $date = d($_GET['date'] ?? null) ?: $today;
    $global = rp_global($u); $managed = managed_dept_ids($u); $review = $global || (bool)$managed;
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
    $since = date('Y-m-d', strtotime($date . ' -30 days')) . ' 00:00:00';
    $tasks = all("SELECT t.id,t.title,t.status,t.priority,t.due_date,t.assignee_id,t.department_id,t.updated_at,t.completed_at,a.name AS assignee_name,d.name AS department
                  FROM tasks t LEFT JOIN users a ON a.id=t.assignee_id LEFT JOIN departments d ON d.id=t.department_id
                  WHERE " . implode(' AND ', $tw) . " AND (t.status IN ('todo','in_progress','blocked','deferred') OR t.updated_at >= ?) AND t.created_at <= ? ORDER BY t.due_date IS NULL, t.due_date LIMIT 5000",
                 array_merge($tpar, [$since, $date . ' 23:59:59']));

    // reports on the chosen day
    [$rs, $rp] = rp_scope($u); $rw = [$rs, 'r.report_date=?']; $rpar = array_merge($rp, [$date]);
    if ($fd) { $rw[] = '(t.department_id=? OR r.user_id IN ' . rp_member_sql([$fd]) . ')'; $rpar[] = $fd; }
    if ($fu) { $rw[] = 'r.user_id=?'; $rpar[] = $fu; }
    $reports = all('SELECT r.id,r.task_id,r.user_id,r.report_date,r.status,r.progress,r.hours,r.summary,r.blockers,r.created_at,r.updated_at,t.title AS task_title,t.department_id,u.name AS user_name,d.name AS department,
                    (SELECT COUNT(*) FROM report_comments c WHERE c.report_id=r.id) AS comment_count, (SELECT COUNT(*) FROM report_shares s WHERE s.report_id=r.id) AS share_count
                    FROM daily_reports r JOIN tasks t ON t.id=r.task_id JOIN users u ON u.id=r.user_id LEFT JOIN departments d ON d.id=t.department_id
                    WHERE ' . implode(' AND ', $rw) . ' ORDER BY u.name, r.updated_at DESC LIMIT 300', $rpar);
    $reported = []; foreach ($reports as $x) $reported[(int)$x['task_id'] . ':' . (int)$x['user_id']] = true;

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
    usort($missing, fn($a, $b) => strcmp((string)$a['user_name'], (string)$b['user_name']));
    $people_rows = array_values($per); usort($people_rows, fn($a, $b) => ($b['expected'] - $b['reported']) <=> ($a['expected'] - $a['reported']) ?: strcmp((string)$a['name'], (string)$b['name']));

    // 7-day trend
    $from = date('Y-m-d', strtotime($date . ' -6 days'));
    $trw = [$rs, 'r.report_date BETWEEN ? AND ?']; $trp = array_merge($rp, [$from, $date]);
    if ($fd) { $trw[] = '(t.department_id=? OR r.user_id IN ' . rp_member_sql([$fd]) . ')'; $trp[] = $fd; }
    if ($fu) { $trw[] = 'r.user_id=?'; $trp[] = $fu; }
    $byDay = array_column(all('SELECT r.report_date dte, COUNT(*) c FROM daily_reports r JOIN tasks t ON t.id=r.task_id WHERE ' . implode(' AND ', $trw) . ' GROUP BY r.report_date', $trp), 'c', 'dte');
    $dw = [$ts, "t.status='done'", 'DATE(t.completed_at) BETWEEN ? AND ?']; $dp = array_merge($tp, [$from, $date]);
    if ($fd) { $dw[] = '(t.department_id=? OR t.assignee_id IN ' . rp_member_sql([$fd]) . ')'; $dp[] = $fd; }
    if ($fu) { $dw[] = 't.assignee_id=?'; $dp[] = $fu; }
    $doneDay = array_column(all('SELECT DATE(t.completed_at) dte, COUNT(*) c FROM tasks t WHERE ' . implode(' AND ', $dw) . ' GROUP BY DATE(t.completed_at)', $dp), 'c', 'dte');
    $trend = []; for ($i = 0; $i < 7; $i++) { $day = date('Y-m-d', strtotime($from . " +$i days")); $trend[] = ['date' => $day, 'reports' => (int)($byDay[$day] ?? 0), 'completed' => (int)($doneDay[$day] ?? 0)]; }

    // reports other people shared with me
    $shared = all('SELECT r.id,r.report_date,r.status,r.summary,t.title AS task_title,u.name AS user_name,sb.name AS shared_by,rs.note,rs.created_at AS shared_at
                   FROM report_shares rs JOIN daily_reports r ON r.id=rs.report_id JOIN tasks t ON t.id=r.task_id JOIN users u ON u.id=r.user_id LEFT JOIN users sb ON sb.id=rs.shared_by
                   WHERE rs.user_id=? ORDER BY rs.created_at DESC LIMIT 15', [$uid]);

    $mine = 0; foreach ($tasks as $t) if ((int)$t['assignee_id'] === $uid && in_array($t['status'], RP_WORKING, true) && !isset($reported[(int)$t['id'] . ':' . $uid])) $mine++;
    out(['date' => $date, 'today' => $today, 'scope' => $global ? 'all' : ($managed ? 'department' : 'own'), 'review' => $review, 'read_only' => $ro,
         'departments' => $depts, 'people' => $people, 'filter' => ['department' => $fd, 'user' => $fu],
         'counts' => $counts, 'reports' => $reports, 'missing' => array_slice($missing, 0, 100), 'missing_total' => count($missing), 'people_rows' => $people_rows,
         'trend' => $trend, 'shared' => $shared, 'my_missing' => $mine,
         'totals' => ['reports' => count($reports), 'expected' => array_sum(array_column($people_rows, 'expected')), 'missing' => count($missing)]]);
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
    if ($date < date('Y-m-d', strtotime('-3 days'))) fail('Reports can be added for today or the last 3 days only');
    $existing = one('SELECT id FROM daily_reports WHERE task_id=? AND user_id=? AND report_date=?', [$tid, $uid, $date]);
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
            $note = s($b['note'] ?? '', 500); $names = [];
            foreach ($ids as $x) {
                if ($x === $uid) continue; $p = one('SELECT id,name FROM users WHERE id=? AND active=1', [$x]); if (!$p) continue;
                $new = q('INSERT INTO report_shares (report_id,user_id,shared_by,note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE note=COALESCE(VALUES(note),note)', [$id, $x, $uid, $note ?: null])->rowCount() === 1;
                if ($new) notify($x, 'report_shared', $u['name'] . ' shared a report with you: ' . $rep['task_title'], $rep['user_name'] . ' · ' . $rep['report_date'] . ($note ? ' — ' . mb_substr($note, 0, 120) : ''), '#/reports/' . $id);
                $names[] = $p['name'];
            }
            if (!$names) fail('Choose someone other than yourself');
            log_act($uid, 'report_shared', $rep['task_title']);
            out(['ok' => true, 'shared' => $names]);
        }
        if ($method === 'DELETE' && $sub2) {
            if (!rp_can_share($u, $rep) && $sub2 !== $uid) fail('Not allowed', 403);
            q('DELETE FROM report_shares WHERE report_id=? AND user_id=?', [$id, $sub2]); out(['ok' => true]);
        }
    }
}
fail('Bad request', 404);
