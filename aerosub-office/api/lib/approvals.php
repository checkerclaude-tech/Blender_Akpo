<?php
declare(strict_types=1);
// Approval and signature chain for finance documents (commercials, proformas / quotations, invoices).
//   Review  >  Request signature  >  Sign   (or Revert at any step, with a reason)
// The author picks the chain (reviewers first, then one or more signers). Each person acts in turn; the document is locked while it is
// in the chain and after it is signed, and it must still be exactly what was submitted (a content fingerprint is checked at every step).
// Routes (called from finance.php):
//   GET  finance/doc/{id}/approval            the chain, whose turn it is, suggestions for a new chain
//   POST finance/doc/{id}/approval            ask for approval      {steps:[{kind:'review'|'sign', user_id}]}
//   POST finance/doc/{id}/approval/act        {action:'approve'|'sign'|'revert'|'cancel', comment, signature:{mode:'saved'|'new', image, save}}
//   GET  finance/approvals                    what is waiting for me, and my requests
//   GET|PUT|DELETE finance/signature          my saved signature (PNG)
require_once __DIR__ . '/accounts.php';

const AP_ACTIVE = ['in_review', 'awaiting_sign'];
const AP_TYPES = ['commercial' => 'Commercial', 'proforma' => 'Proforma / quotation', 'invoice' => 'Invoice'];

function ap_role_label(array $r): string { return ['super_admin' => 'Super Admin', 'founder' => 'Founder / CEO', 'manager' => 'Manager', 'employee' => 'Employee'][$r['role']] ?? $r['role']; }
/** People who may review or sign: administrators, the Founder, managers and heads of department (the chain of command). */
function ap_eligible(): array {
    $heads = []; foreach (all('SELECT id,name,head_id FROM departments WHERE head_id IS NOT NULL') as $d) $heads[(int)$d['head_id']][] = $d['name'];
    $rows = all("SELECT u.id,u.name,u.role,u.job_title,u.department_id FROM users u WHERE u.active=1 AND u.archived_at IS NULL ORDER BY u.name"); $out = [];
    foreach ($rows as $r) { $id = (int)$r['id']; if (!(in_array($r['role'], ['super_admin', 'founder', 'manager'], true) || isset($heads[$id]))) continue;
        $out[] = ['id' => $id, 'name' => $r['name'], 'title' => isset($heads[$id]) ? 'Head of ' . implode(', ', $heads[$id]) : ($r['job_title'] ?: ap_role_label($r)), 'role' => $r['role'], 'head' => isset($heads[$id])]; }
    return $out;
}
/** Content fingerprint: the same document always gives the same value; any change gives a different one. */
function ap_hash(array $d): string {
    $keep = ['doc_type', 'number', 'client_name', 'client_address', 'client_email', 'currency', 'currency_symbol', 'issue_date', 'due_date', 'valid_until', 'po_number', 'rfq_ref', 'tax_label', 'tax_rate', 'discount', 'notes', 'terms', 'days'];
    $core = []; foreach ($keep as $k) $core[$k] = $d[$k] ?? null; $core['items'] = array_map(fn($i) => [$i['description'] ?? '', (float)($i['qty'] ?? 0), $i['unit'] ?? '', (float)($i['unit_price'] ?? 0), $i['category'] ?? '', $i['freq'] ?? '', $i['amount_note'] ?? ''], $d['items'] ?? []);
    return hash('sha256', json_encode($core, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function ap_png($v): string {
    $v = (string)$v; if (!preg_match('~^data:image/png;base64,([A-Za-z0-9+/=]+)$~', $v, $m)) fail('The signature must be a drawn or typed picture');
    $bin = base64_decode($m[1], true); if ($bin === false || strlen($bin) > 220000 || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") fail('That signature picture is not valid');
    $sz = @getimagesizefromstring($bin); if (!$sz || $sz[0] > 1600 || $sz[1] > 800 || $sz[0] < 30) fail('That signature picture is not valid'); return $v;
}
function ap_event(int $aid, int $uid, string $action, string $comment = ''): void { q('INSERT INTO doc_approval_events (approval_id,user_id,action,comment) VALUES (?,?,?,?)', [$aid, $uid, $action, $comment !== '' ? mb_substr($comment, 0, 1000) : null]); }
function ap_current(int $docId): ?array { return one('SELECT * FROM doc_approvals WHERE doc_id=? ORDER BY id DESC LIMIT 1', [$docId]); }
function ap_steps(int $aid): array { return all('SELECT s.*, u.name, u.job_title, u.role FROM doc_approval_steps s JOIN users u ON u.id=s.user_id WHERE s.approval_id=? ORDER BY s.position', [$aid]); }
function ap_next_pending(array $steps): ?array { foreach ($steps as $s) if ($s['status'] === 'pending') return $s; return null; }
function ap_link(int $docId): string { require_once __DIR__ . '/campcore.php'; return camp_base() . '/#/finance/' . $docId; }
function ap_tell(int $to, array $actor, string $type, string $title, string $body, array $d, bool $mail = false, string $extra = ''): void {
    notify($to, $type, $title, $body, '#/finance/' . $d['id']);
    if (!$mail) return; $r = one('SELECT name,email FROM users WHERE id=? AND active=1', [$to]); if (!$r || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) return;
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); $link = ap_link((int)$d['id']);
    $html = '<p>Hello ' . $e($r['name']) . ',</p><p><b>' . $e($actor['name']) . '</b>: ' . $e($title) . '</p><p>' . $e((AP_TYPES[$d['doc_type']] ?? $d['doc_type']) . ' ' . $d['number'] . ' · ' . $d['client_name']) . '</p>' . ($extra !== '' ? '<div style="white-space:pre-wrap;border-left:3px solid #ccd;padding:6px 12px">' . $e($extra) . '</div>' : '') .
        '<p><a href="' . $e($link) . '" style="background:#0A6EA8;color:#fff;padding:9px 16px;border-radius:6px;text-decoration:none">Open the document</a></p><p style="color:#778;font-size:12px">Sent through Aerosub Office on behalf of ' . $e($actor['name']) . '. You also have a notification in the app.</p>';
    acct_send_mail($r['email'], $title . ': ' . $d['number'], $html, $title . "\n" . $d['number'] . ' · ' . $d['client_name'] . "\n" . $link . "\n", [], ['from_name' => $actor['name'], 'reply_to' => $actor['email'] ?? '']);
}
/** The chain as the page needs it. */
function ap_out(array $a, int $viewer): array {
    $steps = ap_steps((int)$a['id']); $next = $a['status'] === 'in_review' || $a['status'] === 'awaiting_sign' ? ap_next_pending($steps) : null; $o = []; $hd = []; foreach (all('SELECT name,head_id FROM departments WHERE head_id IS NOT NULL') as $dd) $hd[(int)$dd['head_id']][] = $dd['name'];
    foreach ($steps as $s) $o[] = ['id' => (int)$s['id'], 'position' => (int)$s['position'], 'kind' => $s['kind'], 'status' => $s['status'], 'user_id' => (int)$s['user_id'], 'name' => $s['name'], 'title' => isset($hd[(int)$s['user_id']]) ? 'Head of ' . implode(', ', $hd[(int)$s['user_id']]) : ($s['job_title'] ?: ap_role_label($s)), 'acted_at' => $s['acted_at'], 'comment' => $s['comment'],
        'signature' => $s['status'] === 'signed' ? $s['signature'] : null, 'fingerprint' => $s['status'] === 'signed' ? substr((string)$s['sig_hash'], 0, 16) : null, 'current' => $next && (int)$next['id'] === (int)$s['id']];
    $ev = all('SELECT e.action,e.comment,e.created_at,u.name FROM doc_approval_events e LEFT JOIN users u ON u.id=e.user_id WHERE e.approval_id=? ORDER BY e.id', [$a['id']]);
    return ['id' => (int)$a['id'], 'status' => $a['status'], 'created_by' => (int)$a['created_by'], 'requester' => one('SELECT name FROM users WHERE id=?', [$a['created_by']])['name'] ?? '', 'created_at' => $a['created_at'], 'closed_at' => $a['closed_at'], 'fingerprint' => substr((string)$a['content_hash'], 0, 16),
        'steps' => $o, 'turn_user_id' => $next ? (int)$next['user_id'] : null, 'my_turn' => $next && (int)$next['user_id'] === $viewer ? ['step_id' => (int)$next['id'], 'kind' => $next['kind'], 'next_is_sign' => (function () use ($steps, $next) { foreach ($steps as $s) if ((int)$s['position'] > (int)$next['position'] && $s['status'] === 'pending') return $s['kind'] === 'sign'; return false; })()] : null, 'events' => $ev];
}
/** Everything the document page needs about approvals (or null when the person has nothing to do with it). */
function ap_summary(array $u, array $d, bool $canEdit): array {
    $a = ap_current((int)$d['id']); $uid = (int)$u['id']; $mySig = one('SELECT 1 FROM user_signatures WHERE user_id=?', [$uid]);
    $locked = in_array($d['approval_status'] ?? '', ['in_review', 'awaiting_sign', 'signed'], true);
    $o = ['status' => $d['approval_status'] ?: null, 'locked' => $locked, 'can_request' => $canEdit && in_array($d['doc_type'], array_keys(AP_TYPES), true) && $d['status'] !== 'void' && !$locked && $d['doc_type'] !== 'receipt', 'has_signature' => (bool)$mySig, 'current' => $a ? ap_out($a, $uid) : null];
    if ($o['can_request']) { $o['eligible'] = array_values(array_filter(ap_eligible(), fn($p) => $p['id'] !== (int)($d['created_by'] ?? 0) || true)); $o['suggest'] = ap_suggest($u, $d); }
    $o['history'] = array_map(fn($h) => ['id' => (int)$h['id'], 'status' => $h['status'], 'created_at' => $h['created_at'], 'closed_at' => $h['closed_at']], all('SELECT id,status,created_at,closed_at FROM doc_approvals WHERE doc_id=? ORDER BY id DESC LIMIT 10', [$d['id']]));
    return $o;
}
/** Chain of command suggestion: the author's department head, then the Founder / CEO to sign. */
function ap_suggest(array $u, array $d): array {
    $uid = (int)$u['id']; $steps = []; $used = [$uid]; $el = array_column(ap_eligible(), null, 'id');
    foreach (array_unique(array_map('intval', $u['department_ids'] ?? [])) as $dep) { $h = (int)(one('SELECT head_id FROM departments WHERE id=?', [$dep])['head_id'] ?? 0); if ($h && isset($el[$h]) && !in_array($h, $used, true)) { $steps[] = ['kind' => 'review', 'user_id' => $h]; $used[] = $h; } }
    $fin = (int)(one("SELECT head_id FROM departments WHERE name='Finance' AND head_id IS NOT NULL LIMIT 1")['head_id'] ?? 0); if ($fin && isset($el[$fin]) && !in_array($fin, $used, true)) { $steps[] = ['kind' => 'review', 'user_id' => $fin]; $used[] = $fin; }
    $sg = (int)(one("SELECT id FROM users WHERE role='founder' AND active=1 AND archived_at IS NULL ORDER BY id LIMIT 1")['id'] ?? 0) ?: (int)(one("SELECT id FROM users WHERE role='super_admin' AND active=1 AND archived_at IS NULL AND id<>? ORDER BY id LIMIT 1", [$uid])['id'] ?? 0);
    if ($sg && !in_array($sg, $used, true)) $steps[] = ['kind' => 'sign', 'user_id' => $sg]; return $steps;
}
function ap_request(array $u, array $d, array $b): array {
    $uid = (int)$u['id']; if (!isset(AP_TYPES[$d['doc_type']])) fail('Receipts are not approved this way'); if ($d['status'] === 'void') fail('This document is void');
    $cur = ap_current((int)$d['id']); if ($cur && in_array($cur['status'], AP_ACTIVE, true)) fail('This document is already waiting for approval'); if ($cur && $cur['status'] === 'signed') fail('This document is already signed. Void it and issue a new one to change it.');
    $steps = []; $seen = []; $el = array_column(ap_eligible(), null, 'id'); $sawSign = false;
    foreach (array_slice((array)($b['steps'] ?? []), 0, 8) as $s) { $kind = ($s['kind'] ?? '') === 'sign' ? 'sign' : 'review'; $to = (int)($s['user_id'] ?? 0);
        if (!isset($el[$to])) fail('Choose people who can review and sign (administrators, the Founder, managers and heads of department)'); if ($to === $uid) fail('You cannot approve your own request. Choose someone else.'); if (isset($seen[$to])) fail($el[$to]['name'] . ' is in the chain twice');
        if ($kind === 'review' && $sawSign) fail('Reviews come first, then signatures'); if ($kind === 'sign') $sawSign = true; $seen[$to] = 1; $steps[] = [$kind, $to]; }
    if (!$sawSign) fail('Add at least one person to sign');
    $hash = ap_hash($d); $first = $steps[0][0] === 'sign' ? 'awaiting_sign' : 'in_review';
    q('INSERT INTO doc_approvals (doc_id,status,created_by,content_hash) VALUES (?,?,?,?)', [$d['id'], $first, $uid, $hash]); $aid = (int)db()->lastInsertId();
    foreach ($steps as $i => [$kind, $to]) q('INSERT INTO doc_approval_steps (approval_id,position,kind,user_id) VALUES (?,?,?,?)', [$aid, $i + 1, $kind, $to]);
    q('UPDATE fin_docs SET approval_status=?, approval_id=?, signed_at=NULL WHERE id=?', [$first, $aid, $d['id']]); ap_event($aid, $uid, 'requested', implode(' > ', array_map(fn($s) => ($s[0] === 'sign' ? 'sign: ' : 'review: ') . $el[$s[1]]['name'], $steps)));
    [$k0, $to0] = $steps[0]; ap_tell($to0, $u, $k0 === 'sign' ? 'approval_sign' : 'approval_review', ($k0 === 'sign' ? 'Signature requested' : 'Review requested') . ' by ' . $u['name'], (AP_TYPES[$d['doc_type']]) . ' ' . $d['number'] . ' · ' . $d['client_name'], $d, true, (string)($b['note'] ?? ''));
    log_act($uid, 'approval_requested', $d['number']); return ['ok' => true, 'approval' => ap_out(ap_current((int)$d['id']), $uid)];
}
function ap_act(array $u, array $d, array $b): array {
    $uid = (int)$u['id']; $a = ap_current((int)$d['id']); if (!$a || !in_array($a['status'], AP_ACTIVE, true)) fail('Nothing is waiting for approval on this document');
    $action = (string)($b['action'] ?? ''); $comment = s($b['comment'] ?? '', 1000); $steps = ap_steps((int)$a['id']); $cur = ap_next_pending($steps); $aid = (int)$a['id'];
    if ($action === 'cancel') {   // the person who asked (or Finance) withdraws the request
        if ((int)$a['created_by'] !== $uid && !can_finance($u) && !is_root($u)) fail('Only the person who asked for approval can withdraw it', 403);
        q("UPDATE doc_approvals SET status='canceled', closed_at=NOW() WHERE id=?", [$aid]); q('UPDATE fin_docs SET approval_status=NULL WHERE id=?', [$d['id']]); ap_event($aid, $uid, 'canceled', $comment);
        if ($cur && (int)$cur['user_id'] !== $uid) ap_tell((int)$cur['user_id'], $u, 'approval_canceled', $u['name'] . ' withdrew the approval request', $d['number'], $d); return ['ok' => true, 'status' => 'canceled']; }
    if (!$cur || (int)$cur['user_id'] !== $uid) fail('It is not your turn on this document', 403);
    if (!hash_equals((string)$a['content_hash'], ap_hash($d))) fail('This document changed after approval was requested. Revert it and ask for approval again.', 409);
    if ($action === 'revert') {
        if ($comment === '') fail('Say what needs to change (the reason is sent back to the author)');
        q("UPDATE doc_approval_steps SET status='reverted', acted_at=NOW(), comment=? WHERE id=?", [$comment, $cur['id']]); q("UPDATE doc_approvals SET status='reverted', closed_at=NOW() WHERE id=?", [$aid]); q("UPDATE fin_docs SET approval_status='reverted' WHERE id=?", [$d['id']]); ap_event($aid, $uid, 'reverted', $comment);
        $who = array_unique(array_merge([(int)$a['created_by']], array_map(fn($s) => (int)$s['user_id'], array_filter($steps, fn($s) => $s['status'] === 'approved' || $s['status'] === 'signed'))));
        foreach ($who as $w) if ($w !== $uid) ap_tell($w, $u, 'approval_reverted', $u['name'] . ' reverted ' . $d['number'], mb_substr($comment, 0, 140), $d, $w === (int)$a['created_by'], $comment);
        log_act($uid, 'approval_reverted', $d['number']); return ['ok' => true, 'status' => 'reverted']; }
    $rest = array_values(array_filter($steps, fn($s) => $s['status'] === 'pending' && (int)$s['id'] !== (int)$cur['id']));
    if ($cur['kind'] === 'review') {
        if ($action !== 'approve') fail('Approve the review, or revert it');
        q("UPDATE doc_approval_steps SET status='approved', acted_at=NOW(), comment=? WHERE id=?", [$comment !== '' ? $comment : null, $cur['id']]); ap_event($aid, $uid, 'review_approved', $comment); $nx = $rest[0] ?? null;
        if ($nx && $nx['kind'] === 'sign') { q("UPDATE doc_approvals SET status='awaiting_sign' WHERE id=?", [$aid]); q("UPDATE fin_docs SET approval_status='awaiting_sign' WHERE id=?", [$d['id']]); ap_event($aid, $uid, 'signature_requested', ''); }
        if ($nx) ap_tell((int)$nx['user_id'], $u, $nx['kind'] === 'sign' ? 'approval_sign' : 'approval_review', ($nx['kind'] === 'sign' ? 'Signature requested' : 'Review requested') . ' (reviewed by ' . $u['name'] . ')', (AP_TYPES[$d['doc_type']]) . ' ' . $d['number'] . ' · ' . $d['client_name'], $d, true, $comment);
        if ((int)$a['created_by'] !== $uid) ap_tell((int)$a['created_by'], $u, 'approval_progress', $u['name'] . ' approved the review of ' . $d['number'], $nx ? ($nx['kind'] === 'sign' ? 'Now waiting for a signature' : 'Next: review') : '', $d);
        log_act($uid, 'approval_reviewed', $d['number']); return ['ok' => true, 'status' => $nx && $nx['kind'] === 'sign' ? 'awaiting_sign' : 'in_review']; }
    // signing
    if ($action !== 'sign') fail('Sign the document, or revert it');
    $sg = (array)($b['signature'] ?? []); $mode = ($sg['mode'] ?? 'saved') === 'new' ? 'new' : 'saved'; $img = null;
    if ($mode === 'saved') { $r = one('SELECT image FROM user_signatures WHERE user_id=?', [$uid]); if (!$r) fail('Draw or type your signature first'); $img = $r['image']; }
    else { $img = ap_png($sg['image'] ?? ''); if (!empty($sg['save'])) q('INSERT INTO user_signatures (user_id,image) VALUES (?,?) ON DUPLICATE KEY UPDATE image=VALUES(image), updated_at=NOW()', [$uid, $img]); }
    $now = date('Y-m-d H:i:s'); $fp = hash('sha256', $a['content_hash'] . '|' . $uid . '|' . $now);
    q("UPDATE doc_approval_steps SET status='signed', acted_at=?, comment=?, signature=?, sig_hash=? WHERE id=?", [$now, $comment !== '' ? $comment : null, $img, $fp, $cur['id']]); ap_event($aid, $uid, 'signed', $comment);
    if (!$rest) {
        q("UPDATE doc_approvals SET status='signed', closed_at=NOW() WHERE id=?", [$aid]); q("UPDATE fin_docs SET approval_status='signed', signed_at=NOW() WHERE id=?", [$d['id']]);
        $all = array_unique(array_merge([(int)$a['created_by']], array_map(fn($s) => (int)$s['user_id'], $steps))); foreach ($all as $w) if ($w !== $uid) ap_tell($w, $u, 'approval_signed', $d['number'] . ' is signed', 'Signed by ' . $u['name'] . '. The approval is complete.', $d, $w === (int)$a['created_by']);
        log_act($uid, 'document_signed', $d['number']); return ['ok' => true, 'status' => 'signed']; }
    $nx = $rest[0]; ap_tell((int)$nx['user_id'], $u, 'approval_sign', 'Signature requested (signed so far by ' . $u['name'] . ')', (AP_TYPES[$d['doc_type']]) . ' ' . $d['number'] . ' · ' . $d['client_name'], $d, true, $comment);
    log_act($uid, 'document_signed', $d['number']); return ['ok' => true, 'status' => 'awaiting_sign'];
}
/** For me(): how many documents wait for this person. */
function ap_pending_count(int $uid): int {
    try { return (int)one("SELECT COUNT(*) c FROM doc_approval_steps s JOIN doc_approvals a ON a.id=s.approval_id WHERE s.user_id=? AND s.status='pending' AND a.status IN ('in_review','awaiting_sign') AND s.position=(SELECT MIN(s2.position) FROM doc_approval_steps s2 WHERE s2.approval_id=a.id AND s2.status='pending')", [$uid])['c']; } catch (Throwable $e) { return 0; }
}
function ap_is_in_chain(int $uid, int $docId): bool { try { return (bool)one('SELECT 1 FROM doc_approval_steps s JOIN doc_approvals a ON a.id=s.approval_id WHERE a.doc_id=? AND s.user_id=? LIMIT 1', [$docId, $uid]); } catch (Throwable $e) { return false; } }
/** finance/approvals and finance/signature */
function ap_global(array $u, string $what, string $method, array $b): void {
    $uid = (int)$u['id'];
    if ($what === 'signature') {
        if ($method === 'GET') { $r = one('SELECT image,updated_at FROM user_signatures WHERE user_id=?', [$uid]); out(['has' => (bool)$r, 'image' => $r['image'] ?? null, 'updated_at' => $r['updated_at'] ?? null]); }
        if ($method === 'PUT' || $method === 'POST') { $img = ap_png($b['image'] ?? ''); q('INSERT INTO user_signatures (user_id,image) VALUES (?,?) ON DUPLICATE KEY UPDATE image=VALUES(image), updated_at=NOW()', [$uid, $img]); out(['ok' => true]); }
        if ($method === 'DELETE') { q('DELETE FROM user_signatures WHERE user_id=?', [$uid]); out(['ok' => true]); }
        fail('Bad request', 404);
    }
    if ($method !== 'GET') fail('Bad request', 404);
    $wait = all("SELECT s.kind, a.id approval_id, a.created_at requested_at, d.id doc_id, d.doc_type, d.number, d.client_name, d.currency, d.currency_symbol, ru.name requester
                 FROM doc_approval_steps s JOIN doc_approvals a ON a.id=s.approval_id JOIN fin_docs d ON d.id=a.doc_id JOIN users ru ON ru.id=a.created_by
                 WHERE s.user_id=? AND s.status='pending' AND a.status IN ('in_review','awaiting_sign') AND s.position=(SELECT MIN(s2.position) FROM doc_approval_steps s2 WHERE s2.approval_id=a.id AND s2.status='pending') ORDER BY a.created_at", [$uid]);
    $mine = all("SELECT a.id approval_id, a.status, a.created_at, a.closed_at, d.id doc_id, d.doc_type, d.number, d.client_name,
                 (SELECT u.name FROM doc_approval_steps s JOIN users u ON u.id=s.user_id WHERE s.approval_id=a.id AND s.status='pending' ORDER BY s.position LIMIT 1) waiting_for
                 FROM doc_approvals a JOIN fin_docs d ON d.id=a.doc_id WHERE a.created_by=? ORDER BY a.id DESC LIMIT 30", [$uid]);
    $done = all("SELECT s.kind, s.status, s.acted_at, d.id doc_id, d.doc_type, d.number, d.client_name FROM doc_approval_steps s JOIN doc_approvals a ON a.id=s.approval_id JOIN fin_docs d ON d.id=a.doc_id WHERE s.user_id=? AND s.status IN ('approved','signed','reverted') ORDER BY s.acted_at DESC LIMIT 20", [$uid]);
    out(['waiting' => $wait, 'mine' => $mine, 'done' => $done]);
}
