<?php
declare(strict_types=1);
// Route: /api/agent-tokens (owner session + CSRF, like every other signed-in route).
// GET lists tokens (never the secret), POST creates one (secret shown once), DELETE /{id} revokes.
// Uses $method, $id from the router.

require_once __DIR__ . '/agent_token.php';
$owner = agent_need_owner();

if ($method === 'GET' && $id === null) {
    out(all('SELECT id, label, scope, created_at, last_used_at, revoked_at FROM agent_tokens ORDER BY id DESC'));
}
if ($method === 'POST' && $id === null) {
    $label = s(body()['label'] ?? '', 80);
    if ($label === '') fail('Give the token a name, for example "Office laptop"');
    $scope = (string)(body()['scope'] ?? 'research'); if (!in_array($scope, ['research', 'personal'], true)) $scope = 'research';
    $t = agent_token_create($label, (int)$owner['id'], $scope);
    log_act((int)$owner['id'], 'agent_token_created', $label . ' (' . $scope . ')');
    out($t, 201);
}
if ($method === 'DELETE' && $id) {
    if (!agent_token_revoke($id)) fail('Not found', 404);
    log_act((int)$owner['id'], 'agent_token_revoked', 'token ' . $id);
    out(['ok' => true]);
}
fail('Bad request', 404);
