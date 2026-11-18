<?php
declare(strict_types=1);
// Route: /api/agent-owner/personal/...  The owner's phone side of the Personal agent's encrypted mailbox. Included by
// agent_owner.php after the owner is verified. Uses $method, $seg, $oid.
//
//   GET  personal/status       is the laptop online, and how many messages are waiting each way (counts only)
//   POST personal/send {envelope}   an encrypted message for the laptop
//   GET  personal/inbox        encrypted replies waiting for the phone
//   POST personal/ack {ids}    the phone has stored them: delete them from the server
//
// The server never sees a key or a readable message. Only the owner's own browser can decrypt (assets/agent/pcrypto.js).

require_once __DIR__ . '/agent_token.php';
require_once __DIR__ . '/agent_relay_lib.php';

$pw = $seg[2] ?? '';

if ($method === 'GET' && $pw === 'status') {
    $t = one("SELECT MAX(last_used_at) seen FROM agent_tokens WHERE scope = 'personal' AND revoked_at IS NULL");
    $seen = $t['seen'] ?? null;
    out([
        'has_token' => (bool)one("SELECT id FROM agent_tokens WHERE scope = 'personal' AND revoked_at IS NULL LIMIT 1"),
        'laptop_last_seen' => $seen,
        'online' => $seen !== null && strtotime($seen) > time() - 120,
        'waiting_for_laptop' => (int)one("SELECT COUNT(*) c FROM agent_relay WHERE direction = 'to_laptop'")['c'],
    ]);
}
if ($method === 'POST' && $pw === 'send') {
    if ((int)one("SELECT COUNT(*) c FROM agent_relay WHERE direction = 'to_laptop' AND created_at > (NOW() - INTERVAL 1 HOUR)")['c'] >= 120) fail('Too many messages. Try again in a while.', 429);
    out(['queued' => relay_put('to_laptop', body()['envelope'] ?? '')], 201);
}
if ($method === 'GET' && $pw === 'inbox') {
    // ?tag=<11 characters>: this browser's replies. No tag: only the older untagged ones (the first phone's page, which knows no tags).
    out(['messages' => relay_collect('to_phone', relay_tag_param($_GET['tag'] ?? null))]);
}
if ($method === 'POST' && $pw === 'ack') {
    $in = body();
    out(['deleted' => relay_delete('to_phone', $in['ids'] ?? [], relay_tag_param($in['tag'] ?? null))]);
}
fail('Not found', 404);
