<?php
declare(strict_types=1);
// Included from api/index.php for routes: companies, contacts, pipeline.
// Uses $r (route), $method, $id, $sub from the router.

const STAGES = ['researching', 'contact_identified', 'outreach_sent', 'in_discussion', 'proposal_sent', 'negotiating', 'won', 'on_hold'];
const PRIOS = ['high', 'medium', 'low'];

require_once __DIR__ . '/vendorcrm.php'; vc_ensure();   // vendors and partners live beside companies; their people are not company contacts
$u = need_user(); $uid = (int)$u['id']; $mgr = in_array($u['role'], ['super_admin', 'manager'], true); $ro = $u['role'] === 'founder';
function must_write(): void { global $ro; if ($ro) fail('Founder accounts are read-only', 403); }
/** A web address a person typed (company website, contact LinkedIn). Only http(s) is kept; "example.com" becomes https://example.com; anything else (javascript:, data:, nonsense) is dropped. */
function url_or_null(?string $v, int $max = 300): ?string {
    $v = s($v ?? '', $max); if ($v === '') return null;
    if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
    return filter_var($v, FILTER_VALIDATE_URL) ? $v : null;
}
function opt_email(?string $v): ?string { $v = strtolower(s($v ?? '', 190)); if ($v === '') return null; return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : null; }
function can_edit_owner(array $row): bool { global $mgr, $uid; return $mgr || (int)($row['owner_id'] ?? 0) === $uid || (int)($row['created_by'] ?? 0) === $uid; }

/* ================= PIPELINE SUMMARY ================= */
if ($r === 'pipeline') {
    $byStage = all('SELECT stage, COUNT(*) c FROM companies GROUP BY stage');
    $stages = array_fill_keys(STAGES, 0); foreach ($byStage as $x) $stages[$x['stage']] = (int)$x['c'];
    $tot = one("SELECT (SELECT COUNT(*) FROM companies) companies, (SELECT COUNT(*) FROM contacts WHERE vendor_id IS NULL) contacts, (SELECT COUNT(*) FROM vendors WHERE is_partner=0) vendors, (SELECT COUNT(*) FROM vendors WHERE is_partner=1) partners,
        (SELECT COUNT(*) FROM contacts WHERE linkedin IS NOT NULL) linkedin,
        (SELECT COUNT(*) FROM companies WHERE priority='high' AND stage NOT IN ('won','on_hold')) high_live,
        (SELECT COUNT(*) FROM companies WHERE flag_note IS NOT NULL) flagged,
        (SELECT COUNT(*) FROM tasks WHERE company_id IS NOT NULL AND status IN ('todo','in_progress','blocked')) open_actions,
        (SELECT COUNT(*) FROM tasks WHERE company_id IS NOT NULL AND status IN ('todo','in_progress','blocked') AND due_date < CURDATE()) overdue_actions");
    foreach ($tot as $k => $v) $tot[$k] = (int)$v;
    $attention = all("SELECT id,name,flag_note FROM companies WHERE flag_note IS NOT NULL ORDER BY updated_at DESC LIMIT 10");
    $actions = all("SELECT t.id,t.title,t.due_date,t.priority,c.name AS company,a.name AS assignee FROM tasks t JOIN companies c ON c.id=t.company_id
        LEFT JOIN users a ON a.id=t.assignee_id WHERE t.status IN ('todo','in_progress','blocked') ORDER BY t.due_date IS NULL, t.due_date LIMIT 12");
    $followups = all("SELECT ct.id,ct.name,ct.followup_date,c.name AS company FROM contacts ct LEFT JOIN companies c ON c.id=ct.company_id
        WHERE ct.followup_date IS NOT NULL AND ct.followup_date <= (CURDATE() + INTERVAL 7 DAY) ORDER BY ct.followup_date LIMIT 12");
    out(['stages' => $stages, 'totals' => $tot, 'attention' => $attention, 'actions' => $actions, 'followups' => $followups]);
}

/* ================= COMPANIES ================= */
if ($r === 'companies') {
    $csel = 'SELECT c.*, o.name AS owner_name, (SELECT COUNT(*) FROM contacts x WHERE x.company_id=c.id) AS contact_count,
             (SELECT COUNT(*) FROM tasks t WHERE t.company_id=c.id AND t.status<>\'done\') AS open_tasks
             FROM companies c LEFT JOIN users o ON o.id=c.owner_id';

    if ($method === 'POST' && $id === null && $sub === 'import') {
        must_write(); $rows = body()['rows'] ?? []; if (!is_array($rows) || !$rows) fail('No rows to import'); if (count($rows) > 500) fail('Import at most 500 rows at a time');
        $added = 0; $skipped = 0;
        foreach ($rows as $x) {
            $name = s($x['name'] ?? '', 190); if ($name === '') { $skipped++; continue; }
            if (one('SELECT id FROM companies WHERE LOWER(name)=LOWER(?)', [$name])) { $skipped++; continue; }
            q('INSERT INTO companies (name,sector,location,website,stage,priority,owner_id,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)',
              [$name, s($x['sector'] ?? '', 80) ?: null, s($x['location'] ?? '', 120) ?: null, url_or_null($x['website'] ?? null, 190),
               in(strtolower(str_replace([' ', '-', "'"], ['_', '_', ''], (string)($x['stage'] ?? ''))), STAGES, 'researching'),
               in(strtolower((string)($x['priority'] ?? '')), PRIOS, 'medium'), $uid, s($x['notes'] ?? '', 5000) ?: null, $uid]);
            $added++;
        }
        log_act($uid, 'companies_imported', "$added added, $skipped skipped");
        out(['ok' => true, 'added' => $added, 'skipped' => $skipped]);
    }

    if ($method === 'GET' && $id === null) {
        $w = ['1=1']; $p = [];
        if (!empty($_GET['stage']) && in_array($_GET['stage'], STAGES, true)) { $w[] = 'c.stage=?'; $p[] = $_GET['stage']; }
        if (!empty($_GET['priority']) && in_array($_GET['priority'], PRIOS, true)) { $w[] = 'c.priority=?'; $p[] = $_GET['priority']; }
        if (!empty($_GET['owner'])) { $w[] = 'c.owner_id=?'; $p[] = $_GET['owner'] === 'me' ? $uid : (int)$_GET['owner']; }
        if (!empty($_GET['q'])) { $w[] = '(c.name LIKE ? OR c.sector LIKE ? OR c.location LIKE ?)'; $like = '%' . s($_GET['q'], 60) . '%'; array_push($p, $like, $like, $like); }
        out(all("$csel WHERE " . implode(' AND ', $w) . " ORDER BY FIELD(c.priority,'high','medium','low'), c.name LIMIT 1000", $p));
    }
    if ($method === 'GET' && $id) {
        $c = one("$csel WHERE c.id=?", [$id]); if (!$c) fail('Not found', 404);
        $c['contacts'] = all('SELECT id,name,position,email,phone,linkedin,followup_date FROM contacts WHERE company_id=? ORDER BY name', [$id]);
        $c['tasks'] = all('SELECT t.id,t.title,t.status,t.priority,t.due_date,a.name AS assignee_name FROM tasks t LEFT JOIN users a ON a.id=t.assignee_id WHERE t.company_id=? ORDER BY t.status=\'done\', t.due_date IS NULL, t.due_date', [$id]);
        $c['can_edit'] = !$ro && can_edit_owner($c); $c['can_delete'] = $mgr;
        out($c);
    }
    if ($method === 'POST' && $id === null) {
        must_write(); $b = body(); $name = s($b['name'] ?? '', 190); if ($name === '') fail('Company name required');
        if (one('SELECT id FROM companies WHERE LOWER(name)=LOWER(?)', [$name])) fail('A company with that name already exists');
        q('INSERT INTO companies (name,code,address,sector,location,website,stage,priority,owner_id,pain_points,current_solution,notes,flag_note,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$name, s($b['code'] ?? '', 40) ?: null, s($b['address'] ?? '', 800) ?: null, s($b['sector'] ?? '', 80) ?: null, s($b['location'] ?? '', 120) ?: null, url_or_null($b['website'] ?? null, 190), in((string)($b['stage'] ?? ''), STAGES, 'researching'),
           in((string)($b['priority'] ?? ''), PRIOS, 'medium'), !empty($b['owner_id']) ? (int)$b['owner_id'] : $uid, s($b['pain_points'] ?? '', 5000) ?: null,
           s($b['current_solution'] ?? '', 5000) ?: null, s($b['notes'] ?? '', 5000) ?: null, s($b['flag_note'] ?? '', 300) ?: null, $uid]);
        $nid = (int)db()->lastInsertId(); log_act($uid, 'company_added', $name); out(['ok' => true, 'id' => $nid], 201);
    }
    if ($id) {
        $c = one('SELECT * FROM companies WHERE id=?', [$id]); if (!$c) fail('Not found', 404);
        if ($method === 'PATCH') {
            must_write(); if (!can_edit_owner($c)) fail('Only the owner or a manager can edit this company', 403);
            $b = body(); $g = fn($k, $d) => array_key_exists($k, $b) ? $b[$k] : $d;
            $stage = in((string)$g('stage', $c['stage']), STAGES, $c['stage']);
            q('UPDATE companies SET name=?,code=?,address=?,sector=?,location=?,website=?,stage=?,priority=?,owner_id=?,pain_points=?,current_solution=?,notes=?,flag_note=? WHERE id=?',
              [s($g('name', $c['name']), 190) ?: $c['name'], s($g('code', $c['code'] ?? ''), 40) ?: null, s($g('address', $c['address'] ?? ''), 800) ?: null, s($g('sector', $c['sector']), 80) ?: null, s($g('location', $c['location']), 120) ?: null, url_or_null($g('website', $c['website']), 190),
               $stage, in((string)$g('priority', $c['priority']), PRIOS, $c['priority']), ($g('owner_id', $c['owner_id']) ?: null),
               s($g('pain_points', $c['pain_points']), 5000) ?: null, s($g('current_solution', $c['current_solution']), 5000) ?: null, s($g('notes', $c['notes']), 5000) ?: null,
               s($g('flag_note', $c['flag_note']), 300) ?: null, $id]);
            if ($stage !== $c['stage']) {
                try { q('INSERT INTO company_stage_history (company_id,from_stage,to_stage,changed_by) VALUES (?,?,?,?)', [$id, $c['stage'], $stage, $uid]); } catch (Throwable $e) {} // table exists after upgrade.php
                log_act($uid, 'stage_changed', $c['name'] . ': ' . str_replace('_', ' ', $c['stage']) . ' → ' . str_replace('_', ' ', $stage));
                if ($c['owner_id'] && (int)$c['owner_id'] !== $uid) notify((int)$c['owner_id'], 'stage_changed', $c['name'] . ' moved to ' . str_replace('_', ' ', $stage), 'By ' . $u['name'], '#/pipeline');
            }
            out(['ok' => true]);
        }
        if ($method === 'DELETE') { if (!$mgr) fail('Only managers and admins can delete companies', 403); q('DELETE FROM companies WHERE id=?', [$id]); log_act($uid, 'company_deleted', $c['name']); out(['ok' => true]); }
    }
    fail('Bad request', 404);
}

/* ================= CONTACTS ================= */
if ($r === 'contacts') {
    $sel = 'SELECT ct.*, c.name AS company_name FROM contacts ct LEFT JOIN companies c ON c.id=ct.company_id';

    if ($method === 'POST' && $id === null && $sub === 'import') {
        must_write(); $rows = body()['rows'] ?? []; if (!is_array($rows) || !$rows) fail('No rows to import'); if (count($rows) > 500) fail('Import at most 500 rows at a time');
        $added = 0; $skipped = 0; $newCo = 0;
        foreach ($rows as $x) {
            $name = s($x['name'] ?? '', 160); if ($name === '') { $skipped++; continue; }
            $email = opt_email($x['email'] ?? null);
            if ($email && one('SELECT id FROM contacts WHERE email=?', [$email])) { $skipped++; continue; }
            $cid = null; $co = s($x['company'] ?? '', 190);
            if ($co !== '') { $f = one('SELECT id FROM companies WHERE LOWER(name)=LOWER(?)', [$co]);
                if ($f) $cid = (int)$f['id']; else { q('INSERT INTO companies (name,created_by,owner_id) VALUES (?,?,?)', [$co, $uid, $uid]); $cid = (int)db()->lastInsertId(); $newCo++; } }
            q('INSERT INTO contacts (company_id,name,position,email,phone,linkedin,location,followup_date,notes,owner_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
              [$cid, $name, s($x['position'] ?? '', 160) ?: null, $email, s($x['phone'] ?? '', 60) ?: null, url_or_null($x['linkedin'] ?? null), s($x['location'] ?? '', 120) ?: null,
               d($x['followup_date'] ?? null), s($x['notes'] ?? '', 3000) ?: null, $uid]);
            $added++;
        }
        log_act($uid, 'contacts_imported', "$added added, $skipped skipped");
        out(['ok' => true, 'added' => $added, 'skipped' => $skipped, 'companies_created' => $newCo]);
    }

    if ($method === 'GET' && $id === null) {
        $w = ['ct.vendor_id IS NULL']; $p = [];
        if (!empty($_GET['company_id'])) { $w[] = 'ct.company_id=?'; $p[] = (int)$_GET['company_id']; }
        if (!empty($_GET['has_email'])) $w[] = 'ct.email IS NOT NULL';
        if (!empty($_GET['has_linkedin'])) $w[] = 'ct.linkedin IS NOT NULL';
        if (!empty($_GET['q'])) { $w[] = '(ct.name LIKE ? OR ct.position LIKE ? OR c.name LIKE ? OR ct.email LIKE ?)'; $like = '%' . s($_GET['q'], 60) . '%'; array_push($p, $like, $like, $like, $like); }
        out(all("$sel WHERE " . implode(' AND ', $w) . ' ORDER BY ct.name LIMIT 1000', $p));
    }
    if ($method === 'GET' && $id) { $c = one("$sel WHERE ct.id=?", [$id]); if (!$c) fail('Not found', 404); out($c); }
    if ($method === 'POST' && $id === null) {
        must_write(); $b = body(); $name = s($b['name'] ?? '', 160); if ($name === '') fail('Contact name required');
        $email = opt_email($b['email'] ?? null); if (!empty($b['email']) && !$email) fail('That email address is not valid');
        q('INSERT INTO contacts (company_id,name,position,email,phone,linkedin,location,followup_date,notes,owner_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
          [!empty($b['company_id']) ? (int)$b['company_id'] : null, $name, s($b['position'] ?? '', 160) ?: null, $email, s($b['phone'] ?? '', 60) ?: null, url_or_null($b['linkedin'] ?? null),
           s($b['location'] ?? '', 120) ?: null, d($b['followup_date'] ?? null), s($b['notes'] ?? '', 3000) ?: null, $uid]);
        $nid = (int)db()->lastInsertId(); log_act($uid, 'contact_added', $name); out(['ok' => true, 'id' => $nid], 201);
    }
    if ($id) {
        $c = one('SELECT * FROM contacts WHERE id=?', [$id]); if (!$c) fail('Not found', 404);
        if ($method === 'PATCH') {
            must_write(); $b = body(); $g = fn($k, $d0) => array_key_exists($k, $b) ? $b[$k] : $d0;
            $email = opt_email($g('email', $c['email'])); if (!empty($b['email']) && !$email) fail('That email address is not valid');
            q('UPDATE contacts SET company_id=?,name=?,position=?,email=?,phone=?,linkedin=?,location=?,followup_date=?,notes=? WHERE id=?',
              [($g('company_id', $c['company_id']) ?: null), s($g('name', $c['name']), 160) ?: $c['name'], s($g('position', $c['position']), 160) ?: null, $email, s($g('phone', $c['phone']), 60) ?: null,
               url_or_null($g('linkedin', $c['linkedin'])), s($g('location', $c['location']), 120) ?: null, array_key_exists('followup_date', $b) ? d($b['followup_date']) : $c['followup_date'],
               s($g('notes', $c['notes']), 3000) ?: null, $id]);
            out(['ok' => true]);
        }
        if ($method === 'DELETE') { must_write(); if (!$mgr && (int)$c['owner_id'] !== $uid) fail('Only the owner or a manager can delete this contact', 403); q('DELETE FROM contacts WHERE id=?', [$id]); out(['ok' => true]); }
    }
    fail('Bad request', 404);
}
fail('Not found', 404);
