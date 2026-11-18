<?php
declare(strict_types=1);
// The Agent site's screens other than the approval inbox. Included by agent_owner.php after the owner is verified.
// Uses $method, $seg, $owner, $oid.
//
//   GET   today            one list for the day (A1)
//   GET   team-tasks       open tasks assigned to other people (A2)
//   PATCH team-tasks/{id}  {status?, due_date?, assignee_id?, note?}  recorded under the owner's name
//   GET   crm?tab=companies|contacts|rfqs|competitors&q=     read-only (A3)
//   GET   crm/company/{id} company with contacts, tasks and stage history
//   GET   people           active staff the owner can assign work to
//   GET   health           agent online/offline, last seen, queue (A5)
//   GET   documents, documents/{id}    checked documents (A7)
//   GET   requests         research requests from the team (A8)
//   GET/PUT settings      scoring weights, public source list, schedules (A6)

require_once __DIR__ . '/agent_docs.php';

$view = $seg[1] ?? ''; $vid = isset($seg[2]) && ctype_digit($seg[2]) ? (int)$seg[2] : null; $vsub = $seg[2] ?? '';
const AGENT_ONLINE_WITHIN_SECONDS = 1800; // PRD A5: not reported in for 30 minutes means offline

function agent_status(): array {
    $hb = one('SELECT last_seen_at, queue_length, current_job, UNIX_TIMESTAMP() - UNIX_TIMESTAMP(last_seen_at) AS age FROM agent_heartbeat WHERE id = 1');
    return ['online' => $hb && (int)$hb['age'] <= AGENT_ONLINE_WITHIN_SECONDS, 'last_seen_at' => $hb['last_seen_at'] ?? null,
            'queue_length' => (int)($hb['queue_length'] ?? 0), 'current_job' => $hb['current_job'] ?? null];
}

/* ---------- Today ---------- */
if ($view === 'today' && $method === 'GET') {
    $status = agent_status();
    $pending = one("SELECT COUNT(*) c FROM agent_items WHERE status = 'pending'")['c'];
    out([
        'agent' => $status,
        'digest' => [
            'new_documents' => (int)one('SELECT COUNT(*) c FROM documents WHERE created_at >= (NOW() - INTERVAL 1 DAY)')['c'],
            'new_requests'  => (int)one('SELECT COUNT(*) c FROM research_requests WHERE created_at >= (NOW() - INTERVAL 1 DAY)')['c'],
            'waiting_for_you' => (int)$pending,
        ],
        'needs_approval' => all("SELECT id, kind, headline, score, created_at FROM agent_items WHERE status = 'pending' ORDER BY score IS NULL, score DESC LIMIT 5"),
        'due' => all("SELECT t.id, t.title, t.due_date, t.priority, c.name AS company_name, t.due_date < CURDATE() AS overdue FROM tasks t LEFT JOIN companies c ON c.id = t.company_id
                      WHERE t.assignee_id = ? AND t.status IN ('todo','in_progress','blocked') AND t.due_date IS NOT NULL AND t.due_date <= CURDATE() ORDER BY t.due_date LIMIT 50", [$oid]),
        'deadlines' => all("SELECT t.id, t.title, t.due_date, a.name AS assignee_name, c.name AS company_name FROM tasks t LEFT JOIN users a ON a.id = t.assignee_id LEFT JOIN companies c ON c.id = t.company_id
                            WHERE t.task_type = 'rfq' AND t.status IN ('todo','in_progress','blocked') AND t.due_date BETWEEN CURDATE() AND (CURDATE() + INTERVAL 14 DAY) ORDER BY t.due_date LIMIT 50"),
        'followups' => all("SELECT ct.id, ct.name, ct.followup_date, c.name AS company_name FROM contacts ct LEFT JOIN companies c ON c.id = ct.company_id
                            WHERE ct.followup_date IS NOT NULL AND ct.followup_date <= (CURDATE() + INTERVAL 7 DAY) AND (ct.owner_id = ? OR ct.owner_id IS NULL) ORDER BY ct.followup_date LIMIT 50", [$oid]),
        'events' => all("SELECT id, name, starts_on, location, status FROM trade_events WHERE starts_on BETWEEN CURDATE() AND (CURDATE() + INTERVAL 14 DAY) AND status IN ('considering','registered','attending') ORDER BY starts_on LIMIT 20"),
        // Weekly career actions come from the Personal agent on the laptop (built later); until then only availability is reported.
        'career' => ['available' => $status['online'], 'items' => []],
    ]);
}

/* ---------- Team tasks ---------- */
if ($view === 'team-tasks') {
    if ($method === 'GET' && $vid === null) {
        out(all("SELECT t.id, t.title, t.status, t.priority, t.due_date, t.assignee_id, a.name AS assignee_name, c.name AS company_name, t.source,
                        (SELECT body FROM task_comments k WHERE k.task_id = t.id ORDER BY k.id DESC LIMIT 1) AS last_note
                 FROM tasks t JOIN users a ON a.id = t.assignee_id LEFT JOIN companies c ON c.id = t.company_id
                 WHERE t.status IN ('todo','in_progress','blocked') AND t.assignee_id <> ? ORDER BY a.name, t.due_date IS NULL, t.due_date LIMIT 500", [$oid]));
    }
    if ($method === 'PATCH' && $vid !== null) {
        $t = one('SELECT * FROM tasks WHERE id = ?', [$vid]); if (!$t) fail('Not found', 404);
        $b = body(); $changes = [];
        if (isset($b['status'])) {
            $st = in((string)$b['status'], ['todo', 'in_progress', 'blocked', 'done'], '');
            if ($st === '') fail('Unknown status', 422);
            q('UPDATE tasks SET status = ?, completed_at = ? WHERE id = ?', [$st, $st === 'done' ? date('Y-m-d H:i:s') : null, $vid]); $changes[] = 'status ' . $st;
        }
        if (array_key_exists('due_date', $b)) {
            $dd = $b['due_date'] === null ? null : d((string)$b['due_date']); if ($b['due_date'] !== null && !$dd) fail('That date is not valid', 422);
            q('UPDATE tasks SET due_date = ? WHERE id = ?', [$dd, $vid]); $changes[] = 'due ' . ($dd ?? 'none');
            if ($t['assignee_id'] && (int)$t['assignee_id'] !== $oid) notify((int)$t['assignee_id'], 'task_date', 'Date changed: ' . $t['title'], $dd ? 'Now due ' . date('D j M', strtotime($dd)) : 'Due date removed', '#/tasks/' . $vid);
        }
        if (!empty($b['assignee_id'])) {
            if (!one('SELECT id FROM users WHERE id = ? AND active = 1', [(int)$b['assignee_id']])) fail('That person is not available', 422);
            q('UPDATE tasks SET assignee_id = ? WHERE id = ?', [(int)$b['assignee_id'], $vid]); $changes[] = 'reassigned';
            if ((int)$b['assignee_id'] !== $oid) notify((int)$b['assignee_id'], 'task_assigned', $t['title'], 'Assigned by ' . $owner['name'], '#/tasks/' . $vid);
        }
        $note = s($b['note'] ?? '', 2000);
        if ($note !== '') { q('INSERT INTO task_comments (task_id, user_id, body) VALUES (?,?,?)', [$vid, $oid, $note]); $changes[] = 'note'; }
        if (!$changes) fail('Nothing to change', 422);
        log_act($oid, 'agent_site_task', mb_substr($t['title'] . ': ' . implode(', ', $changes), 0, 290));
        out(['ok' => true]);
    }
}

/* ---------- CRM lookup: read-only by construction (GET only) ---------- */
if ($view === 'crm') {
    if ($method !== 'GET') fail('The CRM view is read-only', 405);
    $like = '%' . s($_GET['q'] ?? '', 60) . '%';
    if ($vsub === 'company' && isset($seg[3]) && ctype_digit($seg[3])) {
        $cid = (int)$seg[3];
        $c = one('SELECT c.id, c.name, c.sector, c.location, c.website, c.stage, c.priority, c.pain_points, c.current_solution, c.notes, c.flag_note, o.name AS owner_name
                  FROM companies c LEFT JOIN users o ON o.id = c.owner_id WHERE c.id = ?', [$cid]);
        if (!$c) fail('Not found', 404);
        $c['contacts'] = all('SELECT name, position, email, phone, followup_date FROM contacts WHERE company_id = ? ORDER BY name', [$cid]);
        $c['tasks'] = all('SELECT t.title, t.status, t.due_date, a.name AS assignee_name FROM tasks t LEFT JOIN users a ON a.id = t.assignee_id WHERE t.company_id = ? ORDER BY t.status = \'done\', t.due_date', [$cid]);
        $c['stage_history'] = all('SELECT h.from_stage, h.to_stage, h.created_at, u.name AS changed_by_name FROM company_stage_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.company_id = ? ORDER BY h.id DESC', [$cid]);
        out($c);
    }
    $tab = in((string)($_GET['tab'] ?? 'companies'), ['companies', 'contacts', 'rfqs', 'competitors'], 'companies');
    out(match ($tab) {
        'contacts' => all('SELECT ct.id, ct.name, ct.position, ct.email, ct.phone, c.name AS company_name FROM contacts ct LEFT JOIN companies c ON c.id = ct.company_id
                           WHERE ct.name LIKE ? OR ct.position LIKE ? OR c.name LIKE ? OR ct.email LIKE ? ORDER BY ct.name LIMIT 100', [$like, $like, $like, $like]),
        'rfqs' => all("SELECT t.id, t.title, t.status, t.due_date, c.name AS company_name, a.name AS assignee_name FROM tasks t LEFT JOIN companies c ON c.id = t.company_id LEFT JOIN users a ON a.id = t.assignee_id
                       WHERE t.task_type = 'rfq' AND (t.title LIKE ? OR c.name LIKE ?) ORDER BY t.due_date IS NULL, t.due_date LIMIT 100", [$like, $like]),
        'competitors' => all('SELECT id, name, category, threat, website FROM competitors WHERE name LIKE ? OR category LIKE ? ORDER BY name LIMIT 100', [$like, $like]),
        default => all('SELECT c.id, c.name, c.sector, c.stage, c.priority, o.name AS owner_name FROM companies c LEFT JOIN users o ON o.id = c.owner_id
                        WHERE c.name LIKE ? OR c.sector LIKE ? OR c.location LIKE ? ORDER BY c.name LIMIT 100', [$like, $like, $like]),
    });
}

/* ---------- People the owner can assign work to ---------- */
if ($view === 'people' && $method === 'GET') out(all("SELECT id, name, job_title FROM users WHERE active = 1 AND role <> 'founder' ORDER BY name"));

/* ---------- Health ---------- */
if ($view === 'health' && $method === 'GET') {
    out(agent_status() + ['documents_waiting' => (int)one("SELECT COUNT(*) c FROM documents WHERE status IN ('queued','checking')")['c'],
                          'requests_waiting' => (int)one("SELECT COUNT(*) c FROM research_requests WHERE status IN ('waiting','researching')")['c']]);
}

/* ---------- Documents and requests ---------- */
if ($view === 'documents' && $method === 'GET') {
    if ($vid === null) out(agent_doc_list());
    $d = agent_doc_detail($vid); if (!$d) fail('Not found', 404); out($d);
}
if ($view === 'requests' && $method === 'GET') {
    out(all("SELECT rr.id, rr.status, rr.note, rr.created_at, c.name AS company_name, t.title AS task_title, u.name AS requested_by_name
             FROM research_requests rr LEFT JOIN companies c ON c.id = rr.company_id LEFT JOIN tasks t ON t.id = rr.task_id LEFT JOIN users u ON u.id = rr.requested_by ORDER BY rr.id DESC LIMIT 200"));
}

/* ---------- Settings (weights must total 100; sources are public web addresses) ---------- */
if ($view === 'settings') {
    if ($method === 'GET') out(agent_settings_all());
    if ($method === 'PUT') {
        $b = body();
        if (isset($b['weights'])) {
            if (!is_array($b['weights'])) fail('weights must be a list of numbers', 422);
            $sum = 0; foreach ($b['weights'] as $k => $v) { if (!is_numeric($v) || $v < 0 || $v > 100) fail('Each weight must be between 0 and 100', 422); $sum += $v; }
            if ($b['weights'] && (int)round($sum) !== 100) fail("Weights add up to " . (int)round($sum) . ". Adjust them to total 100.", 422);
            agent_setting_put('weights', $b['weights'], $oid);
        }
        if (isset($b['sources'])) {
            if (!is_array($b['sources']) || count($b['sources']) > 50) fail('Add at most 50 sources', 422);
            $clean = []; foreach ($b['sources'] as $src) {
                $url = (string)($src['url'] ?? ''); if (!preg_match('#^https?://[^\s]{4,480}$#i', $url)) fail('Each source needs a web address starting with http:// or https://', 422);
                $clean[] = ['name' => s($src['name'] ?? $url, 120), 'url' => $url, 'kind' => in((string)($src['kind'] ?? 'site'), ['rss', 'site'], 'site')];
            }
            agent_setting_put('sources', $clean, $oid);
        }
        if (isset($b['schedules'])) {
            if (!is_array($b['schedules'])) fail('schedules must be a list', 422);
            $clean = []; foreach ($b['schedules'] as $k => $v) { if (!is_int($v) || $v < 5 || $v > 10080) fail('Each schedule must be 5 to 10080 minutes', 422); $clean[s((string)$k, 40)] = $v; }
            agent_setting_put('schedules', $clean, $oid);
        }
        log_act($oid, 'agent_settings_saved');
        out(agent_settings_all());
    }
}

fail('Not found', 404);
