<?php
declare(strict_types=1);
// Inbox automation (Campaigns > Inbox automation). Reads the info@ mailbox and, for each new email:
//   1. skips automatic mail (no-reply, newsletters, out-of-office, internal),
//   2. sorts it (quote request, service enquiry, meeting, invoice/payment, support, general) with editable keyword rules,
//   3. saves the sender as a contact (and company) in the Pipeline, and moves business enquiries forward in the funnel,
//   4. creates action tasks in the task tracker (send proposal, profile, pitch deck, proforma, invoice ...),
//   5. writes a draft reply and gives Marketing a task to approve it (optionally also sends an instant "we got it" acknowledgement).
// Replies go out from the mailbox itself, with documents attached from Documents / My Documents.
//   GET  /api/inbox-auto/overview[?status=]      settings + counts + items       POST /api/inbox-auto/settings
//   POST /api/inbox-auto/scan {days,limit}       read the mailbox now            GET  /api/inbox-auto/library   files you can attach
//   POST /api/inbox-auto/items/{id}/send|close|redraft|category
require_once __DIR__ . '/mail.php'; require_once __DIR__ . '/accounts.php'; require_once __DIR__ . '/campcore.php'; require_once __DIR__ . '/vendorcrm.php';

const IA_STAGES = ['researching', 'contact_identified', 'outreach_sent', 'in_discussion', 'proposal_sent', 'negotiating', 'won', 'on_hold'];
const IA_FREE = ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'outlook.com', 'hotmail.com', 'live.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com', 'yandex.com', 'gmx.com', 'mail.com', 'zoho.com'];

function ia_reply(string $closing): string { return "Dear {name},\n\n" . $closing . "\n\nKind regards,\n{sender}\nAerosub Solutions Limited"; }
function ia_default_rules(): array {
    return [
        ['key' => 'quote_request', 'label' => 'Quote / proposal request', 'keywords' => ['quote', 'quotation', 'price', 'pricing', 'proforma', 'pro-forma', 'rfq', 'request for quotation', 'tender', 'bid', 'how much', 'cost of', 'budget', 'proposal'],
         'weight' => 2, 'business' => 1, 'stage' => 'in_discussion', 'priority' => 'high', 'tasks' => ['Send proposal', 'Prepare and send proforma invoice'], 'attach' => ['profile', 'proposal'],
         'reply' => ia_reply("Thank you for contacting Aerosub Solutions. We have received your request and our team is preparing a proposal for you. We will come back to you shortly.\n\nIf there is anything that will help us tailor it (scope, location, timing), please reply to this email.")],
        ['key' => 'service_enquiry', 'label' => 'Service enquiry', 'keywords' => ['rov', 'inspection', 'survey', 'subsea', 'underwater', 'services', 'interested', 'capabilit', 'demo', 'presentation', 'brochure', 'profile', 'collaborat', 'partnership', 'work with', 'hire', 'deploy'],
         'business' => 1, 'stage' => 'contact_identified', 'priority' => 'medium', 'tasks' => ['Send company profile', 'Send pitch deck'], 'attach' => ['profile', 'pitch', 'deck'],
         'reply' => ia_reply("Thank you for your interest in Aerosub Solutions. We have attached our company profile so you can see what we do, and we would be glad to walk you through how we can support you.\n\nWhen would be a good time for a short call?")],
        ['key' => 'meeting', 'label' => 'Meeting / call request', 'keywords' => ['meeting', 'call', 'schedule', 'appointment', 'availability', 'available', 'visit', 'catch up', 'zoom', 'teams'],
         'business' => 1, 'stage' => 'in_discussion', 'priority' => 'medium', 'tasks' => ['Schedule follow-up meeting'], 'attach' => [],
         'reply' => ia_reply("Thank you for reaching out. We would be happy to meet. We will confirm a time with you shortly. If you have preferred dates, please let us know.")],
        ['key' => 'invoice_payment', 'label' => 'Invoice / payment', 'keywords' => ['invoice', 'payment', 'receipt', 'remittance', 'paid', 'outstanding', 'statement', 'transfer', 'overdue'],
         'weight' => 1.5, 'business' => 0, 'stage' => '', 'priority' => 'high', 'tasks' => ['Check invoice / payment status with Finance'], 'attach' => [],
         'reply' => ia_reply("Thank you for your message about the invoice. We are checking with our Finance team and will confirm shortly.")],
        ['key' => 'support', 'label' => 'Support / complaint', 'keywords' => ['issue', 'problem', 'fault', 'support', 'repair', 'not working', 'complain', 'damage', 'urgent', 'help'],
         'business' => 0, 'stage' => '', 'priority' => 'high', 'tasks' => ['Respond to support request'], 'attach' => [],
         'reply' => ia_reply("Thank you for letting us know. We are looking into this and will get back to you as soon as possible.")],
        ['key' => 'supplier', 'label' => 'Supplier / vendor offer', 'keywords' => ['price list', 'catalogue', 'catalog', 'datasheet', 'data sheet', 'our quotation', 'our offer', 'your purchase order', 'order acknowledg', 'we supply', 'authorized distributor', 'authorised distributor', 'manufacturer', 'lead time', 'in stock', 'stock available', 'supply of', 'we manufacture', 'wholesale'],
         'weight' => 1.5, 'business' => 0, 'stage' => '', 'priority' => 'medium', 'tasks' => ['Review supplier offer'], 'attach' => [],
         'reply' => ia_reply("Thank you for your message and your offer. We have passed it to our procurement team and will come back to you if we need more information.")],
        ['key' => 'general', 'label' => 'General', 'keywords' => [], 'business' => 0, 'stage' => '', 'priority' => 'low', 'tasks' => [], 'attach' => [],
         'reply' => ia_reply("Thank you for your email. We have received it and a member of our team will respond shortly.")],
    ];
}
function ia_settings(): array {
    $r = one('SELECT * FROM ia_settings WHERE id=1'); if (!$r) { q("INSERT IGNORE INTO ia_settings (id) VALUES (1)"); $r = one('SELECT * FROM ia_settings WHERE id=1'); }
    $rules = json_decode((string)($r['rules'] ?? ''), true); if (!is_array($rules) || !$rules) $rules = ia_default_rules();
    else { $have = array_column($rules, 'key'); foreach (ia_default_rules() as $dr) if ($dr['key'] === 'supplier' && !in_array('supplier', $have, true)) array_splice($rules, max(0, count($rules) - 1), 0, [$dr]); }   // older saved rule sets gain the supplier rule just before 'general'
    $r['rules'] = $rules; $r['skip'] = array_values(array_filter(array_map('trim', explode("\n", (string)($r['skip_list'] ?? '')))));
    if ((string)$r['ack_body'] === '') $r['ack_body'] = "Dear {name},\n\nThank you for contacting Aerosub Solutions. This is to confirm that we have received your email. A member of our team will respond to you shortly.\n\nKind regards,\nAerosub Solutions Limited";
    if ((string)$r['ack_subject'] === '') $r['ack_subject'] = 'We received your email';
    return $r;
}
/** The review queue needs a 'discarded' status. */
function ia_ensure(): void { static $d = false; if ($d) return; $d = true; try { $c = one("SHOW COLUMNS FROM ia_items LIKE 'status'"); if ($c && !str_contains((string)$c['Type'], 'discarded')) db()->exec("ALTER TABLE ia_items MODIFY status ENUM('new','awaiting','sent','closed','skipped','discarded') NOT NULL DEFAULT 'new'"); } catch (Throwable $e) { error_log('Inbox status: ' . $e->getMessage()); } }
function ia_str($v, int $n): string { return mb_substr(trim((string)($v ?? '')), 0, $n); }
function ia_fill(string $t, array $v): string { return str_replace(['{name}', '{sender}', '{company}', '{subject}'], [$v['name'] ?? '', $v['sender'] ?? '', $v['company'] ?? '', $v['subject'] ?? ''], $t); }

/** Automatic mail must never be answered (it creates loops) and must not clutter the funnel. */
function ia_skip_reason(array $hdr, string $fromEmail, string $subject, string $mailbox, array $skipList): string {
    $h = fn($k) => strtolower(trim((string)($hdr[$k] ?? '')));
    if ($h('auto-submitted') !== '' && $h('auto-submitted') !== 'no') return 'Automatic message';
    if (preg_match('/bulk|junk|list/', $h('precedence')) || $h('list-id') !== '' || $h('list-unsubscribe') !== '' || $h('x-auto-response-suppress') !== '') return 'Newsletter or mailing list';
    $local = strtolower(explode('@', $fromEmail)[0] ?? '');
    if (preg_match('/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster|bounce|bounces|notification|notifications|newsletter|alerts?|system)$/', $local)) return 'No-reply sender';
    if (preg_match('/^(automatic reply|auto(matic)?:|out of office|undeliverable|delivery status|returned mail|mail delivery)/i', $subject)) return 'Automatic reply';
    $dom = strtolower(substr(strrchr($fromEmail, '@') ?: '', 1)); $mdom = strtolower(substr(strrchr($mailbox, '@') ?: '', 1));
    if ($fromEmail === strtolower($mailbox) || ($mdom !== '' && $dom === $mdom)) return 'From our own team';
    foreach ($skipList as $s) { $s = strtolower($s); if ($s !== '' && ($fromEmail === $s || $dom === ltrim($s, '@'))) return 'On your ignore list'; }
    return '';
}
function ia_classify(string $subject, string $body, array $rules): string {
    $best = 'general'; $bestScore = 0; $subj = mb_strtolower($subject); $txt = mb_strtolower(mb_substr($body, 0, 2500));
    foreach ($rules as $r) { $score = 0; foreach ((array)($r['keywords'] ?? []) as $k) { $k = mb_strtolower(trim((string)$k)); if ($k === '') continue; $re = '/(?<![\p{L}\p{N}])' . preg_quote($k, '/') . '/u';   // a word (or the start of one), so "rov" does not match "improve"
            if (preg_match($re, $subj)) $score += 3; if (preg_match($re, $txt)) $score += 1; }
        $score *= max(0.5, min(5.0, (float)($r['weight'] ?? 1))); if ($score > $bestScore) { $bestScore = $score; $best = (string)$r['key']; } }
    return $best;
}
function ia_company_for(string $email, string $name, bool $create): ?array {
    $dom = strtolower(substr(strrchr($email, '@') ?: '', 1)); $free = in_array($dom, IA_FREE, true);
    if (!$free && $dom !== '') { $c = one('SELECT id,name,stage,owner_id FROM companies WHERE website LIKE ? ORDER BY id LIMIT 1', ['%' . $dom . '%']);
        $label = ucwords(str_replace(['-', '_'], ' ', explode('.', $dom)[0])); if (!$c) $c = one('SELECT id,name,stage,owner_id FROM companies WHERE LOWER(name)=? ORDER BY id LIMIT 1', [strtolower($label)]);
        if ($c) return $c; if (!$create) return null;
        q("INSERT INTO companies (name,website,stage,priority,notes) VALUES (?,?,'contact_identified','medium','Added from an email enquiry')", [$label, $dom]); return one('SELECT id,name,stage,owner_id FROM companies WHERE id=?', [(int)db()->lastInsertId()]); }
    if (!$create) return null; $nm = ($name !== '' ? $name : explode('@', $email)[0]) . ' (individual)';
    $c = one('SELECT id,name,stage,owner_id FROM companies WHERE name=? LIMIT 1', [$nm]); if ($c) return $c;
    q("INSERT INTO companies (name,stage,priority,notes) VALUES (?,'contact_identified','medium','Added from an email enquiry')", [$nm]); return one('SELECT id,name,stage,owner_id FROM companies WHERE id=?', [(int)db()->lastInsertId()]);
}
function ia_contact_for(string $email, string $name, ?int $companyId, string $note): array {
    $c = one('SELECT id,name,company_id FROM contacts WHERE LOWER(email)=? LIMIT 1', [strtolower($email)]);
    if ($c) { if ($companyId && !$c['company_id']) q('UPDATE contacts SET company_id=? WHERE id=?', [$companyId, $c['id']]); return $c; }
    $nm = $name !== '' ? $name : ucwords(str_replace(['.', '_', '-'], ' ', explode('@', $email)[0]));
    q('INSERT INTO contacts (company_id,name,email,notes) VALUES (?,?,?,?)', [$companyId, ia_str($nm, 160), ia_str($email, 190), ia_str($note, 1000)]); return one('SELECT id,name,company_id FROM contacts WHERE id=?', [(int)db()->lastInsertId()]);
}
function ia_marketing_head(): array { $d = one("SELECT id,head_id FROM departments WHERE name='Marketing' LIMIT 1"); return ['dept' => $d ? (int)$d['id'] : null, 'head' => $d && $d['head_id'] ? (int)$d['head_id'] : null]; }

/** Everything that follows one new email. Returns the item id. */
function ia_handle(array $acc, array $actor, array $st, array $m): int {
    ia_ensure();
    $rules = $st['rules']; $key = sha1($acc['id'] . '|' . ($m['message_id'] ?: ($m['folder'] . $m['uid'])));
    if (one('SELECT id FROM ia_items WHERE msg_key=?', [$key])) return 0;
    $from = strtolower($m['from_email']); $snippet = ia_str(preg_replace('/\s+/', ' ', $m['text']), 600);
    $skip = ia_skip_reason($m['hdr'], $from, $m['subject'], (string)$acc['email'], $st['skip']);
    q('INSERT INTO ia_items (account_id,msg_key,folder,uid,message_id,references_h,from_email,from_name,subject,snippet,received_at,category,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [$acc['id'], $key, $m['folder'], $m['uid'], ia_str($m['message_id'], 250), ia_str($m['references'], 1000), ia_str($from, 190), ia_str($m['from_name'], 190), ia_str($m['subject'], 300), $snippet, $m['date'] ?: date('Y-m-d H:i:s'), $skip !== '' ? 'skipped' : 'general', $skip !== '' ? 'skipped' : 'new', (int)$actor['id']]);
    $id = (int)db()->lastInsertId(); if ($skip !== '') { q('UPDATE ia_items SET skip_reason=? WHERE id=?', [$skip, $id]); return $id; }
    $cat = ia_classify($m['subject'], $m['text'], $rules); if (vc_vendor_for($from)) $cat = 'supplier';   // someone we already buy from is never a sales lead
    ia_apply($id, $acc, $actor, $st, $cat, true); return $id;
}
/** (Re)apply a category: contact/company, funnel stage, action tasks, draft reply and the approval task. */
function ia_apply(int $id, array $acc, array $actor, array $st, string $cat, bool $first): void {
    $it = one('SELECT * FROM ia_items WHERE id=?', [$id]); if (!$it) return; $rule = null; foreach ($st['rules'] as $r) if ($r['key'] === $cat) $rule = $r; if (!$rule) { $cat = 'general'; foreach ($st['rules'] as $r) if ($r['key'] === 'general') $rule = $r; } if (!$rule) return;
    $biz = !empty($rule['business']); $vr = vc_vendor_for($it['from_email']);
    if ($vr || $cat === 'supplier') {   // a supplier: it goes to Vendors (and its person to the vendor), never to Companies
        $fdom = vc_domain($it['from_email']); $vname = ($fdom !== '' && !in_array($fdom, VC_FREE, true)) ? '' : ($it['from_name'] !== '' ? $it['from_name'] : explode('@', $it['from_email'])[0]);
        if (!$vr) [$vr] = vc_make($vname, $it['from_email'], 'email', (int)$actor['id'], ['notes' => 'Added from an email: ' . ia_str($it['subject'], 200)]);
        $co = null; $biz = false; $ct = vc_contact((int)$vr['id'], $it['from_email'], $it['from_name'], 'Emailed: ' . $it['subject']); if ($cat !== 'supplier') { $cat = 'supplier'; foreach ($st['rules'] as $r2) if ($r2['key'] === 'supplier') $rule = $r2; }
    } else { $co = ia_company_for($it['from_email'], $it['from_name'], $biz); $ct = ia_contact_for($it['from_email'], $it['from_name'], $co ? (int)$co['id'] : null, 'Emailed: ' . $it['subject']); }
    if ($co && $biz && ($rule['stage'] ?? '') !== '') { $cur = array_search($co['stage'], IA_STAGES, true); $new = array_search($rule['stage'], IA_STAGES, true);
        if ($cur !== false && $new !== false && $new > $cur && !in_array($co['stage'], ['won', 'on_hold'], true)) q('UPDATE companies SET stage=? WHERE id=?', [$rule['stage'], $co['id']]); }
    $vars = ['name' => explode(' ', trim($it['from_name']) ?: ucfirst(explode('@', $it['from_email'])[0]))[0], 'sender' => ia_str($acc['from_name'] ?: 'The Aerosub team', 80), 'company' => $co['name'] ?? '', 'subject' => $it['subject']];
    $draft = ia_fill((string)$rule['reply'], $vars); $subj = preg_match('/^re:/i', $it['subject']) ? $it['subject'] : 'Re: ' . $it['subject'];
    q("UPDATE ia_items SET category=?, status='awaiting', contact_id=?, company_id=?, draft_subject=?, draft_body=? WHERE id=?", [$cat, $ct['id'], $co['id'] ?? null, ia_str($subj, 300), $draft, $id]);   // sorted and drafted only: no task until someone reviews it and adds it
    if (!$first && (int)($it['task_id'] ?? 0)) ia_make_tasks($id, $actor, $st, true);   // it was already added as tasks: they follow the new category
    if ($first && !empty($st['auto_ack']) && $biz && !one("SELECT id FROM ia_items WHERE from_email=? AND acked=1 AND created_at > (NOW() - INTERVAL 1 DAY) LIMIT 1", [$it['from_email']])) {
        try { ia_send($acc, $it['from_email'], ia_fill($st['ack_subject'], $vars), ia_fill($st['ack_body'], $vars), [], (string)$it['message_id'], (string)$it['references_h'], false); q('UPDATE ia_items SET acked=1 WHERE id=?', [$id]); } catch (Throwable $e) { error_log('Inbox automation acknowledgement: ' . $e->getMessage()); } }
}
/** Review step: "Add as task". Creates the action tasks (and the "approve the reply" task) for the person who adds them, nobody else. They can share the tasks afterwards. */
function ia_make_tasks(int $id, array $actor, array $st, bool $refresh = false): array {
    $it = one('SELECT * FROM ia_items WHERE id=?', [$id]); if (!$it) return []; $rule = null; foreach ($st['rules'] as $r) if ($r['key'] === $it['category']) $rule = $r; if (!$rule) foreach ($st['rules'] as $r) if ($r['key'] === 'general') $rule = $r; if (!$rule) return [];
    if (!$refresh && (int)($it['task_id'] ?? 0)) return json_decode((string)$it['tasks_json'], true) ?: [];   // already added
    $me = (int)$actor['id']; $ids = $refresh ? [] : []; $old = json_decode((string)$it['tasks_json'], true) ?: [];
    if ($refresh) foreach ($old as $tid) q("UPDATE tasks SET status='canceled' WHERE id=? AND status='todo' AND source='inbox'", [(int)$tid]);
    $mk = ia_marketing_head(); $due = date('Y-m-d', strtotime('+2 days')); $co = $it['company_id'] ?? null; $ct = $it['contact_id'] ?? null;
    $who = $it['from_name'] !== '' ? $it['from_name'] : $it['from_email']; $desc = "From: $who <{$it['from_email']}>\nSubject: {$it['subject']}\n\n" . ia_str($it['snippet'], 500) . "\n\nOpen Campaigns > Inbox automation to see the email and its draft reply.";
    foreach ((array)($rule['tasks'] ?? []) as $tt) { $tt = ia_str($tt, 140); if ($tt === '') continue;
        q("INSERT INTO tasks (title,description,status,priority,assignee_id,creator_id,department_id,due_date,company_id,contact_id,task_type,source) VALUES (?,?,'todo',?,?,?,?,?,?,?,'general','inbox')",
          [ia_str($tt . ' — ' . $who, 200), $desc, in($rule['priority'] ?? 'medium', ['low', 'medium', 'high', 'urgent'], 'medium'), $me, $me, $mk['dept'], $due, $co, $ct]); $ids[] = (int)db()->lastInsertId(); }
    $apv = (int)($it['task_id'] ?? 0);
    if (!$apv || !one('SELECT id FROM tasks WHERE id=?', [$apv])) { q("INSERT INTO tasks (title,description,status,priority,assignee_id,creator_id,department_id,due_date,company_id,contact_id,task_type,source) VALUES (?,?,'todo','medium',?,?,?,?,?,?,'general','inbox')",
        [ia_str('Approve reply to ' . $who . ': ' . $it['subject'], 200), "Review the draft reply, add documents (profile, pitch deck...) and send it, or close it out.\n\n" . $desc, $me, $me, $mk['dept'], date('Y-m-d', strtotime('+1 day')), $co, $ct]); $apv = (int)db()->lastInsertId(); }
    q('UPDATE ia_items SET task_id=?, tasks_json=?, handled_by=COALESCE(handled_by,?) WHERE id=?', [$apv, json_encode($ids), $me, $id]); return $ids;
}
function ia_imap(array $acc): Imap { $im = new Imap($acc['imap_host'], (int)$acc['imap_port'], !cfg('mail_insecure_tls')); $im->login($acc['email'], mail_decrypt($acc['secret_enc'])); return $im; }
/** Send from the mailbox itself (so replies come from info@ and a copy lands in its Sent folder). */
function ia_send(array $acc, string $to, string $subject, string $text, array $atts, string $inReplyTo, string $refs, bool $keepCopy = true): void {
    $verify = !cfg('mail_insecure_tls'); $domain = substr(strrchr((string)$acc['email'], '@') ?: '', 1) ?: 'aerosub.co';
    $sig = trim((string)($acc['sig_html'] ?? '')); if ($sig === '' && trim((string)($acc['signature'] ?? '')) !== '') $sig = nl2br(htmlspecialchars((string)$acc['signature'], ENT_QUOTES, 'UTF-8'));
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5">' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . ($sig !== '' ? '<br><br>' . $sig : '') . '</div>';
    $raw = build_message(['from' => ['name' => (string)($acc['from_name'] ?: 'Aerosub Solutions'), 'email' => $acc['email']], 'to' => [['name' => '', 'email' => $to]], 'cc' => [], 'subject' => $subject, 'html' => $html, 'text' => $text, 'attachments' => $atts,
        'in_reply_to' => $inReplyTo, 'references' => $refs, 'domain' => $domain, 'extra_headers' => ['Auto-Submitted: no']]);
    $sm = new Smtp($acc['smtp_host'], (int)$acc['smtp_port'], $verify); $sm->auth($acc['email'], mail_decrypt($acc['secret_enc'])); $sm->deliver($acc['email'], [$to], $raw);
    if ($keepCopy) { try { $im = ia_imap($acc); foreach ($im->folders() as $f) if ($f['role'] === 'sent') { $im->append($f['name'], $raw, '\\Seen'); break; } } catch (Throwable $e) {} }
}
function ia_scan(array $acc, array $actor, int $days, int $limit): array {
    $st = ia_settings(); $im = ia_imap($acc); $im->select('INBOX'); $since = date('d-M-Y', strtotime('-' . max(1, min(60, $days)) . ' days'));
    $uids = $im->search('SINCE ' . $since); $uids = array_slice($uids, -max(1, min(150, $limit))); $new = 0; $skipped = 0; $seen = 0;
    foreach (array_reverse($uids) as $uid) {
        try { $raw = $im->fetchRaw((int)$uid, 600000); $x = mime_extract($raw); $h = $x['headers']; $fr = mime_addr_list($h['from'] ?? ''); if (!$fr) continue; $mid = trim((string)($h['message-id'] ?? ''));
            $key = sha1($acc['id'] . '|' . ($mid ?: 'INBOX' . $uid)); if (one('SELECT id FROM ia_items WHERE msg_key=?', [$key])) { $seen++; continue; }
            $id = ia_handle($acc, $actor, $st, ['folder' => 'INBOX', 'uid' => (int)$uid, 'message_id' => $mid, 'references' => trim((string)($h['references'] ?? '')), 'from_email' => (string)$fr[0]['email'], 'from_name' => (string)($fr[0]['name'] ?? ''),
                'subject' => mime_decode_header($h['subject'] ?? ''), 'date' => mime_date($h['date'] ?? ''), 'text' => (string)$x['text'], 'hdr' => $h]);
            if ($id) { $row = one('SELECT status FROM ia_items WHERE id=?', [$id]); if ($row && $row['status'] === 'skipped') $skipped++; else $new++; }
        } catch (Throwable $e) { error_log('Inbox automation scan: ' . $e->getMessage()); }
    }
    q('UPDATE ia_settings SET last_scan_at=NOW(), mailbox_id=? WHERE id=1', [$acc['id']]);
    return ['new' => $new, 'skipped' => $skipped, 'already_seen' => $seen, 'looked_at' => count($uids)];
}
/** For cron.php: scan the connected info@ mailbox on a schedule. */
function ia_cron(): string {
    try { $st = ia_settings(); if (empty($st['enabled']) || empty($st['mailbox_id'])) return 'Inbox automation: off'; $acc = one('SELECT * FROM mail_accounts WHERE id=?', [$st['mailbox_id']]); if (!$acc) return 'Inbox automation: mailbox missing';
        $actor = one('SELECT id,name,email,role FROM users WHERE id=?', [$acc['user_id']]); if (!$actor) return 'Inbox automation: owner missing'; return 'Inbox automation: ' . json_encode(ia_scan($acc, $actor, 3, 60)); }
    catch (Throwable $e) { return 'Inbox automation skipped: ' . $e->getMessage(); }
}

/* ---------- the files a reply can carry: company Documents and My Documents ---------- */
function ia_library(array $u): array {
    $out = []; $uid = (int)$u['id']; $mgr = $u['role'] === 'super_admin' || !empty($u['is_root']);
    try { foreach (all('SELECT id,original_name,mime,size_bytes FROM documents WHERE ' . ($mgr ? '1=1' : 'uploaded_by=' . $uid) . ' ORDER BY id DESC LIMIT 100') as $d) $out[] = ['ref' => 'doc:' . $d['id'], 'name' => $d['original_name'], 'size' => (int)$d['size_bytes'], 'source' => 'Documents']; } catch (Throwable $e) {}
    try { if (tab_access($u, 'myfiles')) foreach (all("SELECT id,name,title,size FROM vault_items WHERE user_id=? AND kind='file' ORDER BY id DESC LIMIT 100", [$uid]) as $d) $out[] = ['ref' => 'my:' . $d['id'], 'name' => $d['name'] ?: $d['title'], 'size' => (int)$d['size'], 'source' => 'My Documents']; } catch (Throwable $e) {}
    return $out;
}
function ia_file(array $u, string $ref): ?array {
    $uid = (int)$u['id']; $mgr = $u['role'] === 'super_admin' || !empty($u['is_root']);
    if (preg_match('/^doc:(\d+)$/', $ref, $m)) { $d = one('SELECT original_name,mime,storage_path,uploaded_by FROM documents WHERE id=?', [(int)$m[1]]); if (!$d || (!$mgr && (int)$d['uploaded_by'] !== $uid)) return null;
        require_once __DIR__ . '/agent_token.php'; $dir = realpath(agent_doc_dir()); $p = $dir ? realpath($dir . '/' . $d['storage_path']) : false; if (!$p || !str_starts_with($p, $dir) || !is_file($p) || filesize($p) > 8 * 1048576) return null;
        return ['name' => $d['original_name'], 'type' => $d['mime'] ?: 'application/octet-stream', 'data' => (string)file_get_contents($p)]; }
    if (preg_match('/^my:(\d+)$/', $ref, $m) && tab_access($u, 'myfiles')) { $d = one("SELECT name,title,mime,path FROM vault_items WHERE id=? AND user_id=? AND kind='file'", [(int)$m[1], $uid]); if (!$d) return null;
        $p = (string)(cfg('storage_dir') ?: dirname(__DIR__) . '/storage') . '/vault/' . preg_replace('/[^a-f0-9]/', '', (string)$d['path']); if (!is_file($p) || filesize($p) > 8 * 1048576) return null;
        return ['name' => $d['name'] ?: $d['title'], 'type' => $d['mime'] ?: 'application/octet-stream', 'data' => (string)file_get_contents($p)]; }
    return null;
}
function ia_item_out(array $r): array {
    return ['id' => (int)$r['id'], 'from_email' => $r['from_email'], 'from_name' => $r['from_name'], 'subject' => $r['subject'], 'snippet' => $r['snippet'], 'received_at' => $r['received_at'], 'category' => $r['category'], 'status' => $r['status'], 'skip_reason' => $r['skip_reason'] ?? null,
        'contact_id' => $r['contact_id'] ? (int)$r['contact_id'] : null, 'company_id' => $r['company_id'] ? (int)$r['company_id'] : null, 'company' => $r['company_name'] ?? null, 'stage' => $r['company_stage'] ?? null, 'task_id' => $r['task_id'] ? (int)$r['task_id'] : null,
        'tasks' => $r['task_list'] ?? [], 'draft_subject' => $r['draft_subject'], 'draft_body' => $r['draft_body'], 'acked' => (int)$r['acked'], 'sent_at' => $r['sent_at'], 'handled_by' => $r['handler'] ?? null];
}

/* ---------- routes ---------- */
if (isset($seg) && ($seg[0] ?? '') === 'inbox-auto') {
    $u = need_user(); if (!can_campaigns($u)) fail('Inbox automation is for the Marketing team', 403); $uid = (int)$u['id']; $what = $seg[1] ?? ''; $b = $method === 'GET' ? [] : body();
    if ($method !== 'GET' && !empty($u['role']) && $u['role'] === 'founder') fail('Founder accounts are read-only', 403);
    ia_ensure();
    if ($method === 'GET' && $what === 'overview') {
        $st = ia_settings(); $status = in((string)($_GET['status'] ?? ''), ['new', 'awaiting', 'sent', 'closed', 'skipped', 'discarded'], '');
        $rows = all('SELECT i.*, c.name AS company_name, c.stage AS company_stage, hu.name AS handler FROM ia_items i LEFT JOIN companies c ON c.id=i.company_id LEFT JOIN users hu ON hu.id=i.handled_by' . ($status ? ' WHERE i.status=?' : '') . ' ORDER BY i.received_at DESC, i.id DESC LIMIT 120', $status ? [$status] : []);
        foreach ($rows as &$r) { $ids = json_decode((string)$r['tasks_json'], true) ?: []; $r['task_list'] = $ids ? array_map(fn($t) => ['id' => (int)$t['id'], 'title' => $t['title'], 'status' => $t['status']], all('SELECT id,title,status FROM tasks WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')) : []; } unset($r);
        $counts = array_column(all('SELECT status, COUNT(*) n FROM ia_items GROUP BY status'), 'n', 'status'); $accs = array_map(fn($a) => ['id' => (int)$a['id'], 'email' => $a['email']], all('SELECT id,email FROM mail_accounts WHERE user_id=? ORDER BY id', [$uid]));
        $set = ['enabled' => (int)$st['enabled'], 'auto_ack' => (int)$st['auto_ack'], 'ack_subject' => $st['ack_subject'], 'ack_body' => $st['ack_body'], 'rules' => $st['rules'], 'skip_list' => implode("\n", $st['skip']), 'approver_id' => $st['approver_id'] ? (int)$st['approver_id'] : null, 'mailbox_id' => $st['mailbox_id'] ? (int)$st['mailbox_id'] : null, 'last_scan_at' => $st['last_scan_at']];
        out(['settings' => $set, 'counts' => $counts, 'items' => array_map('ia_item_out', $rows), 'accounts' => $accs, 'stages' => IA_STAGES, 'marketing' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name']], all("SELECT u.id,u.name FROM users u WHERE u.active=1 AND u.archived_at IS NULL ORDER BY u.name"))]);
    }
    if ($method === 'GET' && $what === 'library') out(['files' => ia_library($u)]);
    if ($method === 'POST' && $what === 'settings') {
        $rules = []; foreach (array_slice((array)($b['rules'] ?? []), 0, 12) as $r) { if (!is_array($r)) continue; $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($r['key'] ?? ''))) ?: 'rule' . count($rules); $kw = array_values(array_filter(array_map(fn($k) => ia_str($k, 60), is_array($r['keywords'] ?? null) ? $r['keywords'] : preg_split('/[,\n]+/', (string)($r['keywords'] ?? ''))), fn($k) => $k !== ''));
            $tasks = array_values(array_filter(array_map(fn($k) => ia_str($k, 140), is_array($r['tasks'] ?? null) ? $r['tasks'] : preg_split('/\n+/', (string)($r['tasks'] ?? ''))), fn($k) => $k !== '')); $att = array_values(array_filter(array_map(fn($k) => ia_str($k, 40), is_array($r['attach'] ?? null) ? $r['attach'] : preg_split('/[,\n]+/', (string)($r['attach'] ?? ''))), fn($k) => $k !== ''));
            $rules[] = ['key' => $key, 'label' => ia_str($r['label'] ?? $key, 80) ?: $key, 'keywords' => array_slice($kw, 0, 60), 'weight' => max(0.5, min(5.0, (float)($r['weight'] ?? 1))), 'business' => !empty($r['business']) ? 1 : 0, 'stage' => in((string)($r['stage'] ?? ''), IA_STAGES, ''), 'priority' => in((string)($r['priority'] ?? 'medium'), ['low', 'medium', 'high', 'urgent'], 'medium'), 'tasks' => array_slice($tasks, 0, 8), 'attach' => array_slice($att, 0, 8), 'reply' => ia_str($r['reply'] ?? '', 4000)]; }
        if (!$rules) fail('Keep at least one category'); if (!array_filter($rules, fn($r) => $r['key'] === 'general')) $rules[] = ia_default_rules()[count(ia_default_rules()) - 1];
        $ap = (int)($b['approver_id'] ?? 0); if ($ap && !one('SELECT id FROM users WHERE id=? AND active=1', [$ap])) $ap = 0;
        q('UPDATE ia_settings SET enabled=?, auto_ack=?, ack_subject=?, ack_body=?, rules=?, skip_list=?, approver_id=?, mailbox_id=COALESCE(?, mailbox_id) WHERE id=1', [!empty($b['enabled']) ? 1 : 0, !empty($b['auto_ack']) ? 1 : 0, ia_str($b['ack_subject'] ?? '', 200), ia_str($b['ack_body'] ?? '', 3000), json_encode($rules, JSON_UNESCAPED_UNICODE), ia_str($b['skip_list'] ?? '', 3000), $ap ?: null, !empty($b['mailbox_id']) ? (int)$b['mailbox_id'] : null]);
        log_act($uid, 'inbox_auto_settings', ''); out(['ok' => true]);
    }
    if ($method === 'POST' && $what === 'scan') {
        $acc = one('SELECT * FROM mail_accounts WHERE user_id=? AND id=?', [$uid, (int)($b['acc'] ?? 0)]) ?: one('SELECT * FROM mail_accounts WHERE user_id=? ORDER BY id LIMIT 1', [$uid]); if (!$acc) fail('Connect the info@ mailbox in Mail first.', 409);
        try { out(['ok' => true] + ia_scan($acc, $u, (int)($b['days'] ?? 14), (int)($b['limit'] ?? 60))); } catch (MailError $e) { fail($e->getMessage(), 502); }
    }
    if ($what === 'items' && ctype_digit($seg[2] ?? '')) {
        $it = one('SELECT * FROM ia_items WHERE id=?', [(int)$seg[2]]); if (!$it) fail('Not found', 404); $act = $seg[3] ?? ''; $acc = one('SELECT * FROM mail_accounts WHERE id=?', [$it['account_id']]);
        $close = function (string $why) use ($it, $uid) { q("UPDATE ia_items SET status=?, handled_by=?, sent_at=IF(?='sent',NOW(),sent_at) WHERE id=?", [$why, $uid, $why, $it['id']]); if ($it['task_id']) q("UPDATE tasks SET status='done', completed_at=NOW() WHERE id=? AND status<>'done'", [$it['task_id']]); };
        if ($method === 'POST' && $act === 'close') { $close('closed'); out(['ok' => true]); }
        if ($method === 'POST' && $act === 'task') { if (in_array($it['status'], ['skipped', 'discarded'], true)) fail('This email was skipped or discarded'); $st = ia_settings(); $ids = ia_make_tasks((int)$it['id'], $u, $st); log_act($uid, 'inbox_auto_task', $it['from_email']); out(['ok' => true, 'tasks' => count($ids) + 1]); }   // the task is the reviewer's own
        if ($method === 'POST' && $act === 'discard') { q("UPDATE ia_items SET status='discarded', handled_by=? WHERE id=? AND status IN ('new','awaiting')", [$uid, $it['id']]); if ($it['task_id']) q("UPDATE tasks SET status='canceled' WHERE id=? AND status='todo' AND source='inbox'", [$it['task_id']]); foreach (json_decode((string)$it['tasks_json'], true) ?: [] as $tid) q("UPDATE tasks SET status='canceled' WHERE id=? AND status='todo' AND source='inbox'", [(int)$tid]); out(['ok' => true]); }
        if ($method === 'POST' && $act === 'category') { $st = ia_settings(); if (!$acc) fail('The mailbox was removed', 409); $cat = (string)($b['category'] ?? ''); if (!array_filter($st['rules'], fn($r) => $r['key'] === $cat)) fail('Unknown category'); ia_apply((int)$it['id'], $acc, $u, $st, $cat, false); out(['ok' => true]); }
        if ($method === 'POST' && $act === 'send') {
            if (!$acc) fail('The mailbox was removed', 409); if ($it['status'] === 'sent') fail('A reply was already sent'); $subject = ia_str($b['subject'] ?? $it['draft_subject'], 300); $text = ia_str($b['body'] ?? '', 8000); if (trim($text) === '') fail('Write the reply first');
            $to = filter_var($b['to'] ?? $it['from_email'], FILTER_VALIDATE_EMAIL) ?: fail('That email address is not valid'); $atts = []; $bytes = 0;
            foreach (array_slice((array)($b['attach'] ?? []), 0, 8) as $ref) { $f = ia_file($u, (string)$ref); if (!$f) continue; $bytes += strlen($f['data']); if ($bytes > 14 * 1048576) fail('The attachments are larger than 14 MB together'); $atts[] = $f; }
            foreach (array_slice((array)($b['files'] ?? []), 0, 4) as $f) { if (!is_array($f)) continue; $d = base64_decode((string)($f['data'] ?? ''), true); if ($d === false || $d === '') continue; $bytes += strlen($d); if ($bytes > 14 * 1048576) fail('The attachments are larger than 14 MB together');
                if (preg_match('/\.(php|phtml|exe|bat|cmd|com|scr|js|vbs|sh|msi|jar|html?|svg)$/i', (string)($f['name'] ?? ''))) fail('That kind of file cannot be attached'); $atts[] = ['name' => ia_str($f['name'] ?? 'file', 150), 'type' => ia_str($f['type'] ?? 'application/octet-stream', 100), 'data' => $d]; }
            try { ia_send($acc, $to, $subject, $text, $atts, (string)$it['message_id'], trim((string)$it['references_h'] . ' ' . (string)$it['message_id'])); } catch (Throwable $e) { fail('The reply could not be sent: ' . $e->getMessage(), 502); }
            try { $im = ia_imap($acc); $im->select($it['folder']); $im->flag((int)$it['uid'], '\\Answered', true); } catch (Throwable $e) {}
            $close('sent'); log_act($uid, 'inbox_auto_reply', $it['from_email']); out(['ok' => true, 'attached' => count($atts)]);
        }
        fail('Bad request', 404);
    }
    fail('Not found', 404);
}
