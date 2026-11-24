<?php
declare(strict_types=1);
// Letters: clean the text people write, and turn it into a real Word (.docx) file that can be edited in Word.
// The letterhead picture sits behind the text on every page; the paddings become the page margins, so the writing never runs into the artwork.

/** Allowed look of a piece of text. Only these few properties are ever kept. */
function ltr_style(string $css): string {
    $keep = [];
    foreach (explode(';', $css) as $decl) {
        $p = explode(':', $decl, 2); if (count($p) < 2) continue; $k = strtolower(trim($p[0])); $v = strtolower(trim($p[1]));
        if ($k === 'text-align' && in_array($v, ['left', 'right', 'center', 'justify'], true)) $keep[] = "text-align:$v";
        elseif ($k === 'font-weight' && ($v === 'bold' || $v === 'normal' || $v === '700' || $v === '600')) $keep[] = 'font-weight:' . ($v === 'normal' ? 'normal' : 'bold');
        elseif ($k === 'font-style' && in_array($v, ['italic', 'normal'], true)) $keep[] = "font-style:$v";
        elseif ($k === 'text-decoration' || $k === 'text-decoration-line') { $d = []; if (str_contains($v, 'underline')) $d[] = 'underline'; if (str_contains($v, 'line-through')) $d[] = 'line-through'; if ($d) $keep[] = 'text-decoration:' . implode(' ', $d); }
        elseif ($k === 'color' && preg_match('/^#[0-9a-f]{3,6}$/', $v)) $keep[] = "color:$v";
        elseif ($k === 'color' && preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/', $v, $m)) $keep[] = sprintf('color:#%02x%02x%02x', min(255, (int)$m[1]), min(255, (int)$m[2]), min(255, (int)$m[3]));
        elseif ($k === 'font-size' && preg_match('/^(\d{1,2}(\.\d)?)(pt|px)$/', $v, $m)) { $pt = $m[3] === 'px' ? (float)$m[1] * 0.75 : (float)$m[1]; if ($pt >= 6 && $pt <= 48) $keep[] = 'font-size:' . round($pt, 1) . 'pt'; }
        elseif ($k === 'font-family') { foreach (['arial' => 'Arial', 'calibri' => 'Calibri', 'times' => 'Times New Roman', 'georgia' => 'Georgia', 'verdana' => 'Verdana', 'courier' => 'Courier New'] as $n => $f) if (str_contains($v, $n)) { $keep[] = "font-family:$f"; break; } }
        elseif ($k === 'margin-left' && preg_match('/^(\d{1,3})(px|pt)$/', $v, $m) && (int)$m[1] <= 200) $keep[] = 'margin-left:' . $m[1] . $m[2];
    }
    return implode(';', $keep);
}
/** The letter text as safe, simple HTML (no scripts, no pictures, no odd attributes). */
function ltr_clean(string $html): string {
    $html = mb_substr($html, 0, 400000); if (trim(strip_tags($html)) === '' && !preg_match('/<table/i', $html)) return '';
    $d = new DOMDocument('1.0', 'UTF-8'); libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8"><div id="ltr-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET); libxml_clear_errors();
    $root = $d->getElementById('ltr-root'); if (!$root) return '';
    $ok = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'h1', 'h2', 'h3', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'span', 'div', 'a', 'blockquote', 'hr', 'sub', 'sup'];
    $walk = function (DOMNode $n) use (&$walk, $ok, $d): string {
        $out = '';
        foreach (iterator_to_array($n->childNodes) as $c) {
            if ($c instanceof DOMText) { $out .= htmlspecialchars($c->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); continue; }
            if (!($c instanceof DOMElement)) continue;
            $t = strtolower($c->tagName);
            if (in_array($t, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'form', 'input', 'img'], true)) continue;
            if ($t === 'font') { $css = []; if ($c->getAttribute('color') !== '') $css[] = 'color:' . $c->getAttribute('color'); $inner = $walk($c); $st = ltr_style(implode(';', $css)); $out .= $st !== '' ? '<span style="' . $st . '">' . $inner . '</span>' : $inner; continue; }
            if (!in_array($t, $ok, true)) { $out .= $walk($c); continue; }
            if ($t === 'br' || $t === 'hr') { $out .= "<$t>"; continue; }
            $attr = ''; $st = ltr_style($c->getAttribute('style')); if ($st !== '') $attr .= ' style="' . $st . '"';
            if ($t === 'a') { $h = trim($c->getAttribute('href')); if (preg_match('~^(https?://|mailto:)[^\s"<>]+$~i', $h)) $attr .= ' href="' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '"'; }
            if (($t === 'td' || $t === 'th') && ctype_digit($c->getAttribute('colspan') ?: 'x') && (int)$c->getAttribute('colspan') <= 12) $attr .= ' colspan="' . (int)$c->getAttribute('colspan') . '"';
            $out .= "<$t$attr>" . $walk($c) . "</$t>";
        }
        return $out;
    };
    return trim($walk($root));
}

/* ---------- .docx ---------- */
function ltr_x(string $s): string { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function ltr_mm(float $mm): int { return (int)round($mm * 56.6929); }

class LtrDocx {
    public string $xml = ''; public array $links = []; public array $nums = [];   // nums: numId => 'bullet'|'decimal'
    private int $rid = 10;
    /** run properties inherited down the tree: b,i,u,s,color,sz,font,sup,sub */
    private function rpr(array $f): string {
        $o = '';
        if (!empty($f['font'])) $o .= '<w:rFonts w:ascii="' . ltr_x($f['font']) . '" w:hAnsi="' . ltr_x($f['font']) . '" w:cs="' . ltr_x($f['font']) . '"/>';
        if (!empty($f['b'])) $o .= '<w:b/><w:bCs/>'; if (!empty($f['i'])) $o .= '<w:i/><w:iCs/>'; if (!empty($f['s'])) $o .= '<w:strike/>';
        if (!empty($f['color'])) $o .= '<w:color w:val="' . strtoupper(ltrim($f['color'], '#')) . '"/>';
        if (!empty($f['sz'])) $o .= '<w:sz w:val="' . (int)round($f['sz'] * 2) . '"/><w:szCs w:val="' . (int)round($f['sz'] * 2) . '"/>';
        if (!empty($f['u'])) $o .= '<w:u w:val="single"/>'; if (!empty($f['sup'])) $o .= '<w:vertAlign w:val="superscript"/>'; if (!empty($f['sub'])) $o .= '<w:vertAlign w:val="subscript"/>';
        return $o === '' ? '' : "<w:rPr>$o</w:rPr>";
    }
    private function fmt(DOMElement $e, array $f): array {
        $t = strtolower($e->tagName);
        if ($t === 'b' || $t === 'strong') $f['b'] = 1; if ($t === 'i' || $t === 'em') $f['i'] = 1; if ($t === 'u') $f['u'] = 1; if ($t === 's' || $t === 'strike') $f['s'] = 1;
        if ($t === 'sup') $f['sup'] = 1; if ($t === 'sub') $f['sub'] = 1; if ($t === 'a') { $f['u'] = 1; $f['color'] = '0563C1'; }
        if (preg_match('/^h([123])$/', $t, $m)) { $f['b'] = 1; $f['sz'] = [1 => 16, 2 => 14, 3 => 12][(int)$m[1]]; }
        foreach (explode(';', $e->getAttribute('style')) as $d) { $p = explode(':', $d, 2); if (count($p) < 2) continue; [$k, $v] = [trim($p[0]), trim($p[1])];
            if ($k === 'font-weight') $f['b'] = $v === 'bold' ? 1 : 0; elseif ($k === 'font-style') $f['i'] = $v === 'italic' ? 1 : 0; elseif ($k === 'text-decoration') { $f['u'] = str_contains($v, 'underline') ? 1 : ($f['u'] ?? 0); $f['s'] = str_contains($v, 'line-through') ? 1 : ($f['s'] ?? 0); }
            elseif ($k === 'color') $f['color'] = $v; elseif ($k === 'font-size') $f['sz'] = (float)$v; elseif ($k === 'font-family') $f['font'] = $v; }
        return $f;
    }
    private function runs(DOMNode $n, array $f, string &$out): void {
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) { $txt = (string)$c->nodeValue; if ($txt === '') continue; $out .= '<w:r>' . $this->rpr($f) . '<w:t xml:space="preserve">' . ltr_x($txt) . '</w:t></w:r>'; continue; }
            if (!($c instanceof DOMElement)) continue; $t = strtolower($c->tagName);
            if ($t === 'br') { $out .= '<w:r><w:br/></w:r>'; continue; }
            if ($t === 'a' && $c->getAttribute('href') !== '') { $id = 'rIdL' . (++$this->rid); $this->links[$id] = $c->getAttribute('href'); $in = ''; $this->runs($c, $this->fmt($c, $f), $in); $out .= '<w:hyperlink r:id="' . $id . '" w:history="1">' . $in . '</w:hyperlink>'; continue; }
            if (in_array($t, ['p', 'div', 'li', 'ul', 'ol', 'table', 'blockquote', 'h1', 'h2', 'h3'], true)) { continue; }
            $this->runs($c, $this->fmt($c, $f), $out);
        }
    }
    private function para(DOMElement $e, array $f, string $ppr = '', string $prefix = ''): string {
        $al = ''; if (preg_match('/text-align:(left|right|center|justify)/', $e->getAttribute('style'), $m)) $al = '<w:jc w:val="' . ($m[1] === 'justify' ? 'both' : $m[1]) . '"/>';
        $left = (int)($f['ind'] ?? 0); if (preg_match('/margin-left:(\d+)(px|pt)/', $e->getAttribute('style'), $m)) $left += (int)round((float)$m[1] * ($m[2] === 'px' ? 15 : 20)); $ind = $left > 0 ? '<w:ind w:left="' . $left . '"/>' : '';
        $r = ''; $this->runs($e, $this->fmt($e, $f), $r);
        return '<w:p><w:pPr>' . $ppr . '<w:spacing w:after="120" w:line="276" w:lineRule="auto"/>' . $ind . $al . '</w:pPr>' . $prefix . $r . '</w:p>';
    }
    /** Block level: p, div (with text), headings, lists, tables, quotes. Text that sits loose between blocks is gathered into a paragraph. */
    public function blocks(DOMNode $n, array $f, int $lvl = 0, ?array $list = null): string {
        $out = ''; $loose = null;
        $flush = function () use (&$loose, &$out, $f) { if ($loose !== null && trim(strip_tags($loose->ownerDocument->saveHTML($loose))) !== '') { $out .= $this->para($loose, $f); } $loose = null; };
        foreach ($n->childNodes as $c) {
            $blk = $c instanceof DOMElement && in_array(strtolower($c->tagName), ['p', 'div', 'ul', 'ol', 'table', 'blockquote', 'h1', 'h2', 'h3', 'hr'], true);
            if (!$blk) { if ($loose === null) { $loose = $n->ownerDocument->createElement('p'); } $loose->appendChild($c->cloneNode(true)); continue; }
            $flush(); $t = strtolower($c->tagName);
            if ($t === 'div') { $hasBlock = false; foreach ($c->childNodes as $cc) if ($cc instanceof DOMElement && in_array(strtolower($cc->tagName), ['p', 'div', 'ul', 'ol', 'table', 'blockquote', 'h1', 'h2', 'h3'], true)) $hasBlock = true;
                $out .= $hasBlock ? $this->blocks($c, $this->fmt($c, $f), $lvl) : $this->para($c, $f); }
            elseif ($t === 'p' || $t === 'h1' || $t === 'h2' || $t === 'h3') $out .= $this->para($c, $f);
            elseif ($t === 'blockquote') { $g = $f; $g['ind'] = ($f['ind'] ?? 0) + 720; $out .= $this->blocks($c, $g, $lvl + 1); }
            elseif ($t === 'hr') $out .= '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="999999"/></w:pBdr><w:spacing w:after="120"/></w:pPr></w:p>';
            elseif ($t === 'ul' || $t === 'ol') {
                $numId = count($this->nums) + 1; $this->nums[$numId] = $t === 'ol' ? 'decimal' : 'bullet';
                foreach ($c->childNodes as $li) { if (!($li instanceof DOMElement) || strtolower($li->tagName) !== 'li') continue;
                    $ppr = '<w:numPr><w:ilvl w:val="' . min($lvl, 3) . '"/><w:numId w:val="' . $numId . '"/></w:numPr>'; $r = ''; $nested = '';
                    foreach ($li->childNodes as $x) { if ($x instanceof DOMElement && in_array(strtolower($x->tagName), ['ul', 'ol'], true)) { $tmp = $n->ownerDocument->createElement('div'); $tmp->appendChild($x->cloneNode(true)); $nested .= $this->blocks($tmp, $f, $lvl + 1); } }
                    $this->runs($li, $this->fmt($li, $f), $r);
                    $out .= '<w:p><w:pPr>' . $ppr . '<w:spacing w:after="60" w:line="276" w:lineRule="auto"/></w:pPr>' . $r . '</w:p>' . $nested; }
            }
            elseif ($t === 'table') $out .= $this->table($c, $f);
        }
        $flush(); return $out;
    }
    private function table(DOMElement $t, array $f): string {
        $rows = ''; $cols = 1;
        foreach ($t->getElementsByTagName('tr') as $tr) { $n = 0; foreach ($tr->childNodes as $c) if ($c instanceof DOMElement && in_array(strtolower($c->tagName), ['td', 'th'], true)) $n += max(1, (int)$c->getAttribute('colspan')); $cols = max($cols, $n); }
        $grid = ''; for ($i = 0; $i < $cols; $i++) $grid .= '<w:gridCol w:w="' . (int)(9000 / $cols) . '"/>';
        foreach ($t->getElementsByTagName('tr') as $tr) { $cells = '';
            foreach ($tr->childNodes as $c) { if (!($c instanceof DOMElement) || !in_array(strtolower($c->tagName), ['td', 'th'], true)) continue; $span = max(1, (int)$c->getAttribute('colspan')); $hd = strtolower($c->tagName) === 'th';
                $inner = $this->blocks($c, $hd ? $f + ['b' => 1] : $f); if ($inner === '') $inner = '<w:p/>';
                $cells .= '<w:tc><w:tcPr><w:tcW w:w="' . (int)(9000 / $cols * $span) . '" w:type="dxa"/>' . ($span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '') . '</w:tcPr>' . $inner . '</w:tc>'; }
            if ($cells !== '') $rows .= '<w:tr>' . $cells . '</w:tr>'; }
        if ($rows === '') return '';
        $b = '<w:top w:val="single" w:sz="4" w:color="888888"/><w:left w:val="single" w:sz="4" w:color="888888"/><w:bottom w:val="single" w:sz="4" w:color="888888"/><w:right w:val="single" w:sz="4" w:color="888888"/><w:insideH w:val="single" w:sz="4" w:color="888888"/><w:insideV w:val="single" w:sz="4" w:color="888888"/>';
        return '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders>' . $b . '</w:tblBorders><w:tblCellMar><w:left w:w="100" w:type="dxa"/><w:right w:w="100" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>' . $grid . '</w:tblGrid>' . $rows . '</w:tbl><w:p><w:pPr><w:spacing w:after="60"/></w:pPr></w:p>';
    }
}

/**
 * Build the .docx. $art = ['data'=>bytes,'mime'=>'image/png'] or null for plain paper. $pad = mm: top, bottom, left, right.
 * Returns the file contents.
 */
function ltr_docx(string $title, string $html, ?array $art, array $pad): string {
    $d = new DOMDocument('1.0', 'UTF-8'); libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8"><div id="r">' . ltr_clean($html) . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET); libxml_clear_errors();
    $dx = new LtrDocx(); $body = $dx->blocks($d->getElementById('r'), []); if ($body === '') $body = '<w:p/>';
    [$top, $bot, $lft, $rgt] = [ltr_mm((float)$pad[0]), ltr_mm((float)$pad[1]), ltr_mm((float)$pad[2]), ltr_mm((float)$pad[3])];
    $hasArt = $art && !empty($art['data']); $ext = $hasArt && ($art['mime'] ?? '') === 'image/jpeg' ? 'jpeg' : 'png';
    $sect = '<w:sectPr>' . ($hasArt ? '<w:headerReference w:type="default" r:id="rIdH1"/>' : '') . '<w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="' . $top . '" w:right="' . $rgt . '" w:bottom="' . $bot . '" w:left="' . $lft . '" w:header="0" w:footer="0" w:gutter="0"/></w:sectPr>';
    $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
    $hdr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr ' . $ns . '><w:p><w:pPr><w:spacing w:after="0" w:line="20" w:lineRule="exact"/></w:pPr><w:r><w:drawing><wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="0" behindDoc="1" locked="0" layoutInCell="1" allowOverlap="1"><wp:simplePos x="0" y="0"/>'
        . '<wp:positionH relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionH><wp:positionV relativeFrom="page"><wp:posOffset>0</wp:posOffset></wp:positionV><wp:extent cx="7560000" cy="10692000"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/><wp:docPr id="1" name="Letterhead"/><wp:cNvGraphicFramePr/>'
        . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="1" name="letterhead"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rIdImg1"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="7560000" cy="10692000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:anchor></w:drawing></w:r></w:p></w:hdr>';
    $docx = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $body . $sect . '</w:body></w:document>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri" w:eastAsia="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="en-GB"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style></w:styles>';
    $abs = '';
    foreach (['bullet' => ['•', 'Symbol'], 'decimal' => ['%1.', '']] as $k => [$txt, $font]) { $i = $k === 'bullet' ? 0 : 1; $lv = '';
        for ($l = 0; $l < 4; $l++) $lv .= '<w:lvl w:ilvl="' . $l . '"><w:start w:val="1"/><w:numFmt w:val="' . ($k === 'bullet' ? 'bullet' : 'decimal') . '"/><w:lvlText w:val="' . ($k === 'bullet' ? '•' : '%' . ($l + 1) . '.') . '"/><w:lvlJc w:val="left"/><w:pPr><w:ind w:left="' . (720 + 360 * $l) . '" w:hanging="360"/></w:pPr></w:lvl>';
        $abs .= '<w:abstractNum w:abstractNumId="' . $i . '"><w:multiLevelType w:val="hybridMultilevel"/>' . $lv . '</w:abstractNum>'; }
    $nums = ''; foreach ($dx->nums as $id => $kind) $nums .= '<w:num w:numId="' . $id . '"><w:abstractNumId w:val="' . ($kind === 'bullet' ? 0 : 1) . '"/>' . ($kind === 'decimal' ? '<w:lvlOverride w:ilvl="0"><w:startOverride w:val="1"/></w:lvlOverride>' : '') . '</w:num>';
    $numbering = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' . $abs . $nums . '</w:numbering>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rIdN" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
        . ($hasArt ? '<Relationship Id="rIdH1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>' : '');
    foreach ($dx->links as $id => $url) $rels .= '<Relationship Id="' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . ltr_x($url) . '" TargetMode="External"/>';
    $rels .= '</Relationships>';
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
        . ($hasArt ? '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>' : '') . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>';
    $root = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>';
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . ltr_x($title) . '</dc:title><dc:creator>Aerosub Office</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>';
    $tmp = tempnam(sys_get_temp_dir(), 'ltr'); $z = new ZipArchive(); if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not build the Word file');
    $z->addFromString('[Content_Types].xml', $ct); $z->addFromString('_rels/.rels', $root); $z->addFromString('docProps/core.xml', $core); $z->addFromString('word/document.xml', $docx); $z->addFromString('word/styles.xml', $styles); $z->addFromString('word/numbering.xml', $numbering); $z->addFromString('word/_rels/document.xml.rels', $rels);
    if ($hasArt) { $z->addFromString('word/header1.xml', $hdr); $z->addFromString('word/_rels/header1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdImg1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/letterhead.' . $ext . '"/></Relationships>'); $z->addFromString('word/media/letterhead.' . $ext, $art['data']); }
    $z->close(); $bytes = (string)file_get_contents($tmp); @unlink($tmp); return $bytes;
}
