<?php
declare(strict_types=1);
// Routes: /api/mail/*  (included from api/index.php). Each person only ever reaches their OWN mailbox.
require_once __DIR__ . '/mail.php';

$u = need_user(); $uid = (int)$u['id'];
$verify = !cfg('mail_insecure_tls');

function mail_host_ok(string $host, int $port, array $allowedPorts): void {
    if (!preg_match('/^[a-z0-9]([a-z0-9.-]{0,188}[a-z0-9])?$/i', $host)) throw new MailError('That server name does not look right.');
    if (!in_array($port, $allowedPorts, true)) throw new MailError('That port is not supported (use ' . implode(' or ', $allowedPorts) . ').');
    if (!cfg('mail_allow_private')) {
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) throw new MailError("Could not find the server “$host”. Check the spelling.");
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new MailError('That server address is not allowed.');
    }
}
function mail_account(int $uid, int $id = 0): ?array {
    return $id > 0 ? one('SELECT * FROM mail_accounts WHERE user_id=? AND id=?', [$uid, $id]) : one('SELECT * FROM mail_accounts WHERE user_id=? ORDER BY id LIMIT 1', [$uid]);
}
function mail_sig_html(array $a): string {
    $h = trim((string)($a['sig_html'] ?? '')); if ($h !== '') return $h;
    $p = trim((string)($a['signature'] ?? '')); return $p === '' ? '' : nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8'));
}
function mail_pub(array $a, array $u): array {
    return ['id' => (int)$a['id'], 'email' => $a['email'], 'from_name' => $a['from_name'] ?: $u['name'], 'signature_html' => mail_sig_html($a), 'imap_host' => $a['imap_host'], 'smtp_host' => $a['smtp_host']];
}
function mail_defaults(): array {
    return ['imap_host' => cfg('mail_imap_host') ?: 'imap.stackmail.com', 'imap_port' => 993, 'smtp_host' => cfg('mail_smtp_host') ?: 'smtp.stackmail.com', 'smtp_port' => 465];
}
function mail_imap(array $acc, bool $verify): Imap {
    $im = new Imap($acc['imap_host'], (int)$acc['imap_port'], $verify);
    $im->login($acc['email'], mail_decrypt($acc['secret_enc']));
    return $im;
}
function mail_need(int $uid): array {
    $id = (int)($_GET['acc'] ?? 0); if (!$id && $GLOBALS['method'] === 'POST') { $bb = body(); $id = (int)($bb['acc'] ?? 0); }
    $a = mail_account($uid, $id); if (!$a) fail($id ? 'That mailbox is not connected.' : 'Connect your mailbox first.', 409); return $a;
}
function mail_folder_check(Imap $im, string $folder): array {
    $fl = $im->folders();
    foreach ($fl as $f) if ($f['name'] === $folder) return $fl;
    fail('That folder does not exist.', 404);
}
function mail_role(array $folders, string $role): ?string { foreach ($folders as $f) if ($f['role'] === $role) return $f['name']; return null; }

try {

/* ---------- accounts (a person can connect several mailboxes; several people can share one) ---------- */
if ($sub === 'accounts' && $method === 'GET') {
    $rows = all('SELECT * FROM mail_accounts WHERE user_id=? ORDER BY id', [$uid]);
    out(['accounts' => array_map(fn($a) => mail_pub($a, $u), $rows), 'defaults' => mail_defaults(), 'user_name' => $u['name'], 'suggest_email' => $u['email']]);
}
if ($sub === 'account') {
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0); $a = mail_account($uid, $id); if (!$a) fail('Mailbox not found', 404);
        q('DELETE FROM mail_accounts WHERE id=? AND user_id=?', [$id, $uid]); log_act($uid, 'mailbox_disconnected', $a['email']); out(['ok' => true]);
    }
    if ($method === 'POST') {
        $b = body(); $d = mail_defaults(); $id = (int)($b['id'] ?? 0);
        $old = $id ? mail_account($uid, $id) : null; if ($id && !$old) fail('Mailbox not found', 404);
        $email = strtolower(s($b['email'] ?? ($old['email'] ?? ''), 190)); $pass = (string)($b['password'] ?? '');
        if (!$old) { $old = one('SELECT * FROM mail_accounts WHERE user_id=? AND email=?', [$uid, $email]); }
        $fromName = array_key_exists('from_name', $b) ? trim(preg_replace('/[\r\n<>"]+/', ' ', s($b['from_name'], 120))) : ($old['from_name'] ?? '');
        $sigIn = array_key_exists('signature_html', $b) ? $b['signature_html'] : null;
        $sigHtml = $sigIn === null ? ($old['sig_html'] ?? '') : trim(html_sanitize_outgoing(mb_substr((string)$sigIn, 0, 6000), $uid));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter the full mailbox email address.');
        if ($pass === '' && $old && $old['email'] === $email) { // settings-only update (name, signature)
            q('UPDATE mail_accounts SET from_name=?, sig_html=? WHERE id=? AND user_id=?', [$fromName ?: null, $sigHtml, $old['id'], $uid]); out(['ok' => true, 'id' => (int)$old['id']]);
        }
        if ($pass === '') fail('Enter the mailbox password.');
        if (!$old) { $n = one('SELECT COUNT(*) c FROM mail_accounts WHERE user_id=?', [$uid]); if ((int)$n['c'] >= 8) fail('You can connect up to 8 mailboxes.'); }
        $ih = s($b['imap_host'] ?? $d['imap_host'], 190); $ip = (int)($b['imap_port'] ?? $d['imap_port']); $sh = s($b['smtp_host'] ?? $d['smtp_host'], 190); $sp = (int)($b['smtp_port'] ?? $d['smtp_port']);
        mail_host_ok($ih, $ip, [993]); mail_host_ok($sh, $sp, [465, 587]);
        $im = new Imap($ih, $ip, $verify); $im->login($email, $pass);                // proves the password for reading
        $sm = new Smtp($sh, $sp, $verify); $sm->auth($email, $pass);                  // and for sending
        $enc = mail_encrypt($pass);
        if ($old) {
            q('UPDATE mail_accounts SET email=?,imap_host=?,imap_port=?,smtp_host=?,smtp_port=?,secret_enc=?,from_name=?,sig_html=? WHERE id=? AND user_id=?', [$email, $ih, $ip, $sh, $sp, $enc, $fromName ?: null, $sigHtml, $old['id'], $uid]); $nid = (int)$old['id'];
        } else {
            q('INSERT INTO mail_accounts (user_id,email,imap_host,imap_port,smtp_host,smtp_port,secret_enc,from_name,sig_html) VALUES (?,?,?,?,?,?,?,?,?)', [$uid, $email, $ih, $ip, $sh, $sp, $enc, $fromName ?: null, $sigHtml]); $nid = (int)db()->lastInsertId();
        }
        log_act($uid, 'mailbox_connected', $email);
        out(['ok' => true, 'id' => $nid]);
    }
    fail('Bad request', 405);
}

/* ---------- suggestions (CRM contacts + teammates) ---------- */
if ($sub === 'suggest') {
    $qs = s($_GET['q'] ?? '', 60); $list = !empty($_GET['list']);
    if (!$list && strlen($qs) < 2) out([]);
    $like = '%' . $qs . '%'; $lim = $list ? 150 : 6;
    $rows = all("SELECT c.name,c.email,co.name AS company,'contact' AS kind FROM contacts c LEFT JOIN companies co ON co.id=c.company_id WHERE c.email IS NOT NULL AND c.email<>'' AND (c.name LIKE ? OR c.email LIKE ? OR co.name LIKE ?) ORDER BY c.name LIMIT $lim", [$like, $like, $like]);
    $rows = array_merge($rows, all("SELECT name,email,'' AS company,'team' AS kind FROM users WHERE active=1 AND (name LIKE ? OR email LIKE ?) ORDER BY name LIMIT $lim", [$like, $like]));
    out($rows);
}

$acc = mail_need($uid);

/* ---------- unread badge ---------- */
if ($sub === 'unread') {
    $tot = 0; $per = [];
    foreach (all('SELECT * FROM mail_accounts WHERE user_id=? ORDER BY id LIMIT 8', [$uid]) as $a) { try { $st = mail_imap($a, $verify)->status('INBOX'); $per[] = ['id' => (int)$a['id'], 'unseen' => (int)$st['unseen']]; $tot += (int)$st['unseen']; } catch (Throwable $e) {} }
    out(['unseen' => $tot, 'accounts' => $per]);
}

/* ---------- folders ---------- */
if ($sub === 'folders') {
    $im = mail_imap($acc, $verify); $fl = $im->folders();
    foreach (array_slice($fl, 0, 30) as $i => $f) { try { $st = $im->status($f['name']); $fl[$i]['unseen'] = $st['unseen']; $fl[$i]['messages'] = $st['messages']; } catch (MailError $e) {} }
    out($fl);
}

/* ---------- list ---------- */
if ($sub === 'messages') {
    $folder = (string)($_GET['folder'] ?? 'INBOX'); $page = max(1, (int)($_GET['page'] ?? 1)); $per = 25; $qs = trim((string)($_GET['q'] ?? ''));
    $im = mail_imap($acc, $verify); mail_folder_check($im, $folder); $im->select($folder);
    $crit = 'ALL';
    if ($qs !== '') {
        $qs = mb_substr($qs, 0, 80); $qq = imap_q($qs);
        try { $uids = $im->search("CHARSET UTF-8 OR OR FROM $qq TO $qq SUBJECT $qq"); }
        catch (MailError $e) { $uids = $im->search("OR OR FROM $qq TO $qq SUBJECT $qq"); }
    } else { $uids = $im->search(!empty($_GET['unread']) ? 'UNSEEN' : 'ALL'); }
    $total = count($uids); $len = min($per, $total - ($page - 1) * $per); $slice = $len > 0 ? array_reverse(array_slice($uids, max(0, $total - $page * $per), $len)) : [];
    $h = $im->headers($slice); $msgs = [];
    foreach ($slice as $uidm) if (isset($h[$uidm])) $msgs[] = $h[$uidm];
    out(['total' => $total, 'page' => $page, 'per' => $per, 'messages' => $msgs]);
}

/* ---------- read one ---------- */
if ($sub === 'message' && $method === 'GET') {
    $folder = (string)($_GET['folder'] ?? 'INBOX'); $muid = (int)($_GET['uid'] ?? 0); if ($muid < 1) fail('Bad message id');
    $im = mail_imap($acc, $verify); mail_folder_check($im, $folder); $im->select($folder);
    $raw = $im->fetchRaw($muid); $x = mime_extract($raw);
    try { $im->flag($muid, '\\Seen', true); } catch (MailError $e) {}
    $h = $x['headers']; $html = null; $remote = false;
    if ($x['html'] !== null) { $remote = (bool)preg_match('~(src|background)\s*=\s*["\']?\s*https?:|url\(\s*["\']?https?:~i', $x['html']); $html = html_sanitize_incoming($x['html'], $x['inline']); }
    out(['uid' => $muid, 'folder' => $folder, 'subject' => mime_decode_header($h['subject'] ?? ''), 'from' => mime_addr_list($h['from'] ?? ''), 'to' => mime_addr_list($h['to'] ?? ''), 'cc' => mime_addr_list($h['cc'] ?? ''),
         'reply_to' => mime_addr_list($h['reply-to'] ?? ''), 'date' => mime_date($h['date'] ?? ''), 'message_id' => trim($h['message-id'] ?? ''), 'references' => trim($h['references'] ?? ''),
         'html' => $html, 'text' => mb_substr($x['text'], 0, 200000), 'remote_images' => $remote, 'attachments' => $x['attachments']]);
}

/* ---------- attachment download ---------- */
if ($sub === 'attachment' && $method === 'GET') {
    $folder = (string)($_GET['folder'] ?? 'INBOX'); $muid = (int)($_GET['uid'] ?? 0); $i = (int)($_GET['i'] ?? -1);
    $im = mail_imap($acc, $verify); mail_folder_check($im, $folder); $im->select($folder);
    $x = mime_extract($im->fetchRaw($muid)); $leaf = $x['leaves'][$i] ?? null; if (!$leaf) fail('Attachment not found', 404);
    $name = $leaf['filename'] ?: 'attachment'; $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'attachment';
    header('Content-Type: application/octet-stream'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store');
    header('Content-Disposition: attachment; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . strlen($leaf['body'])); echo $leaf['body']; exit;
}

/* ---------- email -> task (e.g. an RFQ that arrived by email) ---------- */
if ($sub === 'to-task' && $method === 'POST') {
    if ($u['role'] === 'founder') fail('Founder accounts are read-only', 403);
    require_once __DIR__ . '/taskfiles.php';
    $b = body(); $folder = (string)($b['folder'] ?? 'INBOX'); $muid = (int)($b['uid'] ?? 0); if ($muid < 1) fail('Bad message id');
    $assignee = (int)($b['assignee_id'] ?? 0) ?: $uid; $at = one('SELECT id,department_id FROM users WHERE id=? AND active=1', [$assignee]); if (!$at) fail('Choose who should handle this');
    $im = mail_imap($acc, $verify); mail_folder_check($im, $folder); $im->select($folder);
    $x = mime_extract($im->fetchRaw($muid)); $h = $x['headers'];
    $subject = mime_decode_header($h['subject'] ?? '') ?: '(no subject)'; $from = implode(', ', array_map(fn($a) => ($a['name'] ?? '') !== '' ? $a['name'] . ' <' . $a['email'] . '>' : $a['email'], mime_addr_list($h['from'] ?? '')));
    $date = mime_date($h['date'] ?? '');
    $note = s($b['note'] ?? '', 1000); $text = trim(preg_replace("/\n{3,}/", "\n\n", (string)$x['text']));
    $desc = ($note !== '' ? $note . "\n\n" : '') . "From: $from\nReceived: " . ($date ?: '') . "\nMailbox: " . $acc['email'] . "\n\n" . mb_substr($text, 0, 4200);
    $type = in((string)($b['task_type'] ?? ''), ['rfq', 'general'], 'rfq');
    q('INSERT INTO tasks (title,description,status,priority,assignee_id,creator_id,department_id,due_date,task_type,source) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [mb_substr(($type === 'rfq' ? 'RFQ: ' : '') . $subject, 0, 200), mb_substr($desc, 0, 5000), 'todo', in((string)($b['priority'] ?? ''), ['low', 'medium', 'high', 'urgent'], 'high'), $assignee, $uid, $at['department_id'] ?: null, d($b['due_date'] ?? null), $type, 'email']);
    $tid = (int)db()->lastInsertId(); $saved = 0; $skipped = 0; $total = 0;
    foreach ($x['attachments'] as $a) { $leaf = $x['leaves'][$a['i']] ?? null; if (!$leaf) continue; $len = strlen($leaf['body']);
        if ($len > 8 * 1024 * 1024 || $total + $len > 24 * 1024 * 1024 || $saved >= 15) { $skipped++; continue; }
        try { task_store_file($tid, $uid, $leaf['filename'] ?: 'attachment', (string)($leaf['type'] ?? 'application/octet-stream'), $leaf['body']); $saved++; $total += $len; } catch (Throwable $e) { $skipped++; } }
    if ($assignee !== $uid) notify($assignee, 'task_assigned', 'New ' . ($type === 'rfq' ? 'RFQ' : 'task') . ': ' . $subject, 'From email · assigned by ' . $u['name'], '#/tasks/' . $tid);
    log_act($uid, 'task_from_email', $subject);
    out(['id' => $tid, 'attachments' => $saved, 'skipped' => $skipped], 201);
}

/* ---------- flag / move / delete ---------- */
if ($method === 'POST' && in_array($sub, ['flag', 'move', 'delete'], true)) {
    $b = body(); $folder = (string)($b['folder'] ?? ''); $muid = (int)($b['uid'] ?? 0); if ($muid < 1) fail('Bad message id');
    $im = mail_imap($acc, $verify); $fl = mail_folder_check($im, $folder); $im->select($folder);
    if ($sub === 'flag') {
        if (array_key_exists('seen', $b)) $im->flag($muid, '\\Seen', (bool)$b['seen']);
        if (array_key_exists('flagged', $b)) $im->flag($muid, '\\Flagged', (bool)$b['flagged']);
        out(['ok' => true]);
    }
    if ($sub === 'move') {
        $to = (string)($b['to'] ?? ''); $okDest = false; foreach ($fl as $f) if ($f['name'] === $to) $okDest = true;
        if (!$okDest || $to === $folder) fail('Pick a different folder.');
        $im->move($muid, $to); out(['ok' => true]);
    }
    $trash = mail_role($fl, 'trash'); $curRole = ''; foreach ($fl as $f) if ($f['name'] === $folder) $curRole = $f['role'];
    if ($curRole === 'trash' || $curRole === 'junk' || !$trash) { $im->expunge($muid); out(['ok' => true, 'permanent' => true]); }
    $im->move($muid, $trash); out(['ok' => true]);
}

/* ---------- send / save draft ---------- */
if ($sub === 'send' && $method === 'POST') {
    $b = body(); $draft = !empty($b['draft']);
    $recent = one("SELECT COUNT(*) c FROM activity_log WHERE user_id=? AND action='mail_sent' AND created_at > (NOW() - INTERVAL 1 HOUR)", [$uid]);
    if (!$draft && (int)$recent['c'] >= 60) fail('Hourly sending limit reached (60). Try again later.', 429);
    $to = parse_recipients((string)($b['to'] ?? '')); $cc = parse_recipients((string)($b['cc'] ?? '')); $bcc = parse_recipients((string)($b['bcc'] ?? ''));
    if (!$draft && !$to) fail('Add at least one recipient.');
    if (count($to) + count($cc) + count($bcc) > 50) fail('Too many recipients (maximum 50).');
    $html = html_sanitize_outgoing((string)($b['html'] ?? ''), $uid);
    if (!$draft && trim(html_to_text($html)) === '' && empty($b['attachments'])) fail('The message is empty.');
    $atts = []; $tot = 0;
    foreach ((array)($b['attachments'] ?? []) as $a) {
        $data = base64_decode((string)($a['data'] ?? ''), true); if ($data === false) fail('An attachment could not be read.');
        $tot += strlen($data); if ($tot > 9_000_000) fail('Attachments are too large (maximum about 9 MB in total).');
        $atts[] = ['name' => s($a['name'] ?? 'attachment', 150), 'type' => s($a['type'] ?? 'application/octet-stream', 100), 'data' => $data];
        if (count($atts) > 10) fail('Too many attachments (maximum 10).');
    }
    $domain = substr(strrchr($acc['email'], '@'), 1) ?: 'aerosub.co';
    $im = mail_imap($acc, $verify); $fl = $im->folders();
    $irt = ''; $refs = ''; $replyFolder = (string)($b['in_reply_folder'] ?? ''); $replyUid = (int)($b['in_reply_uid'] ?? 0);
    if ($replyUid > 0 && $replyFolder !== '') { foreach ($fl as $f) if ($f['name'] === $replyFolder) { $im->select($replyFolder); $hh = $im->headers([$replyUid]); if (isset($hh[$replyUid])) { $irt = $hh[$replyUid]['message_id']; $refs = $hh[$replyUid]['references']; } } }
    $inline = [];
    if (str_contains($html, 'src="api/avatar/')) {
        $av = one('SELECT mime,data FROM user_avatars WHERE user_id=?', [$uid]);
        if ($av) { $cid = 'photo-' . bin2hex(random_bytes(6)) . '@' . $domain; $html = str_replace('src="api/avatar/' . $uid . '"', 'src="cid:' . $cid . '"', $html); $inline[] = ['cid' => $cid, 'type' => $av['mime'], 'data' => $av['data']]; }
        else $html = preg_replace('~<img[^>]*src="api/avatar/\d+"[^>]*>~i', '', $html);
    }
    $raw = build_message(['inline' => $inline, 'from' => ['name' => ($acc['from_name'] ?: $u['name']), 'email' => $acc['email']], 'sent_by' => $u['name'] . ' <' . $u['email'] . '>', 'to' => $to ?: [['name' => '', 'email' => $acc['email']]], 'cc' => $cc, 'subject' => s($b['subject'] ?? '', 300), 'html' => $html, 'text' => html_to_text($html),
        'attachments' => $atts, 'in_reply_to' => $irt, 'references' => $refs, 'domain' => $domain]);
    if ($draft) { $df = mail_role($fl, 'drafts'); if (!$df) fail('No Drafts folder was found on your mailbox.'); $im->append($df, $raw, '\\Draft \\Seen'); out(['ok' => true, 'draft' => true]); }
    $rcpts = array_values(array_unique(array_map(fn($a) => $a['email'], array_merge($to, $cc, $bcc))));
    mail_host_ok($acc['smtp_host'], (int)$acc['smtp_port'], [465, 587]);
    $sm = new Smtp($acc['smtp_host'], (int)$acc['smtp_port'], $verify); $sm->auth($acc['email'], mail_decrypt($acc['secret_enc']));
    $sm->deliver($acc['email'], $rcpts, $raw);
    log_act($uid, 'mail_sent', $acc['email'] . ' → ' . count($rcpts) . ' recipient(s)');
    $warn = null;
    try { $sf = mail_role($fl, 'sent'); if ($sf) $im->append($sf, $raw, '\\Seen'); else $warn = 'Sent, but no Sent folder was found to keep a copy.'; } catch (MailError $e) { $warn = 'Sent, but a copy could not be saved to your Sent folder.'; }
    if ($irt !== '' && $replyUid > 0) { try { $im->select($replyFolder); $im->flag($replyUid, '\\Answered', true); } catch (MailError $e) {} }
    out(['ok' => true, 'warning' => $warn]);
}

fail('Not found', 404);

} catch (MailError $e) {
    fail($e->getMessage(), 502);
}
