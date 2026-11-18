<?php
declare(strict_types=1);
// Route: /api/agent-owner/chat... (Research agent chat). Included by agent_owner.php after the owner is verified.
// Uses $method, $seg, $owner, $oid. Understanding and answering are in agent_chat_intent.php.
//
//   GET    chat                         the latest conversation with its messages ({chat_id: null} if none yet)
//   POST   chat/messages {chat_id?, body}   send a message; the answer comes back at once (a new conversation is made if chat_id is empty)
//   POST   chat/actions/{id}/approve    run an action the agent asked for: research a company, or check a link (the laptop does that one)
//   POST   chat/actions/{id}/dismiss    "Not now"
//   DELETE chat/{chat_id}               delete the whole conversation
// Conversations older than 90 days are removed by cron.php.

require_once __DIR__ . '/agent_chat_intent.php';

const AGENT_CHAT_MAX = 1000;
const AGENT_CHAT_PER_HOUR = 60;

function agent_chat_dto(array $m): array {
    $src = $m['sources'] ? (json_decode((string)$m['sources'], true) ?: []) : [];
    $args = $m['action_args'] ? (json_decode((string)$m['action_args'], true) ?: []) : [];
    return ['id' => (int)$m['id'], 'role' => $m['role'], 'body' => $m['body'], 'created_at' => $m['created_at'], 'sources' => $src,
            'action' => $m['action_kind'] ? ['kind' => $m['action_kind'], 'state' => $m['action_state'], 'company' => $args['company'] ?? null, 'url' => $args['url'] ?? null] : null];
}
function agent_chat_add(int $chatId, string $role, string $body, array $sources = [], ?array $action = null): array {
    q('INSERT INTO agent_chat_messages (chat_id, role, body, sources, action_kind, action_args, action_state) VALUES (?,?,?,?,?,?,?)',
      [$chatId, $role, mb_substr($body, 0, 4000), $sources ? json_encode($sources, JSON_UNESCAPED_SLASHES) : null, $action['kind'] ?? null, $action ? json_encode($action['args']) : null, $action ? 'pending' : null]);
    $id = (int)db()->lastInsertId();
    q('UPDATE agent_chats SET updated_at = NOW() WHERE id = ?', [$chatId]);
    return agent_chat_dto(one('SELECT * FROM agent_chat_messages WHERE id = ?', [$id]));
}
function agent_chat_need_action(int $id): array {
    $m = one("SELECT * FROM agent_chat_messages WHERE id = ? AND action_kind IS NOT NULL AND role = 'agent'", [$id]);
    if (!$m) fail('Not found', 404);
    return $m;
}

$cw = $seg[2] ?? ''; $cid = isset($seg[2]) && ctype_digit($seg[2]) ? (int)$seg[2] : null;

if ($method === 'GET' && $cw === '') {
    $c = one("SELECT id FROM agent_chats WHERE agent = 'research' ORDER BY updated_at DESC, id DESC LIMIT 1");
    if (!$c) out(['chat_id' => null, 'messages' => []]);
    out(['chat_id' => (int)$c['id'], 'messages' => array_map('agent_chat_dto', all('SELECT * FROM agent_chat_messages WHERE chat_id = ? ORDER BY id LIMIT 300', [$c['id']]))]);
}

if ($method === 'POST' && $cw === 'messages') {
    $b = body(); $text = trim((string)($b['body'] ?? ''));
    if ($text === '') fail('Type a message first', 422);
    if (mb_strlen($text) > AGENT_CHAT_MAX) fail('That message is too long. Keep it under ' . AGENT_CHAT_MAX . ' characters.', 422);
    if ((int)one("SELECT COUNT(*) c FROM agent_chat_messages WHERE role = 'owner' AND created_at > (NOW() - INTERVAL 1 HOUR)")['c'] >= AGENT_CHAT_PER_HOUR) fail('Too many messages. Try again in a while.', 429);
    $chatId = (int)($b['chat_id'] ?? 0);
    if ($chatId && !one("SELECT id FROM agent_chats WHERE id = ? AND agent = 'research'", [$chatId])) fail('That conversation is no longer there', 404);
    if (!$chatId) { q("INSERT INTO agent_chats (agent) VALUES ('research')"); $chatId = (int)db()->lastInsertId(); }
    $mine = agent_chat_add($chatId, 'owner', $text);
    $reply = agent_chat_reply(agent_chat_parse($text));
    $theirs = agent_chat_add($chatId, 'agent', $reply['body'], $reply['sources'], $reply['action']);
    out(['chat_id' => $chatId, 'messages' => [$mine, $theirs]], 201);
}

if ($method === 'POST' && $cw === 'actions' && isset($seg[3]) && ctype_digit($seg[3]) && in_array($seg[4] ?? '', ['approve', 'dismiss'], true)) {
    $m = agent_chat_need_action((int)$seg[3]); $args = json_decode((string)$m['action_args'], true) ?: [];
    if ($m['action_state'] !== 'pending') fail('This request has already been answered', 409);
    $chatId = (int)$m['chat_id'];
    if ($seg[4] === 'dismiss') {
        q("UPDATE agent_chat_messages SET action_state = 'dismissed' WHERE id = ?", [$m['id']]);
        out(['ok' => true, 'messages' => [agent_chat_add($chatId, 'agent', "OK, I won't do that.")], 'updated' => [agent_chat_dto(one('SELECT * FROM agent_chat_messages WHERE id = ?', [$m['id']]))]]);
    }
    log_act($oid, 'agent_chat_action', $m['action_kind']);
    if ($m['action_kind'] === 'research_company') {
        $name = agent_clean_name((string)($args['company'] ?? ''));
        if ($name === '') fail('No company was named', 422);
        $co = one('SELECT id FROM companies WHERE LOWER(name) = LOWER(?) LIMIT 1', [$name]);
        $open = $co ? one("SELECT id FROM research_requests WHERE status IN ('waiting','researching') AND company_id = ?", [$co['id']])
                    : one("SELECT id FROM research_requests WHERE status IN ('waiting','researching') AND company_id IS NULL AND LOWER(subject_name) = LOWER(?)", [$name]);
        if ($open) { $text = 'A request for ' . $name . ' is already open.'; }
        else {
            q('INSERT INTO research_requests (company_id, subject_name, requested_by, note) VALUES (?,?,?,?)', [$co['id'] ?? null, $name, $oid, 'From chat']);
            $text = "Approved. I've sent " . $name . ' to the research agent. It shows as Waiting until the agent starts on it, and anything it finds appears in your Inbox.';
        }
        q("UPDATE agent_chat_messages SET action_state = 'done' WHERE id = ?", [$m['id']]);
        $note = agent_chat_add($chatId, 'agent', $text, [['text' => 'Inbox', 'href' => '#/agents/research/inbox']]);
    } elseif ($m['action_kind'] === 'check_link') {
        if (!agent_chat_url_ok((string)($args['url'] ?? ''))) fail('That address cannot be checked', 422);
        q("UPDATE agent_chat_messages SET action_state = 'approved' WHERE id = ?", [$m['id']]);
        $note = agent_chat_add($chatId, 'agent', "Approved. I'll open the link as soon as the agent is online and tell you what I find.");
    } else fail('Unknown request', 422);
    out(['ok' => true, 'messages' => [$note], 'updated' => [agent_chat_dto(one('SELECT * FROM agent_chat_messages WHERE id = ?', [$m['id']]))]]);
}

if ($method === 'DELETE' && $cid !== null) {
    $n = q("DELETE FROM agent_chats WHERE id = ? AND agent = 'research'", [$cid])->rowCount();
    if (!$n) fail('Not found', 404);
    log_act($oid, 'agent_chat_deleted');
    out(['ok' => true]);
}

fail('Not found', 404);
