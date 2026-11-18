<?php
declare(strict_types=1);
// Task extras. Included from /api/tasks/{id}/{files|share|defer|updates|report}, and routes /api/research, /api/projects.
// When included from the tasks route, $u, $uid, $id, $t, $sub, $canEdit, $method are already set.
$u = need_user(); $uid = (int)$u['id']; $role = $u['role']; $ro = $role === 'founder'; $mgr = in_array($role, ['super_admin', 'manager'], true);
$REPORT_TYPES = ['ut' => 'UT form', 'drone' => 'Drone readiness form', 'deployment' => 'Deployment form', 'hse' => 'Health & safety form', 'closeout' => 'Close-out report'];
function tx_json($v, int $max = 60000): string { $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($j === false || strlen($j) > $max) fail('That entry is too large'); return $j; }
function tx_str($v, int $n = 2000): string { return mb_substr(trim((string)($v ?? '')), 0, $n); }
function tx_url($v): string { $v = trim((string)($v ?? '')); if ($v === '') return ''; if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v; return filter_var($v, FILTER_VALIDATE_URL) ? mb_substr($v, 0, 500) : ''; }
function tx_my_projects(array $u): array {
    if (in_array($u['role'], ['super_admin', 'manager', 'founder'], true)) return all("SELECT id,name,client,status FROM projects ORDER BY status,name");
    return all("SELECT p.id,p.name,p.client,p.status FROM projects p JOIN project_members m ON m.project_id=p.id WHERE m.user_id=? ORDER BY p.status,p.name", [$u['id']]);
}
function tx_save_file(int $tid, int $uid, array $a): int {
    $data = base64_decode((string)($a['data'] ?? ''), true); if ($data === false) fail('The file could not be read');
    try { return task_store_file($tid, $uid, (string)($a['name'] ?? 'file'), (string)($a['type'] ?? ''), $data); } catch (RuntimeException $e) { fail($e->getMessage()); }
}

/* ======== sub-resources of one task ======== */
if (isset($t) && isset($id) && $seg[0] === 'tasks') {
    $b = $method === 'GET' ? [] : body(); $fid = isset($seg[3]) && ctype_digit($seg[3]) ? (int)$seg[3] : 0;
    $isAssignee = (int)$t['assignee_id'] === $uid; $full = $canEdit($t);
    if ($sub === 'files') {
        if ($method === 'GET' && $fid) { $f = one('SELECT * FROM task_files WHERE id=? AND task_id=?', [$fid, $id]); if (!$f) fail('Not found', 404);
            $p = task_storage_dir() . '/' . preg_replace('/[^a-f0-9]/', '', $f['path']); if (!is_file($p)) fail('The file is no longer on the server', 404);
            $inline = isset($_GET['view']) && preg_match('~^image/(png|jpe?g|gif|webp)$~', $f['mime']); $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $f['name']) ?: 'file';
            header('Content-Type: ' . ($inline ? $f['mime'] : 'application/octet-stream')); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=3600');
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($f['name'])); header('Content-Length: ' . filesize($p)); readfile($p); exit; }
        if ($method === 'POST') { if ($ro) fail('Founder accounts are read-only', 403); if (one('SELECT COUNT(*) c FROM task_files WHERE task_id=?', [$id])['c'] >= 40) fail('This task already has 40 files');
            $fileId = tx_save_file($id, $uid, $b); foreach (array_unique(array_filter([$t['assignee_id'], $t['creator_id']])) as $n) if ((int)$n !== $uid) notify((int)$n, 'task_file', $u['name'] . ' added a file to: ' . $t['title'], mb_substr((string)($b['name'] ?? ''), 0, 100), '#/tasks/' . $id);
            out(['id' => $fileId], 201); }
        if ($method === 'DELETE' && $fid) { $f = one('SELECT * FROM task_files WHERE id=? AND task_id=?', [$fid, $id]); if (!$f) fail('Not found', 404); if (!$full && (int)$f['user_id'] !== $uid) fail('Not allowed', 403);
            q('DELETE FROM task_files WHERE id=?', [$fid]); task_delete_file_blob($f['path']); out(['ok' => true]); }
        fail('Bad request', 400);
    }
    if ($sub === 'share') {
        if ($ro) fail('Founder accounts are read-only', 403);
        if ($method === 'POST') { $ids = array_values(array_unique(array_filter(array_map('intval', (array)($b['user_ids'] ?? []))))); if (!$ids) fail('Choose at least one person'); $names = []; $emails = [];
            foreach ($ids as $x) { if ($x === $uid) continue; $r = one('SELECT id,name,email FROM users WHERE id=? AND active=1', [$x]); if (!$r) continue;
                $new = q('INSERT IGNORE INTO task_shares (task_id,user_id,shared_by) VALUES (?,?,?)', [$id, $x, $uid])->rowCount();
                if ($new) notify($x, $t['task_type'] === 'meeting' ? 'meeting_invite' : 'task_shared', ($t['task_type'] === 'meeting' ? 'Meeting invitation: ' : $u['name'] . ' shared a task with you: ') . $t['title'], $t['starts_at'] ? 'Starts ' . date('D j M, H:i', strtotime($t['starts_at'])) : tx_str($b['note'] ?? '', 140), '#/tasks/' . $id);
                $names[] = $r['name']; $emails[$x] = $r['email']; }
            q('INSERT INTO task_updates (task_id,user_id,kind,data) VALUES (?,?,?,?)', [$id, $uid, 'share', tx_json(['with' => $names, 'note' => tx_str($b['note'] ?? '', 500)])]);
            out(['ok' => true, 'shared' => $names, 'emails' => (object)$emails]); }
        if ($method === 'DELETE' && $fid) { if (!$full && $fid !== $uid) fail('Not allowed', 403); q('DELETE FROM task_shares WHERE task_id=? AND user_id=?', [$id, $fid]); out(['ok' => true]); }
        fail('Bad request', 400);
    }
    if ($sub === 'defer' && $method === 'POST') {
        if ($ro) fail('Founder accounts are read-only', 403); if (!$full && !$isAssignee) fail('Not allowed', 403);
        $nd = d($b['due_date'] ?? null); if (!$nd) fail('Choose the new due date'); $why = tx_str($b['reason'] ?? '', 500);
        q('UPDATE tasks SET due_date=?, defer_count=defer_count+1 WHERE id=?', [$nd, $id]);
        q('INSERT INTO task_updates (task_id,user_id,kind,data) VALUES (?,?,?,?)', [$id, $uid, 'defer', tx_json(['from' => $t['due_date'], 'to' => $nd, 'reason' => $why])]);
        foreach (array_unique(array_filter([$t['creator_id'], $t['assignee_id']])) as $n) if ((int)$n !== $uid) notify((int)$n, 'task_deferred', $u['name'] . ' deferred: ' . $t['title'], 'New due date ' . date('D j M', strtotime($nd)) . ($why ? ' — ' . mb_substr($why, 0, 100) : ''), '#/tasks/' . $id);
        out(['ok' => true]);
    }
    if ($sub === 'updates' && $method === 'POST') {
        if ($ro) fail('Founder accounts are read-only', 403); if (!$full && !$isAssignee) fail('Only the person doing this task can add to it', 403);
        $kind = in((string)($b['kind'] ?? ''), ['research', 'progress', 'operations', 'note'], 'note'); $dta = (array)($b['data'] ?? []); $clean = [];
        if ($kind === 'research') { foreach (['research_type', 'contact_name', 'contact_company', 'contact_email', 'contact_phone', 'offering', 'notes'] as $k) $clean[$k] = tx_str($dta[$k] ?? '', $k === 'notes' ? 20000 : 500);
            if ($clean['contact_email'] !== '' && !filter_var($clean['contact_email'], FILTER_VALIDATE_EMAIL)) fail('That contact email does not look right'); $clean['files'] = array_values(array_filter(array_map('intval', (array)($dta['files'] ?? [])))); }
        elseif ($kind === 'progress') { $clean = ['stage' => tx_str($dta['stage'] ?? '', 1000), 'url' => tx_url($dta['url'] ?? ''), 'image' => (int)($dta['image'] ?? 0), 'milestones' => []];
            foreach (array_slice((array)($dta['milestones'] ?? []), 0, 20) as $m) { $ti = tx_str($m['title'] ?? '', 160); if ($ti !== '') $clean['milestones'][] = ['title' => $ti, 'deadline' => d($m['deadline'] ?? null), 'done' => !empty($m['done'])]; }
            if ($clean['stage'] === '' && !$clean['url'] && !$clean['image'] && !$clean['milestones']) fail('Add a link, a screenshot, a short description or a timeline'); }
        elseif ($kind === 'operations') { $clean = ['text' => tx_str($dta['text'] ?? '', 10000)]; if ($clean['text'] === '') fail('Type what was done'); }
        else { $clean = ['text' => tx_str($dta['text'] ?? '', 3000)]; if ($clean['text'] === '') fail('Write a note first'); }
        if (isset($clean['image']) && $clean['image'] && !one('SELECT id FROM task_files WHERE id=? AND task_id=?', [$clean['image'], $id])) $clean['image'] = 0;
        q('INSERT INTO task_updates (task_id,user_id,kind,data) VALUES (?,?,?,?)', [$id, $uid, $kind, tx_json($clean)]);
        $newStatus = !empty($b['complete']) ? 'done' : ($t['status'] === 'todo' ? 'in_progress' : $t['status']);
        if ($newStatus !== $t['status']) { q('UPDATE tasks SET status=?, completed_at=' . ($newStatus === 'done' ? 'NOW()' : 'NULL') . ' WHERE id=?', [$newStatus, $id]);
            if ($newStatus === 'done' && (int)$t['creator_id'] !== $uid) notify((int)$t['creator_id'], 'task_status', $u['name'] . ' completed: ' . $t['title'], '', '#/tasks/' . $id); }
        out(['ok' => true, 'status' => $newStatus], 201);
    }
    if ($sub === 'report' && $method === 'POST') {
        if ($ro) fail('Founder accounts are read-only', 403); if (!$full && !$isAssignee) fail('Only the person doing this task can file a report', 403);
        $type = (string)($b['type'] ?? ''); if (!isset($REPORT_TYPES[$type])) fail('Choose the report type');
        $pid = (int)($b['project_id'] ?? 0); $okp = array_column(tx_my_projects($u), 'id'); if (!$pid || !in_array($pid, array_map('intval', $okp), true)) fail('Choose one of the projects you have been added to');
        $dta = (array)($b['data'] ?? []); $clean = []; foreach ($dta as $k => $v) { if (!preg_match('/^[a-z0-9_]{1,40}$/', (string)$k)) continue; $clean[$k] = is_bool($v) ? $v : tx_str($v, 5000); }
        if (!$clean) fail('The report is empty');
        q('INSERT INTO field_reports (project_id,task_id,user_id,type,data) VALUES (?,?,?,?,?)', [$pid, $id, $uid, $type, tx_json($clean)]); $rid2 = (int)db()->lastInsertId();
        q('UPDATE tasks SET project_id=? WHERE id=?', [$pid, $id]);
        q('INSERT INTO task_updates (task_id,user_id,kind,data) VALUES (?,?,?,?)', [$id, $uid, 'report', tx_json(['report_id' => $rid2, 'type' => $type, 'project_id' => $pid])]);
        $newStatus = !empty($b['complete']) ? 'done' : ($t['status'] === 'todo' ? 'in_progress' : $t['status']);
        if ($newStatus !== $t['status']) q('UPDATE tasks SET status=?, completed_at=' . ($newStatus === 'done' ? 'NOW()' : 'NULL') . ' WHERE id=?', [$newStatus, $id]);
        out(['ok' => true, 'id' => $rid2], 201);
    }
    fail('Bad request', 400);
}

/* ======== /api/research : notes + imports, each is automatically a task ======== */
if ($seg[0] === 'research') {
    $b = $method === 'GET' ? [] : body();
    if ($method === 'GET') { $w = in_array($role, ['super_admin', 'founder'], true) ? ['1=1', []] : ['(t.assignee_id=? OR t.creator_id=? OR EXISTS(SELECT 1 FROM task_shares ts WHERE ts.task_id=t.id AND ts.user_id=?))', [$uid, $uid, $uid]];
        if ($role === 'manager' && $u['department_ids']) $w = ['(t.assignee_id=? OR t.creator_id=? OR t.department_id IN (' . dept_sql_list($u) . '))', [$uid, $uid]];
        $rows = all("SELECT t.id,t.title,t.status,t.created_at,t.updated_at,a.name AS assignee_name,(SELECT COUNT(*) FROM task_files f WHERE f.task_id=t.id) AS file_count,
            (SELECT x.data FROM task_updates x WHERE x.task_id=t.id AND x.kind='research' ORDER BY x.id DESC LIMIT 1) AS last FROM tasks t LEFT JOIN users a ON a.id=t.assignee_id WHERE t.task_type='research' AND {$w[0]} ORDER BY t.updated_at DESC LIMIT 200", $w[1]);
        foreach ($rows as &$r) { $r['last'] = $r['last'] ? json_decode($r['last'], true) : null; } unset($r); out($rows); }
    if ($method === 'POST') { if ($ro) fail('Founder accounts are read-only', 403); $title = tx_str($b['title'] ?? '', 200); if ($title === '') fail('Give the research a title');
        $dta = (array)($b['data'] ?? []); $clean = []; foreach (['research_type', 'contact_name', 'contact_company', 'contact_email', 'contact_phone', 'offering', 'notes'] as $k) $clean[$k] = tx_str($dta[$k] ?? '', $k === 'notes' ? 20000 : 500);
        if ($clean['contact_email'] !== '' && !filter_var($clean['contact_email'], FILTER_VALIDATE_EMAIL)) fail('That contact email does not look right');
        $done = !empty($b['complete']); $files = (array)($b['files'] ?? []); if (count($files) > 10) fail('Too many files (maximum 10)');
        q('INSERT INTO tasks (title,description,status,priority,assignee_id,creator_id,department_id,task_type,completed_at) VALUES (?,?,?,?,?,?,?,?,' . ($done ? 'NOW()' : 'NULL') . ')',
          [$title, tx_str($clean['offering'], 400) ?: null, $done ? 'done' : 'in_progress', 'medium', $uid, $uid, $u['department_id'] ?? null, 'research']); $tid = (int)db()->lastInsertId();
        $ids = []; foreach ($files as $a) $ids[] = tx_save_file($tid, $uid, (array)$a); $clean['files'] = $ids;
        q('INSERT INTO task_updates (task_id,user_id,kind,data) VALUES (?,?,?,?)', [$tid, $uid, 'research', tx_json($clean)]); log_act($uid, 'research_created', $title);
        out(['id' => $tid], 201); }
    fail('Bad request', 400);
}

/* ======== /api/projects : field projects + their reports ======== */
if ($seg[0] === 'projects') {
    $b = $method === 'GET' ? [] : body(); $pid = isset($seg[1]) && ctype_digit($seg[1]) ? (int)$seg[1] : 0; $act = $pid ? ($seg[2] ?? '') : ($seg[1] ?? '');
    $mine = array_map('intval', array_column(tx_my_projects($u), 'id'));
    if ($method === 'GET' && !$pid && $act === 'types') out($REPORT_TYPES);
    if ($method === 'GET' && !$pid) { $rows = tx_my_projects($u); foreach ($rows as &$r) { $r['members'] = all('SELECT u.id,u.name FROM project_members m JOIN users u ON u.id=m.user_id WHERE m.project_id=? ORDER BY u.name', [$r['id']]);
        $r['report_count'] = (int)one('SELECT COUNT(*) c FROM field_reports WHERE project_id=?', [$r['id']])['c']; } unset($r); out(['projects' => $rows, 'can_edit' => $mgr]); }
    if ($method === 'POST' && !$pid) { if (!$mgr) fail('Only managers and admins can create projects', 403); $n = tx_str($b['name'] ?? '', 160); if ($n === '') fail('Project name is required');
        q('INSERT INTO projects (name,client,created_by) VALUES (?,?,?)', [$n, tx_str($b['client'] ?? '', 160) ?: null, $uid]); $np = (int)db()->lastInsertId();
        foreach (array_unique(array_filter(array_map('intval', (array)($b['members'] ?? [])))) as $x) if (one('SELECT id FROM users WHERE id=? AND active=1', [$x])) q('INSERT IGNORE INTO project_members (project_id,user_id) VALUES (?,?)', [$np, $x]);
        out(['id' => $np], 201); }
    if (!$pid) fail('Not found', 404);
    $p = one('SELECT * FROM projects WHERE id=?', [$pid]); if (!$p) fail('Not found', 404);
    if ($method === 'PATCH') { if (!$mgr) fail('Not allowed', 403); q('UPDATE projects SET name=?, client=?, status=? WHERE id=?', [tx_str($b['name'] ?? $p['name'], 160) ?: $p['name'], array_key_exists('client', $b) ? (tx_str($b['client'], 160) ?: null) : $p['client'], in((string)($b['status'] ?? $p['status']), ['active', 'closed'], $p['status']), $pid]);
        if (array_key_exists('members', $b)) { q('DELETE FROM project_members WHERE project_id=?', [$pid]); foreach (array_unique(array_filter(array_map('intval', (array)$b['members']))) as $x) if (one('SELECT id FROM users WHERE id=? AND active=1', [$x])) q('INSERT IGNORE INTO project_members (project_id,user_id) VALUES (?,?)', [$pid, $x]); }
        out(['ok' => true]); }
    if ($method === 'DELETE') { if (!$mgr) fail('Not allowed', 403); q('DELETE FROM projects WHERE id=?', [$pid]); out(['ok' => true]); }
    if (!in_array($pid, $mine, true)) fail('Not found', 404);
    if ($method === 'GET' && $act === 'reports') out(array_map(function ($r) { $r['data'] = json_decode($r['data'], true); return $r; },
        all('SELECT r.id,r.type,r.data,r.created_at,r.task_id,u.name AS user_name FROM field_reports r LEFT JOIN users u ON u.id=r.user_id WHERE r.project_id=? ORDER BY r.id DESC LIMIT 200', [$pid])));
    fail('Bad request', 400);
}
fail('Bad request', 400);
