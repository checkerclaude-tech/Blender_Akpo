<?php
declare(strict_types=1);
// Route: /api/agent-personal/...  Called only by the Personal agent's laptop with ITS OWN token (scope 'personal').
// The Research token is refused here and this token is refused by every Research route (agent_token.php).
//
// This file only moves ENCRYPTED text between the owner's phone and the laptop (PRD N12). It never holds a key, never
// looks inside a message, and deletes a message as soon as the laptop has collected it. Uses $method and $seg from the router.
//
//   GET  inbox          encrypted messages the phone sent (oldest first, at most 50)
//   POST ack  {ids}     the laptop has stored them safely: delete them from the server
//   POST outbox {envelope}   an encrypted reply for the phone

require_once __DIR__ . '/agent_token.php';
require_once __DIR__ . '/agent_relay_lib.php';

$tok = agent_authenticate('personal');
$what = $seg[1] ?? '';

if ($method === 'GET' && $what === 'inbox') {
    out(['messages' => relay_collect('to_laptop', null, true)]);       // everything any browser sent, in one list
}
if ($method === 'POST' && $what === 'ack') {
    out(['deleted' => relay_delete('to_laptop', body()['ids'] ?? [], null, true)]);
}
if ($method === 'POST' && $what === 'outbox') {
    $in = body();
    out(['queued' => relay_put('to_phone', $in['envelope'] ?? '', $in['keep'] ?? null)], 201);   // keep: "removal" marks a removal notice (kept 90 days)
}
fail('Not found', 404);
