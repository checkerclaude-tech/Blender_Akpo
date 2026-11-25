<?php
declare(strict_types=1);
// The task horn: the person who gave a task blows a horn at whoever has it. It pops up on their screen with a trumpet blast and the sender's message;
// they can snooze it or answer with a quick reply. Routes (all need a signed-in person):
//   POST horn {task_id, message}            blow the horn (task creator, managers and admins), at the person the task is assigned to
//   GET  horn/inbox                         horns waiting for me (new ones and snoozes that have run out)
//   POST horn/{id}/reply {action, minutes}  action: snooze | on_it | paused | angry | almost
//   GET  horn/task/{taskId}                 what was blown on a task and how it was answered (the sender's view)
$u = need_user(); $uid = (int)$u['id']; $sub = $seg[1] ?? ''; $hid = ctype_digit($sub) ? (int)$sub : 0;
try { if (!one("SHOW TABLES LIKE 'task_horns'")) { require_once __DIR__ . '/migrate_fin3.php'; migrate_fin_ext(); } } catch (Throwable $e) { error_log('Horn schema: ' . $e->getMessage()); }
const HORN_REPLIES = ['on_it' => 'On it 💪', 'paused' => 'Paused ⏸️', 'angry' => '😠', 'almost' => 'Almost done! 🏁'];
function horn_pub(array $r): array {
    return ['id' => (int)$r['id'], 'task_id' => (int)$r['task_id'], 'task' => $r['task_title'], 'from' => $r['from_name'], 'message' => $r['message'], 'created_at' => $r['created_at'], 'snoozes' => (int)$r['snoozes'], 'status' => $r['status']];
}
if ($method === 'POST' && $sub === '') {
    $b = body(); $tid = (int)($b['task_id'] ?? 0); $t = $tid ? one('SELECT id,title,assignee_id,creator_id,status FROM tasks WHERE id=?', [$tid]) : null; if (!$t) fail('Task not found', 404);
    $isCreator = (int)$t['creator_id'] === $uid; $isAssignee = (int)$t['assignee_id'] === $uid; $isBoss = $isCreator || in_array($u['role'], ['super_admin', 'manager'], true);
    if (!$isBoss && !$isAssignee) fail('Only the person who gave the task, a manager, or the person doing it can blow the horn', 403);
    // the person who gave the task (or a manager) blows it at whoever has the task; whoever has the task blows it back at the person who gave it
    $to = $isAssignee && !$isCreator ? (int)$t['creator_id'] : (int)$t['assignee_id']; if (!$to) fail('Nobody has this task yet'); if ($to === $uid) fail('You are the only person on this task');
    if (in_array($t['status'], ['done', 'canceled'], true)) fail('This task is already closed');
    $msg = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/u', '', (string)($b['message'] ?? ''))); $msg = mb_substr($msg !== '' ? $msg : 'Please check this task!', 0, 120);
    if ((int)(one('SELECT COUNT(*) n FROM task_horns WHERE from_user_id=? AND task_id=? AND created_at > (NOW() - INTERVAL 20 SECOND)', [$uid, $tid])['n'] ?? 0)) fail('You just blew the horn on this task. Give them a moment.', 429);
    if ((int)(one('SELECT COUNT(*) n FROM task_horns WHERE from_user_id=? AND created_at > (NOW() - INTERVAL 1 HOUR)', [$uid])['n'] ?? 0) >= 15) fail('That is a lot of horns for one hour. Try again a little later.', 429);
    q("UPDATE task_horns SET status='replied', reply='replaced', replied_at=NOW() WHERE task_id=? AND to_user_id=? AND status IN ('open','snoozed')", [$tid, $to]);   // a new blast replaces the old one
    q('INSERT INTO task_horns (task_id,from_user_id,to_user_id,message) VALUES (?,?,?,?)', [$tid, $uid, $to, $msg]); $id = (int)db()->lastInsertId();
    notify($to, 'horn', '📯 ' . $u['name'] . ': ' . $msg, 'Task: ' . $t['title'], '#/tasks/' . $tid); log_act($uid, 'horn', $t['title'] . ' → user ' . $to);
    out(['ok' => true, 'id' => $id], 201);
}
if ($method === 'GET' && $sub === 'inbox') {
    out(['horns' => array_map('horn_pub', all("SELECT h.*, t.title AS task_title, u.name AS from_name FROM task_horns h JOIN tasks t ON t.id=h.task_id JOIN users u ON u.id=h.from_user_id WHERE h.to_user_id=? AND t.status NOT IN ('done','canceled') AND (h.status='open' OR (h.status='snoozed' AND h.snooze_until <= NOW())) ORDER BY h.id DESC LIMIT 5", [$uid]))]);
}
if ($method === 'GET' && $sub === 'task') {
    $tid = (int)($seg[2] ?? 0); $t = one('SELECT creator_id,assignee_id FROM tasks WHERE id=?', [$tid]); if (!$t) fail('Not found', 404); $boss = (int)$t['creator_id'] === $uid || in_array($u['role'], ['super_admin', 'manager'], true);
    if (!$boss && (int)$t['assignee_id'] !== $uid) fail('Not allowed', 403);
    out(['horns' => array_map(fn($r) => ['id' => (int)$r['id'], 'message' => $r['message'], 'created_at' => $r['created_at'], 'to' => $r['to_name'], 'status' => $r['status'], 'reply' => HORN_REPLIES[$r['reply']] ?? null, 'snooze_until' => $r['status'] === 'snoozed' ? $r['snooze_until'] : null],
        all('SELECT h.*, u.name AS to_name FROM task_horns h JOIN users u ON u.id=h.to_user_id WHERE h.task_id=?' . ($boss ? '' : ' AND (h.from_user_id=' . $uid . ' OR h.to_user_id=' . $uid . ')') . ' ORDER BY h.id DESC LIMIT 8', [$tid]))]);
}
if ($method === 'POST' && $hid && ($seg[2] ?? '') === 'reply') {
    $h = one('SELECT h.*, t.title AS task_title FROM task_horns h JOIN tasks t ON t.id=h.task_id WHERE h.id=? AND h.to_user_id=?', [$hid, $uid]); if (!$h) fail('Not found', 404);
    if (!in_array($h['status'], ['open', 'snoozed'], true)) out(['ok' => true]);   // already answered (e.g. on another screen)
    $b = body(); $a = in((string)($b['action'] ?? ''), array_merge(['snooze'], array_keys(HORN_REPLIES)), ''); if ($a === '') fail('Choose a reply');
    if ($a === 'snooze') { $min = (int)in((string)($b['minutes'] ?? 15), ['5', '15', '30', '60'], '15');
        q("UPDATE task_horns SET status='snoozed', snooze_until=(NOW() + INTERVAL $min MINUTE), snoozes=snoozes+1 WHERE id=?", [$hid]);
        notify((int)$h['from_user_id'], 'horn_reply', '😴 ' . $u['name'] . ' snoozed your horn for ' . ($min >= 60 ? '1 hour' : $min . ' minutes'), 'Task: ' . $h['task_title'], '#/tasks/' . $h['task_id']); out(['ok' => true, 'snooze_minutes' => $min]); }
    q("UPDATE task_horns SET status='replied', reply=?, replied_at=NOW() WHERE id=?", [$a, $hid]);
    notify((int)$h['from_user_id'], 'horn_reply', '📯 ' . $u['name'] . ' replied: ' . HORN_REPLIES[$a], 'Task: ' . $h['task_title'], '#/tasks/' . $h['task_id']); out(['ok' => true]);
}
fail('Not found', 404);
