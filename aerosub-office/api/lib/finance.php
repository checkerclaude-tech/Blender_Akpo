<?php
declare(strict_types=1);
// Routes: /api/finance/*  (commercials, proformas, invoices, receipts per RFQ/PO),  /api/catalog/* (products & services),  /api/news/* (banners)
$u = need_user(); $uid = (int)$u['id']; $role = $u['role']; $b = $method === 'GET' ? [] : body();
$fin = can_finance($u); $ro = $role === 'founder'; $sid = isset($seg[2]) && ctype_digit($seg[2]) ? (int)$seg[2] : 0; $act = $sid ? ($seg[3] ?? '') : ($seg[2] ?? '');
function fx_s($v, int $n = 500): ?string { $v = trim((string)($v ?? '')); return $v === '' ? null : mb_substr($v, 0, $n); }
function fx_num($v, float $def = 0): float { return is_numeric($v) ? round((float)$v, 3) : $def; }
/** The sign shown before amounts (₦ $ £ € or whatever the user types). Empty = use the usual sign for the currency code. */
function fx_sym($v): ?string { $v = (string)($v ?? ''); $v = preg_replace('/[\x00-\x1F]/', '', $v) ?? ''; $v = trim(str_replace(['<', '>', '"', "'", '&', '\\'], '', $v)); return $v === '' ? null : mb_substr($v, 0, 8); }
function fx_cur($v): string { $v = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$v)); return strlen($v) >= 2 && strlen($v) <= 5 ? $v : 'USD'; }
const FX_DAILY = ['day rate', 'per day', 'daily'];
/** [shown, counted] for one line. On a commercial, a "Day rate" line counts qty x rate x days, "As incurred / Optional / As applicable" lines are shown but not counted. */
function fx_line(array $i, string $type, int $days): array {
    $base = round((float)$i['qty'] * (float)$i['unit_price'], 2);
    if ($type !== 'commercial') return [$base, $base];
    if (trim((string)($i['amount_note'] ?? '')) !== '') return [0.0, 0.0];
    return [$base, in_array(strtolower(trim((string)($i['freq'] ?? ''))), FX_DAILY, true) ? round($base * max(1, $days), 2) : $base];
}
/** SQL for the same sum, for lists (needs the fin_docs alias d). */
function fx_sub_sql(): string {
    return "(SELECT COALESCE(SUM(CASE WHEN d.doc_type='commercial' THEN (CASE WHEN COALESCE(i.amount_note,'')<>'' THEN 0 WHEN LOWER(TRIM(COALESCE(i.freq,''))) IN ('day rate','per day','daily') THEN i.qty*i.unit_price*GREATEST(d.days,1) ELSE i.qty*i.unit_price END) ELSE i.qty*i.unit_price END),0) FROM fin_items i WHERE i.doc_id=d.id)";
}
function fx_totals(array $items, float $discount, float $rate, string $type = 'invoice', int $days = 1): array {
    $sub = 0.0; foreach ($items as $i) $sub += fx_line($i, $type, $days)[1];
    $disc = min(max(0, $discount), $sub); $net = $sub - $disc; $tax = round($net * $rate / 100, 2); return ['subtotal' => round($sub, 2), 'discount' => round($disc, 2), 'tax' => $tax, 'total' => round($net + $tax, 2)];
}
function fx_settings(): array { return one('SELECT * FROM fin_settings WHERE id=1') ?: ['currency' => 'USD', 'tax_label' => 'VAT', 'tax_rate' => 0]; }
function fx_number(string $type): string {
    if ($type === 'invoice') { // plain running number (100026706, 100026707, ...) like the invoice template; Finance can change the next number in Company details
        try { for ($k = 0; $k < 20; $k++) { q('UPDATE fin_settings SET inv_next = LAST_INSERT_ID(inv_next) + 1 WHERE id=1'); $n = (int)one('SELECT LAST_INSERT_ID() n')['n']; if ($n > 0 && !one('SELECT 1 FROM fin_docs WHERE number=?', [(string)$n])) return (string)$n; } } catch (Throwable $e) {}
    }
    $pre = ['commercial' => 'COM', 'proforma' => 'PRO', 'invoice' => 'INV', 'receipt' => 'REC'][$type]; $y = date('Y');
    $r = one("SELECT MAX(CAST(SUBSTRING_INDEX(number,'-',-1) AS UNSIGNED)) m FROM fin_docs WHERE number LIKE ?", ["$pre-$y-%"]); return sprintf('%s-%s-%04d', $pre, $y, ((int)($r['m'] ?? 0)) + 1);
}
function fx_task_visible(array $u, int $tid): bool {
    $t = one('SELECT * FROM tasks WHERE id=?', [$tid]); if (!$t) return false; $id = (int)$u['id'];
    if (in_array($u['role'], ['super_admin', 'founder'], true) || can_finance($u)) return true;
    if ((int)$t['assignee_id'] === $id || (int)$t['creator_id'] === $id) return true;
    if (one('SELECT 1 FROM task_shares WHERE task_id=? AND user_id=?', [$tid, $id])) return true;
    return $u['role'] === 'manager' && $t['department_id'] && in_array((int)$t['department_id'], array_map('intval', $u['department_ids'] ?? []), true);
}
function fx_doc(int $id, bool $items = true): ?array {
    $d = one('SELECT d.*, u.name AS created_by_name, t.title AS task_title FROM fin_docs d LEFT JOIN users u ON u.id=d.created_by LEFT JOIN tasks t ON t.id=d.task_id WHERE d.id=?', [$id]); if (!$d) return null;
    $d['items'] = $items ? all('SELECT description,qty,unit,unit_price,category,freq,amount_note FROM fin_items WHERE doc_id=? ORDER BY position,id', [$id]) : [];
    $d['totals'] = fx_totals($d['items'], (float)$d['discount'], (float)$d['tax_rate'], (string)$d['doc_type'], (int)$d['days']);
    return $d;
}
function fx_clean_items($raw): array {
    $out = []; foreach (array_slice((array)$raw, 0, 100) as $i) { $desc = fx_s($i['description'] ?? '', 500); if (!$desc) continue; $note = fx_s($i['amount_note'] ?? '', 40); $qty = fx_num($i['qty'] ?? 1, 1); if (($qty <= 0 && !$note) || $qty < 0 || $qty > 1e7) fail('Quantity must be more than zero');
        $price = fx_num($i['unit_price'] ?? 0); if ($price < 0 || $price > 1e10) fail('Unit price is not valid'); $out[] = ['description' => $desc, 'qty' => $qty, 'unit' => fx_s($i['unit'] ?? '', 30), 'unit_price' => $price, 'category' => fx_s($i['category'] ?? '', 120), 'freq' => fx_s($i['freq'] ?? '', 40), 'amount_note' => $note]; }
    return $out;
}
function fx_save_items(int $id, array $items): void { q('DELETE FROM fin_items WHERE doc_id=?', [$id]); foreach ($items as $n => $i) q('INSERT INTO fin_items (doc_id,position,description,qty,unit,unit_price,category,freq,amount_note) VALUES (?,?,?,?,?,?,?,?,?)', [$id, $n, $i['description'], $i['qty'], $i['unit'], $i['unit_price'], $i['category'] ?? null, $i['freq'] ?? null, $i['amount_note'] ?? null]); }
/** The template fields (attention, customer id, ship to, lead time, project title, days ...). Only fields present in $b change. */
function fx_save_extra(int $id, array $b): void {
    $f = ['customer_id' => 60, 'sales_rep' => 80, 'sales_email' => 120, 'sales_tel' => 60, 'attention' => 120, 'terms_instr' => 120, 'payment_terms' => 120, 'ship_to' => 500, 'shipping_method' => 80, 'delivery_leadtime' => 120, 'subject' => 300, 'intro' => 3000];
    $sets = []; $vals = [];
    foreach ($f as $k => $n) if (array_key_exists($k, $b)) { $sets[] = "$k=?"; $vals[] = fx_s($b[$k], $n); }
    if (array_key_exists('currency_symbol', $b)) { $sets[] = 'currency_symbol=?'; $vals[] = fx_sym($b['currency_symbol']); }
    if (array_key_exists('days', $b)) { $sets[] = 'days=?'; $vals[] = max(1, min(365, (int)$b['days'])); }
    if ($sets) { $vals[] = $id; q('UPDATE fin_docs SET ' . implode(',', $sets) . ' WHERE id=?', $vals); }
}
function fx_date($v): ?string { $v = trim((string)($v ?? '')); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; }

/* =============== FINANCE =============== */
if ($seg[0] === 'finance') {
    $sub = $seg[1] ?? '';
    if ($sub === 'settings') {
        if (!$fin && !in_array($role, ['founder'], true)) fail('Not allowed', 403);
        if ($method === 'GET') out(['settings' => fx_settings(), 'can_edit' => $fin]);
        if (!$fin) fail('Not allowed', 403); $cur = fx_cur($b['currency'] ?? 'USD'); $rate = max(0, min(100, fx_num($b['tax_rate'] ?? 0)));
        q('UPDATE fin_settings SET company_name=?,address=?,email=?,phone=?,tax_id=?,bank_details=?,currency=?,tax_label=?,tax_rate=?,terms=?,footer=? WHERE id=1', [fx_s($b['company_name'] ?? '', 160), fx_s($b['address'] ?? '', 800), fx_s($b['email'] ?? '', 190), fx_s($b['phone'] ?? '', 60), fx_s($b['tax_id'] ?? '', 80), fx_s($b['bank_details'] ?? '', 1200), $cur, fx_s($b['tax_label'] ?? 'VAT', 30) ?: 'VAT', $rate, fx_s($b['terms'] ?? '', 2000), fx_s($b['footer'] ?? '', 300)]);
        $nx = (int)preg_replace('/\D/', '', (string)($b['inv_next'] ?? ''));
        q('UPDATE fin_settings SET currency_symbol=?,bank_name=?,account_name=?,account_number=?,website=?,rc_number=? WHERE id=1', [fx_sym($b['currency_symbol'] ?? ''), fx_s($b['bank_name'] ?? '', 120), fx_s($b['account_name'] ?? '', 160), fx_s($b['account_number'] ?? '', 60), fx_s($b['website'] ?? '', 120), fx_s($b['rc_number'] ?? '', 40)]);
        if ($nx > 0) { $top = (int)(one("SELECT MAX(CAST(number AS UNSIGNED)) m FROM fin_docs WHERE doc_type='invoice' AND number REGEXP '^[0-9]{6,}$'")['m'] ?? 0); if ($nx <= $top) fail('The next invoice number must be higher than ' . $top . ', which is already used.'); q('UPDATE fin_settings SET inv_next=? WHERE id=1', [$nx]); }
        out(['ok' => true]);
    }
    if ($sub === 'summary') { if (!$fin && !$ro) fail('Not allowed', 403);
        $rows = all("SELECT d.id,d.doc_type,d.status,d.discount,d.tax_rate,d.amount_paid,d.currency,d.issue_date," . fx_sub_sql() . " AS sub FROM fin_docs d WHERE d.status<>'void'");
        $o = ['outstanding' => [], 'paid_month' => [], 'counts' => ['commercial' => 0, 'proforma' => 0, 'invoice' => 0, 'receipt' => 0], 'unpaid_invoices' => 0];
        foreach ($rows as $r) { $o['counts'][$r['doc_type']]++; $net = (float)$r['sub'] - min((float)$r['discount'], (float)$r['sub']); $tot = round($net + $net * (float)$r['tax_rate'] / 100, 2); $c = $r['currency'];
            if ($r['doc_type'] === 'invoice' && in_array($r['status'], ['draft', 'sent'], true)) { $paid = (float)(one("SELECT COALESCE(SUM(amount_paid),0) s FROM fin_docs WHERE doc_type='receipt' AND parent_id=? AND status<>'void'", [$r['id']])['s'] ?? 0); $o['outstanding'][$c] = round(($o['outstanding'][$c] ?? 0) + max(0, $tot - $paid), 2); if ($r['status'] === 'sent') $o['unpaid_invoices']++; }
            if ($r['doc_type'] === 'receipt' && substr((string)$r['issue_date'], 0, 7) === date('Y-m')) $o['paid_month'][$c] = round(($o['paid_month'][$c] ?? 0) + (float)$r['amount_paid'], 2); }
        out($o); }
    if ($sub === 'docs' || $sub === 'doc') {
        if (!$sid && $method === 'GET') {
            $w = ['1=1']; $p = [];
            if (!empty($_GET['task_id'])) { $tid = (int)$_GET['task_id']; if (!fx_task_visible($u, $tid)) fail('Not found', 404); $w[] = 'd.task_id=?'; $p[] = $tid; }
            elseif (!$fin && !$ro) fail('Not allowed', 403);
            foreach (['type' => 'doc_type', 'status' => 'status'] as $g => $col) if (!empty($_GET[$g])) { $w[] = "d.$col=?"; $p[] = $_GET[$g]; }
            if (!empty($_GET['q'])) { $w[] = '(d.number LIKE ? OR d.client_name LIKE ? OR d.po_number LIKE ? OR d.rfq_ref LIKE ?)'; $qq = '%' . mb_substr((string)$_GET['q'], 0, 60) . '%'; array_push($p, $qq, $qq, $qq, $qq); }
            $rows = all('SELECT d.id,d.doc_type,d.number,d.status,d.client_name,d.po_number,d.rfq_ref,d.issue_date,d.due_date,d.currency,d.currency_symbol,d.task_id,d.parent_id,d.amount_paid,d.discount,d.tax_rate,' . fx_sub_sql() . ' AS sub FROM fin_docs d WHERE ' . implode(' AND ', $w) . ' ORDER BY d.id DESC LIMIT 300', $p);
            foreach ($rows as &$r) { $net = (float)$r['sub'] - min((float)$r['discount'], (float)$r['sub']); $r['total'] = $r['doc_type'] === 'receipt' ? (float)$r['amount_paid'] : round($net + $net * (float)$r['tax_rate'] / 100, 2); unset($r['sub']); } unset($r);
            out(['docs' => $rows, 'can_edit' => $fin]);
        }
        if ($method === 'POST' && !$sid) {
            if (!$fin) fail('Only Finance can create these documents', 403); $st = fx_settings();
            $type = in((string)($b['doc_type'] ?? ''), ['commercial', 'proforma', 'invoice'], ''); if ($type === '') fail('Choose the document type');
            $client = fx_s($b['client_name'] ?? '', 190); if (!$client) fail('Enter the client name'); $items = fx_clean_items($b['items'] ?? []); if (!$items) fail('Add at least one line item');
            $tid = !empty($b['task_id']) ? (int)$b['task_id'] : null; if ($tid && !one('SELECT id FROM tasks WHERE id=?', [$tid])) $tid = null;
            $cid = !empty($b['company_id']) && one('SELECT id FROM companies WHERE id=?', [(int)$b['company_id']]) ? (int)$b['company_id'] : null;
            $issue = fx_date($b['issue_date'] ?? null) ?: date('Y-m-d');
            for ($try = 0; $try < 5; $try++) { try {
                q('INSERT INTO fin_docs (doc_type,number,task_id,company_id,po_number,rfq_ref,client_name,client_address,client_email,currency,issue_date,due_date,valid_until,tax_label,tax_rate,discount,notes,terms,parent_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                  [$type, fx_number($type), $tid, $cid, fx_s($b['po_number'] ?? '', 80), fx_s($b['rfq_ref'] ?? '', 80), $client, fx_s($b['client_address'] ?? '', 800), fx_s($b['client_email'] ?? '', 190), fx_cur($b['currency'] ?? $st['currency']), $issue, fx_date($b['due_date'] ?? null), fx_date($b['valid_until'] ?? null),
                   fx_s($b['tax_label'] ?? $st['tax_label'], 30) ?: 'VAT', max(0, min(100, fx_num($b['tax_rate'] ?? $st['tax_rate']))), max(0, fx_num($b['discount'] ?? 0)), fx_s($b['notes'] ?? '', 3000), fx_s($b['terms'] ?? $st['terms'], 3000), null, $uid]); break; }
                catch (PDOException $e) { if ($try === 4 || strpos($e->getMessage(), 'uq_number') === false) throw $e; } }
            $nid = (int)db()->lastInsertId(); fx_save_items($nid, $items); fx_save_extra($nid, $b); log_act($uid, 'fin_created', $type . ' ' . (fx_doc($nid, false)['number'] ?? ''));
            out(['id' => $nid, 'number' => fx_doc($nid, false)['number']], 201);
        }
        if (!$sid) fail('Not found', 404);
        $d = fx_doc($sid); if (!$d) fail('Not found', 404);
        if (!$fin && !$ro && !($d['task_id'] && fx_task_visible($u, (int)$d['task_id']))) fail('Not found', 404);
        if ($method === 'GET' && $act === '') { $d['seller'] = fx_settings(); $d['can_edit'] = $fin; $d['children'] = all('SELECT id,doc_type,number,status FROM fin_docs WHERE parent_id=? ORDER BY id', [$sid]);
            $d['parent'] = $d['parent_id'] ? one('SELECT id,doc_type,number FROM fin_docs WHERE id=?', [$d['parent_id']]) : null;
            if ($d['doc_type'] === 'invoice') { $d['paid_total'] = (float)one("SELECT COALESCE(SUM(amount_paid),0) s FROM fin_docs WHERE doc_type='receipt' AND parent_id=? AND status<>'void'", [$sid])['s']; $d['balance'] = round($d['totals']['total'] - $d['paid_total'], 2); }
            out($d); }
        if (!$fin) fail('Only Finance can change these documents', 403);
        if ($method === 'PATCH' && $act === '') {
            if ($d['status'] === 'void') fail('This document is void'); if ($d['doc_type'] === 'receipt') fail('Receipts cannot be edited; void it and issue a new one');
            if ($d['status'] === 'paid') fail('A paid invoice cannot be edited');
            $items = array_key_exists('items', $b) ? fx_clean_items($b['items']) : null; if ($items !== null && !$items) fail('Add at least one line item');
            $client = array_key_exists('client_name', $b) ? fx_s($b['client_name'], 190) : $d['client_name']; if (!$client) fail('Enter the client name');
            q('UPDATE fin_docs SET client_name=?,client_address=?,client_email=?,po_number=?,rfq_ref=?,currency=?,issue_date=?,due_date=?,valid_until=?,tax_label=?,tax_rate=?,discount=?,notes=?,terms=? WHERE id=?',
              [$client, array_key_exists('client_address', $b) ? fx_s($b['client_address'], 800) : $d['client_address'], array_key_exists('client_email', $b) ? fx_s($b['client_email'], 190) : $d['client_email'], array_key_exists('po_number', $b) ? fx_s($b['po_number'], 80) : $d['po_number'], array_key_exists('rfq_ref', $b) ? fx_s($b['rfq_ref'], 80) : $d['rfq_ref'],
               array_key_exists('currency', $b) ? fx_cur($b['currency']) : $d['currency'], fx_date($b['issue_date'] ?? null) ?: $d['issue_date'], array_key_exists('due_date', $b) ? fx_date($b['due_date']) : $d['due_date'], array_key_exists('valid_until', $b) ? fx_date($b['valid_until']) : $d['valid_until'],
               array_key_exists('tax_label', $b) ? (fx_s($b['tax_label'], 30) ?: 'VAT') : $d['tax_label'], array_key_exists('tax_rate', $b) ? max(0, min(100, fx_num($b['tax_rate']))) : $d['tax_rate'], array_key_exists('discount', $b) ? max(0, fx_num($b['discount'])) : $d['discount'],
               array_key_exists('notes', $b) ? fx_s($b['notes'], 3000) : $d['notes'], array_key_exists('terms', $b) ? fx_s($b['terms'], 3000) : $d['terms'], $sid]);
            if ($items !== null) fx_save_items($sid, $items); fx_save_extra($sid, $b); out(['ok' => true]);
        }
        if ($method === 'POST' && $act === 'status') { $ns = in((string)($b['status'] ?? ''), ['draft', 'sent', 'void'], ''); if ($ns === '') fail('Bad status'); if ($d['status'] === 'paid' && $ns !== 'void') fail('This invoice is already paid'); q('UPDATE fin_docs SET status=? WHERE id=?', [$ns, $sid]);
            if ($ns === 'void' && $d['doc_type'] === 'receipt' && $d['parent_id']) { $paid = (float)one("SELECT COALESCE(SUM(amount_paid),0) s FROM fin_docs WHERE doc_type='receipt' AND parent_id=? AND status<>'void'", [$d['parent_id']])['s']; $par = fx_doc((int)$d['parent_id']); if ($par && $par['status'] === 'paid' && $paid < $par['totals']['total'] - 0.005) q("UPDATE fin_docs SET status='sent' WHERE id=?", [$d['parent_id']]); }
            log_act($uid, 'fin_' . $ns, $d['number']); out(['ok' => true]); }
        if ($method === 'POST' && $act === 'convert') {
            $to = in((string)($b['to'] ?? ''), ['proforma', 'invoice'], ''); $ok = ['commercial' => ['proforma', 'invoice'], 'proforma' => ['invoice']][$d['doc_type']] ?? []; if ($to === '' || !in_array($to, $ok, true)) fail('That conversion is not available');
            if ($d['status'] === 'void') fail('This document is void');
            for ($try = 0; $try < 5; $try++) { try {
                q('INSERT INTO fin_docs (doc_type,number,task_id,company_id,po_number,rfq_ref,client_name,client_address,client_email,currency,issue_date,due_date,tax_label,tax_rate,discount,notes,terms,parent_id,created_by) SELECT ?,?,task_id,company_id,po_number,rfq_ref,client_name,client_address,client_email,currency,?,?,tax_label,tax_rate,discount,notes,terms,id,? FROM fin_docs WHERE id=?',
                  [$to, fx_number($to), date('Y-m-d'), $to === 'invoice' ? date('Y-m-d', strtotime('+30 days')) : null, $uid, $sid]); break; } catch (PDOException $e) { if ($try === 4 || strpos($e->getMessage(), 'uq_number') === false) throw $e; } }
            $nid = (int)db()->lastInsertId();
            q('UPDATE fin_docs SET currency_symbol=?, sales_email=?, sales_tel=?, subject=?, attention=?, customer_id=?, sales_rep=?, terms_instr=?, payment_terms=?, ship_to=?, shipping_method=?, delivery_leadtime=?, valid_until=? WHERE id=?', [$d['currency_symbol'], $d['sales_email'], $d['sales_tel'], $d['subject'], $d['attention'], $d['customer_id'], $d['sales_rep'], $d['terms_instr'], $d['payment_terms'], $d['ship_to'], $d['shipping_method'], $d['delivery_leadtime'], $d['valid_until'], $nid]);
            if ($d['doc_type'] === 'commercial') { // an invoice bills what is counted: day-rate lines become qty x days, "as incurred / optional" lines are left out
                $pos = 0; foreach ($d['items'] as $i) { if (trim((string)$i['amount_note']) !== '') continue;
                    $mult = in_array(strtolower(trim((string)$i['freq'])), FX_DAILY, true) ? max(1, (int)$d['days']) : 1;
                    q('INSERT INTO fin_items (doc_id,position,description,qty,unit,unit_price) VALUES (?,?,?,?,?,?)', [$nid, $pos++, $i['description'] . ($mult > 1 ? ' (' . $mult . ' days)' : ''), (float)$i['qty'] * $mult, $i['unit'], $i['unit_price']]); }
            } else q('INSERT INTO fin_items (doc_id,position,description,qty,unit,unit_price) SELECT ?,position,description,qty,unit,unit_price FROM fin_items WHERE doc_id=?', [$nid, $sid]);
            log_act($uid, 'fin_converted', $d['number'] . ' → ' . $to); out(['id' => $nid, 'number' => fx_doc($nid, false)['number']], 201);
        }
        if ($method === 'POST' && $act === 'pay') {
            if ($d['doc_type'] !== 'invoice') fail('Payments are recorded on invoices'); if ($d['status'] === 'void') fail('This invoice is void');
            $paid = (float)one("SELECT COALESCE(SUM(amount_paid),0) s FROM fin_docs WHERE doc_type='receipt' AND parent_id=? AND status<>'void'", [$sid])['s']; $bal = round($d['totals']['total'] - $paid, 2);
            $amt = round(fx_num($b['amount'] ?? 0), 2); if ($amt <= 0) fail('Enter the amount received'); if ($amt > $bal + 0.005) fail('That is more than the balance of ' . number_format($bal, 2));
            for ($try = 0; $try < 5; $try++) { try {
                q("INSERT INTO fin_docs (doc_type,number,task_id,company_id,po_number,rfq_ref,client_name,client_address,client_email,currency,issue_date,status,tax_label,tax_rate,discount,notes,parent_id,amount_paid,pay_method,pay_ref,created_by) SELECT 'receipt',?,task_id,company_id,po_number,rfq_ref,client_name,client_address,client_email,currency,?,'paid',tax_label,0,0,NULL,id,?,?,?,? FROM fin_docs WHERE id=?",
                  [fx_number('receipt'), fx_date($b['date'] ?? null) ?: date('Y-m-d'), $amt, fx_s($b['method'] ?? '', 60), fx_s($b['reference'] ?? '', 120), $uid, $sid]); break; } catch (PDOException $e) { if ($try === 4 || strpos($e->getMessage(), 'uq_number') === false) throw $e; } }
            $rid = (int)db()->lastInsertId(); q('UPDATE fin_docs SET currency_symbol=? WHERE id=?', [$d['currency_symbol'], $rid]); q('INSERT INTO fin_items (doc_id,position,description,qty,unit,unit_price) VALUES (?,?,?,?,?,?)', [$rid, 0, 'Payment received against invoice ' . $d['number'] . ($d['po_number'] ? ' (PO ' . $d['po_number'] . ')' : ''), 1, null, $amt]);
            if (abs($bal - $amt) < 0.005) q("UPDATE fin_docs SET status='paid' WHERE id=?", [$sid]); log_act($uid, 'fin_payment', $d['number'] . ' ' . $amt);
            out(['id' => $rid, 'number' => fx_doc($rid, false)['number'], 'fully_paid' => abs($bal - $amt) < 0.005], 201);
        }
        if ($method === 'DELETE') { if ($d['status'] !== 'draft') fail('Only drafts can be deleted. Void it instead.'); if ($d['doc_type'] === 'receipt') fail('Receipts cannot be deleted'); q('DELETE FROM fin_docs WHERE id=?', [$sid]); out(['ok' => true]); }
    }
    fail('Not found', 404);
}

/* =============== PRODUCTS & SERVICES =============== */
if ($seg[0] === 'catalog') {
    $edit = $fin || in_array($role, ['super_admin', 'manager'], true); $id = isset($seg[1]) && ctype_digit($seg[1]) ? (int)$seg[1] : 0;
    if ($method === 'GET' && !$id) out(['items' => all('SELECT * FROM products' . ($edit ? '' : ' WHERE published=1') . ' ORDER BY kind, category, name LIMIT 500'), 'can_edit' => $edit]);
    if (!$edit) fail('Only managers, Finance and admins can change the catalogue', 403);
    $f = function (array $b, ?array $o): array { $name = fx_s($b['name'] ?? ($o['name'] ?? ''), 190); if (!$name) fail('Name is required'); $price = ($b['price'] ?? ($o['price'] ?? '')) === '' || ($b['price'] ?? ($o['price'] ?? null)) === null ? null : max(0, fx_num($b['price'] ?? $o['price']));
        $img = fx_s($b['image_url'] ?? ($o['image_url'] ?? ''), 500); if ($img && !preg_match('~^https://~i', $img)) fail('The picture link must start with https://');
        return [in((string)($b['kind'] ?? ($o['kind'] ?? '')), ['product', 'service'], 'service'), $name, fx_s($b['sku'] ?? ($o['sku'] ?? ''), 60), fx_s($b['category'] ?? ($o['category'] ?? ''), 80), fx_s($b['description'] ?? ($o['description'] ?? ''), 3000), fx_s($b['unit'] ?? ($o['unit'] ?? ''), 30), $price, fx_cur($b['currency'] ?? ($o['currency'] ?? 'USD')), (int)(bool)($b['published'] ?? ($o['published'] ?? 1)), $img]; };
    if ($method === 'POST' && !$id) { q('INSERT INTO products (kind,name,sku,category,description,unit,price,currency,published,image_url,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)', array_merge($f($b, null), [$uid])); out(['id' => (int)db()->lastInsertId()], 201); }
    if ($id) { $o = one('SELECT * FROM products WHERE id=?', [$id]); if (!$o) fail('Not found', 404);
        if ($method === 'PATCH') { q('UPDATE products SET kind=?,name=?,sku=?,category=?,description=?,unit=?,price=?,currency=?,published=?,image_url=? WHERE id=?', array_merge($f($b, $o), [$id])); out(['ok' => true]); }
        if ($method === 'DELETE') { q('DELETE FROM products WHERE id=?', [$id]); out(['ok' => true]); } }
    fail('Not found', 404);
}

/* =============== NEWS BANNERS =============== */
if ($seg[0] === 'news') {
    $mine = array_map('intval', $u['department_ids'] ?? []); $id = isset($seg[1]) && ctype_digit($seg[1]) ? (int)$seg[1] : 0; $isAdm = $role === 'super_admin'; $canPost = $isAdm || $role === 'manager';
    if ($method === 'GET' && ($seg[1] ?? '') === 'manage') { if (!$canPost) fail('Not allowed', 403); $w = $isAdm ? '1=1' : 'b.created_by=' . $uid . ($mine ? ' OR b.department_id IN (' . implode(',', $mine) . ')' : '');
        out(all("SELECT b.*, d.name AS department, u.name AS author FROM banners b LEFT JOIN departments d ON d.id=b.department_id LEFT JOIN users u ON u.id=b.created_by WHERE $w ORDER BY b.id DESC LIMIT 100")); }
    if ($method === 'GET' && !$id) { $dl = $mine ? 'b.department_id IS NULL OR b.department_id IN (' . implode(',', $mine) . ')' : 'b.department_id IS NULL'; if (in_array($role, ['super_admin', 'founder'], true)) $dl = '1=1';
        out(all("SELECT b.id,b.title,b.body,b.link,b.level,b.created_at,b.department_id,d.name AS department FROM banners b LEFT JOIN departments d ON d.id=b.department_id WHERE b.active=1 AND (b.starts_on IS NULL OR b.starts_on<=CURDATE()) AND (b.ends_on IS NULL OR b.ends_on>=CURDATE()) AND ($dl) ORDER BY b.id DESC LIMIT 20")); }
    if (!$canPost) fail('Only managers and admins can post news', 403);
    $f = function (array $b, ?array $o) use ($isAdm, $mine): array { $t = fx_s($b['title'] ?? ($o['title'] ?? ''), 200); if (!$t) fail('Give the banner a headline');
        $dept = array_key_exists('department_id', $b) ? ((int)$b['department_id'] ?: null) : ($o['department_id'] ?? null); if (!$isAdm && (!$dept || !in_array((int)$dept, $mine, true))) fail('Managers can post to their own departments; company-wide news is for Super Admins', 403);
        if ($dept && !one('SELECT id FROM departments WHERE id=?', [$dept])) $dept = null; $link = fx_s($b['link'] ?? ($o['link'] ?? ''), 500); if ($link && !preg_match('~^(https?://|#/)~i', $link)) fail('The link must start with https:// or #/');
        return [$t, fx_s($b['body'] ?? ($o['body'] ?? ''), 2000), $link, in((string)($b['level'] ?? ($o['level'] ?? '')), ['info', 'success', 'warning'], 'info'), $dept, fx_date($b['starts_on'] ?? ($o['starts_on'] ?? null)), fx_date($b['ends_on'] ?? ($o['ends_on'] ?? null)), (int)(bool)($b['active'] ?? ($o['active'] ?? 1))]; };
    if ($method === 'POST' && !$id) { $v = $f($b, null); q('INSERT INTO banners (title,body,link,level,department_id,starts_on,ends_on,active,created_by) VALUES (?,?,?,?,?,?,?,?,?)', array_merge($v, [$uid])); $nid = (int)db()->lastInsertId();
        if (!empty($b['notify'])) { $to = $v[4] ? array_column(all('SELECT DISTINCT u.id FROM users u LEFT JOIN user_departments ud ON ud.user_id=u.id WHERE u.active=1 AND (u.department_id=? OR ud.department_id=?)', [$v[4], $v[4]]), 'id') : array_column(all('SELECT id FROM users WHERE active=1'), 'id'); foreach ($to as $x) if ((int)$x !== $uid) notify((int)$x, 'news', $v[0], mb_substr((string)$v[1], 0, 140), '#/news'); }
        out(['id' => $nid], 201); }
    if ($id) { $o = one('SELECT * FROM banners WHERE id=?', [$id]); if (!$o) fail('Not found', 404); if (!$isAdm && (int)$o['created_by'] !== $uid && !in_array((int)$o['department_id'], $mine, true)) fail('Not allowed', 403);
        if ($method === 'PATCH') { q('UPDATE banners SET title=?,body=?,link=?,level=?,department_id=?,starts_on=?,ends_on=?,active=? WHERE id=?', array_merge($f($b, $o), [$id])); out(['ok' => true]); }
        if ($method === 'DELETE') { q('DELETE FROM banners WHERE id=?', [$id]); out(['ok' => true]); } }
    fail('Not found', 404);
}
fail('Not found', 404);
