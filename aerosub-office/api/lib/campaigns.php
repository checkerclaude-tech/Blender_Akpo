<?php
declare(strict_types=1);
// Routes: /api/campaigns/*   — only Marketing-department members and the Super Admin.
require_once __DIR__ . '/campcore.php';
$u = need_user(); $uid = (int)$u['id'];
if (!can_campaigns($u)) fail('Campaigns is for the Marketing team. Ask the Super Admin to add you to the Marketing department.', 403);
$res = $seg[1] ?? ''; $rid = isset($seg[2]) && ctype_digit($seg[2]) ? (int)$seg[2] : 0; $act = $seg[3] ?? ($rid ? '' : ($seg[2] ?? ''));
$STATUS = ['subscribed', 'unsubscribed', 'bounced'];

function camp_clean_email(string $e): string { return strtolower(substr(trim($e), 0, 190)); }
function camp_add_contact(int $list, array $r, string $source): string { // returns 'added'|'exists'|'bad'
    $email = camp_clean_email((string)($r['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'bad';
    $exists = one('SELECT id FROM camp_contacts WHERE list_id=? AND email=?', [$list, $email]); if ($exists) return 'exists';
    $sup = one('SELECT 1 FROM camp_suppress WHERE email=?', [$email]);
    q('INSERT INTO camp_contacts (list_id,email,first_name,last_name,company,status,token,source) VALUES (?,?,?,?,?,?,?,?)',
      [$list, $email, s($r['first_name'] ?? '', 100) ?: null, s($r['last_name'] ?? '', 100) ?: null, s($r['company'] ?? '', 160) ?: null, $sup ? 'unsubscribed' : 'subscribed', bin2hex(random_bytes(16)), $source]);
    return 'added';
}
function camp_need_list(int $id): array { $l = one('SELECT * FROM camp_lists WHERE id=?', [$id]); if (!$l) fail('List not found', 404); return $l; }
function camp_design_in($b): string { return json_encode(camp_norm_design($b), JSON_UNESCAPED_UNICODE); }

try {

/* ---------- settings ---------- */
if ($res === 'settings') {
    if ($method === 'GET' || $act === '') {
        if ($method === 'POST') {
            $b = body(); $o = camp_settings(); $host = s($b['smtp_host'] ?? '', 190); $port = (int)($b['smtp_port'] ?? 587);
            if ($host !== '' && !preg_match('/^[a-z0-9]([a-z0-9.-]{0,188}[a-z0-9])?$/i', $host)) fail('That server name does not look right.');
            if (!in_array($port, [465, 587, 25, 2525], true)) fail('Use port 587, 465 or 2525.');
            if (!cfg('mail_allow_private') && $host !== '') { $ip = gethostbyname($host); if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) fail('That server address is not allowed.'); }
            $pass = (string)($b['smtp_pass'] ?? ''); $enc = $pass !== '' ? mail_encrypt($pass) : $o['secret_enc'];
            $fe = camp_clean_email((string)($b['from_email'] ?? '')); if ($fe !== '' && !filter_var($fe, FILTER_VALIDATE_EMAIL)) fail('Enter a valid From email address.');
            $rt = camp_clean_email((string)($b['reply_to'] ?? '')); if ($rt !== '' && !filter_var($rt, FILTER_VALIDATE_EMAIL)) fail('Enter a valid Reply-to address or leave it empty.');
            $au = s($b['app_url'] ?? '', 190); if ($au !== '' && !preg_match('~^https://[a-z0-9.-]+(:\d+)?$~i', rtrim($au, '/'))) fail('The site address must look like https://office.aerosub.co');
            q('UPDATE camp_settings SET smtp_host=?, smtp_port=?, smtp_user=?, secret_enc=?, from_email=?, from_name=?, reply_to=?, address=?, batch_size=?, hour_cap=?, app_url=? WHERE id=1',
              [$host ?: null, $port, s($b['smtp_user'] ?? '', 190) ?: null, $enc, $fe ?: null, s($b['from_name'] ?? '', 120) ?: null, $rt ?: null, s($b['address'] ?? '', 300) ?: null,
               max(10, min(500, (int)($b['batch_size'] ?? 100))), max(20, min(5000, (int)($b['hour_cap'] ?? 400))), rtrim($au, '/') ?: null]);
            log_act($uid, 'campaign_settings_saved');
        }
        $o = camp_settings();
        out(['smtp_host' => $o['smtp_host'], 'smtp_port' => (int)$o['smtp_port'], 'smtp_user' => $o['smtp_user'], 'has_secret' => !empty($o['secret_enc']), 'from_email' => $o['from_email'], 'from_name' => $o['from_name'], 'reply_to' => $o['reply_to'],
             'address' => $o['address'], 'batch_size' => (int)$o['batch_size'], 'hour_cap' => (int)$o['hour_cap'], 'app_url' => $o['app_url'] ?: '', 'base' => camp_base(), 'is_admin' => $u['role'] === 'super_admin']);
    }
    if ($act === 'test' && $method === 'POST') {
        $st = camp_settings(); $sm = camp_smtp($st); // throws a readable error if the sign-in fails
        $b = body(); $to = camp_clean_email((string)($b['to'] ?? $u['email'])); if (!filter_var($to, FILTER_VALIDATE_EMAIL)) fail('Enter a valid address for the test.');
        $c = ['first_name' => explode(' ', $u['name'])[0], 'last_name' => '', 'company' => '', 'email' => $to, 'token' => str_repeat('0', 32), 'id' => 0];
        $camp = ['id' => 0, 'subject' => 'Aerosub Office: sending test', 'preheader' => '', 'design' => json_encode(['blocks' => [['type' => 'heading', 'text' => 'It works, {{first_name|there}}!'], ['type' => 'text', 'text' => 'This is a test from the Aerosub Office Campaigns sending account.']]]), 'from_name' => '', 'from_email' => ''];
        $sm->deliver($st['from_email'], [$to], camp_build_raw($camp, $c, $st)); out(['ok' => true]);
    }
    fail('Bad request', 405);
}

/* ---------- images (public URLs, needed so emails can show them) ---------- */
if ($res === 'images' && $method === 'POST') {
    $b = body(); $d = (string)($b['data'] ?? '');
    if (!preg_match('~^data:image/(jpeg|png|gif|webp);base64,([A-Za-z0-9+/=\r\n]+)$~', $d, $mm)) fail('Choose a JPG, PNG, GIF or WebP picture.');
    $bin = base64_decode($mm[2], true); if ($bin === false || strlen($bin) > 1_500_000) fail('That picture is too large (maximum 1.5 MB).');
    $info = @getimagesizefromstring($bin); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) fail('That picture could not be read.');
    $mime = image_type_to_mime_type($info[2]); $k = bin2hex(random_bytes(8));
    q('INSERT INTO camp_images (name,mime,data,k,created_by) VALUES (?,?,?,?,?)', [s($b['name'] ?? 'image', 160), $mime, $bin, $k, $uid]); $iid = (int)db()->lastInsertId();
    out(['url' => camp_base() . '/img.php?i=' . $iid . '&k=' . $k]);
}

/* ---------- live preview of a design ---------- */
if ($res === 'preview' && $method === 'POST') {
    $b = body(); $v = ['first_name' => explode(' ', $u['name'])[0], 'last_name' => '', 'company' => 'Example Ltd', 'email' => $u['email']];
    out(['html' => camp_render(camp_norm_design($b['design'] ?? []), $v, '#unsubscribe', s($b['preheader'] ?? '', 200))]);
}

/* ---------- lists ---------- */
if ($res === 'lists') {
    if ($method === 'GET' && !$rid) {
        out(all("SELECT l.id,l.name,l.description,l.created_at, COUNT(c.id) total, SUM(c.status='subscribed') subscribed, SUM(c.status='unsubscribed') unsubscribed, SUM(c.status='bounced') bounced,
                 SUM(c.verify='valid') v_valid, SUM(c.verify='risky') v_risky, SUM(c.verify='invalid') v_invalid, SUM(c.verify='unchecked') v_unchecked
                 FROM camp_lists l LEFT JOIN camp_contacts c ON c.list_id=l.id GROUP BY l.id ORDER BY l.name"));
    }
    if ($method === 'POST' && !$rid) {
        $b = body(); $n = s($b['name'] ?? '', 120); if ($n === '') fail('Give the list a name.');
        q('INSERT INTO camp_lists (name,description,created_by) VALUES (?,?,?)', [$n, s($b['description'] ?? '', 300) ?: null, $uid]); out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201);
    }
    if ($rid) {
        $list = camp_need_list($rid);
        if ($method === 'PATCH' && $act === '') { $b = body(); q('UPDATE camp_lists SET name=?, description=? WHERE id=?', [s($b['name'] ?? $list['name'], 120) ?: $list['name'], s($b['description'] ?? $list['description'], 300) ?: null, $rid]); out(['ok' => true]); }
        if ($method === 'DELETE' && $act === '') { if (one("SELECT 1 FROM camp_campaigns WHERE list_id=? AND status IN ('sending','scheduled')", [$rid])) fail('A campaign using this list is sending or scheduled.'); q('DELETE FROM camp_lists WHERE id=?', [$rid]); out(['ok' => true]); }
        if ($act === 'contacts' && $method === 'GET') {
            $page = max(1, (int)($_GET['page'] ?? 1)); $per = 50; $w = ['list_id=?']; $p = [$rid];
            if (!empty($_GET['q'])) { $like = '%' . s($_GET['q'], 80) . '%'; $w[] = '(email LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR company LIKE ?)'; array_push($p, $like, $like, $like, $like); }
            if (!empty($_GET['status']) && in_array($_GET['status'], $STATUS, true)) { $w[] = 'status=?'; $p[] = $_GET['status']; }
            if (!empty($_GET['verify']) && in_array($_GET['verify'], ['unchecked', 'valid', 'risky', 'invalid'], true)) { $w[] = 'verify=?'; $p[] = $_GET['verify']; }
            $tot = (int)one('SELECT COUNT(*) c FROM camp_contacts WHERE ' . implode(' AND ', $w), $p)['c'];
            out(['total' => $tot, 'page' => $page, 'per' => $per, 'contacts' => all('SELECT id,email,first_name,last_name,company,status,verify,verify_note,source,created_at FROM camp_contacts WHERE ' . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $p)]);
        }
        if ($act === 'add' && $method === 'POST') { $r = camp_add_contact($rid, body(), 'manual'); if ($r === 'bad') fail('That email address does not look right.'); out(['ok' => true, 'result' => $r]); }
        if ($act === 'import' && $method === 'POST') {
            $rows = (array)(body()['rows'] ?? []); if (count($rows) > 5000) fail('Import up to 5,000 contacts at a time.');
            $c = ['added' => 0, 'exists' => 0, 'bad' => 0]; foreach ($rows as $r) { if (is_array($r)) $c[camp_add_contact($rid, $r, 'import')]++; } out(['ok' => true] + $c);
        }
        if ($act === 'from_pipeline' && $method === 'POST') {
            $b = body(); $w = ["ct.email IS NOT NULL AND ct.email<>''"]; $p = [];
            if (!empty($b['stage']) && is_string($b['stage'])) { $w[] = 'co.stage=?'; $p[] = $b['stage']; }
            $c = ['added' => 0, 'exists' => 0, 'bad' => 0];
            foreach (all('SELECT ct.name,ct.email,co.name AS company FROM contacts ct LEFT JOIN companies co ON co.id=ct.company_id WHERE ' . implode(' AND ', $w) . ' LIMIT 5000', $p) as $r) {
                $parts = preg_split('/\s+/', trim((string)$r['name']), 2); $c[camp_add_contact($rid, ['email' => $r['email'], 'first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '', 'company' => $r['company']], 'pipeline')]++;
            }
            out(['ok' => true] + $c);
        }
        if ($act === 'verify' && $method === 'POST') {
            $b = body(); $recheck = !empty($b['recheck']); $dns = [];
            $rows = all('SELECT id,email FROM camp_contacts WHERE list_id=? ' . ($recheck ? '' : "AND verify='unchecked' ") . 'ORDER BY id LIMIT 300', [$rid]);
            if ($recheck) q("UPDATE camp_contacts SET verify='unchecked' WHERE list_id=? AND id NOT IN (" . (implode(',', array_map('intval', array_column($rows, 'id'))) ?: '0') . ')', [$rid]);
            foreach ($rows as $r) { [$status, $note] = camp_verify_email($r['email'], $dns); q('UPDATE camp_contacts SET verify=?, verify_note=? WHERE id=?', [$status, $note, $r['id']]); }
            out(['ok' => true, 'checked' => count($rows), 'remaining' => (int)one("SELECT COUNT(*) c FROM camp_contacts WHERE list_id=? AND verify='unchecked'", [$rid])['c']]);
        }
        if ($act === 'remove_invalid' && $method === 'POST') { $n = q("DELETE FROM camp_contacts WHERE list_id=? AND (verify='invalid' OR status='bounced')", [$rid])->rowCount(); out(['ok' => true, 'removed' => $n]); }
    }
    fail('Bad request', 405);
}
if ($res === 'contact' && $rid) {
    $c = one('SELECT * FROM camp_contacts WHERE id=?', [$rid]); if (!$c) fail('Not found', 404);
    if ($method === 'DELETE') { q('DELETE FROM camp_contacts WHERE id=?', [$rid]); out(['ok' => true]); }
    if ($method === 'PATCH') {
        $b = body(); $st = in($b['status'] ?? $c['status'], $STATUS, $c['status']);
        if ($st === 'subscribed' && one('SELECT 1 FROM camp_suppress WHERE email=?', [$c['email']])) fail('This person unsubscribed themselves, so they cannot be re-subscribed here. They must opt in again.');
        q('UPDATE camp_contacts SET status=?, first_name=?, last_name=?, company=? WHERE id=?', [$st, s($b['first_name'] ?? $c['first_name'], 100) ?: null, s($b['last_name'] ?? $c['last_name'], 100) ?: null, s($b['company'] ?? $c['company'], 160) ?: null, $rid]);
        out(['ok' => true]);
    }
    fail('Bad request', 405);
}

/* ---------- templates ---------- */
if ($res === 'templates') {
    if ($method === 'GET' && !$rid) out(all('SELECT id,name,subject,updated_at FROM camp_templates ORDER BY updated_at DESC'));
    if ($method === 'GET' && $rid) { $t = one('SELECT * FROM camp_templates WHERE id=?', [$rid]); if (!$t) fail('Not found', 404); $t['design'] = json_decode($t['design'], true); out($t); }
    if ($method === 'POST' && !$rid) { $b = body(); $n = s($b['name'] ?? '', 120); if ($n === '') fail('Give the template a name.'); q('INSERT INTO camp_templates (name,subject,design,created_by) VALUES (?,?,?,?)', [$n, s($b['subject'] ?? '', 200) ?: null, camp_design_in($b['design'] ?? camp_default_design()), $uid]); out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201); }
    if ($rid && $method === 'PATCH') { $t = one('SELECT * FROM camp_templates WHERE id=?', [$rid]); if (!$t) fail('Not found', 404); $b = body(); q('UPDATE camp_templates SET name=?, subject=?, design=? WHERE id=?', [s($b['name'] ?? $t['name'], 120) ?: $t['name'], s($b['subject'] ?? $t['subject'], 200) ?: null, array_key_exists('design', $b) ? camp_design_in($b['design']) : $t['design'], $rid]); out(['ok' => true]); }
    if ($rid && $method === 'DELETE') { q('DELETE FROM camp_templates WHERE id=?', [$rid]); out(['ok' => true]); }
    fail('Bad request', 405);
}

/* ---------- campaigns ---------- */
if ($res === 'tick' && $method === 'POST') { out(camp_tick(20) + ['campaigns' => all("SELECT id,status,total,sent,failed,last_error FROM camp_campaigns WHERE status IN ('sending','paused','scheduled')")]); }
if ($res === 'campaigns') {
    $cols = 'c.id,c.name,c.subject,c.status,c.list_id,l.name AS list_name,c.total,c.sent,c.failed,c.scheduled_at,c.started_at,c.finished_at,c.last_error,c.created_at,(SELECT COUNT(DISTINCT e.contact_id) FROM camp_events e WHERE e.campaign_id=c.id AND e.kind=\'open\') AS opens,(SELECT COUNT(DISTINCT e.contact_id) FROM camp_events e WHERE e.campaign_id=c.id AND e.kind=\'click\') AS clicks';
    if ($method === 'GET' && !$rid) out(all("SELECT $cols FROM camp_campaigns c LEFT JOIN camp_lists l ON l.id=c.list_id ORDER BY c.id DESC LIMIT 200"));
    if ($method === 'POST' && !$rid) {
        $b = body(); $n = s($b['name'] ?? '', 140); $sub = s($b['subject'] ?? '', 200); if ($n === '' || $sub === '') fail('A campaign needs a name and a subject line.');
        $lid = (int)($b['list_id'] ?? 0); if ($lid) camp_need_list($lid);
        $design = $b['design'] ?? null; if (!$design && !empty($b['template_id'])) { $t = one('SELECT design FROM camp_templates WHERE id=?', [(int)$b['template_id']]); $design = $t ? json_decode($t['design'], true) : null; }
        q('INSERT INTO camp_campaigns (name,subject,preheader,design,list_id,from_name,from_email,include_risky,created_by) VALUES (?,?,?,?,?,?,?,?,?)',
          [$n, $sub, s($b['preheader'] ?? '', 200) ?: null, camp_design_in($design ?: camp_default_design()), $lid ?: null, s($b['from_name'] ?? '', 120) ?: null, camp_clean_email((string)($b['from_email'] ?? '')) ?: null, !empty($b['include_risky']) ? 1 : 0, $uid]);
        out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201);
    }
    if ($rid) {
        $c = one('SELECT * FROM camp_campaigns WHERE id=?', [$rid]); if (!$c) fail('Not found', 404);
        if ($method === 'GET' && $act === '') { $c['design'] = json_decode($c['design'], true); $c['queue_failed'] = all("SELECT c.email, qu.error FROM camp_queue qu JOIN camp_contacts c ON c.id=qu.contact_id WHERE qu.campaign_id=? AND qu.status='failed' LIMIT 50", [$rid]); out($c); }
        $editable = in_array($c['status'], ['draft', 'paused'], true);
        if ($method === 'PATCH' && $act === '') {
            if (!$editable) fail('Only drafts and paused campaigns can be edited.'); $b = body();
            $lid = array_key_exists('list_id', $b) ? ((int)$b['list_id'] ?: null) : $c['list_id']; if ($lid) camp_need_list((int)$lid);
            q('UPDATE camp_campaigns SET name=?,subject=?,preheader=?,design=?,list_id=?,from_name=?,from_email=?,include_risky=? WHERE id=?',
              [s($b['name'] ?? $c['name'], 140) ?: $c['name'], s($b['subject'] ?? $c['subject'], 200) ?: $c['subject'], array_key_exists('preheader', $b) ? (s($b['preheader'], 200) ?: null) : $c['preheader'], array_key_exists('design', $b) ? camp_design_in($b['design']) : $c['design'], $lid,
               array_key_exists('from_name', $b) ? (s($b['from_name'], 120) ?: null) : $c['from_name'], array_key_exists('from_email', $b) ? (camp_clean_email((string)$b['from_email']) ?: null) : $c['from_email'], array_key_exists('include_risky', $b) ? (int)(bool)$b['include_risky'] : (int)$c['include_risky'], $rid]);
            out(['ok' => true]);
        }
        if ($method === 'DELETE' && $act === '') { if (in_array($c['status'], ['sending'], true)) fail('Pause the campaign before deleting it.'); q('DELETE FROM camp_campaigns WHERE id=?', [$rid]); out(['ok' => true]); }
        if ($act === 'test' && $method === 'POST') {
            $st = camp_settings(); $sm = camp_smtp($st); $b = body(); $to = camp_clean_email((string)($b['to'] ?? $u['email'])); if (!filter_var($to, FILTER_VALIDATE_EMAIL)) fail('Enter a valid address for the test.');
            $cc = ['first_name' => explode(' ', $u['name'])[0], 'last_name' => '', 'company' => 'Example Ltd', 'email' => $to, 'token' => str_repeat('0', 32)];
            $c2 = $c; $c2['subject'] = '[Test] ' . $c['subject']; $sm->deliver($c['from_email'] ?: $st['from_email'], [$to], camp_build_raw($c2, $cc, $st)); out(['ok' => true]);
        }
        if ($act === 'start' && $method === 'POST') {
            if (!in_array($c['status'], ['draft', 'paused'], true)) fail('This campaign has already started.'); $b = body();
            if (!$c['list_id']) fail('Choose a list first.');
            $st = camp_settings(); if (empty($st['smtp_host']) || empty($st['secret_enc']) || empty($st['from_email'])) fail('Set up the sending account first (Campaigns → Settings).');
            if ($c['status'] === 'draft') {
                $unv = (int)one("SELECT COUNT(*) c FROM camp_contacts WHERE list_id=? AND status='subscribed' AND verify='unchecked'", [$c['list_id']])['c'];
                if ($unv > 0 && empty($b['skip_verify_warning'])) out(['needs_confirm' => 'verify', 'unchecked' => $unv], 409);
            }
            $at = !empty($b['schedule_at']) ? dt((string)$b['schedule_at']) : null;
            if ($c['status'] === 'draft') { $n = camp_queue_build($rid); if ($n === 0) fail('There is nobody to send to. The list has no subscribed contacts with a valid address.'); }
            if ($at && strtotime($at) > time() + 60) { q("UPDATE camp_campaigns SET status='scheduled', scheduled_at=?, last_error=NULL WHERE id=?", [$at, $rid]); log_act($uid, 'campaign_scheduled', $c['name']); out(['ok' => true, 'status' => 'scheduled']); }
            q("UPDATE camp_campaigns SET status='sending', started_at=COALESCE(started_at,NOW()), scheduled_at=NULL, last_error=NULL WHERE id=?", [$rid]); log_act($uid, 'campaign_started', $c['name']);
            out(['ok' => true, 'status' => 'sending']);
        }
        if ($act === 'pause' && $method === 'POST') { q("UPDATE camp_campaigns SET status='paused' WHERE id=? AND status IN ('sending','scheduled')", [$rid]); out(['ok' => true]); }
        if ($act === 'stats' && $method === 'GET') {
            $ev = fn($k) => (int)one('SELECT COUNT(DISTINCT contact_id) n FROM camp_events WHERE campaign_id=? AND kind=?', [$rid, $k])['n'];
            out(['sent' => (int)$c['sent'], 'failed' => (int)$c['failed'], 'opens' => $ev('open'), 'clicks' => $ev('click'), 'unsubs' => $ev('unsub'),
                 'links' => all("SELECT url, COUNT(*) clicks, COUNT(DISTINCT contact_id) people FROM camp_events WHERE campaign_id=? AND kind='click' GROUP BY url ORDER BY people DESC, clicks DESC LIMIT 10", [$rid]),
                 'recent' => all("SELECT e.kind, e.url, e.created_at, ct.email FROM camp_events e JOIN camp_contacts ct ON ct.id=e.contact_id WHERE e.campaign_id=? ORDER BY e.id DESC LIMIT 15", [$rid])]);
        }
    }
    fail('Bad request', 405);
}
/* ---------- auto-responders (drip sequences) ---------- */
function camp_need_auto(int $id): array { $a = one('SELECT * FROM camp_autos WHERE id=?', [$id]); if (!$a) fail('Auto-responder not found', 404); return $a; }
function camp_renumber(int $auto): void { $i = 1; foreach (all('SELECT id FROM camp_auto_steps WHERE auto_id=? ORDER BY position', [$auto]) as $r) { q('UPDATE camp_auto_steps SET position=? WHERE id=?', [1000 + $i, $r['id']]); $i++; } q('UPDATE camp_auto_steps SET position=position-1000 WHERE auto_id=?', [$auto]); }
if ($res === 'autos') {
    if ($method === 'GET' && !$rid) {
        out(all("SELECT a.id,a.name,a.status,a.list_id,l.name AS list_name,(SELECT COUNT(*) FROM camp_auto_steps x WHERE x.auto_id=a.id) steps,
                 (SELECT COUNT(*) FROM camp_auto_state s WHERE s.auto_id=a.id) enrolled, (SELECT COUNT(*) FROM camp_auto_state s WHERE s.auto_id=a.id AND s.status='active') active,
                 (SELECT COUNT(*) FROM camp_auto_state s WHERE s.auto_id=a.id AND s.status='done') finished, (SELECT COUNT(*) FROM camp_auto_log g WHERE g.auto_id=a.id AND g.status='sent') sent
                 FROM camp_autos a LEFT JOIN camp_lists l ON l.id=a.list_id ORDER BY a.id DESC"));
    }
    if ($method === 'POST' && !$rid) {
        $b = body(); $n = s($b['name'] ?? '', 140); if ($n === '') fail('Give the auto-responder a name.'); $lid = (int)($b['list_id'] ?? 0); if ($lid) camp_need_list($lid);
        q('INSERT INTO camp_autos (name,list_id,created_by) VALUES (?,?,?)', [$n, $lid ?: null, $uid]); $aid = (int)db()->lastInsertId();
        q('INSERT INTO camp_auto_steps (auto_id,position,delay_hours,subject,preheader,design) VALUES (?,?,?,?,?,?)', [$aid, 1, 0, 'Welcome to Aerosub', null, camp_design_in(camp_default_design())]);
        out(['ok' => true, 'id' => $aid], 201);
    }
    if ($rid) {
        $a = camp_need_auto($rid); $sid = isset($seg[4]) && ctype_digit($seg[4]) ? (int)$seg[4] : 0;
        if ($method === 'GET' && $act === '') {
            $a['steps'] = all('SELECT id,position,delay_hours,subject,preheader,design FROM camp_auto_steps WHERE auto_id=? ORDER BY position', [$rid]);
            foreach ($a['steps'] as &$st) {
                $st['design'] = json_decode($st['design'], true);
                $st['sent'] = (int)one("SELECT COUNT(*) n FROM camp_auto_log WHERE step_id=? AND status='sent'", [$st['id']])['n'];
                $st['opens'] = (int)one("SELECT COUNT(DISTINCT contact_id) n FROM camp_events WHERE step_id=? AND kind='open'", [$st['id']])['n'];
                $st['clicks'] = (int)one("SELECT COUNT(DISTINCT contact_id) n FROM camp_events WHERE step_id=? AND kind='click'", [$st['id']])['n'];
            } unset($st);
            $a['counts'] = one("SELECT COUNT(*) enrolled, SUM(status='active') active, SUM(status='done') finished, SUM(status='stopped') stopped FROM camp_auto_state WHERE auto_id=?", [$rid]);
            out($a);
        }
        if ($method === 'PATCH' && $act === '') {
            $b = body(); $lid = array_key_exists('list_id', $b) ? ((int)$b['list_id'] ?: null) : $a['list_id']; if ($lid) camp_need_list((int)$lid);
            q('UPDATE camp_autos SET name=?, list_id=?, from_name=?, from_email=? WHERE id=?', [s($b['name'] ?? $a['name'], 140) ?: $a['name'], $lid, array_key_exists('from_name', $b) ? (s($b['from_name'], 120) ?: null) : $a['from_name'], array_key_exists('from_email', $b) ? (camp_clean_email((string)$b['from_email']) ?: null) : $a['from_email'], $rid]);
            out(['ok' => true]);
        }
        if ($method === 'DELETE' && $act === '') { q('DELETE FROM camp_autos WHERE id=?', [$rid]); out(['ok' => true]); }
        if ($act === 'steps') {
            if ($method === 'POST' && !$sid) {
                if ($a['status'] === 'active') fail('Pause the auto-responder to add or remove steps.');
                $b = body(); $n = (int)one('SELECT COALESCE(MAX(position),0) m FROM camp_auto_steps WHERE auto_id=?', [$rid])['m']; if ($n >= 20) fail('An auto-responder can have up to 20 emails.');
                q('INSERT INTO camp_auto_steps (auto_id,position,delay_hours,subject,preheader,design) VALUES (?,?,?,?,?,?)', [$rid, $n + 1, max(0, min(24 * 90, (int)($b['delay_hours'] ?? 72))), s($b['subject'] ?? '', 200) ?: 'A message from Aerosub', s($b['preheader'] ?? '', 200) ?: null, camp_design_in($b['design'] ?? camp_default_design())]);
                out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201);
            }
            $step = $sid ? one('SELECT * FROM camp_auto_steps WHERE id=? AND auto_id=?', [$sid, $rid]) : null; if (!$step) fail('Step not found', 404);
            if ($method === 'PATCH') {
                $b = body();
                q('UPDATE camp_auto_steps SET delay_hours=?, subject=?, preheader=?, design=? WHERE id=?', [array_key_exists('delay_hours', $b) ? max(0, min(24 * 90, (int)$b['delay_hours'])) : (int)$step['delay_hours'], s($b['subject'] ?? $step['subject'], 200) ?: $step['subject'], array_key_exists('preheader', $b) ? (s($b['preheader'], 200) ?: null) : $step['preheader'], array_key_exists('design', $b) ? camp_design_in($b['design']) : $step['design'], $sid]);
                if (!empty($b['move']) && $a['status'] !== 'active') { // swap with the neighbour
                    $dir = (int)$b['move'] < 0 ? -1 : 1; $nb = one('SELECT id,position FROM camp_auto_steps WHERE auto_id=? AND position' . ($dir < 0 ? '<' : '>') . '? ORDER BY position ' . ($dir < 0 ? 'DESC' : 'ASC') . ' LIMIT 1', [$rid, $step['position']]);
                    if ($nb) { q('UPDATE camp_auto_steps SET position=0 WHERE id=?', [$sid]); q('UPDATE camp_auto_steps SET position=? WHERE id=?', [$step['position'], $nb['id']]); q('UPDATE camp_auto_steps SET position=? WHERE id=?', [$nb['position'], $sid]); }
                }
                out(['ok' => true]);
            }
            if ($method === 'DELETE') { if ($a['status'] === 'active') fail('Pause the auto-responder to add or remove steps.'); q('DELETE FROM camp_auto_steps WHERE id=?', [$sid]); camp_renumber($rid); out(['ok' => true]); }
            if ($method === 'POST' && ($seg[5] ?? '') === 'test') {
                $st = camp_settings(); $sm = camp_smtp($st); $b = body(); $to = camp_clean_email((string)($b['to'] ?? $u['email'])); if (!filter_var($to, FILTER_VALIDATE_EMAIL)) fail('Enter a valid address for the test.');
                $cc = ['first_name' => explode(' ', $u['name'])[0], 'last_name' => '', 'company' => 'Example Ltd', 'email' => $to, 'token' => str_repeat('0', 32)];
                $camp = ['id' => (int)$step['id'], 'subject' => '[Test] ' . $step['subject'], 'preheader' => $step['preheader'], 'design' => $step['design'], 'from_name' => $a['from_name'], 'from_email' => $a['from_email']];
                $sm->deliver($a['from_email'] ?: $st['from_email'], [$to], camp_build_raw($camp, $cc, $st)); out(['ok' => true]);
            }
            fail('Bad request', 405);
        }
        if ($act === 'activate' && $method === 'POST') {
            $b = body(); if (!$a['list_id']) fail('Choose the list whose new subscribers should get these emails.');
            if (!one('SELECT 1 FROM camp_auto_steps WHERE auto_id=?', [$rid])) fail('Add at least one email.');
            $st = camp_settings(); if (empty($st['smtp_host']) || empty($st['secret_enc']) || empty($st['from_email'])) fail('Set up the sending account first (Campaigns → Sending settings).');
            if ($a['status'] === 'draft') q("UPDATE camp_autos SET status='active', include_existing=?, activated_at=NOW() WHERE id=?", [!empty($b['include_existing']) ? 1 : 0, $rid]);
            else q("UPDATE camp_autos SET status='active' WHERE id=?", [$rid]);
            log_act($uid, 'autoresponder_started', $a['name']); out(['ok' => true]);
        }
        if ($act === 'pause' && $method === 'POST') { q("UPDATE camp_autos SET status='paused' WHERE id=? AND status='active'", [$rid]); out(['ok' => true]); }
    }
    fail('Bad request', 405);
}
fail('Not found', 404);

} catch (MailError $e) { fail($e->getMessage(), 502); }
