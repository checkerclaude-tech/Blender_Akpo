<?php
declare(strict_types=1);
// Routes: /api/learn/*  — department SOP / training pages with versions and "read & understood" confirmations.
require_once __DIR__ . '/mail.php'; // html_load / html_inner
$u = need_user(); $uid = (int)$u['id']; $role = $u['role'];
$rid = isset($seg[1]) && ctype_digit($seg[1]) ? (int)$seg[1] : 0; $act = $rid ? ($seg[2] ?? '') : ($seg[1] ?? '');

function sop_sanitize(string $html): string {
    $allowed = ['h2', 'h3', 'p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'a', 'blockquote', 'code', 'pre', 'hr', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td'];
    $dom = html_load($html); $changed = true; $guard = 0;
    while ($changed && $guard++ < 60) {
        $changed = false;
        foreach (iterator_to_array((new DOMXPath($dom))->query('//*[not(@id="aero-root")]')) as $el) {
            if (!$el->parentNode) continue; $name = strtolower($el->nodeName);
            if (in_array($name, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'link', 'meta', 'svg', 'head', 'title', 'input', 'button', 'textarea', 'select', 'video', 'audio'], true)) { $el->parentNode->removeChild($el); $changed = true; continue; }
            if (!in_array($name, $allowed, true)) { while ($el->firstChild) $el->parentNode->insertBefore($el->firstChild, $el); $el->parentNode->removeChild($el); $changed = true; continue; }
            $href = $name === 'a' ? trim($el->getAttribute('href')) : ''; $src = $name === 'img' ? trim($el->getAttribute('src')) : ''; $alt = $name === 'img' ? mb_substr($el->getAttribute('alt'), 0, 150) : '';
            foreach (iterator_to_array($el->attributes) as $a) $el->removeAttribute($a->name);
            if ($name === 'a') { if (preg_match('~^(https?://|mailto:)~i', $href)) { $el->setAttribute('href', $href); $el->setAttribute('target', '_blank'); $el->setAttribute('rel', 'noopener noreferrer'); } }
            if ($name === 'img') { if (!preg_match('~^api/learn/img/\d+$~', $src)) { $el->parentNode->removeChild($el); $changed = true; continue; } $el->setAttribute('src', $src); $el->setAttribute('alt', $alt); }
        }
    }
    return html_inner($dom);
}
function sop_my_depts(): array { global $u; return array_map('intval', $u['department_ids'] ?? []); }
function sop_can_edit(array $u, ?int $dept): bool {
    if ($u['role'] === 'super_admin') return true;
    return $u['role'] === 'manager' && $dept !== null && in_array($dept, array_map('intval', $u['department_ids'] ?? []), true);
}
function sop_can_view(array $u, array $p): bool {
    if (sop_can_edit($u, $p['department_id'] !== null ? (int)$p['department_id'] : null)) return true;
    if ($p['status'] !== 'published') return false;
    if ($u['role'] === 'founder') return true;
    return $p['department_id'] === null || in_array((int)$p['department_id'], array_map('intval', $u['department_ids'] ?? []), true);
}
function sop_need(int $id): array { $p = one('SELECT p.*, d.name AS department, uu.name AS updated_by_name FROM sop_pages p LEFT JOIN departments d ON d.id=p.department_id LEFT JOIN users uu ON uu.id=p.updated_by WHERE p.id=?', [$id]); if (!$p) fail('Page not found', 404); return $p; }
function sop_members(?int $dept): array {
    if ($dept === null) return all('SELECT id,name FROM users WHERE active=1 ORDER BY name');
    return all('SELECT DISTINCT u.id,u.name FROM users u LEFT JOIN user_departments ud ON ud.user_id=u.id WHERE u.active=1 AND (u.department_id=? OR ud.department_id=?) ORDER BY u.name', [$dept, $dept]);
}
function sop_notify(array $p, string $what): void {
    foreach (sop_members($p['department_id'] !== null ? (int)$p['department_id'] : null) as $m) { if ((int)$m['id'] === $GLOBALS['uid']) continue; notify((int)$m['id'], 'sop_update', $what . ': ' . $p['title'], 'Please read and confirm.', '#/learn/' . $p['id']); }
}
function sop_fields(array $b, array $u, ?array $old): array {
    $title = s($b['title'] ?? ($old['title'] ?? ''), 200); if ($title === '') fail('Give the page a title.');
    $dept = array_key_exists('department_id', $b) ? ((int)$b['department_id'] ?: null) : ($old['department_id'] !== null ? (int)$old['department_id'] : null);
    if ($dept !== null && !one('SELECT 1 FROM departments WHERE id=?', [$dept])) fail('Department not found');
    if (!sop_can_edit($u, $dept)) fail($dept === null ? 'Only the Super Admin can publish company-wide pages.' : 'You can only edit pages for departments you manage.', 403);
    return ['title' => $title, 'dept' => $dept, 'category' => s($b['category'] ?? ($old['category'] ?? ''), 80) ?: null, 'status' => in((string)($b['status'] ?? ($old['status'] ?? 'published')), ['draft', 'published'], 'published'),
        'pinned' => array_key_exists('pinned', $b) ? (int)(bool)$b['pinned'] : (int)($old['pinned'] ?? 0), 'ack' => array_key_exists('requires_ack', $b) ? (int)(bool)$b['requires_ack'] : (int)($old['requires_ack'] ?? 0),
        'body' => array_key_exists('body', $b) ? sop_sanitize(mb_substr((string)$b['body'], 0, 300000)) : (string)($old['body'] ?? '')];
}

/* ---------- images (any signed-in person may view; only editors upload) ---------- */
if (($seg[1] ?? '') === 'img') {
    $iid = (int)($seg[2] ?? 0);
    if ($method === 'GET') { $r = $iid ? one('SELECT mime,data FROM sop_images WHERE id=?', [$iid]) : null; if (!$r) { http_response_code(404); exit; } header('Content-Type: ' . $r['mime']); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=604800'); header('Content-Length: ' . strlen($r['data'])); echo $r['data']; exit; }
    if ($method === 'POST') {
        if (!in_array($role, ['super_admin', 'manager'], true)) fail('Only managers can upload pictures.', 403);
        $b = body(); $d = (string)($b['data'] ?? '');
        if (!preg_match('~^data:image/(jpeg|png|gif|webp);base64,([A-Za-z0-9+/=\r\n]+)$~', $d, $mm)) fail('Choose a JPG, PNG, GIF or WebP picture.');
        $bin = base64_decode($mm[2], true); if ($bin === false || strlen($bin) > 1_500_000) fail('That picture is too large (maximum 1.5 MB).');
        $info = @getimagesizefromstring($bin); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) fail('That picture could not be read.');
        q('INSERT INTO sop_images (mime,data,created_by) VALUES (?,?,?)', [image_type_to_mime_type($info[2]), $bin, $uid]); out(['url' => 'api/learn/img/' . (int)db()->lastInsertId()]);
    }
    fail('Bad request', 405);
}

/* ---------- list ---------- */
if ($method === 'GET' && !$rid) {
    $rows = all("SELECT p.id,p.department_id,d.name AS department,p.title,p.category,p.status,p.pinned,p.requires_ack,p.version,p.updated_at,uu.name AS updated_by_name,a.version AS ack_version
                 FROM sop_pages p LEFT JOIN departments d ON d.id=p.department_id LEFT JOIN users uu ON uu.id=p.updated_by LEFT JOIN sop_acks a ON a.page_id=p.id AND a.user_id=?
                 ORDER BY p.pinned DESC, p.title", [$uid]);
    $q = mb_strtolower(s($_GET['q'] ?? '', 80)); $bodies = [];
    if ($q !== '') foreach (all('SELECT id FROM sop_pages WHERE title LIKE ? OR body LIKE ?', ['%' . $q . '%', '%' . $q . '%']) as $r) $bodies[(int)$r['id']] = true;
    $out = [];
    foreach ($rows as $p) {
        $p['department_id'] = $p['department_id'] !== null ? (int)$p['department_id'] : null;
        if (!sop_can_view($u, $p)) continue; if ($q !== '' && empty($bodies[(int)$p['id']])) continue;
        $p['can_edit'] = sop_can_edit($u, $p['department_id']); $p['needs_ack'] = $p['status'] === 'published' && (int)$p['requires_ack'] === 1 && (int)($p['ack_version'] ?? 0) < (int)$p['version'];
        $out[] = $p;
    }
    out(['pages' => $out, 'can_create' => in_array($role, ['super_admin', 'manager'], true)]);
}

/* ---------- create ---------- */
if ($method === 'POST' && !$rid && $act === '') {
    $b = body(); $f = sop_fields($b, $u, null);
    q('INSERT INTO sop_pages (department_id,title,body,category,status,pinned,requires_ack,version,created_by,updated_by,created_at) VALUES (?,?,?,?,?,?,?,1,?,?,NOW())', [$f['dept'], $f['title'], $f['body'], $f['category'], $f['status'], $f['pinned'], $f['ack'], $uid, $uid]);
    $id = (int)db()->lastInsertId(); log_act($uid, 'sop_created', $f['title']);
    if ($f['status'] === 'published' && $f['ack'] && !empty($b['notify'])) sop_notify(sop_need($id), 'New procedure to read');
    out(['ok' => true, 'id' => $id], 201);
}
if (!$rid) fail('Not found', 404);
$p = sop_need($rid); $p['department_id'] = $p['department_id'] !== null ? (int)$p['department_id'] : null;
if (!sop_can_view($u, $p)) fail('You do not have access to this page.', 403);
$edit = sop_can_edit($u, $p['department_id']);

if ($method === 'GET' && $act === '') {
    $ack = one('SELECT version,acked_at FROM sop_acks WHERE page_id=? AND user_id=?', [$rid, $uid]);
    $p['can_edit'] = $edit; $p['my_ack'] = $ack ? (int)$ack['version'] : 0; $p['needs_ack'] = $p['status'] === 'published' && (int)$p['requires_ack'] === 1 && (int)($ack['version'] ?? 0) < (int)$p['version'];
    if ($edit && (int)$p['requires_ack'] === 1) {
        $acks = []; foreach (all('SELECT user_id,version,acked_at FROM sop_acks WHERE page_id=?', [$rid]) as $a) $acks[(int)$a['user_id']] = $a;
        $p['ack_status'] = array_map(fn($m) => ['name' => $m['name'], 'done' => isset($acks[(int)$m['id']]) && (int)$acks[(int)$m['id']]['version'] >= (int)$p['version'], 'at' => $acks[(int)$m['id']]['acked_at'] ?? null], sop_members($p['department_id']));
    }
    out($p);
}
if ($method === 'POST' && $act === 'ack') {
    if ($p['status'] !== 'published') fail('This page is not published.');
    q('INSERT INTO sop_acks (page_id,user_id,version) VALUES (?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version), acked_at=NOW()', [$rid, $uid, $p['version']]); out(['ok' => true]);
}
if (!$edit) fail('You can only edit pages for departments you manage.', 403);
if ($method === 'PATCH' && $act === '') {
    $b = body(); $f = sop_fields($b, $u, $p); $changed = $f['title'] !== $p['title'] || $f['body'] !== $p['body'];
    if ($changed) { q('INSERT INTO sop_versions (page_id,version,title,body,saved_by) VALUES (?,?,?,?,?)', [$rid, $p['version'], $p['title'], $p['body'], $p['updated_by']]); q('DELETE FROM sop_versions WHERE page_id=? AND id NOT IN (SELECT id FROM (SELECT id FROM sop_versions WHERE page_id=? ORDER BY id DESC LIMIT 15) x)', [$rid, $rid]); }
    q('UPDATE sop_pages SET department_id=?,title=?,body=?,category=?,status=?,pinned=?,requires_ack=?,version=version+?,updated_by=? WHERE id=?', [$f['dept'], $f['title'], $f['body'], $f['category'], $f['status'], $f['pinned'], $f['ack'], $changed ? 1 : 0, $uid, $rid]);
    log_act($uid, 'sop_updated', $f['title']);
    if ($changed && $f['status'] === 'published' && $f['ack'] && !empty($b['notify'])) { $np = sop_need($rid); $np['department_id'] = $np['department_id'] !== null ? (int)$np['department_id'] : null; sop_notify($np, 'Procedure updated'); }
    out(['ok' => true]);
}
if ($method === 'DELETE' && $act === '') { q('DELETE FROM sop_pages WHERE id=?', [$rid]); log_act($uid, 'sop_deleted', $p['title']); out(['ok' => true]); }
if ($method === 'GET' && $act === 'versions') out(all('SELECT v.id,v.version,v.title,v.saved_at,uu.name AS saved_by_name FROM sop_versions v LEFT JOIN users uu ON uu.id=v.saved_by WHERE v.page_id=? ORDER BY v.id DESC', [$rid]));
if ($method === 'POST' && $act === 'restore') {
    $v = one('SELECT * FROM sop_versions WHERE id=? AND page_id=?', [(int)(body()['version_id'] ?? 0), $rid]); if (!$v) fail('Version not found', 404);
    q('INSERT INTO sop_versions (page_id,version,title,body,saved_by) VALUES (?,?,?,?,?)', [$rid, $p['version'], $p['title'], $p['body'], $p['updated_by']]);
    q('UPDATE sop_pages SET title=?, body=?, version=version+1, updated_by=? WHERE id=?', [$v['title'], $v['body'], $uid, $rid]); out(['ok' => true]);
}
fail('Bad request', 405);
