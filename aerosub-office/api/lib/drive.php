<?php
declare(strict_types=1);
// Google Drive folders in My Documents, WITHOUT any Google Cloud / API setup.
// The person pastes the link of a folder that is shared as "Anyone with the link can view". We read the folder the same way a visitor's browser does
// (Google's public folder page) and download files from the public download addresses. Nothing is stored from Drive: only the folder's id and the name given to it.
// Only Google's own addresses are ever contacted, and only for ids that pass the pattern below.

function gd_base(): string { return rtrim((string)(cfg('gdrive_base') ?: 'https://drive.google.com'), '/'); }
function gd_id_ok(string $id): bool { return (bool)preg_match('/^[A-Za-z0-9_-]{10,100}$/', $id); }
/** A folder link in any of its usual forms -> the folder id. */
function gd_folder_id(string $url): ?string {
    $url = trim($url); if (gd_id_ok($url)) return $url;
    if (!preg_match('~^https://(drive|docs)\.google\.com/~i', $url)) return null;
    if (preg_match('~/folders/([A-Za-z0-9_-]{10,100})~', $url, $m)) return $m[1];
    if (preg_match('~[?&]id=([A-Za-z0-9_-]{10,100})~', $url, $m)) return $m[1];
    return null;
}
function gd_host_ok(string $url): bool {
    $p = parse_url($url); if (!$p || !in_array(strtolower((string)($p['scheme'] ?? '')), ['https', 'http'], true)) return false; $h = strtolower((string)($p['host'] ?? ''));
    if (cfg('feeds_allow_private') && preg_match('/^(127\.0\.0\.1|localhost)$/', $h)) return true;   // tests only
    if (strtolower((string)$p['scheme']) !== 'https') return false;
    return $h === 'drive.google.com' || $h === 'docs.google.com' || $h === 'drive.usercontent.google.com' || (bool)preg_match('/\.googleusercontent\.com$/', $h);
}
/** [code, contentType, body, error]. Up to 4 redirects, each checked; $max bytes. */
function gd_fetch(string $url, int $max = 26214400): array {
    for ($hop = 0; $hop < 5; $hop++) {
        if (!gd_host_ok($url)) return [0, '', '', 'That address is not allowed']; $buf = ''; $loc = ''; $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AerosubOffice/1.0)', CURLOPT_ENCODING => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$buf, $max) { $buf .= $d; return strlen($buf) > $max ? 0 : strlen($d); }, CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$loc) { if (stripos($h, 'location:') === 0) $loc = trim(substr($h, 9)); return strlen($h); }]);
        curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); $err = curl_error($ch); curl_close($ch);
        if ($code >= 300 && $code < 400 && $loc !== '') { $url = preg_match('~^https?://~i', $loc) ? $loc : (preg_replace('~^(https?://[^/]+).*$~i', '$1', $url) . '/' . ltrim($loc, '/')); continue; }
        if ($code === 0 && $err !== '') return [0, $ct, $buf, str_contains($err, 'Failed writing') ? 'That file is too large to fetch here (25 MB limit)' : 'Could not reach Google Drive (' . mb_substr($err, 0, 80) . ')'];
        return [$code, $ct, $buf, ''];
    }
    return [0, '', '', 'Too many redirects'];
}
function gd_kind(string $href, string $icon): string {
    if (str_contains($href, '/drive/folders/') || str_contains($icon, 'vnd.google-apps.folder')) return 'folder';
    if (str_contains($href, '/document/d/') || str_contains($icon, 'vnd.google-apps.document')) return 'doc';
    if (str_contains($href, '/spreadsheets/d/') || str_contains($icon, 'vnd.google-apps.spreadsheet')) return 'sheet';
    if (str_contains($href, '/presentation/d/') || str_contains($icon, 'vnd.google-apps.presentation')) return 'slide';
    return 'file';
}
/** What is in a shared folder: [{id,name,kind,type,modified}]. Throws RuntimeException with a message fit to show. */
function gd_list(string $folderId): array {
    if (!gd_id_ok($folderId)) throw new RuntimeException('That folder address is not valid');
    [$code, $ct, $html, $err] = gd_fetch(gd_base() . '/embeddedfolderview?id=' . $folderId . '#list', 5242880); if ($err) throw new RuntimeException($err);
    if ($code === 404 || $code === 403 || preg_match('~accounts\.google\.com/(ServiceLogin|v3/signin)|You need access|Request access~i', $html) && !str_contains($html, 'flip-entry')) throw new RuntimeException('Google Drive says this folder is private. In Drive, right-click the folder → Share → General access → “Anyone with the link” (Viewer), then try again.');
    if ($code >= 400 || $code === 0) throw new RuntimeException('Google Drive answered ' . $code . '. Try again in a moment.');
    $out = [];
    if (preg_match_all('~<div[^>]*class="[^"]*flip-entry[^"]*"[^>]*id="entry-([A-Za-z0-9_-]+)"(.*?)(?=<div[^>]*class="[^"]*flip-entry[ "]|$)~s', $html, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) { $id = $m[1]; $blk = $m[2]; $href = preg_match('~href="([^"]+)"~', $blk, $h) ? html_entity_decode($h[1]) : ''; $icon = preg_match('~<img[^>]+src="([^"]+)"~', $blk, $i) ? html_entity_decode($i[1]) : '';
            $name = preg_match('~class="flip-entry-title"[^>]*>(.*?)</~s', $blk, $n) ? trim(html_entity_decode(strip_tags($n[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : ''; if ($name === '') continue;
            $mod = preg_match('~flip-entry-last-modified[^>]*>\s*<div[^>]*>(.*?)</div>~s', $blk, $d) ? trim(html_entity_decode(strip_tags($d[1]))) : ''; $kind = gd_kind($href, $icon);
            $out[] = ['id' => $id, 'name' => mb_substr($name, 0, 200), 'kind' => $kind, 'modified' => mb_substr($mod, 0, 30)]; }
    }
    if (!$out && !preg_match('~flip-entries|flip-list|embeddedfolderview~i', $html) && !preg_match('~<title>[^<]*</title>~i', $html)) throw new RuntimeException('Google Drive sent something unexpected. Check the folder is shared with “Anyone with the link”.');
    usort($out, fn($a, $b) => (($a['kind'] === 'folder') === ($b['kind'] === 'folder')) ? strcasecmp($a['name'], $b['name']) : ($a['kind'] === 'folder' ? -1 : 1));
    return $out;
}
/** [bytes, filename, mime] of a file (Google Docs, Sheets and Slides are exported as Word, Excel and PowerPoint files). Throws RuntimeException. */
function gd_download(string $id, string $kind, string $name): array {
    if (!gd_id_ok($id)) throw new RuntimeException('Bad file');
    $ex = ['doc' => ['document', 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'sheet' => ['spreadsheets', 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], 'slide' => ['presentation', 'pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation']];
    if (isset($ex[$kind])) { [$seg, $ext, $mime] = $ex[$kind]; [$code, $ct, $body, $err] = gd_fetch((cfg('gdrive_docs_base') ? rtrim((string)cfg('gdrive_docs_base'), '/') : 'https://docs.google.com') . "/$seg/d/$id/export?format=$ext"); $fn = preg_replace('/\.' . $ext . '$/i', '', $name) . '.' . $ext; }
    else { [$code, $ct, $body, $err] = gd_fetch(gd_base() . '/uc?export=download&confirm=t&id=' . $id); $fn = $name; $mime = 'application/octet-stream';
        if (!$err && $code < 400 && stripos($ct, 'text/html') !== false && stripos($body, 'download') !== false) { [$code, $ct, $body, $err] = gd_fetch((cfg('gdrive_dl_base') ?: 'https://drive.usercontent.google.com') . '/download?id=' . $id . '&export=download&confirm=t'); } }
    if ($err) throw new RuntimeException($err); if ($code === 404 || $code === 403 || $code === 401) throw new RuntimeException('Google Drive will not give this file out (it may not be shared, or downloads are switched off on it).');
    if ($code >= 400 || $body === '') throw new RuntimeException('Google Drive answered ' . $code . ' for this file.');
    if (stripos($ct, 'text/html') !== false) throw new RuntimeException('Google Drive would not hand this file over (sharing is restricted, or it is too large for a direct download). Open it in Drive instead.');
    $fn = trim(preg_replace('/[\x00-\x1f\\\\\/"]+/', '_', $fn), ' .') ?: 'file'; return [$body, mb_substr($fn, 0, 150), $mime];
}
function gd_url(string $id, string $kind): string {
    return match ($kind) { 'folder' => 'https://drive.google.com/drive/folders/' . $id, 'doc' => 'https://docs.google.com/document/d/' . $id . '/edit', 'sheet' => 'https://docs.google.com/spreadsheets/d/' . $id . '/edit', 'slide' => 'https://docs.google.com/presentation/d/' . $id . '/edit', default => 'https://drive.google.com/file/d/' . $id . '/view' };
}
