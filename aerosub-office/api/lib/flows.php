<?php
declare(strict_types=1);
// Dependent tasks ("flows"): a plan made of steps. Each step is an ordinary task for the people doing it, but it waits until the steps it depends on are done,
// has its own time frame (one window for everyone on it, or one window per person) and can hold sub-tasks. The creator sees the whole flow as a timeline.
//   POST tasks {title, description, flow:{start_date, steps:[{title, assignee_id, user_ids[], start_date, due_date, days, scope:'group'|'user', windows:{userId:{start_date,due_date}}, after:[step numbers]}]}}
//   GET  tasks/{id}/flow                     the flow (id = the flow or any of its steps)
//   POST tasks/{flowId}/steps {…one step, after:[task ids]…}     add a step
//   PUT  tasks/{stepId}/step {title, assignee_id, user_ids, start_date, due_date, scope, windows, after:[task ids]}   change a step, its people, its time frame and what it waits for
//   GET/POST tasks/{id}/subs · PATCH/DELETE tasks/{id}/subs/{subId}   sub-tasks on any task, step or flow

function flow_ensure(): void {
    static $done = false; if ($done) return; $done = true;
    try { require_once __DIR__ . '/migrate.php'; if (!col_exists('tasks', 'kind') || !col_exists('tasks', 'transfer_state') || !one("SHOW TABLES LIKE 'task_subs'")) { require_once __DIR__ . '/migrate_fin3.php'; migrate_fin_ext(); } } catch (Throwable $e) { error_log('Flow schema: ' . $e->getMessage()); fail('The task flow tables could not be created automatically. Open /upgrade.php once as Super Admin.', 500); }
}
function flow_d($v): ?string { $v = is_string($v) ? trim($v) : ''; return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null; }
function flow_add_days(string $d, int $n): string { return date('Y-m-d', strtotime($d . ' ' . ($n >= 0 ? '+' : '') . $n . ' day')); }
function flow_can_assign(array $u, int $assignee): bool {
    if ($u['role'] === 'super_admin') return true; if ($assignee === (int)$u['id']) return true; return manages_user($u, $assignee);
}
/** The steps this one is still waiting for (not done, not cancelled). */
function flow_waiting_on(int $stepId): array {
    return all("SELECT t.id,t.title FROM task_deps d JOIN tasks t ON t.id=d.depends_on_id WHERE d.task_id=? AND t.status NOT IN ('done','canceled') ORDER BY t.id", [$stepId]);
}
function flow_reaches(int $from, int $target, int $depth = 0): bool {   // does $from (through what it waits for) end up waiting for $target?
    if ($depth > 60) return true; foreach (all('SELECT depends_on_id FROM task_deps WHERE task_id=?', [$from]) as $r) { $n = (int)$r['depends_on_id']; if ($n === $target || flow_reaches($n, $target, $depth + 1)) return true; } return false;
}
function flow_set_after(int $stepId, int $flowId, array $ids): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids)))); q('DELETE FROM task_deps WHERE task_id=?', [$stepId]);
    foreach ($ids as $d) {
        if ($d === $stepId) fail('A step cannot wait for itself'); if (!one("SELECT 1 FROM tasks WHERE id=? AND parent_id=? AND kind='step'", [$d, $flowId])) fail('A step can only wait for another step of the same flow');
        if (flow_reaches($d, $stepId)) fail('That would make the steps wait for each other in a circle');
        q('INSERT INTO task_deps (task_id,depends_on_id) VALUES (?,?)', [$stepId, $d]);
    }
}
/** Add one step to a flow. $after holds task ids. Returns the new task id. */
function flow_add_step(array $flow, array $s, array $u, array $after): int {
    $uid = (int)$u['id']; $title = s($s['title'] ?? '', 200); if ($title === '') fail('Every step needs a name');
    $as = (int)($s['assignee_id'] ?? 0) ?: $uid; if (!one('SELECT id FROM users WHERE id=? AND active=1', [$as])) fail('Choose who does “' . $title . '”'); if (!flow_can_assign($u, $as)) fail('You can give steps to people in the departments you head or manage', 403);
    $extra = array_values(array_unique(array_filter(array_map('intval', (array)($s['user_ids'] ?? [])), fn($x) => $x && $x !== $as))); foreach ($extra as $x) if (!one('SELECT id FROM users WHERE id=? AND active=1', [$x])) fail('One of the people on “' . $title . '” was not found');
    $dueOf = fn($id) => (string)(one('SELECT due_date FROM tasks WHERE id=?', [$id])['due_date'] ?? ''); $latest = ''; foreach ($after as $a) { $dd = $dueOf((int)$a); if ($dd > $latest) $latest = $dd; }
    $start = flow_d($s['start_date'] ?? null) ?: ($latest !== '' ? flow_add_days($latest, 1) : (flow_d($flow['start_date'] ?? null) ?: date('Y-m-d')));
    $due = flow_d($s['due_date'] ?? null) ?: flow_add_days($start, max(1, min(365, (int)($s['days'] ?? 3))) - 1); if ($due < $start) fail('“' . $title . '”: the end date is before the start date');
    $scope = in((string)($s['scope'] ?? ''), ['group', 'user'], 'group'); $waiting = false; foreach ($after as $a) if (one("SELECT 1 FROM tasks WHERE id=? AND status NOT IN ('done','canceled')", [(int)$a])) $waiting = true;
    q('INSERT INTO tasks (title,description,status,priority,assignee_id,creator_id,department_id,due_date,task_type,kind,parent_id,start_date,window_scope) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [$title, s($s['description'] ?? '', 3000) ?: null, $waiting ? 'blocked' : 'todo', in((string)($s['priority'] ?? ''), ['low', 'medium', 'high', 'urgent'], $flow['priority'] ?? 'medium'), $as, $flow['creator_id'], $flow['department_id'], $due, $flow['task_type'] ?: 'general', 'step', (int)$flow['id'], $start, $scope]);
    $id = (int)db()->lastInsertId(); flow_set_after($id, (int)$flow['id'], $after);
    flow_people($id, (int)$flow['id'], $as, $extra, $scope, (array)($s['windows'] ?? []), $start, $due, $uid);
    if ($waiting) { $w = flow_waiting_on($id); q('UPDATE tasks SET status_reason=? WHERE id=?', ['Waiting for: ' . mb_substr(implode(', ', array_column($w, 'title')), 0, 250), $id]); }
    foreach (array_merge([$as], $extra) as $p) if ($p !== $uid) notify($p, 'task_assigned', ($waiting ? 'Coming up: ' : 'New task: ') . $title, 'Step of “' . $flow['title'] . '”, ' . date('j M', strtotime($start)) . ' to ' . date('j M', strtotime($due)) . ($waiting ? ' (starts when the step before it is done)' : '') . ' · by ' . $u['name'], '#/tasks/' . $id);
    return $id;
}
/** The people on a step: the main person plus the others added to it (they can open it and update its status), and the time frames. */
function flow_people(int $stepId, int $flowId, int $as, array $extra, string $scope, array $windows, string $start, string $due, int $by): void {
    q('DELETE FROM task_shares WHERE task_id=?', [$stepId]); q('DELETE FROM task_windows WHERE task_id=?', [$stepId]);
    foreach ($extra as $p) q('INSERT IGNORE INTO task_shares (task_id,user_id,shared_by) VALUES (?,?,?)', [$stepId, $p, $by]);
    foreach (array_merge([$as], $extra) as $p) { if ($p !== $by) q('INSERT IGNORE INTO task_shares (task_id,user_id,shared_by) VALUES (?,?,?)', [$flowId, $p, $by]); }   // so everyone can open the flow
    if ($scope === 'user') foreach (array_merge([$as], $extra) as $p) { $w = (array)($windows[(string)$p] ?? $windows[$p] ?? []); $ws = flow_d($w['start_date'] ?? null) ?: $start; $we = flow_d($w['due_date'] ?? null) ?: $due; if ($we < $ws) $we = $ws;
        q('INSERT INTO task_windows (task_id,user_id,start_date,due_date) VALUES (?,?,?,?)', [$stepId, $p, $ws, $we]); }
}
/** POST tasks with a flow: the container and its steps in one go. Never returns. */
function flow_create(array $b, array $u): never {
    flow_ensure(); $uid = (int)$u['id']; $title = s($b['title'] ?? '', 200); if ($title === '') fail('Title required'); $f = (array)$b['flow']; $steps = array_values(array_filter((array)($f['steps'] ?? []), 'is_array'));
    if (!$steps) fail('Add at least one step'); if (count($steps) > 40) fail('A flow can have up to 40 steps');
    $dept = !empty($b['department_id']) ? (int)$b['department_id'] : (($u['head_of'] ?? [])[0] ?? ($u['department_id'] ?: null)); $start = flow_d($f['start_date'] ?? null) ?: date('Y-m-d');
    db()->beginTransaction();
    q("INSERT INTO tasks (title,description,status,priority,assignee_id,creator_id,department_id,task_type,kind,start_date) VALUES (?,?,?,?,?,?,?,?,?,?)", [$title, s($b['description'] ?? '', 5000) ?: null, 'in_progress', in((string)($b['priority'] ?? ''), ['low', 'medium', 'high', 'urgent'], 'medium'), $uid, $uid, $dept, 'general', 'flow', $start]);
    $fid = (int)db()->lastInsertId(); $flow = one('SELECT * FROM tasks WHERE id=?', [$fid]); $flow['start_date'] = $start; $ids = [];
    try {
        foreach ($steps as $i => $s) { $after = []; foreach ((array)($s['after'] ?? []) as $n) { $n = (int)$n; if ($n < 0 || $n >= $i) fail('Step ' . ($i + 1) . ' can only wait for a step above it'); $after[] = $ids[$n]; } $ids[$i] = flow_add_step($flow, $s, $u, $after); }
        $mx = (string)(one('SELECT MAX(due_date) m FROM tasks WHERE parent_id=?', [$fid])['m'] ?? ''); if ($mx !== '') q('UPDATE tasks SET due_date=? WHERE id=?', [$mx, $fid]);
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); q('DELETE FROM tasks WHERE id=? OR parent_id=?', [$fid, $fid]); throw $e; }
    log_act($uid, 'flow_created', $title . ' (' . count($steps) . ' steps)'); out(['ok' => true, 'id' => $fid, 'steps' => $ids], 201);
}
/** After a step's status changed: open the next steps, and keep the flow's own status in step with its steps. */
function flow_status_changed(array $t, string $status, array $u): void {
    if (empty($t['kind'])) return; $flowId = $t['kind'] === 'step' ? (int)$t['parent_id'] : 0; if (!$flowId) return;
    if (in_array($status, ['done', 'canceled'], true)) {
        foreach (all("SELECT d.task_id FROM task_deps d JOIN tasks n ON n.id=d.task_id WHERE d.depends_on_id=? AND n.status='blocked'", [(int)$t['id']]) as $r) {
            $nid = (int)$r['task_id']; if (flow_waiting_on($nid)) { $w = flow_waiting_on($nid); q('UPDATE tasks SET status_reason=? WHERE id=?', ['Waiting for: ' . mb_substr(implode(', ', array_column($w, 'title')), 0, 250), $nid]); continue; }
            q("UPDATE tasks SET status='todo', status_reason=NULL WHERE id=? AND status='blocked'", [$nid]); $n = one('SELECT title,assignee_id FROM tasks WHERE id=?', [$nid]);
            $who = array_unique(array_merge([(int)$n['assignee_id']], array_map('intval', array_column(all('SELECT user_id FROM task_shares WHERE task_id=?', [$nid]), 'user_id')))); foreach ($who as $p) if ($p) notify($p, 'task_ready', '▶ Your turn: ' . $n['title'], '“' . $t['title'] . '” is done, so this can start now', '#/tasks/' . $nid);
        }
    }
    $tot = (int)one("SELECT COUNT(*) n FROM tasks WHERE parent_id=? AND kind='step' AND status<>'canceled'", [$flowId])['n']; $dn = (int)one("SELECT COUNT(*) n FROM tasks WHERE parent_id=? AND kind='step' AND status='done'", [$flowId])['n'];
    $fl = one('SELECT status,creator_id,title FROM tasks WHERE id=?', [$flowId]); if (!$fl) return;
    $new = $tot > 0 && $dn >= $tot ? 'done' : ($fl['status'] === 'done' ? 'in_progress' : $fl['status']);
    if ($new !== $fl['status']) { q("UPDATE tasks SET status=?, completed_at=" . ($new === 'done' ? 'NOW()' : 'NULL') . ' WHERE id=?', [$new, $flowId]); if ($new === 'done') notify((int)$fl['creator_id'], 'task_status', '🎉 Every step is done: ' . $fl['title'], '', '#/tasks/' . $flowId); }
}
function flow_task_extra(array $t, int $uid): array {
    $o = ['subs' => [], 'flow_info' => null];
    try { $o['subs'] = array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'done' => (int)$r['done'], 'user_id' => $r['user_id'] ? (int)$r['user_id'] : null, 'user_name' => $r['user_name']], all('SELECT s.id,s.title,s.done,s.user_id,u.name AS user_name FROM task_subs s LEFT JOIN users u ON u.id=s.user_id WHERE s.task_id=? ORDER BY s.id', [(int)$t['id']])); } catch (Throwable $e) {}
    if (($t['kind'] ?? '') === 'step') { $fl = one('SELECT id,title FROM tasks WHERE id=?', [(int)$t['parent_id']]);
        $o['flow_info'] = ['flow_id' => $fl ? (int)$fl['id'] : null, 'flow_title' => $fl['title'] ?? null, 'waiting_on' => flow_waiting_on((int)$t['id']), 'after' => all('SELECT t.id,t.title,t.status FROM task_deps d JOIN tasks t ON t.id=d.depends_on_id WHERE d.task_id=?', [(int)$t['id']]),
            'unlocks' => all('SELECT t.id,t.title FROM task_deps d JOIN tasks t ON t.id=d.task_id WHERE d.depends_on_id=?', [(int)$t['id']]), 'scope' => $t['window_scope'] ?: 'group', 'start_date' => $t['start_date'],
            'windows' => all('SELECT w.user_id,u.name,w.start_date,w.due_date FROM task_windows w JOIN users u ON u.id=w.user_id WHERE w.task_id=? ORDER BY u.name', [(int)$t['id']])]; }
    elseif (($t['kind'] ?? '') === 'flow') { $o['flow_info'] = ['flow_id' => (int)$t['id'], 'is_flow' => true]; }
    return $o;
}
function flow_data(int $flowId): array {
    $f = one('SELECT t.*, c.name AS creator_name FROM tasks t JOIN users c ON c.id=t.creator_id WHERE t.id=?', [$flowId]); $rows = all("SELECT t.*, a.name AS assignee_name FROM tasks t LEFT JOIN users a ON a.id=t.assignee_id WHERE t.parent_id=? AND t.kind='step' ORDER BY t.start_date, t.id", [$flowId]); $steps = []; $min = null; $max = null;
    foreach ($rows as $r) { $id = (int)$r['id'];
        $people = all('SELECT u.id,u.name FROM task_shares s JOIN users u ON u.id=s.user_id WHERE s.task_id=? ORDER BY u.name', [$id]); $win = all('SELECT w.user_id,u.name,w.start_date,w.due_date FROM task_windows w JOIN users u ON u.id=w.user_id WHERE w.task_id=? ORDER BY u.name', [$id]);
        $sc = one('SELECT COUNT(*) n, COALESCE(SUM(done),0) d FROM task_subs WHERE task_id=?', [$id]); $s = $r['start_date'] ?: $r['due_date']; $e = $r['due_date'] ?: $s;
        foreach ($win as $w) { if ($w['start_date'] < $s) $s = $w['start_date']; if ($w['due_date'] > $e) $e = $w['due_date']; } if ($s && ($min === null || $s < $min)) $min = $s; if ($e && ($max === null || $e > $max)) $max = $e;
        $steps[] = ['id' => $id, 'title' => $r['title'], 'status' => $r['status'], 'status_reason' => $r['status_reason'], 'assignee_id' => (int)$r['assignee_id'], 'assignee' => $r['assignee_name'], 'people' => $people, 'start_date' => $r['start_date'], 'due_date' => $r['due_date'], 'scope' => $r['window_scope'] ?: 'group', 'windows' => $win, 'span' => [$s, $e],
            'after' => array_map('intval', array_column(all('SELECT depends_on_id FROM task_deps WHERE task_id=?', [$id]), 'depends_on_id')), 'waiting' => $r['status'] === 'blocked' && (bool)flow_waiting_on($id), 'subs' => [(int)$sc['d'], (int)$sc['n']]]; }
    $done = count(array_filter($steps, fn($s) => $s['status'] === 'done'));
    return ['flow' => ['id' => (int)$f['id'], 'title' => $f['title'], 'description' => $f['description'], 'status' => $f['status'], 'creator_id' => (int)$f['creator_id'], 'creator' => $f['creator_name'], 'priority' => $f['priority']], 'steps' => $steps, 'range' => [$min, $max], 'today' => date('Y-m-d'), 'progress' => [$done, count($steps)]];
}
/** Routes under tasks/{id}/… for flows and sub-tasks. $t is the task (already checked as visible to the person). */
function flow_route(array $t, array $u, string $method, string $sub, array $seg, callable $canEdit): void {
    flow_ensure(); $uid = (int)$u['id']; $id = (int)$t['id']; $t = one('SELECT * FROM tasks WHERE id=?', [$id]) + $t; $full = $canEdit($t); if ($u['role'] === 'founder' && $method !== 'GET') fail('Founder accounts are read-only', 403);
    if ($sub === 'flow' && $method === 'GET') { $fid = ($t['kind'] ?? '') === 'step' ? (int)$t['parent_id'] : $id; if (($t['kind'] ?? '') !== 'flow' && ($t['kind'] ?? '') !== 'step') fail('This is not a dependent task', 400); out(flow_data($fid) + ['can_edit' => $full]); }
    if ($sub === 'steps' && $method === 'POST') {
        if (($t['kind'] ?? '') !== 'flow') fail('Steps can only be added to a dependent task', 400); if (!$full) fail('Only the person who set up the flow can add steps', 403); $b = body();
        $newId = flow_add_step($t, $b, $u, array_map('intval', (array)($b['after'] ?? []))); $mx = (string)(one('SELECT MAX(due_date) m FROM tasks WHERE parent_id=?', [$id])['m'] ?? ''); if ($mx !== '') q('UPDATE tasks SET due_date=?, status=IF(status=\'done\',\'in_progress\',status), completed_at=NULL WHERE id=?', [$mx, $id]); out(['ok' => true, 'id' => $newId], 201);
    }
    if ($sub === 'step' && $method === 'PUT') {
        if (($t['kind'] ?? '') !== 'step') fail('This is not a step of a flow', 400); if (!$full) fail('Only the person who set up the flow can change its steps', 403); $b = body(); $flow = one('SELECT * FROM tasks WHERE id=?', [(int)$t['parent_id']]);
        $as = array_key_exists('assignee_id', $b) ? ((int)$b['assignee_id'] ?: (int)$t['assignee_id']) : (int)$t['assignee_id']; if (!flow_can_assign($u, $as) && $as !== (int)$t['assignee_id']) fail('You can give steps to people in the departments you head or manage', 403);
        $extra = array_key_exists('user_ids', $b) ? array_values(array_unique(array_filter(array_map('intval', (array)$b['user_ids']), fn($x) => $x && $x !== $as))) : array_map('intval', array_column(all('SELECT user_id FROM task_shares WHERE task_id=?', [$id]), 'user_id'));
        $start = flow_d($b['start_date'] ?? null) ?: ($t['start_date'] ?: date('Y-m-d')); $due = flow_d($b['due_date'] ?? null) ?: ($t['due_date'] ?: $start); if ($due < $start) fail('The end date is before the start date'); $scope = in((string)($b['scope'] ?? $t['window_scope']), ['group', 'user'], 'group');
        $title = array_key_exists('title', $b) ? (s($b['title'], 200) ?: $t['title']) : $t['title'];
        q('UPDATE tasks SET title=?, assignee_id=?, start_date=?, due_date=?, window_scope=? WHERE id=?', [$title, $as, $start, $due, $scope, $id]);
        if (array_key_exists('after', $b)) { flow_set_after($id, (int)$flow['id'], (array)$b['after']); $w = flow_waiting_on($id);
            if ($w && in_array($t['status'], ['todo'], true)) q("UPDATE tasks SET status='blocked', status_reason=? WHERE id=?", ['Waiting for: ' . mb_substr(implode(', ', array_column($w, 'title')), 0, 250), $id]);
            elseif (!$w && $t['status'] === 'blocked' && ($t['status_reason'] ?? '') !== '' && str_starts_with((string)$t['status_reason'], 'Waiting for')) q("UPDATE tasks SET status='todo', status_reason=NULL WHERE id=?", [$id]); }
        $old = array_map('intval', array_column(all('SELECT user_id FROM task_shares WHERE task_id=?', [$id]), 'user_id')); flow_people($id, (int)$flow['id'], $as, $extra, $scope, (array)($b['windows'] ?? []), $start, $due, $uid);
        foreach (array_diff(array_merge([$as], $extra), array_merge([(int)$t['assignee_id']], $old)) as $p) if ($p !== $uid) notify($p, 'task_assigned', 'Added to a step: ' . $title, 'Step of “' . $flow['title'] . '” · by ' . $u['name'], '#/tasks/' . $id);
        $mx = (string)(one('SELECT MAX(due_date) m FROM tasks WHERE parent_id=?', [(int)$flow['id']])['m'] ?? ''); if ($mx !== '') q('UPDATE tasks SET due_date=? WHERE id=?', [$mx, (int)$flow['id']]); out(['ok' => true]);
    }
    if ($sub === 'subs') {   // small items inside a task, step or flow; anyone who can see the task can tick them
        $sid = ctype_digit((string)($seg[3] ?? '')) ? (int)$seg[3] : 0;
        if ($method === 'GET' && !$sid) out(flow_task_extra($t, $uid)['subs']);
        if ($method === 'POST' && !$sid) { $b = body(); $title = s($b['title'] ?? '', 200); if ($title === '') fail('Write the sub-task'); if ((int)(one('SELECT COUNT(*) n FROM task_subs WHERE task_id=?', [$id])['n'] ?? 0) >= 60) fail('That is a lot of sub-tasks. Group them into steps.');
            $who = (int)($b['user_id'] ?? 0) ?: null; if ($who && !one('SELECT id FROM users WHERE id=? AND active=1', [$who])) $who = null; q('INSERT INTO task_subs (task_id,title,user_id,created_by) VALUES (?,?,?,?)', [$id, $title, $who, $uid]);
            if ($who && $who !== $uid) notify($who, 'task_assigned', 'Sub-task for you: ' . $title, 'On “' . $t['title'] . '” · by ' . $u['name'], '#/tasks/' . $id); out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201); }
        $row = $sid ? one('SELECT * FROM task_subs WHERE id=? AND task_id=?', [$sid, $id]) : null; if (!$row) fail('Not found', 404);
        if ($method === 'PATCH') { $b = body(); $done = array_key_exists('done', $b) ? (int)(bool)$b['done'] : (int)$row['done']; $title = array_key_exists('title', $b) ? (s($b['title'], 200) ?: $row['title']) : $row['title']; q('UPDATE task_subs SET done=?, title=?, done_at=' . ($done ? 'NOW()' : 'NULL') . ' WHERE id=?', [$done, $title, $sid]); out(['ok' => true]); }
        if ($method === 'DELETE') { if (!$full && (int)$row['created_by'] !== $uid) fail('Not allowed', 403); q('DELETE FROM task_subs WHERE id=?', [$sid]); out(['ok' => true]); }
    }
    fail('Not found', 404);
}
