<?php
declare(strict_types=1);
// Preview of Office files in the browser: turns a Word, Excel or PowerPoint file into a plain, safe HTML view (text and tables only, everything escaped).
//   POST /api/preview  {name, data (base64)}   ->  {kind:'html', html, truncated?}
require_once __DIR__ . '/xlsx.php';

function pv_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function pv_zip_part(ZipArchive $z, string $name): ?string { $d = $z->getFromName($name); return $d === false ? null : $d; }
/** Word: paragraphs, headings and tables from word/document.xml. */
function pv_docx(string $path): array {
    $z = new ZipArchive(); if ($z->open($path) !== true) throw new RuntimeException('That file could not be opened'); $xml = pv_zip_part($z, 'word/document.xml'); $z->close(); if ($xml === null) throw new RuntimeException('That is not a Word document');
    $dom = new DOMDocument(); if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) throw new RuntimeException('That Word file could not be read');
    $x = new DOMXPath($dom); $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'; $x->registerNamespace('w', $ns); $x->registerNamespace('mc', 'http://schemas.openxmlformats.org/markup-compatibility/2006'); $body = $x->query('//w:body')->item(0); if (!$body) return ['', false];
    $ptext = function (DOMNode $p) use ($x): string { $t = ''; foreach ($x->query('.//w:r[not(ancestor::mc:Fallback)]', $p) as $r) { foreach ($r->childNodes as $c) { if ($c->localName === 't') $t .= $c->textContent; elseif ($c->localName === 'tab') $t .= "\t"; elseif ($c->localName === 'br') $t .= "\n"; } } return $t; };
    $html = ''; $n = 0; $trunc = false;
    foreach ($body->childNodes as $el) {
        if (++$n > 1500) { $trunc = true; break; }
        if ($el->localName === 'p') { $t = $ptext($el); $st = $x->query('./w:pPr/w:pStyle/@w:val', $el)->item(0); $style = $st ? $st->nodeValue : ''; $b = $x->query('./w:pPr/w:jc/@w:val', $el)->item(0);
            if (trim($t) === '') { $html .= '<p>&nbsp;</p>'; continue; } $tag = preg_match('/^(Heading|Title)/i', $style) ? 'h3' : 'p'; $al = $b && in_array($b->nodeValue, ['center', 'right'], true) ? ' style="text-align:' . $b->nodeValue . '"' : '';
            $html .= "<$tag$al>" . nl2br(pv_e($t)) . "</$tag>"; }
        elseif ($el->localName === 'tbl') { $html .= '<table>'; foreach ($x->query('./w:tr', $el) as $tr) { $html .= '<tr>'; foreach ($x->query('./w:tc', $tr) as $tc) { $cell = []; foreach ($x->query('.//w:p', $tc) as $p) { $cell[] = $ptext($p); } $html .= '<td>' . nl2br(pv_e(trim(implode("\n", array_filter($cell, fn($v) => trim($v) !== ''))))) . '</td>'; } $html .= '</tr>'; } $html .= '</table>'; }
    }
    return [$html, $trunc];
}
/** Excel: the first sheet as a table. */
function pv_xlsx(string $path): array {
    $rows = xlsx_read($path); $trunc = count($rows) > 200 || ($rows && count($rows[0]) > 30); $html = '<table>'; foreach (array_slice($rows, 0, 200) as $i => $r) { $html .= '<tr>'; foreach (array_slice($r, 0, 30) as $c) $html .= ($i === 0 ? '<th>' : '<td>') . pv_e((string)$c) . ($i === 0 ? '</th>' : '</td>'); $html .= '</tr>'; } return [$html . '</table>', $trunc];
}
/** PowerPoint: the text of each slide. */
function pv_pptx(string $path): array {
    $z = new ZipArchive(); if ($z->open($path) !== true) throw new RuntimeException('That file could not be opened'); $slides = []; for ($i = 0; $i < $z->numFiles; $i++) { $n = $z->getNameIndex($i); if (preg_match('~^ppt/slides/slide(\d+)\.xml$~', $n, $m)) $slides[(int)$m[1]] = $n; } ksort($slides); $html = ''; $k = 0;
    foreach (array_slice($slides, 0, 80, true) as $no => $part) { $xml = (string)$z->getFromName($part); preg_match_all('~<a:t>([^<]*)</a:t>~', $xml, $mm); $lines = array_map(fn($t) => html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8'), $mm[1]); $html .= '<h3>Slide ' . (++$k) . '</h3>' . ($lines ? '<p>' . nl2br(pv_e(implode("\n", $lines))) . '</p>' : '<p class="muted">(no text)</p>'); }
    $z->close(); return [$html, count($slides) > 80];
}

if (!isset($r) || $r !== 'preview') return;
$u = need_user(); if ($method !== 'POST') fail('POST only', 405); $b = body(); $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 200); $data = base64_decode((string)($b['data'] ?? ''), true);
if ($data === false || $data === '' || strlen($data) > 12 * 1048576) fail('Choose a file up to 12 MB');
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)); if (!in_array($ext, ['docx', 'xlsx', 'pptx'], true)) fail('No preview for this kind of file', 415);
$tmp = tempnam(sys_get_temp_dir(), 'pv'); file_put_contents($tmp, $data);
try { [$html, $trunc] = $ext === 'docx' ? pv_docx($tmp) : ($ext === 'xlsx' ? pv_xlsx($tmp) : pv_pptx($tmp)); }
catch (Throwable $e) { @unlink($tmp); fail('This file could not be previewed: ' . mb_substr($e->getMessage(), 0, 120), 422); }
@unlink($tmp); out(['kind' => 'html', 'html' => $html !== '' ? $html : '<p class="muted">Nothing to show.</p>', 'truncated' => (bool)$trunc]);
