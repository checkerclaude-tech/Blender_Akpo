<?php
declare(strict_types=1);
// Team-facing routes (signed-in office users; founders are read-only):
//   /api/documents            GET list, POST upload (base64 JSON), GET /{id} detail, GET /{id}/file, POST /{id}/retry
//   /api/research-requests    GET own (managers and admins: all), POST create
//   /api/agent-ratings        POST rate an agent-sourced row, GET own ratings
//   /api/agent-badges         GET score, approver and your own rating for agent-created rows (the Agent badge)
// Uses $r, $method, $seg from the router. The usual session + CSRF rules apply (index.php).

require_once __DIR__ . '/agent_token.php';
require_once __DIR__ . '/agent_docs.php';

$u = need_user(); $uid = (int)$u['id'];
$mgr = in_array($u['role'], ['super_admin', 'manager'], true);
function agent_team_write(array $u): void { if ($u['role'] === 'founder') fail('Founder accounts are read-only', 403); }
function agent_ini_bytes(string $k): int {
    $v = trim((string)ini_get($k)); if ($v === '' || $v === '-1') return 0;
    $n = (int)$v; return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}
/** Largest file we accept: the configured limit, but never more than the server can actually receive (the file travels as base64, about 4/3 bigger). */
function agent_doc_max_bytes(): int {
    $cfgMax = max(1, min(64, (int)(cfg('agent_doc_max_mb') ?: 8))) * 1048576;
    $post = agent_ini_bytes('post_max_size');
    return $post > 0 ? min($cfgMax, (int)(floor(($post * 3 / 4 - 4096) / 1048576)) * 1048576) : $cfgMax;
}
function agent_doc_too_big(): never { fail('This file is larger than ' . intdiv(agent_doc_max_bytes(), 1048576) . ' MB. Upload a smaller file.', 413); }
$sid = isset($seg[1]) && ctype_digit($seg[1]) ? (int)$seg[1] : null; $act = $seg[2] ?? '';

/** Who may see an uploaded document (owner decision 2026-11-13): the person who uploaded it, and owners and managers. Anyone else gets "not found",
 *  the same answer as for a number that does not exist, so nothing is revealed. A document with no recorded uploader is for managers only. */
function agent_doc_visible(array $u, bool $mgr, ?array $doc): bool {
    return $doc !== null && ($mgr || ((int)($doc['uploaded_by'] ?? 0) === (int)$u['id'] && (int)$u['id'] > 0));
}

/* ============ documents ============ */
if ($r === 'documents') {
    if ($method === 'GET' && ($seg[1] ?? '') === 'limits') out(['max_mb' => intdiv(agent_doc_max_bytes(), 1048576)]);
    if ($method === 'GET' && $sid === null) out(agent_doc_list(!empty($_GET['company_id']) ? (int)$_GET['company_id'] : null, 200, $mgr ? null : $uid));

    if ($method === 'GET' && $sid !== null && $act === '') {
        if (!agent_doc_visible($u, $mgr, one('SELECT uploaded_by FROM documents WHERE id = ?', [$sid]) ?: null)) fail('Not found', 404);
        $d = agent_doc_detail($sid); if (!$d) fail('Not found', 404); out($d);
    }

    if ($method === 'GET' && $sid !== null && $act === 'file') {
        $d = one('SELECT original_name, mime, storage_path, uploaded_by FROM documents WHERE id = ?', [$sid]);
        if (!agent_doc_visible($u, $mgr, $d ?: null)) fail('Not found', 404);
        $dir = realpath(agent_doc_dir()); $path = $dir ? realpath($dir . '/' . $d['storage_path']) : false;
        if (!$path || !str_starts_with($path, $dir . DIRECTORY_SEPARATOR) || !is_file($path)) fail('The file is no longer on the server', 404);
        header('Content-Type: ' . $d['mime']); header('Content-Length: ' . filesize($path)); header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '_', $d['original_name']) . '"');
        readfile($path); exit;
    }

    if ($method === 'POST' && $sid === null) {
        agent_team_write($u);
        $post = agent_ini_bytes('post_max_size');
        if ($post > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $post) agent_doc_too_big(); // PHP drops an over-size body before our code sees it
        $b = body();
        $name = mb_substr(preg_replace('/[\x00-\x1f\\\\\/]+/', '_', trim((string)($b['name'] ?? ''))) ?: '', 0, 190);
        $data = base64_decode((string)($b['data'] ?? ''), true);
        if ($name === '' || $data === false || $data === '') fail('Choose a PDF or Word file to upload');
        if (strlen($data) > agent_doc_max_bytes()) agent_doc_too_big();
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $isPdf = str_starts_with($data, '%PDF-');
        $isDocx = str_starts_with($data, "PK\x03\x04");
        if ($isDocx && class_exists('ZipArchive')) { // a real Word file has word/document.xml inside
            $tmp = tempnam(sys_get_temp_dir(), 'agd'); file_put_contents($tmp, $data); $z = new ZipArchive();
            $isDocx = $z->open($tmp) === true && $z->locateName('word/document.xml') !== false; if ($z->status === ZipArchive::ER_OK) $z->close(); @unlink($tmp);
        }
        if (!(($ext === 'pdf' && $isPdf) || ($ext === 'docx' && $isDocx))) fail('Only PDF and Word files can be checked.', 415);
        $hash = hash('sha256', $data);
        // Only YOUR OWN earlier upload of the same file counts as a duplicate. A colleague's identical file is never merged with yours, and its number is never handed back.
        $dup = one("SELECT id, checked_at FROM documents WHERE content_sha256 = ? AND uploaded_by = ? AND status <> 'failed' ORDER BY id LIMIT 1", [$hash, $uid]);
        if ($dup) out(['duplicate' => true, 'id' => (int)$dup['id'], 'checked_at' => $dup['checked_at']]);
        $companyId = !empty($b['company_id']) && one('SELECT id FROM companies WHERE id = ?', [(int)$b['company_id']]) ? (int)$b['company_id'] : null;
        $taskId = !empty($b['task_id']) && one('SELECT id FROM tasks WHERE id = ?', [(int)$b['task_id']]) ? (int)$b['task_id'] : null;
        $rel = bin2hex(random_bytes(16)) . '.' . $ext; $dir = agent_doc_dir();
        if (@file_put_contents($dir . '/' . $rel, $data) === false) fail('Upload failed. The server could not save the file.', 500);
        q('INSERT INTO documents (company_id, task_id, original_name, mime, size_bytes, content_sha256, storage_path, uploaded_by) VALUES (?,?,?,?,?,?,?,?)',
          [$companyId, $taskId, $name, $ext === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', strlen($data), $hash, $rel, $uid]);
        $id = (int)db()->lastInsertId(); log_act($uid, 'document_uploaded', $name);
        out(['id' => $id, 'status' => 'queued'], 201);
    }

    if ($method === 'POST' && $sid !== null && $act === 'retry') {
        agent_team_write($u); $d = one('SELECT uploaded_by, status FROM documents WHERE id = ?', [$sid]);
        if (!agent_doc_visible($u, $mgr, $d ?: null)) fail('Not found', 404);        // checked FIRST: the answer must not reveal the state of a file you cannot see
        if ($d['status'] !== 'failed') fail('Only a document that could not be checked can be retried', 409);
        q("UPDATE documents SET status = 'queued', status_note = NULL WHERE id = ?", [$sid]); out(['ok' => true]);
    }
    fail('Bad request', 404);
}

/* ============ research requests ============ */
if ($r === 'research-requests') {
    if ($method === 'GET') {
        $rows = all("SELECT rr.id, rr.status, rr.note, rr.created_at, c.name AS company_name, t.title AS task_title, u.name AS requested_by_name
                     FROM research_requests rr LEFT JOIN companies c ON c.id = rr.company_id LEFT JOIN tasks t ON t.id = rr.task_id LEFT JOIN users u ON u.id = rr.requested_by
                     " . ($mgr ? '' : 'WHERE rr.requested_by = ' . $uid) . ' ORDER BY rr.id DESC LIMIT 200');
        out($rows);
    }
    if ($method === 'POST') {
        agent_team_write($u); $b = body();
        $companyId = !empty($b['company_id']) ? (int)$b['company_id'] : null; $taskId = !empty($b['task_id']) ? (int)$b['task_id'] : null;
        if (!$companyId && !$taskId) fail('Choose a company or an RFQ to research');
        if ($companyId && !one('SELECT id FROM companies WHERE id = ?', [$companyId])) fail('Company not found', 404);
        if ($taskId && !one('SELECT id FROM tasks WHERE id = ?', [$taskId])) fail('RFQ not found', 404);
        $open = one("SELECT id FROM research_requests WHERE status IN ('waiting','researching') AND " . ($companyId ? 'company_id = ?' : 'task_id = ?'), [$companyId ?: $taskId]);
        if ($open) out(['error' => 'A request for this is already open', 'id' => (int)$open['id']], 409);
        q('INSERT INTO research_requests (company_id, task_id, requested_by, note) VALUES (?,?,?,?)', [$companyId, $taskId, $uid, s($b['note'] ?? '', 500) ?: null]);
        $newId = (int)db()->lastInsertId(); // read before log_act(), which inserts a row of its own
        log_act($uid, 'research_requested', $companyId ? 'company ' . $companyId : 'task ' . $taskId);
        out(['id' => $newId], 201);
    }
    fail('Bad request', 404);
}

/* ============ ratings (team answers, kept apart from the owner's decisions) ============ */
if ($r === 'agent-ratings') {
    if ($method === 'GET') {
        $type = in((string)($_GET['subject_type'] ?? ''), ['company', 'contact', 'task'], '');
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))));
        if ($type === '' || !$ids) out([]);
        out(all('SELECT subject_id, rating FROM agent_ratings WHERE user_id = ? AND subject_type = ? AND subject_id IN (' . implode(',', array_slice($ids, 0, 200)) . ')', [$uid, $type]));
    }
    if ($method === 'POST') {
        agent_team_write($u); $b = body();
        $type = in((string)($b['subject_type'] ?? ''), ['company', 'contact', 'task'], ''); $sub = (int)($b['subject_id'] ?? 0);
        $rating = in((string)($b['rating'] ?? ''), ['useful', 'not_useful'], '');
        if ($type === '' || !$sub || $rating === '') fail('Choose Useful or Not useful', 422);
        $table = ['company' => 'companies', 'contact' => 'contacts', 'task' => 'tasks'][$type];
        if (!one("SELECT id FROM $table WHERE id = ? AND source = 'agent'", [$sub])) fail('Only items suggested by the agent can be rated', 422);
        q('REPLACE INTO agent_ratings (user_id, subject_type, subject_id, rating) VALUES (?,?,?,?)', [$uid, $type, $sub, $rating]);
        out(['ok' => true]);
    }
    fail('Bad request', 404);
}
/* ============ badges: which rows the agent suggested, with score and who approved ============ */
if ($r === 'agent-badges') {
    if ($method !== 'GET') fail('Bad request', 404);
    $type = in((string)($_GET['subject_type'] ?? ''), ['company', 'contact', 'task'], '');
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))));
    if ($type === '' || !$ids) out([]);
    out(all('SELECT o.subject_id, o.score, u.name AS approved_by_name,
                    (SELECT r.rating FROM agent_ratings r WHERE r.user_id = ? AND r.subject_type = o.subject_type AND r.subject_id = o.subject_id) AS rating
             FROM agent_origins o LEFT JOIN users u ON u.id = o.approved_by
             WHERE o.subject_type = ? AND o.subject_id IN (' . implode(',', array_slice($ids, 0, 300)) . ')', [$uid, $type]));
}
fail('Not found', 404);
