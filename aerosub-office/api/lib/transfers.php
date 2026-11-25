<?php
declare(strict_types=1);
// Task transfers must be answered. Moving a task to someone offers it: the person it is offered to sees an Accept / Decline banner, and the task stays with its
// current assignee until they accept. Routes (task = the copy offered to the new person, or the original it came from):
//   POST tasks/{copyId}/transfer/accept
//   POST tasks/{copyId}/transfer/decline {reason}
//   POST tasks/{originalId}/transfer/withdraw        the person who made the offer takes it back

function tr_ensure(): void { require_once __DIR__ . '/flows.php'; flow_ensure(); }
function tr_notify_set(array $ids, int $skip, string $type, string $title, string $body, string $link): void { foreach (array_unique(array_filter(array_map('intval', $ids))) as $n) if ($n !== $skip) notify($n, $type, $title, $body, $link); }
function tr_answer(array $t, array $u, string $act, array $b): never {
    tr_ensure(); $uid = (int)$u['id']; if ($u['role'] === 'founder') fail('Founder accounts are read-only', 403);
    $t = one('SELECT t.*, a.name AS assignee_name FROM tasks t LEFT JOIN users a ON a.id=t.assignee_id WHERE t.id=?', [(int)$t['id']]);
    if ($act === 'withdraw') {
        if (($t['transfer_state'] ?? '') !== 'offered') fail('There is no waiting transfer on this task', 400);
        $offer = one("SELECT user_id FROM task_updates WHERE task_id=? AND kind='transfer_offer' ORDER BY id DESC LIMIT 1", [(int)$t['id']]);
        if ($u['role'] !== 'super_admin' && (int)($offer['user_id'] ?? 0) !== $uid && (int)$t['creator_id'] !== $uid) fail('Only the person who offered the task can take it back', 403);
        $copy = one('SELECT id,assignee_id,title FROM tasks WHERE id=? AND transfer_state=\'pending\'', [(int)$t['transferred_to_id']]);
        if ($copy) { q('DELETE FROM task_updates WHERE task_id=?', [(int)$copy['id']]); q('DELETE FROM tasks WHERE id=?', [(int)$copy['id']]); notify((int)$copy['assignee_id'], 'task_transfer', $u['name'] . ' took back the offer of “' . $t['title'] . '”', '', '#/tasks');
        }
        q('UPDATE tasks SET transfer_state=NULL, transferred_to_id=NULL WHERE id=?', [(int)$t['id']]); q('INSERT INTO task_updates (task_id,user_id,kind,data) VALUES (?,?,?,?)', [(int)$t['id'], $uid, 'transfer_declined', json_encode(['text' => 'Transfer offer taken back'], JSON_UNESCAPED_UNICODE)]); out(['ok' => true, 'id' => (int)$t['id']]);
    }
    if (($t['transfer_state'] ?? '') !== 'pending') fail('This task is not waiting for an answer', 400);
    if ((int)$t['assignee_id'] !== $uid) fail('Only the person the task was offered to can answer', 403);
    $old = one("SELECT t.*, a.name AS assignee_name FROM tasks t LEFT JOIN users a ON a.id=t.assignee_id WHERE t.transferred_to_id=? AND t.transfer_state='offered'", [(int)$t['id']]); if (!$old) fail('The original task is gone', 409);
    $offer = one("SELECT user_id,data FROM task_updates WHERE task_id=? AND kind='transfer_offer' ORDER BY id DESC LIMIT 1", [(int)$old['id']]); $by = (int)($offer['user_id'] ?? 0); $note = (string)(json_decode((string)($offer['data'] ?? ''), true)['note'] ?? '');
    $who = [$by, (int)$old['assignee_id'], (int)$old['creator_id']];
    if ($act === 'accept') {
        $pdo = db(); $pdo->beginTransaction();
        try {
            q("UPDATE tasks SET transfer_state=NULL, status='todo' WHERE id=?", [(int)$t['id']]);
            q("UPDATE tasks SET status='transferred', transfer_state=NULL, status_reason=?, completed_at=NULL WHERE id=?", [$note ?: ('Transferred to ' . $t['assignee_name']), (int)$old['id']]);
            $info = json_encode(['from' => $old['assignee_name'], 'to' => $t['assignee_name'], 'note' => $note, 'old_task' => (int)$old['id'], 'new_task' => (int)$t['id']], JSON_UNESCAPED_UNICODE);
            q('INSERT INTO task_updates (task_id,user_id,kind,data) VALUES (?,?,?,?)', [(int)$old['id'], $uid, 'transfer', $info]); q('INSERT INTO task_updates (task_id,user_id,kind,data) VALUES (?,?,?,?)', [(int)$t['id'], $uid, 'transfer', $info]); $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        tr_notify_set($who, $uid, 'task_transfer', '✅ ' . $u['name'] . ' accepted “' . $t['title'] . '”', 'It is now theirs', '#/tasks/' . (int)$t['id']); log_act($uid, 'task_transfer_accepted', (string)$t['title']); out(['ok' => true, 'id' => (int)$t['id']]);
    }
    if ($act === 'decline') {
        $reason = s($b['reason'] ?? '', 300); q('DELETE FROM task_updates WHERE task_id=?', [(int)$t['id']]); q('DELETE FROM tasks WHERE id=?', [(int)$t['id']]);
        q('UPDATE tasks SET transfer_state=NULL, transferred_to_id=NULL WHERE id=?', [(int)$old['id']]); q('INSERT INTO task_updates (task_id,user_id,kind,data) VALUES (?,?,?,?)', [(int)$old['id'], $uid, 'transfer_declined', json_encode(['text' => $u['name'] . ' declined the transfer' . ($reason !== '' ? ': ' . $reason : '')], JSON_UNESCAPED_UNICODE)]);
        tr_notify_set($who, $uid, 'task_transfer', '✋ ' . $u['name'] . ' declined “' . $t['title'] . '”', $reason !== '' ? $reason : 'It stays with ' . $old['assignee_name'], '#/tasks/' . (int)$old['id']); log_act($uid, 'task_transfer_declined', (string)$t['title']); out(['ok' => true, 'id' => (int)$old['id']]);
    }
    fail('Not found', 404);
}
