<?php
declare(strict_types=1);
// Web Push (RFC 8030 + 8291 message encryption + 8292 VAPID), written with PHP's own openssl and curl: no libraries.
// People who allow notifications get a push when something happens, even if Aerosub Office is closed (the phone or browser's own
// push service wakes the installed app). The server key pair is created on first use and kept in the database.
//   GET  /api/push/key            the public key the browser subscribes with
//   POST /api/push/subscribe      {endpoint, keys:{p256dh, auth}}   (the endpoint is tied to the signed-in person)
//   POST /api/push/unsubscribe    {endpoint}
//   POST /api/push/test           sends a test notification to the signed-in person's devices
//   GET  /api/push/status         {subscribed: devices}

function push_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function push_unb64u(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), false); }
function push_available(): bool { return function_exists('openssl_pkey_derive') && function_exists('curl_init') && function_exists('hash_hkdf'); }
function push_point(\OpenSSLAsymmetricKey $key): string {
    $d = openssl_pkey_get_details($key); if (!$d || empty($d['ec'])) throw new RuntimeException('Not an EC key');
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}
function push_pub_from_point(string $point): \OpenSSLAsymmetricKey {
    if (strlen($point) !== 65 || $point[0] !== "\x04") throw new RuntimeException('Bad browser key');
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    $k = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n"); if (!$k) throw new RuntimeException('Bad browser key');
    return $k;
}
function push_priv_from_scalar(string $d, string $point): \OpenSSLAsymmetricKey { // used by the tests
    $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $point;
    $k = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n"); if (!$k) throw new RuntimeException('Bad key');
    return $k;
}
/** @return array{0:string,1:string} [public key (base64url), private key PEM] */
function push_keys(): array {
    $r = one('SELECT public_key, private_pem FROM push_config WHERE id=1'); if ($r) return [$r['public_key'], $r['private_pem']];
    $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']); if (!$k) throw new RuntimeException('This server cannot create push keys');
    openssl_pkey_export($k, $pem); $pub = push_b64u(push_point($k));
    q('INSERT IGNORE INTO push_config (id,public_key,private_pem) VALUES (1,?,?)', [$pub, $pem]); $r = one('SELECT public_key, private_pem FROM push_config WHERE id=1'); return [$r['public_key'], $r['private_pem']];
}
/** RFC 8291 "aes128gcm": returns the whole request body. $salt and $asKey are only given by the tests. */
function push_encrypt(string $payload, string $uaPublic, string $authSecret, ?string $salt = null, ?\OpenSSLAsymmetricKey $asKey = null): string {
    if (strlen($payload) > 3500) $payload = substr($payload, 0, 3500);
    $salt ??= random_bytes(16);
    $as = $asKey ?? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']); $asPub = push_point($as);
    $shared = openssl_pkey_derive(push_pub_from_point($uaPublic), $as, 32); if ($shared === false) throw new RuntimeException('Key agreement failed');
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPub, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt); $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $tag = ''; $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16); if ($ct === false) throw new RuntimeException('Encryption failed');
    return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}
function push_der_to_raw(string $der): string {
    $o = 2; if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f; $parts = [];
    for ($i = 0; $i < 2; $i++) { $len = ord($der[$o + 1]); $v = substr($der, $o + 2, $len); $v = ltrim($v, "\0"); $parts[] = str_pad($v, 32, "\0", STR_PAD_LEFT); $o += 2 + $len; }
    return $parts[0] . $parts[1];
}
function push_vapid(string $endpoint): string {
    [$pub, $pem] = push_keys(); $u = parse_url($endpoint); $aud = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
    $host = preg_replace('/^www\./', '', (string)parse_url((string)cfg('app_url'), PHP_URL_HOST)) ?: 'aerosub.co'; $sub = (string)(cfg('push_contact') ?: 'mailto:noreply@' . $host);
    $h = push_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])); $c = push_b64u(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => $sub]));
    if (!openssl_sign($h . '.' . $c, $der, $pem, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Could not sign');
    return 'vapid t=' . $h . '.' . $c . '.' . push_b64u(push_der_to_raw($der)) . ', k=' . $pub;
}
function push_endpoint_ok(string $e): bool { return (bool)preg_match('~^https://[^\s]{10,600}$~i', $e) || (cfg('push_allow_http') && preg_match('~^http://[^\s]{10,600}$~i', $e)); }
/** Sends one payload to every device of the given people. Never throws: a failed push must not break whatever caused it. */
function push_send(array $userIds, array $msg): int {
    try {
        if (!push_available() || !$userIds) return 0; $in = implode(',', array_map('intval', $userIds)); $subs = all("SELECT id,user_id,endpoint,p256dh,auth FROM push_subs WHERE user_id IN ($in) AND fails < 5"); if (!$subs) return 0;
        $json = json_encode(['title' => mb_substr((string)($msg['title'] ?? 'Aerosub Office'), 0, 120), 'body' => mb_substr((string)($msg['body'] ?? ''), 0, 200), 'link' => (string)($msg['link'] ?? '#/alerts'), 'tag' => (string)($msg['tag'] ?? ''), 'icon' => 'assets/icon-192.png'], JSON_UNESCAPED_UNICODE);
        $mh = curl_multi_init(); $hs = [];
        foreach ($subs as $s) {
            try { $body = push_encrypt($json, push_unb64u($s['p256dh']), push_unb64u($s['auth'])); $auth = push_vapid($s['endpoint']); } catch (Throwable $e) { q('UPDATE push_subs SET fails=fails+1 WHERE id=?', [$s['id']]); continue; }
            $ch = curl_init($s['endpoint']); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_NOSIGNAL => 1,
                CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 86400', 'Urgency: high', 'Authorization: ' . $auth, 'Content-Length: ' . strlen($body)]]);
            curl_multi_add_handle($mh, $ch); $hs[(int)$s['id']] = $ch;
        }
        $run = 0; do { curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 0.2); } while ($run > 0);
        $sent = 0;
        foreach ($hs as $id => $ch) { $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code >= 200 && $code < 300) { $sent++; q('UPDATE push_subs SET last_ok=NOW(), fails=0 WHERE id=?', [$id]); }
            elseif ($code === 404 || $code === 410) q('DELETE FROM push_subs WHERE id=?', [$id]);   // the device unsubscribed
            else q('UPDATE push_subs SET fails=fails+1 WHERE id=?', [$id]);
            curl_multi_remove_handle($mh, $ch); curl_close($ch); }
        curl_multi_close($mh); return $sent;
    } catch (Throwable $e) { error_log('Aerosub Office push: ' . $e->getMessage()); return 0; }
}
/** Called whenever a notification is created for someone. */
function push_notify(int $uid, string $title, string $body, string $link, string $tag): void {
    static $has = null; if ($has === null) { try { $has = (bool)one('SELECT 1 FROM push_subs LIMIT 1'); } catch (Throwable $e) { $has = false; } }
    if (!$has) return; push_send([$uid], ['title' => $title, 'body' => $body, 'link' => $link ?: '#/alerts', 'tag' => $tag]);
}

/* ---------------- routes ---------------- */
if (isset($seg[0]) && $seg[0] === 'push' && isset($method)) {
    $u = need_user(); $uid = (int)$u['id']; $act = $seg[1] ?? '';
    if ($act === 'key' && $method === 'GET') { if (!push_available()) fail('Push notifications are not available on this server', 501); try { out(['key' => push_keys()[0]]); } catch (RuntimeException $e) { fail($e->getMessage(), 501); } }
    if ($act === 'status' && $method === 'GET') out(['devices' => (int)one('SELECT COUNT(*) c FROM push_subs WHERE user_id=?', [$uid])['c'], 'available' => push_available()]);
    $b = $method === 'POST' ? body() : [];
    if ($act === 'subscribe' && $method === 'POST') {
        $e = trim((string)($b['endpoint'] ?? '')); $k = (array)($b['keys'] ?? []); $p = (string)($k['p256dh'] ?? ''); $a = (string)($k['auth'] ?? '');
        if (!push_endpoint_ok($e)) fail('That notification address is not valid'); if (strlen(push_unb64u($p)) !== 65 || strlen(push_unb64u($a)) < 8) fail('The notification keys are not valid');
        q('INSERT INTO push_subs (user_id,endpoint_hash,endpoint,p256dh,auth,ua) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), p256dh=VALUES(p256dh), auth=VALUES(auth), ua=VALUES(ua), fails=0',
          [$uid, hash('sha256', $e), $e, $p, $a, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
        out(['ok' => true, 'devices' => (int)one('SELECT COUNT(*) c FROM push_subs WHERE user_id=?', [$uid])['c']]);
    }
    if ($act === 'unsubscribe' && $method === 'POST') { q('DELETE FROM push_subs WHERE user_id=? AND endpoint_hash=?', [$uid, hash('sha256', trim((string)($b['endpoint'] ?? '')))]); out(['ok' => true]); }
    if ($act === 'test' && $method === 'POST') { $n = push_send([$uid], ['title' => 'Notifications are on', 'body' => 'You will get alerts here, even when Aerosub Office is closed.', 'link' => '#/alerts', 'tag' => 'push-test']); out(['ok' => true, 'sent' => $n]); }
    fail('Bad request', 404);
}
