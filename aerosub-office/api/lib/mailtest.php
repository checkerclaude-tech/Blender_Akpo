<?php
declare(strict_types=1);
// Test your emails with mail-tester.com (Campaigns > Test your emails).
// Mail-Tester gives each test a one-off address (test-xxxx@srv1.mail-tester.com). We send a real campaign-style message from your sending account to it
// (same SMTP, same headers, unsubscribe link and design a recipient would get), then read the score back, and we check the DNS records of the sending domain.
//   GET  /api/mail-test/overview     recent tests, campaigns you can test, the sending domain
//   POST /api/mail-test/send {address, campaign_id?}      GET /api/mail-test/result?id=ID      GET /api/mail-test/dns
require_once __DIR__ . '/mail.php'; require_once __DIR__ . '/campcore.php';

function mt_base(): string { try { $b = trim((string)(one('SELECT mt_base FROM pr_settings WHERE id=1')['mt_base'] ?? '')); } catch (Throwable $e) { $b = ''; } return rtrim($b !== '' ? $b : 'https://www.mail-tester.com', '/'); }
/** "test-abc123@srv1.mail-tester.com", "https://www.mail-tester.com/test-abc123" or "test-abc123" all give the test id. */
function mt_id(string $v): string { $v = trim($v); if (preg_match('/(test-[a-z0-9]{4,40})/i', $v, $m)) return strtolower($m[1]); return ''; }
function mt_address(string $id): string { return $id . '@srv1.mail-tester.com'; }
function mt_fetch(string $url): array {
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'AerosubOffice/1.0 (deliverability check)', CURLOPT_HTTPHEADER => ['Accept: application/json, text/html;q=0.8']]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch); return [$code, $body === false ? '' : (string)$body, $err];
}
/** Pull a score out of whatever Mail-Tester answers with: JSON (several shapes) or the report page. Returns [score|null, sections[]] */
function mt_parse(string $body): array {
    $score = null; $sections = []; $j = json_decode($body, true);
    $num = function ($v) { if (is_numeric($v)) return (float)$v; if (is_string($v) && preg_match('/(\d+(?:[.,]\d+)?)\s*\/\s*10/', $v, $m)) return (float)str_replace(',', '.', $m[1]); return null; };
    if (is_array($j)) {
        foreach (['mark', 'score', 'note', 'rating', 'result'] as $k) if (isset($j[$k]) && ($n = $num($j[$k])) !== null && $n <= 10) { $score = $n; break; }
        if ($score === null) { $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($j)); foreach ($it as $k => $v) if (in_array(strtolower((string)$k), ['mark', 'score', 'rating'], true) && ($n = $num($v)) !== null && $n <= 10) { $score = $n; break; } }
        foreach ($j as $k => $v) { if (is_scalar($v) || $v === null) { if ($v !== null && $v !== '' && !preg_match('/^(id|token|messageid|message_id)$/i', (string)$k)) $sections[] = ['name' => ucfirst(str_replace('_', ' ', (string)$k)), 'value' => mb_substr((string)$v, 0, 200)]; }
            elseif (is_array($v)) { $short = []; foreach ($v as $kk => $vv) { if (is_scalar($vv)) $short[] = (is_string($kk) ? $kk . ': ' : '') . mb_substr((string)$vv, 0, 90); elseif (is_array($vv) && isset($vv['title'])) $short[] = (string)$vv['title'] . (isset($vv['status']) ? ' (' . (is_scalar($vv['status']) ? $vv['status'] : '') . ')' : ''); if (count($short) >= 8) break; }
                if ($short) $sections[] = ['name' => ucfirst(str_replace('_', ' ', (string)$k)), 'value' => implode(' · ', $short)]; } }
    } elseif (preg_match('/(\d+(?:[.,]\d+)?)\s*\/\s*10/', $body, $m)) $score = (float)str_replace(',', '.', $m[1]);
    return [$score, array_slice($sections, 0, 14)];
}
function mt_verdict(?float $s): array { if ($s === null) return ['Not ready', '']; if ($s >= 9) return ['Excellent: ready to send', 'done']; if ($s >= 7) return ['Good, a few things to improve', 'in_progress']; if ($s >= 5) return ['Risky: some mail will go to spam', 'warn']; return ['Poor: fix before sending', 'blocked']; }
function mt_dns(string $domain): array {
    $out = []; $txt = function (string $h) { try { $r = @dns_get_record($h, DNS_TXT) ?: []; return array_map(fn($x) => (string)($x['txt'] ?? ''), $r); } catch (Throwable $e) { return []; } };
    $spf = array_values(array_filter($txt($domain), fn($t) => stripos($t, 'v=spf1') === 0));
    $out[] = ['check' => 'SPF', 'ok' => (bool)$spf, 'note' => $spf ? $spf[0] : 'No SPF record. Add a TXT record that lists your sending service, e.g. "v=spf1 include:spf.brevo.com ~all".', 'warn' => count($spf) > 1 ? 'More than one SPF record: keep only one.' : ''];
    $dm = array_values(array_filter($txt('_dmarc.' . $domain), fn($t) => stripos($t, 'v=DMARC1') === 0));
    $out[] = ['check' => 'DMARC', 'ok' => (bool)$dm, 'note' => $dm ? $dm[0] : 'No DMARC record. Add a TXT record at _dmarc.' . $domain . ', e.g. "v=DMARC1; p=none; rua=mailto:info@' . $domain . '".', 'warn' => ''];
    $found = ''; foreach (['default', 'google', 'selector1', 'selector2', 'mail', 'k1', 'k2', 'brevo', 'brevo1', 'brevo2', 'mandrill', 'smtp', 's1', 's2', 'dkim', 'zoho', 'mailjet', 'sendgrid', 'mxvault', 'stackmail'] as $sel) { $r = $txt($sel . '._domainkey.' . $domain); if ($r && preg_match('/v=DKIM1|p=/i', implode('', $r))) { $found = $sel; break; } if (!$r) { try { $c = @dns_get_record($sel . '._domainkey.' . $domain, DNS_CNAME) ?: []; if ($c) { $found = $sel . ' (CNAME)'; break; } } catch (Throwable $e) {} } }
    $out[] = ['check' => 'DKIM', 'ok' => $found !== '', 'note' => $found !== '' ? 'Signing key found (selector ' . $found . ').' : 'No signing key found at the usual selectors. Your sending service gives you a DKIM record to add; if you already did, ignore this (the selector may be unusual). Mail-Tester checks it for real.', 'warn' => ''];
    $mx = @dns_get_record($domain, DNS_MX) ?: []; $out[] = ['check' => 'Mail server (MX)', 'ok' => (bool)$mx, 'note' => $mx ? count($mx) . ' mail server record(s).' : 'No MX record: replies cannot reach this domain.', 'warn' => ''];
    return $out;
}

if (isset($seg) && ($seg[0] ?? '') === 'mail-test') {
    $u = need_user(); if (!can_campaigns($u)) fail('Email testing is for the Marketing team', 403); $uid = (int)$u['id']; $what = $seg[1] ?? ''; $b = $method === 'GET' ? [] : body(); $st = camp_settings();
    $domain = substr((string)strrchr((string)($st['from_email'] ?? ''), '@'), 1);
    if ($method === 'GET' && $what === 'overview') {
        out(['configured' => !empty($st['smtp_host']) && !empty($st['from_email']) && !empty($st['secret_enc']), 'from_email' => $st['from_email'] ?? '', 'domain' => $domain, 'base' => 'https://www.mail-tester.com',
            'campaigns' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'subject' => $c['subject'], 'status' => $c['status']], all('SELECT id,name,subject,status FROM camp_campaigns ORDER BY id DESC LIMIT 30')),
            'tests' => array_map(fn($t) => ['id' => (int)$t['id'], 'test_id' => $t['test_id'], 'subject' => $t['subject'], 'source' => $t['source'], 'score' => $t['score'] !== null ? (float)$t['score'] : null, 'sent_at' => $t['sent_at'], 'checked_at' => $t['checked_at'], 'by' => $t['by_name']],
                all('SELECT t.*, u.name AS by_name FROM mt_tests t LEFT JOIN users u ON u.id=t.user_id ORDER BY t.id DESC LIMIT 20'))]);
    }
    if ($method === 'GET' && $what === 'dns') { if ($domain === '') fail('Set up the sending account first (Sending settings)', 409); out(['domain' => $domain, 'checks' => mt_dns($domain)]); }
    if ($method === 'POST' && $what === 'send') {
        if (($u['role'] ?? '') === 'founder') fail('Founder accounts are read-only', 403); $id = mt_id((string)($b['address'] ?? '')); if ($id === '') fail('Paste the address Mail-Tester shows you (it looks like test-abc123@srv1.mail-tester.com).');
        if (empty($st['smtp_host']) || empty($st['from_email']) || empty($st['secret_enc'])) fail('Set up the sending account first (Campaigns > Sending settings).', 409);
        $recent = one("SELECT COUNT(*) c FROM mt_tests WHERE user_id=? AND sent_at > (NOW() - INTERVAL 1 HOUR)", [$uid]); if ((int)$recent['c'] >= 10) fail('Ten tests an hour is enough. Try again later.', 429);
        $cid = (int)($b['campaign_id'] ?? 0); $camp = $cid ? one('SELECT * FROM camp_campaigns WHERE id=?', [$cid]) : null; if ($cid && !$camp) fail('That campaign was not found', 404);
        if (!$camp) $camp = ['id' => 0, 'name' => 'Sample', 'subject' => 'Aerosub: a quick hello from our team', 'preheader' => 'A short note from Aerosub Solutions', 'design' => json_encode(camp_default_design()), 'from_name' => '', 'from_email' => ''];
        $contact = ['first_name' => 'Test', 'last_name' => 'Reader', 'company' => 'Aerosub', 'email' => mt_address($id), 'token' => str_repeat('0', 32)];
        try { $raw = camp_build_raw($camp, $contact, $st); $sm = camp_smtp($st); $sm->deliver((string)$st['from_email'], [mt_address($id)], $raw); }
        catch (MailError $e) { fail('The test could not be sent: ' . $e->getMessage(), 502); } catch (Throwable $e) { fail('The test could not be sent: ' . $e->getMessage(), 502); }
        q('INSERT INTO mt_tests (user_id,test_id,subject,source) VALUES (?,?,?,?)', [$uid, $id, mb_substr(camp_merge((string)$camp['subject'], ['first_name' => 'Test', 'last_name' => 'Reader', 'company' => 'Aerosub', 'email' => '']), 0, 200), $cid ? 'Campaign: ' . mb_substr((string)$camp['name'], 0, 80) : 'Sample message']);
        log_act($uid, 'mail_test_sent', $id); out(['ok' => true, 'test_id' => $id, 'report_url' => 'https://www.mail-tester.com/' . $id]);
    }
    if ($method === 'GET' && $what === 'result') {
        $id = mt_id((string)($_GET['id'] ?? '')); if ($id === '') fail('Unknown test'); $t = one('SELECT * FROM mt_tests WHERE test_id=? ORDER BY id DESC LIMIT 1', [$id]); if (!$t) fail('That test was not sent from here', 404);
        [$code, $body, $err] = mt_fetch(mt_base() . '/' . $id . '&format=json'); if ($code < 200 || $code >= 300 || $body === '') [$code, $body, $err] = mt_fetch(mt_base() . '/' . $id);
        if ($body === '') fail('Could not reach Mail-Tester' . ($err ? ' (' . $err . ')' : '') . '. Open the full report with the link below.', 502);
        [$score, $sections] = mt_parse($body); if ($score !== null) q('UPDATE mt_tests SET score=?, checked_at=NOW() WHERE id=?', [$score, $t['id']]);
        $v = mt_verdict($score); out(['test_id' => $id, 'score' => $score, 'verdict' => $v[0], 'tone' => $v[1], 'sections' => $sections, 'report_url' => 'https://www.mail-tester.com/' . $id, 'waiting' => $score === null]);
    }
    fail('Not found', 404);
}
