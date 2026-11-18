<?php
declare(strict_types=1);
// Routes under /api/agent/* , called only by the agent laptop with an X-Agent-Token header.
// This file never looks at the signed-in session and never approves anything: approval is owner-only (agent_owner.php).
// Uses $method and $seg from the router. The laptop talks to this through ONE client module (PRD N11).

require_once __DIR__ . '/agent_token.php';

const AGENT_LABELS = ['supported', 'partly_supported', 'not_supported', 'cannot_check', 'cited_not_online', 'source_suggested', 'no_source', 'author_flagged'];
const AGENT_TIERS = ['primary', 'news', 'weak'];
const AGENT_CANNOT_CHECK_REASONS = ['dead', 'blocked', 'paywalled', 'login'];
const AGENT_ITEM_KINDS = ['signal', 'company', 'contact', 'task', 'research'];

function agent_http_url(mixed $u): bool { return is_string($u) && strlen($u) <= 500 && (bool)preg_match('#^https?://[^\s]+$#i', $u); }

/**
 * Runs a write once per Idempotency-Key. A retry with the same key gets the first answer back and writes nothing (PRD N4).
 * $do() must return the response array.
 */
function agent_once(array $tok, string $route, callable $do): never {
    $key = s($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '', 80);
    if (!preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $key)) fail('Idempotency-Key header required (8 to 80 letters, digits or . _ : -)');
    $prev = one('SELECT response FROM agent_idempotency WHERE token_id = ? AND idem_key = ?', [$tok['id'], $key]);
    if ($prev) out(['replayed' => true] + json_decode($prev['response'], true));
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $resp = $do();
        q('INSERT INTO agent_idempotency (idem_key, token_id, route, response) VALUES (?,?,?,?)', [$key, $tok['id'], $route, json_encode($resp)]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $again = one('SELECT response FROM agent_idempotency WHERE token_id = ? AND idem_key = ?', [$tok['id'], $key]);
        if ($again) out(['replayed' => true] + json_decode($again['response'], true));
        throw $e;
    }
    out($resp, 201);
}

function agent_need_document(int $id): array {
    $d = one('SELECT * FROM documents WHERE id = ?', [$id]);
    if (!$d) fail('Not found', 404);
    return $d;
}

$tok = agent_authenticate();
$what = $seg[1] ?? '';
$aid = isset($seg[2]) && ctype_digit($seg[2]) ? (int)$seg[2] : null;
$act = $seg[3] ?? '';
$route = $what . ($aid !== null ? '/' . $act : '');
// Reads and heartbeats are not logged: the laptop polls every minute and would bury the Activity page.
if ($method !== 'GET' && $what !== 'heartbeat') log_act(null, 'agent_call', mb_substr($tok['label'] . ' ' . $method . ' /' . $what . ($aid !== null ? '/' . $aid . '/' . $act : ''), 0, 290));

/* ---- heartbeat: the laptop says it is online and what it is doing ---- */
if ($what === 'heartbeat' && $method === 'POST') {
    $b = body();
    q('INSERT INTO agent_heartbeat (id, last_seen_at, queue_length, current_job, agent_version) VALUES (1, NOW(), ?, ?, ?)
       ON DUPLICATE KEY UPDATE last_seen_at = NOW(), queue_length = VALUES(queue_length), current_job = VALUES(current_job), agent_version = VALUES(agent_version)',
      [max(0, (int)($b['queue_length'] ?? 0)), s($b['current_job'] ?? '', 160) ?: null, s($b['agent_version'] ?? '', 40) ?: null]);
    out(['ok' => true]);
}

/* ---- work: what is waiting for the agent ---- */
if ($what === 'work' && $method === 'GET') {
    out([
        'documents' => all("SELECT id, original_name, mime, size_bytes, content_sha256, company_id, task_id, created_at FROM documents WHERE status = 'queued' ORDER BY id LIMIT 10"),
        'requests'  => all("SELECT r.id, r.company_id, r.task_id, r.note, COALESCE(c.name, r.subject_name) AS company_name, t.title AS task_title FROM research_requests r LEFT JOIN companies c ON c.id = r.company_id LEFT JOIN tasks t ON t.id = r.task_id WHERE r.status = 'waiting' ORDER BY r.id LIMIT 10"),
        // companies worth watching every night: the live pipeline (not won, not on hold). Names only.
        'watch'     => array_column(all("SELECT name FROM companies WHERE stage NOT IN ('won','on_hold') ORDER BY (priority = 'high') DESC, updated_at DESC LIMIT 40"), 'name'),
        // actions the owner approved in chat (today only: open a link and report on it)
        'chat_actions' => array_map(fn($m) => ['id' => (int)$m['id'], 'kind' => $m['action_kind'], 'args' => json_decode((string)$m['action_args'], true) ?: []],
                                    all("SELECT id, action_kind, action_args FROM agent_chat_messages WHERE action_state = 'approved' ORDER BY id LIMIT 10")),
    ]);
}

/* ---- settings the owner edits on the Agent site (read-only for the laptop) ---- */
if ($what === 'settings' && $method === 'GET') out(agent_settings_all());

/* ---- documents ---- */
if ($what === 'documents' && $aid !== null) {
    $d = agent_need_document($aid);

    if ($act === 'claim' && $method === 'POST') {
        $n = q("UPDATE documents SET status = 'checking', status_note = NULL WHERE id = ? AND status = 'queued'", [$aid])->rowCount();
        if (!$n) fail('This document is not waiting to be checked', 409);
        out(['ok' => true]);
    }

    if ($act === 'file' && $method === 'GET') {
        $dir = realpath(agent_doc_dir());
        $path = $dir ? realpath($dir . '/' . $d['storage_path']) : false;
        if (!$path || !str_starts_with($path, $dir . DIRECTORY_SEPARATOR) || !is_file($path)) fail('File not found', 404);
        header('Content-Type: ' . $d['mime']);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    if ($act === 'fail' && $method === 'POST') {
        q("UPDATE documents SET status = 'failed', status_note = ? WHERE id = ? AND status IN ('queued','checking')", [s(body()['note'] ?? 'The check did not finish', 200), $aid]);
        out(['ok' => true]);
    }

    if ($act === 'result' && $method === 'POST') {
        $b = body();
        $claims = $b['claims'] ?? null;
        if (!is_array($claims) || count($claims) > 500) fail('claims must be a list of at most 500 items', 422);
        $clean = [];
        foreach (array_values($claims) as $i => $c) {
            $label = (string)($c['label'] ?? '');
            if (!in_array($label, AGENT_LABELS, true)) fail("Claim " . ($i + 1) . ": unknown label", 422);
            $text = s($c['claim_text'] ?? '', 2000);
            if ($text === '') fail("Claim " . ($i + 1) . ": claim_text is required", 422);
            $url = $c['source_url'] ?? null; $quote = s($c['quote'] ?? '', 4000);
            // Server-side backstop for "no unverified Supported": a code-confirmed quote and a real link must come with it.
            if (in_array($label, ['supported', 'partly_supported'], true) && ($quote === '' || !agent_http_url($url))) fail("Claim " . ($i + 1) . ": '$label' needs a source link and a quote", 422);
            if ($label === 'not_supported' && !agent_http_url($url)) fail("Claim " . ($i + 1) . ": 'not_supported' needs the source link that was opened", 422);
            $reason = $c['reason'] ?? null;
            if ($label === 'cannot_check' && !in_array($reason, AGENT_CANNOT_CHECK_REASONS, true)) fail("Claim " . ($i + 1) . ": cannot_check needs a reason", 422);
            $tier = $c['source_tier'] ?? null;
            if ($tier !== null && !in_array($tier, AGENT_TIERS, true)) fail("Claim " . ($i + 1) . ": unknown source_tier", 422);
            $clean[] = [$aid, $i + 1, $text, s($c['claim_kind'] ?? 'other', 30), $label, $reason, agent_http_url($url) ? $url : null, $tier, $quote ?: null, max(0, min(65000, (int)($c['check_priority'] ?? 0)))];
        }
        $scorecard = $b['scorecard'] ?? null;
        if (!is_array($scorecard)) fail('scorecard is required', 422);
        agent_once($tok, $route, function () use ($aid, $clean, $scorecard, $b) {
            q('DELETE FROM document_claims WHERE document_id = ?', [$aid]);
            foreach ($clean as $row) q('INSERT INTO document_claims (document_id, position, claim_text, claim_kind, label, reason, source_url, source_tier, quote, check_priority) VALUES (?,?,?,?,?,?,?,?,?,?)', $row);
            q('REPLACE INTO document_reviews (document_id, scorecard, summary, action_points, conflicts) VALUES (?,?,?,?,?)',
              [$aid, json_encode($scorecard), s($b['summary'] ?? '', 5000) ?: null, json_encode($b['action_points'] ?? []), json_encode($b['conflicts'] ?? [])]);
            q("UPDATE documents SET status = 'ready', status_note = NULL, checked_at = NOW() WHERE id = ?", [$aid]);
            return ['ok' => true, 'claims' => count($clean)];
        });
    }
    fail('Bad request', 404);
}

/* ---- chat: the laptop reports what it found for an action the owner approved ---- */
if ($what === 'chat' && $aid !== null && in_array($act, ['result', 'fail'], true) && $method === 'POST') {
    $m = one("SELECT id, chat_id FROM agent_chat_messages WHERE id = ? AND action_state = 'approved'", [$aid]);
    if (!$m) fail('This request is not waiting for an answer', 409);
    $b = body();
    $text = s($b['body'] ?? '', 2000);
    if ($act === 'fail') $text = "I couldn't do that. " . (s($b['note'] ?? '', 300) ?: 'The check did not finish.');
    elseif ($text === '') fail('body is required', 422);
    $src = [];
    foreach (array_slice((array)($b['sources'] ?? []), 0, 5) as $x) {
        $href = (string)($x['href'] ?? ''); $label = s($x['text'] ?? '', 120);
        if ($label !== '' && agent_http_url($href)) $src[] = ['text' => $label, 'href' => $href];
    }
    agent_once($tok, $route, function () use ($m, $aid, $text, $src, $act) {
        q('INSERT INTO agent_chat_messages (chat_id, role, body, sources) VALUES (?,?,?,?)', [$m['chat_id'], 'agent', $text, $src ? json_encode($src, JSON_UNESCAPED_SLASHES) : null]);
        q('UPDATE agent_chats SET updated_at = NOW() WHERE id = ?', [$m['chat_id']]);
        q("UPDATE agent_chat_messages SET action_state = ? WHERE id = ?", [$act === 'fail' ? 'failed' : 'done', $aid]);
        return ['ok' => true];
    });
}

/* ---- what the owner decided, so the agent can learn which proposals he wants (PRD P6 for the Research agent) ---- */
if ($what === 'decisions' && $method === 'GET') {
    $rows = all("SELECT f.action, f.reason_code, f.created_at, i.payload, i.score FROM agent_feedback f JOIN agent_items i ON i.id = f.item_id
                 WHERE i.kind = 'signal' AND f.action IN ('approve','edit_approve','reject') ORDER BY f.id DESC LIMIT 300");
    out(array_map(function ($r) {
        $p = json_decode((string)$r['payload'], true) ?: [];
        $urls = array_values(array_filter(array_map(fn($x) => $x['url'] ?? null, (array)($p['sources'] ?? []))));
        return ['decision' => $r['action'] === 'reject' ? 'rejected' : 'approved', 'reason' => $r['reason_code'], 'signal_type' => $p['signal_type'] ?? null,
                'service' => $p['service'] ?? null, 'company_name' => $p['company_name'] ?? null, 'score' => $r['score'] === null ? null : (int)$r['score'],
                'source_urls' => array_slice($urls, 0, 3), 'at' => $r['created_at']];
    }, $rows));
}

/* ---- research requests ---- */
if ($what === 'requests' && $aid !== null && $act === 'status' && $method === 'POST') {
    $st = (string)(body()['status'] ?? '');
    if (!in_array($st, ['researching', 'ready', 'failed'], true)) fail('Unknown status', 422);
    if (!q('UPDATE research_requests SET status = ? WHERE id = ?', [$st, $aid])->rowCount() && !one('SELECT 1 FROM research_requests WHERE id = ?', [$aid])) fail('Not found', 404);
    out(['ok' => true]);
}

/* ---- proposals: things for the owner to approve. Nothing here reaches the pipeline. ---- */
if ($what === 'items' && $method === 'POST' && $aid === null) {
    $b = body();
    $kind = (string)($b['kind'] ?? '');
    if (!in_array($kind, AGENT_ITEM_KINDS, true)) fail('Unknown kind', 422);
    $headline = s($b['headline'] ?? '', 300);
    if ($headline === '') fail('headline is required', 422);
    $payload = $b['payload'] ?? null;
    if (!is_array($payload)) fail('payload must be an object', 422);
    if ($kind === 'signal') { // R7: a signal must carry at least one supported source with a link
        $ok = false; foreach (($payload['sources'] ?? []) as $src) if (($src['label'] ?? '') === 'supported' && agent_http_url($src['url'] ?? null)) $ok = true;
        if (!$ok) fail('A signal needs at least one supported source with a link', 422);
    }
    $json = json_encode($payload);
    if (strlen($json) > 200000) fail('payload is too large', 422);
    $score = isset($b['score']) ? max(0, min(100, (int)$b['score'])) : null;
    $key = s($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '', 80);
    $reqId = !empty($b['request_id']) ? (int)$b['request_id'] : null;
    agent_once($tok, $route, function () use ($kind, $headline, $score, $json, $reqId, $key) {
        q('INSERT INTO agent_items (kind, headline, score, payload, request_id, idempotency_key) VALUES (?,?,?,?,?,?)', [$kind, $headline, $score, $json, $reqId, $key]);
        return ['ok' => true, 'id' => (int)db()->lastInsertId()];
    });
}
if ($what === 'items' && $aid !== null && $act === 'decision' && $method === 'GET') {
    $it = one('SELECT id, status, sync_state, decided_at FROM agent_items WHERE id = ?', [$aid]);
    if (!$it) fail('Not found', 404);
    $it['reason_code'] = (one('SELECT reason_code FROM agent_feedback WHERE item_id = ? ORDER BY id DESC LIMIT 1', [$aid])['reason_code'] ?? null);
    out($it);
}

fail('Not found', 404);
