<?php
declare(strict_types=1);
// Pure-PHP IMAP + SMTP + MIME helpers (no PHP "imap" extension needed).

class MailError extends RuntimeException {}

/* ---------------- credentials at rest ---------------- */
function mail_key(): string {
    $k = (string)(cfg('mail_key') ?: '');
    if ($k === '' || $k === 'CHANGE_ME_MAIL_KEY') $k = (string)cfg('cron_key') . '|' . (string)cfg('db_name') . '|' . (string)cfg('db_user');
    return hash('sha256', $k . '|aerosub-mail-v1', true);
}
function mail_encrypt(string $plain): string {
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', mail_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new MailError('Could not secure the mailbox password');
    return base64_encode($iv . $tag . $ct);
}
function mail_decrypt(string $blob): string {
    $raw = base64_decode($blob, true); if ($raw === false || strlen($raw) < 29) throw new MailError('Stored mailbox password is unreadable. Please reconnect your mailbox.');
    $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', mail_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($pt === false) throw new MailError('Stored mailbox password is unreadable. Please reconnect your mailbox.');
    return $pt;
}

/* ---------------- IMAP ---------------- */
function imap_q(string $s): string { // quoted string
    if (preg_match('/[\r\n\0]/', $s)) throw new MailError('Invalid characters in request');
    return '"' . addcslashes($s, '"\\') . '"';
}

class Imap {
    private $fp; private int $n = 0; public string $selected = '';
    public function __construct(string $host, int $port, bool $verify = true) {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $fp = @stream_socket_client("ssl://$host:$port", $errno, $err, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new MailError("Could not connect to the incoming mail server ($host:$port). " . ($err ?: 'Check the server name, port and that outgoing connections are allowed.'));
        $this->fp = $fp; stream_set_timeout($fp, 30);
        $g = fgets($fp);
        if ($g === false || !preg_match('/^\* (OK|PREAUTH)/i', $g)) throw new MailError('The incoming mail server did not respond correctly.');
    }
    public function __destruct() { if ($this->fp) { @fwrite($this->fp, 'A' . (++$this->n) . " LOGOUT\r\n"); @fclose($this->fp); } }

    private function bytes(int $n): string {
        $buf = '';
        while (strlen($buf) < $n) { $c = fread($this->fp, $n - strlen($buf)); if ($c === false || $c === '') throw new MailError('Mail server connection timed out.'); $buf .= $c; }
        return $buf;
    }
    private function readResp(string $tag): array {
        $untagged = [];
        while (true) {
            $line = fgets($this->fp); if ($line === false) throw new MailError('Mail server connection timed out.');
            if (strncmp($line, $tag . ' ', strlen($tag) + 1) === 0) {
                $rest = trim(substr($line, strlen($tag) + 1));
                return ['status' => strtoupper((string)strtok($rest, ' ')), 'text' => $rest, 'data' => $untagged];
            }
            if ($line[0] === '+') { $untagged[] = $line; continue; }
            $resp = $line;
            while (preg_match('/\{(\d+)\}\r?\n$/', $resp, $m)) {
                $resp .= $this->bytes((int)$m[1]);
                $more = fgets($this->fp); if ($more === false) throw new MailError('Mail server connection timed out.');
                $resp .= $more;
            }
            $untagged[] = $resp;
        }
    }
    public function cmd(string $c, bool $mustOk = true): array {
        $tag = 'A' . (++$this->n);
        fwrite($this->fp, "$tag $c\r\n");
        $r = $this->readResp($tag);
        if ($mustOk && $r['status'] !== 'OK') throw new MailError('Mail server said: ' . preg_replace('/^(NO|BAD)\s*/i', '', $r['text']));
        return $r;
    }
    public function login(string $user, string $pass): void {
        if (!preg_match('/^[\x20-\x7e]*$/', $user . $pass)) throw new MailError('Mailbox email or password contains characters this connection cannot send. Use a password with standard keyboard characters.');
        $tag = 'A' . (++$this->n);
        fwrite($this->fp, "$tag LOGIN " . imap_q($user) . ' ' . imap_q($pass) . "\r\n");
        $r = $this->readResp($tag);
        if ($r['status'] !== 'OK') throw new MailError('Sign-in to the mailbox was refused. Check the email address and password.');
    }
    public function select(string $folder): int {
        $r = $this->cmd('SELECT ' . imap_q(self::enc($folder)));
        $this->selected = $folder; $exists = 0;
        foreach ($r['data'] as $l) if (preg_match('/^\* (\d+) EXISTS/i', $l, $m)) $exists = (int)$m[1];
        return $exists;
    }
    public static function enc(string $name): string { return mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8'); }
    public static function dec(string $name): string { return mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP'); }

    /** @return array<int,array{name:string,role:string,flags:string[],delim:string}> */
    public function folders(): array {
        $r = $this->cmd('LIST "" "*"'); $out = [];
        foreach ($r['data'] as $l) {
            if (!preg_match('/^\* LIST \(([^)]*)\) (?:"((?:[^"\\\\]|\\\\.)*)"|NIL) (?:"((?:[^"\\\\]|\\\\.)*)"|(\S+))\r?\n?$/s', $l, $m)) continue;
            $flags = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (in_array('\\Noselect', $flags, true) || in_array('\\NonExistent', $flags, true)) continue;
            $raw = stripcslashes($m[3] !== '' ? $m[3] : ($m[4] ?? ''));
            $name = self::dec($raw); $role = '';
            foreach (['\\Sent' => 'sent', '\\Trash' => 'trash', '\\Drafts' => 'drafts', '\\Junk' => 'junk', '\\Archive' => 'archive'] as $f => $ro) if (in_array($f, $flags, true)) $role = $ro;
            if ($role === '') {
                $n = strtolower($name);
                if (strcasecmp($name, 'INBOX') === 0) $role = 'inbox';
                elseif (preg_match('/(^|[.\/])(sent( items| messages| mail)?)$/', $n)) $role = 'sent';
                elseif (preg_match('/(^|[.\/])(trash|deleted( items| messages)?|bin)$/', $n)) $role = 'trash';
                elseif (preg_match('/(^|[.\/])drafts?$/', $n)) $role = 'drafts';
                elseif (preg_match('/(^|[.\/])(junk|spam|junk e-?mail)$/', $n)) $role = 'junk';
                elseif (preg_match('/(^|[.\/])archives?$/', $n)) $role = 'archive';
            }
            $out[] = ['name' => $name, 'role' => $role, 'flags' => $flags, 'delim' => stripcslashes($m[2] ?? '/')];
        }
        $order = ['inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'junk' => 4, 'trash' => 5, '' => 6];
        usort($out, fn($a, $b) => [$order[$a['role']], strtolower($a['name'])] <=> [$order[$b['role']], strtolower($b['name'])]);
        return $out;
    }
    public function status(string $folder): array {
        $r = $this->cmd('STATUS ' . imap_q(self::enc($folder)) . ' (MESSAGES UNSEEN)');
        $o = ['messages' => 0, 'unseen' => 0];
        foreach ($r['data'] as $l) { if (preg_match('/MESSAGES (\d+)/', $l, $m)) $o['messages'] = (int)$m[1]; if (preg_match('/UNSEEN (\d+)/', $l, $m)) $o['unseen'] = (int)$m[1]; }
        return $o;
    }
    /** @return int[] UIDs ascending */
    public function search(string $criteria): array {
        $r = $this->cmd('UID SEARCH ' . $criteria); $uids = [];
        foreach ($r['data'] as $l) if (preg_match('/^\* SEARCH ?(.*)$/m', $l, $m)) foreach (preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) as $u) $uids[] = (int)$u;
        sort($uids); return $uids;
    }
    public function headers(array $uids): array {
        if (!$uids) return [];
        $r = $this->cmd('UID FETCH ' . implode(',', array_map('intval', $uids)) . ' (UID FLAGS RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID REFERENCES CONTENT-TYPE)])');
        $out = [];
        foreach ($r['data'] as $l) {
            if (!preg_match('/UID (\d+)/', $l, $mu)) continue;
            $flags = []; if (preg_match('/FLAGS \(([^)]*)\)/', $l, $mf)) $flags = preg_split('/\s+/', trim($mf[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $hdr = ''; if (preg_match('/BODY\[HEADER[^\]]*\] \{(\d+)\}\r?\n/', $l, $mh, PREG_OFFSET_CAPTURE)) $hdr = substr($l, $mh[0][1] + strlen($mh[0][0]), (int)$mh[1][0]);
            $h = mime_headers($hdr);
            $out[(int)$mu[1]] = ['uid' => (int)$mu[1], 'flags' => $flags, 'seen' => in_array('\\Seen', $flags, true), 'flagged' => in_array('\\Flagged', $flags, true), 'answered' => in_array('\\Answered', $flags, true),
                'from' => mime_addr_list($h['from'] ?? ''), 'to' => mime_addr_list($h['to'] ?? ''), 'subject' => mime_decode_header($h['subject'] ?? ''), 'date' => mime_date($h['date'] ?? ''),
                'message_id' => trim($h['message-id'] ?? ''), 'references' => trim($h['references'] ?? ''), 'has_att' => (bool)preg_match('~multipart/mixed~i', $h['content-type'] ?? '')];
        }
        return $out;
    }
    public function fetchRaw(int $uid, int $maxBytes = 25_000_000): string {
        $r = $this->cmd("UID FETCH $uid (RFC822.SIZE)");
        foreach ($r['data'] as $l) if (preg_match('/RFC822\.SIZE (\d+)/', $l, $m) && (int)$m[1] > $maxBytes) throw new MailError('This message is too large to open here.');
        $r = $this->cmd("UID FETCH $uid (BODY.PEEK[])");
        foreach ($r['data'] as $l) if (preg_match('/BODY\[\] \{(\d+)\}\r?\n/', $l, $m, PREG_OFFSET_CAPTURE)) return substr($l, $m[0][1] + strlen($m[0][0]), (int)$m[1][0]);
        throw new MailError('Message not found.');
    }
    public function flag(int $uid, string $flag, bool $on): void { $this->cmd("UID STORE $uid " . ($on ? '+' : '-') . "FLAGS.SILENT ($flag)"); }
    public function move(int $uid, string $dest): void {
        $this->cmd("UID COPY $uid " . imap_q(self::enc($dest)));
        $this->flag($uid, '\\Deleted', true); $this->cmd('EXPUNGE');
    }
    public function expunge(int $uid): void { $this->flag($uid, '\\Deleted', true); $this->cmd('EXPUNGE'); }
    public function append(string $folder, string $raw, string $flags = '\\Seen'): void {
        $tag = 'A' . (++$this->n);
        fwrite($this->fp, "$tag APPEND " . imap_q(self::enc($folder)) . " ($flags) {" . strlen($raw) . "}\r\n");
        $c = fgets($this->fp); if ($c === false || $c[0] !== '+') throw new MailError('The server refused to store a copy of the message.');
        fwrite($this->fp, $raw . "\r\n");
        $r = $this->readResp($tag); if ($r['status'] !== 'OK') throw new MailError('The server refused to store a copy of the message.');
    }
}

/* ---------------- SMTP ---------------- */
class Smtp {
    private $fp;
    public function __construct(string $host, int $port, bool $verify = true) {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $fp = @stream_socket_client(($port === 465 ? 'ssl' : 'tcp') . "://$host:$port", $errno, $err, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new MailError("Could not connect to the outgoing mail server ($host:$port). " . ($err ?: 'Check the server name, port and that outgoing connections are allowed.'));
        $this->fp = $fp; stream_set_timeout($fp, 30);
        $this->expect(220);
        $this->send('EHLO ' . (preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['SERVER_NAME'] ?? 'localhost') ?: 'localhost'), 250);
        if ($port !== 465) {
            $this->send('STARTTLS', 220);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new MailError('Could not secure the connection to the outgoing mail server.');
            $this->send('EHLO localhost', 250);
        }
    }
    private function read(): array {
        $text = ''; $code = 0;
        while (true) { $l = fgets($this->fp); if ($l === false) throw new MailError('Outgoing mail server connection timed out.'); $code = (int)substr($l, 0, 3); $text .= $l; if (strlen($l) < 4 || $l[3] !== '-') break; }
        return [$code, trim($text)];
    }
    private function expect(int ...$ok): string {
        [$code, $text] = $this->read();
        if (!in_array($code, $ok, true)) throw new MailError('Outgoing mail server said: ' . preg_replace('/\s+/', ' ', $text));
        return $text;
    }
    private function send(string $line, int ...$ok): string { fwrite($this->fp, $line . "\r\n"); return $this->expect(...$ok); }
    public function auth(string $user, string $pass): void {
        fwrite($this->fp, "AUTH LOGIN\r\n"); $this->expect(334);
        fwrite($this->fp, base64_encode($user) . "\r\n"); $this->expect(334);
        fwrite($this->fp, base64_encode($pass) . "\r\n");
        [$code, $txt] = $this->read(); if ($code !== 235) throw new MailError('The outgoing mail server refused the mailbox sign-in. Check the email address and password. Server reply: ' . mb_substr(preg_replace('/\s+/', ' ', $txt), 0, 160));
    }
    public function reset(): void { $this->send('RSET', 250); }
    public function deliver(string $from, array $rcpts, string $raw): void {
        $this->send('MAIL FROM:<' . $from . '>', 250);
        foreach ($rcpts as $r) $this->send('RCPT TO:<' . $r . '>', 250, 251);
        $this->send('DATA', 354);
        $raw = preg_replace('/(?<!\r)\n/', "\r\n", $raw); $raw = preg_replace('/^\./m', '..', $raw);
        fwrite($this->fp, $raw . "\r\n.\r\n"); $this->expect(250);
    }
    public function __destruct() { if ($this->fp) { @fwrite($this->fp, "QUIT\r\n"); @fclose($this->fp); } }
}

/* ---------------- MIME parsing ---------------- */
function mime_headers(string $block): array {
    $block = preg_replace('/\r?\n[ \t]+/', ' ', $block); $h = [];
    foreach (preg_split('/\r?\n/', $block) as $line) if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) { $k = strtolower($m[1]); if (!isset($h[$k])) $h[$k] = trim($m[2]); }
    return $h;
}
function mime_to_utf8(string $s, string $charset): string {
    $charset = trim(strtolower($charset), "\"' "); if ($charset === '' || $charset === 'utf-8' || $charset === 'us-ascii') return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    $r = @mb_convert_encoding($s, 'UTF-8', $charset); if ($r !== false && $r !== null) return $r;
    $r = @iconv($charset, 'UTF-8//IGNORE', $s); return $r !== false ? $r : $s;
}
function mime_decode_header(string $v): string {
    if ($v === '') return '';
    if (stripos($v, '=?') === false) return trim($v);
    $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8'); return trim($d !== false ? $d : $v);
}
function mime_addr_list(string $v): array {
    $out = []; if (trim($v) === '') return $out;
    foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $v) as $part) {
        $part = trim($part); if ($part === '') continue;
        if (preg_match('/^(.*?)<([^>]+)>\s*$/', $part, $m)) $out[] = ['name' => trim(mime_decode_header(trim($m[1])), " \"'"), 'email' => trim($m[2])];
        else $out[] = ['name' => '', 'email' => trim($part, " <>")];
    }
    return $out;
}
function mime_date(string $v): ?string { $t = strtotime($v); return $t ? date('Y-m-d H:i:s', $t) : null; }
function mime_param(string $header, string $name): ?string {
    if (preg_match('/;\s*' . preg_quote($name, '/') . '\*=([^\'"]*)\'[^\']*\'([^;]+)/i', $header, $m)) return mime_to_utf8(rawurldecode($m[2]), $m[1]);
    if (preg_match('/;\s*' . preg_quote($name, '/') . '="((?:[^"\\\\]|\\\\.)*)"/i', $header, $m)) return mime_decode_header(stripslashes($m[1]));
    if (preg_match('/;\s*' . preg_quote($name, '/') . '=([^\s;]+)/i', $header, $m)) return mime_decode_header($m[1]);
    return null;
}
function mime_parse(string $raw, int $depth = 0): array {
    $pos = strpos($raw, "\r\n\r\n"); $sep = 4;
    if ($pos === false) { $pos = strpos($raw, "\n\n"); $sep = 2; }
    if ($pos === false) { $head = $raw; $body = ''; } else { $head = substr($raw, 0, $pos); $body = substr($raw, $pos + $sep); }
    $h = mime_headers($head); $ct = $h['content-type'] ?? 'text/plain'; $type = strtolower(trim((string)strtok($ct, ';')));
    $node = ['headers' => $h, 'type' => $type, 'charset' => mime_param($ct, 'charset') ?? '', 'parts' => [], 'body' => '', 'filename' => null, 'disp' => '', 'cid' => ''];
    $cd = $h['content-disposition'] ?? ''; $node['disp'] = strtolower(trim((string)strtok($cd, ';')));
    $node['filename'] = mime_param($cd, 'filename') ?? mime_param($ct, 'name');
    $node['cid'] = trim($h['content-id'] ?? '', '<> ');
    if (str_starts_with($type, 'multipart/') && $depth < 8 && ($b = mime_param($ct, 'boundary'))) {
        $chunks = preg_split('/\r?\n?--' . preg_quote($b, '/') . '(?:--)?[ \t]*\r?\n?/', $body);
        array_shift($chunks);
        foreach ($chunks as $c) { if (trim($c) === '') continue; $node['parts'][] = mime_parse(ltrim($c, "\r\n") === $c ? $c : ltrim($c, "\r\n"), $depth + 1); }
        return $node;
    }
    if ($type === 'message/rfc822' && $depth < 8) { $node['body'] = $body; return $node; }
    $enc = strtolower(trim($h['content-transfer-encoding'] ?? '7bit'));
    $body = match ($enc) { 'base64' => (string)base64_decode(preg_replace('/\s+/', '', $body), false), 'quoted-printable' => quoted_printable_decode($body), default => $body };
    $node['body'] = $body; return $node;
}
function mime_flatten(array $n, array &$out): void { if ($n['parts']) foreach ($n['parts'] as $p) mime_flatten($p, $out); else $out[] = $n; }

/** Returns ['html'=>string|null,'text'=>string,'attachments'=>[['i','name','type','size']], 'inline'=>[cid=>data-uri]] */
function mime_extract(string $raw): array {
    $tree = mime_parse($raw); $leaves = []; mime_flatten($tree, $leaves);
    $html = null; $text = ''; $atts = []; $inline = [];
    foreach ($leaves as $i => $l) {
        $isAtt = $l['disp'] === 'attachment' || ($l['filename'] && !str_starts_with($l['type'], 'text/')) || $l['type'] === 'message/rfc822';
        if (!$isAtt && $l['type'] === 'text/html' && $html === null) { $html = mime_to_utf8($l['body'], $l['charset']); continue; }
        if (!$isAtt && ($l['type'] === 'text/plain' || $l['type'] === '') && $text === '') { $text = mime_to_utf8($l['body'], $l['charset']); continue; }
        if ($l['cid'] !== '' && str_starts_with($l['type'], 'image/') && strlen($l['body']) < 1_500_000 && preg_match('~^image/(png|jpe?g|gif|webp)$~', $l['type'])) { $inline[$l['cid']] = 'data:' . $l['type'] . ';base64,' . base64_encode($l['body']); if (!$isAtt) continue; }
        if ($isAtt || $l['filename']) $atts[] = ['i' => $i, 'name' => $l['filename'] ?: ($l['type'] === 'message/rfc822' ? 'forwarded-message.eml' : 'attachment'), 'type' => $l['type'], 'size' => strlen($l['body'])];
    }
    if ($text === '' && $html !== null) $text = trim(html_entity_decode(strip_tags(preg_replace(['~<(script|style)[^>]*>.*?</\1>~is', '~<br\s*/?>|</(p|div|tr|li|h\d)>~i'], ['', "\n"], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return ['html' => $html, 'text' => $text, 'attachments' => $atts, 'inline' => $inline, 'leaves' => $leaves, 'headers' => $tree['headers']];
}

/* ---------------- HTML safety ---------------- */
function html_load(string $html): DOMDocument {
    $dom = new DOMDocument(); libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><div id="aero-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    libxml_clear_errors(); return $dom;
}
function html_inner(DOMDocument $dom): string {
    $root = $dom->getElementById('aero-root'); $o = '';
    if ($root) foreach ($root->childNodes as $c) $o .= $dom->saveHTML($c);
    return $o;
}
/** Received email: remove anything active. Remote content is additionally blocked by CSP in the viewer. */
function html_sanitize_incoming(string $html, array $inline = []): string {
    foreach ($inline as $cid => $uri) $html = str_ireplace(['cid:' . $cid], [$uri], $html);
    $dom = html_load($html); $kill = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'meta', 'link', 'base', 'svg', 'math', 'applet', 'audio', 'video', 'source'];
    foreach ($kill as $t) { $nodes = iterator_to_array($dom->getElementsByTagName($t)); foreach ($nodes as $n) $n->parentNode?->removeChild($n); }
    $xp = new DOMXPath($dom);
    foreach ($xp->query('//*') as $el) {
        $drop = [];
        foreach ($el->attributes as $a) {
            $n = strtolower($a->name); $v = strtolower(preg_replace('/[\s\x00-\x1f]+/', '', $a->value));
            if (str_starts_with($n, 'on') || in_array($n, ['srcdoc', 'formaction', 'srcset', 'ping'], true)) $drop[] = $a->name;
            elseif (in_array($n, ['href', 'src', 'background', 'action', 'xlink:href'], true) && preg_match('/^(javascript|vbscript|data:text|data:application)/', $v)) $drop[] = $a->name;
            elseif ($n === 'style' && preg_match('/expression\(|javascript:|behavior:|@import/i', $a->value)) $drop[] = $a->name;
        }
        foreach ($drop as $d) $el->removeAttribute($d);
        if (strtolower($el->nodeName) === 'a') { $el->setAttribute('target', '_blank'); $el->setAttribute('rel', 'noopener noreferrer nofollow'); }
    }
    return html_inner($dom);
}
/** Outgoing email composed in the app: tiny allow-list. */
function html_sanitize_outgoing(string $html, ?int $ownerUid = null): string {
    $allowed = ['img', 'p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'a', 'blockquote', 'h1', 'h2', 'h3', 'hr', 'pre', 'code'];
    $dom = html_load($html);
    $changed = true; $guard = 0;
    while ($changed && $guard++ < 50) {
        $changed = false;
        foreach (iterator_to_array((new DOMXPath($dom))->query('//*[not(@id="aero-root")]')) as $el) {
            if (!$el->parentNode) continue;
            $name = strtolower($el->nodeName);
            if (in_array($name, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'link', 'meta', 'svg', 'head', 'title'], true)) { $el->parentNode->removeChild($el); $changed = true; continue; }
            if (!in_array($name, $allowed, true)) { while ($el->firstChild) $el->parentNode->insertBefore($el->firstChild, $el); $el->parentNode->removeChild($el); $changed = true; continue; }
            if ($name === 'img') { // only the sender's own profile photo is allowed
                $src = trim($el->getAttribute('src'));
                if ($ownerUid === null || !preg_match('~^api/avatar/(\d+)(\?[\w=&]*)?$~', $src, $mm) || (int)$mm[1] !== $ownerUid) { $el->parentNode->removeChild($el); $changed = true; continue; }
                foreach (iterator_to_array($el->attributes) as $a) $el->removeAttribute($a->name);
                $el->setAttribute('src', 'api/avatar/' . $ownerUid); $el->setAttribute('width', '72'); $el->setAttribute('height', '72'); $el->setAttribute('alt', '');
                $el->setAttribute('style', 'border-radius:50%;object-fit:cover;vertical-align:middle;width:72px;height:72px');
                continue;
            }
            $drop = []; foreach ($el->attributes as $a) if (!($name === 'a' && $a->name === 'href')) $drop[] = $a->name;
            foreach ($drop as $d) $el->removeAttribute($d);
            if ($name === 'a') { $href = trim($el->getAttribute('href')); if (!preg_match('~^(https?://|mailto:)~i', $href)) $el->removeAttribute('href'); else { $el->setAttribute('rel', 'noopener noreferrer'); } }
        }
    }
    return html_inner($dom);
}
function html_to_text(string $html): string {
    $t = preg_replace(['~<(script|style)[^>]*>.*?</\1>~is', '~<br\s*/?>~i', '~</(p|div|li|h\d|blockquote|tr)>~i', '~<li[^>]*>~i'], ['', "\n", "\n", '- '], $html);
    return trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/* ---------------- building outgoing mail ---------------- */
function mime_hdr_encode(string $s): string { return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s; }
function mime_addr(array $a): string {
    $e = $a['email']; $n = trim($a['name'] ?? ''); if ($n === '') return $e;
    return (preg_match('/[^\x20-\x7e]/', $n) ? mime_hdr_encode($n) : '"' . addcslashes($n, '"\\') . '"') . " <$e>";
}
/** Parses "Ann <a@x.com>, b@y.com" → list of ['name','email'] with validation. */
function parse_recipients(string $v): array {
    $out = [];
    foreach (mime_addr_list(str_replace(';', ',', $v)) as $a) {
        $e = strtolower(trim($a['email'])); if ($e === '') continue;
        if (!filter_var($e, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $e)) throw new MailError("“{$a['email']}” is not a valid email address.");
        $out[] = ['name' => preg_replace('/[\r\n]+/', ' ', $a['name']), 'email' => $e];
    }
    return $out;
}
function build_message(array $o): string { // from, to[], cc[], subject, html, text, attachments[[name,type,data]], in_reply_to, references, message_id_domain
    $dom = $o['domain'] ?? 'aerosub.co';
    $id = '<' . bin2hex(random_bytes(12)) . '@' . $dom . '>';
    $h = ['Date: ' . date(DATE_RFC2822), 'From: ' . mime_addr($o['from']), 'To: ' . implode(', ', array_map('mime_addr', $o['to']))];
    if ($o['cc']) $h[] = 'Cc: ' . implode(', ', array_map('mime_addr', $o['cc']));
    $subj = preg_replace('/[\r\n]+/', ' ', (string)$o['subject']);
    $h[] = 'Subject: ' . mime_hdr_encode($subj); $h[] = 'Message-ID: ' . $id; $h[] = 'MIME-Version: 1.0'; $h[] = 'X-Mailer: Aerosub Office';
    if (!empty($o['sent_by'])) $h[] = 'X-Sent-By: ' . mime_hdr_encode(preg_replace('/[\r\n]+/', ' ', (string)$o['sent_by']));
    foreach (($o['extra_headers'] ?? []) as $eh) $h[] = preg_replace('/[\r\n]+/', ' ', $eh);
    if (!empty($o['in_reply_to'])) { $h[] = 'In-Reply-To: ' . preg_replace('/[\r\n]+/', '', $o['in_reply_to']); $h[] = 'References: ' . preg_replace('/[\r\n]+/', '', trim(($o['references'] ?? '') . ' ' . $o['in_reply_to'])); }
    $b64 = fn(string $d) => chunk_split(base64_encode($d), 76, "\r\n");
    $alt = 'alt_' . bin2hex(random_bytes(8));
    $htmlB64 = $b64(!empty($o['full_html']) ? $o['full_html'] : '<!doctype html><html><body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111">' . $o['html'] . '</body></html>');
    if (!empty($o['inline'])) {
        $rel = 'rel_' . bin2hex(random_bytes(8));
        $htmlPart = "Content-Type: multipart/related; boundary=\"$rel\"\r\n\r\n--$rel\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $htmlB64;
        foreach ($o['inline'] as $im) $htmlPart .= "--$rel\r\nContent-Type: {$im['type']}\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <{$im['cid']}>\r\nContent-Disposition: inline\r\n\r\n" . $b64($im['data']);
        $htmlPart .= "--$rel--\r\n";
    } else $htmlPart = "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $htmlB64;
    $altBody = "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($o['text']) . "--$alt\r\n" . $htmlPart . "--$alt--\r\n";
    if (!$o['attachments']) return implode("\r\n", $h) . "\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n" . $altBody;
    $mix = 'mix_' . bin2hex(random_bytes(8));
    $body = "--$mix\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n" . $altBody;
    foreach ($o['attachments'] as $a) {
        $fn = preg_replace('/[\r\n"\\\\\/]+/', '_', $a['name']) ?: 'attachment'; $type = preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+(;\s*[a-z0-9._-]+="?[a-z0-9._-]+"?)*$~i', $a['type']) ? $a['type'] : 'application/octet-stream';
        $body .= "--$mix\r\nContent-Type: $type; name=\"" . addcslashes(preg_replace('/[^\x20-\x7e]/', '_', $fn), '"\\') . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . addcslashes(preg_replace('/[^\x20-\x7e]/', '_', $fn), '"\\') . "\"; filename*=UTF-8''" . rawurlencode($fn) . "\r\n\r\n" . $b64($a['data']);
    }
    $body .= "--$mix--\r\n";
    return implode("\r\n", $h) . "\r\nContent-Type: multipart/mixed; boundary=\"$mix\"\r\n\r\n" . $body;
}
