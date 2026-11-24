<?php
declare(strict_types=1);
// The agent's credential and the owner-only helpers. Requires core.php.
//
// Token rules (PRD N3): sent in the X-Agent-Token header (cPanel can strip "Authorization"), stored only as a
// SHA-256 hash, revocable, rate-limited per minute, accepted ONLY by api/lib/agent.php. No other route reads it.

const AGENT_RATE_PER_MINUTE = 120;

function agent_hash(string $token): string { return hash('sha256', $token); }

/** Creates a token. The plain text is returned once and never stored. */
function agent_token_create(string $label, int $createdBy, string $scope = 'research'): array {
    if (!in_array($scope, ['research', 'personal', 'seo'], true)) $scope = 'research';
    $plain = 'agt_' . bin2hex(random_bytes(32));
    q('INSERT INTO agent_tokens (label, scope, token_hash, created_by) VALUES (?,?,?,?)', [s($label, 80), $scope, agent_hash($plain), $createdBy]);
    return ['id' => (int)db()->lastInsertId(), 'label' => s($label, 80), 'scope' => $scope, 'token' => $plain];
}

function agent_token_revoke(int $id): bool {
    return q('UPDATE agent_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL', [$id])->rowCount() > 0;
}

/**
 * Checks the request's agent token and rate limit. Fails with 401 or 429, otherwise returns the token row.
 * A token only works for its own scope: the Research laptop token is refused by the Personal routes and the other way round.
 */
function agent_authenticate(string $scope = 'research'): array {
    $given = (string)($_SERVER['HTTP_X_AGENT_TOKEN'] ?? '');
    $row = preg_match('/^agt_[a-f0-9]{64}$/', $given)
        ? one('SELECT id, label FROM agent_tokens WHERE token_hash = ? AND scope = ? AND revoked_at IS NULL', [agent_hash($given), $scope])
        : null;
    if (!$row) {
        $recent = one("SELECT COUNT(*) c FROM activity_log WHERE action='agent_auth_failed' AND created_at > (NOW() - INTERVAL 10 MINUTE)");
        if ((int)$recent['c'] < 20) log_act(null, 'agent_auth_failed', 'ip ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        fail('Not authorised', 401);
    }
    $window = date('Y-m-d H:i:00');
    q('INSERT INTO agent_rate (token_id, window_start, hits) VALUES (?,?,1) ON DUPLICATE KEY UPDATE hits = hits + 1', [$row['id'], $window]);
    $hits = (int)one('SELECT hits FROM agent_rate WHERE token_id = ? AND window_start = ?', [$row['id'], $window])['hits'];
    if ($hits > AGENT_RATE_PER_MINUTE) fail('Too many requests', 429);
    if (random_int(1, 100) === 1) q('DELETE FROM agent_rate WHERE window_start < (NOW() - INTERVAL 1 DAY)');
    q('UPDATE agent_tokens SET last_used_at = NOW() WHERE id = ?', [$row['id']]);
    return $row;
}

/** The one person allowed to manage tokens and (later) use the Agent site: the Super Admin named in config. */
function agent_need_owner(): array {
    $u = need_user();
    $owner = strtolower(trim((string)cfg('agent_owner_email')));
    if ($u['role'] !== 'super_admin' || $owner === '' || $owner === 'change_me_owner_email' || strtolower((string)$u['email']) !== $owner) {
        log_act((int)$u['id'], 'agent_owner_denied', $_SERVER['REQUEST_URI'] ?? '');
        fail('Not allowed', 403);
    }
    return $u;
}

/** Where uploaded documents live. Same protected storage root the task files use (never reachable by URL). */
function agent_doc_dir(): string {
    $root = (string)(cfg('storage_dir') ?: dirname(__DIR__) . '/storage');
    $dir = $root . '/documents';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_file($root . '/.htaccess')) { @file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n"); @file_put_contents($root . '/index.html', ''); }
    return $dir;
}

/* ---- owner-editable settings (weights, sources, schedules) ---- */
function agent_settings_all(): array {
    $out = ['weights' => (object)[], 'sources' => [], 'schedules' => (object)[]];
    foreach (all('SELECT k, v FROM agent_settings') as $r) $out[$r['k']] = json_decode($r['v'], true);
    return $out;
}
function agent_setting_put(string $key, mixed $value, int $by): void {
    q('REPLACE INTO agent_settings (k, v, updated_by) VALUES (?,?,?)', [$key, json_encode($value), $by]);
}
