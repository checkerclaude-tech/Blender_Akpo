<?php
declare(strict_types=1);
// Vendors and Partners in the Pipeline.
//   Company = someone we sell to.  Vendor = someone we buy from.  Partner = a vendor we have a long-term agreement with.
// A vendor row also feeds the Finance catalogue (products.vendor_id), so one list serves both.
// Functions are used by inbox automation, prospecting and the RFQ tracker; the routes run only for /api/vendors.
//   GET vendors[?kind=vendors|partners&dealing=&q=]   POST vendors   GET|PATCH|DELETE vendors/{id}
//   POST vendors/{id}/contacts   DELETE vendors/{id}/contacts/{cid}   POST vendors/{id}/dealt
//   POST vendors/move {from:companies|vendors|contacts, ids, to:company|vendor|partner|regulator|regulatory|customer}
//   POST vendors/from-company {company_id,partner?}   POST vendors/from-contact {contact_id}   GET vendors/suspects
//   GET vendors/rfq-people[?q=]   POST vendors/rfq-ok {kind:company|contact,id,on}
require_once __DIR__ . '/migrate.php';
const VC_DEALING = ['not_yet' => 'Not dealing yet', 'active' => 'Dealing with them', 'paused' => 'Paused', 'stopped' => 'Stopped'];
const VC_FREE = ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'outlook.com', 'hotmail.com', 'live.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com', 'yandex.com', 'gmx.com', 'mail.com', 'zoho.com'];

function vc_ensure(): void {
    static $d = false; if ($d) return; $d = true;
    try { if (!col_exists('vendors', 'is_partner') || !col_exists('contacts', 'vendor_id') || !col_exists('companies', 'rfq_ok') || !col_exists('vendors', 'is_regulator') || !col_exists('contacts', 'contact_type')) { require_once __DIR__ . '/migrate_fin3.php'; migrate_fin_ext(); } } catch (Throwable $e) { error_log('Vendor schema: ' . $e->getMessage()); }
}
function vc_domain(string $email): string { return strtolower(substr(strrchr(strtolower(trim($email)), '@') ?: '', 1)); }
/** The vendor an email address belongs to: its own address, a vendor contact, or (for a company address, not gmail and the like) its web domain. */
function vc_vendor_for(string $email): ?array {
    vc_ensure(); $email = strtolower(trim($email)); if ($email === '') return null; $dom = vc_domain($email);
    $v = one('SELECT * FROM vendors WHERE LOWER(email)=? LIMIT 1', [$email]); if ($v) return $v;
    $v = one('SELECT v.* FROM contacts c JOIN vendors v ON v.id=c.vendor_id WHERE LOWER(c.email)=? LIMIT 1', [$email]); if ($v) return $v;
    if ($dom !== '' && !in_array($dom, VC_FREE, true)) {
        $v = one('SELECT * FROM vendors WHERE (website IS NOT NULL AND website<>\'\' AND website LIKE ?) OR LOWER(email) LIKE ? ORDER BY id LIMIT 1', ['%' . $dom . '%', '%@' . $dom]); if ($v) return $v; }
    return null;
}
/** Creates the vendor if it is new. Returns [row, wasCreated]. */
function vc_make(string $name, string $email, string $source, ?int $uid, array $extra = []): array {
    vc_ensure(); $name = mb_substr(trim($name), 0, 190); $email = strtolower(trim($email)); $v = null;
    if ($email !== '') $v = vc_vendor_for($email); if (!$v && $name !== '') $v = one('SELECT * FROM vendors WHERE LOWER(name)=LOWER(?) LIMIT 1', [$name]); if ($v) return [$v, false];
    if ($name === '') $name = $email !== '' ? ucwords(str_replace(['-', '_'], ' ', explode('.', vc_domain($email))[0])) : 'Vendor';
    $dom = vc_domain($email); $site = $dom !== '' && !in_array($dom, VC_FREE, true) ? $dom : null;
    q('INSERT INTO vendors (name,email,website,notes,created_by,source,dealing,is_partner,partner_type,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())', [$name, $email ?: null, $extra['website'] ?? $site, mb_substr((string)($extra['notes'] ?? ''), 0, 1000) ?: null, $uid, $source, 'not_yet', !empty($extra['partner']) ? 1 : 0, !empty($extra['partner']) ? 'Long-term agreement' : null]);
    return [one('SELECT * FROM vendors WHERE id=?', [(int)db()->lastInsertId()]), true];
}
/** A person at a vendor. An existing contact with that address is re-pointed at the vendor and taken out of any company. */
function vc_contact(int $vendorId, string $email, string $name, string $note = ''): array {
    $email = strtolower(trim($email)); $rg = one('SELECT is_regulator FROM vendors WHERE id=?', [$vendorId]); $ctype = !empty($rg['is_regulator']) ? 'regulatory' : null; $c = $email !== '' ? one('SELECT id,name,company_id,vendor_id FROM contacts WHERE LOWER(email)=? LIMIT 1', [$email]) : null;
    if ($c) { if ((int)$c['vendor_id'] !== $vendorId) q('UPDATE contacts SET vendor_id=?, company_id=NULL, contact_type=? WHERE id=?', [$vendorId, $ctype, $c['id']]); return $c; }
    $nm = $name !== '' ? $name : ucwords(str_replace(['.', '_', '-'], ' ', explode('@', $email)[0]));
    q('INSERT INTO contacts (company_id,vendor_id,name,email,notes,contact_type) VALUES (NULL,?,?,?,?,?)', [$vendorId, mb_substr($nm, 0, 160), $email ?: null, mb_substr($note, 0, 1000), $ctype]);
    return one('SELECT id,name,company_id,vendor_id FROM contacts WHERE id=?', [(int)db()->lastInsertId()]);
}
function vc_dto(array $v): array {
    return ['id' => (int)$v['id'], 'name' => $v['name'], 'contact_name' => $v['contact_name'] ?? null, 'email' => $v['email'], 'phone' => $v['phone'] ?? null, 'address' => $v['address'] ?? null, 'website' => $v['website'] ?? null, 'category' => $v['category'] ?? null, 'notes' => $v['notes'] ?? null,
        'dealing' => $v['dealing'] ?? 'not_yet', 'dealing_label' => VC_DEALING[$v['dealing'] ?? 'not_yet'] ?? '', 'dealing_note' => $v['dealing_note'] ?? null, 'last_dealt_at' => $v['last_dealt_at'] ?? null,
        'is_partner' => !empty($v['is_partner']), 'is_regulator' => !empty($v['is_regulator']), 'group' => !empty($v['is_regulator']) ? 'regulators' : (!empty($v['is_partner']) ? 'partners' : 'vendors'), 'partner_type' => $v['partner_type'] ?? null, 'agreement_start' => $v['agreement_start'] ?? null, 'agreement_end' => $v['agreement_end'] ?? null, 'agreement_notes' => $v['agreement_notes'] ?? null, 'agreement_url' => $v['agreement_url'] ?? null,
        'source' => $v['source'] ?? null, 'owner_id' => isset($v['owner_id']) ? (int)$v['owner_id'] : null, 'owner_name' => $v['owner_name'] ?? null, 'contacts' => isset($v['contacts']) ? (int)$v['contacts'] : null, 'items' => isset($v['items']) ? (int)$v['items'] : null,
        'agreement_days_left' => !empty($v['agreement_end']) ? (int)floor((strtotime($v['agreement_end']) - strtotime(date('Y-m-d'))) / 86400) : null, 'created_at' => $v['created_at'] ?? null];
}

if (!isset($r) || $r !== 'vendors') return;   // loaded as a library by cron / inbox automation / the RFQ tracker

vc_ensure(); $u = need_user(); $uid = (int)$u['id']; $mgr = in_array($u['role'], ['super_admin', 'manager'], true); $b = $method === 'GET' ? [] : body(); $s2 = $seg[1] ?? ''; $s3 = $seg[2] ?? ''; $s4 = $seg[3] ?? '';
if ($method !== 'GET' && $u['role'] === 'founder') fail('Founder accounts are read-only', 403);
$date = fn($v) => ($v = trim((string)$v)) !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
$apply = function (int $vid, array $b, array $cur) use ($date): void {
    $f = [];
    foreach (['name' => 190, 'contact_name' => 160, 'email' => 190, 'phone' => 60, 'address' => 300, 'website' => 190, 'category' => 80, 'notes' => 2000, 'dealing_note' => 300, 'partner_type' => 60, 'agreement_notes' => 4000, 'agreement_url' => 500] as $k => $n)
        if (array_key_exists($k, $b)) { $x = mb_substr(trim((string)$b[$k]), 0, $n); if ($k === 'name' && $x === '') fail('Enter the vendor name'); if ($k === 'email' && $x !== '' && !filter_var($x, FILTER_VALIDATE_EMAIL)) fail('That email address does not look right'); if ($k === 'agreement_url' && $x !== '' && !preg_match('~^https?://~i', $x)) $x = 'https://' . $x; $f[$k] = $x === '' ? null : ($k === 'email' ? strtolower($x) : $x); }
    foreach (['agreement_start', 'agreement_end', 'last_dealt_at'] as $k) if (array_key_exists($k, $b)) $f[$k] = $date($b[$k]);
    if (array_key_exists('dealing', $b)) $f['dealing'] = in((string)$b['dealing'], array_keys(VC_DEALING), 'not_yet');
    if (array_key_exists('is_partner', $b)) { $f['is_partner'] = !empty($b['is_partner']) ? 1 : 0; if ($f['is_partner'] && !array_key_exists('dealing', $b) && ($cur['dealing'] ?? 'not_yet') === 'not_yet') $f['dealing'] = 'active'; if ($f['is_partner'] && empty($cur['partner_type']) && !isset($f['partner_type'])) $f['partner_type'] = 'Long-term agreement'; }
    if (array_key_exists('is_regulator', $b)) { $f['is_regulator'] = !empty($b['is_regulator']) ? 1 : 0; if ($f['is_regulator']) { $f['is_partner'] = 0; $f['dealing'] = $f['dealing'] ?? ($cur['dealing'] ?? 'not_yet'); } }
    if (!empty($f['is_partner'])) $f['is_regulator'] = 0;
    if (array_key_exists('owner_id', $b)) $f['owner_id'] = (int)$b['owner_id'] ?: null;
    if (isset($f['name']) && ($x = one('SELECT id FROM vendors WHERE LOWER(name)=LOWER(?) AND id<>?', [$f['name'], $vid]))) fail('There is already a vendor with that name');
    if (($f['dealing'] ?? '') === 'active' && empty($cur['last_dealt_at']) && !array_key_exists('last_dealt_at', $f)) $f['last_dealt_at'] = date('Y-m-d');
    if ($f) { $set = implode(',', array_map(fn($k) => "$k=?", array_keys($f))); q("UPDATE vendors SET $set, updated_at=NOW() WHERE id=?", array_merge(array_values($f), [$vid])); }
    if (array_key_exists('is_regulator', $f)) q('UPDATE contacts SET contact_type=? WHERE vendor_id=?', [$f['is_regulator'] ? 'regulatory' : null, $vid]);   // its people follow the organisation
};

if ($s2 === '' || $s2 === null) {
    if ($method === 'GET') {
        $kind = (string)($_GET['kind'] ?? ''); $dl = (string)($_GET['dealing'] ?? ''); $qq = trim((string)($_GET['q'] ?? '')); $w = []; $p = [];
        if ($kind === 'partners') $w[] = 'v.is_partner=1 AND v.is_regulator=0'; elseif ($kind === 'regulators') $w[] = 'v.is_regulator=1'; elseif ($kind === 'vendors') $w[] = 'v.is_partner=0 AND v.is_regulator=0';
        if (isset(VC_DEALING[$dl])) { $w[] = 'v.dealing=?'; $p[] = $dl; }
        if ($qq !== '') { $w[] = '(v.name LIKE ? OR v.email LIKE ? OR v.contact_name LIKE ? OR v.category LIKE ? OR v.website LIKE ?)'; $l = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $qq) . '%'; array_push($p, $l, $l, $l, $l, $l); }
        $rows = all('SELECT v.*, (SELECT COUNT(*) FROM contacts c WHERE c.vendor_id=v.id) AS contacts, (SELECT COUNT(*) FROM products pr WHERE pr.vendor_id=v.id) AS items, ow.name AS owner_name FROM vendors v LEFT JOIN users ow ON ow.id=v.owner_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY v.is_partner DESC, v.name LIMIT 600', $p);
        $cnt = one("SELECT COUNT(*) total, COALESCE(SUM(is_partner=1 AND is_regulator=0),0) partners, COALESCE(SUM(is_regulator=1),0) regulators, COALESCE(SUM(dealing='active'),0) active, COALESCE(SUM(dealing='not_yet'),0) not_yet FROM vendors");
        out(['vendors' => array_map('vc_dto', $rows), 'counts' => array_map('intval', $cnt), 'dealing' => VC_DEALING, 'people' => all('SELECT id,name FROM users WHERE active=1 ORDER BY name')]);
    }
    if ($method === 'POST') { $nm = mb_substr(trim((string)($b['name'] ?? '')), 0, 190); if ($nm === '') fail('Enter the vendor name'); if (one('SELECT id FROM vendors WHERE LOWER(name)=LOWER(?)', [$nm])) fail('There is already a vendor with that name');
        q('INSERT INTO vendors (name,created_by,source,owner_id) VALUES (?,?,?,?)', [$nm, $uid, 'manual', $uid]); $vid = (int)db()->lastInsertId(); $apply($vid, $b, one('SELECT * FROM vendors WHERE id=?', [$vid]));
        if (!empty($b['contact_email']) || !empty($b['contact_name'])) { $ce = strtolower(trim((string)($b['contact_email'] ?? ''))); if ($ce === '' || filter_var($ce, FILTER_VALIDATE_EMAIL)) vc_contact($vid, $ce, mb_substr(trim((string)($b['contact_name'] ?? '')), 0, 160)); }
        log_act($uid, 'vendor_added', $nm); out(['ok' => true, 'id' => $vid], 201); }
    fail('Bad request', 405);
}

// ---- tools that work on the whole list
if ($s2 === 'move' && $method === 'POST') {   // the Pipeline "Move to…" tool: companies, vendors, partners, regulatory bodies and contacts change group
    $from = (string)($b['from'] ?? ''); $to = (string)($b['to'] ?? ''); $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($b['ids'] ?? [])), fn($i) => $i > 0))), 0, 200); if (!$ids) fail('Nothing was selected');
    if (!in_array($from, ['companies', 'vendors', 'contacts'], true) || !in_array($to, ['company', 'vendor', 'partner', 'regulator', 'regulatory', 'customer'], true)) fail('Bad request'); $ok = 0; $bad = [];
    foreach ($ids as $i) { try {
        if ($from === 'contacts') { if (!in_array($to, ['regulatory', 'customer'], true)) fail('A contact can be moved to regulatory or customer contacts'); $c = one('SELECT id,name,owner_id,vendor_id FROM contacts WHERE id=?', [$i]); if (!$c) continue;
            if (!$mgr && (int)$c['owner_id'] !== $uid) { $bad[] = $c['name'] . ': only the owner or a manager can change this contact'; continue; } q('UPDATE contacts SET contact_type=? WHERE id=?', [$to === 'regulatory' ? 'regulatory' : null, $i]); $ok++; continue; }
        if (!in_array($to, ['company', 'vendor', 'partner', 'regulator'], true)) fail('Bad request');
        if ($from === 'companies') { $c = one('SELECT * FROM companies WHERE id=?', [$i]); if (!$c) continue; if ($to === 'company') continue;
            if (!$mgr && (int)$c['owner_id'] !== $uid && (int)$c['created_by'] !== $uid) { $bad[] = $c['name'] . ': only the owner or a manager can move this company'; continue; }
            [$v] = vc_make((string)$c['name'], '', 'moved', $uid, ['website' => $c['website'], 'notes' => 'Moved from the Pipeline companies. ' . mb_substr((string)$c['notes'], 0, 600)]); $first = null;
            foreach (all('SELECT id,email FROM contacts WHERE company_id=?', [$c['id']]) as $ct) { q('UPDATE contacts SET vendor_id=?, company_id=NULL, contact_type=? WHERE id=?', [$v['id'], $to === 'regulator' ? 'regulatory' : null, $ct['id']]); if (!$first && $ct['email']) $first = $ct['email']; }
            if (empty($v['email']) && $first) q('UPDATE vendors SET email=? WHERE id=?', [$first, $v['id']]);
            q('UPDATE vendors SET is_partner=?, is_regulator=?, partner_type=IF(?=1,COALESCE(partner_type,\'Long-term agreement\'),partner_type) WHERE id=?', [$to === 'partner' ? 1 : 0, $to === 'regulator' ? 1 : 0, $to === 'partner' ? 1 : 0, $v['id']]);
            q('UPDATE tasks SET company_id=NULL WHERE company_id=?', [$c['id']]); q('DELETE FROM companies WHERE id=?', [$c['id']]); log_act($uid, 'company_moved', $c['name'] . ' → ' . $to); $ok++; continue; }
        $v = one('SELECT * FROM vendors WHERE id=?', [$i]); if (!$v) continue;   // from vendors / partners / regulatory bodies
        if ($to === 'company') { if (!$mgr) { $bad[] = 'Only managers and admins can move a vendor to Companies'; break; }
            $site = $v['website'] ? mb_substr((string)$v['website'], 0, 190) : null; q("INSERT INTO companies (name,website,stage,priority,owner_id,notes,created_by) VALUES (?,?,'researching','medium',?,?,?)", [mb_substr((string)$v['name'], 0, 190), $site, $uid, 'Moved from Vendors. ' . mb_substr((string)$v['notes'], 0, 600), $uid]); $cid = (int)db()->lastInsertId();
            q('UPDATE contacts SET company_id=?, vendor_id=NULL, contact_type=NULL WHERE vendor_id=?', [$cid, $i]); q('UPDATE products SET vendor_id=NULL WHERE vendor_id=?', [$i]); q('DELETE FROM vendors WHERE id=?', [$i]); log_act($uid, 'vendor_to_company', $v['name']); $ok++; continue; }
        $pa = $to === 'partner' ? 1 : 0; $rg = $to === 'regulator' ? 1 : 0; q("UPDATE vendors SET is_partner=?, is_regulator=?, partner_type=IF(?=1,COALESCE(partner_type,'Long-term agreement'),partner_type), dealing=IF(? AND dealing='not_yet','active',dealing), updated_at=NOW() WHERE id=?", [$pa, $rg, $pa, $pa, $i]);
        q('UPDATE contacts SET contact_type=? WHERE vendor_id=?', [$rg ? 'regulatory' : null, $i]); $ok++;
    } catch (PDOException $e) { error_log('Move: ' . $e->getMessage()); $bad[] = 'Item ' . $i . ' could not be moved'; } }
    out(['ok' => true, 'moved' => $ok, 'skipped' => count($bad), 'errors' => array_slice($bad, 0, 8)]);
}
if ($s2 === 'from-company' && $method === 'POST') {   // a company that was really a supplier: it becomes a vendor, its people move with it, the company is removed
    $c = one('SELECT * FROM companies WHERE id=?', [(int)($b['company_id'] ?? 0)]); if (!$c) fail('Company not found', 404);
    if (!$mgr && (int)$c['owner_id'] !== $uid && (int)$c['created_by'] !== $uid) fail('Only the owner or a manager can move this company', 403);
    [$v, $new] = vc_make((string)$c['name'], '', 'moved', $uid, ['website' => $c['website'], 'notes' => 'Moved from the Pipeline companies. ' . mb_substr((string)$c['notes'], 0, 600), 'partner' => !empty($b['partner'])]);
    $first = null; foreach (all('SELECT id,email FROM contacts WHERE company_id=?', [$c['id']]) as $ct) { q('UPDATE contacts SET vendor_id=?, company_id=NULL WHERE id=?', [$v['id'], $ct['id']]); if (!$first && $ct['email']) $first = $ct['email']; }
    if (empty($v['email']) && $first) q('UPDATE vendors SET email=? WHERE id=?', [$first, $v['id']]);
    q('UPDATE tasks SET company_id=NULL WHERE company_id=?', [$c['id']]); q('DELETE FROM companies WHERE id=?', [$c['id']]); log_act($uid, 'company_to_vendor', $c['name']); out(['ok' => true, 'vendor_id' => (int)$v['id'], 'created' => $new]);
}
if ($s2 === 'from-contact' && $method === 'POST') {
    $ct = one('SELECT * FROM contacts WHERE id=?', [(int)($b['contact_id'] ?? 0)]); if (!$ct) fail('Contact not found', 404);
    $co = $ct['company_id'] ? one('SELECT name FROM companies WHERE id=?', [$ct['company_id']]) : null;
    [$v] = vc_make((string)($co['name'] ?? ($ct['name'] . ' (vendor)')), (string)$ct['email'], 'moved', $uid, ['partner' => !empty($b['partner'])]); q('UPDATE contacts SET vendor_id=?, company_id=NULL WHERE id=?', [$v['id'], $ct['id']]); out(['ok' => true, 'vendor_id' => (int)$v['id']]);
}
if ($s2 === 'suspects' && $method === 'GET') {   // companies that were created automatically (a scraped or emailed address) and may really be suppliers
    $rows = all("SELECT c.id,c.name,c.website,c.stage,c.notes,c.created_at, (SELECT COUNT(*) FROM contacts k WHERE k.company_id=c.id) contacts, (SELECT GROUP_CONCAT(k.email SEPARATOR ', ') FROM contacts k WHERE k.company_id=c.id) emails,
        (SELECT COUNT(*) FROM vendors v WHERE LOWER(v.name)=LOWER(c.name) OR (c.website IS NOT NULL AND c.website<>'' AND v.website IS NOT NULL AND v.website<>'' AND (v.website LIKE CONCAT('%',c.website,'%') OR c.website LIKE CONCAT('%',v.website,'%')))) is_known_vendor
        FROM companies c WHERE c.stage NOT IN ('won') AND (c.notes LIKE 'Added from an email enquiry%' OR c.notes LIKE 'Market signal%' OR c.notes LIKE 'Scraped%' OR c.notes LIKE 'Found while%') ORDER BY is_known_vendor DESC, c.created_at DESC LIMIT 200");
    out(['companies' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'website' => $x['website'], 'stage' => $x['stage'], 'contacts' => (int)$x['contacts'], 'emails' => mb_substr((string)$x['emails'], 0, 160), 'note' => mb_substr(strtok((string)$x['notes'], "\n") ?: '', 120), 'known_vendor' => (int)$x['is_known_vendor'] > 0, 'created_at' => $x['created_at']], $rows)]);
}
if ($s2 === 'rfq-people' && $method === 'GET') {   // who can be designated as an RFQ sender
    $qq = trim((string)($_GET['q'] ?? '')); $l = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $qq) . '%';
    $co = all('SELECT id,name,website,rfq_ok FROM companies WHERE (?=\'\' OR name LIKE ? OR website LIKE ?) ORDER BY rfq_ok DESC, name LIMIT 60', [$qq, $l, $l]);
    $ct = all('SELECT c.id,c.name,c.email,c.rfq_ok,co.name AS company FROM contacts c LEFT JOIN companies co ON co.id=c.company_id WHERE c.vendor_id IS NULL AND c.email IS NOT NULL AND c.email<>\'\' AND (?=\'\' OR c.name LIKE ? OR c.email LIKE ? OR co.name LIKE ?) ORDER BY c.rfq_ok DESC, c.name LIMIT 60', [$qq, $l, $l, $l]);
    out(['companies' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'website' => $x['website'], 'on' => (bool)$x['rfq_ok']], $co), 'contacts' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'email' => $x['email'], 'company' => $x['company'], 'on' => (bool)$x['rfq_ok']], $ct)]);
}
if ($s2 === 'rfq-ok' && $method === 'POST') {
    if (!$mgr && !function_exists('can_finance')) fail('Not allowed', 403); $kind = (string)($b['kind'] ?? ''); $t = $kind === 'company' ? 'companies' : ($kind === 'contact' ? 'contacts' : ''); if ($t === '') fail('Bad request');
    if (!$mgr && function_exists('can_finance') && !can_finance($u)) fail('Only managers and Finance can choose who sends RFQs', 403);
    $row = one("SELECT id FROM $t WHERE id=?", [(int)($b['id'] ?? 0)]); if (!$row) fail('Not found', 404); q("UPDATE $t SET rfq_ok=? WHERE id=?", [!empty($b['on']) ? 1 : 0, $row['id']]); out(['ok' => true]);
}

// ---- one vendor
if (ctype_digit((string)$s2)) {
    $vid = (int)$s2; $v = one('SELECT v.*, ow.name AS owner_name FROM vendors v LEFT JOIN users ow ON ow.id=v.owner_id WHERE v.id=?', [$vid]); if (!$v) fail('Not found', 404);
    if ($s3 === 'contacts') {
        if ($method === 'POST') { $e = strtolower(trim((string)($b['email'] ?? ''))); $n = mb_substr(trim((string)($b['name'] ?? '')), 0, 160); if ($e === '' && $n === '') fail('Enter a name or an email'); if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) fail('That email address does not look right');
            $c = vc_contact($vid, $e, $n); foreach (['phone' => 60, 'position' => 160] as $k => $m) if (!empty($b[$k])) q("UPDATE contacts SET $k=? WHERE id=?", [mb_substr(trim((string)$b[$k]), 0, $m), $c['id']]); out(['ok' => true, 'id' => (int)$c['id']], 201); }
        if ($method === 'DELETE' && ctype_digit((string)$s4)) { q('UPDATE contacts SET vendor_id=NULL WHERE id=? AND vendor_id=?', [(int)$s4, $vid]); out(['ok' => true]); }
        fail('Bad request', 405);
    }
    if ($s3 === 'dealt' && $method === 'POST') { q("UPDATE vendors SET last_dealt_at=CURDATE(), dealing='active', updated_at=NOW() WHERE id=?", [$vid]); out(['ok' => true]); }
    if ($s3 === '' || $s3 === null) {
        if ($method === 'GET') {
            $d = vc_dto($v); $d['contact_list'] = all('SELECT id,name,email,phone,position FROM contacts WHERE vendor_id=? ORDER BY name', [$vid]);
            $d['documents'] = all("SELECT id,number,doc_type,status,currency,issue_date,created_at FROM fin_docs WHERE doc_type='requisition' AND LOWER(client_name)=LOWER(?) ORDER BY id DESC LIMIT 20", [$v['name']]);
            $d['products'] = all('SELECT id,name,kind,price,currency FROM products WHERE vendor_id=? ORDER BY name LIMIT 100', [$vid]); out(['vendor' => $d]);
        }
        if ($method === 'PATCH') { $apply($vid, $b, $v); log_act($uid, 'vendor_updated', $v['name']); out(['ok' => true]); }
        if ($method === 'DELETE') { if (!$mgr) fail('Only managers and admins can delete vendors', 403); q('UPDATE products SET vendor_id=NULL WHERE vendor_id=?', [$vid]); q('UPDATE contacts SET vendor_id=NULL WHERE vendor_id=?', [$vid]); q('DELETE FROM vendors WHERE id=?', [$vid]); log_act($uid, 'vendor_deleted', $v['name']); out(['ok' => true]); }
    }
}
fail('Bad request', 404);
