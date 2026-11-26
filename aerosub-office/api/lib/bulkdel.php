<?php
declare(strict_types=1);
// Deleting, one or many (tasks and research, RFQs, void finance documents, contacts, companies, vendors, prospects), and "keep a copy" of a deleted task.
//   POST bulk/del  {kind, ids:[...]}   kind: tasks | rfqs | docs | contacts | companies | vendors | signals     -> {deleted, skipped, errors[]}
//   GET  bulk/keeps/{id}            POST bulk/keeps/{id}/keep | dismiss      (the person a deleted task was shared with or assigned to)
// When a task is deleted, everyone it was assigned or shared to (except the person deleting it) is told, and can keep a copy for themselves.
require_once __DIR__ . '/migrate.php';

function bd_ensure(): void {
    static $d = false; if ($d) return; $d = true;
    try { if (!col_exists('pr_signals', 'scan_no') || !one("SHOW TABLES LIKE 'task_keeps'") || !one("SHOW TABLES LIKE 'pr_stars'") || one("SHOW INDEX FROM pr_signals WHERE Key_name='uq_url'")) { require_once __DIR__ . '/migrate_fin3.php'; migrate_fin_ext(); } } catch (Throwable $e) { error_log('Bulk delete schema: ' . $e->getMessage()); }
}
/** Deletes a task. Everyone it was assigned or shared to hears about it and can keep a copy. */
function bd_task_delete(array $t, array $u): void {
    bd_ensure(); $id = (int)$t['id']; $me = (int)$u['id']; $who = [];
    if (!empty($t['assignee_id'])) $who[(int)$t['assignee_id']] = 1;
    foreach (all('SELECT user_id FROM task_shares WHERE task_id=?', [$id]) as $x) $who[(int)$x['user_id']] = 1;
    unset($who[$me]);
    if ($who) { $snap = json_encode(['title' => $t['title'], 'description' => $t['description'] ?? '', 'priority' => $t['priority'] ?? 'medium', 'due_date' => $t['due_date'] ?? null, 'task_type' => $t['task_type'] ?? 'general'], JSON_UNESCAPED_UNICODE);
        foreach (array_keys($who) as $uid) { q('INSERT INTO task_keeps (user_id,from_user,title,snapshot) VALUES (?,?,?,?)', [$uid, $me, mb_substr((string)$t['title'], 0, 200), $snap]); $kid = (int)db()->lastInsertId();
            notify($uid, 'task_deleted', '“' . mb_substr((string)$t['title'], 0, 120) . '” was deleted by ' . $u['name'], 'It was ' . ((int)($t['assignee_id'] ?? 0) === $uid ? 'assigned' : 'shared') . ' to you. Open this to keep a copy for yourself.', '#/kept/' . $kid); } }
    q('DELETE FROM tasks WHERE id=?', [$id]); log_act($me, 'task_deleted', (string)$t['title']);
}
function bd_can_edit_task(array $t, array $u): bool {
    if ($u['role'] === 'super_admin') return true; if ($u['role'] === 'founder') return false; if ((int)$t['creator_id'] === (int)$u['id']) return true;
    return !empty($t['department_id']) && in_array((int)$t['department_id'], managed_dept_ids($u), true);
}
/** One RFQ with everything that belongs to it. A paid RFQ is a recorded sale and stays. The email it came from stays marked as seen, so a scan does not add it again. */
function bd_rfq_delete(int $id): string {
    $r = one('SELECT * FROM rfqs WHERE id=?', [$id]); if (!$r) return 'Not found'; if ($r['status'] === 'paid') return $r['code'] . ' is paid: it is a recorded sale';
    if (one('SELECT id FROM delivery_notes WHERE rfq_id=? AND printed_at IS NOT NULL LIMIT 1', [$id])) return $r['code'] . ' has a printed delivery note';
    foreach (all('SELECT path FROM rfq_files WHERE rfq_id=?', [$id]) as $f) { $p = rfq_dir() . '/' . basename((string)$f['path']); if (is_file($p)) @unlink($p); }
    q('DELETE FROM rfq_files WHERE rfq_id=?', [$id]); q('DELETE FROM rfq_events WHERE rfq_id=?', [$id]); q('DELETE FROM delivery_notes WHERE rfq_id=?', [$id]);
    foreach (array_filter([(int)($r['task_id'] ?? 0), (int)($r['po_task_id'] ?? 0)]) as $tid) q("DELETE FROM tasks WHERE id=? AND source='rfq' AND status IN ('todo','canceled')", [$tid]);
    q('DELETE FROM rfqs WHERE id=?', [$id]); return '';
}

if (!isset($r) || $r !== 'bulk') return;   // loaded as a library

bd_ensure(); $u = need_user(); $uid = (int)$u['id']; $mgr = in_array($u['role'], ['super_admin', 'manager'], true); $kind = $seg[1] ?? ''; $s3 = $seg[2] ?? ''; $s4 = $seg[3] ?? '';
if ($kind === 'keeps') {   // a deleted task offered to the person it was shared or assigned to
    $kid = (int)$s3; $k = $kid ? one('SELECT * FROM task_keeps WHERE id=? AND user_id=?', [$kid, $uid]) : null; if (!$k) fail('Not found', 404);
    if ($method === 'GET') { $s = json_decode((string)$k['snapshot'], true) ?: []; $from = one('SELECT name FROM users WHERE id=?', [$k['from_user']]); out(['keep' => ['id' => (int)$k['id'], 'title' => $k['title'], 'description' => $s['description'] ?? '', 'priority' => $s['priority'] ?? 'medium', 'due_date' => $s['due_date'] ?? null, 'deleted_by' => $from['name'] ?? '', 'deleted_at' => $k['created_at'], 'state' => $k['state']]]); }
    if ($method === 'POST' && $s4 === 'keep') { if ($k['state'] === 'kept' && $k['new_task_id']) out(['ok' => true, 'task_id' => (int)$k['new_task_id']]); if ($u['role'] === 'founder') fail('Founder accounts are read-only', 403); $s = json_decode((string)$k['snapshot'], true) ?: [];
        q("INSERT INTO tasks (title,description,status,priority,assignee_id,creator_id,due_date,task_type) VALUES (?,?,'todo',?,?,?,?,?)", [mb_substr((string)$k['title'], 0, 200), mb_substr((string)($s['description'] ?? ''), 0, 8000), in((string)($s['priority'] ?? ''), ['low', 'medium', 'high', 'urgent'], 'medium'), $uid, $uid, d($s['due_date'] ?? null), in((string)($s['task_type'] ?? ''), ['general', 'research', 'build', 'operations', 'field', 'rfq', 'meeting'], 'general') === 'meeting' ? 'general' : in((string)($s['task_type'] ?? ''), ['general', 'research', 'build', 'operations', 'field', 'rfq', 'meeting'], 'general')]);
        $nid = (int)db()->lastInsertId(); q("UPDATE task_keeps SET state='kept', new_task_id=? WHERE id=?", [$nid, $kid]); out(['ok' => true, 'task_id' => $nid]); }
    if ($method === 'POST' && $s4 === 'dismiss') { q("UPDATE task_keeps SET state='dismissed' WHERE id=? AND state='new'", [$kid]); out(['ok' => true]); }
    fail('Bad request', 405);
}
if ($method !== 'POST') fail('Bad request', 405); if ($u['role'] === 'founder') fail('Founder accounts are read-only', 403);
$b = body(); $kind = (string)($b['kind'] ?? ''); $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($b['ids'] ?? [])), fn($i) => $i > 0))), 0, 300); if (!$ids) fail('Nothing was selected');
$tabOf = ['contacts' => 'pipeline', 'companies' => 'pipeline', 'vendors' => 'pipeline', 'signals' => 'campaigns', 'inbox' => 'campaigns']; if (isset($tabOf[$kind])) { if (!tab_access($u, $tabOf[$kind])) fail('You do not have access to this section', 403); if (tab_readonly($u, $tabOf[$kind])) fail('Your access here is read-only', 403); }
if ($kind === 'docs' || $kind === 'rfqs') { if (!can_finance($u) && !$mgr) fail('Only Finance, managers and admins can delete these', 403); if (tab_readonly($u, 'finance')) fail('Your access to Finance is read-only', 403); }
if ($kind === 'tasks' && tab_readonly($u, 'tasks')) fail('Your access to Tasks is read-only', 403);
$done = 0; $skip = []; $mark = function (string $m) use (&$skip): void { $skip[] = $m; };
foreach ($ids as $i) {
    try {
        if ($kind === 'tasks') { $t = one('SELECT * FROM tasks WHERE id=?', [$i]); if (!$t) continue; if (!bd_can_edit_task($t, $u)) { $mark('“' . mb_substr((string)$t['title'], 0, 50) . '”: only its creator, the department head or an admin can delete it'); continue; }
            if ($t['task_type'] === 'meeting' && $t['starts_at'] && !in_array($t['status'], ['canceled', 'done', 'transferred'], true) && strtotime((string)$t['starts_at']) > time() - 3600) { require_once __DIR__ . '/ics.php'; $mt = ics_task($i); if ($mt) { $mt['ics_seq'] = (int)$mt['ics_seq'] + 1; ics_send($mt, ics_people($i), $u, 'cancel'); } }
            bd_task_delete($t, $u); $done++; continue; }
        if ($kind === 'rfqs') { require_once __DIR__ . '/rfq.php'; rfq_ensure(); $m = bd_rfq_delete($i); if ($m === '') { $done++; log_act($uid, 'rfq_deleted', (string)$i); } elseif ($m !== 'Not found') $mark($m); continue; }
        if ($kind === 'docs') { $d = one('SELECT id,number,status,doc_type FROM fin_docs WHERE id=?', [$i]); if (!$d) continue; if (!in_array($d['status'], ['draft', 'void'], true)) { $mark($d['number'] . ' is ' . $d['status'] . ': only drafts and void documents can be deleted'); continue; }
            q('DELETE FROM fin_docs WHERE id=?', [$i]); log_act($uid, 'fin_doc_deleted', (string)$d['number']); $done++; continue; }
        if ($kind === 'contacts') { $c = one('SELECT id,name,owner_id FROM contacts WHERE id=?', [$i]); if (!$c) continue; if (!$mgr && (int)$c['owner_id'] !== $uid) { $mark($c['name'] . ': only the owner or a manager can delete this contact'); continue; } q('DELETE FROM contacts WHERE id=?', [$i]); $done++; continue; }
        if ($kind === 'companies') { if (!$mgr) { $mark('Only managers and admins can delete companies'); break; } $c = one('SELECT name FROM companies WHERE id=?', [$i]); if (!$c) continue; q('DELETE FROM companies WHERE id=?', [$i]); log_act($uid, 'company_deleted', (string)$c['name']); $done++; continue; }
        if ($kind === 'vendors') { if (!$mgr) { $mark('Only managers and admins can delete vendors'); break; } $v = one('SELECT name FROM vendors WHERE id=?', [$i]); if (!$v) continue; q('UPDATE products SET vendor_id=NULL WHERE vendor_id=?', [$i]); q('UPDATE contacts SET vendor_id=NULL WHERE vendor_id=?', [$i]); q('DELETE FROM vendors WHERE id=?', [$i]); $done++; continue; }
        if ($kind === 'signals') { require_once __DIR__ . '/prospect.php'; if (!can_campaigns($u)) { $mark('Prospecting is for the Marketing team'); break; } $n = q('DELETE FROM pr_signals WHERE id=? AND (user_id=? OR user_id IS NULL)', [$i, $uid])->rowCount(); $done += $n; continue; }
        if ($kind === 'inbox') { if (!can_campaigns($u)) { $mark('Inbox automation is for the Marketing team'); break; } require_once __DIR__ . '/inboxauto.php'; ia_ensure(); $n = q("UPDATE ia_items SET status='deleted' WHERE id=? AND created_by=?", [$i, $uid])->rowCount(); $done += $n; continue; }   // removed from my list; the email stays marked as seen so a scan does not bring it back
        fail('Bad request', 404);
    } catch (PDOException $e) { error_log('Bulk delete ' . $kind . ' #' . $i . ': ' . $e->getMessage()); $mark('Item ' . $i . ' could not be deleted'); }
}
if ($done) log_act($uid, 'bulk_delete', $kind . ' x' . $done);
out(['ok' => true, 'deleted' => $done, 'skipped' => count($skip), 'errors' => array_slice($skip, 0, 8)]);
