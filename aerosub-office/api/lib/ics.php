<?php
declare(strict_types=1);
// Calendar invites for meetings (tasks of type "meeting").
//  * An e-mail with an invite.ics attached (METHOD:REQUEST) goes to everyone invited, from the organizer's name, with a link to add it to a phone or desktop calendar.
//    Phones and desktop calendars (Google, Apple, Outlook) then alert before the meeting (alarms at 1 hour and 10 minutes before).
//  * Changes to the time, place or title send an updated invite (same UID, higher SEQUENCE); cancelling or deleting sends a cancellation.
//  * Personal links that need no sign-in (the calendar app cannot sign in):   GET /api/ics/{taskId}/{token}   one meeting
//                                                                              GET /api/ics/feed/{token}       every meeting of one person (subscribe once)
//  * Signed-in people can also download:                                       GET /api/tasks/{id}/ics   and   GET /api/tasks/{id}/ics-links
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/campcore.php';

function ics_esc(string $s): string { return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $s); }
function ics_fold(string $l): string {
    $out = ''; $first = true;
    while (strlen($l) > 73) { $cut = mb_strcut($l, 0, $first ? 73 : 72, 'UTF-8'); if ($cut === '') break; $out .= ($first ? '' : ' ') . $cut . "\r\n"; $l = substr($l, strlen($cut)); $first = false; }
    return $out . ($first ? '' : ' ') . $l . "\r\n";
}
function ics_utc(string $dt): string { $d = new DateTime($dt, new DateTimeZone(date_default_timezone_get())); $d->setTimezone(new DateTimeZone('UTC')); return $d->format('Ymd\THis\Z'); }
function ics_host(): string { $h = preg_replace('/^www\./', '', (string)parse_url((string)(cfg('app_url') ?: camp_base()), PHP_URL_HOST)); return $h ?: 'aerosub.co'; }
function ics_token(int $tid, int $uid): string { return base_convert((string)$uid, 10, 36) . '-' . substr(hash_hmac('sha256', "ics|$tid|$uid", mail_key()), 0, 32); }
function ics_feed_token(int $uid): string { return base_convert((string)$uid, 10, 36) . '-' . substr(hash_hmac('sha256', "feed|$uid", mail_key()), 0, 32); }
/** @return int|null the person a token belongs to */
function ics_verify(string $kind, int $tid, string $token): ?int {
    if (!preg_match('/^([0-9a-z]{1,8})-([a-f0-9]{32})$/', $token, $m)) return null; $uid = (int)base_convert($m[1], 36, 10); if ($uid < 1) return null;
    $want = substr(hash_hmac('sha256', ($kind === 'feed' ? "feed|$uid" : "ics|$tid|$uid"), mail_key()), 0, 32); return hash_equals($want, $m[2]) ? $uid : null;
}
function ics_urls(int $tid, int $uid): array {
    $https = camp_base() . '/api/ics/' . $tid . '/' . ics_token($tid, $uid); $feed = camp_base() . '/api/ics/feed/' . ics_feed_token($uid);
    return ['https' => $https, 'webcal' => preg_replace('~^https?://~', 'webcal://', $https), 'feed' => $feed, 'feed_webcal' => preg_replace('~^https?://~', 'webcal://', $feed)];
}
/** Everyone invited: the organizer, the person it is assigned to, and everyone it was shared with. */
function ics_people(int $tid): array {
    return all('SELECT u.id,u.name,u.email FROM users u WHERE u.active=1 AND u.archived_at IS NULL AND u.id IN (SELECT user_id FROM task_shares WHERE task_id=? UNION SELECT assignee_id FROM tasks WHERE id=? AND assignee_id IS NOT NULL UNION SELECT creator_id FROM tasks WHERE id=?) ORDER BY u.name', [$tid, $tid, $tid]);
}
function ics_vevent(array $t, array $people, array $org, bool $cancel = false): string {
    $host = ics_host(); $start = $t['starts_at']; $end = $t['ends_at'] ?: date('Y-m-d H:i:s', strtotime($start . ' +1 hour')); if (strtotime($end) <= strtotime($start)) $end = date('Y-m-d H:i:s', strtotime($start . ' +1 hour'));
    $link = camp_base() . '/#/tasks/' . $t['id']; $desc = trim((string)($t['description'] ?? '')); $desc = ($desc !== '' ? $desc . "\n\n" : '') . 'Open in Aerosub Office: ' . $link;
    $L = ['BEGIN:VEVENT', 'UID:meeting-' . $t['id'] . '@' . $host, 'DTSTAMP:' . gmdate('Ymd\THis\Z'), 'DTSTART:' . ics_utc($start), 'DTEND:' . ics_utc($end), 'SEQUENCE:' . (int)($t['ics_seq'] ?? 0),
          'SUMMARY:' . ics_esc((string)$t['title']), 'DESCRIPTION:' . ics_esc($desc), 'URL:' . $link];
    if (!empty($t['meeting_place'])) $L[] = 'LOCATION:' . ics_esc((string)$t['meeting_place']);
    if ($org && filter_var($org['email'] ?? '', FILTER_VALIDATE_EMAIL)) $L[] = 'ORGANIZER;CN=' . ics_esc((string)$org['name']) . ':mailto:' . $org['email'];
    foreach ($people as $p) if (filter_var($p['email'], FILTER_VALIDATE_EMAIL)) $L[] = 'ATTENDEE;CN=' . ics_esc((string)$p['name']) . ';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=FALSE:mailto:' . $p['email'];
    $L[] = 'STATUS:' . ($cancel ? 'CANCELLED' : 'CONFIRMED'); $L[] = 'TRANSP:OPAQUE'; $L[] = 'CATEGORIES:Aerosub Office';
    if (!$cancel) foreach ([['-PT1H', '1 hour'], ['-PT10M', '10 minutes']] as [$trig, $lbl]) { $L[] = 'BEGIN:VALARM'; $L[] = 'ACTION:DISPLAY'; $L[] = 'DESCRIPTION:' . ics_esc($t['title'] . ' starts in ' . $lbl); $L[] = 'TRIGGER:' . $trig; $L[] = 'END:VALARM'; }
    $L[] = 'END:VEVENT'; return implode("\n", $L) . "\n";
}
function ics_calendar(array $vevents, string $method = 'PUBLISH', string $name = 'Aerosub Office meetings'): string {
    $head = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Aerosub Office//Meetings//EN', 'CALSCALE:GREGORIAN', 'METHOD:' . $method, 'X-WR-CALNAME:' . ics_esc($name)];
    $body = implode("\n", $head) . "\n" . implode('', $vevents) . "END:VCALENDAR\n"; $out = '';
    foreach (explode("\n", rtrim($body, "\n")) as $line) $out .= ics_fold($line); return $out;
}
function ics_task(int $tid): ?array { return one("SELECT t.*, c.name AS creator_name, c.email AS creator_email FROM tasks t LEFT JOIN users c ON c.id=t.creator_id WHERE t.id=? AND t.task_type='meeting' AND t.starts_at IS NOT NULL", [$tid]); }
/** One meeting as a .ics file. $method: REQUEST (invite or update), CANCEL, or PUBLISH (a link or a download). */
function ics_for_task(array $t, string $method = 'REQUEST'): string {
    $cancel = $method === 'CANCEL' || in_array($t['status'] ?? '', ['canceled', 'transferred'], true);
    return ics_calendar([ics_vevent($t, ics_people((int)$t['id']), ['name' => $t['creator_name'] ?? 'Aerosub Office', 'email' => $t['creator_email'] ?? ''], $cancel)], $cancel ? 'CANCEL' : $method);
}
/** Sends the invite (or an update / cancellation) by e-mail with the .ics attached. Never throws. @return int e-mails sent */
function ics_send(array $t, array $to, array $org, string $kind = 'invite'): int {
    $n = 0; try {
        $method = $kind === 'cancel' ? 'CANCEL' : 'REQUEST'; $ics = ics_for_task($t, $method); $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $when = date('D j M Y, H:i', strtotime($t['starts_at'])) . ($t['ends_at'] ? ' to ' . date('H:i', strtotime($t['ends_at'])) : ''); $link = camp_base() . '/#/tasks/' . $t['id'];
        $word = ['invite' => 'Meeting invitation', 'update' => 'Updated meeting', 'cancel' => 'Meeting cancelled'][$kind] ?? 'Meeting'; $with = implode(', ', array_map(fn($p) => $p['name'], ics_people((int)$t['id'])));
        foreach ($to as $r) { if (!filter_var($r['email'] ?? '', FILTER_VALIDATE_EMAIL)) continue; $u = ics_urls((int)$t['id'], (int)$r['id']);
            $btns = $kind === 'cancel' ? '' : '<p><a href="' . $e($u['https']) . '" style="background:#0A6EA8;color:#fff;padding:9px 16px;border-radius:6px;text-decoration:none">Add to my calendar</a> &nbsp; <a href="' . $e($link) . '">Open in Aerosub Office</a></p><p style="color:#556">The invite is attached (invite.ics). Open it on your phone or computer and your calendar app will add the meeting and alert you before it starts. To get every meeting automatically, subscribe once: <a href="' . $e($u['feed']) . '">calendar feed</a>.</p>';
            $html = '<p>Hello ' . $e($r['name']) . ',</p><p><b>' . $e($org['name'] ?? 'Someone') . '</b> ' . ($kind === 'cancel' ? 'cancelled' : ($kind === 'update' ? 'changed' : 'invited you to')) . ' a meeting:</p><p style="font-size:16px"><b>' . $e($t['title']) . '</b></p><table><tr><td style="padding:2px 14px 2px 0;color:#667"><b>When</b></td><td>' . $e($when) . '</td></tr>' .
                    (!empty($t['meeting_place']) ? '<tr><td style="padding:2px 14px 2px 0;color:#667"><b>Where</b></td><td>' . $e($t['meeting_place']) . '</td></tr>' : '') . '<tr><td style="padding:2px 14px 2px 0;color:#667"><b>With</b></td><td>' . $e($with) . '</td></tr></table>' . (trim((string)$t['description']) !== '' ? '<div style="white-space:pre-wrap;border-left:3px solid #ccd;padding:6px 12px;margin:10px 0">' . $e($t['description']) . '</div>' : '') . $btns;
            $text = $word . ': ' . $t['title'] . "\nWhen: $when\n" . (!empty($t['meeting_place']) ? 'Where: ' . $t['meeting_place'] . "\n" : '') . ($kind === 'cancel' ? '' : "Add to your calendar: " . $u['https'] . "\n") . 'Open: ' . $link . "\n";
            if (acct_send_mail($r['email'], $word . ': ' . $t['title'] . ' (' . date('D j M, H:i', strtotime($t['starts_at'])) . ')', $html, $text, [['name' => 'invite.ics', 'type' => 'text/calendar; method=' . $method . '; charset=UTF-8', 'data' => $ics]], ['from_name' => (string)($org['name'] ?? ''), 'reply_to' => (string)($org['email'] ?? '')])) $n++; }
    } catch (Throwable $e) { error_log('Aerosub Office invite: ' . $e->getMessage()); }
    return $n;
}
/** Adds people to a meeting (shared with them) and tells them. @return array the people newly added */
function ics_invite_people(array $t, array $ids, array $by, bool $notify = true): array {
    $added = []; foreach (array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 120) as $x) { if ($x === (int)$by['id'] || $x === (int)$t['creator_id']) continue; $r = one('SELECT id,name,email FROM users WHERE id=? AND active=1 AND archived_at IS NULL', [$x]); if (!$r) continue;
        $new = q('INSERT IGNORE INTO task_shares (task_id,user_id,shared_by) VALUES (?,?,?)', [$t['id'], $x, $by['id']])->rowCount(); if (!$new) continue; $added[] = $r;
        if ($notify) notify($x, 'meeting_invite', 'Meeting invitation: ' . $t['title'], 'Starts ' . date('D j M, H:i', strtotime($t['starts_at'])) . ' · from ' . $by['name'], '#/tasks/' . $t['id']); }
    return $added;
}

/* ---------------- public routes: /api/ics/... ---------------- */
if (isset($seg[0]) && $seg[0] === 'ics' && isset($method)) {
    if ($method !== 'GET') fail('GET only', 405);
    $a = $seg[1] ?? ''; $b = $seg[2] ?? '';
    if ($a === 'feed') {
        $uid = ics_verify('feed', 0, preg_replace('/\.ics$/', '', $b)); if (!$uid || !one('SELECT id FROM users WHERE id=? AND active=1 AND archived_at IS NULL', [$uid])) { http_response_code(404); exit; }
        $evs = []; foreach (all("SELECT t.*, c.name AS creator_name, c.email AS creator_email FROM tasks t LEFT JOIN users c ON c.id=t.creator_id WHERE t.task_type='meeting' AND t.starts_at IS NOT NULL AND t.starts_at > (NOW() - INTERVAL 30 DAY) AND (t.assignee_id=? OR t.creator_id=? OR t.id IN (SELECT task_id FROM task_shares WHERE user_id=?)) ORDER BY t.starts_at LIMIT 400", [$uid, $uid, $uid]) as $t)
            $evs[] = ics_vevent($t, ics_people((int)$t['id']), ['name' => $t['creator_name'], 'email' => $t['creator_email']], in_array($t['status'], ['canceled', 'transferred'], true));
        header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: inline; filename="aerosub-meetings.ics"'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); echo ics_calendar($evs, 'PUBLISH', 'Aerosub Office meetings'); exit;
    }
    $tid = (int)$a; $uid = $tid ? ics_verify('task', $tid, preg_replace('/\.ics$/', '', $b)) : null; $t = $uid ? ics_task($tid) : null;
    if (!$t || !in_array($uid, array_map('intval', array_column(ics_people($tid), 'id')), true)) { http_response_code(404); exit; }
    header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: inline; filename="meeting-' . $tid . '.ics"'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); echo ics_for_task($t, 'PUBLISH'); exit;
}
