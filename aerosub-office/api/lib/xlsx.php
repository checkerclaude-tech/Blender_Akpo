<?php
declare(strict_types=1);
// A small Excel (.xlsx) writer and reader that need nothing but PHP's zip extension.
//
// xlsx_write($sheet) builds a workbook:
//   ['name' => 'Sheet1', 'cols' => [width, ...], 'rows' => [ rowNumber => ['h' => height, 'cells' => [colNumber => ['v' => value, 's' => styleArray]]] ],
//    'merges' => ['E2:G2'], 'landscape' => true, 'image' => ['data' => pngBytes, 'col' => 0, 'row' => 0, 'w' => 160, 'h' => 40]]
//   style keys: b (bold), i, sz, color 'RRGGBB', fill 'RRGGBB', border 'thin'|'medium', h 'left|center|right', v 'top|center', wrap, font
// xlsx_read($path) returns the first sheet as a list of rows (list of strings).

function xlsx_need_zip(): void { if (!class_exists('ZipArchive')) throw new RuntimeException('Excel files need the PHP "zip" extension. Ask your host to enable it, or use a CSV file instead.'); }
function xlsx_col(int $n): string { $s = ''; while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); } return $s; }
function xlsx_esc(string $v): string { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v), ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

function xlsx_write(array $sheet): string {
    xlsx_need_zip();
    $fonts = []; $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>']; $borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>']; $xfs = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'];
    $fontIdx = []; $fillIdx = []; $borderIdx = []; $xfIdx = [];
    $mkFont = function (array $st) use (&$fonts, &$fontIdx): int {
        $x = '<font>' . (!empty($st['b']) ? '<b/>' : '') . (!empty($st['i']) ? '<i/>' : '') . '<sz val="' . ($st['sz'] ?? 11) . '"/><color rgb="FF' . ($st['color'] ?? '000000') . '"/><name val="' . xlsx_esc($st['font'] ?? 'Poppins') . '"/><family val="2"/></font>';
        return $fontIdx[$x] ??= (function () use (&$fonts, $x) { $fonts[] = $x; return count($fonts) - 1; })();
    };
    $style = function (array $st) use (&$fills, &$borders, &$xfs, &$fillIdx, &$borderIdx, &$xfIdx, $mkFont): int {
        $key = json_encode($st); if (isset($xfIdx[$key])) return $xfIdx[$key];
        $f = $mkFont($st); $fi = 0; $bi = 0;
        if (!empty($st['fill'])) { $x = '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $st['fill'] . '"/><bgColor indexed="64"/></patternFill></fill>'; $fi = $fillIdx[$x] ??= (function () use (&$fills, $x) { $fills[] = $x; return count($fills) - 1; })(); }
        if (!empty($st['border'])) { $b = $st['border']; $side = fn($n) => "<$n style=\"$b\"><color rgb=\"FF000000\"/></$n>"; $x = '<border>' . $side('left') . $side('right') . $side('top') . $side('bottom') . '<diagonal/></border>'; $bi = $borderIdx[$x] ??= (function () use (&$borders, $x) { $borders[] = $x; return count($borders) - 1; })(); }
        $al = '<alignment' . (!empty($st['h']) ? ' horizontal="' . $st['h'] . '"' : '') . ' vertical="' . ($st['v'] ?? 'center') . '"' . (!empty($st['wrap']) ? ' wrapText="1"' : '') . '/>';
        $xfs[] = "<xf numFmtId=\"0\" fontId=\"$f\" fillId=\"$fi\" borderId=\"$bi\" xfId=\"0\" applyFont=\"1\" applyFill=\"1\" applyBorder=\"1\" applyAlignment=\"1\">$al</xf>";
        return $xfIdx[$key] = count($xfs) - 1;
    };
    $mkFont([]); // font 0 is the default
    $rowsXml = ''; ksort($sheet['rows']);
    foreach ($sheet['rows'] as $rn => $row) {
        $cells = $row['cells'] ?? []; ksort($cells); $cx = '';
        foreach ($cells as $cn => $c) {
            $ref = xlsx_col((int)$cn) . $rn; $s = $style($c['s'] ?? []); $v = $c['v'] ?? null;
            if ($v === null || $v === '') $cx .= "<c r=\"$ref\" s=\"$s\"/>";
            elseif (is_int($v) || is_float($v)) $cx .= "<c r=\"$ref\" s=\"$s\"><v>$v</v></c>";
            else $cx .= "<c r=\"$ref\" s=\"$s\" t=\"inlineStr\"><is><t xml:space=\"preserve\">" . xlsx_esc((string)$v) . '</t></is></c>';
        }
        $rowsXml .= "<row r=\"$rn\"" . (isset($row['h']) ? ' ht="' . $row['h'] . '" customHeight="1"' : '') . ">$cx</row>";
    }
    $cols = ''; foreach (($sheet['cols'] ?? []) as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    $merges = ''; if (!empty($sheet['merges'])) $merges = '<mergeCells count="' . count($sheet['merges']) . '">' . implode('', array_map(fn($m) => '<mergeCell ref="' . $m . '"/>', $sheet['merges'])) . '</mergeCells>';
    $img = $sheet['image'] ?? null;
    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet ' . $ns . '><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/>' .
        ($cols ? "<cols>$cols</cols>" : '') . "<sheetData>$rowsXml</sheetData>$merges<pageMargins left=\"0.4\" right=\"0.4\" top=\"0.5\" bottom=\"0.5\" header=\"0.3\" footer=\"0.3\"/><pageSetup paperSize=\"9\" orientation=\"" . (!empty($sheet['landscape']) ? 'landscape' : 'portrait') . "\" fitToWidth=\"1\" fitToHeight=\"0\"/>" . ($img ? '<drawing r:id="rId1"/>' : '') . '</worksheet>';
    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts><fills count="' . count($fills) . '">' . implode('', $fills) . '</fills><borders count="' . count($borders) . '">' . implode('', $borders) . '</borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $name = xlsx_esc(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', (string)($sheet['name'] ?? 'Sheet1')), 0, 31));
    $tmp = tempnam(sys_get_temp_dir(), 'xl'); $z = new ZipArchive(); if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the Excel file.');
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . ($img ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '') . '</Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook ' . $ns . '><sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml', $stylesXml);
    $z->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    if ($img) {
        $z->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
        $z->addFromString('xl/drawings/_rels/drawing1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.png"/></Relationships>');
        $emuW = (int)($img['w'] * 9525); $emuH = (int)($img['h'] * 9525);
        $z->addFromString('xl/drawings/drawing1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><xdr:oneCellAnchor><xdr:from><xdr:col>' . (int)$img['col'] . '</xdr:col><xdr:colOff>' . (int)($img['dx'] ?? 76200) . '</xdr:colOff><xdr:row>' . (int)$img['row'] . '</xdr:row><xdr:rowOff>' . (int)($img['dy'] ?? 76200) . '</xdr:rowOff></xdr:from><xdr:ext cx="' . $emuW . '" cy="' . $emuH . '"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Logo"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr><xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $emuW . '" cy="' . $emuH . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>');
        $z->addFromString('xl/media/image1.png', (string)$img['data']);
    }
    $z->close(); $bin = (string)file_get_contents($tmp); @unlink($tmp); return $bin;
}

/** First worksheet of an .xlsx file as rows of strings. */
function xlsx_read(string $path): array {
    xlsx_need_zip();
    $z = new ZipArchive(); if ($z->open($path) !== true) throw new RuntimeException('That does not look like an Excel (.xlsx) file.');
    $shared = [];
    $big = function (string $n) use ($z): bool { $st = $z->statName($n); return $st && $st['size'] > 40 * 1024 * 1024; }; // refuse a spreadsheet that would unpack to something huge
    if ($big('xl/sharedStrings.xml')) { $z->close(); throw new RuntimeException('That spreadsheet is too large.'); }
    if (($ss = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $x = @simplexml_load_string($ss); if ($x) foreach ($x->si as $si) { $t = ''; if (isset($si->t)) $t = (string)$si->t; else foreach ($si->r as $r) $t .= (string)$r->t; $shared[] = $t; }
    }
    $target = 'xl/worksheets/sheet1.xml';
    $wb = $z->getFromName('xl/workbook.xml'); $rels = $z->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false && preg_match('/<sheet\b[^>]*r:id="([^"]+)"/', $wb, $m) && preg_match('/<Relationship\b[^>]*Id="' . preg_quote($m[1], '/') . '"[^>]*>/', $rels, $rm) && preg_match('/Target="([^"]+)"/', $rm[0], $tm))
        $target = 'xl/' . ltrim(preg_replace('#^/?(xl/)?#', '', $tm[1]), '/');
    if ($big($target)) { $z->close(); throw new RuntimeException('That spreadsheet is too large.'); }
    $xml = $z->getFromName($target); if ($xml === false) { $xml = $z->getFromName('xl/worksheets/sheet1.xml'); }
    $z->close(); if ($xml === false) throw new RuntimeException('The spreadsheet has no data.');
    $sx = @simplexml_load_string($xml); if (!$sx) throw new RuntimeException('The spreadsheet could not be read.');
    $rows = []; $maxRows = 5000;
    foreach ($sx->sheetData->row as $row) {
        $rn = (int)$row['r']; if ($rn > $maxRows) break; $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r']; preg_match('/^([A-Z]+)/', $ref, $cm); $ci = 0; foreach (str_split($cm[1] ?? 'A') as $ch) $ci = $ci * 26 + (ord($ch) - 64); $ci--;
            $t = (string)$c['t']; $v = '';
            if ($t === 's') $v = $shared[(int)$c->v] ?? ''; elseif ($t === 'inlineStr') { $v = (string)($c->is->t ?? ''); if ($v === '' && isset($c->is->r)) foreach ($c->is->r as $r) $v .= (string)$r->t; }
            elseif ($t === 'b') $v = ((string)$c->v) === '1' ? 'TRUE' : 'FALSE'; else { $v = (string)$c->v; if ($v !== '' && is_numeric($v) && (float)$v == (int)(float)$v && abs((float)$v) < 1e15) $v = (string)(int)(float)$v; }
            $cells[$ci] = trim($v);
        }
        if ($cells) { $line = []; for ($i = 0, $mx = max(array_keys($cells)); $i <= $mx; $i++) $line[] = $cells[$i] ?? ''; $rows[$rn] = $line; }
    }
    return array_values($rows);
}
