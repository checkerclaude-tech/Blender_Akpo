<?php
declare(strict_types=1);
// Reading what an RFQ asks for: the list of items, from a PDF, an Excel sheet, a Word file or the email itself.
//   rfq_extract(array $mime)  ->  ['items' => [['description','qty','unit']], 'source' => 'RFQ-12.pdf' | 'the email', 'files' => [['name','mime','data']]]
// PDFs are read with pdftotext when the server has it, otherwise with a built-in reader for ordinary text PDFs (a scanned picture of a page cannot be read).
require_once __DIR__ . '/xlsx.php';

const RFQ_UNITS = 'pcs?|pieces?|nos?|no\.?|ea|each|units?|sets?|lots?|m|mtrs?|meters?|metres?|km|kg|kgs|g|tons?|tonnes?|ltrs?|litres?|liters?|l|rolls?|packs?|packets?|drums?|bags?|boxes?|bundles?|lengths?|pairs?|pkts?|ctns?|cartons?|crates?|days?|hrs?|hours?|mths?|months?|lot|lt|sqm|m2|m3|ft|pc';
function rfq_num($v): ?float { $s = trim(str_replace([',', ' '], '', (string)$v)); return $s !== '' && preg_match('/^\d+(\.\d+)?$/', $s) ? (float)$s : null; }
function rfq_clean_cell($v): string { return trim(preg_replace('/\s+/u', ' ', (string)$v)); }
/** One cell such as "2 Pcs", "10 m" or "5" -> [qty, unit] */
function rfq_qty_unit(string $c): ?array { if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(' . RFQ_UNITS . ')?\.?$/i', trim($c), $m)) return [(float)str_replace(',', '.', str_replace(',', '', $m[1])), $m[2] ?? '']; return null; }
function rfq_not_item(string $d): bool { return $d === '' || mb_strlen($d) < 3 || preg_match('/^(sub-?total|total|grand total|vat|tax|notes?|terms|signature|page \d+)\b/i', $d) || preg_match('/^[\d\s.,\-]+$/', $d); }
/** Rows of cells (a sheet, a Word table or text columns) -> items. Looks for a header row naming a description and a quantity column. */
function rfq_items_from_rows(array $rows, bool $merge = false): array {
    $hi = -1; $dc = $qc = $uc = -1; $both = false;
    foreach (array_slice($rows, 0, 40, true) as $i => $r) {
        $d = $q = $u = -1; if (isset($r['__wrap'])) continue; foreach ($r as $k => $c) { $t = strtolower(rfq_clean_cell($c)); if ($t === '') continue;
            if ($d < 0 && preg_match('/^(item\s*)?(description|details?|particulars|material|materials|goods|scope( of (work|supply))?|name of item|product|items?|specification)\b/', $t)) $d = $k;
            elseif ($q < 0 && preg_match('/^(qty|quantity|qnty|qtys?\.?|no\.? of|number|nos?\.?|required)\b/', $t)) { $q = $k; if (preg_match('/uo?m|unit/', $t)) $both = true; }
            elseif ($u < 0 && preg_match('/^(uo?m|u\/m|units?|unit of measure)\b/', $t)) $u = $k; }
        if ($d >= 0 && ($q >= 0)) { $hi = $i; $dc = $d; $qc = $q; $uc = $u; break; } }
    $items = [];
    if ($hi >= 0) { foreach ($rows as $i => $r) { if ($i <= $hi) continue; if (isset($r['__wrap'])) { if ($merge && $items) $items[count($items) - 1]['description'] = mb_substr($items[count($items) - 1]['description'] . ' ' . rfq_clean_cell($r['__wrap']), 0, 500); continue; } $desc = rfq_clean_cell($r[$dc] ?? ''); if (rfq_not_item($desc)) continue; $cell = rfq_clean_cell($r[$qc] ?? ''); $qty = rfq_num($cell); $unit = $uc >= 0 ? rfq_clean_cell($r[$uc] ?? '') : '';
        if ($qty === null && ($qu = rfq_qty_unit($cell))) { $qty = $qu[0]; if ($unit === '') $unit = $qu[1]; } elseif ($qty !== null && $unit === '' && $uc < 0) { /* number only */ }
        if ($qty === null && $cell === '') { if ($merge && $items) $items[count($items) - 1]['description'] = mb_substr($items[count($items) - 1]['description'] . ' ' . $desc, 0, 500); continue; }   // a wrapped line of the description above, or a heading
        if ($qty === null) continue; $items[] = ['description' => mb_substr($desc, 0, 500), 'qty' => $qty ?? 1.0, 'unit' => mb_substr($unit, 0, 30)]; if (count($items) >= 100) break; } }
    return $items;
}
/** Free text (an email, or a PDF turned into text) -> items, from numbered / bulleted lines or column layouts. */
function rfq_items_from_text(string $text): array {
    $lines = preg_split('/\r?\n/', $text); $rows = []; foreach ($lines as $l) { $l = rtrim($l); if (trim($l) === '') continue; $cells = preg_split('/\s{2,}|\t+/', trim($l)); $rows[] = count($cells) === 1 && preg_match('/^\s{2,}/', $l) ? ['__wrap' => $cells[0]] : $cells; }
    $its = rfq_items_from_rows($rows, true); if ($its) return $its;
    $U = RFQ_UNITS; $items = [];
    foreach ($lines as $l) { $l = trim($l); if (mb_strlen($l) < 5 || mb_strlen($l) > 300) continue;
        if (preg_match('/^(?:\d{1,3}[.)\-:]?\s+|[-•*·]\s+)(.+?)[\s,:\-–(]+(?:qty\.?|quantity)?\s*[:=]?\s*(\d+(?:[.,]\d+)?)\s*(' . $U . ')\b\.?\)?\s*$/i', $l, $m) && !rfq_not_item($m[1])) $items[] = ['description' => trim($m[1], " \t-–:,"), 'qty' => (float)str_replace(',', '', $m[2]), 'unit' => $m[3]];
        elseif (preg_match('/^(?:\d{1,3}[.)\-:]?\s+|[-•*·]\s+)(\d+(?:[.,]\d+)?)\s*(?:x\s*)?(' . $U . ')\b\.?\s*(?:of\s+|[-–:]\s*)?(.{3,})$/i', $l, $m) && !rfq_not_item($m[3])) $items[] = ['description' => trim($m[3]), 'qty' => (float)str_replace(',', '', $m[1]), 'unit' => $m[2]];
        elseif (preg_match('/^\d{1,3}[.)]\s+(.{4,120}?)\s+x\s*(\d+)\s*$/i', $l, $m)) $items[] = ['description' => trim($m[1]), 'qty' => (float)$m[2], 'unit' => '']; if (count($items) >= 100) break; }
    return $items;
}
function rfq_tmp(string $data, string $ext): string { $p = tempnam(sys_get_temp_dir(), 'rfq'); $q = $p . '.' . $ext; @rename($p, $q); file_put_contents($q, $data); return $q; }
function rfq_items_from_xlsx(string $data): array { $f = rfq_tmp($data, 'xlsx'); try { $rows = xlsx_read($f); } catch (Throwable $e) { $rows = []; } @unlink($f); return rfq_items_from_rows($rows); }
function rfq_items_from_csv(string $data): array { $rows = []; foreach (preg_split('/\r?\n/', $data) as $l) if (trim($l) !== '') $rows[] = str_getcsv($l); return rfq_items_from_rows($rows); }
/** Word (.docx): every table is tried as rows, then the paragraphs as text. */
function rfq_items_from_docx(string $data): array {
    if (!class_exists('ZipArchive')) return []; $f = rfq_tmp($data, 'docx'); $z = new ZipArchive(); $xml = ''; if ($z->open($f) === true) { $xml = (string)$z->getFromName('word/document.xml'); $z->close(); } @unlink($f); if ($xml === '') return [];
    $d = new DOMDocument(); libxml_use_internal_errors(true); if (!@$d->loadXML($xml, LIBXML_NONET)) return []; libxml_clear_errors(); $W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'; $all = [];
    $cellText = function (DOMElement $c) use ($W) { $t = []; foreach ($c->getElementsByTagNameNS($W, 'p') as $p) { $s = ''; foreach ($p->getElementsByTagNameNS($W, 't') as $x) $s .= $x->textContent; if (trim($s) !== '') $t[] = $s; } return implode(' ', $t); };
    foreach ($d->getElementsByTagNameNS($W, 'tbl') as $tbl) { $rows = []; foreach ($tbl->getElementsByTagNameNS($W, 'tr') as $tr) { $r = []; foreach ($tr->childNodes as $c) if ($c instanceof DOMElement && $c->localName === 'tc') $r[] = $cellText($c); $rows[] = $r; } $its = rfq_items_from_rows($rows); $all = array_merge($all, $its); }
    if ($all) return array_slice($all, 0, 100);
    $lines = []; foreach ($d->getElementsByTagNameNS($W, 'p') as $p) { $s = ''; foreach ($p->childNodes as $n) { if ($n instanceof DOMElement && $n->localName === 'r') foreach ($n->childNodes as $x) { if ($x instanceof DOMElement && $x->localName === 't') $s .= $x->textContent; elseif ($x instanceof DOMElement && $x->localName === 'tab') $s .= "\t"; } } $lines[] = $s; }
    return rfq_items_from_text(implode("\n", $lines));
}
function rfq_pdf_str(string $s): string { return preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3})/', fn($m) => match ($m[1][0]) { 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => '', 'f' => '', '(' => '(', ')' => ')', '\\' => '\\', default => chr(octdec($m[1]) & 255) }, $s); }
/** code => text from a ToUnicode CMap stream. */
function rfq_pdf_cmap(string $c): array {
    $map = []; $u = fn(string $h) => (string)mb_convert_encoding(hex2bin(strlen($h) % 2 ? '0' . $h : $h) ?: '', 'UTF-8', 'UTF-16BE');
    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $c, $b)) foreach ($b[1] as $blk) if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $blk, $p, PREG_SET_ORDER)) foreach ($p as $m) $map[hexdec($m[1])] = $u($m[2]);
    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $c, $b)) foreach ($b[1] as $blk) {
        if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(\[[^\]]*\]|<[0-9A-Fa-f]+>)/', $blk, $p, PREG_SET_ORDER)) foreach ($p as $m) { $lo = hexdec($m[1]); $hi = min(hexdec($m[2]), $lo + 512);
            if ($m[3][0] === '[') { preg_match_all('/<([0-9A-Fa-f]+)>/', $m[3], $d); foreach ($d[1] as $k => $h) $map[$lo + $k] = $u($h); } else { $base = hexdec(trim($m[3], '<>')); for ($k = $lo; $k <= $hi; $k++) $map[$k] = $u(dechex($base + $k - $lo)); } } }
    return $map;
}
function rfq_pdf_text_php(string $data): string {
    if (!preg_match_all('/(\d+)\s+0\s+obj\b(.*?)endobj/s', $data, $o, PREG_SET_ORDER)) return ''; $objs = [];
    foreach ($o as $m) { $body = $m[2]; $stream = null; if (preg_match('/^(.*?)stream\r?\n(.*)\r?\nendstream/s', $body, $sm)) { $dict = $sm[1]; $raw = $sm[2]; $s = @gzuncompress($raw); if ($s === false) $s = @gzinflate(substr($raw, 2)); $stream = $s === false ? $raw : $s; } else $dict = $body; $objs[(int)$m[1]] = [$dict, $stream]; }
    $cm = []; foreach ($objs as $id => [$dict]) if (preg_match('/\/ToUnicode\s+(\d+)\s+0\s+R/', $dict, $mt) && isset($objs[(int)$mt[1]]) && $objs[(int)$mt[1]][1] !== null) $cm[$id] = rfq_pdf_cmap($objs[(int)$mt[1]][1]);
    $name = []; foreach ($objs as [$dict]) if (preg_match_all('/\/([A-Za-z0-9_.+\-]+)\s+(\d+)\s+0\s+R/', $dict, $f, PREG_SET_ORDER)) foreach ($f as $m) if (isset($cm[(int)$m[2]])) $name[$m[1]] = (int)$m[2];
    $dec = function (string $hex, ?array $map): string { $hex = preg_replace('/\s+/', '', $hex); if ($map) { $step = (max(array_keys($map)) > 255 || strlen($hex) % 4 === 0 && strlen($hex) >= 4 && !isset($map[hexdec(substr($hex, 0, 2))])) ? 4 : 2; $o = ''; for ($i = 0; $i + $step <= strlen($hex); $i += $step) $o .= $map[hexdec(substr($hex, $i, $step))] ?? ''; return $o; }
        $b = strlen($hex) % 2 === 0 ? hex2bin($hex) : false; return $b !== false && preg_match('/^[\x20-\x7e\xa0-\xff]+$/', $b) ? $b : ''; };
    $out = '';
    foreach ($objs as [$dict, $c]) { if ($c === null || !str_contains($c, 'BT') || !preg_match_all('/BT(.*?)ET/s', $c, $bt)) continue; $map = null; $frags = [];
        foreach ($bt[1] as $blk) { $lx = 0.0; $ly = 0.0; $moved = true;
            if (!preg_match_all('/\/([A-Za-z0-9_.+\-]+)\s+[\d.]+\s+Tf|(\[(?:\\\\.|<[0-9A-Fa-f\s]*>|[^\]])*\]|\((?:\\\\.|[^)])*\)|<[0-9A-Fa-f\s]*>)\s*(TJ|Tj|\')|(-?[\d.]+)\s+(-?[\d.]+)\s+(Td|TD)|(T\*)|(?:-?[\d.]+\s+){4}(-?[\d.]+)\s+(-?[\d.]+)\s+Tm/', $blk, $ops, PREG_SET_ORDER)) continue;
            foreach ($ops as $op) {
                if (!empty($op[1])) { $map = isset($name[$op[1]]) ? $cm[$name[$op[1]]] : null; }
                elseif (!empty($op[3])) { $s = ''; $arg = $op[2];
                    if ($arg[0] === '[') { preg_match_all('/\((?:\\\\.|[^)])*\)|<[0-9A-Fa-f\s]*>|-?\d+(?:\.\d+)?/', $arg, $parts); foreach ($parts[0] as $p) { if ($p[0] === '(') $s .= $map ? '' : rfq_pdf_str(substr($p, 1, -1)); elseif ($p[0] === '<') $s .= $dec(substr($p, 1, -1), $map); elseif ((float)$p < -250) $s .= ' '; } }
                    elseif ($arg[0] === '(') $s = $map ? '' : rfq_pdf_str(substr($arg, 1, -1)); else $s = $dec(substr($arg, 1, -1), $map);
                    if ($s === '') continue; if (!$moved && $frags) $frags[count($frags) - 1][2] .= $s; else $frags[] = [$ly, $lx, $s]; $moved = false; }
                elseif (!empty($op[6])) { $lx += (float)$op[4]; $ly += (float)$op[5]; $moved = true; }
                elseif (!empty($op[7])) { $ly -= 12; $moved = true; }
                elseif (isset($op[9])) { $lx = (float)$op[8]; $ly = (float)$op[9]; $moved = true; } } }
        if (!$frags) continue; usort($frags, fn($a, $b) => abs($a[0] - $b[0]) > 3 ? $b[0] <=> $a[0] : $a[1] <=> $b[1]); $minx = min(array_column($frags, 1)); $rows = []; $cy = null;
        foreach ($frags as $f) { if ($cy === null || abs($f[0] - $cy) > 3) { $rows[] = []; $cy = $f[0]; } $rows[count($rows) - 1][] = $f; }
        foreach ($rows as $r) { usort($r, fn($a, $b) => $a[1] <=> $b[1]); $txt = implode('  ', array_map(fn($f) => trim($f[2]), $r)); $out .= (count($r) === 1 && $r[0][1] > $minx + 15 ? '   ' : '') . $txt . "\n"; }
        $out .= "\n"; }
    return trim(preg_replace('/\n{3,}/', "\n\n", (string)$out));
}
function rfq_pdf_text(string $data): string {
    $off = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (function_exists('proc_open') && !in_array('proc_open', $off, true)) {
        $bin = null; foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/bin/pdftotext'] as $c) if (is_executable($c)) { $bin = $c; break; }
        if ($bin) { $f = rfq_tmp($data, 'pdf'); $ps = @proc_open([$bin, '-layout', '-enc', 'UTF-8', '-q', $f, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes); if (is_resource($ps)) { stream_set_timeout($pipes[1], 20); $out = (string)stream_get_contents($pipes[1], 2_000_000); fclose($pipes[1]); fclose($pipes[2]); proc_close($ps); @unlink($f); if (trim($out) !== '') return $out; } @unlink($f); }
    }
    return rfq_pdf_text_php($data);
}
function rfq_items_from_pdf(string $data): array { $t = rfq_pdf_text($data); return $t === '' ? [] : rfq_items_from_text($t); }
/** The attachments and body of a read email: the first source that yields a list of items wins. Original files are returned so they can be kept. */
function rfq_extract(array $x): array {
    $files = []; $items = []; $source = '';
    foreach (($x['attachments'] ?? []) as $a) { $name = (string)$a['name']; $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)); if (!in_array($ext, ['pdf', 'xlsx', 'docx', 'csv'], true)) continue; $leaf = $x['leaves'][$a['i']] ?? null; $data = $leaf ? (string)$leaf['body'] : ''; if ($data === '' || strlen($data) > 12_000_000) continue;
        $files[] = ['name' => mb_substr($name, 0, 180), 'mime' => (string)($a['type'] ?? 'application/octet-stream'), 'data' => $data];
        if ($items) continue; try { $got = match ($ext) { 'pdf' => rfq_items_from_pdf($data), 'xlsx' => rfq_items_from_xlsx($data), 'docx' => rfq_items_from_docx($data), default => rfq_items_from_csv($data) }; } catch (Throwable $e) { error_log('RFQ read ' . $name . ': ' . $e->getMessage()); $got = []; }
        if ($got) { $items = $got; $source = $name; } }
    if (!$items) { $got = rfq_items_from_text(mb_substr((string)($x['text'] ?? ''), 0, 8000)); if ($got) { $items = $got; $source = 'the email'; } }
    return ['items' => $items, 'source' => $source, 'files' => $files];
}
