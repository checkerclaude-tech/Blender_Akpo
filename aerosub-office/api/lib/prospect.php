<?php
declare(strict_types=1);
// Prospecting (Campaigns > Prospecting): market signals found with Exa (exa.ai). You enter keywords and a region; each kind of signal
// (contracts and tenders, trade shows and events, local news, new hires, social posts) is searched, scored, and kept. A signal can become
// a lead (company, contact, research task) or be saved or dismissed. A "watch" runs the same search every day (cron.php) and tells you what is new.
//   GET  /api/prospecting/overview[?status=&type=&watch=]     POST /api/prospecting/settings {api_key?, region}
//   POST /api/prospecting/search {keywords, region, days, signals[], watch_id?}
//   POST|PATCH|DELETE /api/prospecting/watches[/{id}]          POST /api/prospecting/signals/{id}/lead|save|dismiss
require_once __DIR__ . '/mail.php'; require_once __DIR__ . '/campcore.php';

const PR_TYPES = [
    'contracts' => ['label' => 'Contracts & tenders', 'icon' => '📄', 'weight' => 3.0, 'category' => null, 'domains' => [],
        'q' => '{kw} contract award OR tender OR RFQ OR "request for proposal" OR "expression of interest" {region}'],
    'events' => ['label' => 'Trade shows & events', 'icon' => '🎪', 'weight' => 2.0, 'category' => null, 'domains' => [],
        'q' => '{kw} conference OR exhibition OR "trade show" OR expo OR summit exhibitors speakers {region}'],
    'news' => ['label' => 'Local news', 'icon' => '📰', 'weight' => 1.5, 'category' => 'news', 'domains' => [],
        'q' => '{kw} {region} project OR investment OR expansion OR commissioning OR new facility'],
    'hires' => ['label' => 'New hires & appointments', 'icon' => '🧑‍💼', 'weight' => 2.0, 'category' => null, 'domains' => ['linkedin.com'],
        'q' => 'appointed OR "joins as" OR "new role" OR "excited to join" head OR manager OR director {kw} {region}'],
    'social' => ['label' => 'Social signals', 'icon' => '💬', 'weight' => 1.0, 'category' => null, 'domains' => ['linkedin.com', 'x.com', 'twitter.com', 'facebook.com'],
        'q' => 'looking for OR "we need" OR "anyone recommend" OR "seeking" OR RFP {kw} {region}'],
];
const PR_MEDIA = ['linkedin.com', 'x.com', 'twitter.com', 'facebook.com', 'instagram.com', 'youtube.com', 'medium.com', 'reuters.com', 'bbc.com', 'bloomberg.com', 'punchng.com', 'guardian.ng', 'businessday.ng', 'vanguardngr.com', 'thisdaylive.com', 'premiumtimesng.com', 'nairametrics.com', 'allafrica.com', 'tribuneonlineng.com', 'thenationonlineng.net'];

function pr_str($v, int $n): string { return mb_substr(trim((string)($v ?? '')), 0, $n); }
function pr_settings(): array { $r = one('SELECT * FROM pr_settings WHERE id=1'); if (!$r) { q('INSERT IGNORE INTO pr_settings (id) VALUES (1)'); $r = one('SELECT * FROM pr_settings WHERE id=1'); } return $r; }
function pr_key(): string { $r = pr_settings(); if (empty($r['exa_key_enc'])) return ''; try { return mail_decrypt((string)$r['exa_key_enc']); } catch (Throwable $e) { return ''; } }

/** One search on Exa. Returns the list of results; throws RuntimeException with a message a person can act on. */
function pr_exa(string $key, array $payload, string $endpoint = ''): array {
    $url = $endpoint !== '' ? $endpoint : 'https://api.exa.ai/search';
    for ($try = 0; $try < 2; $try++) {
        $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'x-api-key: ' . $key, 'Authorization: Bearer ' . $key]]);
        $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($body === false) throw new RuntimeException('Could not reach Exa (' . ($err ?: 'no answer') . '). Check that the server may make outside connections to api.exa.ai.');
        $j = json_decode((string)$body, true);
        if ($code === 400 && $try === 0 && isset($payload['category'])) { unset($payload['category']); continue; }       // an unsupported category: ask again without it
        if ($code === 401 || $code === 403) throw new RuntimeException('Exa did not accept the API key. Check it in Settings.');
        if ($code === 402 || $code === 429) throw new RuntimeException('Exa says the free credits or the rate limit are used up. Try again later or check your plan.');
        if ($code >= 400) throw new RuntimeException('Exa returned an error (' . $code . '): ' . mb_substr((string)($j['error'] ?? $j['message'] ?? $body), 0, 160));
        return is_array($j['results'] ?? null) ? $j['results'] : [];
    }
    return [];
}
function pr_domain(string $url): string { $h = strtolower((string)parse_url($url, PHP_URL_HOST)); return preg_replace('/^www\./', '', $h); }
function pr_company_guess(string $title, string $url, string $snippet = ''): string {
    $verbs = 'wins|won|awarded|awards|signs|signed|appoints|appointed|names|hires|launches|secures|secured|acquires|opens|expands|invests|partners|bags|gets|completes|unveils|commissions|invites|announces|seeks|requests|issues';
    if ($snippet !== '' && preg_match('/^(.{3,60}?)\s+(' . $verbs . ')\b/i', trim($snippet), $sm) && !preg_match('/^(the|a|an|how|why|what|new|we|our|this|there)\b/i', $sm[1])) { $fromSnippet = trim($sm[1], " -–—|:"); } else $fromSnippet = '';
    if (preg_match('/\b(appointed|joins|joined|hired|promoted|named|welcomes)\b/i', $title) && preg_match('/\b(?:at|with|by)\s+([A-Z][\p{L}\p{N}&.\- ]{2,50}?)(?:\s+(?:as|in|to)\b|[,.|]|$)/u', $title, $hm)) return trim($hm[1]);   // "Jane Doe appointed Head of Subsea at Delta Offshore" -> Delta Offshore
    $d = pr_domain($url); $media = false; foreach (PR_MEDIA as $m) if ($d === $m || str_ends_with($d, '.' . $m)) $media = true;
    if (preg_match('/^(.{3,60}?)\s+(wins|won|awarded|awards|signs|signed|appoints|appointed|names|hires|launches|secures|secured|acquires|opens|expands|invests|partners|bags|gets|completes|unveils|commissions)\b/i', trim($title), $m) && !preg_match('/^(the|a|an|how|why|what|new)\b/i', $m[1])) return trim($m[1], " -–—|:");
    if ($fromSnippet !== '' && !preg_match('/^(contract|tender|rfq|rfp|notice|project|job|vacancy|opportunity|request|invitation)s?$/i', $fromSnippet)) return $fromSnippet;
    if (!$media && $d !== '') { $l = explode('.', $d); $lab = count($l) > 2 && in_array($l[count($l) - 2], ['co', 'com', 'org', 'net', 'gov', 'ac'], true) ? $l[count($l) - 3] : $l[max(0, count($l) - 2)]; return ucwords(str_replace(['-', '_'], ' ', $lab)); }
    return preg_match('/^(contract|tender|rfq|rfp|notice|project|job|vacancy|opportunity|request|invitation)s?$/i', $fromSnippet) ? '' : $fromSnippet;
}
function pr_score(array $r, string $type, array $kws): float {
    $w = (float)(PR_TYPES[$type]['weight'] ?? 1); $s = $w; $title = mb_strtolower((string)($r['title'] ?? '')); $txt = mb_strtolower(mb_substr((string)($r['text'] ?? '') . ' ' . implode(' ', (array)($r['highlights'] ?? [])) . ' ' . (string)($r['summary'] ?? ''), 0, 2500));
    foreach ($kws as $k) { $k = mb_strtolower($k); if ($k === '') continue; if (str_contains($title, $k)) $s += 2; if (str_contains($txt, $k)) $s += 1; }
    if (!empty($r['publishedDate'])) { $age = (time() - (int)strtotime((string)$r['publishedDate'])) / 86400; if ($age <= 7) $s += 3; elseif ($age <= 30) $s += 2; elseif ($age <= 90) $s += 1; }
    $s += min(2, max(0, (float)($r['score'] ?? 0))); return round($s, 2);
}
/** Run every chosen signal type for the keywords; save what is new. Returns ['new' => n, 'seen' => n, 'errors' => [...]] */
function pr_run(array $o, int $uid, ?int $watchId = null): array {
    $key = pr_key(); if ($key === '') throw new RuntimeException('Add your Exa API key first (Prospecting > Settings).');
    $kwList = array_values(array_filter(array_map('trim', preg_split('/[,\n;]+/', (string)$o['keywords'])))); if (!$kwList) throw new RuntimeException('Enter at least one keyword');
    $kw = implode(' OR ', array_map(fn($k) => str_contains($k, ' ') ? '"' . $k . '"' : $k, array_slice($kwList, 0, 6))); $region = pr_str($o['region'] ?? '', 120); $days = max(7, min(365, (int)($o['days'] ?? 30)));
    $types = array_values(array_intersect(array_keys(PR_TYPES), (array)($o['signals'] ?? array_keys(PR_TYPES)))); if (!$types) $types = array_keys(PR_TYPES); $end = (string)(pr_settings()['endpoint'] ?? '');
    $new = 0; $seen = 0; $errors = []; $ids = [];
    foreach ($types as $t) { $d = PR_TYPES[$t]; $payload = ['query' => trim(str_replace(['{kw}', '{region}'], [$kw, $region], $d['q'])), 'numResults' => (int)($o['per'] ?? 8), 'type' => 'auto', 'startPublishedDate' => gmdate('Y-m-d\TH:i:s.000\Z', time() - $days * 86400), 'contents' => ['text' => ['maxCharacters' => 600], 'summary' => ['query' => 'What company or organisation is involved and what is the business opportunity?']]];
        if ($d['category']) $payload['category'] = $d['category']; if ($d['domains']) $payload['includeDomains'] = $d['domains'];
        try { $rs = pr_exa($key, $payload, $end); } catch (RuntimeException $e) { $errors[] = $d['label'] . ': ' . $e->getMessage(); if (str_contains($e->getMessage(), 'API key') || str_contains($e->getMessage(), 'credits')) break; continue; }
        foreach ($rs as $r) { $url = trim((string)($r['url'] ?? '')); if ($url === '' || !preg_match('~^https?://~i', $url)) continue; $h = sha1(strtolower(preg_replace('~[#?].*$~', '', $url)));
            if (one('SELECT id FROM pr_signals WHERE url_hash=?', [$h])) { $seen++; continue; }
            $title = pr_str($r['title'] ?? $url, 300); $snip = pr_str(($r['summary'] ?? '') ?: (is_array($r['highlights'] ?? null) ? implode(' … ', $r['highlights']) : ($r['text'] ?? '')), 900); $pub = !empty($r['publishedDate']) ? date('Y-m-d H:i:s', (int)strtotime((string)$r['publishedDate'])) : null;
            q('INSERT INTO pr_signals (watch_id,user_id,type,title,url,url_hash,domain,published_at,snippet,score,company_guess,keywords) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$watchId, $uid, $t, $title, mb_substr($url, 0, 600), $h, pr_domain($url), $pub, $snip, pr_score($r, $t, $kwList), pr_company_guess($title, $url, $snip), pr_str(implode(', ', $kwList), 300)]); $ids[] = (int)db()->lastInsertId(); $new++; } }
    q("INSERT INTO activity_log (user_id,action,detail) VALUES (?,?,?)", [$uid, 'prospect_search', mb_substr(implode(', ', $kwList), 0, 150)]);
    return ['new' => $new, 'seen' => $seen, 'errors' => $errors, 'ids' => $ids];
}
/** cron.php: run every active watch once a day and tell its owner what is new. */
function pr_cron(): string {
    try { if (pr_key() === '') return 'Prospecting: no Exa key'; $n = 0; $tot = 0;
        foreach (all("SELECT * FROM pr_watches WHERE active=1 AND (last_run_at IS NULL OR last_run_at < (NOW() - INTERVAL 20 HOUR)) ORDER BY id LIMIT 10") as $w) {
            try { $r = pr_run(['keywords' => $w['keywords'], 'region' => $w['region'], 'days' => $w['days'], 'signals' => json_decode((string)$w['signals'], true) ?: [], 'per' => 6], (int)$w['user_id'], (int)$w['id']); $tot += $r['new']; $n++;
                if ($r['new'] > 0) notify((int)$w['user_id'], 'prospect_signals', $r['new'] . ' new market signal' . ($r['new'] === 1 ? '' : 's') . ': ' . $w['name'], 'Open Campaigns > Prospecting to review them.', '#/campaigns'); }
            catch (Throwable $e) { error_log('Prospecting watch ' . $w['id'] . ': ' . $e->getMessage()); }
            q('UPDATE pr_watches SET last_run_at=NOW() WHERE id=?', [$w['id']]); }
        return "Prospecting: $n watch(es), $tot new signal(s)"; }
    catch (Throwable $e) { return 'Prospecting skipped: ' . $e->getMessage(); }
}
function pr_out(array $r): array { return ['id' => (int)$r['id'], 'type' => $r['type'], 'title' => $r['title'], 'url' => $r['url'], 'domain' => $r['domain'], 'published_at' => $r['published_at'], 'snippet' => $r['snippet'], 'score' => (float)$r['score'], 'company' => $r['company_guess'], 'keywords' => $r['keywords'], 'status' => $r['status'], 'company_id' => $r['company_id'] ? (int)$r['company_id'] : null, 'task_id' => $r['task_id'] ? (int)$r['task_id'] : null, 'watch' => $r['watch_name'] ?? null, 'created_at' => $r['created_at']]; }

/* ---------- routes ---------- */
if (isset($seg) && ($seg[0] ?? '') === 'prospecting') {
    $u = need_user(); if (!can_campaigns($u)) fail('Prospecting is for the Marketing team', 403); $uid = (int)$u['id']; $what = $seg[1] ?? ''; $b = $method === 'GET' ? [] : body();
    if ($method !== 'GET' && ($u['role'] ?? '') === 'founder') fail('Founder accounts are read-only', 403);
    $admin = ($u['role'] ?? '') === 'super_admin' || !empty($u['is_root']);
    if ($method === 'GET' && $what === 'overview') {
        $st = pr_settings(); $w = ['1=1']; $p = [];
        $status = in((string)($_GET['status'] ?? 'new'), ['new', 'lead', 'saved', 'dismissed', 'all'], 'new'); if ($status !== 'all') { $w[] = 's.status=?'; $p[] = $status; }
        if (!empty($_GET['type']) && isset(PR_TYPES[$_GET['type']])) { $w[] = 's.type=?'; $p[] = $_GET['type']; } if (!empty($_GET['watch'])) { $w[] = 's.watch_id=?'; $p[] = (int)$_GET['watch']; }
        $rows = all('SELECT s.*, wt.name AS watch_name FROM pr_signals s LEFT JOIN pr_watches wt ON wt.id=s.watch_id WHERE ' . implode(' AND ', $w) . ' ORDER BY s.score DESC, s.id DESC LIMIT 150', $p);
        $counts = array_column(all('SELECT status, COUNT(*) n FROM pr_signals GROUP BY status'), 'n', 'status'); $used = (int)one("SELECT COUNT(*) c FROM activity_log WHERE action='prospect_search' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")['c'];
        out(['has_key' => pr_key() !== '', 'region' => $st['region'], 'can_set_key' => $admin, 'searches_this_month' => $used, 'counts' => $counts, 'types' => array_map(fn($k, $d) => ['key' => $k, 'label' => $d['label'], 'icon' => $d['icon']], array_keys(PR_TYPES), PR_TYPES),
            'watches' => all('SELECT id,name,keywords,region,signals,days,active,last_run_at FROM pr_watches ORDER BY id DESC LIMIT 30'), 'signals' => array_map('pr_out', $rows), 'lists' => array_map(fn($l) => ['id' => (int)$l['id'], 'name' => $l['name']], (function () { try { return all('SELECT id,name FROM camp_lists ORDER BY name LIMIT 100'); } catch (Throwable $e) { return []; } })())]);
    }
    if ($method === 'POST' && $what === 'settings') {
        if (!$admin) fail('Only the Super Admin can change the Exa key', 403); $key = trim((string)($b['api_key'] ?? ''));
        if ($key !== '') { if (!preg_match('/^[A-Za-z0-9._\-]{16,200}$/', $key)) fail('That does not look like an Exa API key'); q('UPDATE pr_settings SET exa_key_enc=? WHERE id=1', [mail_encrypt($key)]); }
        if (!empty($b['remove_key'])) q('UPDATE pr_settings SET exa_key_enc=NULL WHERE id=1'); q('UPDATE pr_settings SET region=? WHERE id=1', [pr_str($b['region'] ?? '', 120) ?: null]); log_act($uid, 'prospecting_settings', ''); out(['ok' => true]);
    }
    if ($method === 'POST' && $what === 'search') {
        try { $r = pr_run(['keywords' => $b['keywords'] ?? '', 'region' => $b['region'] ?? pr_settings()['region'], 'days' => $b['days'] ?? 30, 'signals' => $b['signals'] ?? []], $uid, !empty($b['watch_id']) ? (int)$b['watch_id'] : null); }
        catch (RuntimeException $e) { fail($e->getMessage(), 400); } out(['ok' => true] + $r);
    }
    if ($what === 'watches') {
        $wid = ctype_digit($seg[2] ?? '') ? (int)$seg[2] : 0;
        if ($method === 'POST' && !$wid) { $kw = pr_str($b['keywords'] ?? '', 300); if ($kw === '') fail('Enter at least one keyword'); if ((int)one('SELECT COUNT(*) c FROM pr_watches')['c'] >= 15) fail('Up to 15 daily watches');
            $sig = array_values(array_intersect(array_keys(PR_TYPES), (array)($b['signals'] ?? []))) ?: array_keys(PR_TYPES); q('INSERT INTO pr_watches (user_id,name,keywords,region,signals,days) VALUES (?,?,?,?,?,?)', [$uid, pr_str($b['name'] ?? '', 120) ?: mb_substr($kw, 0, 60), $kw, pr_str($b['region'] ?? '', 120) ?: null, json_encode($sig), max(7, min(365, (int)($b['days'] ?? 30)))]); out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201); }
        $w = $wid ? one('SELECT * FROM pr_watches WHERE id=?', [$wid]) : null; if (!$w) fail('Not found', 404);
        if ($method === 'PATCH') { q('UPDATE pr_watches SET active=? WHERE id=?', [!empty($b['active']) ? 1 : 0, $wid]); out(['ok' => true]); }
        if ($method === 'DELETE') { q('UPDATE pr_signals SET watch_id=NULL WHERE watch_id=?', [$wid]); q('DELETE FROM pr_watches WHERE id=?', [$wid]); out(['ok' => true]); }
        fail('Bad request', 400);
    }
    if ($what === 'signals' && ctype_digit($seg[2] ?? '')) {
        $sg = one('SELECT * FROM pr_signals WHERE id=?', [(int)$seg[2]]); if (!$sg) fail('Not found', 404); $act = $seg[3] ?? '';
        if ($method === 'POST' && ($act === 'save' || $act === 'dismiss')) { q('UPDATE pr_signals SET status=? WHERE id=?', [$act === 'save' ? 'saved' : 'dismissed', $sg['id']]); out(['ok' => true]); }
        if ($method === 'POST' && $act === 'lead') {   // a signal becomes a company in the Pipeline, an optional contact, and a research task
            $name = pr_str($b['company'] ?? $sg['company_guess'], 190); if ($name === '') fail('Enter the company name'); $note = pr_str($b['note'] ?? '', 4000);
            $co = one('SELECT id,stage FROM companies WHERE LOWER(name)=LOWER(?) LIMIT 1', [$name]); $new = false;
            if (!$co) { $website = preg_match('~linkedin|facebook|x\.com|twitter|instagram|youtube~', (string)$sg['domain']) ? null : $sg['domain'];
                q("INSERT INTO companies (name,website,stage,priority,owner_id,notes,created_by) VALUES (?,?,'researching',?,?,?,?)", [$name, $website, (float)$sg['score'] >= 8 ? 'high' : 'medium', $uid, "Market signal (" . (PR_TYPES[$sg['type']]['label'] ?? $sg['type']) . "): " . $sg['title'] . "\n" . $sg['url'] . ($note !== '' ? "\n\n" . $note : ''), $uid]); $co = ['id' => (int)db()->lastInsertId(), 'stage' => 'researching']; $new = true; }
            else q('UPDATE companies SET notes=CONCAT(COALESCE(notes,\'\'), ?) WHERE id=?', ["\n\nMarket signal (" . (PR_TYPES[$sg['type']]['label'] ?? $sg['type']) . '): ' . $sg['title'] . "\n" . $sg['url'] . ($note !== '' ? "\n" . $note : ''), $co['id']]);
            $cid = null; $cn = pr_str($b['contact_name'] ?? '', 160); $ce = filter_var(trim((string)($b['contact_email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
            if ($cn !== '' || $ce !== '') { $ex = $ce !== '' ? one('SELECT id FROM contacts WHERE LOWER(email)=LOWER(?) LIMIT 1', [$ce]) : null; if ($ex) $cid = (int)$ex['id']; else { q('INSERT INTO contacts (company_id,name,email,position,owner_id) VALUES (?,?,?,?,?)', [$co['id'], $cn !== '' ? $cn : ucwords(str_replace(['.', '_', '-'], ' ', explode('@', $ce)[0])), $ce ?: null, pr_str($b['contact_position'] ?? '', 160) ?: null, $uid]); $cid = (int)db()->lastInsertId(); } }
            $mk = one("SELECT id,head_id FROM departments WHERE name='Marketing' LIMIT 1"); $asg = !empty($b['assignee_id']) ? (int)$b['assignee_id'] : ($mk && $mk['head_id'] ? (int)$mk['head_id'] : $uid);
            q("INSERT INTO tasks (title,description,status,priority,assignee_id,creator_id,department_id,due_date,company_id,contact_id,task_type,source) VALUES (?,?,'todo',?,?,?,?,?,?,?,'research','prospecting')", [mb_substr('Research & reach out: ' . $name, 0, 200), 'Why now: ' . $sg['title'] . "\n" . $sg['url'] . "\n\n" . $sg['snippet'] . ($note !== '' ? "\n\n" . $note : ''), (float)$sg['score'] >= 8 ? 'high' : 'medium', $asg, $uid, $mk['id'] ?? null, date('Y-m-d', strtotime('+3 days')), $co['id'], $cid]);
            $tid = (int)db()->lastInsertId(); if ($asg !== $uid) notify($asg, 'task_assigned', 'New lead to research: ' . $name, mb_substr($sg['title'], 0, 140), '#/tasks/' . $tid);
            $lid = (int)($b['list_id'] ?? 0); $added = false; if ($lid && $ce !== '') { try { if (!one('SELECT id FROM camp_contacts WHERE list_id=? AND LOWER(email)=LOWER(?)', [$lid, $ce])) { q("INSERT INTO camp_contacts (list_id,email,first_name,company,token,source) VALUES (?,?,?,?,?,'prospecting')", [$lid, $ce, explode(' ', $cn)[0] ?: null, $name, bin2hex(random_bytes(16))]); $added = true; } } catch (Throwable $e) { error_log('Prospecting list add: ' . $e->getMessage()); } }
            q("UPDATE pr_signals SET status='lead', company_id=?, task_id=? WHERE id=?", [$co['id'], $tid, $sg['id']]); log_act($uid, 'prospect_lead', $name);
            out(['ok' => true, 'company_id' => (int)$co['id'], 'new_company' => $new, 'task_id' => $tid, 'contact_id' => $cid, 'added_to_list' => $added]);
        }
        fail('Bad request', 404);
    }
    fail('Not found', 404);
}
