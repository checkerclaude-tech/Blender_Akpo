<?php
declare(strict_types=1);
// Route: /api/agent-owner/... The owner reviews what the agent proposed. Only the owner's office session with a current
// authenticator step-up works here (agent_site.php); the agent token and every other account are refused.
// Other screens: agent_owner_views.php.
// Uses $method and $seg from the router. Nothing here is reachable with the agent token.
//
//   GET    items?tab=review|approved|rejected|stale   list (review = pending and younger than 14 days)
//   GET    items/{id}                                  one item with its history
//   POST   items/{id}/approve   {edits?, assignments?, clear_flag?}
//   POST   items/{id}/reject    {reason_code}
//   POST   items/{id}/sync      retry the pipeline write for an approved item that is still "syncing"

require_once __DIR__ . '/agent_site.php';
require_once __DIR__ . '/agent_writeback.php';

const AGENT_REJECT_REASONS = ['wrong_service', 'wrong_country', 'too_late', 'already_known', 'bad_source', 'low_value'];
const AGENT_STALE_DAYS = 14;

$owner = agent_area_owner(); $oid = (int)$owner['id'];
$what = $seg[1] ?? ''; $iid = isset($seg[2]) && ctype_digit($seg[2]) ? (int)$seg[2] : null; $act = $seg[3] ?? '';
if ($what === 'chat') { require __DIR__ . '/agent_chat.php'; exit; }
if ($what === 'personal') { require __DIR__ . '/agent_relay_owner.php'; exit; }
if ($what !== 'items') { require __DIR__ . '/agent_owner_views.php'; exit; }

function agent_has_open_flag(array $payload): bool { return !empty($payload['risk_flags']) && empty($payload['flags_cleared']); }

if ($iid === null && $method === 'GET') {
    $tab = (string)($_GET['tab'] ?? 'review');
    $stale = AGENT_STALE_DAYS;
    $where = match ($tab) {
        'approved' => "status = 'approved'",
        'rejected' => "status = 'rejected'",
        'stale'    => "status = 'pending' AND created_at < (NOW() - INTERVAL $stale DAY)",
        default    => "status = 'pending' AND created_at >= (NOW() - INTERVAL $stale DAY)",
    };
    $c = one("SELECT SUM(status='pending' AND created_at >= (NOW() - INTERVAL $stale DAY)) review, SUM(status='pending' AND created_at < (NOW() - INTERVAL $stale DAY)) stale,
              SUM(status='approved') approved, SUM(status='rejected') rejected FROM agent_items");
    // The list shows what happened, who, which service and any open risk without opening each item, so a few fields are lifted out of the payload here.
    $rows = all("SELECT id, kind, headline, score, status, sync_state, created_at, decided_at, payload FROM agent_items WHERE $where ORDER BY score IS NULL, score DESC, id DESC LIMIT 200");
    foreach ($rows as &$r) {
        $p = json_decode((string)$r['payload'], true) ?: []; unset($r['payload']);
        $r['company_name'] = isset($p['company_name']) ? (string)$p['company_name'] : null;
        $r['service'] = isset($p['service']) ? (string)$p['service'] : null;
        $r['timing'] = isset($p['timing']) ? (string)$p['timing'] : null;
        $r['signal_type'] = isset($p['signal_type']) ? (string)$p['signal_type'] : null;
        $r['risk'] = agent_has_open_flag($p) ? (string)($p['risk_flags'][0]['type'] ?? 'risk') : null;
    }
    unset($r);
    out(['counts' => array_map('intval', array_map(fn($v) => $v ?? 0, $c)), 'items' => $rows]);
}

if ($iid !== null && $act === '' && $method === 'GET') {
    $it = agent_item_row($iid);
    $it['history'] = all('SELECT f.action, f.reason_code, f.created_at, u.name AS actor FROM agent_feedback f LEFT JOIN users u ON u.id = f.actor_id WHERE f.item_id = ? ORDER BY f.id', [$iid]);
    $it['flag_open'] = agent_has_open_flag($it['payload']);
    out($it);
}

if ($iid !== null && $act === 'approve' && $method === 'POST') {
    $b = body(); $it = agent_item_row($iid);
    if ($it['status'] !== 'pending') fail('This item has already been decided', 409);
    $payload = $it['payload']; $before = $payload;
    $edits = is_array($b['edits'] ?? null) ? $b['edits'] : [];
    foreach ($edits as $k => $v) { if (is_string($k) && $k !== 'sources' && $k !== 'flags_cleared') $payload[$k] = $v; } // the owner edits content, not the evidence
    if (!empty($b['clear_flag']) && agent_has_open_flag($payload)) {
        $payload['flags_cleared'] = true;
        q("INSERT INTO agent_feedback (item_id, actor_id, action) VALUES (?,?, 'clear_flag')", [$iid, $oid]);
    }
    if (agent_has_open_flag($payload)) fail('This company has an open risk flag. Clear it before approving.', 409);
    $assignments = []; foreach (($b['assignments'] ?? []) as $a) if (isset($a['index'])) $assignments[(int)$a['index']] = ['assignee_id' => $a['assignee_id'] ?? null, 'due_date' => $a['due_date'] ?? null];

    // Phase 1: record the decision. It stands even if the pipeline write below fails.
    $payload['_assignments'] = $assignments; // kept so an automatic retry assigns the same people
    $n = q("UPDATE agent_items SET status = 'approved', sync_state = 'syncing', payload = ?, decided_at = NOW(), decided_by = ? WHERE id = ? AND status = 'pending'", [json_encode($payload), $oid, $iid])->rowCount();
    if (!$n) fail('This item has already been decided', 409);
    q('INSERT INTO agent_feedback (item_id, actor_id, action, before_json, after_json) VALUES (?,?,?,?,?)',
      [$iid, $oid, $edits ? 'edit_approve' : 'approve', $edits ? json_encode($before) : null, $edits ? json_encode($payload) : null]);

    // Phase 2: write the pipeline rows.
    try {
        out(['ok' => true, 'sync' => 'synced', 'created' => agent_sync_item($iid, $oid, $assignments)]);
    } catch (Throwable $e) {
        out(['ok' => true, 'sync' => 'syncing', 'message' => "Approval saved, but the pipeline update didn't go through. We'll retry automatically."], 202);
    }
}

if ($iid !== null && $act === 'sync' && $method === 'POST') {
    $it = agent_item_row($iid);
    if ($it['status'] !== 'approved' || $it['sync_state'] !== 'syncing') fail('Nothing to retry', 409);
    try { out(['ok' => true, 'sync' => 'synced', 'created' => agent_sync_item($iid, $oid, [])]); }
    catch (Throwable $e) { out(['ok' => false, 'sync' => 'syncing', 'message' => 'Still could not update the pipeline.'], 202); }
}

if ($iid !== null && $act === 'reject' && $method === 'POST') {
    $reason = (string)(body()['reason_code'] ?? '');
    if (!in_array($reason, AGENT_REJECT_REASONS, true)) fail('Choose a reason for rejecting', 422);
    agent_item_row($iid);
    $n = q("UPDATE agent_items SET status = 'rejected', decided_at = NOW(), decided_by = ? WHERE id = ? AND status = 'pending'", [$oid, $iid])->rowCount();
    if (!$n) fail('This item has already been decided', 409);
    q("INSERT INTO agent_feedback (item_id, actor_id, action, reason_code) VALUES (?,?, 'reject', ?)", [$iid, $oid, $reason]);
    log_act($oid, 'agent_rejected', $reason);
    out(['ok' => true]);
}

fail('Bad request', 404);
