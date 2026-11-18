<?php
declare(strict_types=1);
// Email-marketing engine: settings, rendering, verification and the sending queue. No user input is trusted here.
require_once __DIR__ . '/mail.php';

function camp_settings(): array {
    $r = one('SELECT * FROM camp_settings WHERE id=1');
    if (!$r) { q('INSERT IGNORE INTO camp_settings (id) VALUES (1)'); $r = one('SELECT * FROM camp_settings WHERE id=1'); }
    return $r;
}
function camp_base(): string {
    $u = trim((string)(camp_settings()['app_url'] ?? '')) ?: trim((string)cfg('app_url'));
    if ($u === '' && !empty($_SERVER['HTTP_HOST'])) $u = 'https://' . preg_replace('/[^a-z0-9.:-]/i', '', $_SERVER['HTTP_HOST']);
    if ($u === '') $u = 'https://office.aerosub.co';
    return rtrim($u, '/');
}
function camp_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function camp_url(string $u, bool $allowMail = true): string {
    $u = trim($u); if ($u === '' || preg_match('/[\s"<>]/', $u)) return '';
    return preg_match($allowMail ? '~^(https?://|mailto:)~i' : '~^https?://~i', $u) ? $u : '';
}
function camp_color(string $c, string $d): string { return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $d; }
function camp_merge(string $t, array $v): string {
    return preg_replace_callback('/\{\{\s*(first_name|last_name|company|email)\s*(?:\|([^}]*))?\}\}/i', function ($m) use ($v) {
        $val = trim((string)($v[strtolower($m[1])] ?? '')); return $val !== '' ? $val : trim($m[2] ?? '');
    }, $t);
}
function camp_default_design(): array {
    return ['bg' => '#EEF2F8', 'width' => 600, 'accent' => '#00A3DE', 'navy' => '#0C1F61', 'blocks' => [
        ['type' => 'header', 'title' => 'AEROSUB', 'logo' => ''],
        ['type' => 'heading', 'text' => 'Hello {{first_name|there}},', 'align' => 'left'],
        ['type' => 'text', 'text' => "Write your message here. Blank lines make new paragraphs. Greet people by name with a merge field such as first name (see the tip under the blocks).", 'align' => 'left'],
        ['type' => 'button', 'text' => 'Learn more', 'url' => 'https://aerosub.co', 'align' => 'left'],
        ['type' => 'divider'],
        ['type' => 'text', 'text' => "Best regards,\nThe Aerosub team", 'align' => 'left'],
    ]];
}
function camp_norm_design($d): array {
    if (is_string($d)) $d = json_decode($d, true);
    if (!is_array($d)) $d = camp_default_design();
    $out = ['bg' => camp_color((string)($d['bg'] ?? ''), '#EEF2F8'), 'width' => max(480, min(700, (int)($d['width'] ?? 600))), 'accent' => camp_color((string)($d['accent'] ?? ''), '#00A3DE'), 'navy' => camp_color((string)($d['navy'] ?? ''), '#0C1F61'), 'blocks' => []];
    foreach (array_slice((array)($d['blocks'] ?? []), 0, 60) as $b) {
        if (!is_array($b)) continue; $t = (string)($b['type'] ?? '');
        $al = in_array($b['align'] ?? '', ['left', 'center', 'right'], true) ? $b['align'] : 'left';
        switch ($t) {
            case 'header': $out['blocks'][] = ['type' => 'header', 'title' => mb_substr((string)($b['title'] ?? ''), 0, 80), 'logo' => camp_url((string)($b['logo'] ?? ''), false)]; break;
            case 'heading': $out['blocks'][] = ['type' => 'heading', 'text' => mb_substr((string)($b['text'] ?? ''), 0, 300), 'align' => $al]; break;
            case 'text': $out['blocks'][] = ['type' => 'text', 'text' => mb_substr((string)($b['text'] ?? ''), 0, 8000), 'align' => $al]; break;
            case 'image': $out['blocks'][] = ['type' => 'image', 'src' => camp_url((string)($b['src'] ?? ''), false), 'alt' => mb_substr((string)($b['alt'] ?? ''), 0, 150), 'link' => camp_url((string)($b['link'] ?? '')), 'size' => max(20, min(100, (int)($b['size'] ?? 100))), 'align' => $al]; break;
            case 'button': $out['blocks'][] = ['type' => 'button', 'text' => mb_substr((string)($b['text'] ?? ''), 0, 80), 'url' => camp_url((string)($b['url'] ?? '')), 'align' => $al, 'color' => camp_color((string)($b['color'] ?? ''), '')]; break;
            case 'divider': $out['blocks'][] = ['type' => 'divider']; break;
            case 'spacer': $out['blocks'][] = ['type' => 'spacer', 'h' => max(8, min(80, (int)($b['h'] ?? 24)))]; break;
            case 'columns': $out['blocks'][] = ['type' => 'columns', 'left' => mb_substr((string)($b['left'] ?? ''), 0, 3000), 'right' => mb_substr((string)($b['right'] ?? ''), 0, 3000)]; break;
        }
    }
    return $out;
}
function camp_para(string $text, array $v): string {
    $t = camp_h(camp_merge($text, $v));
    $t = preg_replace('~(https?://[^\s<]+)~i', '<a href="$1" style="color:#0a6fb8">$1</a>', $t);
    return nl2br(str_replace("\r", '', $t), false);
}
function camp_render(array $design, array $v, string $unsubUrl, string $preheader = ''): string {
    $dz = camp_norm_design($design); $w = $dz['width']; $ac = $dz['accent']; $nv = $dz['navy']; $font = "Poppins,Inter,Arial,Helvetica,sans-serif";
    $rows = '';
    foreach ($dz['blocks'] as $b) {
        switch ($b['type']) {
            case 'header':
                $logo = $b['logo'] !== '' ? '<img src="' . camp_h($b['logo']) . '" alt="' . camp_h($b['title']) . '" height="40" style="display:block;border:0;height:40px;max-width:260px">' : '<span style="font:700 22px/1 ' . $font . ';letter-spacing:4px;color:#ffffff">' . camp_h($b['title']) . '</span>';
                $rows .= '<tr><td style="background:' . $nv . ';padding:22px 32px;border-radius:12px 12px 0 0;border-bottom:4px solid ' . $ac . '">' . $logo . '</td></tr>'; break;
            case 'heading': $rows .= '<tr><td style="padding:24px 32px 4px;text-align:' . $b['align'] . ';font:700 24px/1.3 ' . $font . ';color:' . $nv . '">' . camp_h(camp_merge($b['text'], $v)) . '</td></tr>'; break;
            case 'text': $rows .= '<tr><td style="padding:10px 32px;text-align:' . $b['align'] . ';font:400 15px/1.65 Arial,Helvetica,sans-serif;color:#1f2937">' . camp_para($b['text'], $v) . '</td></tr>'; break;
            case 'image':
                if ($b['src'] === '') break;
                $img = '<img src="' . camp_h($b['src']) . '" alt="' . camp_h($b['alt']) . '" width="' . (int)round(($w - 64) * $b['size'] / 100) . '" style="display:inline-block;border:0;max-width:100%;height:auto;border-radius:8px">';
                if ($b['link'] !== '') $img = '<a href="' . camp_h($b['link']) . '">' . $img . '</a>';
                $rows .= '<tr><td style="padding:10px 32px;text-align:' . $b['align'] . '">' . $img . '</td></tr>'; break;
            case 'button':
                if ($b['url'] === '' || $b['text'] === '') break;
                $col = $b['color'] !== '' ? $b['color'] : $ac;
                $rows .= '<tr><td style="padding:14px 32px;text-align:' . $b['align'] . '"><a href="' . camp_h($b['url']) . '" style="display:inline-block;background:' . $col . ';color:#ffffff;text-decoration:none;font:600 15px/1 ' . $font . ';padding:14px 28px;border-radius:999px">' . camp_h(camp_merge($b['text'], $v)) . '</a></td></tr>'; break;
            case 'divider': $rows .= '<tr><td style="padding:12px 32px"><div style="border-top:1px solid #dbe3ef;height:1px;line-height:1px">&nbsp;</div></td></tr>'; break;
            case 'spacer': $rows .= '<tr><td style="height:' . $b['h'] . 'px;line-height:' . $b['h'] . 'px;font-size:1px">&nbsp;</td></tr>'; break;
            case 'columns':
                $c = 'font:400 15px/1.6 Arial,Helvetica,sans-serif;color:#1f2937;vertical-align:top;padding:0 8px';
                $rows .= '<tr><td style="padding:10px 24px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td class="col" width="50%" style="' . $c . '">' . camp_para($b['left'], $v) . '</td><td class="col" width="50%" style="' . $c . '">' . camp_para($b['right'], $v) . '</td></tr></table></td></tr>'; break;
        }
    }
    $st = camp_settings(); $addr = trim((string)$st['address']);
    $foot = '<tr><td style="padding:22px 32px;background:#f7f9fc;border-radius:0 0 12px 12px;text-align:center;font:400 12px/1.6 Arial,Helvetica,sans-serif;color:#6b7280">' .
        ($addr !== '' ? camp_h($addr) . '<br>' : '') . 'You are receiving this email because you are a contact of Aerosub.<br><a href="' . camp_h($unsubUrl) . '" style="color:#6b7280;text-decoration:underline">Unsubscribe</a></td></tr>';
    $pre = $preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . camp_h(camp_merge($preheader, $v)) . '</div>' : '';
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>@media(max-width:620px){.col{display:block!important;width:100%!important;padding:6px 0!important}.wrap{width:100%!important}}</style></head><body style="margin:0;padding:0;background:' . $dz['bg'] . '">' . $pre .
        '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:' . $dz['bg'] . '"><tr><td align="center" style="padding:24px 12px"><table role="presentation" class="wrap" width="' . $w . '" cellspacing="0" cellpadding="0" style="width:' . $w . 'px;max-width:100%;background:#ffffff;border-radius:12px">' . $rows . $foot . '</table></td></tr></table></body></html>';
}
function camp_text(array $design, array $v, string $unsubUrl): string {
    $dz = camp_norm_design($design); $o = [];
    foreach ($dz['blocks'] as $b) {
        if ($b['type'] === 'header' && $b['title'] !== '') $o[] = strtoupper($b['title']);
        elseif ($b['type'] === 'heading' || $b['type'] === 'text') $o[] = camp_merge($b['text'], $v);
        elseif ($b['type'] === 'columns') { $o[] = camp_merge($b['left'], $v); $o[] = camp_merge($b['right'], $v); }
        elseif ($b['type'] === 'button' && $b['url'] !== '') $o[] = camp_merge($b['text'], $v) . ': ' . $b['url'];
        elseif ($b['type'] === 'image' && $b['link'] !== '') $o[] = $b['link'];
    }
    $a = trim((string)camp_settings()['address']);
    return trim(implode("\n\n", array_filter($o, fn($x) => trim($x) !== ''))) . "\n\n--\n" . ($a !== '' ? $a . "\n" : '') . 'Unsubscribe: ' . $unsubUrl;
}

/* ---------------- verification ---------------- */
function camp_typo_map(): array {
    return ['gmial.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmail.con' => 'gmail.com', 'gmail.cm' => 'gmail.com', 'gnail.com' => 'gmail.com',
        'hotmial.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com', 'hotmail.con' => 'hotmail.com', 'yahooo.com' => 'yahoo.com', 'yaho.com' => 'yahoo.com', 'yahoo.con' => 'yahoo.com', 'outlok.com' => 'outlook.com', 'outllok.com' => 'outlook.com',
        'outlook.con' => 'outlook.com', 'icloud.con' => 'icloud.com', 'iclod.com' => 'icloud.com'];
}
function camp_disposable(): array {
    return ['mailinator.com', 'guerrillamail.com', 'guerrillamail.net', '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'trashmail.com', 'yopmail.com', 'sharklasers.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'throwawaymail.com',
        'fakeinbox.com', 'mintemail.com', 'mohmal.com', 'emailondeck.com', 'tempinbox.com', 'spamgourmet.com', 'mailnesia.com', 'burnermail.io', 'tempail.com', 'discard.email'];
}
function camp_verify_email(string $email, array &$dns): array { // returns [status, note]
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) return ['invalid', 'Not a valid email format'];
    [$local, $dom] = explode('@', $email, 2);
    if (!str_contains($dom, '.') || strlen($local) > 64) return ['invalid', 'Not a valid email format'];
    $typo = camp_typo_map(); if (isset($typo[$dom])) return ['invalid', 'Looks like a typo — did you mean ' . $local . '@' . $typo[$dom] . '?'];
    if (in_array($dom, camp_disposable(), true)) return ['invalid', 'Disposable / throwaway address'];
    if (!array_key_exists($dom, $dns)) {
        $res = 'unknown';
        if (function_exists('checkdnsrr')) { $res = @checkdnsrr($dom . '.', 'MX') ? 'mx' : (@checkdnsrr($dom . '.', 'A') || @checkdnsrr($dom . '.', 'AAAA') ? 'a' : 'none'); }
        $dns[$dom] = $res;
    }
    if ($dns[$dom] === 'none') return ['invalid', 'Domain does not exist or cannot receive email'];
    $note = '';
    if (in_array($local, ['info', 'admin', 'support', 'sales', 'contact', 'noreply', 'no-reply', 'postmaster', 'abuse', 'webmaster', 'office', 'enquiries', 'mail', 'team'], true)) $note = 'Shared/role mailbox';
    if ($dns[$dom] === 'a') $note = 'No mail-server record; may not receive email';
    if ($dns[$dom] === 'unknown') $note = $note ?: 'Format is fine; domain could not be checked';
    return $note !== '' ? ['risky', $note] : ['valid', 'Format and mail server look good'];
}

/* ---------------- sending ---------------- */
function camp_unsub_url(string $token): string { return camp_base() . '/unsub.php?t=' . $token; }
function camp_build_raw(array $camp, array $c, array $st, ?array $ctx = null): string {
    $v = ['first_name' => (string)$c['first_name'], 'last_name' => (string)$c['last_name'], 'company' => (string)$c['company'], 'email' => (string)$c['email']];
    $un = camp_unsub_url((string)$c['token']) . ($ctx ? '&k=' . (int)($ctx['c'] ?? 0) . '&s=' . (int)($ctx['s'] ?? 0) : ''); $fromEmail = $camp['from_email'] ?: $st['from_email'];
    $dom = substr(strrchr($fromEmail, '@'), 1) ?: 'aerosub.co';
    $html = camp_render(json_decode((string)$camp['design'], true) ?: [], $v, $un, (string)$camp['preheader']);
    if ($ctx && !preg_match('/^0+$/', (string)$c['token'])) $html = camp_track_html($html, $c, $ctx);
    $text = camp_text(json_decode((string)$camp['design'], true) ?: [], $v, $un);
    $extra = ['List-Unsubscribe: <' . $un . '>' . (filter_var($st['reply_to'] ?: $fromEmail, FILTER_VALIDATE_EMAIL) ? ', <mailto:' . ($st['reply_to'] ?: $fromEmail) . '?subject=unsubscribe>' : ''), 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', 'Precedence: bulk', 'X-Campaign-ID: ' . (int)$camp['id']];
    if (!empty($st['reply_to']) && filter_var($st['reply_to'], FILTER_VALIDATE_EMAIL)) $extra[] = 'Reply-To: ' . $st['reply_to'];
    // build_message puts the html inside a wrapper body; for campaigns we need the full document, so pass it through a marker
    $raw = build_message(['from' => ['name' => $camp['from_name'] ?: $st['from_name'], 'email' => $fromEmail], 'to' => [['name' => trim($c['first_name'] . ' ' . $c['last_name']), 'email' => $c['email']]], 'cc' => [],
        'subject' => camp_merge((string)$camp['subject'], $v), 'html' => '', 'text' => $text, 'attachments' => [], 'domain' => $dom, 'extra_headers' => $extra, 'full_html' => $html]);
    return $raw;
}
function camp_sig(string $s): string { return substr(hash_hmac('sha256', $s, mail_key()), 0, 24); }
function camp_track_html(string $html, array $c, array $ctx): string { // open pixel + signed click redirects (no open-redirect possible)
    $base = camp_base(); $t = (string)$c['token']; $k = (int)($ctx['c'] ?? 0); $sp = (int)($ctx['s'] ?? 0);
    $html = preg_replace_callback('~<a\b([^>]*?)href="(https?://[^"]+)"~i', function ($m) use ($base, $t, $k, $sp) {
        $u = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5); if (str_contains($u, '/unsub.php?t=')) return $m[0];
        $b = rtrim(strtr(base64_encode($u), '+/', '-_'), '=');
        return '<a' . $m[1] . 'href="' . camp_h($base . '/track.php?e=c&t=' . $t . '&k=' . $k . '&s=' . $sp . '&u=' . $b . '&g=' . camp_sig($t . '|' . $k . '|' . $sp . '|' . $u)) . '"';
    }, $html);
    $px = '<img src="' . camp_h($base . '/track.php?e=o&t=' . $t . '&k=' . $k . '&s=' . $sp) . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px">';
    return str_ireplace('</body>', $px . '</body>', $html);
}
function camp_smtp(array $st): Smtp {
    if (empty($st['smtp_host']) || empty($st['smtp_user']) || empty($st['secret_enc']) || empty($st['from_email'])) throw new MailError('Set up the sending account first (Campaigns → Settings).');
    $sm = new Smtp($st['smtp_host'], (int)$st['smtp_port'], !cfg('mail_insecure_tls'));
    $sm->auth($st['smtp_user'], mail_decrypt($st['secret_enc']));
    return $sm;
}
function camp_queue_build(int $cid): int {
    $c = one('SELECT * FROM camp_campaigns WHERE id=?', [$cid]); if (!$c || !$c['list_id']) return 0;
    q("INSERT IGNORE INTO camp_queue (campaign_id, contact_id) SELECT ?, id FROM camp_contacts WHERE list_id=? AND status='subscribed' AND verify<>'invalid' AND (? = 1 OR verify<>'risky') AND email NOT IN (SELECT email FROM camp_suppress)", [$cid, $c['list_id'], (int)$c['include_risky']]);
    $n = (int)one('SELECT COUNT(*) c FROM camp_queue WHERE campaign_id=?', [$cid])['c'];
    q('UPDATE camp_campaigns SET total=? WHERE id=?', [$n, $cid]); return $n;
}
function camp_hour_used(): int {
    return (int)one("SELECT COUNT(*) c FROM camp_queue WHERE status='sent' AND sent_at > (NOW() - INTERVAL 1 HOUR)")['c'] + (int)one("SELECT COUNT(*) c FROM camp_auto_log WHERE status='sent' AND sent_at > (NOW() - INTERVAL 1 HOUR)")['c'];
}
function camp_is_bounce(string $msg): bool { return (bool)preg_match('/\b55\d\b|user unknown|does not exist|no such user|mailbox unavailable/i', $msg); }
function camp_auto_sweep(): void { // enrol people who joined an active auto-responder's list
    foreach (all("SELECT * FROM camp_autos WHERE status='active' AND list_id IS NOT NULL") as $a) {
        $f = one('SELECT position, delay_hours FROM camp_auto_steps WHERE auto_id=? ORDER BY position LIMIT 1', [$a['id']]); if (!$f) continue;
        q("INSERT IGNORE INTO camp_auto_state (auto_id, contact_id, next_pos, next_at)
           SELECT ?, c.id, ?, DATE_ADD(GREATEST(c.created_at, ?), INTERVAL ? HOUR) FROM camp_contacts c
           WHERE c.list_id=? AND c.status='subscribed' AND c.verify<>'invalid' AND c.email NOT IN (SELECT email FROM camp_suppress) AND (? = 1 OR c.created_at >= ?)",
          [$a['id'], $f['position'], $a['activated_at'], (int)$f['delay_hours'], $a['list_id'], (int)$a['include_existing'], $a['activated_at']]);
    }
}
function camp_tick_autos(array $st, int $room): int {
    camp_auto_sweep(); $done = 0; if ($room <= 0) return 0;
    $rows = all("SELECT s.id sid, s.auto_id, s.next_pos, a.from_name AS afn, a.from_email AS afe, c.* FROM camp_auto_state s JOIN camp_autos a ON a.id=s.auto_id JOIN camp_contacts c ON c.id=s.contact_id
                 WHERE s.status='active' AND a.status='active' AND s.next_at <= NOW() ORDER BY s.next_at LIMIT " . (int)$room);
    if (!$rows) return 0;
    try { $sm = camp_smtp($st); } catch (MailError $e) { return 0; }
    foreach ($rows as $r) {
        $step = one('SELECT * FROM camp_auto_steps WHERE auto_id=? AND position=?', [$r['auto_id'], $r['next_pos']]);
        if (!$step) { q("UPDATE camp_auto_state SET status='done' WHERE id=?", [$r['sid']]); continue; }
        $cur = one('SELECT status FROM camp_contacts WHERE id=?', [$r['id']]);
        if (!$cur || $cur['status'] !== 'subscribed' || $r['verify'] === 'invalid' || $r['verify'] === 'risky' || one('SELECT 1 FROM camp_suppress WHERE email=?', [$r['email']])) { q("UPDATE camp_auto_state SET status='stopped' WHERE id=?", [$r['sid']]); continue; }
        $camp = ['id' => (int)$step['id'], 'subject' => $step['subject'], 'preheader' => $step['preheader'], 'design' => $step['design'], 'from_name' => $r['afn'], 'from_email' => $r['afe']];
        try {
            $sm->deliver($camp['from_email'] ?: $st['from_email'], [$r['email']], camp_build_raw($camp, $r, $st, ['c' => 0, 's' => (int)$step['id']]));
            q("INSERT INTO camp_auto_log (state_id,auto_id,step_id,contact_id,status) VALUES (?,?,?,?, 'sent')", [$r['sid'], $r['auto_id'], $step['id'], $r['id']]); $done++;
            $nx = one('SELECT position, delay_hours FROM camp_auto_steps WHERE auto_id=? AND position>? ORDER BY position LIMIT 1', [$r['auto_id'], $r['next_pos']]);
            if ($nx) q("UPDATE camp_auto_state SET next_pos=?, next_at=DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id=?", [$nx['position'], (int)$nx['delay_hours'], $r['sid']]);
            else q("UPDATE camp_auto_state SET status='done' WHERE id=?", [$r['sid']]);
        } catch (MailError $e) {
            $msg = mb_substr($e->getMessage(), 0, 190);
            q("INSERT INTO camp_auto_log (state_id,auto_id,step_id,contact_id,status,error) VALUES (?,?,?,?, 'failed', ?)", [$r['sid'], $r['auto_id'], $step['id'], $r['id'], $msg]);
            if (camp_is_bounce($msg)) { q("UPDATE camp_contacts SET status='bounced' WHERE id=?", [$r['id']]); q("UPDATE camp_auto_state SET status='stopped' WHERE id=?", [$r['sid']]); }
            else q("UPDATE camp_auto_state SET next_at=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?", [$r['sid']]);
            try { $sm->reset(); } catch (Throwable $x) { try { $sm = camp_smtp($st); } catch (Throwable $y) { break; } }
        }
    }
    return $done;
}
function camp_tick(int $max = 50): array {
    $lock = one("SELECT GET_LOCK('camp_tick', 0) l"); if (!$lock || (int)$lock['l'] !== 1) return ['busy' => true];
    $done = 0; $autos = 0;
    try {
        foreach (all("SELECT id FROM camp_campaigns WHERE status='scheduled' AND scheduled_at <= NOW()") as $r) { camp_queue_build((int)$r['id']); q("UPDATE camp_campaigns SET status='sending', started_at=COALESCE(started_at,NOW()), last_error=NULL WHERE id=?", [$r['id']]); }
        $st = camp_settings();
        $room = max(0, min($max, (int)$st['hour_cap'] - camp_hour_used()));
        $camp = $room > 0 ? one("SELECT * FROM camp_campaigns WHERE status='sending' ORDER BY id LIMIT 1") : null;
        if ($camp) {
            $rows = all("SELECT qu.id qid, c.* FROM camp_queue qu JOIN camp_contacts c ON c.id=qu.contact_id WHERE qu.campaign_id=? AND qu.status='pending' ORDER BY qu.id LIMIT " . (int)$room, [$camp['id']]);
            if ($rows) {
                try { $sm = camp_smtp($st); }
                catch (MailError $e) { q("UPDATE camp_campaigns SET status='paused', last_error=? WHERE id=?", [mb_substr($e->getMessage(), 0, 290), $camp['id']]); $sm = null; }
                if ($sm) foreach ($rows as $r) {
                    if (one('SELECT 1 FROM camp_suppress WHERE email=?', [$r['email']]) || (one('SELECT status FROM camp_contacts WHERE id=?', [$r['id']])['status'] ?? '') !== 'subscribed') { q("UPDATE camp_queue SET status='failed', error='Unsubscribed' WHERE id=?", [$r['qid']]); continue; }
                    try {
                        $sm->deliver($camp['from_email'] ?: $st['from_email'], [$r['email']], camp_build_raw($camp, $r, $st, ['c' => (int)$camp['id'], 's' => 0]));
                        q("UPDATE camp_queue SET status='sent', sent_at=NOW() WHERE id=?", [$r['qid']]); $done++;
                    } catch (MailError $e) {
                        $msg = mb_substr($e->getMessage(), 0, 190);
                        q("UPDATE camp_queue SET status='failed', error=? WHERE id=?", [$msg, $r['qid']]);
                        if (camp_is_bounce($msg)) q("UPDATE camp_contacts SET status='bounced' WHERE id=?", [$r['id']]);
                        try { $sm->reset(); } catch (Throwable $x) { try { $sm = camp_smtp($st); } catch (Throwable $y) { break; } }
                    }
                }
            }
            $c = one("SELECT SUM(status='sent') s, SUM(status='failed') f, SUM(status='pending') p FROM camp_queue WHERE campaign_id=?", [$camp['id']]);
            q('UPDATE camp_campaigns SET sent=?, failed=? WHERE id=?', [(int)$c['s'], (int)$c['f'], $camp['id']]);
            if ((int)$c['p'] === 0) q("UPDATE camp_campaigns SET status='sent', finished_at=NOW() WHERE id=? AND status='sending'", [$camp['id']]);
        }
        $room2 = max(0, min($max, (int)$st['hour_cap'] - camp_hour_used()));
        $autos = camp_tick_autos($st, $room2);
    } finally { q("SELECT RELEASE_LOCK('camp_tick')"); }
    return ['sent' => $done, 'auto_sent' => $autos];
}
