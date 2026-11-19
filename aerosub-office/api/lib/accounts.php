<?php
declare(strict_types=1);
// Password recovery e-mail, and the 60-day archive for deleted users.
const ARCHIVE_DAYS = 60;

/** Sends one e-mail. Uses the sending account set up under Campaigns → Settings; falls back to the server's own mail(). Returns false if neither worked. */
function acct_send_mail(string $to, string $subject, string $html, string $text, array $attachments = [], array $opts = []): bool {
    $fn = trim((string)preg_replace('/[\x00-\x1f"<>]+/', ' ', (string)($opts['from_name'] ?? ''))); $rt = filter_var((string)($opts['reply_to'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '';
    try {
        require_once __DIR__ . '/campcore.php';
        $st = camp_settings();
        if (!empty($st['smtp_host']) && !empty($st['smtp_user']) && !empty($st['secret_enc']) && !empty($st['from_email'])) {
            $sm = camp_smtp($st);
            $raw = build_message(['from' => ['name' => $fn !== '' ? $fn : (string)($st['from_name'] ?: 'Aerosub Office'), 'email' => $st['from_email']], 'to' => [['name' => '', 'email' => $to]], 'cc' => [],
                'subject' => $subject, 'html' => $html, 'text' => $text, 'attachments' => $attachments, 'extra_headers' => $rt !== '' ? ['Reply-To: ' . ($fn !== '' ? mime_hdr_encode($fn) . ' <' . $rt . '>' : $rt)] : []]);
            $sm->deliver($st['from_email'], [$to], $raw);
            return true;
        }
    } catch (Throwable $e) { error_log('Aerosub Office recovery mail (smtp): ' . $e->getMessage()); }
    try {      // no SMTP set up: PHP's own mail(), still as a proper multipart message (HTML, plain text and attachments) from the sender's name
        require_once __DIR__ . '/campcore.php';
        $host = preg_replace('/^www\./', '', (string)parse_url((string)cfg('app_url'), PHP_URL_HOST)) ?: 'aerosub.co';
        $raw = build_message(['from' => ['name' => $fn !== '' ? $fn : 'Aerosub Office', 'email' => "no-reply@$host"], 'to' => [['name' => '', 'email' => $to]], 'cc' => [], 'subject' => $subject, 'html' => $html, 'text' => $text, 'attachments' => $attachments,
            'extra_headers' => $rt !== '' ? ['Reply-To: ' . ($fn !== '' ? mime_hdr_encode($fn) . ' <' . $rt . '>' : $rt)] : []]);
        [$hdr, $body] = explode("\r\n\r\n", $raw, 2); $keep = []; $skip = false;
        foreach (explode("\r\n", $hdr) as $line) { if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) { if (!$skip) $keep[] = $line; continue; } $skip = (bool)preg_match('/^(To|Subject):/i', $line); if (!$skip) $keep[] = $line; }
        return (bool)@mail($to, mime_hdr_encode(preg_replace('/[\r\n]+/', ' ', $subject)), $body, implode("\r\n", $keep));
    } catch (Throwable $e) { return false; }
}

/** Everyone whose account is past the archive period is removed for good. Work they created stays, handed to a Super Admin. */
function purge_archived_users(): int {
    $n = 0;
    $rows = all('SELECT id,archived_by FROM users WHERE archived_at IS NOT NULL AND archived_at < (NOW() - INTERVAL ' . ARCHIVE_DAYS . ' DAY)');
    foreach ($rows as $r) {
        $uid = (int)$r['id'];
        $keep = one("SELECT id FROM users WHERE role='super_admin' AND active=1 AND archived_at IS NULL AND id<>? ORDER BY (id=?) DESC, id LIMIT 1", [$uid, (int)$r['archived_by']]);
        if (!$keep) continue; // never remove the work if there is nobody to hand it to
        try {
            $vd = (string)(cfg('storage_dir') ?: dirname(__DIR__) . '/storage') . '/vault'; // their personal documents are deleted with the account
            try { foreach (all('SELECT path FROM vault_items WHERE user_id=? AND path IS NOT NULL', [$uid]) as $f) { $fp = $vd . '/' . preg_replace('/[^a-f0-9]/', '', (string)$f['path']); if (is_file($fp)) @unlink($fp); } } catch (Throwable $e) {}
            q('UPDATE tasks SET creator_id=? WHERE creator_id=?', [$keep['id'], $uid]);
            q('UPDATE events SET creator_id=? WHERE creator_id=?', [$keep['id'], $uid]);
            q('DELETE FROM users WHERE id=? AND archived_at IS NOT NULL', [$uid]);
            log_act(null, 'user_purged', 'Archived account #' . $uid . ' deleted after ' . ARCHIVE_DAYS . ' days');
            $n++;
        } catch (Throwable $e) { error_log('Aerosub Office purge user ' . $uid . ': ' . $e->getMessage()); }
    }
    return $n;
}
