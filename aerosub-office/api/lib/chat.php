<?php
declare(strict_types=1);
// Team chat: one-to-one chats and groups, with files and tasks shared into the conversation.
// Private to the people in a conversation: there is no route that lets anyone else (administrators included) read it. Routes:
//   GET  chat/people                            colleagues to start a chat with
//   GET  chat/rooms                             my conversations (latest message, unread count)
//   POST chat/rooms {user_id}                   open (or find) a one-to-one chat
//   POST chat/rooms {name, user_ids[]}          make a group
//   GET  chat/rooms/{id}                        the conversation and its members
//   PATCH chat/rooms/{id} {name}                rename a group (its owner)
//   POST chat/rooms/{id}/members {user_ids[]}   add people to a group (its owner)
//   DELETE chat/rooms/{id}/members/{userId}     remove someone (owner), or leave (yourself)
//   GET  chat/rooms/{id}/messages[?after=ID&before=ID]
//   POST chat/rooms/{id}/messages {body} · {task_id, share_task} · {file:{name,type,data}} · {vault_id}   send
//   POST chat/rooms/{id}/read                   I have read up to the latest
//   PATCH / DELETE chat/messages/{id}           edit (24 h) or delete my own message
//   GET  chat/files/{messageId}                 download a shared file (members only)
//   GET  chat/unread                            total unread, per room, and the newest unread message (for the badge and pop-up)
require_once __DIR__ . '/migrate.php';
$u = need_user(); $uid = (int)$u['id']; $sub = $seg[1] ?? ''; $rid = isset($seg[2]) && ctype_digit($seg[2]) ? (int)$seg[2] : 0; $act = $seg[3] ?? '';
try { if (!one("SHOW TABLES LIKE 'chat_rooms'") || !one("SHOW TABLES LIKE 'chat_messages'")) { require_once __DIR__ . '/migrate_fin3.php'; migrate_fin_ext(); } } catch (Throwable $e) { error_log('Chat schema: ' . $e->getMessage()); fail('Chat could not be set up automatically. Open /upgrade.php once as Super Admin.', 500); }
const CHAT_FILE_MAX = 10 * 1048576;
function chat_dir(): string { $dir = (string)(cfg('storage_dir') ?: dirname(__DIR__) . '/storage') . '/chat'; if (!is_dir($dir)) @mkdir($dir, 0775, true); $root = dirname($dir); if (!is_file($root . '/.htaccess')) { @file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n"); @file_put_contents($root . '/index.html', ''); } return $dir; }
function chat_mem(int $room, int $uid): ?array { return one('SELECT * FROM chat_members WHERE room_id=? AND user_id=?', [$room, $uid]); }
function chat_need(int $room, int $uid): array { $r = $room ? one('SELECT r.* FROM chat_rooms r JOIN chat_members m ON m.room_id=r.id AND m.user_id=? WHERE r.id=?', [$uid, $room]) : null; if (!$r) fail('Not found', 404); return $r; }   // someone else's conversation looks like a missing one
function chat_members(int $room): array { return all('SELECT u.id,u.name,m.role,d.name AS department FROM chat_members m JOIN users u ON u.id=m.user_id LEFT JOIN departments d ON d.id=u.department_id WHERE m.room_id=? ORDER BY m.role=\'owner\' DESC, u.name', [$room]); }
function chat_title(array $r, int $uid): string { if ($r['kind'] === 'group') return (string)$r['name']; $p = one('SELECT u.name FROM chat_members m JOIN users u ON u.id=m.user_id WHERE m.room_id=? AND m.user_id<>? LIMIT 1', [(int)$r['id'], $uid]); return $p['name'] ?? 'Chat'; }
function chat_msg(array $m): array {
    $o = ['id' => (int)$m['id'], 'user_id' => (int)$m['user_id'], 'name' => $m['uname'] ?? '', 'kind' => $m['kind'], 'body' => $m['deleted_at'] ? null : $m['body'], 'deleted' => (bool)$m['deleted_at'], 'edited' => (bool)$m['edited_at'], 'at' => $m['created_at']];
    if ($m['kind'] === 'file' && !$m['deleted_at']) $o['file'] = ['name' => $m['file_name'], 'size' => (int)$m['file_size'], 'mime' => $m['file_mime'], 'image' => (bool)preg_match('~^image/(png|jpe?g|gif|webp)$~', (string)$m['file_mime'])];
    if ($m['kind'] === 'task' && !$m['deleted_at'] && $m['task_id']) { $t = one('SELECT id,title,status,due_date FROM tasks WHERE id=?', [(int)$m['task_id']]); $o['task'] = $t ? ['id' => (int)$t['id'], 'title' => $t['title'], 'status' => $t['status'], 'due_date' => $t['due_date'], 'shared' => (bool)$m['task_shared']] : ['gone' => true]; }
    return $o;
}
function chat_task_ok(array $u, int $tid): ?array {
    $id = (int)$u['id']; $w = '1=1'; $p = [$tid]; if (!in_array($u['role'], ['super_admin', 'founder'], true)) { $md = managed_dept_ids($u); $w = "(t.assignee_id=? OR t.creator_id=? OR EXISTS(SELECT 1 FROM task_shares ts WHERE ts.task_id=t.id AND ts.user_id=?)" . ($md ? ' OR t.department_id IN (' . implode(',', array_map('intval', $md)) . ')' : '') . ')'; array_push($p, $id, $id, $id); }
    return one("SELECT t.* FROM tasks t WHERE t.id=? AND $w", $p);
}
function chat_push(array $room, array $u, string $preview, int $uid): void {
    foreach (all("SELECT m.user_id FROM chat_members m JOIN users x ON x.id=m.user_id WHERE m.room_id=? AND m.user_id<>? AND m.muted=0 AND x.active=1 AND (x.last_seen_at IS NULL OR x.last_seen_at < (NOW() - INTERVAL 90 SECOND))", [(int)$room['id'], $uid]) as $r)
        push_try((int)$r['user_id'], $room['kind'] === 'group' ? $room['name'] . ': ' . $u['name'] : $u['name'], mb_substr($preview, 0, 120), '#/chat/' . (int)$room['id'], 'chat' . (int)$room['id']);
}

if ($sub === 'people' && $method === 'GET') out(['people' => all('SELECT u.id,u.name,d.name AS department FROM users u LEFT JOIN departments d ON d.id=u.department_id WHERE u.active=1 AND u.archived_at IS NULL AND u.id<>? ORDER BY u.name', [$uid])]);
if ($sub === 'unread' && $method === 'GET') {
    $rows = all("SELECT m.room_id, COUNT(*) n, MAX(x.id) last_id FROM chat_members m JOIN chat_messages x ON x.room_id=m.room_id AND x.id>m.last_read_id AND x.user_id<>? AND x.deleted_at IS NULL AND x.kind<>'system' WHERE m.user_id=? AND m.muted=0 GROUP BY m.room_id", [$uid, $uid]); $latest = null;
    if ($rows) { usort($rows, fn($a, $b) => (int)$b['last_id'] <=> (int)$a['last_id']); $m = one('SELECT x.id,x.room_id,x.kind,x.body,x.file_name,u.name AS uname,r.kind AS rk,r.name AS rname FROM chat_messages x JOIN users u ON u.id=x.user_id JOIN chat_rooms r ON r.id=x.room_id WHERE x.id=?', [(int)$rows[0]['last_id']]);
        if ($m) $latest = ['id' => (int)$m['id'], 'room_id' => (int)$m['room_id'], 'from' => $m['uname'], 'room' => $m['rk'] === 'group' ? $m['rname'] : null, 'text' => $m['kind'] === 'file' ? '📎 ' . $m['file_name'] : ($m['kind'] === 'task' ? '☑ shared a task' : mb_substr((string)$m['body'], 0, 100))]; }
    out(['total' => array_sum(array_map(fn($r) => (int)$r['n'], $rows)), 'rooms' => array_column(array_map(fn($r) => ['id' => (int)$r['room_id'], 'n' => (int)$r['n']], $rows), 'n', 'id'), 'latest' => $latest]);
}
if ($sub === 'rooms' && !$rid) {
    if ($method === 'GET') {
        $rows = all("SELECT r.*, m.last_read_id, m.muted, (SELECT COUNT(*) FROM chat_messages x WHERE x.room_id=r.id AND x.id>m.last_read_id AND x.user_id<>? AND x.deleted_at IS NULL AND x.kind<>'system') AS unread,
            (SELECT COUNT(*) FROM chat_members c WHERE c.room_id=r.id) AS members FROM chat_rooms r JOIN chat_members m ON m.room_id=r.id AND m.user_id=? ORDER BY r.updated_at DESC, r.id DESC LIMIT 200", [$uid, $uid]); $out = [];
        foreach ($rows as $r) { $l = one('SELECT x.id,x.kind,x.body,x.file_name,x.deleted_at,x.created_at,x.user_id,u.name AS uname FROM chat_messages x JOIN users u ON u.id=x.user_id WHERE x.room_id=? ORDER BY x.id DESC LIMIT 1', [(int)$r['id']]);
            $peer = $r['kind'] === 'dm' ? one('SELECT u.id,u.last_seen_at FROM chat_members c JOIN users u ON u.id=c.user_id WHERE c.room_id=? AND c.user_id<>? LIMIT 1', [(int)$r['id'], $uid]) : null;
            $out[] = ['id' => (int)$r['id'], 'kind' => $r['kind'], 'title' => chat_title($r, $uid), 'members' => (int)$r['members'], 'unread' => (int)$r['unread'], 'muted' => (bool)$r['muted'], 'peer_id' => $peer ? (int)$peer['id'] : null, 'online' => $peer && $peer['last_seen_at'] && strtotime((string)$peer['last_seen_at']) > time() - 120,
                'last' => $l ? ['from' => (int)$l['user_id'] === $uid ? 'You' : $l['uname'], 'text' => $l['deleted_at'] ? 'Message deleted' : ($l['kind'] === 'file' ? '📎 ' . $l['file_name'] : ($l['kind'] === 'task' ? '☑ Task shared' : mb_substr((string)$l['body'], 0, 80))), 'at' => $l['created_at']] : null, 'updated_at' => $r['updated_at']]; }
        out(['rooms' => $out]);
    }
    if ($method === 'POST') {
        $b = body(); $other = (int)($b['user_id'] ?? 0);
        if ($other) {   // one-to-one: the same two people always get the same chat
            if ($other === $uid) fail('Pick a colleague'); if (!one('SELECT id FROM users WHERE id=? AND active=1 AND archived_at IS NULL', [$other])) fail('Person not found', 404); $key = min($uid, $other) . '_' . max($uid, $other);
            $r = one('SELECT id FROM chat_rooms WHERE dm_key=?', [$key]); if ($r) out(['id' => (int)$r['id']]);
            q("INSERT INTO chat_rooms (kind,dm_key,created_by) VALUES ('dm',?,?)", [$key, $uid]); $id = (int)db()->lastInsertId(); q("INSERT INTO chat_members (room_id,user_id,role) VALUES (?,?,'member'),(?,?,'member')", [$id, $uid, $id, $other]); out(['id' => $id], 201);
        }
        $name = s($b['name'] ?? '', 80); if ($name === '') fail('Give the group a name'); $ids = array_values(array_unique(array_filter(array_map('intval', (array)($b['user_ids'] ?? [])), fn($x) => $x && $x !== $uid))); if (!$ids) fail('Choose who is in the group'); if (count($ids) > 100) fail('A group can have up to 100 people');
        $ok = array_map('intval', array_column(all('SELECT id FROM users WHERE active=1 AND archived_at IS NULL AND id IN (' . implode(',', $ids) . ')'), 'id')); if (!$ok) fail('Those people were not found');
        q("INSERT INTO chat_rooms (kind,name,created_by) VALUES ('group',?,?)", [$name, $uid]); $id = (int)db()->lastInsertId(); q("INSERT INTO chat_members (room_id,user_id,role) VALUES (?,?,'owner')", [$id, $uid]); foreach ($ok as $x) q("INSERT INTO chat_members (room_id,user_id,role) VALUES (?,?,'member')", [$id, $x]);
        q("INSERT INTO chat_messages (room_id,user_id,kind,body) VALUES (?,?,'system',?)", [$id, $uid, $u['name'] . ' created the group']); foreach ($ok as $x) push_try($x, 'New group: ' . $name, $u['name'] . ' added you', '#/chat/' . $id, 'chat' . $id); out(['id' => $id], 201);
    }
}
if ($sub === 'rooms' && $rid) {
    $room = chat_need($rid, $uid); $mem = chat_mem($rid, $uid);
    if ($method === 'GET' && $act === '') out(['id' => $rid, 'kind' => $room['kind'], 'title' => chat_title($room, $uid), 'owner' => $mem['role'] === 'owner', 'members' => chat_members($rid), 'muted' => (bool)$mem['muted']]);
    if ($method === 'PATCH' && $act === '') { $b = body(); if (array_key_exists('muted', $b)) q('UPDATE chat_members SET muted=? WHERE room_id=? AND user_id=?', [(int)!empty($b['muted']), $rid, $uid]);
        if (array_key_exists('name', $b)) { if ($room['kind'] !== 'group' || $mem['role'] !== 'owner') fail('Only the group owner can rename it', 403); $n = s($b['name'], 80); if ($n === '') fail('Give the group a name'); q('UPDATE chat_rooms SET name=? WHERE id=?', [$n, $rid]); } out(['ok' => true]); }
    if ($act === 'members' && $method === 'POST') {
        if ($room['kind'] !== 'group') fail('You can only add people to a group', 400); if ($mem['role'] !== 'owner') fail('Only the group owner can add people', 403); $b = body(); $added = [];
        foreach (array_slice(array_unique(array_map('intval', (array)($b['user_ids'] ?? []))), 0, 50) as $x) { if (!$x || chat_mem($rid, $x) || !one('SELECT id FROM users WHERE id=? AND active=1 AND archived_at IS NULL', [$x])) continue;
            if ((int)(one('SELECT COUNT(*) n FROM chat_members WHERE room_id=?', [$rid])['n'] ?? 0) >= 100) break; q("INSERT INTO chat_members (room_id,user_id,role,last_read_id) VALUES (?,?,'member',(SELECT COALESCE(MAX(id),0) FROM chat_messages WHERE room_id=?))", [$rid, $x, $rid]); $added[] = $x; push_try($x, 'Added to ' . $room['name'], $u['name'] . ' added you', '#/chat/' . $rid, 'chat' . $rid); }
        if ($added) { $names = array_column(all('SELECT name FROM users WHERE id IN (' . implode(',', $added) . ')'), 'name'); q("INSERT INTO chat_messages (room_id,user_id,kind,body) VALUES (?,?,'system',?)", [$rid, $uid, $u['name'] . ' added ' . implode(', ', $names)]); q('UPDATE chat_rooms SET updated_at=NOW() WHERE id=?', [$rid]); } out(['ok' => true, 'added' => count($added)]);
    }
    if ($act === 'members' && $method === 'DELETE') {
        $x = (int)($seg[4] ?? 0); if ($room['kind'] !== 'group') fail('You cannot leave a one-to-one chat', 400); if ($x !== $uid && $mem['role'] !== 'owner') fail('Only the group owner can remove people', 403); $t = chat_mem($rid, $x); if (!$t) fail('Not found', 404);
        q('DELETE FROM chat_members WHERE room_id=? AND user_id=?', [$rid, $x]); $nm = (string)(one('SELECT name FROM users WHERE id=?', [$x])['name'] ?? '');
        if ($t['role'] === 'owner') { $nx = one('SELECT user_id FROM chat_members WHERE room_id=? ORDER BY joined_at, user_id LIMIT 1', [$rid]); if ($nx) q("UPDATE chat_members SET role='owner' WHERE room_id=? AND user_id=?", [$rid, (int)$nx['user_id']]); }
        if (!(int)(one('SELECT COUNT(*) n FROM chat_members WHERE room_id=?', [$rid])['n'] ?? 0)) { foreach (all("SELECT file_path FROM chat_messages WHERE room_id=? AND file_path IS NOT NULL", [$rid]) as $f) { $p = chat_dir() . '/' . preg_replace('/[^a-f0-9]/', '', (string)$f['file_path']); if (is_file($p)) @unlink($p); } q('DELETE FROM chat_messages WHERE room_id=?', [$rid]); q('DELETE FROM chat_rooms WHERE id=?', [$rid]); }
        else q("INSERT INTO chat_messages (room_id,user_id,kind,body) VALUES (?,?,'system',?)", [$rid, $uid, $x === $uid ? $u['name'] . ' left' : $u['name'] . ' removed ' . $nm]); out(['ok' => true]);
    }
    if ($act === 'read' && $method === 'POST') { q('UPDATE chat_members SET last_read_id=GREATEST(last_read_id, COALESCE((SELECT MAX(id) FROM chat_messages WHERE room_id=?),0)) WHERE room_id=? AND user_id=?', [$rid, $rid, $uid]); out(['ok' => true]); }
    if ($act === 'messages' && $method === 'GET') {
        $after = (int)($_GET['after'] ?? 0); $before = (int)($_GET['before'] ?? 0); $w = 'x.room_id=?'; $p = [$rid]; if ($after) { $w .= ' AND x.id>?'; $p[] = $after; } if ($before) { $w .= ' AND x.id<?'; $p[] = $before; }
        $rows = $after ? all("SELECT x.*, u.name AS uname FROM chat_messages x JOIN users u ON u.id=x.user_id WHERE $w ORDER BY x.id LIMIT 100", $p) : array_reverse(all("SELECT x.*, u.name AS uname FROM chat_messages x JOIN users u ON u.id=x.user_id WHERE $w ORDER BY x.id DESC LIMIT 60", $p));
        if (!$before) q('UPDATE chat_members SET last_read_id=GREATEST(last_read_id, COALESCE((SELECT MAX(id) FROM chat_messages WHERE room_id=?),0)) WHERE room_id=? AND user_id=?', [$rid, $rid, $uid]);
        $others = all('SELECT user_id,last_read_id FROM chat_members WHERE room_id=? AND user_id<>?', [$rid, $uid]); out(['messages' => array_map('chat_msg', $rows), 'more' => !$after && count($rows) >= 60, 'read' => array_column($others, 'last_read_id', 'user_id')]);
    }
    if ($act === 'messages' && $method === 'POST') {
        if ($u['role'] === 'founder' && false) fail('Not allowed', 403); $b = body();
        if ((int)(one('SELECT COUNT(*) n FROM chat_messages WHERE user_id=? AND created_at > (NOW() - INTERVAL 1 MINUTE)', [$uid])['n'] ?? 0) >= 60) fail('You are sending very fast. Wait a moment.', 429);
        $kind = 'text'; $body = trim((string)($b['body'] ?? '')); $f = ['name' => null, 'mime' => null, 'size' => null, 'path' => null]; $tid = null; $shared = 0; $preview = $body;
        if (!empty($b['task_id'])) {
            $t = chat_task_ok($u, (int)$b['task_id']); if (!$t) fail('You can only share tasks you can see', 403); $kind = 'task'; $tid = (int)$t['id']; $preview = '☑ ' . $t['title'];
            if (!empty($b['share_task'])) { if (!in_array($u['role'], ['super_admin', 'manager'], true) && (int)$t['creator_id'] !== $uid && (int)$t['assignee_id'] !== $uid) fail('Only the person who gave the task, the person doing it, or a manager can give others access to it', 403);
                foreach (all('SELECT user_id FROM chat_members WHERE room_id=? AND user_id<>?', [$rid, $uid]) as $mm) q('INSERT IGNORE INTO task_shares (task_id,user_id,shared_by) VALUES (?,?,?)', [$tid, (int)$mm['user_id'], $uid]); $shared = 1; }
        } elseif (!empty($b['file']) && is_array($b['file'])) {
            $data = base64_decode((string)($b['file']['data'] ?? ''), true); if ($data === false || $data === '') fail('The file could not be read'); if (strlen($data) > CHAT_FILE_MAX) fail('Files can be up to ' . (CHAT_FILE_MAX / 1048576) . ' MB');
            $nm = mb_substr(preg_replace('/[\x00-\x1f\\\\\/]+/', '_', (string)($b['file']['name'] ?? 'file')) ?: 'file', 0, 190); $mime = preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~i', (string)($b['file']['type'] ?? '')) ? (string)$b['file']['type'] : 'application/octet-stream';
            $rel = bin2hex(random_bytes(16)); if (@file_put_contents(chat_dir() . '/' . $rel, $data) === false) fail('Could not save the file on the server (storage folder not writable)', 500); $kind = 'file'; $f = ['name' => $nm, 'mime' => $mime, 'size' => strlen($data), 'path' => $rel]; $preview = '📎 ' . $nm;
        } elseif (!empty($b['vault_id'])) {   // a file from My Documents, shared on purpose; notes and links go in as text
            $it = one('SELECT * FROM vault_items WHERE id=? AND user_id=?', [(int)$b['vault_id'], $uid]); if (!$it) fail('That document was not found', 404);
            if ($it['kind'] === 'file') { $src = (string)(cfg('storage_dir') ?: dirname(__DIR__) . '/storage') . '/vault/' . preg_replace('/[^a-f0-9]/', '', (string)$it['path']); if (!is_file($src)) fail('The file is no longer on the server', 404); if (filesize($src) > CHAT_FILE_MAX) fail('That file is too large to send in chat (10 MB)');
                $rel = bin2hex(random_bytes(16)); if (!@copy($src, chat_dir() . '/' . $rel)) fail('Could not copy the file', 500); $kind = 'file'; $f = ['name' => $it['name'], 'mime' => $it['mime'], 'size' => (int)$it['size'], 'path' => $rel]; $preview = '📎 ' . $it['name']; }
            else { $body = ($it['title'] ? $it['title'] . "\n" : '') . (string)$it['body']; $preview = mb_substr($body, 0, 100); }
        }
        if ($kind === 'text' && $body === '') fail('Type a message'); if (mb_strlen($body) > 4000) fail('That message is too long (4000 characters at most)');
        if ($kind !== 'text' && $body !== '' && mb_strlen($body) > 500) $body = mb_substr($body, 0, 500);
        q('INSERT INTO chat_messages (room_id,user_id,kind,body,file_name,file_mime,file_size,file_path,task_id,task_shared) VALUES (?,?,?,?,?,?,?,?,?,?)', [$rid, $uid, $kind, $body !== '' ? $body : null, $f['name'], $f['mime'], $f['size'], $f['path'], $tid, $shared]); $mid = (int)db()->lastInsertId();
        q('UPDATE chat_rooms SET updated_at=NOW() WHERE id=?', [$rid]); q('UPDATE chat_members SET last_read_id=? WHERE room_id=? AND user_id=?', [$mid, $rid, $uid]); chat_push($room, $u, $preview, $uid); out(['ok' => true, 'id' => $mid], 201);
    }
    fail('Not found', 404);
}
if ($sub === 'messages' && $rid) {   // here $rid is the message id
    $m = one('SELECT x.* FROM chat_messages x JOIN chat_members c ON c.room_id=x.room_id AND c.user_id=? WHERE x.id=?', [$uid, $rid]); if (!$m || (int)$m['user_id'] !== $uid || $m['kind'] === 'system') fail('Not found', 404);
    if ($method === 'PATCH') { if ($m['kind'] !== 'text' || $m['deleted_at']) fail('That message cannot be edited', 400); if (strtotime((string)$m['created_at']) < time() - 86400) fail('Messages can be edited for 24 hours', 400); $t = trim((string)(body()['body'] ?? '')); if ($t === '' || mb_strlen($t) > 4000) fail('Type the message'); q('UPDATE chat_messages SET body=?, edited_at=NOW() WHERE id=?', [$t, $rid]); out(['ok' => true]); }
    if ($method === 'DELETE') { if ($m['file_path']) { $p = chat_dir() . '/' . preg_replace('/[^a-f0-9]/', '', (string)$m['file_path']); if (is_file($p)) @unlink($p); } q('UPDATE chat_messages SET deleted_at=NOW(), body=NULL, file_path=NULL WHERE id=?', [$rid]); out(['ok' => true]); }
}
if ($sub === 'files' && $rid && $method === 'GET') {
    $m = one('SELECT x.* FROM chat_messages x JOIN chat_members c ON c.room_id=x.room_id AND c.user_id=? WHERE x.id=? AND x.kind=\'file\' AND x.deleted_at IS NULL', [$uid, $rid]); if (!$m) fail('Not found', 404);
    $p = chat_dir() . '/' . preg_replace('/[^a-f0-9]/', '', (string)$m['file_path']); if (!is_file($p)) fail('The file is no longer on the server', 404); $inline = isset($_GET['view']) && preg_match('~^image/(png|jpe?g|gif|webp)$~', (string)$m['file_mime']); $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$m['file_name']) ?: 'file';
    header('Content-Type: ' . ($inline ? $m['file_mime'] : 'application/octet-stream')); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store'); header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode((string)$m['file_name'])); header('Content-Length: ' . filesize($p)); readfile($p); exit;
}
fail('Not found', 404);
