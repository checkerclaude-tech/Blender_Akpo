<?php
declare(strict_types=1);
// Rich share messages for reports (daily and weekly): formatted text with pictures, attached documents, links, optional e-mail.
//   GET    /api/share/messages?kind=daily|weekly&id=N     the messages about one report that you may read (sent by you, to you, or you own the report)
//   GET    /api/share/file/{id}[?view=1]                  one attached file or picture (same access rule)
//   DELETE /api/share/messages/{id}                       the sender (or a Super Admin) removes a message
// Other code calls sm_create() when a report is shared.
require_once __DIR__ . '/taskfiles.php';

const SM_TAGS = ['p' => [], 'div' => [], 'br' => [], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'ul' => [], 'ol' => [], 'li' => [], 'h3' => [], 'h4' => [], 'blockquote' => [], 'a' => ['href'], 'img' => ['src', 'alt'], 'hr' => []];
const SM_BLOCKED_EXT = ['php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'com', 'scr', 'js', 'vbs', 'sh', 'msi', 'jar', 'htm', 'html', 'svg'];

function sm_dir(): string { task_storage_dir(); $dir = (string)(cfg('storage_dir') ?: dirname(__DIR__) . '/storage') . '/shares'; if (!is_dir($dir)) @mkdir($dir, 0775, true); return $dir; }

/** Keep only a small safe set of tags. Pictures may only point at our own stored files ($imgMap: upload index => file id). */
function sm_clean_html(string $html, array $imgMap = []): string {
    $html = trim($html); if ($html === '') return '';
    $doc = new DOMDocument('1.0', 'UTF-8'); $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="sm-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD); libxml_clear_errors(); libxml_use_internal_errors($prev);
    $root = $doc->getElementById('sm-root'); if (!$root) return '';
    $walk = function (DOMNode $n) use (&$walk, $doc, $imgMap) {
        foreach (iterator_to_array($n->childNodes) as $c) {
            if ($c->nodeType === XML_COMMENT_NODE) { $n->removeChild($c); continue; }
            if ($c->nodeType !== XML_ELEMENT_NODE) continue;
            $tag = strtolower($c->nodeName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'head', 'title'], true)) { $n->removeChild($c); continue; }
            if (!isset(SM_TAGS[$tag])) { $walk($c); while ($c->firstChild) $n->insertBefore($c->firstChild, $c); $n->removeChild($c); continue; }   // unknown tag: keep its text, drop the tag
            $keep = SM_TAGS[$tag]; $vals = [];
            foreach ($c->attributes as $a) $vals[strtolower($a->nodeName)] = $a->nodeValue;
            foreach (iterator_to_array($c->attributes) as $a) $c->removeAttributeNode($a);
            if ($tag === 'a') { $h = trim((string)($vals['href'] ?? '')); if (!preg_match('~^(https?://|mailto:)~i', $h)) { $walk($c); while ($c->firstChild) $n->insertBefore($c->firstChild, $c); $n->removeChild($c); continue; }
                $c->setAttribute('href', $h); $c->setAttribute('target', '_blank'); $c->setAttribute('rel', 'noopener noreferrer nofollow'); }
            if ($tag === 'img') { $src = null;
                if (isset($vals['data-i']) && ctype_digit((string)$vals['data-i']) && isset($imgMap[(int)$vals['data-i']])) $src = 'api/share/file/' . (int)$imgMap[(int)$vals['data-i']];
                elseif (preg_match('~^(?:api/)?share/file/(\d+)$~', (string)($vals['src'] ?? ''), $m)) $src = 'api/share/file/' . (int)$m[1];
                if ($src === null) { $n->removeChild($c); continue; } $c->setAttribute('src', $src); $c->setAttribute('alt', mb_substr((string)($vals['alt'] ?? ''), 0, 120)); continue; }
            $walk($c);
        }
    };
    $walk($root); $out = ''; foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    $out = trim($out); if (mb_strlen($out) > 60000) fail('The message is too long');
    return $out;
}
/** Plain text of a cleaned message (notifications, plain-text e-mail). */
function sm_plain(string $html): string {
    $t = preg_replace('~<br\s*/?>|</(p|div|li|h3|h4|blockquote)>~i', "\n", $html); $t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $t)));
}
function sm_url($v): string { $v = trim((string)($v ?? '')); if ($v === '') return ''; if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v; return filter_var($v, FILTER_VALIDATE_URL) && mb_strlen($v) <= 500 ? $v : ''; }

function sm_store(int $msgId, string $name, string $mime, string $data, int $inline): int {
    if ($data === '') fail('That file is empty'); if (strlen($data) > 8 * 1048576) fail('Files are limited to 8 MB each');
    $name = mb_substr(preg_replace('/[\x00-\x1f\\\\\/]+/', '_', $name) ?: 'file', 0, 190); $rel = bin2hex(random_bytes(16));
    if (@file_put_contents(sm_dir() . '/' . $rel, $data) === false) fail('Could not save the file on the server (storage folder not writable)');
    q('INSERT INTO share_files (msg_id,name,mime,size,path,inline) VALUES (?,?,?,?,?,?)', [$msgId, $name, mb_substr($mime ?: 'application/octet-stream', 0, 100), strlen($data), $rel, $inline]);
    return (int)db()->lastInsertId();
}

/**
 * Save a message about one report. $b: html, note, images[{type,data}], files[{name,type,data}], links[]. Returns null when it holds nothing.
 * Result: id, html (pictures point at stored files), text, links, files (documents only), mail_inline (for e-mail), mail_files.
 */
function sm_create(string $kind, int $targetId, array $u, array $b, array $to): ?array {
    $html0 = (string)($b['html'] ?? ''); if (trim($html0) === '' && trim((string)($b['note'] ?? '')) !== '') $html0 = '<p>' . nl2br(htmlspecialchars(mb_substr(trim((string)$b['note']), 0, 8000), ENT_QUOTES, 'UTF-8')) . '</p>';
    $imgs = array_slice(array_values(array_filter((array)($b['images'] ?? []), 'is_array')), 0, 10); $docs = array_slice(array_values(array_filter((array)($b['files'] ?? []), 'is_array')), 0, 8);
    $links = []; foreach (array_slice((array)($b['links'] ?? []), 0, 10) as $l) { $l = sm_url($l); if ($l !== '') $links[] = $l; }
    $bytes = 0; $raw = []; foreach (['i' => $imgs, 'd' => $docs] as $k => $list) foreach ($list as $i => $f) { $d = base64_decode((string)($f['data'] ?? ''), true); if ($d === false || $d === '') fail('A file could not be read'); $bytes += strlen($d); $raw[$k][$i] = $d; }
    if ($bytes > 14 * 1048576) fail('The pictures and attachments are larger than 14 MB together');
    if (trim(strip_tags($html0)) === '' && !preg_match('/<img\b/i', $html0) && !$docs && !$links) return null;
    foreach ($imgs as $f) if (!preg_match('~^image/(png|jpeg|gif|webp)$~', strtolower((string)($f['type'] ?? '')))) fail('Pictures must be PNG, JPG, GIF or WEBP');   // check everything before anything is saved
    foreach ($raw as $list) foreach ($list as $d) if (strlen($d) > 8 * 1048576) fail('Files are limited to 8 MB each');
    foreach ($docs as $f) { $nm = (string)($f['name'] ?? 'file'); if (in_array(strtolower(pathinfo($nm, PATHINFO_EXTENSION)), SM_BLOCKED_EXT, true)) fail('That kind of file cannot be attached: ' . $nm); }
    q('INSERT INTO share_messages (kind,target_id,sender_id,body,links,to_ids) VALUES (?,?,?,?,?,?)', [$kind, $targetId, (int)$u['id'], '', json_encode($links), json_encode(array_values($to))]); $mid = (int)db()->lastInsertId();
    try {
        $map = []; $mailInline = [];
        foreach ($imgs as $i => $f) { $t = strtolower((string)($f['type'] ?? '')); if (!preg_match('~^image/(png|jpeg|gif|webp)$~', $t)) fail('Pictures must be PNG, JPG, GIF or WEBP');
            $fid = sm_store($mid, (string)($f['name'] ?? 'picture'), $t, $raw['i'][$i], 1); $map[$i] = $fid; $mailInline[$fid] = ['type' => $t, 'cid' => 'sm' . $fid . '@aerosub', 'data' => $raw['i'][$i]]; }
        $mailFiles = []; $files = [];
        foreach ($docs as $i => $f) { $nm = (string)($f['name'] ?? 'file'); if (in_array(strtolower(pathinfo($nm, PATHINFO_EXTENSION)), SM_BLOCKED_EXT, true)) fail('That kind of file cannot be attached: ' . $nm);
            $fid = sm_store($mid, $nm, (string)($f['type'] ?? 'application/octet-stream'), $raw['d'][$i], 0); $files[] = ['id' => $fid, 'name' => $nm, 'size' => strlen($raw['d'][$i])]; $mailFiles[] = ['name' => $nm, 'type' => (string)($f['type'] ?? '') ?: 'application/octet-stream', 'data' => $raw['d'][$i]]; }
        $html = sm_clean_html($html0, $map); q('UPDATE share_messages SET body=? WHERE id=?', [$html, $mid]);
    } catch (Throwable $e) { sm_delete($mid); throw $e; }
    return ['id' => $mid, 'html' => $html, 'text' => sm_plain($html), 'links' => $links, 'files' => $files, 'mail_inline' => $mailInline, 'mail_files' => $mailFiles];
}
function sm_delete(int $mid): void {
    foreach (all('SELECT path FROM share_files WHERE msg_id=?', [$mid]) as $f) { $p = sm_dir() . '/' . preg_replace('/[^a-f0-9]/', '', (string)$f['path']); if (is_file($p)) @unlink($p); }
    q('DELETE FROM share_files WHERE msg_id=?', [$mid]); q('DELETE FROM share_messages WHERE id=?', [$mid]);
}
/** The e-mail body for one recipient: name, who shared what, the rich message with pictures inline, links, attached-file names, a link back. */
function sm_mail(array $m, array $u, array $rcpt, string $what, string $link, string $extraHtml = ''): array {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); $body = (string)$m['html'];
    $body = preg_replace_callback('~<img src="api/share/file/(\d+)"~', fn($x) => isset($m['mail_inline'][(int)$x[1]]) ? '<img style="max-width:100%;height:auto" src="cid:' . $m['mail_inline'][(int)$x[1]]['cid'] . '"' : '<img', $body);
    $ls = $m['links'] ? '<p><b>Links</b></p><ul>' . implode('', array_map(fn($l) => '<li><a href="' . $e($l) . '">' . $e($l) . '</a></li>', $m['links'])) . '</ul>' : '';
    $fs = $m['files'] ? '<p><b>Attached</b>: ' . implode(', ', array_map(fn($f) => $e($f['name']), $m['files'])) . '</p>' : '';
    $html = '<p>Hello ' . $e($rcpt['name']) . ',</p><p><b>' . $e($u['name']) . '</b> shared ' . $e($what) . ' with you on Aerosub Office.</p>' . $extraHtml . ($body !== '' ? '<div style="border-left:3px solid #ccd;padding:6px 14px;margin:10px 0">' . $body . '</div>' : '') . $ls . $fs .
        '<p><a href="' . $e($link) . '" style="background:#0A6EA8;color:#fff;padding:9px 16px;border-radius:6px;text-decoration:none">Open it in Aerosub Office</a></p><p style="color:#778;font-size:12px">' . $e($u['name']) . ' used Aerosub Office to send this. Replies go to them. You also have a notification in the app.</p>';
    $text = 'Hello ' . $rcpt['name'] . ",\n\n" . $u['name'] . ' shared ' . $what . " with you.\n\n" . ($m['text'] !== '' ? $m['text'] . "\n\n" : '') . ($m['links'] ? "Links:\n" . implode("\n", $m['links']) . "\n\n" : '') . ($m['files'] ? 'Attached: ' . implode(', ', array_column($m['files'], 'name')) . "\n\n" : '') . 'Open it: ' . $link . "\n";
    return [$html, $text];
}

function sm_owner(string $kind, int $target): int { $r = $kind === 'weekly' ? one('SELECT user_id FROM weekly_reports WHERE id=?', [$target]) : one('SELECT user_id FROM daily_reports WHERE id=?', [$target]); return (int)($r['user_id'] ?? 0); }
function sm_can_read(array $u, array $m): bool {
    $uid = (int)$u['id']; if ($u['role'] === 'super_admin' || (int)$m['sender_id'] === $uid || in_array($uid, array_map('intval', json_decode((string)$m['to_ids'], true) ?: []), true)) return true;
    return sm_owner((string)$m['kind'], (int)$m['target_id']) === $uid;
}
function sm_out(array $m): array {
    $files = array_map(fn($f) => ['id' => (int)$f['id'], 'name' => $f['name'], 'size' => (int)$f['size'], 'mime' => $f['mime']], all('SELECT id,name,size,mime FROM share_files WHERE msg_id=? AND inline=0 ORDER BY id', [$m['id']]));
    $to = array_column(all('SELECT name FROM users WHERE id IN (' . (implode(',', array_map('intval', json_decode((string)$m['to_ids'], true) ?: [])) ?: '0') . ') ORDER BY name'), 'name');
    return ['id' => (int)$m['id'], 'sender_id' => (int)$m['sender_id'], 'sender' => $m['sender'], 'created_at' => $m['created_at'], 'html' => $m['body'], 'links' => json_decode((string)$m['links'], true) ?: [], 'files' => $files, 'to' => $to];
}

/* ---------- routes: /api/share/... ---------- */
if (isset($seg) && ($seg[0] ?? '') === 'share') {
    $u = me(); if (!$u) fail('Please sign in', 401); $what = $seg[1] ?? '';
    if ($method === 'GET' && $what === 'messages') {
        $kind = ($_GET['kind'] ?? '') === 'weekly' ? 'weekly' : 'daily'; $tid = (int)($_GET['id'] ?? 0); $out = [];
        foreach (all('SELECT m.*,us.name AS sender FROM share_messages m LEFT JOIN users us ON us.id=m.sender_id WHERE m.kind=? AND m.target_id=? ORDER BY m.id DESC LIMIT 50', [$kind, $tid]) as $m) if (sm_can_read($u, $m)) $out[] = sm_out($m);
        out(['messages' => $out]);
    }
    if ($method === 'GET' && $what === 'file' && ctype_digit($seg[2] ?? '')) {
        $f = one('SELECT f.*,m.kind,m.target_id,m.sender_id,m.to_ids FROM share_files f JOIN share_messages m ON m.id=f.msg_id WHERE f.id=?', [(int)$seg[2]]); if (!$f || !sm_can_read($u, $f)) fail('Not found', 404);
        $p = sm_dir() . '/' . preg_replace('/[^a-f0-9]/', '', (string)$f['path']); if (!is_file($p)) fail('The file is no longer on the server', 404);
        $inline = (!empty($_GET['view']) || (int)$f['inline'] === 1) && preg_match('~^image/(png|jpeg|gif|webp)$~', (string)$f['mime']); $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$f['name']) ?: 'file';
        header('Content-Type: ' . ($inline ? $f['mime'] : 'application/octet-stream')); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=3600');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode((string)$f['name'])); header('Content-Length: ' . (string)filesize($p)); readfile($p); exit;
    }
    if ($method === 'DELETE' && $what === 'messages' && ctype_digit($seg[2] ?? '')) {
        $m = one('SELECT * FROM share_messages WHERE id=?', [(int)$seg[2]]); if (!$m) fail('Not found', 404);
        if ((int)$m['sender_id'] !== (int)$u['id'] && $u['role'] !== 'super_admin') fail('Only the sender can remove this', 403); sm_delete((int)$m['id']); out(['ok' => true]);
    }
    fail('Bad request', 404);
}
