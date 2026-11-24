<?php
declare(strict_types=1);
// Sending a document by email on someone's behalf (letters, My Documents). Used by letters.php and myfiles.php.
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/accounts.php';

/** "a@b.com, c@d.com" or an array -> clean list of valid addresses (max 10). */
function share_emails($v): array {
    $parts = is_array($v) ? $v : preg_split('/[\s,;]+/', (string)$v); $out = [];
    foreach ($parts as $p) { $p = trim((string)$p); if ($p === '') continue; $e = filter_var($p, FILTER_VALIDATE_EMAIL); if (!$e) fail('“' . mb_substr($p, 0, 60) . '” is not a valid email address'); $out[strtolower($e)] = $e; }
    if (!$out) fail('Type at least one email address'); if (count($out) > 10) fail('You can send to up to 10 addresses at a time');
    return array_values($out);
}
/**
 * via 'mine'  = from the person's own connected mailbox (so replies come to them and a copy sits in their Sent folder);
 * via 'system' = from the office sender set up in Campaigns, with the person's name and their address as Reply-To.
 * $atts = [[name,type,data]]. Returns a short text on what happened.
 */
function share_send_mail(array $u, string $via, array $to, string $subject, string $message, array $atts): string {
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject)) ?: 'A document from ' . $u['name'];
    $msgHtml = nl2br(htmlspecialchars(trim($message), ENT_QUOTES, 'UTF-8'));
    $html = ($msgHtml !== '' ? '<p>' . $msgHtml . '</p>' : '') . '<p style="color:#666;font-size:12px">Sent by ' . htmlspecialchars((string)$u['name'], ENT_QUOTES, 'UTF-8') . ' from Aerosub Office.</p>';
    $text = ($message !== '' ? trim($message) . "\n\n" : '') . 'Sent by ' . $u['name'] . ' from Aerosub Office.';
    $bf = array_map(fn($a) => ['name' => $a[0], 'type' => $a[1], 'data' => $a[2]], $atts);
    if ($via === 'mine') {
        $acc = one('SELECT * FROM mail_accounts WHERE user_id=? ORDER BY id LIMIT 1', [(int)$u['id']]);
        if (!$acc) fail('Your mailbox is not connected yet (Mail tab). Choose “Aerosub system sender” instead, or connect your mailbox first.', 409);
        try {
            $verify = !cfg('mail_insecure_tls'); $domain = substr(strrchr((string)$acc['email'], '@') ?: '@aerosub.co', 1) ?: 'aerosub.co';
            $raw = build_message(['from' => ['name' => ($acc['from_name'] ?: $u['name']), 'email' => $acc['email']], 'sent_by' => $u['name'] . ' <' . $u['email'] . '>', 'to' => array_map(fn($e) => ['name' => '', 'email' => $e], $to), 'cc' => [], 'subject' => $subject, 'html' => $html, 'text' => $text, 'attachments' => $bf, 'domain' => $domain]);
            $sm = new Smtp($acc['smtp_host'], (int)$acc['smtp_port'], $verify); $sm->auth($acc['email'], mail_decrypt($acc['secret_enc'])); $sm->deliver($acc['email'], $to, $raw);
        } catch (MailError $e) { fail('Your mailbox could not send it: ' . $e->getMessage(), 502); }
        log_act((int)$u['id'], 'mail_sent', $acc['email'] . ' → ' . count($to) . ' recipient(s) (shared document)');
        return 'Sent from your mailbox to ' . implode(', ', $to);
    }
    $sent = 0; foreach ($to as $e) { if (acct_send_mail($e, $subject, $html, $text, $bf, ['from_name' => (string)$u['name'], 'reply_to' => (string)$u['email']])) $sent++; }
    if (!$sent) fail('The office sender could not send it. Check Campaigns → Sending account, or use your own mailbox.', 502);
    log_act((int)$u['id'], 'mail_sent', 'office sender → ' . $sent . ' recipient(s) (shared document)');
    return $sent === count($to) ? 'Sent to ' . implode(', ', $to) : 'Sent to ' . $sent . ' of ' . count($to) . ' addresses';
}
/** Which sending options this person has. */
function share_mail_options(array $u): array {
    $acc = one('SELECT email FROM mail_accounts WHERE user_id=? ORDER BY id LIMIT 1', [(int)$u['id']]);
    return ['mine' => $acc ? $acc['email'] : null];
}
