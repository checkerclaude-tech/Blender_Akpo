<?php
declare(strict_types=1);
// Letters (Word documents) on letterheads.  Included from finance.php, which has already signed the person in ($u, $uid, $b, $fin, $seg, $method, $sid, $act).
//   GET    finance/letterheads                     the letterheads (+ how this person can send mail)
//   POST   finance/letterheads                     {name, pad_top, pad_bottom, pad_left, pad_right, is_default, art_data (base64), art_type}
//   PATCH  finance/letterheads/{id}                same fields (art optional) · DELETE finance/letterheads/{id}
//   GET    finance/letterheads/{id}/art            the artwork picture
//   GET    finance/letters[?q=]                    · POST finance/letters {title, letterhead_id, body}
//   GET    finance/letters/{id}                    · PATCH · DELETE
//   GET    finance/letters/{id}/docx               the editable Word file
//   POST   finance/letters/{id}/share              {mail:{to, subject, message, via:'mine'|'system'}, task:{task_id, note}}
require_once __DIR__ . '/docxout.php'; require_once __DIR__ . '/sharesend.php';
if (!$fin) fail('Letters are for the finance team', 403);
$what = $seg[1];   // letters | letterheads
function lh_store_dir(): string { $dir = (string)(cfg('storage_dir') ?: dirname(__DIR__) . '/storage') . '/letterheads'; if (!is_dir($dir)) @mkdir($dir, 0775, true); $root = dirname($dir); if (!is_file($root . '/.htaccess')) { @file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n"); @file_put_contents($root . '/index.html', ''); } return $dir; }
function lh_art(?array $r): ?array {
    if (!$r || !$r['art_path']) return null;
    $p = $r['art_path'] === 'builtin' ? dirname(__DIR__, 2) . '/assets/img/letterhead-aerosub.png' : lh_store_dir() . '/' . preg_replace('/[^a-f0-9]/', '', (string)$r['art_path']);
    return is_file($p) ? ['data' => (string)file_get_contents($p), 'mime' => (string)($r['art_mime'] ?: 'image/png')] : null;
}
function lh_pub(array $r): array {
    return ['id' => (int)$r['id'], 'name' => $r['name'], 'pad_top' => (float)$r['pad_top'], 'pad_bottom' => (float)$r['pad_bottom'], 'pad_left' => (float)$r['pad_left'], 'pad_right' => (float)$r['pad_right'], 'is_default' => (int)$r['is_default'] === 1, 'has_art' => (bool)$r['art_path'], 'art' => $r['art_path'] ? 'api/finance/letterheads/' . (int)$r['id'] . '/art?v=' . substr(md5((string)$r['art_path']), 0, 6) : null];
}
function lh_pad($v, float $def): float { return is_numeric($v) ? max(5.0, min(120.0, round((float)$v, 1))) : $def; }
function lh_save_art(array $b): array {   // [path, mime] or [null,null]
    if (empty($b['art_data'])) return [null, null];
    $d = base64_decode((string)$b['art_data'], true); if ($d === false || strlen($d) < 100) fail('The letterhead picture could not be read');
    if (strlen($d) > 6 * 1048576) fail('The letterhead picture can be up to 6 MB');
    $info = @getimagesizefromstring($d); if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) fail('Use a PNG or JPG picture of the whole A4 page (artwork at the top and bottom)');
    $rel = bin2hex(random_bytes(16)); if (@file_put_contents(lh_store_dir() . '/' . $rel, $d) === false) fail('Could not save the picture on the server (storage folder not writable)', 500);
    return [$rel, $info[2] === IMAGETYPE_JPEG ? 'image/jpeg' : 'image/png'];
}
function lt_pub(array $r, bool $full): array {
    $o = ['id' => (int)$r['id'], 'title' => $r['title'], 'letterhead_id' => $r['letterhead_id'] ? (int)$r['letterhead_id'] : null, 'letterhead' => $r['lh_name'] ?? null, 'created_by' => $r['created_by_name'] ?? null, 'updated_at' => $r['updated_at'], 'created_at' => $r['created_at'], 'preview' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['</p>', '<br>', '</div>'], ' ', (string)$r['body'])))), 0, 140)];
    if ($full) $o['body'] = $r['body']; return $o;
}
function lt_file(array $r): array {
    $lh = $r['letterhead_id'] ? one('SELECT * FROM fin_letterheads WHERE id=?', [(int)$r['letterhead_id']]) : null; $art = lh_art($lh);
    $pad = $lh ? [(float)$lh['pad_top'], (float)$lh['pad_bottom'], (float)$lh['pad_left'], (float)$lh['pad_right']] : [25.0, 25.0, 22.0, 22.0];
    $name = trim(preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string)$r['title']), ' ._') ?: 'Letter';
    return [ltr_docx((string)$r['title'], (string)$r['body'], $art, $pad), mb_substr($name, 0, 80) . '.docx'];
}

if ($what === 'letterheads') {
    if ($method === 'GET' && !$sid) out(['letterheads' => array_map('lh_pub', all('SELECT * FROM fin_letterheads ORDER BY is_default DESC, name')), 'mail' => share_mail_options($u)]);
    if ($method === 'GET' && $act === 'art') { $r = one('SELECT * FROM fin_letterheads WHERE id=?', [$sid]); $a = lh_art($r); if (!$a) fail('Not found', 404);
        header('Content-Type: ' . $a['mime']); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=3600'); header('Content-Length: ' . strlen($a['data'])); echo $a['data']; exit; }
    if ($method === 'POST' && !$sid) {
        $name = fx_s($b['name'] ?? '', 120); if (!$name) fail('Give the letterhead a name'); [$p, $m] = lh_save_art($b); $def = !empty($b['is_default']);
        if ($def) q('UPDATE fin_letterheads SET is_default=0');
        q('INSERT INTO fin_letterheads (name,art_path,art_mime,pad_top,pad_bottom,pad_left,pad_right,is_default,created_by) VALUES (?,?,?,?,?,?,?,?,?)', [$name, $p, $m, lh_pad($b['pad_top'] ?? 52, 52), lh_pad($b['pad_bottom'] ?? 44, 44), lh_pad($b['pad_left'] ?? 22, 22), lh_pad($b['pad_right'] ?? 22, 22), $def ? 1 : 0, $uid]);
        $id = (int)db()->lastInsertId(); log_act($uid, 'letterhead_add', $name); out(['ok' => true, 'id' => $id], 201);
    }
    $r = $sid ? one('SELECT * FROM fin_letterheads WHERE id=?', [$sid]) : null; if (!$r) fail('Not found', 404);
    if ($method === 'PATCH') {
        $name = array_key_exists('name', $b) ? fx_s($b['name'], 120) : $r['name']; if (!$name) fail('Give the letterhead a name'); [$p, $m] = lh_save_art($b);
        $isdef = array_key_exists('is_default', $b) ? !empty($b['is_default']) : (int)$r['is_default'] === 1; if ($isdef) q('UPDATE fin_letterheads SET is_default=0');
        q('UPDATE fin_letterheads SET name=?,art_path=?,art_mime=?,pad_top=?,pad_bottom=?,pad_left=?,pad_right=?,is_default=? WHERE id=?', [$name, $p ?? $r['art_path'], $m ?? $r['art_mime'], lh_pad($b['pad_top'] ?? $r['pad_top'], 52), lh_pad($b['pad_bottom'] ?? $r['pad_bottom'], 44), lh_pad($b['pad_left'] ?? $r['pad_left'], 22), lh_pad($b['pad_right'] ?? $r['pad_right'], 22), $isdef ? 1 : 0, $sid]);
        if ($p && $r['art_path'] && $r['art_path'] !== 'builtin') { $old = lh_store_dir() . '/' . preg_replace('/[^a-f0-9]/', '', (string)$r['art_path']); if (is_file($old)) @unlink($old); }
        out(['ok' => true]);
    }
    if ($method === 'DELETE') {
        if ((int)(one('SELECT COUNT(*) n FROM fin_letterheads')['n'] ?? 0) <= 1) fail('Keep at least one letterhead');
        $used = (int)(one('SELECT COUNT(*) n FROM fin_letters WHERE letterhead_id=?', [$sid])['n'] ?? 0); if ($used) fail($used . ' letter(s) use this letterhead. Move them to another letterhead first, or edit this one instead.');
        if ($r['art_path'] && $r['art_path'] !== 'builtin') { $old = lh_store_dir() . '/' . preg_replace('/[^a-f0-9]/', '', (string)$r['art_path']); if (is_file($old)) @unlink($old); }
        q('DELETE FROM fin_letterheads WHERE id=?', [$sid]); if ((int)$r['is_default'] === 1) q('UPDATE fin_letterheads SET is_default=1 ORDER BY id LIMIT 1'); out(['ok' => true]);
    }
    fail('Not found', 404);
}

/* ---- letters ---- */
$sel = 'SELECT l.*, h.name AS lh_name, u.name AS created_by_name FROM fin_letters l LEFT JOIN fin_letterheads h ON h.id=l.letterhead_id LEFT JOIN users u ON u.id=l.created_by';
if ($method === 'GET' && !$sid) {
    $w = '1=1'; $p = []; $qs = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60); if ($qs !== '') { $w = '(l.title LIKE ? OR l.body LIKE ?)'; $p = ['%' . $qs . '%', '%' . $qs . '%']; }
    out(['letters' => array_map(fn($r) => lt_pub($r, false), all("$sel WHERE $w ORDER BY l.updated_at DESC LIMIT 300", $p))]);
}
if ($method === 'POST' && !$sid) {
    $title = fx_s($b['title'] ?? '', 200) ?: 'Untitled letter'; $lh = (int)($b['letterhead_id'] ?? 0);
    if (!$lh || !one('SELECT id FROM fin_letterheads WHERE id=?', [$lh])) $lh = (int)(one('SELECT id FROM fin_letterheads ORDER BY is_default DESC, id LIMIT 1')['id'] ?? 0);
    q('INSERT INTO fin_letters (title,letterhead_id,body,created_by) VALUES (?,?,?,?)', [$title, $lh ?: null, ltr_clean((string)($b['body'] ?? '')), $uid]); $id = (int)db()->lastInsertId(); log_act($uid, 'letter_add', $title); out(['ok' => true, 'id' => $id], 201);
}
$r = $sid ? one("$sel WHERE l.id=?", [$sid]) : null; if (!$r) fail('Not found', 404);
if ($method === 'GET' && $act === '') out(['letter' => lt_pub($r, true)]);
if ($method === 'GET' && $act === 'docx') {
    [$bytes, $fn] = lt_file($r); header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $fn) . '"; filename*=UTF-8\'\'' . rawurlencode($fn)); header('Content-Length: ' . strlen($bytes)); echo $bytes; exit;
}
if ($method === 'PATCH' && $act === '') {
    $title = array_key_exists('title', $b) ? (fx_s($b['title'], 200) ?: 'Untitled letter') : $r['title']; $lh = array_key_exists('letterhead_id', $b) ? (int)$b['letterhead_id'] : (int)$r['letterhead_id'];
    if ($lh && !one('SELECT id FROM fin_letterheads WHERE id=?', [$lh])) $lh = (int)$r['letterhead_id'];
    q('UPDATE fin_letters SET title=?, letterhead_id=?, body=? WHERE id=?', [$title, $lh ?: null, array_key_exists('body', $b) ? ltr_clean((string)$b['body']) : $r['body'], $sid]); out(['ok' => true]);
}
if ($method === 'DELETE' && $act === '') { q('DELETE FROM fin_letters WHERE id=?', [$sid]); log_act($uid, 'letter_delete', (string)$r['title']); out(['ok' => true]); }
if ($method === 'POST' && $act === 'share') {
    [$bytes, $fn] = lt_file($r); $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'; $done = [];
    $m = $b['mail'] ?? null; $tk = $b['task'] ?? null; if (!$m && !$tk) fail('Choose where to send it: an email address or a task');
    if (is_array($m) && !empty($m['to'])) {
        $to = share_emails($m['to']); $via = in((string)($m['via'] ?? ''), ['mine', 'system'], 'system');
        $done[] = share_send_mail($u, $via, $to, (string)($m['subject'] ?? '') ?: (string)$r['title'], (string)($m['message'] ?? ''), [[$fn, $mime, $bytes]]);
    }
    if (is_array($tk) && !empty($tk['task_id'])) {
        $tid = (int)$tk['task_id']; $t = one('SELECT t.id,t.title,t.assignee_id,t.creator_id FROM tasks t WHERE t.id=?', [$tid]); if (!$t) fail('That task was not found', 404);
        $mine = in_array($role, ['super_admin', 'founder'], true) || (int)$t['assignee_id'] === $uid || (int)$t['creator_id'] === $uid || one('SELECT 1 FROM task_shares WHERE task_id=? AND user_id=?', [$tid, $uid]) || can_finance($u) && one("SELECT 1 FROM tasks WHERE id=? AND task_type='rfq'", [$tid]);
        if (!$mine) fail('You can only attach to tasks you can see', 403);
        try { task_store_file($tid, $uid, $fn, $mime, $bytes); } catch (RuntimeException $e) { fail($e->getMessage()); }
        $note = trim((string)($tk['note'] ?? '')); q('INSERT INTO task_comments (task_id,user_id,body) VALUES (?,?,?)', [$tid, $uid, mb_substr('Attached the letter “' . $r['title'] . '” (Word file).' . ($note !== '' ? "\n" . $note : ''), 0, 4000)]);
        foreach (array_unique(array_filter([(int)$t['assignee_id'], (int)$t['creator_id']])) as $n) if ($n !== $uid) notify($n, 'task_file', $u['name'] . ' attached a letter to “' . $t['title'] . '”', (string)$r['title'], '#/tasks/' . $tid);
        $done[] = 'Attached to the task “' . $t['title'] . '”';
    }
    if (!$done) fail('Type an email address or choose a task'); out(['ok' => true, 'done' => $done]);
}
fail('Not found', 404);
