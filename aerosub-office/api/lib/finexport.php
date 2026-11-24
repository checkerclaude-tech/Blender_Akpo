<?php
declare(strict_types=1);
// Excel copy of a finance document (GET /api/finance/doc/{id}/export). The commercial follows the Aerosub commercial template:
// lettered sections, a total per section, an estimated total for the whole scope window, standby and optional lines, and a TOTAL row.
// Totals are real Excel formulas, so the sheet can be edited and recalculated in Excel.
require_once __DIR__ . '/xlsx.php';

function fx_xl_sym(array $d): string {
    $s = trim((string)($d['currency_symbol'] ?? '')); if ($s === '') $s = ['NGN' => '₦', 'USD' => '$', 'GBP' => '£', 'EUR' => '€'][strtoupper((string)$d['currency'])] ?? ((string)$d['currency'] . ' ');
    return str_replace(['"', '\\'], '', $s);
}
function fx_export_xlsx(array $d): void {
    $type = (string)$d['doc_type']; $comm = $type === 'commercial'; $days = max(1, (int)($d['days'] ?? 1)); $cur = strtoupper((string)$d['currency']);
    $money = '"' . fx_xl_sym($d) . '"#,##0.00'; $rows = []; $merges = []; $navy = '0B1F5C'; $light = 'BDEBFF';
    $base = ['font' => 'Poppins', 'sz' => 11, 'border' => 'thin', 'v' => 'center'];
    $put = function (int $r, int $c, $v, array $st = [], ?string $f = null) use (&$rows) { $cell = ['v' => $v, 's' => $st]; if ($f !== null) $cell['f'] = $f; $rows[$r]['cells'][$c] = $cell; };
    $span = function (int $r1, int $c1, int $r2, int $c2, $v, array $st = [], ?string $f = null) use (&$put, &$merges) {
        for ($r = $r1; $r <= $r2; $r++) for ($c = $c1; $c <= $c2; $c++) $put($r, $c, ($r === $r1 && $c === $c1) ? $v : null, $st, ($r === $r1 && $c === $c1) ? $f : null);
        if ($r1 !== $r2 || $c1 !== $c2) $merges[] = xlsx_col($c1) . $r1 . ':' . xlsx_col($c2) . $r2; };
    $h = function (int $r, float $ht) use (&$rows) { $rows[$r]['h'] = $ht; };
    $addr = fn(string $col, int $r) => $col . $r;
    $short = fn(string $n) => trim(preg_split('/\s[–-]\s/u', $n)[0]);
    $logo = null; $lp = dirname(__DIR__, 2) . '/assets/img/aerosub-logo.png'; if (is_file($lp)) { $sz = @getimagesize($lp); if ($sz) { $hh = 64; $logo = ['data' => (string)file_get_contents($lp), 'col' => 1, 'row' => 0, 'w' => (int)round($sz[0] * $hh / max(1, $sz[1])), 'h' => $hh]; } }
    $title = trim((string)($d['subject'] ?? '')) ?: ($comm ? 'Commercial offer' : strtoupper($type));
    $line = function (array $i) use ($days, $type): array { $base = round((float)$i['qty'] * (float)$i['unit_price'], 2); if ($type !== 'commercial') return [$base, false, '']; $note = trim((string)($i['amount_note'] ?? '')); if ($note !== '') return [0.0, false, $note];
        $daily = in_array(strtolower(trim((string)($i['freq'] ?? ''))), FX_DAILY, true); return [$base, $daily, '']; };
    $grandRef = null; $grandVal = 0.0;

    if ($comm) {
        $span(5, 1, 5, 10, $title, ['font' => 'Poppins', 'b' => true, 'sz' => 14, 'h' => 'center', 'v' => 'center']); $h(5, 28);
        $span(6, 2, 8, 9, (string)($d['intro'] ?? '') ?: ('Prepared for ' . $d['client_name'] . ($d['rfq_ref'] ? ' · RFQ ' . $d['rfq_ref'] : '') . ' · ' . $d['number']), ['font' => 'Poppins', 'sz' => 11, 'wrap' => true, 'h' => 'center', 'v' => 'center']); foreach ([6, 7, 8] as $r) $h($r, 21);
        $hd = $base + ['b' => true, 'h' => 'center', 'wrap' => true, 'fill' => $light, 'color' => $navy];
        foreach ([2 => 'S/N', 3 => 'Category', 4 => 'Items Description', 5 => 'UOM', 6 => 'QTY', 7 => "Frequency/\nNo of Days", 8 => 'Unit Rate (' . $cur . ')', 9 => 'Total (' . $cur . ')'] as $c => $t) $put(9, $c, $t, $hd); $h(9, 34.5);
        $groups = []; foreach ($d['items'] as $i) { $n = trim((string)($i['category'] ?? '')) ?: 'General'; if (!isset($groups[$n])) $groups[$n] = ['name' => $n, 'items' => []]; $groups[$n]['items'][] = $i; } $groups = array_values($groups);
        foreach ($groups as $gi => &$g) { $g['letter'] = chr(65 + $gi); $g['counted'] = 0; foreach ($g['items'] as $i) if (trim((string)($i['amount_note'] ?? '')) === '') $g['counted']++; } unset($g);
        usort($groups, fn($x, $y) => ((int)($y['counted'] > 0) <=> (int)($x['counted'] > 0)) ?: strcmp($x['letter'], $y['letter']));   // counted sections first, then standby and optional ones
        $r = 10; $subRefs = []; $anyDaily = false; $names = []; $letters = [];
        $writeGroup = function (array $g) use (&$r, &$subRefs, &$anyDaily, &$names, &$put, &$span, &$h, $base, $line, $short, $days, $money, &$grandVal, $light, $navy) {
            $r0 = $r; $first = $r; $est = 0.0; $nd = 0.0;
            foreach ($g['items'] as $i) { [$val, $daily, $note] = $line($i); if ($daily && $note === '') $nd = max($nd, (float)$i['qty']); $m = $base + ['fmt' => $money];
                $put($r, 4, (string)$i['description'], $base + ['wrap' => true]); $put($r, 5, (string)($i['unit'] ?? ''), $base + ['h' => 'center']); $put($r, 6, (float)$i['qty'], $base + ['h' => 'center']); $put($r, 7, (string)($i['freq'] ?? ''), $base + ['h' => 'center']); $put($r, 8, (float)$i['unit_price'], $m);
                if ($note !== '') $put($r, 9, $note, $base + ['h' => 'center']); else { $put($r, 9, $val, $m, 'H' . $r . '*F' . $r); $est += $val; } $h($r, 21.4); $r++; }
            $last = $r - 1;
            if ($g['counted'] > 0) { $ds = $base + ['b' => true, 'h' => 'center', 'wrap' => true, 'fill' => $light, 'color' => $navy];   // one subtotal per category; days run side by side, so the estimate is the longest line
                $label = 'Sub Total for ' . $short($g['name']) . ($nd > 0 ? ' (Estimated ' . rtrim(rtrim(number_format($nd, 2, '.', ''), '0'), '.') . ($nd == 1.0 ? ' Day)' : ' Days)') : '');
                $span($r, 4, $r, 7, $label, $ds); $span($r, 8, $r, 9, $est, $ds + ['fmt' => $money], 'SUM(I' . $first . ':I' . $last . ')'); $subRefs[] = ['H' . $r, $est];
                $h($r, 26); $r++; $names[] = $short($g['name']); }
            $span($r0, 2, $r - 1, 2, $g['letter'], $base + ['b' => true, 'h' => 'center']); $span($r0, 3, $r - 1, 3, (string)$g['name'], $base + ['b' => true, 'h' => 'center', 'wrap' => true]); };
        $counted = array_values(array_filter($groups, fn($g) => $g['counted'] > 0)); $rest = array_values(array_filter($groups, fn($g) => $g['counted'] === 0));
        foreach ($counted as $g) $writeGroup($g);
        $grandVal = (float)array_sum(array_column($subRefs, 1)); $grandRef = $subRefs ? 'SUM(' . implode(',', array_column($subRefs, 0)) . ')' : '0';
        if (count($counted) > 1) { $gs = $base + ['b' => true, 'h' => 'center', 'wrap' => true, 'fill' => 'C6E0B4', 'color' => '000000'];   // the green line: what the total is made of
            $span($r, 2, $r, 3, '', $gs); $span($r, 4, $r, 7, implode(' + ', $names), $gs); $span($r, 8, $r, 9, $grandVal, $gs + ['fmt' => $money], $grandRef); $h($r, 28); $r++; }
        foreach ($rest as $g) $writeGroup($g);
        $disc = min((float)($d['discount'] ?? 0), $grandVal); $taxRate = (float)($d['tax_rate'] ?? 0); $tax = round(($grandVal - $disc) * $taxRate / 100, 2); $ms = $base + ['b' => true, 'h' => 'right'];
        $ref = $grandRef; $taxRef = null; $discRef = null;
        if ($disc > 0) { $span($r, 2, $r, 8, 'Discount', $ms); $put($r, 9, -$disc, $ms + ['fmt' => $money], '-MIN(' . round((float)$d['discount'], 2) . ',' . $ref . ')'); $discRef = 'I' . $r; $r++; }
        if ($taxRate > 0) { $span($r, 2, $r, 8, ($d['tax_label'] ?: 'VAT') . ' (' . rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.') . '%)', $ms); $put($r, 9, $tax, $ms + ['fmt' => $money], '(' . $ref . ($discRef ? '+' . $discRef : '') . ')*' . $taxRate . '/100'); $taxRef = 'I' . $r; $r++; }
        $ts = $base + ['b' => true, 'h' => 'right']; $span($r, 2, $r, 8, 'TOTAL (' . $cur . ')', $ts); $put($r, 9, $grandVal - $disc + $tax, ['font' => 'Poppins', 'sz' => 11, 'border' => 'thin', 'v' => 'center', 'b' => true, 'h' => 'right', 'fill' => 'C6E0B4', 'fmt' => $money], $ref . ($discRef ? '+' . $discRef : '') . ($taxRef ? '+' . $taxRef : '')); $h($r, 25.5);
        $sheet = ['name' => mb_substr($title, 0, 28) ?: 'Commercial', 'cols' => [5.1, 6.2, 15.2, 50.5, 7.5, 7.8, 18.8, 15.1, 19.9, 8.6], 'rows' => $rows, 'merges' => $merges, 'landscape' => true, 'image' => $logo];
    } else {
        $label = ['proforma' => 'PROFORMA INVOICE', 'invoice' => 'INVOICE', 'requisition' => 'REQUISITION', 'receipt' => 'RECEIPT'][$type] ?? strtoupper($type);
        $span(1, 1, 1, 6, $label . ' ' . $d['number'], ['font' => 'Poppins', 'sz' => 14, 'b' => true, 'h' => 'left']); $h(1, 26);
        $span(2, 1, 2, 6, 'To: ' . $d['client_name'] . ($d['client_email'] ? ' · ' . $d['client_email'] : '') . ' · Date: ' . $d['issue_date'], ['font' => 'Poppins', 'sz' => 11, 'h' => 'left']);
        $hd = $base + ['b' => true, 'h' => 'center', 'fill' => $light, 'color' => $navy]; foreach ([1 => 'S/N', 2 => 'Description', 3 => 'Type', 4 => 'UoM', 5 => 'Qty', 6 => 'Unit price', 7 => 'Amount'] as $c => $t) $put(4, $c, $t, $hd);
        $r = 5; $first = 5; $sub = 0.0; foreach ($d['items'] as $n => $i) { $amt = round((float)$i['qty'] * (float)$i['unit_price'], 2); $sub += $amt;
            $put($r, 1, $n + 1, $base + ['h' => 'center']); $put($r, 2, (string)$i['description'], $base + ['wrap' => true]); $put($r, 3, ucfirst(fx_kind($i)), $base + ['h' => 'center']); $put($r, 4, (string)($i['unit'] ?? ''), $base + ['h' => 'center']); $put($r, 5, (float)$i['qty'], $base + ['h' => 'center']);
            $put($r, 6, (float)$i['unit_price'], $base + ['fmt' => $money]); $put($r, 7, $amt, $base + ['fmt' => $money], 'E' . $r . '*F' . $r); $r++; }
        $last = $r - 1; $ms = $base + ['b' => true, 'h' => 'right'];
        $span($r, 1, $r, 6, 'Subtotal', $ms); $put($r, 7, $sub, $ms + ['fmt' => $money], 'SUM(G' . $first . ':G' . $last . ')'); $subRef = 'G' . $r; $r++; $disc = min((float)($d['discount'] ?? 0), $sub); $discRef = null; $taxRef = null;
        if ($disc > 0) { $span($r, 1, $r, 6, 'Discount', $ms); $put($r, 7, -$disc, $ms + ['fmt' => $money], '-MIN(' . round((float)$d['discount'], 2) . ',' . $subRef . ')'); $discRef = 'G' . $r; $r++; }
        $rate = (float)($d['tax_rate'] ?? 0); $tax = round(($sub - $disc) * $rate / 100, 2); if ($rate > 0) { $span($r, 1, $r, 6, ($d['tax_label'] ?: 'VAT') . ' (' . $rate . '%)', $ms); $put($r, 7, $tax, $ms + ['fmt' => $money], '(' . $subRef . ($discRef ? '+' . $discRef : '') . ')*' . $rate . '/100'); $taxRef = 'G' . $r; $r++; }
        $ts = $ms + ['fill' => $navy, 'color' => 'FFFFFF']; $span($r, 1, $r, 6, 'TOTAL (' . $cur . ')', $ts); $put($r, 7, $sub - $disc + $tax, $ts + ['fmt' => $money], $subRef . ($discRef ? '+' . $discRef : '') . ($taxRef ? '+' . $taxRef : ''));
        $sheet = ['name' => $label, 'cols' => [6, 52, 12, 9, 9, 18, 20], 'rows' => $rows, 'merges' => $merges, 'landscape' => false, 'image' => null];
    }
    try { $bin = xlsx_write($sheet); } catch (Throwable $e) { fail($e->getMessage(), 500); }
    $fn = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$d['number'] . '-' . (string)$d['client_name']) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="' . $fn . '"'); header('Content-Length: ' . strlen($bin)); header('X-Content-Type-Options: nosniff'); echo $bin; exit;
}
