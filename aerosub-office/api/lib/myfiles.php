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
