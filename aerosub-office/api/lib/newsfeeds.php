<?php
declare(strict_types=1);
// News feeds: subscribe to outside RSS / Atom feeds, keep only what matches the keywords, and keep the list moving (old items expire, each feed keeps only its newest few).
// Functions only (used by the News routes in finance.php and by cron.php).

function nf_ensure(): void {
    static $done = false; if ($done) return; $done = true;
    try { if (!one("SHOW TABLES LIKE 'news_feeds'") || !one("SHOW TABLES LIKE 'news_items'")) { require_once __DIR__ . '/migrate_fin3.php'; migrate_fin_ext(); } } catch (Throwable $e) { error_log('News feeds schema: ' . $e->getMessage()); }
}
/** Public addresses only: http(s), a real host name, and nothing that points inside our own network. Returns '' when fine, else the reason. */
function nf_url_check(string $url): string {
    $p = parse_url($url); if (!$p || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host'])) return 'The feed address must start with https:// (or http://)';
    if (isset($p['user']) || isset($p['pass'])) return 'Remove the user name and password from the address';
    if (cfg('feeds_allow_private') || cfg('mail_allow_private')) return '';
    if (isset($p['port']) && !in_array((int)$p['port'], [80, 443, 8080, 8443], true)) return 'That port is not supported';
    $host = (string)$p['host']; $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return 'Could not find the server “' . mb_substr($host, 0, 80) . '”. Check the spelling.';
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return 'That address is not allowed';
    return '';
}
/** [body, error]. Follows up to 3 redirects, checking every hop; max 2 MB. */
function nf_get(string $url): array {
    for ($hop = 0; $hop < 4; $hop++) {
        if ($e = nf_url_check($url)) return ['', $e];
        $buf = ''; $ch = curl_init($url); $loc = '';
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_USERAGENT => 'AerosubOffice/1.0 (news feed reader)', CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.5'], CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$buf) { $buf .= $d; return strlen($buf) > 2097152 ? 0 : strlen($d); },
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$loc) { if (stripos($h, 'location:') === 0) $loc = trim(substr($h, 9)); return strlen($h); }]);
        curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($code >= 300 && $code < 400 && $loc !== '') { $url = preg_match('~^https?://~i', $loc) ? $loc : (preg_replace('~^(https?://[^/]+).*$~i', '$1', $url) . '/' . ltrim($loc, '/')); continue; }
        if ($code >= 400) return ['', 'The site answered ' . $code]; if ($buf === '') return ['', $err !== '' ? 'Could not read the feed (' . mb_substr($err, 0, 100) . ')' : 'The feed was empty'];
        return [$buf, ''];
    }
    return ['', 'Too many redirects'];
}
function nf_text(string $h, int $n): string { $t = html_entity_decode(strip_tags(preg_replace('~<(script|style)[^>]*>.*?</\1>~is', ' ', $h) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'); $t = trim(preg_replace('/\s+/u', ' ', $t) ?? ''); return mb_strlen($t) > $n ? rtrim(mb_substr($t, 0, $n - 1)) . '…' : $t; }
/** RSS 2.0, RSS 1.0 (RDF) and Atom -> [['title','summary','link','date','guid'], …] newest first */
function nf_parse(string $xml): array {
    if (preg_match('/<!DOCTYPE[^>]*\[/i', $xml)) return [];   // no entity tricks
    $prev = libxml_use_internal_errors(true); $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA); libxml_use_internal_errors($prev); if (!$x) return [];
    $ns = $x->getNamespaces(true); $out = [];
    $nodes = []; if (isset($x->channel->item)) foreach ($x->channel->item as $i) $nodes[] = $i; elseif (isset($x->item)) foreach ($x->item as $i) $nodes[] = $i; elseif (isset($x->entry)) foreach ($x->entry as $i) $nodes[] = $i;
    foreach (array_slice($nodes, 0, 100) as $i) {
        $title = nf_text((string)$i->title, 250); if ($title === '') continue; $link = '';
        if (isset($i->link)) { foreach ($i->link as $l) { $h = (string)($l['href'] ?? ''); $rel = (string)($l['rel'] ?? 'alternate'); if ($h !== '' && ($rel === 'alternate' || $link === '')) $link = $h; elseif ($h === '' && trim((string)$l) !== '' && $link === '') $link = trim((string)$l); } }
        $link = preg_match('~^https?://[^\s"<>]{4,480}$~i', $link) ? $link : '';
        $desc = (string)($i->description ?? $i->summary ?? ''); if ($desc === '' && isset($ns['content'])) $desc = (string)$i->children($ns['content'])->encoded; if ($desc === '') $desc = (string)($i->content ?? '');
        $d = (string)($i->pubDate ?? $i->published ?? $i->updated ?? ''); if ($d === '' && isset($ns['dc'])) $d = (string)$i->children($ns['dc'])->date; $ts = $d !== '' ? strtotime($d) : false; if (!$ts || $ts > time() + 86400) $ts = time();
        $guid = (string)($i->guid ?? $i->id ?? '') ?: ($link ?: $title);
        $out[] = ['title' => $title, 'summary' => nf_text($desc, 400), 'link' => $link, 'date' => date('Y-m-d H:i:s', $ts), 'guid' => $guid];
    }
    usort($out, fn($a, $b) => strcmp($b['date'], $a['date'])); return $out;
}
/** "drone, inspection, “oil and gas”" -> terms */
function nf_terms($s): array { $o = []; foreach (preg_split('/[,\n;]+/u', (string)$s) as $t) { $t = trim($t, " \t\"'“”"); if (mb_strlen($t) >= 2) $o[mb_strtolower($t)] = mb_substr($t, 0, 60); } return array_slice(array_values($o), 0, 40); }
/** null = leave it out, '' = keep (no keywords set), otherwise the keyword that matched */
function nf_match(array $it, array $inc, array $exc): ?string {
    $h = mb_strtolower($it['title'] . ' ' . $it['summary']);
    foreach ($exc as $t) if (str_contains($h, mb_strtolower($t))) return null;
    if (!$inc) return ''; $hit = [];
    foreach ($inc as $t) if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote(mb_strtolower($t), '/') . '/u', $h)) $hit[] = $t;
    return $hit ? implode(', ', array_slice($hit, 0, 4)) : null;
}
/** Look at a feed address without saving anything: what it is called and the first few items (after the keyword filter). */
function nf_preview(string $url, string $kw, string $ex): array {
    [$body, $err] = nf_get($url); if ($err) return ['error' => $err]; $items = nf_parse($body); if (!$items) return ['error' => 'That address does not look like an RSS or Atom feed'];
    $inc = nf_terms($kw); $exc = nf_terms($ex); $keep = []; foreach ($items as $it) { $m = nf_match($it, $inc, $exc); if ($m !== null) $keep[] = $it + ['matched' => $m]; }
    return ['total' => count($items), 'kept' => count($keep), 'sample' => array_slice($keep, 0, 5), 'title' => (function () use ($body) { $x = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA); return $x ? nf_text((string)($x->channel->title ?? $x->title ?? ''), 120) : ''; })()];
}
/** Fetch one feed, add the new matching items, then tidy: expired items go, and only the newest `max_items` stay. Returns [added, error]. */
function nf_refresh(array $f): array {
    [$body, $err] = nf_get((string)$f['url']); $id = (int)$f['id'];
    if ($err === '' && !($items = nf_parse($body))) $err = 'That address does not look like an RSS or Atom feed';
    if ($err !== '') { q('UPDATE news_feeds SET last_fetch=NOW(), last_error=? WHERE id=?', [mb_substr($err, 0, 250), $id]); return [0, $err]; }
    $inc = nf_terms($f['keywords']); $exc = nf_terms($f['exclude_words']); $keepDays = max(1, (int)$f['keep_days']); $max = max(1, (int)$f['max_items']); $added = 0; $kept = 0; $cut = date('Y-m-d H:i:s', time() - $keepDays * 86400);
    foreach ($items as $it) {
        if ($it['date'] < $cut) continue; $m = nf_match($it, $inc, $exc); if ($m === null) continue;
        if (++$kept > $max) break;   // newest first: older matches would only be tidied away again
        $r = q('INSERT IGNORE INTO news_items (feed_id,guid_hash,title,summary,link,published_at,matched) VALUES (?,?,?,?,?,?,?)', [$id, sha1($it['guid']), $it['title'], $it['summary'] ?: null, $it['link'] ?: null, $it['date'], $m !== '' ? $m : null]); $added += $r->rowCount();
    }
    q('DELETE FROM news_items WHERE feed_id=? AND published_at < ?', [$id, $cut]);   // expired
    $extra = all('SELECT id FROM news_items WHERE feed_id=? ORDER BY published_at DESC, id DESC LIMIT 1000 OFFSET ' . $max, [$id]); if ($extra) q('DELETE FROM news_items WHERE id IN (' . implode(',', array_map('intval', array_column($extra, 'id'))) . ')');   // only the newest few
    q('UPDATE news_feeds SET last_fetch=NOW(), last_error=NULL, last_added=? WHERE id=?', [$added, $id]); return [$added, ''];
}
/** Feeds that are due (not looked at for `every_min` minutes). `$limit` keeps one request short. */
function nf_run_due(int $limit = 6): array {
    nf_ensure(); $n = 0; $added = 0;
    foreach (all("SELECT * FROM news_feeds WHERE active=1 AND (last_fetch IS NULL OR last_fetch < (NOW() - INTERVAL every_min MINUTE)) ORDER BY last_fetch IS NULL DESC, last_fetch LIMIT " . (int)$limit) as $f) { [$a] = nf_refresh($f); $added += $a; $n++; }
    try { q('DELETE i FROM news_items i LEFT JOIN news_feeds f ON f.id=i.feed_id WHERE f.id IS NULL'); } catch (Throwable $e) {}
    return [$n, $added];
}
function nf_cron(): string { try { [$n, $a] = nf_run_due(30); return "News feeds: $n feed(s) read, $a new item(s)."; } catch (Throwable $e) { return 'News feeds skipped: ' . $e->getMessage(); } }
