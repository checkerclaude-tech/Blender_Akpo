<?php
declare(strict_types=1);
// Access rules for the owner-only Agents area (/agent in the office app).
// It uses the normal office login and the normal request-token (CSRF) check, and only the Super Admin named in
// agent_owner_email can ever pass. OPTIONAL extra step: when config 'agent_require_code' is true, the owner must also enter an
// authenticator-app code ("step-up") before the area opens, and is asked again after AGENT_AREA_IDLE_SECONDS without use.
// The step is OFF by default (a decision for go-live, tracked in docs/security/open-items.md) but fully built and tested, so
// it can be switched on for everything, or kept for the Personal agent, by one config line.
// Functions only; routing is in agent_auth.php (unlock) and agent_owner.php (everything behind it).

require_once __DIR__ . '/agent_token.php';

const AGENT_AREA_IDLE_SECONDS = 900; // 15 minutes without use: the code is asked for again

function agent_is_owner(array $u): bool {
    $owner = strtolower(trim((string)cfg('agent_owner_email')));
    return $u['role'] === 'super_admin' && $owner !== '' && $owner !== 'change_me_owner_email' && strtolower((string)$u['email']) === $owner;
}

function agent_code_required(): bool { return (bool)cfg('agent_require_code'); }

/** Has this office session passed the authenticator step recently? Always true while the step is switched off. */
function agent_area_unlocked(array $u): bool {
    if (!agent_code_required()) return true;
    start_session();
    $a = one('SELECT session_epoch FROM agent_owner_auth WHERE user_id = ? AND confirmed_at IS NOT NULL', [$u['id']]);
    if (!$a || (int)$a['session_epoch'] !== (int)($_SESSION['agent_epoch'] ?? -1)) return false;
    return time() - (int)($_SESSION['agent_seen'] ?? 0) <= AGENT_AREA_IDLE_SECONDS;
}

function agent_area_lock(): void {
    start_session();
    unset($_SESSION['agent_epoch'], $_SESSION['agent_seen']);
}

/**
 * The signed-in owner for Agents area routes. Fails 401 if not signed in to the office app, 403 if this account is not
 * the owner (nothing else is revealed), and 401 with step "code" when the authenticator step is missing or expired.
 */
function agent_area_owner(): array {
    $u = need_user();
    if (!agent_is_owner($u)) { log_act((int)$u['id'], 'agent_area_denied'); fail("This site is private. Your account doesn't have access.", 403); }
    if (!agent_area_unlocked($u)) out(['error' => 'Enter your code to continue', 'step' => 'code'], 401);
    $_SESSION['agent_seen'] = time();
    return $u;
}

/* ---- authenticator-app codes (RFC 6238: 6 digits, 30 seconds, SHA-1) ---- */
function agent_b32_encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function agent_totp_code(string $secret, int $step): string {
    $h = hash_hmac('sha1', pack('J', $step), $secret, true); $o = ord($h[19]) & 15;
    $v = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($v % 1000000), 6, '0', STR_PAD_LEFT);
}
/** Returns the matching 30-second step (allowing one step either side) if it is newer than the last one used, else null. */
function agent_totp_check(string $secret, string $code, int $lastStep): ?int {
    if (!preg_match('/^\d{6}$/', $code)) return null;
    $now = intdiv(time(), 30);
    for ($d = -1; $d <= 1; $d++) { $st = $now + $d; if ($st > $lastStep && hash_equals(agent_totp_code($secret, $st), $code)) return $st; }
    return null;
}

/* ---- secret at rest ---- */
function agent_secret_key(): string { return hash('sha256', (string)cfg('mail_key') . '|' . (string)cfg('cron_key') . '|agent-totp-v1', true); }
function agent_secret_encrypt(string $plain): string {
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', agent_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new RuntimeException('Could not secure the authenticator secret');
    return base64_encode($iv . $tag . $ct);
}
function agent_secret_decrypt(string $blob): string {
    $raw = base64_decode($blob, true);
    $pt = $raw !== false && strlen($raw) > 28 ? openssl_decrypt(substr($raw, 28), 'aes-256-gcm', agent_secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)) : false;
    if ($pt === false) throw new RuntimeException('The authenticator secret is unreadable');
    return $pt;
}
