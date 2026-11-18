<?php
declare(strict_types=1);
// Route: /api/agent-auth/{status|setup|confirm|unlock|lock|logout-all}  (step-up for the owner-only Agents area; see agent_site.php)
// The person must already be signed in to the office app. These routes only add the authenticator-code step.
//
//   GET  status      -> {owner, code_required, enrolled, unlocked}. A non-owner always gets {owner:false} and nothing else,
//                       so the app can hide the Agents tab without revealing anything.
//   POST setup       -> first time only: {secret, otpauth_uri} to add to an authenticator app
//   POST confirm     {code} -> finishes first-time setup, unlocks, returns {recovery_codes} (shown once)
//   POST unlock      {code} -> a 6-digit code or one unused recovery code; unlocks for AGENT_AREA_IDLE_SECONDS of inactivity
//   POST lock        -> ends the unlocked state now
//   POST logout-all  -> locks every device (a code is needed again everywhere)
// Uses $method and $seg from the router. The request-token (CSRF) check for POST is done by the router like any office route.

require_once __DIR__ . '/agent_site.php';

$what = $seg[1] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$u = need_user();
$mine = agent_is_owner($u);

function agent_code_throttle(string $key, string $ip): void {
    $n = one('SELECT COUNT(*) c FROM login_attempts WHERE (email = ? OR ip = ?) AND created_at > (NOW() - INTERVAL 15 MINUTE)', [$key, $ip]);
    if ((int)$n['c'] >= 8) fail('Too many attempts. Try again in 15 minutes.', 429);
}
function agent_code_fail(array $u, string $key, string $ip): never {
    q('INSERT INTO login_attempts (email, ip) VALUES (?,?)', [$key, $ip]);
    log_act((int)$u['id'], 'agent_area_code_failed', 'ip ' . $ip);
    fail('That code is not right. Check the code in your authenticator app and try again.', 401);
}
function agent_area_open(array $u, int $epoch): void {
    start_session(); session_regenerate_id(true); // new session id whenever access is raised
    $_SESSION['agent_epoch'] = $epoch; $_SESSION['agent_seen'] = time();
    log_act((int)$u['id'], 'agent_area_unlocked');
}

if ($what === 'status' && $method === 'GET') {
    if (!$mine) out(['owner' => false]);
    $a = one('SELECT confirmed_at FROM agent_owner_auth WHERE user_id = ?', [$u['id']]);
    out(['owner' => true, 'code_required' => agent_code_required(), 'enrolled' => (bool)($a && $a['confirmed_at']), 'unlocked' => agent_area_unlocked($u)]);
}

// Everything below is the owner only. A team account gets the same plain refusal as every other agent route.
if (!$mine) { log_act((int)$u['id'], 'agent_area_denied'); fail("This site is private. Your account doesn't have access.", 403); }
if (!agent_code_required()) fail('The code step is switched off', 409);
$key = 'agent:' . strtolower((string)$u['email']);
$a = one('SELECT * FROM agent_owner_auth WHERE user_id = ?', [$u['id']]);
$code = preg_replace('/\s+/', '', (string)(body()['code'] ?? ''));

if ($what === 'lock' && $method === 'POST') { agent_area_lock(); out(['ok' => true]); }

if ($what === 'logout-all' && $method === 'POST') {
    if (!agent_area_unlocked($u)) fail('Enter your code to continue', 401);
    q('UPDATE agent_owner_auth SET session_epoch = session_epoch + 1 WHERE user_id = ?', [$u['id']]);
    agent_area_lock();
    log_act((int)$u['id'], 'agent_area_logout_all');
    out(['ok' => true]);
}

// First time: show the secret to add to the authenticator app, then confirm with a code from it.
if ($what === 'setup' && $method === 'POST') {
    if ($a && $a['confirmed_at']) fail('Already set up', 409);
    if (!$a) {
        q('INSERT INTO agent_owner_auth (user_id, totp_secret_enc) VALUES (?,?)', [$u['id'], agent_secret_encrypt(random_bytes(20))]);
        $a = one('SELECT * FROM agent_owner_auth WHERE user_id = ?', [$u['id']]);
    }
    $b32 = agent_b32_encode(agent_secret_decrypt($a['totp_secret_enc']));
    out(['secret' => $b32,
         'otpauth_uri' => 'otpauth://totp/' . rawurlencode('Aerosub Agents:' . $u['email']) . '?secret=' . $b32 . '&issuer=' . rawurlencode('Aerosub Agents') . '&digits=6&period=30']);
}

if ($what === 'confirm' && $method === 'POST') {
    if (!$a) fail('Start the setup first', 409);
    if ($a['confirmed_at']) fail('Already set up', 409);
    agent_code_throttle($key, $ip);
    $step = agent_totp_check(agent_secret_decrypt($a['totp_secret_enc']), $code, (int)$a['last_step']);
    if ($step === null) agent_code_fail($u, $key, $ip);
    $plain = []; $hashes = [];
    for ($i = 0; $i < 10; $i++) { $c = bin2hex(random_bytes(4)); $plain[] = substr($c, 0, 4) . '-' . substr($c, 4); $hashes[] = hash('sha256', $c); }
    q('UPDATE agent_owner_auth SET confirmed_at = NOW(), last_step = ?, recovery_json = ? WHERE user_id = ?', [$step, json_encode($hashes), $u['id']]);
    agent_area_open($u, (int)$a['session_epoch']);
    out(['unlocked' => true, 'recovery_codes' => $plain], 201);
}

// Normal entry: an authenticator code, or one unused recovery code.
if ($what === 'unlock' && $method === 'POST') {
    if (!$a || !$a['confirmed_at']) fail('Set up your authenticator first', 409);
    agent_code_throttle($key, $ip);
    $step = agent_totp_check(agent_secret_decrypt($a['totp_secret_enc']), $code, (int)$a['last_step']);
    if ($step !== null) {
        q('UPDATE agent_owner_auth SET last_step = ? WHERE user_id = ?', [$step, $u['id']]);
    } else {
        $hashes = json_decode((string)$a['recovery_json'], true) ?: [];
        $i = array_search(hash('sha256', str_replace('-', '', strtolower($code))), $hashes, true);
        if ($i === false) agent_code_fail($u, $key, $ip);
        unset($hashes[$i]); q('UPDATE agent_owner_auth SET recovery_json = ? WHERE user_id = ?', [json_encode(array_values($hashes)), $u['id']]);
        log_act((int)$u['id'], 'agent_area_recovery_code_used');
    }
    agent_area_open($u, (int)$a['session_epoch']);
    out(['unlocked' => true]);
}

fail('Not found', 404);
