<?php
declare(strict_types=1);
// Executive dashboard: shows ANONYMOUS, AGGREGATE numbers (such as number of users and earnings) from other Aerosub apps (for example Insight),
// per organization and combined, to the people the Root Admin allows. Only numbers and organization names are ever stored: no names, e-mail addresses,
// phone numbers or other personal details of the people using those apps (fields that look personal are refused, and organization names that look like an
// e-mail or phone number are masked).
//   PULL  the server asks the other app's JSON API on a schedule (API key kept encrypted here, never sent to a browser).
//   PUSH  the other app posts its numbers to  POST /api/exec/ingest  with the source's key.
// Routes (all JSON):
//   GET  exec                       dashboard (needs the Executive tab); also says whether you can manage sources
//   POST exec/refresh               refresh pull sources now ({id} for one)
//   GET  exec/sources               configuration (Root Admin / Super Admin)
//   POST exec/sources               add a source          PATCH|DELETE exec/sources/{id}
//   POST exec/sources/{id}/key      make a new push key   POST exec/sources/{id}/test {endpoint}   try a request and show the JSON
//   POST exec/sources/{id}/metrics  add a metric          PATCH|DELETE exec/metrics/{mid}
//   POST exec/ingest                push numbers (X-Ingest-Key header)
//        {"totals": {"users": 1234}, "organizations": {"Acme Ltd": {"users": 120, "earnings": 5600.5}}, "at": "optional ISO time"}   (a list of {"name": "Acme Ltd", "users": 120} works too)

require_once __DIR__ . '/mail.php';
function ex_manage(array $u): bool { return is_root($u) || ($u['role'] ?? '') === 'super_admin'; }
function ex_slug(string $s): string { $s = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($s))); return trim($s, '-') ?: 'source'; }
function ex_key(string $s): string { $s = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', trim($s))); return trim(substr($s, 0, 60), '_'); }
/** Reads a value out of decoded JSON using a path such as data.totals.users or items[0].amount. */
function ex_path($data, string $path) {
    $path = trim($path); if ($path === '' || $path === '$') return $data;
    $path = preg_replace('/\[(\d+)\]/', '.$1', ltrim($path, '$.'));
    foreach (explode('.', $path) as $k) { if ($k === '') continue; if (is_array($data) && array_key_exists($k, $data)) $data = $data[$k]; else return null; }
    return $data;
}
function ex_num($v): ?float {
    if (is_bool($v) || $v === null || is_array($v)) return null; if (is_int($v) || is_float($v)) return (float)$v;
    $s = preg_replace('/[^0-9.\-eE]/', '', str_replace(',', '', (string)$v)); return ($s !== '' && is_numeric($s)) ? (float)$s : null;
}
function ex_secret(string $plain): string { return mail_encrypt($plain); }
function ex_unsecret(?string $blob): string { if (!$blob) return ''; try { return mail_decrypt($blob); } catch (Throwable $e) { return ''; } }
/** Safe outbound GET: https only, public addresses only, address pinned, small answer, short wait. @return array{0:int,1:string} [http status, body] */
function ex_http(string $url, array $headers): array {
    $p = parse_url($url); $scheme = strtolower($p['scheme'] ?? ''); $host = $p['host'] ?? '';
    if (!$host || !in_array($scheme, ['https', 'http'], true)) throw new RuntimeException('The address must start with https://');
    if ($scheme === 'http' && !cfg('exec_allow_http')) throw new RuntimeException('The address must start with https://');
    $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80)); $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : (string)gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('That address could not be found');
    if (!cfg('exec_allow_private') && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('That address is not allowed (private network)');
    $ch = curl_init($url); $buf = '';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_NOSIGNAL => 1, CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip],
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'User-Agent: AerosubOffice/1'], $headers), CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (cfg('exec_allow_http') ? CURLPROTO_HTTP : 0),
        CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$buf) { $buf .= $d; return strlen($buf) > 1048576 ? 0 : strlen($d); }]);
    $ok = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($ok === false && $code === 0) throw new RuntimeException($err !== '' ? 'Could not connect: ' . mb_substr($err, 0, 120) : 'Could not connect');
    return [$code, $buf];
}
function ex_headers(array $s): array { $v = ex_unsecret($s['auth_value'] ?? ''); $h = trim((string)($s['auth_header'] ?? '')); return ($v !== '' && $h !== '') ? [$h . ': ' . $v] : []; }
function ex_personal(string $t): bool { return (bool)preg_match('/e-?mail|phone|mobile|msisdn|password|passwd|secret|token|address|\bssn\b|birth|\bdob\b|surname|first_?name|last_?name|full_?name|user_?name|customer_?name|card|iban|account_?n|salary|bvn|nin\b/i', $t); }
/** Organization names are shown on the dashboard; one that looks like an e-mail address or phone number is masked. */
function ex_org_clean($v): string { $s = trim((string)preg_replace('/\s+/', ' ', is_array($v) ? '' : (string)$v)); if ($s === '' ) return 'Unnamed organization'; if (strpos($s, '@') !== false || preg_match('/\d[\d\s\-+().]{6,}\d/', $s)) return 'Unnamed organization'; return mb_substr($s, 0, 120); }
function ex_record(int $mid, string $org, ?float $val, ?string $at = null): void {
    if ($val === null) return; $at = $at ?: date('Y-m-d H:i:s');
    $last = one('SELECT value,at FROM exec_points WHERE metric_id=? AND org=? ORDER BY at DESC,id DESC LIMIT 1', [$mid, $org]);
    if ($last && abs((float)$last['value'] - $val) < 1e-9 && strtotime((string)$last['at']) > time() - 6 * 3600) { q('UPDATE exec_points SET at=? WHERE metric_id=? AND org=? AND at=?', [$at, $mid, $org, $last['at']]); return; }
    q('INSERT INTO exec_points (metric_id,org,value,at) VALUES (?,?,?,?)', [$mid, $org, $val, $at]);
    if (mt_rand(1, 40) === 1) q('DELETE FROM exec_points WHERE metric_id=? AND at < (NOW() - INTERVAL 800 DAY)', [$mid]);
}
/** Fetches a pull source now. Never throws. @return string '' on success, otherwise the reason */
function ex_pull(array $s): string {
    $err = '';
    try {
        $ms = all('SELECT * FROM exec_metrics WHERE source_id=? AND endpoint IS NOT NULL AND endpoint<>\'\'', [$s['id']]); if (!$ms) throw new RuntimeException('No numbers with an address yet');
        $base = rtrim((string)$s['base_url'], '/'); $cache = []; $got = 0;
        foreach ($ms as $m) {
            $ep = (string)$m['endpoint']; if (!isset($cache[$ep])) {
                $url = preg_match('~^https?://~i', $ep) ? $ep : $base . '/' . ltrim($ep, '/');
                if (preg_match('~^https?://~i', $ep) && parse_url($ep, PHP_URL_HOST) !== parse_url($base, PHP_URL_HOST)) { $cache[$ep] = ['err' => 'An address must stay on the source\'s own site']; continue; }
                try { [$code, $body] = ex_http($url, ex_headers($s)); $j = json_decode($body, true); $cache[$ep] = $code >= 200 && $code < 300 ? (is_array($j) ? ['j' => $j] : ['err' => 'The answer was not JSON']) : ['err' => 'The other app answered ' . $code . ($code === 401 || $code === 403 ? ' (check the API key)' : '')]; }
                catch (RuntimeException $e) { $cache[$ep] = ['err' => $e->getMessage()]; }
            }
            if (isset($cache[$ep]['err'])) { $err = $err ?: $cache[$ep]['err']; continue; }
            $j = $cache[$ep]['j'];
            if ((string)$m['list_path'] !== '') {      // one entry per organization
                $list = ex_path($j, (string)$m['list_path']); if (!is_array($list)) { $err = $err ?: '“' . $m['label'] . '”: no list of organizations at ' . $m['list_path']; continue; }
                $of = trim((string)$m['org_path']); $n0 = 0;
                foreach ($list as $k => $item) { if (!is_array($item)) continue; if (strtolower((string)($item['org_status'] ?? '')) === 'archived') continue;   // archived organisations are not current
                    $org = ex_org_clean($of !== '' ? ex_path($item, $of) : (is_string($k) ? $k : ''));
                    $n = ex_num(ex_path($item, (string)$m['path'])); if ($n !== null) { ex_record((int)$m['id'], $org, $n); $n0++; } }
                if ($n0) $got++; else $err = $err ?: '“' . $m['label'] . '”: no numbers found for the organizations';
            } else {                                     // one number for the whole app
                $n = ex_num(ex_path($j, (string)$m['path'])); if ($n !== null) { ex_record((int)$m['id'], '', $n); $got++; } else $err = $err ?: '“' . $m['label'] . '”: no number found at ' . ($m['path'] ?: '(empty path)');
            }
        }
        if ($got === 0 && $err === '') $err = 'Nothing was read';
    } catch (Throwable $e) { $err = $e->getMessage(); }
    q('UPDATE exec_sources SET last_fetch=NOW(), last_status=? WHERE id=?', [$err === '' ? 'ok' : mb_substr($err, 0, 190), $s['id']]);
    return $err;
}
function ex_refresh_due(bool $force = false, ?int $only = null): int {
    $n = 0; foreach (all("SELECT * FROM exec_sources WHERE active=1 AND mode='pull'" . ($only ? ' AND id=' . (int)$only : '') . ' ORDER BY id') as $s) {
        if (!$force && $s['last_fetch'] && strtotime((string)$s['last_fetch']) > time() - max(5, (int)$s['refresh_min']) * 60) continue; ex_pull($s); if (++$n >= 4) break; }
    return $n;
}
const EX_DAYS = 30;
function ex_day_list(): array { $o = []; for ($i = EX_DAYS - 1; $i >= 0; $i--) $o[] = date('Y-m-d', strtotime("-$i day")); return $o; }
/** Daily values carried forward from the last known one (null before the first report). */
function ex_ffill(array $byDay, array $days, ?float $before): array { $out = []; $cur = $before; foreach ($days as $d) { if (array_key_exists($d, $byDay)) $cur = $byDay[$d]; $out[] = $cur; } return $out; }
function ex_combine(array $lists, string $agg): array {
    $n = count($lists[0] ?? []); $out = []; for ($i = 0; $i < $n; $i++) { $v = []; foreach ($lists as $l) if ($l[$i] !== null) $v[] = $l[$i]; if (!$v) { $out[] = null; continue; }
        $out[] = $agg === 'avg' ? array_sum($v) / count($v) : ($agg === 'max' ? max($v) : array_sum($v)); }
    return $out;
}
function ex_trend(array $series): array {
    $last = null; foreach ($series as $i => $v) if ($v !== null) $last = $i; if ($last === null) return [null, null];
    $prev = null; $j = $last - 7; if ($j >= 0 && $series[$j] !== null) $prev = $series[$j]; else { foreach ($series as $i => $v) if ($v !== null && $i < $last) { $prev = $v; break; } }
    return [$series[$last], $prev];
}
function ex_dash_source(array $s, array $days): array {
    $ms = all('SELECT * FROM exec_metrics WHERE source_id=? ORDER BY position,id', [$s['id']]); $out = []; $since = $days[0];
    foreach ($ms as $m) {
        $mid = (int)$m['id'];
        $orgs = []; foreach (all('SELECT org, SUBSTRING_INDEX(GROUP_CONCAT(value ORDER BY at DESC, id DESC SEPARATOR \'|\'), \'|\', 1) v, MAX(at) at FROM exec_points WHERE metric_id=? GROUP BY org', [$mid]) as $r) $orgs[$r['org']] = ['v' => (float)$r['v'], 'at' => $r['at']];
        $daily = []; foreach (all('SELECT org, DATE(at) d, SUBSTRING_INDEX(GROUP_CONCAT(value ORDER BY at DESC, id DESC SEPARATOR \'|\'), \'|\', 1) v FROM exec_points WHERE metric_id=? AND DATE(at) >= ? GROUP BY org, DATE(at)', [$mid, $since]) as $r) $daily[$r['org']][$r['d']] = (float)$r['v'];
        $base = []; foreach (all('SELECT org, SUBSTRING_INDEX(GROUP_CONCAT(value ORDER BY at DESC, id DESC SEPARATOR \'|\'), \'|\', 1) v FROM exec_points WHERE metric_id=? AND DATE(at) < ? GROUP BY org', [$mid, $since]) as $r) $base[$r['org']] = (float)$r['v'];
        $rows = []; $lists = []; foreach ($orgs as $org => $o) { $sr = ex_ffill($daily[$org] ?? [], $days, $base[$org] ?? null); [$val, $prev] = ex_trend($sr); $latest = $o['v'];
            if ($org !== '') { $lists[] = $sr; $rows[] = ['org' => $org, 'value' => $latest, 'prev' => $prev, 'series' => $sr]; } }
        usort($rows, fn($a, $b) => $b['value'] <=> $a['value']);
        $own = array_key_exists('', $orgs) ? ex_ffill($daily[''] ?? [], $days, $base[''] ?? null) : null; $derived = false;
        if ($own !== null) { $tot = $own; $tv = $orgs['']['v']; } elseif ($lists) { $derived = true; $tot = ex_combine($lists, $m['agg']); $vals = array_column($rows, 'value'); $tv = $m['agg'] === 'avg' ? array_sum($vals) / count($vals) : ($m['agg'] === 'max' ? max($vals) : array_sum($vals)); } else { $tot = array_fill(0, count($days), null); $tv = null; }
        [, $tp] = ex_trend($tot); $at = null; foreach ($orgs as $o) if ($at === null || $o['at'] > $at) $at = $o['at'];
        $out[] = ['id' => $mid, 'key' => $m['mkey'], 'label' => $m['label'], 'kind' => $m['kind'], 'currency' => $m['currency'], 'good' => $m['good'], 'agg' => $m['agg'], 'value' => $tv, 'prev' => $tp, 'series' => $tot, 'derived' => $derived, 'orgs' => $rows, 'at' => $at];
    }
    return ['id' => (int)$s['id'], 'name' => $s['name'], 'mode' => $s['mode'], 'last_fetch' => $s['last_fetch'], 'status' => $s['last_status'], 'metrics' => $out];
}
/** All apps together: the same kind of number (same key, kind and currency) added up across apps. */
function ex_overview(array $sources, array $days): array {
    $g = []; foreach ($sources as $s) foreach ($s['metrics'] as $m) { if ($m['value'] === null) continue; $k = $m['key'] . '|' . $m['kind'] . '|' . $m['currency']; $g[$k]['m'] = $g[$k]['m'] ?? $m; $g[$k]['list'][] = $m; $g[$k]['apps'][$s['id']] = 1; }
    $out = []; foreach ($g as $x) { $m = $x['m']; $agg = $m['agg']; $series = ex_combine(array_map(fn($y) => $y['series'], $x['list']), $agg); [$v, $p] = ex_trend($series); $orgNames = []; foreach ($x['list'] as $y) foreach ($y['orgs'] as $o) $orgNames[$o['org']] = 1;
        $out[] = ['key' => $m['key'], 'label' => $m['label'], 'kind' => $m['kind'], 'currency' => $m['currency'], 'good' => $m['good'], 'value' => $v, 'prev' => $p, 'series' => $series, 'apps' => count($x['apps']), 'orgs' => count($orgNames)]; }
    return $out;
}
function ex_cfg_source(array $s): array {
    $ms = all('SELECT id,mkey,label,kind,currency,endpoint,list_path,org_path,path,agg,good,position FROM exec_metrics WHERE source_id=? ORDER BY position,id', [$s['id']]);
    return ['id' => (int)$s['id'], 'name' => $s['name'], 'mode' => $s['mode'], 'base_url' => $s['base_url'], 'auth_header' => $s['auth_header'], 'has_token' => (bool)$s['auth_value'], 'key_hint' => $s['ingest_hint'], 'refresh_min' => (int)$s['refresh_min'], 'active' => (int)$s['active'], 'last_fetch' => $s['last_fetch'], 'status' => $s['last_status'], 'metrics' => $ms];
}
function ex_metric_in(array $b, ?array $cur = null): array {
    $g = fn($k, $d = '') => array_key_exists($k, $b) ? $b[$k] : ($cur[$k] ?? $d);
    $label = s($g('label'), 80); if ($label === '') fail('Give the number a name');
    $kind = in_array($g('kind', 'number'), ['number', 'money', 'percent'], true) ? $g('kind', 'number') : 'number';
    $cu = strtoupper(s($g('currency'), 8)); if ($kind === 'money' && !preg_match('/^[A-Z]{3}$/', $cu)) $cu = 'NGN'; if ($kind !== 'money') $cu = '';
    $ep = s($g('endpoint'), 300); $lp = s($g('list_path'), 200); $op = s($g('org_path'), 100); $path = s($g('path'), 200);
    foreach ([$label, $path, $lp, $op] as $t) if ($t !== '' && ex_personal($t)) fail('This dashboard shows totals only, so a field that looks personal (“' . mb_substr($t, 0, 40) . '”) cannot be added.');
    $agg = in_array($g('agg', 'sum'), ['sum', 'avg', 'max'], true) ? $g('agg', 'sum') : 'sum'; if ($kind === 'percent' && !array_key_exists('agg', $b) && !$cur) $agg = 'avg';
    return ['label' => $label, 'kind' => $kind, 'currency' => $cu ?: null, 'endpoint' => $ep ?: null, 'list_path' => $lp ?: null, 'org_path' => $op ?: null, 'path' => $path ?: null, 'agg' => $agg, 'good' => $g('good', 'up') === 'down' ? 'down' : 'up'];
}
function ex_new_key(): array { $k = 'ak_' . bin2hex(random_bytes(20)); return [$k, hash('sha256', $k), substr($k, 0, 7) . '…' . substr($k, -4)]; }

/* ---------------- routes ---------------- */
if (isset($seg[0]) && $seg[0] === 'exec' && isset($method)) {
    $a = $seg[1] ?? ''; $x = $seg[2] ?? ''; $y = $seg[3] ?? '';
    // push: the other app posts its numbers (no sign-in; the source's key is the credential)
    if ($a === 'ingest') {
        if ($method !== 'POST') fail('POST only', 405);
        $k = trim((string)($_SERVER['HTTP_X_INGEST_KEY'] ?? '')); if ($k === '' && preg_match('/^Bearer\s+(\S+)/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $mm)) $k = $mm[1];
        $src = $k !== '' ? one("SELECT * FROM exec_sources WHERE mode='push' AND active=1 AND ingest_hash=?", [hash('sha256', $k)]) : null; if (!$src) { usleep(400000); fail('Invalid key', 401); }
        $b = body(); $tot = is_array($b['totals'] ?? null) ? $b['totals'] : (is_array($b['metrics'] ?? null) ? $b['metrics'] : []); $orgs = $b['organizations'] ?? $b['organisations'] ?? [];
        if (!$tot && !$orgs) fail('Send {"totals": {"users": 1234}, "organizations": {"Acme": {"users": 120}}}');
        $at = isset($b['at']) && ($t = strtotime((string)$b['at'])) ? date('Y-m-d H:i:s', min($t, time())) : null; $n = 0; $skipped = 0; $rows = [];
        foreach (array_slice($tot, 0, 100, true) as $name => $val) $rows[] = ['', (string)$name, $val];
        if (is_array($orgs)) { $isList = array_is_list($orgs); foreach (array_slice($orgs, 0, 500, true) as $k => $o) { if (!is_array($o)) continue; $oname = $isList ? ($o['name'] ?? $o['organization'] ?? $o['organisation'] ?? '') : $k; $oname = ex_org_clean($oname);
            foreach (array_slice($o, 0, 40, true) as $name => $val) { if ($isList && in_array($name, ['name', 'organization', 'organisation'], true)) continue; $rows[] = [$oname, (string)$name, $val]; } } }
        foreach ($rows as [$oname, $name, $val]) {
            $mk = ex_key($name); if ($mk === '' || ex_personal($mk) || ($f = ex_num($val)) === null) { $skipped++; continue; } $m = one('SELECT * FROM exec_metrics WHERE source_id=? AND mkey=?', [$src['id'], $mk]);
            if (!$m) { if ((int)one('SELECT COUNT(*) c FROM exec_metrics WHERE source_id=?', [$src['id']])['c'] >= 60) { $skipped++; continue; } q('INSERT INTO exec_metrics (source_id,mkey,label,kind,agg,position) VALUES (?,?,?,?,?,?)', [$src['id'], $mk, ucfirst(str_replace('_', ' ', $mk)), 'number', 'sum', 900]); $m = one('SELECT * FROM exec_metrics WHERE source_id=? AND mkey=?', [$src['id'], $mk]); }
            ex_record((int)$m['id'], $oname, $f, $at); $n++;
        }
        q("UPDATE exec_sources SET last_fetch=NOW(), last_status='ok' WHERE id=?", [$src['id']]); out(['ok' => true, 'stored' => $n, 'skipped' => $skipped]);
    }
    $u = need_user(); $manage = ex_manage($u); $view = tab_access($u, 'executive');
    if ($a === '' && $method === 'GET') {
        if (!$view && !$manage) fail('You do not have access to this section', 403);
        $srcs = []; $days = ex_day_list(); if ($view) { ex_refresh_due(); foreach (all('SELECT * FROM exec_sources WHERE active=1 ORDER BY position,id') as $s) $srcs[] = ex_dash_source($s, $days); }
        out(['can_view' => $view, 'can_manage' => $manage, 'days' => $days, 'sources' => $srcs, 'overview' => $srcs ? ex_overview($srcs, $days) : [], 'now' => date('Y-m-d H:i:s'), 'push_url' => rtrim((string)cfg('app_url'), '/') . '/api/exec/ingest']);
    }
    if ($a === 'refresh' && $method === 'POST') { if (!$view) fail('You do not have access to this section', 403); $b = body(); $n = ex_refresh_due(true, isset($b['id']) ? (int)$b['id'] : null); out(['ok' => true, 'refreshed' => $n]); }
    if (!$manage) fail('Only the Root Admin or a Super Admin can set up sources', 403);
    if ($a === 'sources') {
        if ($x === '' && $method === 'GET') out(['sources' => array_map('ex_cfg_source', all('SELECT * FROM exec_sources ORDER BY position,id')), 'push_url' => rtrim((string)cfg('app_url'), '/') . '/api/exec/ingest']);
        if ($x === '' && $method === 'POST') {
            $b = body(); $name = s($b['name'] ?? '', 80); if ($name === '') fail('Give the source a name'); $mode = ($b['mode'] ?? 'pull') === 'push' ? 'push' : 'pull';
            $base = s($b['base_url'] ?? '', 300); if ($mode === 'pull') { if (!preg_match('~^https://[^\s/]+[^\s]*$~i', $base) && !(cfg('exec_allow_http') && preg_match('~^http://[^\s/]+~i', $base))) fail('The site address must start with https://'); } else $base = '';
            $hdr = s($b['auth_header'] ?? 'Authorization', 60); if (!preg_match('/^[A-Za-z][A-Za-z0-9\-]*$/', $hdr)) $hdr = 'Authorization'; $tok = trim((string)($b['token'] ?? ''));
            $slug = ex_slug($name); $i = 1; while (one('SELECT 1 FROM exec_sources WHERE slug=?', [$slug])) $slug = ex_slug($name) . '-' . (++$i);
            $key = null; $hash = null; $hint = null; if ($mode === 'push') [$key, $hash, $hint] = ex_new_key();
            q('INSERT INTO exec_sources (name,slug,mode,base_url,auth_header,auth_value,ingest_hash,ingest_hint,refresh_min,position) VALUES (?,?,?,?,?,?,?,?,?,?)',
              [$name, $slug, $mode, $base ?: null, $hdr, $tok !== '' ? ex_secret($tok) : null, $hash, $hint, max(5, min(1440, (int)($b['refresh_min'] ?? 30))), 100 + (int)one('SELECT COUNT(*) c FROM exec_sources')['c']]);
            $id = (int)db()->lastInsertId(); log_act((int)$u['id'], 'exec_source_added', $name); out(['id' => $id, 'key' => $key, 'source' => ex_cfg_source(one('SELECT * FROM exec_sources WHERE id=?', [$id]))], 201);
        }
        $id = (int)$x; $src = $id ? one('SELECT * FROM exec_sources WHERE id=?', [$id]) : null; if (!$src) fail('Source not found', 404);
        if ($y === '' && $method === 'PATCH') {
            $b = body(); $f = []; $p = [];
            if (isset($b['name'])) { $nm = s($b['name'], 80); if ($nm === '') fail('Give the source a name'); $f[] = 'name=?'; $p[] = $nm; }
            if (isset($b['base_url']) && $src['mode'] === 'pull') { $bu = s($b['base_url'], 300); if (!preg_match('~^https://[^\s/]+[^\s]*$~i', $bu) && !(cfg('exec_allow_http') && preg_match('~^http://~i', $bu))) fail('The site address must start with https://'); $f[] = 'base_url=?'; $p[] = $bu; }
            if (isset($b['auth_header'])) { $hh = s($b['auth_header'], 60); if (preg_match('/^[A-Za-z][A-Za-z0-9\-]*$/', $hh)) { $f[] = 'auth_header=?'; $p[] = $hh; } }
            if (isset($b['token']) && trim((string)$b['token']) !== '') { $f[] = 'auth_value=?'; $p[] = ex_secret(trim((string)$b['token'])); } if (!empty($b['clear_token'])) $f[] = 'auth_value=NULL';
            if (isset($b['refresh_min'])) { $f[] = 'refresh_min=?'; $p[] = max(5, min(1440, (int)$b['refresh_min'])); } if (isset($b['active'])) { $f[] = 'active=?'; $p[] = $b['active'] ? 1 : 0; }
            if ($f) { $p[] = $id; q('UPDATE exec_sources SET ' . implode(',', $f) . ' WHERE id=?', $p); } log_act((int)$u['id'], 'exec_source_changed', $src['name']); out(['ok' => true, 'source' => ex_cfg_source(one('SELECT * FROM exec_sources WHERE id=?', [$id]))]);
        }
        if ($y === '' && $method === 'DELETE') { q('DELETE FROM exec_sources WHERE id=?', [$id]); log_act((int)$u['id'], 'exec_source_removed', $src['name']); out(['ok' => true]); }
        if ($y === 'key' && $method === 'POST') { if ($src['mode'] !== 'push') fail('Only push sources have a key'); [$key, $hash, $hint] = ex_new_key(); q('UPDATE exec_sources SET ingest_hash=?, ingest_hint=? WHERE id=?', [$hash, $hint, $id]); log_act((int)$u['id'], 'exec_key_renewed', $src['name']); out(['key' => $key, 'hint' => $hint]); }
        if ($y === 'test' && $method === 'POST') {
            if ($src['mode'] !== 'pull') fail('Only pull sources can be tested'); $b = body(); $ep = s($b['endpoint'] ?? '', 300); $base = rtrim((string)$src['base_url'], '/');
            $url = preg_match('~^https?://~i', $ep) ? $ep : $base . '/' . ltrim($ep, '/'); if (preg_match('~^https?://~i', $ep) && parse_url($ep, PHP_URL_HOST) !== parse_url($base, PHP_URL_HOST)) fail('An address must stay on the source\'s own site');
            try { [$code, $bodyTxt] = ex_http($url, ex_headers($src)); } catch (RuntimeException $e) { out(['ok' => false, 'error' => $e->getMessage()]); }
            $j = json_decode($bodyTxt, true); $path = s($b['path'] ?? '', 200); $lp = s($b['list_path'] ?? '', 200); $paths = []; $shape = [];
            // Only field names and numbers are shown (never the raw answer), and fields that look personal are left out.
            $walk = function ($d, $pre, $depth) use (&$walk, &$paths, &$shape) { if ($depth > 4 || count($paths) > 60) return; foreach ((array)$d as $k => $v) { if (is_string($k) && ex_personal($k)) continue; $p = $pre === '' ? (string)$k : $pre . '.' . $k;
                if (is_array($v)) { if (array_is_list($v)) { if ($v && is_array($v[0])) { $shape[] = $p . ' (list of ' . count($v) . ')'; $walk($v[0], $p . '.0', $depth + 1); } } else $walk($v, $p, $depth + 1); } elseif (is_int($v) || is_float($v) || (is_string($v) && ex_num($v) !== null && !preg_match('/\d[\d\s\-+().]{8,}/', $v))) $paths[] = $p . '  =  ' . (is_string($v) ? $v : json_encode($v)); } };
            if (is_array($j)) $walk($j, '', 0); $val = null; $orgs = null;
            if (is_array($j) && $lp !== '') { $list = ex_path($j, $lp); if (is_array($list)) { $orgs = 0; foreach ($list as $it) if (is_array($it)) $orgs++; if ($path !== '' && ($first = reset($list)) && is_array($first)) $val = ex_path($first, $path); } }
            elseif (is_array($j) && $path !== '') $val = ex_path($j, $path); if (is_array($val) || (is_string($val) && !is_numeric(str_replace(',', '', $val)))) $val = null;
            out(['ok' => $code >= 200 && $code < 300, 'status' => $code, 'json' => is_array($j), 'numbers' => $paths, 'lists' => $shape, 'value' => $val, 'organizations_found' => $orgs]);
        }
        if ($y === 'metrics' && $method === 'POST') {
            $b = body(); $m = ex_metric_in($b); $mk = ex_key((string)($b['key'] ?? $m['label'])) ?: 'metric'; $i = 1; $base = $mk; while (one('SELECT 1 FROM exec_metrics WHERE source_id=? AND mkey=?', [$id, $mk])) $mk = $base . '_' . (++$i);
            q('INSERT INTO exec_metrics (source_id,mkey,label,kind,currency,endpoint,list_path,org_path,path,agg,good,position) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$id, $mk, $m['label'], $m['kind'], $m['currency'], $m['endpoint'], $m['list_path'], $m['org_path'], $m['path'], $m['agg'], $m['good'], (int)one('SELECT COUNT(*) c FROM exec_metrics WHERE source_id=?', [$id])['c']]);
            out(['id' => (int)db()->lastInsertId(), 'key' => $mk], 201);
        }
    }
    if ($a === 'metrics' && (int)$x) {
        $mid = (int)$x; $cur = one('SELECT m.*,s.name sname FROM exec_metrics m JOIN exec_sources s ON s.id=m.source_id WHERE m.id=?', [$mid]); if (!$cur) fail('Not found', 404);
        if ($method === 'PATCH') { $b = body(); $m = ex_metric_in($b, $cur); q('UPDATE exec_metrics SET label=?,kind=?,currency=?,endpoint=?,list_path=?,org_path=?,path=?,agg=?,good=? WHERE id=?', [$m['label'], $m['kind'], $m['currency'], $m['endpoint'], $m['list_path'], $m['org_path'], $m['path'], $m['agg'], $m['good'], $mid]); out(['ok' => true]); }
        if ($method === 'DELETE') { q('DELETE FROM exec_metrics WHERE id=?', [$mid]); out(['ok' => true]); }
    }
    fail('Bad request', 404);
}
