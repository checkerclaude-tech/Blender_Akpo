<?php
declare(strict_types=1);
// RFQ tracker (Finance). Reads the operations@ (or any chosen) mailbox:
//   * a request for quotation -> a tracker entry with its code + an action task "Create proforma / quotation" for Finance
//   * the quotation made in Finance for that RFQ (same RFQ ref, or made from the task) -> its number is stored and the RFQ is marked quoted once it is sent
//   * a purchase order that mentions the RFQ code -> the RFQ is marked PO received, with the PO number, and a task to prepare delivery
//   * a delivery note -> the delivery date goes into the tracker; the note is signed by an Aerosub user and printed for the client to sign
//   * once printed, the user confirms the payment -> the sale is recorded
// Functions are used by cron.php too; the routes run only when finance.php includes this file for finance/rfqs and finance/delivery.
//   GET  finance/rfqs[?status=&q=]   POST finance/rfqs (add by hand)   GET|PATCH finance/rfqs/{id}   POST finance/rfqs/scan   GET|PUT finance/rfqs/settings
//   POST finance/rfqs/{id}/po {po_number}   POST finance/rfqs/{id}/delivery {delivery_date,delivery_address,items?}   POST finance/rfqs/{id}/payment {amount,date,reference}
//   GET|PATCH finance/delivery/{id}   POST finance/delivery/{id}/sign   POST finance/delivery/{id}/printed
require_once __DIR__ . '/mail.php'; require_once __DIR__ . '/inboxauto.php';

function rfq_ensure(): void {
    static $d = false; if ($d) return; $d = true;
    try { if (!one("SHOW TABLES LIKE 'rfqs'") || !one("SHOW TABLES LIKE 'delivery_notes'")) { require_once __DIR__ . '/migrate_fin3.php'; migrate_fin_ext(); } } catch (Throwable $e) { error_log('RFQ schema: ' . $e->getMessage()); }
    if (!one('SELECT id FROM rfq_settings WHERE id=1')) q('INSERT INTO rfq_settings (id) VALUES (1)');
}
function rfq_settings(): array { rfq_ensure(); return one('SELECT * FROM rfq_settings WHERE id=1') ?: []; }
function rfq_norm(string $s): string { return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $s)); }
function rfq_event(int $id, string $kind, string $text, ?int $uid = null): void { q('INSERT INTO rfq_events (rfq_id,kind,text,user_id) VALUES (?,?,?,?)', [$id, $kind, mb_substr($text, 0, 400), $uid]); q('UPDATE rfqs SET updated_at=NOW() WHERE id=?', [$id]); }
const RFQ_WORDS = '/\b(rfq|request for (quotation|quote|proposal|price)|requests? for (quotation|quote)|invitation (to|for) (tender|bid|quote)|\bitt\b|please quote|kindly quote|quotation request|request a quotation|tender (no|number|ref)|call for (tender|bids?))\b/i';
const RFQ_PO_WORDS = '/\b(purchase order|\blpo\b|\bp\.?o\.?\b|work order|order (no|number|confirmation)|we (hereby )?(award|confirm the order))\b/i';
/** The reference the client gave the request, e.g. "RFQ-2026-0042" or "NLNG/ITT/123". Needs at least one digit. */
function rfq_find_code(string $subject, string $body): ?string {
    foreach ([$subject, mb_substr($body, 0, 3000)] as $t) {
        if (preg_match('/\b(?:RFQ|ITT|RFP|RFQN|TENDER)[\s#:.\-]*(?:No\.?|Number|Ref(?:erence)?\.?|ID)?[\s#:.\-]*([A-Z0-9][A-Z0-9\/_.\-]{2,38})/i', $t, $m) && preg_match('/\d/', $m[1])) { $c = rtrim($m[1], '.-/_'); $pre = strtoupper(preg_match('/^(RFQ|ITT|RFP)/i', $m[0], $pp) ? $pp[1] : ''); return (stripos($c, 'RFQ') === 0 || $pre === '' ? $c : ($pre . '-' . $c)); }
        if (preg_match('/\b([A-Z]{2,6}[\/\-][A-Z0-9]{2,8}[\/\-]\d{2,}[A-Z0-9\/\-]*)\b/', $t, $m) && preg_match('/\d/', $m[1])) return $m[1];
    }
    return null;
}
function rfq_find_po(string $subject, string $body): ?string {
    foreach ([$subject, mb_substr($body, 0, 4000)] as $t) if (preg_match('/\b(?:LPO|P\.?O\.?|Purchase Order|Order)[\s#:.\-]*(?:No\.?|Number|Ref\.?)?[\s#:.\-]*([A-Z0-9][A-Z0-9\/_.\-]{2,30})/i', $t, $m) && preg_match('/\d/', $m[1]) && !preg_match('/^(RFQ)/i', $m[1])) return rtrim($m[1], '.-/_');
    return null;
}
function rfq_find_date(string $text): ?string {
    if (!preg_match('/(closing|close|deadline|due|submit(?:ted)?|submission|return(?:ed)?|respond)[^.\n]{0,40}?(?:by|on|before|date|:)[^0-9A-Za-z]{0,6}(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}|\d{4}-\d{2}-\d{2}|\d{1,2}(?:st|nd|rd|th)?\s+(?:of\s+)?[A-Za-z]{3,9},?\s+\d{4})/i', mb_substr($text, 0, 4000), $m)) return null;
    $d = trim($m[2]); if (preg_match('~^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$~', $d, $x)) { $y = (int)$x[3] < 100 ? 2000 + (int)$x[3] : (int)$x[3]; $d = sprintf('%04d-%02d-%02d', $y, (int)$x[2], (int)$x[1]); } else $d = preg_replace('/(\d)(st|nd|rd|th)/i', '$1', str_replace(' of ', ' ', $d));
    $t = strtotime($d); return $t && $t > strtotime('-1 year') && $t < strtotime('+3 years') ? date('Y-m-d', $t) : null;
}
function rfq_next_code(): string { $n = (int)(one("SELECT COUNT(*) n FROM rfqs WHERE code LIKE ?", ['RFQ-' . date('ym') . '-%'])['n'] ?? 0) + 1; do { $c = 'RFQ-' . date('ym') . '-' . str_pad((string)$n++, 3, '0', STR_PAD_LEFT); } while (one('SELECT id FROM rfqs WHERE code=?', [$c])); return $c; }
function rfq_assignee(array $st, array $actor): array {
    $dept = one("SELECT id,head_id FROM departments WHERE name='Finance' LIMIT 1"); $to = (int)($st['assign_user_id'] ?? 0);
    if (!$to && $dept && $dept['head_id']) $to = (int)$dept['head_id']; if (!$to) $to = (int)$actor['id']; return [$to, $dept ? (int)$dept['id'] : null];
}
function rfq_task(int $rfqId, string $title, string $desc, ?string $due, array $st, array $actor): int {
    [$to, $dept] = rfq_assignee($st, $actor);
    q("INSERT INTO tasks (title,description,status,priority,assignee_id,creator_id,department_id,due_date,task_type,source) VALUES (?,?,'todo','high',?,?,?,?,'rfq','rfq')", [mb_substr($title, 0, 200), mb_substr($desc, 0, 4000), $to, (int)$actor['id'], $dept, $due ?: date('Y-m-d', strtotime('+3 days'))]);
    $tid = (int)db()->lastInsertId(); notify($to, 'task_assigned', 'New task: ' . mb_substr($title, 0, 150), 'RFQ tracker', '#/tasks/' . $tid); return $tid;
}
/** One email. Returns 'rfq' | 'po' | 'followup' | '' */
function rfq_handle_mail(array $acc, array $actor, array $st, array $m): string {
    $subj = (string)$m['subject']; $body = (string)$m['text']; $from = strtolower((string)$m['from_email']); $all = $subj . "\n" . $body . "\n" . implode(' ', $m['att_names'] ?? []); $norm = rfq_norm($all);
    // a purchase order for one of our open RFQs (its code appears in the mail, or in a file name)
    $open = all("SELECT * FROM rfqs WHERE status IN ('new','quoting','quoted') AND LENGTH(code)>=4 ORDER BY id DESC LIMIT 400"); $hit = null;
    foreach ($open as $r) { if (str_contains($norm, rfq_norm((string)$r['code']))) { $hit = $r; break; } }
    if ($hit && (preg_match(RFQ_PO_WORDS, $subj . ' ' . mb_substr($body, 0, 2000)) || !preg_match(RFQ_WORDS, $subj))) {
        $po = rfq_find_po($subj, $body) ?: ('PO-' . date('ymd')); $tid = rfq_task((int)$hit['id'], 'Prepare delivery note and invoice: PO ' . $po . ' (RFQ ' . $hit['code'] . ')', 'Purchase order received from ' . $m['from_email'] . ' for RFQ ' . $hit['code'] . ($hit['quote_number'] ? ' (quotation ' . $hit['quote_number'] . ')' : '') . ".\nSubject: " . $subj . "\nOpen the RFQ tracker in Finance to create the delivery note.", null, $st, $actor);
        $dd2 = preg_match('/(deliver(?:y|ed)?)[^.\n]{0,40}?(?:by|on|before|date|within|:)[^0-9A-Za-z]{0,6}(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}|\d{4}-\d{2}-\d{2}|\d{1,2}(?:st|nd|rd|th)?\s+(?:of\s+)?[A-Za-z]{3,9},?\s+\d{4})/i', mb_substr($body, 0, 4000), $mm) ? rfq_find_date('due by ' . $mm[2]) : null;
        if ($dd2) q('UPDATE rfqs SET delivery_due=? WHERE id=?', [$dd2, (int)$hit['id']]);
        q("UPDATE rfqs SET status='po_received', po_number=?, po_received_at=?, po_from=?, po_subject=?, po_files=?, po_task_id=? WHERE id=?", [mb_substr($po, 0, 80), $m['date'] ?: date('Y-m-d H:i:s'), mb_substr($m['from_email'], 0, 190), mb_substr($subj, 0, 300), mb_substr(implode(', ', $m['att_names'] ?? []), 0, 480) ?: null, $tid, (int)$hit['id']]);
        if ($hit['task_id']) q("UPDATE tasks SET status='done', completed_at=NOW() WHERE id=? AND status<>'done'", [(int)$hit['task_id']]);
        rfq_event((int)$hit['id'], 'po', 'Purchase order ' . $po . ' received from ' . $m['from_email']); foreach (array_unique(array_filter([(int)$hit['created_by']])) as $n) notify($n, 'rfq_po', '📨 PO received for RFQ ' . $hit['code'], 'PO ' . $po, '#/finance/rfqs'); return 'po';
    }
    if (!preg_match(RFQ_WORDS, $subj . ' ' . mb_substr($body, 0, 1500))) return '';
    $code = rfq_find_code($subj, $body); $src = 'email'; if ($code === null) { $code = rfq_next_code(); $src = 'generated'; }
    $dup = one('SELECT id FROM rfqs WHERE code=? AND (client_email=? OR client_email IS NULL) ORDER BY id DESC LIMIT 1', [$code, $from]);
    if ($dup) { rfq_event((int)$dup['id'], 'mail', 'Another email about this RFQ from ' . $m['from_email'] . ': ' . mb_substr($subj, 0, 120)); return 'followup'; }
    $name = trim((string)($m['from_name'] ?? '')); $co = ia_company_for($from, $name, false); $client = $co['name'] ?? ($name !== '' ? $name : ucfirst(explode('.', explode('@', substr(strrchr($from, '@') ?: '@', 1))[0])[0]));
    $due = rfq_find_date($subj . "\n" . $body); $snip = mb_substr(trim(preg_replace('/\s+/', ' ', $body)), 0, 600);
    $desc = trim(preg_replace('/^(re|fw|fwd)\s*:\s*|\b(rfq|request for (quotation|quote))\b[\s#:.\-]*' . preg_quote((string)$code, '/') . '[\s:.\-]*/i', '', $subj)) ?: $subj;
    q('INSERT INTO rfqs (code,code_source,client_name,client_email,subject,description,buyer_details,snippet,received_at,due_date,msg_key,account_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [mb_substr($code, 0, 80), $src, mb_substr($client, 0, 190), mb_substr($from, 0, 190), mb_substr($subj, 0, 300), mb_substr($desc, 0, 500), mb_substr($from, 0, 480), $snip, $m['date'] ?: date('Y-m-d H:i:s'), $due, $m['msg_key'], (int)$acc['id'], (int)$actor['id']]);
    $id = (int)db()->lastInsertId(); $tid = rfq_task($id, 'Create proforma / quotation: ' . $client . ' (RFQ ' . $code . ')', "Request for quotation received from " . ($name ?: $from) . " <" . $from . ">\nRFQ code: " . $code . ($src === 'generated' ? ' (the sender gave no code, so we made one)' : '') . ($due ? "\nClosing date: " . $due : '') . "\nSubject: " . $subj . "\n\nOpen the RFQ tracker (Finance → RFQs) and press “Create quotation”.", $due, $st, $actor);
    q('UPDATE rfqs SET task_id=? WHERE id=?', [$tid, $id]); rfq_event($id, 'new', 'RFQ received from ' . $from . ' · code ' . $code . ($src === 'generated' ? ' (made by us)' : '')); return 'rfq';
}
function rfq_scan(array $acc, array $actor, int $days = 7, int $limit = 80): array {
    rfq_ensure(); $st = rfq_settings(); $im = ia_imap($acc); $im->select('INBOX'); $since = date('d-M-Y', strtotime('-' . max(1, min(60, $days)) . ' days')); $uids = array_slice($im->search('SINCE ' . $since), -max(1, min(200, $limit))); $r = ['rfqs' => 0, 'pos' => 0, 'followups' => 0, 'looked_at' => count($uids)];
    $skip = (array)(ia_settings()['skip'] ?? []);
    foreach (array_reverse($uids) as $uid) {
        try { $raw = $im->fetchRaw((int)$uid, 600000); $x = mime_extract($raw); $h = $x['headers']; $fr = mime_addr_list($h['from'] ?? ''); if (!$fr) continue; $mid = trim((string)($h['message-id'] ?? '')); $key = sha1('rfq|' . $acc['id'] . '|' . ($mid ?: 'INBOX' . $uid));
            if (one('SELECT msg_key FROM rfq_msgs WHERE msg_key=?', [$key])) continue; $subject = mime_decode_header($h['subject'] ?? ''); $fe = strtolower((string)$fr[0]['email']);
            q('INSERT IGNORE INTO rfq_msgs (msg_key,kind) VALUES (?,?)', [$key, 'seen']); if (ia_skip_reason($h, $fe, $subject, (string)$acc['email'], $skip) !== '') continue;
            $res = rfq_handle_mail($acc, $actor, $st, ['subject' => $subject, 'text' => (string)$x['text'], 'from_email' => $fe, 'from_name' => (string)($fr[0]['name'] ?? ''), 'date' => mime_date($h['date'] ?? ''), 'msg_key' => $key, 'att_names' => array_map(fn($a) => (string)$a['name'], $x['attachments'] ?? [])]);
            if ($res) { q('UPDATE rfq_msgs SET kind=? WHERE msg_key=?', [$res, $key]); $r[$res === 'rfq' ? 'rfqs' : ($res === 'po' ? 'pos' : 'followups')]++; }
        } catch (Throwable $e) { error_log('RFQ scan: ' . $e->getMessage()); }
    }
    q('UPDATE rfq_settings SET last_scan_at=NOW() WHERE id=1'); return $r;
}
/** The quotation made in Finance for an RFQ: matched by RFQ reference or by the action task. Marks "quoted" once it has been sent. */
function rfq_sync_quotes(): void {
    foreach (all("SELECT * FROM rfqs WHERE status IN ('new','quoting','quoted') ORDER BY id DESC LIMIT 300") as $r) {
        $d = one("SELECT id,number,doc_type,status,currency,updated_at FROM fin_docs WHERE doc_type IN ('commercial','proforma') AND status<>'void' AND (UPPER(REPLACE(REPLACE(REPLACE(REPLACE(rfq_ref,'-',''),'/',''),' ',''),'.',''))=? OR (task_id IS NOT NULL AND task_id=?)) ORDER BY (status IN ('sent','paid')) DESC, id DESC LIMIT 1", [rfq_norm((string)$r['code']), (int)($r['task_id'] ?: 0)]);
        if (!$d) continue; $tot = rfq_doc_total((int)$d['id']); $sent = in_array($d['status'], ['sent', 'paid'], true); $new = $sent ? 'quoted' : 'quoting';
        if ((int)$r['quote_doc_id'] !== (int)$d['id'] || $r['status'] !== $new || abs((float)$r['quote_total'] - $tot) > 0.004) {
            if (!$r['quote_doc_id'] || (int)$r['quote_doc_id'] !== (int)$d['id']) rfq_event((int)$r['id'], 'quote', 'Quotation ' . $d['number'] . ' created');
            q('UPDATE rfqs SET quote_doc_id=?, quote_number=?, quote_total=?, currency=?, status=?, quoted_at=IF(?=1 AND quoted_at IS NULL, NOW(), quoted_at) WHERE id=?', [(int)$d['id'], $d['number'], $tot, $d['currency'], $new, $sent ? 1 : 0, (int)$r['id']]);
            if ($sent && $r['status'] !== 'quoted') { rfq_event((int)$r['id'], 'quoted', 'Quotation ' . $d['number'] . ' sent'); if ($r['task_id']) q("UPDATE tasks SET status='done', completed_at=NOW() WHERE id=? AND status<>'done'", [(int)$r['task_id']]); } }
    }
}
/** Total of a quotation (lines that carry a note such as "As incurred" are shown but not counted; then discount and tax), the same arithmetic as the document itself. */
function rfq_doc_total(int $id): float {
    $d = one('SELECT discount,tax_rate FROM fin_docs WHERE id=?', [$id]); if (!$d) return 0.0; $sub = (float)(one("SELECT COALESCE(SUM(CASE WHEN COALESCE(amount_note,'')<>'' THEN 0 ELSE qty*unit_price END),0) s FROM fin_items WHERE doc_id=?", [$id])['s'] ?? 0);
    $net = $sub - min(max(0.0, (float)$d['discount']), $sub); return round($net + round($net * (float)$d['tax_rate'] / 100, 2), 2);
}
function rfq_cron(): string {
    try { rfq_ensure(); $st = rfq_settings(); if (empty($st['enabled'])) return 'RFQ tracker: off'; rfq_sync_quotes(); $ids = array_filter(array_map('intval', explode(',', (string)($st['mailbox_ids'] ?? '')))); if (!$ids) return 'RFQ tracker: no mailbox chosen'; $tot = ['rfqs' => 0, 'pos' => 0];
        foreach ($ids as $aid) { $acc = one('SELECT * FROM mail_accounts WHERE id=?', [$aid]); if (!$acc) continue; $actor = one('SELECT id,name,email,role FROM users WHERE id=?', [$acc['user_id']]); if (!$actor) continue; $r = rfq_scan($acc, $actor, 3, 80); $tot['rfqs'] += $r['rfqs']; $tot['pos'] += $r['pos']; }
        return 'RFQ tracker: ' . json_encode($tot); } catch (Throwable $e) { return 'RFQ tracker skipped: ' . $e->getMessage(); }
}

/** The Excel tracker in the layout Aerosub already uses: RFQ block, then PO block, grouped by client. */
const RFQ_LABEL = ['new' => 'Received', 'quoting' => 'Quotation in progress', 'quoted' => 'Submitted', 'revisited' => 'Revisited', 'on_hold' => 'On Hold', 'po_received' => 'PO received', 'delivered' => 'Delivered', 'paid' => 'PAID', 'lost' => 'Lost', 'closed' => 'Closed'];
function rfq_export_xlsx(): string {
    $hd = ['fill' => '0B2A63', 'color' => 'FFFFFF', 'b' => true, 'h' => 'center', 'border' => 'thin', 'wrap' => true]; $cell = ['border' => 'thin', 'wrap' => true, 'v' => 'top']; $dt = ['border' => 'thin', 'v' => 'top', 'h' => 'center', 'fmt' => 'dd/mm/yyyy'];
    $rows = [1 => ['h' => 24, 'cells' => [1 => ['v' => 'RFP/RFQ/TENDERS', 's' => ['b' => true, 'sz' => 13]], 8 => ['v' => 'PURCHASE ORDER (PO)', 's' => ['b' => true, 'sz' => 13]]]]]; $cols = ['S/N', 'RFQ NUMBER', 'DESCRIPTION', 'DATE RECEIVED', 'SUBMISSION DATE', 'STATUS', 'COMMENTS', 'PO NUMBER', 'DESCRIPTION', 'DATE RECEIVED', 'DELIVERY DUE DATE', 'ACTUAL DELIVERY DATE', 'STATUS', 'INVOICE STATUS', 'COMMENTS', 'PAYMENT STATUS', 'BUYER DETAILS'];
    $c = []; foreach ($cols as $i => $t) $c[$i + 1] = ['v' => $t, 's' => $hd]; $rows[2] = ['h' => 30, 'cells' => $c]; $r = 3; $merges = []; $client = null; $n = 0;
    $d = fn($v) => $v ? ['v' => date('d/m/Y', strtotime((string)$v)), 's' => $dt] : ['v' => '', 's' => $cell];
    foreach (all('SELECT * FROM rfqs ORDER BY client_name, id') as $x) {
        if ($client !== $x['client_name']) { $client = $x['client_name']; $n = 0; $rows[$r] = ['h' => 20, 'cells' => [2 => ['v' => strtoupper((string)$client), 's' => ['b' => true, 'fill' => 'DCE6F4', 'border' => 'thin']], 7 => ['v' => strtoupper((string)$client), 's' => ['b' => true, 'fill' => 'DCE6F4', 'border' => 'thin']]]]; $merges[] = "B$r:F$r"; $merges[] = "G$r:Q$r"; $r++; }
        $n++; $po = $x['po_number'] !== null; $pay = $x['status'] === 'paid' ? 'PAID' : '';
        $rows[$r++] = ['cells' => [1 => ['v' => $n, 's' => $cell], 2 => ['v' => $x['code'], 's' => $cell], 3 => ['v' => (string)($x['description'] ?: $x['subject']), 's' => $cell], 4 => $d($x['received_at']), 5 => $d($x['due_date']), 6 => ['v' => RFQ_LABEL[$x['status']] ?? $x['status'], 's' => $cell], 7 => ['v' => (string)$x['notes'], 's' => $cell],
            8 => ['v' => (string)$x['po_number'], 's' => $cell], 9 => ['v' => $po ? (string)($x['po_description'] ?: ($x['description'] ?: $x['subject'])) : '', 's' => $cell], 10 => $d($x['po_received_at']), 11 => $d($x['delivery_due']), 12 => $d($x['delivery_date']), 13 => ['v' => in_array($x['status'], ['delivered', 'paid'], true) ? 'Delivered' : '', 's' => $cell], 14 => ['v' => (string)$x['invoice_status'], 's' => $cell], 15 => ['v' => (string)$x['po_comments'], 's' => $cell], 16 => ['v' => $pay, 's' => $cell], 17 => ['v' => (string)($x['buyer_details'] ?: $x['client_email']), 's' => $cell]]];
    }
    return xlsx_write(['name' => (string)date('Y'), 'cols' => [5, 15, 48, 13, 13, 18, 34, 28, 40, 13, 13, 14, 12, 14, 30, 13, 36], 'rows' => $rows, 'merges' => $merges, 'landscape' => true]);
}
function rfq_cell_date($v): ?string {
    $v = trim((string)$v); if ($v === '') return null; if (is_numeric($v) && (float)$v > 30000 && (float)$v < 80000) return date('Y-m-d', (int)round(((float)$v - 25569) * 86400));
    if (preg_match('~^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$~', $v, $m)) { $y = (int)$m[3] < 100 ? 2000 + (int)$m[3] : (int)$m[3]; return checkdate((int)$m[2], (int)$m[1], $y) ? sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]) : null; }
    $t = strtotime($v); return $t ? date('Y-m-d', $t) : null;
}
/** Rows of the Excel tracker (first sheet): client heading rows, then one RFQ (and its PO) per row. Existing RFQ numbers for the same client are left alone. */
function rfq_import_rows(array $rows, array $u): array {
    rfq_ensure(); $client = ''; $added = 0; $skipped = 0; $clients = [];
    foreach ($rows as $row) {
        $a = trim((string)($row[0] ?? '')); $b = trim((string)($row[1] ?? '')); $c = trim((string)($row[2] ?? ''));
        if ($a === '' && $b !== '' && $c === '' && !is_numeric($b)) { $client = trim(preg_replace('/\s+/', ' ', $b)); continue; }
        if ($a === '' || !is_numeric($a) || $b === '' || $client === '') continue; $code = preg_replace('/\.0$/', '', $b); if (one('SELECT id FROM rfqs WHERE code=? AND client_name=?', [$code, $client])) { $skipped++; continue; }
        $stt = strtolower(trim((string)($row[5] ?? ''))); $po = trim((string)($row[7] ?? '')); $del = rfq_cell_date($row[11] ?? ''); $mst = strtolower(trim((string)($row[12] ?? ''))); $pay = strtolower(trim((string)($row[15] ?? '')));
        $status = str_contains($stt, 'revis') ? 'revisited' : (str_contains($stt, 'hold') ? 'on_hold' : (str_contains($stt, 'submit') ? 'quoted' : (str_contains($stt, 'cancel') || str_contains($stt, 'lost') ? 'lost' : 'new')));
        if ($po !== '') $status = 'po_received'; if ($mst === 'delivered' || $del) $status = 'delivered'; if ($pay === 'paid') $status = 'paid';
        $buyer = trim((string)($row[16] ?? '')); $first = trim(explode(',', $buyer)[0]);
        q('INSERT INTO rfqs (code,code_source,client_name,client_email,subject,description,buyer_details,received_at,due_date,status,notes,po_number,po_description,po_received_at,delivery_due,delivery_date,invoice_status,po_comments,paid_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [mb_substr($code, 0, 80), 'manual', mb_substr($client, 0, 190), filter_var($first, FILTER_VALIDATE_EMAIL) ?: null, mb_substr((string)$row[2], 0, 300), mb_substr((string)$row[2], 0, 500), mb_substr($buyer, 0, 480) ?: null, rfq_cell_date($row[3] ?? ''), rfq_cell_date($row[4] ?? ''), $status, trim((string)($row[6] ?? '')) ?: null, $po !== '' ? mb_substr($po, 0, 80) : null, $po !== '' ? mb_substr((string)($row[8] ?? ''), 0, 500) : null, rfq_cell_date($row[9] ?? ''), rfq_cell_date($row[10] ?? ''), $del, trim((string)($row[13] ?? '')) ?: null, trim((string)($row[14] ?? '')) ?: null, null, (int)$u['id']]);
        rfq_event((int)db()->lastInsertId(), 'new', 'Imported from the Excel tracker', (int)$u['id']); $added++; $clients[$client] = 1;
    }
    return ['added' => $added, 'skipped' => $skipped, 'clients' => count($clients)];
}
/** The delivery note as an editable Word file on the Aerosub letterhead (same layout as AEROSUB_DELIVERY_NOTE_TEMPLATE). */
function dn_docx(array $d): array {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); $items = json_decode((string)$d['items'], true) ?: []; $rows = ''; foreach ($items as $i => $it) $rows .= '<tr><td>' . ($i + 1) . '</td><td>' . $e($it['description']) . '</td><td>' . $e(rtrim(rtrim(number_format((float)$it['qty'], 2, '.', ''), '0'), '.') . ' ' . ($it['unit'] ?? '')) . '</td></tr>';
    $html = '<h2 style="text-align:center">DELIVERY NOTE</h2><p style="text-align:right">' . $e(date('F j, Y', strtotime((string)($d['delivery_date'] ?: 'today')))) . '</p>' . ($d['po_number'] ? '<p><b>PO NUMBER : </b>' . $e($d['po_number']) . '</p>' : '') . '<p><b>DELIVERED TO :</b></p><table><tr><td><b>' . $e($d['client_name']) . '</b><br>' . nl2br($e(trim((string)($d['delivery_address'] ?: $d['client_address'])))) . '</td></tr></table>'
        . '<p></p><table><tr><th>S/N</th><th>DESCRIPTION</th><th>QTY/UoM</th></tr>' . $rows . '</table><p>Goods received in good condition</p><p></p><table><tr><td>Name: [' . $e($d['client_name']) . ']<br><br><br>Signature: ______________________</td><td>Name: [Aerosub Solutions Ltd]' . ($d['signed_at'] ? '<br>Signed in Aerosub Office on ' . $e(date('j M Y', strtotime((string)$d['signed_at']))) : '<br>') . '<br><br>Signature: ______________________</td></tr></table>';
    $art = is_file(dirname(__DIR__, 2) . '/assets/img/letterhead-aerosub.png') ? ['data' => (string)file_get_contents(dirname(__DIR__, 2) . '/assets/img/letterhead-aerosub.png'), 'mime' => 'image/png'] : null;
    return [ltr_docx('Delivery note ' . $d['number'], $html, $art, [52.0, 44.0, 22.0, 22.0]), $d['number'] . '.docx'];
}

/* ---------------- routes ---------------- */
if (!isset($seg[0]) || $seg[0] !== 'finance' || !isset($u)) return;
require_once __DIR__ . '/taskfiles.php'; rfq_ensure();
if (!$fin) fail('The RFQ tracker is for the finance team', 403);
function rfq_dto(array $r): array {
    return ['id' => (int)$r['id'], 'code' => $r['code'], 'code_source' => $r['code_source'], 'client_name' => $r['client_name'], 'client_email' => $r['client_email'], 'subject' => $r['subject'], 'received_at' => $r['received_at'], 'due_date' => $r['due_date'], 'status' => $r['status'], 'task_id' => $r['task_id'] ? (int)$r['task_id'] : null,
        'quote_doc_id' => $r['quote_doc_id'] ? (int)$r['quote_doc_id'] : null, 'quote_number' => $r['quote_number'], 'quote_total' => $r['quote_total'] !== null ? (float)$r['quote_total'] : null, 'currency' => $r['currency'], 'quoted_at' => $r['quoted_at'], 'po_number' => $r['po_number'], 'po_received_at' => $r['po_received_at'], 'po_from' => $r['po_from'], 'po_files' => $r['po_files'],
        'description' => $r['description'] ?? null, 'po_description' => $r['po_description'] ?? null, 'delivery_due' => $r['delivery_due'] ?? null, 'invoice_status' => $r['invoice_status'] ?? null, 'po_comments' => $r['po_comments'] ?? null, 'buyer_details' => $r['buyer_details'] ?? null, 'delivery_date' => $r['delivery_date'], 'paid_at' => $r['paid_at'], 'paid_amount' => $r['paid_amount'] !== null ? (float)$r['paid_amount'] : null, 'pay_ref' => $r['pay_ref'], 'notes' => $r['notes'], 'updated_at' => $r['updated_at']];
}
function dn_dto(array $d): array {
    return ['id' => (int)$d['id'], 'number' => $d['number'], 'rfq_id' => $d['rfq_id'] ? (int)$d['rfq_id'] : null, 'rfq_code' => $d['rfq_code'], 'client_name' => $d['client_name'], 'client_address' => $d['client_address'], 'po_number' => $d['po_number'], 'delivery_date' => $d['delivery_date'], 'delivery_address' => $d['delivery_address'], 'items' => json_decode((string)$d['items'], true) ?: [], 'notes' => $d['notes'],
        'signed' => $d['signed_at'] !== null, 'signed_by' => $d['signed_by_name'] ?? null, 'signed_at' => $d['signed_at'], 'signature' => $d['signature'], 'printed_at' => $d['printed_at'], 'created_by' => $d['created_by_name'] ?? null, 'created_at' => $d['created_at']];
}
if ($sub === 'rfqs') {
    $rid = isset($seg[2]) && ctype_digit($seg[2]) ? (int)$seg[2] : 0; $act = $rid ? ($seg[3] ?? '') : ($seg[2] ?? '');
    if ($act === 'settings') {
        $st = rfq_settings();
        if ($method === 'GET') { $mine = all('SELECT id,email FROM mail_accounts WHERE user_id=? ORDER BY id', [$uid]); $sel = array_filter(array_map('intval', explode(',', (string)($st['mailbox_ids'] ?? ''))));
            out(['enabled' => (bool)$st['enabled'], 'mailboxes' => array_map(fn($a) => ['id' => (int)$a['id'], 'email' => $a['email'], 'on' => in_array((int)$a['id'], $sel, true)], $mine), 'others' => all('SELECT id,email FROM mail_accounts WHERE id IN (' . ($sel ? implode(',', $sel) : '0') . ') AND user_id<>?', [$uid]), 'assign_user_id' => $st['assign_user_id'] ? (int)$st['assign_user_id'] : null,
                'finance_people' => all("SELECT u.id,u.name FROM users u WHERE u.active=1 AND (u.role='super_admin' OR u.department_id=(SELECT id FROM departments WHERE name='Finance' LIMIT 1)) ORDER BY u.name"), 'last_scan_at' => $st['last_scan_at']]); }
        if (!in_array($role, ['super_admin', 'manager'], true) && !can_finance($u)) fail('Not allowed', 403);
        if ($method === 'PUT') { $mine = array_map('intval', array_column(all('SELECT id FROM mail_accounts WHERE user_id=?', [$uid]), 'id')); $keep = array_filter(array_map('intval', explode(',', (string)($st['mailbox_ids'] ?? ''))), fn($i) => !in_array($i, $mine, true)); $on = array_values(array_intersect(array_map('intval', (array)($b['mailbox_ids'] ?? [])), $mine));
            q('UPDATE rfq_settings SET enabled=?, mailbox_ids=?, assign_user_id=? WHERE id=1', [(int)!empty($b['enabled']), implode(',', array_unique(array_merge($keep, $on))) ?: null, !empty($b['assign_user_id']) ? (int)$b['assign_user_id'] : null]); out(['ok' => true]); }
        fail('Not found', 404);
    }
    if ($act === 'export' && $method === 'GET') { rfq_sync_quotes(); require_once __DIR__ . '/xlsx.php'; $bin = rfq_export_xlsx(); header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="LIST_OF_RFQs_AND_POs_' . date('Y') . '.xlsx"'); header('Content-Length: ' . strlen($bin)); header('Cache-Control: private, no-store'); echo $bin; exit; }
    if ($act === 'import' && $method === 'POST') { require_once __DIR__ . '/xlsx.php'; $data = base64_decode((string)($b['data'] ?? ''), true); if ($data === false || strlen($data) < 200 || strlen($data) > 6 * 1048576) fail('Choose the Excel file (up to 6 MB)'); $tmp = tempnam(sys_get_temp_dir(), 'rfq'); file_put_contents($tmp, $data);
        try { $r = rfq_import_rows(xlsx_read($tmp), $u); } catch (Throwable $e) { @unlink($tmp); fail('That file could not be read: ' . mb_substr($e->getMessage(), 0, 120)); } @unlink($tmp); log_act($uid, 'rfq_import', $r['added'] . ' added'); out(['ok' => true] + $r); }
    if ($act === 'scan' && $method === 'POST') {
        rfq_sync_quotes(); $st = rfq_settings(); $ids = array_filter(array_map('intval', explode(',', (string)($st['mailbox_ids'] ?? '')))); $acc = [];
        foreach ($ids as $i) { $a = one('SELECT * FROM mail_accounts WHERE id=?', [$i]); if ($a && (int)$a['user_id'] === $uid) $acc[] = $a; } if (!$acc) { $a = one('SELECT * FROM mail_accounts WHERE user_id=? ORDER BY id LIMIT 1', [$uid]); if ($a) $acc[] = $a; }
        if (!$acc) fail('Connect the operations@ mailbox in the Mail tab first, then choose it in the tracker settings.', 409); $tot = ['rfqs' => 0, 'pos' => 0, 'followups' => 0, 'looked_at' => 0];
        try { foreach ($acc as $a) { $r = rfq_scan($a, $u, (int)($b['days'] ?? 14), (int)($b['limit'] ?? 100)); foreach ($tot as $k => $_) $tot[$k] += $r[$k]; } } catch (MailError $e) { fail($e->getMessage(), 502); }
        out(['ok' => true] + $tot);
    }
    if ($method === 'GET' && !$rid) {
        rfq_sync_quotes(); $w = '1=1'; $p = []; if (!empty($_GET['status']) && $_GET['status'] === 'open') { $w .= " AND status IN ('new','quoting','quoted','revisited','on_hold','po_received','delivered')"; } elseif (!empty($_GET['status']) && in_array($_GET['status'], ['new', 'quoting', 'quoted', 'revisited', 'on_hold', 'po_received', 'delivered', 'paid', 'lost', 'closed'], true)) { $w .= ' AND status=?'; $p[] = $_GET['status']; }
        if (!empty($_GET['q'])) { $w .= ' AND (code LIKE ? OR client_name LIKE ? OR po_number LIKE ? OR quote_number LIKE ? OR subject LIKE ?)'; $qq = '%' . fx_s($_GET['q'], 60) . '%'; array_push($p, $qq, $qq, $qq, $qq, $qq); }
        $rows = all("SELECT * FROM rfqs WHERE $w ORDER BY client_name, id LIMIT 600", $p);
        $cnt = array_column(all('SELECT status, COUNT(*) n FROM rfqs GROUP BY status'), 'n', 'status'); $sales = one("SELECT COUNT(*) n, COALESCE(SUM(paid_amount),0) s FROM rfqs WHERE status='paid'"); $month = one("SELECT COALESCE(SUM(paid_amount),0) s FROM rfqs WHERE status='paid' AND paid_at >= ?", [date('Y-m-01')]);
        out(['rfqs' => array_map('rfq_dto', $rows), 'counts' => $cnt, 'sales' => ['count' => (int)$sales['n'], 'total' => (float)$sales['s'], 'month' => (float)$month['s']], 'quoted_open' => (float)(one("SELECT COALESCE(SUM(quote_total),0) s FROM rfqs WHERE status IN ('quoted','po_received','delivered')")['s'] ?? 0)]);
    }
    if ($method === 'POST' && !$rid) {
        $client = fx_s($b['client_name'] ?? '', 190); if (!$client) fail('Who is the RFQ from?'); $code = fx_s($b['code'] ?? '', 80); $src = $code ? 'manual' : 'generated'; $code = $code ?: rfq_next_code(); $due = $b['due_date'] ?? null; $due = $due && strtotime((string)$due) ? date('Y-m-d', strtotime((string)$due)) : null;
        q('INSERT INTO rfqs (code,code_source,client_name,client_email,subject,received_at,due_date,notes,created_by) VALUES (?,?,?,?,?,NOW(),?,?,?)', [$code, $src, $client, fx_s($b['client_email'] ?? '', 190), fx_s($b['subject'] ?? '', 300), $due, fx_s($b['notes'] ?? '', 2000), $uid]); $id = (int)db()->lastInsertId(); $st = rfq_settings();
        $tid = rfq_task($id, 'Create proforma / quotation: ' . $client . ' (RFQ ' . $code . ')', "RFQ added by " . $u['name'] . ".\nRFQ code: " . $code . "\nOpen the RFQ tracker and press “Create quotation”.", $due, $st, $u); q('UPDATE rfqs SET task_id=? WHERE id=?', [$tid, $id]); rfq_event($id, 'new', 'Added by ' . $u['name'], $uid); out(['ok' => true, 'id' => $id], 201);
    }
    $r = $rid ? one('SELECT * FROM rfqs WHERE id=?', [$rid]) : null; if (!$r) fail('Not found', 404);
    if ($method === 'GET' && $act === '') { rfq_sync_quotes(); $r = one('SELECT * FROM rfqs WHERE id=?', [$rid]); out(['rfq' => rfq_dto($r), 'events' => all('SELECT e.kind,e.text,e.at,u.name AS by_name FROM rfq_events e LEFT JOIN users u ON u.id=e.user_id WHERE e.rfq_id=? ORDER BY e.id DESC LIMIT 60', [$rid]), 'deliveries' => array_map('dn_dto', all('SELECT d.*, u.name AS signed_by_name FROM delivery_notes d LEFT JOIN users u ON u.id=d.signed_by WHERE d.rfq_id=? ORDER BY d.id DESC', [$rid]))]); }
    if ($method === 'PATCH' && $act === '') {
        $f = []; $p = []; foreach (['client_name' => 190, 'client_email' => 190, 'subject' => 300, 'notes' => 2000, 'code' => 80, 'description' => 500, 'po_description' => 500, 'po_comments' => 2000, 'buyer_details' => 480, 'invoice_status' => 30, 'po_number' => 80] as $k => $n) if (array_key_exists($k, $b)) { $v = fx_s($b[$k], $n); if ($k === 'code' && !$v) continue; $f[] = "$k=?"; $p[] = $v; }
        foreach (['delivery_due', 'delivery_date', 'paid_at'] as $dk) if (array_key_exists($dk, $b)) { $f[] = "$dk=?"; $p[] = $b[$dk] && strtotime((string)$b[$dk]) ? date('Y-m-d', strtotime((string)$b[$dk])) : null; }
        if (array_key_exists('due_date', $b)) { $f[] = 'due_date=?'; $p[] = $b['due_date'] && strtotime((string)$b['due_date']) ? date('Y-m-d', strtotime((string)$b['due_date'])) : null; }
        if (!empty($b['status']) && in_array($b['status'], ['lost', 'closed', 'new', 'quoting', 'quoted', 'revisited', 'on_hold', 'po_received', 'delivered'], true)) { $f[] = 'status=?'; $p[] = $b['status']; rfq_event($rid, 'status', 'Marked ' . $b['status'] . ' by ' . $u['name'], $uid); }
        if ($f) { $p[] = $rid; q('UPDATE rfqs SET ' . implode(',', $f) . ' WHERE id=?', $p); } out(['ok' => true]);
    }
    if ($method === 'POST' && $act === 'po') { $po = fx_s($b['po_number'] ?? '', 80); if (!$po) fail('Type the PO number'); q("UPDATE rfqs SET status='po_received', po_number=?, po_received_at=NOW() WHERE id=?", [$po, $rid]); rfq_event($rid, 'po', 'PO ' . $po . ' recorded by ' . $u['name'], $uid); out(['ok' => true]); }
    if ($method === 'POST' && $act === 'delivery') {
        $items = []; foreach (array_slice((array)($b['items'] ?? []), 0, 100) as $i) { $dsc = fx_s($i['description'] ?? '', 500); if (!$dsc) continue; $items[] = ['description' => $dsc, 'qty' => fx_num($i['qty'] ?? 1, 1), 'unit' => fx_s($i['unit'] ?? '', 30)]; }
        if (!$items && $r['quote_doc_id']) { $q = fx_doc((int)$r['quote_doc_id']); foreach (($q['items'] ?? []) as $i) { if (trim((string)$i['description']) === '') continue; $items[] = ['description' => (string)$i['description'], 'qty' => (float)$i['qty'], 'unit' => (string)($i['unit'] ?? '')]; } }
        if (!$items) fail('Add what is being delivered (or create the quotation first so its lines can be used)');
        $dd = $b['delivery_date'] ?? null; $dd = $dd && strtotime((string)$dd) ? date('Y-m-d', strtotime((string)$dd)) : date('Y-m-d'); $n = (int)(one('SELECT COUNT(*) n FROM delivery_notes WHERE number LIKE ?', ['DN-' . date('Y') . '-%'])['n'] ?? 0) + 1; do { $num = 'DN-' . date('Y') . '-' . str_pad((string)$n++, 4, '0', STR_PAD_LEFT); } while (one('SELECT id FROM delivery_notes WHERE number=?', [$num]));
        $q = $r['quote_doc_id'] ? fx_doc((int)$r['quote_doc_id']) : null;
        q('INSERT INTO delivery_notes (number,rfq_id,quote_doc_id,client_name,client_address,po_number,rfq_code,delivery_date,delivery_address,items,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$num, $rid, $r['quote_doc_id'] ?: null, $r['client_name'], fx_s($b['client_address'] ?? ($q['client_address'] ?? ''), 800), $r['po_number'], $r['code'], $dd, fx_s($b['delivery_address'] ?? ($q['ship_to'] ?? ''), 500), json_encode($items, JSON_UNESCAPED_UNICODE), fx_s($b['notes'] ?? '', 1000), $uid]);
        $id = (int)db()->lastInsertId(); q("UPDATE rfqs SET delivery_date=?, status=IF(status IN ('paid','lost','closed'), status, 'delivered') WHERE id=?", [$dd, $rid]); if ($r['po_task_id']) q("UPDATE tasks SET status='done', completed_at=NOW() WHERE id=? AND status<>'done'", [(int)$r['po_task_id']]);
        rfq_event($rid, 'delivery', 'Delivery note ' . $num . ' created · delivery date ' . $dd, $uid); out(['ok' => true, 'id' => $id, 'number' => $num], 201);
    }
    if ($method === 'POST' && $act === 'payment') {
        $dn = one('SELECT id,printed_at FROM delivery_notes WHERE rfq_id=? AND printed_at IS NOT NULL ORDER BY id DESC LIMIT 1', [$rid]); if (!$dn) fail('Print the delivery note first (for the client to sign), then confirm the payment here.', 409);
        $amt = fx_num($b['amount'] ?? ($r['quote_total'] ?? 0)); if ($amt <= 0) fail('Type the amount received'); $dt = $b['date'] ?? null; $dt = $dt && strtotime((string)$dt) ? date('Y-m-d', strtotime((string)$dt)) : date('Y-m-d');
        q("UPDATE rfqs SET status='paid', paid_at=?, paid_amount=?, pay_ref=? WHERE id=?", [$dt, $amt, fx_s($b['reference'] ?? '', 120), $rid]); rfq_event($rid, 'paid', 'Payment confirmed: ' . number_format($amt, 2) . ' ' . ($r['currency'] ?: '') . ' on ' . $dt . ' · sale recorded', $uid); log_act($uid, 'rfq_sale', $r['code'] . ' ' . $amt); out(['ok' => true]);
    }
    fail('Not found', 404);
}
if ($sub === 'delivery') {
    $did = isset($seg[2]) && ctype_digit($seg[2]) ? (int)$seg[2] : 0; $act = $seg[3] ?? ''; $d = $did ? one('SELECT d.*, u.name AS signed_by_name, c.name AS created_by_name FROM delivery_notes d LEFT JOIN users u ON u.id=d.signed_by LEFT JOIN users c ON c.id=d.created_by WHERE d.id=?', [$did]) : null; if (!$d) fail('Not found', 404);
    if ($method === 'GET' && $act === 'docx') { require_once __DIR__ . '/docxout.php'; [$bytes, $fn] = dn_docx($d); header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document'); header('Content-Disposition: attachment; filename="' . $fn . '"'); header('Content-Length: ' . strlen($bytes)); header('Cache-Control: private, no-store'); echo $bytes; exit; }
    if ($method === 'GET' && $act === '') { $co = one('SELECT company_name,address,email,phone,tax_id,website,rc_number FROM fin_settings WHERE id=1') ?: []; out(['note' => dn_dto($d), 'company' => $co]); }
    if ($method === 'PATCH' && $act === '') { if ($d['signed_at']) fail('A signed delivery note cannot be changed. It is locked.', 409); $items = $d['items']; if (isset($b['items'])) { $it = []; foreach (array_slice((array)$b['items'], 0, 100) as $i) { $dsc = fx_s($i['description'] ?? '', 500); if ($dsc) $it[] = ['description' => $dsc, 'qty' => fx_num($i['qty'] ?? 1, 1), 'unit' => fx_s($i['unit'] ?? '', 30)]; } if ($it) $items = json_encode($it, JSON_UNESCAPED_UNICODE); }
        $dd = array_key_exists('delivery_date', $b) && strtotime((string)$b['delivery_date']) ? date('Y-m-d', strtotime((string)$b['delivery_date'])) : $d['delivery_date'];
        q('UPDATE delivery_notes SET delivery_date=?, delivery_address=?, client_address=?, notes=?, items=? WHERE id=?', [$dd, array_key_exists('delivery_address', $b) ? fx_s($b['delivery_address'], 500) : $d['delivery_address'], array_key_exists('client_address', $b) ? fx_s($b['client_address'], 800) : $d['client_address'], array_key_exists('notes', $b) ? fx_s($b['notes'], 1000) : $d['notes'], $items, $did]);
        if ($d['rfq_id']) q('UPDATE rfqs SET delivery_date=? WHERE id=?', [$dd, (int)$d['rfq_id']]); out(['ok' => true]); }
    if ($method === 'POST' && $act === 'sign') { if ($d['signed_at']) fail('Already signed', 409); $sg = one('SELECT image FROM user_signatures WHERE user_id=?', [$uid]); if (!$sg) fail('Save your signature first (Finance → Approvals → My signature), then sign.', 409);
        q('UPDATE delivery_notes SET signed_by=?, signed_at=NOW(), signature=? WHERE id=?', [$uid, $sg['image'], $did]); if ($d['rfq_id']) rfq_event((int)$d['rfq_id'], 'signed', 'Delivery note ' . $d['number'] . ' signed by ' . $u['name'], $uid); out(['ok' => true]); }
    if ($method === 'POST' && $act === 'printed') { q('UPDATE delivery_notes SET printed_at=IFNULL(printed_at,NOW()), printed_by=IFNULL(printed_by,?) WHERE id=?', [$uid, $did]); if ($d['rfq_id'] && !$d['printed_at']) rfq_event((int)$d['rfq_id'], 'printed', 'Delivery note ' . $d['number'] . ' printed for signing · confirm the payment when it comes in', $uid); out(['ok' => true]); }
    fail('Not found', 404);
}
