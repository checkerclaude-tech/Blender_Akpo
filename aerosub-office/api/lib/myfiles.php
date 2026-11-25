<?php
declare(strict_types=1);
// My Documents: a private place for each person's own files, notes and links. Route: /api/myfiles
//   GET  myfiles[?folder=&q=]        my items + folders + storage used
//   POST myfiles                     add {kind:'file', title, folder, name, type, data(base64)} · {kind:'note', title, folder, body} · {kind:'link', title, folder, body:'https://…'}
//   GET  myfiles/{id}/file[?view=1]  download (or show an image)
//   PATCH myfiles/{id}               rename / move / edit a note
//   DELETE myfiles/{id}
// Everything here is filtered to the signed-in person. Administrators, the Super Admin and the Root Admin have no way in: there is no
// route that reads another person's items, and the menu entry is switched off for administrators.
$u = need_user(); $uid = (int)$u['id'];
if (!tab_access($u, 'myfiles')) fail('My Documents is for staff accounts only', 403);
$mid = isset($seg[1]) && ctype_digit($seg[1]) ? (int)$seg[1] : 0; $act = $mid ? ($seg[2] ?? '') : '';
const VAULT_QUOTA = 300 * 1048576; const VAULT_MAX_FILE = 12 * 1048576;
function vault_dir(): string {
    $dir = (string)(cfg('storage_dir') ?: dirname(__DIR__) . '/storage') . '/vault';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $root = dirname($dir); if (!is_file($root . '/.htaccess')) { @file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n"); @file_put_contents($root . '/index.html', ''); }
    return $dir;
}
function vault_path(string $rel): string { return vault_dir() . '/' . preg_replace('/[^a-f0-9]/', '', $rel); }
function vault_used(int $uid): int { return (int)(one('SELECT COALESCE(SUM(size),0) s FROM vault_items WHERE user_id=?', [$uid])['s'] ?? 0); }
function vault_str($v, int $n): string { return mb_substr(trim((string)($v ?? '')), 0, $n); }
function vault_row(array $r): array {
    return ['id' => (int)$r['id'], 'kind' => $r['kind'], 'title' => $r['title'], 'folder' => $r['folder'], 'name' => $r['name'], 'mime' => $r['mime'], 'size' => (int)$r['size'], 'body' => $r['kind'] === 'file' ? null : $r['body'],
            'image' => $r['kind'] === 'file' && preg_match('~^image/(png|jpe?g|gif|webp)$~', (string)$r['mime']) === 1, 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at']];
}

/* ---- sharing: only ever something the owner chose to hand over, one person at a time ---- */
function vault_ensure(): void {   // the sharing and Google Drive tables, created the first time they are needed
    static $d = false; if ($d) return; $d = true;
    try { if (!one("SHOW TABLES LIKE 'vault_shares'") || !one("SHOW TABLES LIKE 'vault_drives'")) { require_once __DIR__ . '/migrate_fin3.php'; migrate_fin_ext(); } } catch (Throwable $e) { error_log('Vault schema: ' . $e->getMessage()); }
}
function vault_people(int $uid): array {   // colleagues who have a My Documents tab (administrators do not, so nothing can be shared to them)
    $out = []; foreach (all('SELECT u.id,u.name,u.role,u.is_root,(SELECT d.name FROM departments d WHERE d.id=u.department_id) AS department FROM users u WHERE u.active=1 AND u.archived_at IS NULL AND u.id<>? ORDER BY u.name', [$uid]) as $r)
        if (tab_access(['id' => (int)$r['id'], 'role' => $r['role'], 'is_root' => $r['is_root']], 'myfiles')) $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'department' => $r['department']];
    return $out;
}
if (($seg[1] ?? '') === 'drive') {   // Google Drive folders shared with "Anyone with the link" (no Google API setup)
    vault_ensure(); require_once __DIR__ . '/drive.php'; $did = isset($seg[2]) && ctype_digit($seg[2]) ? (int)$seg[2] : 0; $dact = $seg[3] ?? '';
    if ($method === 'GET' && !$did) out(['drives' => all('SELECT id,name FROM vault_drives WHERE user_id=? ORDER BY name', [$uid])]);
    if ($method === 'POST' && !$did) {
        $b = body(); $fid = gd_folder_id((string)($b['url'] ?? '')); if (!$fid) fail('Paste the link of the Google Drive folder (it looks like https://drive.google.com/drive/folders/…)');
        if ((int)(one('SELECT COUNT(*) n FROM vault_drives WHERE user_id=?', [$uid])['n'] ?? 0) >= 10) fail('You can connect up to 10 Drive folders');
        if (one('SELECT id FROM vault_drives WHERE user_id=? AND folder_id=?', [$uid, $fid])) fail('That folder is already connected');
        try { $n = count(gd_list($fid)); } catch (RuntimeException $e) { fail($e->getMessage(), 422); }
        $name = vault_str($b['name'] ?? '', 160) ?: 'Google Drive folder'; q('INSERT INTO vault_drives (user_id,name,folder_id) VALUES (?,?,?)', [$uid, $name, $fid]); out(['ok' => true, 'id' => (int)db()->lastInsertId(), 'items' => $n], 201);
    }
    $dr = $did ? one('SELECT * FROM vault_drives WHERE id=? AND user_id=?', [$did, $uid]) : null; if (!$dr) fail('Not found', 404);
    if ($method === 'DELETE' && $dact === '') { q('DELETE FROM vault_drives WHERE id=?', [$did]); out(['ok' => true]); }
    if ($method === 'PATCH' && $dact === '') { $nm = vault_str(body()['name'] ?? '', 160); if ($nm === '') fail('Give it a name'); q('UPDATE vault_drives SET name=? WHERE id=?', [$nm, $did]); out(['ok' => true]); }
    $fileId = (string)($_GET['file'] ?? (body()['file'] ?? '')); $kind = in((string)($_GET['kind'] ?? (body()['kind'] ?? '')), ['file', 'doc', 'sheet', 'slide', 'folder'], 'file'); $fname = vault_str($_GET['name'] ?? (body()['name'] ?? ''), 190) ?: 'file';
    if ($method === 'GET' && $dact === 'list') { $f = (string)($_GET['folder'] ?? '') ?: $dr['folder_id']; if (!gd_id_ok($f)) fail('Bad folder'); try { out(['folder' => $f, 'root' => $dr['folder_id'], 'name' => $dr['name'], 'items' => gd_list($f)]); } catch (RuntimeException $e) { fail($e->getMessage(), 502); } }
    if ($method === 'GET' && $dact === 'file') { try { [$bytes, $fn, $mime] = gd_download($fileId, $kind, $fname); } catch (RuntimeException $e) { fail($e->getMessage(), 502); }
        header('Content-Type: application/octet-stream'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store'); header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $fn) . '"; filename*=UTF-8\'\'' . rawurlencode($fn)); header('Content-Length: ' . strlen($bytes)); echo $bytes; exit; }
    if ($method === 'POST' && $dact === 'email') { require_once __DIR__ . '/sharesend.php'; $b = body(); $to = share_emails($b['to'] ?? ''); $via = in((string)($b['via'] ?? ''), ['mine', 'system'], 'system'); $atts = []; $text = (string)($b['message'] ?? '');
        if ($kind === 'folder') $text .= ($text !== '' ? "\n\n" : '') . gd_url($fileId, 'folder');
        else { try { [$bytes, $fn, $mime] = gd_download($fileId, $kind, $fname); } catch (RuntimeException $e) { fail($e->getMessage(), 502); } if (strlen($bytes) > 10 * 1048576) fail('That file is too large to email (10 MB). Send the link instead.'); $atts[] = [$fn, $mime, $bytes]; }
        out(['ok' => true, 'done' => [share_send_mail($u, $via, $to, (string)($b['subject'] ?? '') ?: $fname, $text, $atts)]]); }
    if ($method === 'POST' && $dact === 'link') {   // keep a link to the file or folder in My Documents so it can be shared with colleagues like any other item
        if (!gd_id_ok($fileId)) fail('Bad file'); $url = gd_url($fileId, $kind); $ex = one("SELECT id FROM vault_items WHERE user_id=? AND kind='link' AND body=?", [$uid, $url]);
        if ($ex) out(['ok' => true, 'id' => (int)$ex['id'], 'title' => $fname]); q('INSERT INTO vault_items (user_id,kind,title,folder,body) VALUES (?,?,?,?,?)', [$uid, 'link', mb_substr($fname, 0, 200), 'Google Drive', $url]); out(['ok' => true, 'id' => (int)db()->lastInsertId(), 'title' => $fname], 201); }
    fail('Not found', 404);
}
if (in_array($seg[1] ?? '', ['people', 'shared'], true) || in_array($act, ['share', 'shares'], true)) vault_ensure();
if (($seg[1] ?? '') === 'people' && $method === 'GET') out(['people' => vault_people($uid), 'mail' => (function () use ($u) { require_once __DIR__ . '/sharesend.php'; return share_mail_options($u); })()]);
if (($seg[1] ?? '') === 'shared') {
    $shid = isset($seg[2]) && ctype_digit($seg[2]) ? (int)$seg[2] : 0;
    if ($method === 'GET' && !$shid) out(['items' => array_map(fn($r) => ['share_id' => (int)$r['sid'], 'kind' => $r['kind'], 'title' => $r['title'], 'name' => $r['name'], 'mime' => $r['mime'], 'size' => (int)$r['size'], 'body' => $r['kind'] === 'file' ? null : $r['body'], 'note' => $r['note'], 'owner' => $r['owner'], 'shared_at' => $r['created_at'],
        'image' => $r['kind'] === 'file' && preg_match('~^image/(png|jpe?g|gif|webp)$~', (string)$r['mime']) === 1], all('SELECT s.id AS sid,s.note,s.created_at,i.kind,i.title,i.name,i.mime,i.size,i.body,o.name AS owner FROM vault_shares s JOIN vault_items i ON i.id=s.item_id JOIN users o ON o.id=s.owner_id WHERE s.user_id=? ORDER BY s.id DESC LIMIT 300', [$uid]))]);
    $sh = $shid ? one('SELECT s.id,i.kind,i.name,i.mime,i.path FROM vault_shares s JOIN vault_items i ON i.id=s.item_id WHERE s.id=? AND s.user_id=?', [$shid, $uid]) : null; if (!$sh) fail('Not found', 404);
    if ($method === 'GET' && ($seg[3] ?? '') === 'file' && $sh['kind'] === 'file') { $p = vault_path((string)$sh['path']); if (!is_file($p)) fail('The file is no longer on the server', 404);
        $inline = isset($_GET['view']) && preg_match('~^image/(png|jpe?g|gif|webp)$~', (string)$sh['mime']); $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$sh['name']) ?: 'file';
        header('Content-Type: ' . ($inline ? $sh['mime'] : 'application/octet-stream')); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store'); header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode((string)$sh['name'])); header('Content-Length: ' . filesize($p)); readfile($p); exit; }
    if ($method === 'DELETE') { q('DELETE FROM vault_shares WHERE id=? AND user_id=?', [$shid, $uid]); out(['ok' => true]); }   // "remove from my shared list"
    fail('Not found', 404);
}
if ($method === 'GET' && !$mid) {
    $w = ['user_id=?']; $p = [$uid];
    if (isset($_GET['folder']) && $_GET['folder'] !== '') { if ($_GET['folder'] === '-') $w[] = '(folder IS NULL OR folder=\'\')'; else { $w[] = 'folder=?'; $p[] = vault_str($_GET['folder'], 80); } }
    if (!empty($_GET['q'])) { $w[] = '(title LIKE ? OR name LIKE ? OR (kind<>\'file\' AND body LIKE ?))'; $qq = '%' . vault_str($_GET['q'], 60) . '%'; array_push($p, $qq, $qq, $qq); }
    $items = array_map('vault_row', all('SELECT id,kind,title,folder,name,mime,size,body,created_at,updated_at FROM vault_items WHERE ' . implode(' AND ', $w) . ' ORDER BY updated_at DESC, id DESC LIMIT 500', $p));
    $folders = all("SELECT COALESCE(NULLIF(folder,''),'-') AS folder, COUNT(*) n FROM vault_items WHERE user_id=? GROUP BY COALESCE(NULLIF(folder,''),'-') ORDER BY folder", [$uid]);
    out(['items' => $items, 'folders' => $folders, 'used' => vault_used($uid), 'quota' => VAULT_QUOTA, 'max_file' => VAULT_MAX_FILE]);
}
if ($method === 'POST' && !$mid) {
    if ($u['role'] === 'founder') { /* founders keep their own files too */ }
    $b = body(); $kind = in((string)($b['kind'] ?? ''), ['file', 'note', 'link'], ''); if ($kind === '') fail('Choose what to add');
    $folder = vault_str($b['folder'] ?? '', 80) ?: null; $title = vault_str($b['title'] ?? '', 200);
    if ($kind === 'note') { $body = vault_str($b['body'] ?? '', 20000); if ($body === '') fail('Write the note'); $title = $title ?: mb_substr(strtok($body, "\n") ?: 'Note', 0, 80);
        q('INSERT INTO vault_items (user_id,kind,title,folder,body) VALUES (?,?,?,?,?)', [$uid, 'note', $title, $folder, $body]); out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201); }
    if ($kind === 'link') { $url = vault_str($b['body'] ?? '', 500); if (!preg_match('~^https?://[^\s"<>]+$~i', $url)) fail('The link must start with https://'); $title = $title ?: $url;
        q('INSERT INTO vault_items (user_id,kind,title,folder,body) VALUES (?,?,?,?,?)', [$uid, 'link', $title, $folder, $url]); out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201); }
    $data = base64_decode((string)($b['data'] ?? ''), true); if ($data === false || $data === '') fail('The file could not be read');
    if (strlen($data) > VAULT_MAX_FILE) fail('Files can be up to ' . (VAULT_MAX_FILE / 1048576) . ' MB');
    if (vault_used($uid) + strlen($data) > VAULT_QUOTA) fail('Your storage is full (' . (VAULT_QUOTA / 1048576) . ' MB). Delete something first.');
    $name = mb_substr(preg_replace('/[\x00-\x1f\\\\\/]+/', '_', (string)($b['name'] ?? 'file')) ?: 'file', 0, 190); $mime = mb_substr(preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~i', (string)($b['type'] ?? '')) ? (string)$b['type'] : 'application/octet-stream', 0, 100);
    $rel = bin2hex(random_bytes(16)); if (@file_put_contents(vault_path($rel), $data) === false) fail('Could not save the file on the server (storage folder not writable)', 500);
    q('INSERT INTO vault_items (user_id,kind,title,folder,name,mime,size,path) VALUES (?,?,?,?,?,?,?,?)', [$uid, 'file', $title ?: $name, $folder, $name, $mime, strlen($data), $rel]); out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201);
}
$it = $mid ? one('SELECT * FROM vault_items WHERE id=? AND user_id=?', [$mid, $uid]) : null; if (!$it) fail('Not found', 404);   // someone else's item looks exactly like a missing one
if ($act === 'shares' && $method === 'GET') out(['shares' => all('SELECT s.user_id,u.name,s.note,s.created_at FROM vault_shares s JOIN users u ON u.id=s.user_id WHERE s.item_id=? ORDER BY u.name', [$mid])]);
if ($act === 'share' && $method === 'POST') {
    $b = body(); $ids = array_values(array_unique(array_map('intval', (array)($b['user_ids'] ?? [])))); if (!$ids) fail('Choose who to share it with'); if (count($ids) > 60) fail('Choose up to 60 people at a time');
    $ok = array_column(vault_people($uid), null, 'id'); $note = vault_str($b['note'] ?? '', 300) ?: null; $n = 0;
    foreach ($ids as $to) { if (!isset($ok[$to])) continue; $had = one('SELECT id FROM vault_shares WHERE item_id=? AND user_id=?', [$mid, $to]);
        q('INSERT INTO vault_shares (item_id,owner_id,user_id,note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE note=VALUES(note)', [$mid, $uid, $to, $note]);
        if (!$had) { notify($to, 'vault_share', $u['name'] . ' shared “' . $it['title'] . '” with you', $note ?? 'Open My Documents → Shared with me', '#/myfiles'); $n++; } }
    if (!$n && !array_intersect($ids, array_keys($ok))) fail('Those people cannot receive shared documents'); out(['ok' => true, 'shared' => $n]);
}
if ($act === 'share' && $method === 'DELETE') { $to = (int)($seg[3] ?? 0); q('DELETE FROM vault_shares WHERE item_id=? AND user_id=? AND owner_id=?', [$mid, $to, $uid]); out(['ok' => true]); }
if ($act === 'email' && $method === 'POST') {
    require_once __DIR__ . '/sharesend.php'; $b = body(); $to = share_emails($b['to'] ?? ''); $via = in((string)($b['via'] ?? ''), ['mine', 'system'], 'system'); $atts = []; $text = (string)($b['message'] ?? '');
    if ($it['kind'] === 'file') { $p = vault_path((string)$it['path']); if (!is_file($p)) fail('The file is no longer on the server', 404); if (filesize($p) > 10 * 1048576) fail('That file is too large to email (10 MB). Share it with a colleague instead.'); $atts[] = [(string)$it['name'], (string)($it['mime'] ?: 'application/octet-stream'), (string)file_get_contents($p)]; }
    else $text .= ($text !== '' ? "\n\n" : '') . ($it['kind'] === 'link' ? (string)$it['body'] : (string)$it['body']);
    out(['ok' => true, 'done' => [share_send_mail($u, $via, $to, (string)($b['subject'] ?? '') ?: (string)$it['title'], $text, $atts)]]);
}
if ($method === 'GET' && $act === 'file') {
    if ($it['kind'] !== 'file') fail('Not found', 404); $p = vault_path((string)$it['path']); if (!is_file($p)) fail('The file is no longer on the server', 404);
    $inline = isset($_GET['view']) && preg_match('~^image/(png|jpe?g|gif|webp)$~', (string)$it['mime']); $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$it['name']) ?: 'file';
    header('Content-Type: ' . ($inline ? $it['mime'] : 'application/octet-stream')); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode((string)$it['name'])); header('Content-Length: ' . filesize($p)); readfile($p); exit;
}
if ($method === 'PATCH') {
    $b = body(); $title = array_key_exists('title', $b) ? vault_str($b['title'], 200) : $it['title']; if ($title === '') fail('Give it a name'); $folder = array_key_exists('folder', $b) ? (vault_str($b['folder'], 80) ?: null) : $it['folder']; $body = $it['body'];
    if ($it['kind'] === 'note' && array_key_exists('body', $b)) { $body = vault_str($b['body'], 20000); if ($body === '') fail('Write the note'); }
    if ($it['kind'] === 'link' && array_key_exists('body', $b)) { $body = vault_str($b['body'], 500); if (!preg_match('~^https?://[^\s"<>]+$~i', $body)) fail('The link must start with https://'); }
    q('UPDATE vault_items SET title=?, folder=?, body=? WHERE id=?', [$title, $folder, $body, $mid]); out(['ok' => true]);
}
if ($method === 'DELETE') { if ($it['kind'] === 'file' && $it['path']) { $p = vault_path((string)$it['path']); if (is_file($p)) @unlink($p); } q('DELETE FROM vault_items WHERE id=?', [$mid]); out(['ok' => true]); }
fail('Bad request', 404);
