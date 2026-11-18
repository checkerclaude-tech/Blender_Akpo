<?php
declare(strict_types=1);
// Password recovery e-mail, and the 60-day archive for deleted users.
const ARCHIVE_DAYS = 60;

/** Sends one e-mail. Uses the sending account set up under Campaigns → Settings; falls back to the server's own mail(). Returns false if neither worked. */
function acct_send_mail(string $to, string $subject, string $html, string $text, array $attachments = []): bool {
    try {
        require_once __DIR__ . '/campcore.php';
        $st = camp_settings();
        if (!empty($st['smtp_host']) && !empty($st['smtp_user']) && !empty($st['secret_enc']) && !empty($st['from_email'])) {
            $sm = camp_smtp($st);
            $raw = build_message(['from' => ['name' => (string)($st['from_name'] ?: 'Aerosub Office'), 'email' => $st['from_email']], 'to' => [['name' => '', 'email' => $to]], 'cc' => [],
                'subject' => $subject, 'html' => $html, 'text' => $text, 'attachments' => $attachments]);
            $sm->deliver($st['from_email'], [$to], $raw);
            return true;
        }
    } catch (Throwable $e) { error_log('Aerosub Office recovery mail (smtp): ' . $e->getMessage()); }
    try {
        $host = preg_replace('/^www\./', '', (string)parse_url((string)cfg('app_url'), PHP_URL_HOST)) ?: 'aerosub.co';
        $h = "From: Aerosub Office <no-reply@$host>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        return (bool)@mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $h);
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
            q('UPDATE tasks SET creator_id=? WHERE creator_id=?', [$keep['id'], $uid]);
            q('UPDATE events SET creator_id=? WHERE creator_id=?', [$keep['id'], $uid]);
            q('DELETE FROM users WHERE id=? AND archived_at IS NOT NULL', [$uid]);
            log_act(null, 'user_purged', 'Archived account #' . $uid . ' deleted after ' . ARCHIVE_DAYS . ' days');
            $n++;
        } catch (Throwable $e) { error_log('Aerosub Office purge user ' . $uid . ': ' . $e->getMessage()); }
    }
    return $n;
}
