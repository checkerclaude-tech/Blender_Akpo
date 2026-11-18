<?php
declare(strict_types=1);
// Scrabble for 2 to 4 players: standard 100 tiles, 15x15 board with the usual premium squares, 7-tile racks, 50-point bonus for using all seven.
// The server checks the geometry (one line, no gaps, connected, centre on the first move) and scores every word formed.
// There is no built-in dictionary: players challenge words they doubt. The challenged player may take the move back or keep it
// (a wrong challenge costs the challenger their turn). Blank tiles are stored on the board as lowercase letters.
const SCR_DIST = ['A' => 9, 'B' => 2, 'C' => 2, 'D' => 4, 'E' => 12, 'F' => 2, 'G' => 3, 'H' => 2, 'I' => 9, 'J' => 1, 'K' => 1, 'L' => 4, 'M' => 2, 'N' => 6, 'O' => 8, 'P' => 2, 'Q' => 1, 'R' => 6, 'S' => 4, 'T' => 6, 'U' => 4, 'V' => 2, 'W' => 2, 'X' => 1, 'Y' => 2, 'Z' => 1, '?' => 2];
const SCR_VAL = ['A' => 1, 'B' => 3, 'C' => 3, 'D' => 2, 'E' => 1, 'F' => 4, 'G' => 2, 'H' => 4, 'I' => 1, 'J' => 8, 'K' => 5, 'L' => 1, 'M' => 3, 'N' => 1, 'O' => 1, 'P' => 3, 'Q' => 10, 'R' => 1, 'S' => 1, 'T' => 1, 'U' => 1, 'V' => 4, 'W' => 4, 'X' => 8, 'Y' => 4, 'Z' => 10, '?' => 0];
const SCR_TW = [[0, 0], [0, 7], [0, 14], [7, 0], [7, 14], [14, 0], [14, 7], [14, 14]];
const SCR_DW = [[1, 1], [2, 2], [3, 3], [4, 4], [1, 13], [2, 12], [3, 11], [4, 10], [13, 1], [12, 2], [11, 3], [10, 4], [13, 13], [12, 12], [11, 11], [10, 10], [7, 7]];
const SCR_TL = [[1, 5], [1, 9], [5, 1], [5, 5], [5, 9], [5, 13], [9, 1], [9, 5], [9, 9], [9, 13], [13, 5], [13, 9]];
const SCR_DL = [[0, 3], [0, 11], [2, 6], [2, 8], [3, 0], [3, 7], [3, 14], [6, 2], [6, 6], [6, 8], [6, 12], [7, 3], [7, 11], [8, 2], [8, 6], [8, 8], [8, 12], [11, 0], [11, 7], [11, 14], [12, 6], [12, 8], [14, 3], [14, 11]];

function scr_prem(): array { static $m = null; if ($m !== null) return $m; $m = [];
    foreach (['TW' => SCR_TW, 'DW' => SCR_DW, 'TL' => SCR_TL, 'DL' => SCR_DL] as $k => $list) foreach ($list as [$r, $c]) $m[$r * 15 + $c] = $k; return $m; }
function scr_val(string $ch): int { return ctype_lower($ch) ? 0 : (SCR_VAL[$ch] ?? 0); }
function scr_setup(array $ids, array $o): array {
    $bag = []; foreach (SCR_DIST as $l => $n) for ($i = 0; $i < $n; $i++) $bag[] = $l; shuffle($bag);
    $racks = []; $scores = []; foreach ($ids as $i => $_) { $racks[$i] = array_splice($bag, 0, 7); $scores[$i] = 0; }
    return ['n' => count($ids), 'board' => array_fill(0, 225, ''), 'bag' => $bag, 'racks' => $racks, 'scores' => $scores, 'turn' => 0, 'passes' => 0, 'first' => true, 'last' => null, 'prev' => null, 'challenge' => null, 'over' => false, 'winners' => [], 'result' => '', 'log' => []];
}
function scr_word(array $b, int $r, int $c, int $dr, int $dc): array { // the full word through (r,c) along a direction: [text, cells]
    while ($r - $dr >= 0 && $c - $dc >= 0 && $b[($r - $dr) * 15 + $c - $dc] !== '') { $r -= $dr; $c -= $dc; }
    $cells = []; $txt = ''; while ($r < 15 && $c < 15 && $b[$r * 15 + $c] !== '') { $cells[] = [$r, $c]; $txt .= strtoupper($b[$r * 15 + $c]); $r += $dr; $c += $dc; }
    return [$txt, $cells];
}
function scr_score_word(array $b, array $cells, array $newSet): int {
    $prem = scr_prem(); $sum = 0; $wm = 1;
    foreach ($cells as [$r, $c]) { $i = $r * 15 + $c; $v = scr_val($b[$i]); if (isset($newSet[$i])) { $p = $prem[$i] ?? ''; if ($p === 'DL') $v *= 2; elseif ($p === 'TL') $v *= 3; elseif ($p === 'DW') $wm *= 2; elseif ($p === 'TW') $wm *= 3; } $sum += $v; }
    return $sum * $wm;
}
function scr_end(array &$st, string $why): void {
    $st['over'] = true; $st['result'] = $why;
    foreach ($st['racks'] as $s => $rack) { $left = array_sum(array_map(fn($t) => SCR_VAL[$t] ?? 0, $rack)); $st['scores'][$s] -= $left; }
    $out = array_search(0, array_map('count', $st['racks']), true); if ($out !== false) { $bonus = 0; foreach ($st['racks'] as $s => $rack) if ($s !== $out) $bonus += array_sum(array_map(fn($t) => SCR_VAL[$t] ?? 0, $rack)); $st['scores'][$out] += $bonus; }
    $max = max($st['scores']); $st['winners'] = array_keys(array_filter($st['scores'], fn($x) => $x === $max));
}
function scr_next(array &$st, int $seat): void { $st['turn'] = ($seat + 1) % $st['n']; }
function scr_move(array &$st, int $seat, array $mv): void {
    if ($st['over']) throw new RuntimeException('This game is over');
    if ($st['turn'] !== $seat) throw new RuntimeException('It is not your turn');
    $type = (string)($mv['type'] ?? ''); $who = 'P' . ($seat + 1);
    if ($st['challenge']) { // someone doubted the last word: only the player who played it can answer
        if ($type !== 'answer') throw new RuntimeException('Answer the challenge first: take your word back or keep it');
        $ch = $st['challenge']; $st['challenge'] = null;
        if (!empty($mv['take_back'])) { $keep = $st['prev']; $keep['log'] = $st['log']; $keep['log'][] = $who . ' took the word back'; $keep['turn'] = $ch['by']; $keep['challenge'] = null; $keep['prev'] = null; $keep['last'] = null; $st = $keep; return; }
        $st['log'][] = $who . ' kept the word; the challenge failed and P' . ($ch['by'] + 1) . ' loses a turn'; $st['prev'] = null; $st['turn'] = ($ch['by'] + 1) % $st['n']; $st['passes'] = 0; return;
    }
    if ($type === 'challenge') {
        if (!$st['prev'] || !$st['last'] || $st['last']['seat'] === $seat || $st['last']['type'] !== 'play') throw new RuntimeException('There is no word to challenge');
        $st['challenge'] = ['by' => $seat, 'on' => $st['last']['seat'], 'words' => $st['last']['words']]; $st['log'][] = $who . ' challenged ' . implode(', ', $st['last']['words']); $st['turn'] = $st['last']['seat']; return;
    }
    if ($type === 'pass') { $st['passes']++; $st['log'][] = $who . ' passed'; $st['last'] = ['seat' => $seat, 'type' => 'pass']; $st['prev'] = null; if ($st['passes'] >= $st['n'] * 2) { scr_end($st, 'Everyone passed twice'); return; } scr_next($st, $seat); return; }
    if ($type === 'exchange') {
        $letters = array_map('strval', (array)($mv['letters'] ?? [])); if (!$letters) throw new RuntimeException('Choose tiles to exchange'); if (count($st['bag']) < 7) throw new RuntimeException('Fewer than seven tiles are left in the bag, so you cannot exchange');
        $rack = $st['racks'][$seat]; foreach ($letters as $l) { $i = array_search($l, $rack, true); if ($i === false) throw new RuntimeException('You do not have those tiles'); array_splice($rack, (int)$i, 1); }
        $new = array_splice($st['bag'], 0, count($letters)); $st['bag'] = array_merge($st['bag'], $letters); shuffle($st['bag']); $st['racks'][$seat] = array_merge($rack, $new);
        $st['passes']++; $st['log'][] = $who . ' exchanged ' . count($letters) . ' tile(s)'; $st['last'] = ['seat' => $seat, 'type' => 'exchange']; $st['prev'] = null; scr_next($st, $seat); return;
    }
    if ($type !== 'play') throw new RuntimeException('Unknown action');
    $tiles = (array)($mv['tiles'] ?? []); if (!$tiles || count($tiles) > 7) throw new RuntimeException('Place between one and seven tiles');
    $b = $st['board']; $rack = $st['racks'][$seat]; $placed = []; $set = [];
    foreach ($tiles as $t) {
        $r = (int)($t['r'] ?? -1); $c = (int)($t['c'] ?? -1); $l = strtoupper((string)($t['l'] ?? '')); $blank = !empty($t['blank']);
        if ($r < 0 || $r > 14 || $c < 0 || $c > 14 || !preg_match('/^[A-Z]$/', $l)) throw new RuntimeException('A tile is not placed correctly');
        $i = $r * 15 + $c; if ($b[$i] !== '' || isset($set[$i])) throw new RuntimeException('That square is already taken');
        $need = $blank ? '?' : $l; $k = array_search($need, $rack, true); if ($k === false) throw new RuntimeException('You do not have the tile ' . ($blank ? 'blank' : $l)); array_splice($rack, (int)$k, 1);
        $placed[] = ['r' => $r, 'c' => $c, 'i' => $i, 'ch' => $blank ? strtolower($l) : $l]; $set[$i] = true;
    }
    $rows = array_unique(array_column($placed, 'r')); $cols = array_unique(array_column($placed, 'c'));
    if (count($rows) > 1 && count($cols) > 1) throw new RuntimeException('Tiles must all be in one row or one column');
    foreach ($placed as $p) $b[$p['i']] = $p['ch'];
    $horizontal = count($rows) === 1 && (count($cols) > 1 || true); if (count($placed) === 1) $horizontal = true;
    // no gaps along the line
    if (count($placed) > 1) { if (count($rows) === 1) { $r = $rows[array_key_first($rows)]; $min = min($cols); $max = max($cols); for ($c = $min; $c <= $max; $c++) if ($b[$r * 15 + $c] === '') throw new RuntimeException('There is a gap between your tiles'); }
        else { $c = $cols[array_key_first($cols)]; $min = min($rows); $max = max($rows); for ($r = $min; $r <= $max; $r++) if ($b[$r * 15 + $c] === '') throw new RuntimeException('There is a gap between your tiles'); } }
    // connection
    if ($st['first']) { if (!isset($set[7 * 15 + 7])) throw new RuntimeException('The first word must cover the centre star'); if (count($placed) < 2) throw new RuntimeException('The first word needs at least two tiles'); }
    else { $touch = false; foreach ($placed as $p) foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dr, $dc]) { $rr = $p['r'] + $dr; $cc = $p['c'] + $dc; if ($rr >= 0 && $rr < 15 && $cc >= 0 && $cc < 15 && $b[$rr * 15 + $cc] !== '' && !isset($set[$rr * 15 + $cc])) $touch = true; }
        if (!$touch) throw new RuntimeException('Your word must connect to tiles already on the board'); }
    // words formed
    $words = []; $total = 0; $seen = [];
    $addWord = function (int $r, int $c, int $dr, int $dc) use (&$words, &$total, &$seen, $b, $set) { [$txt, $cells] = scr_word($b, $r, $c, $dr, $dc); if (count($cells) < 2) return; $key = $dr . ',' . $dc . ':' . $cells[0][0] . ',' . $cells[0][1]; if (isset($seen[$key])) return; $seen[$key] = 1; $words[] = $txt; $total += scr_score_word($b, $cells, $set); };
    if (count($placed) === 1) { $addWord($placed[0]['r'], $placed[0]['c'], 0, 1); $addWord($placed[0]['r'], $placed[0]['c'], 1, 0); }
    elseif (count($rows) === 1) { $addWord($placed[0]['r'], $placed[0]['c'], 0, 1); foreach ($placed as $p) $addWord($p['r'], $p['c'], 1, 0); }
    else { $addWord($placed[0]['r'], $placed[0]['c'], 1, 0); foreach ($placed as $p) $addWord($p['r'], $p['c'], 0, 1); }
    if (!$words) throw new RuntimeException('Your tiles must form a word of at least two letters');
    $bingo = count($placed) === 7; if ($bingo) $total += 50;
    $prev = $st; unset($prev['prev']); // so a challenge can undo this move
    $draw = array_splice($st['bag'], 0, min(count($placed), count($st['bag']))); $st['racks'][$seat] = array_merge($rack, $draw); $st['board'] = $b; $st['scores'][$seat] += $total; $st['first'] = false; $st['passes'] = 0;
    $st['last'] = ['seat' => $seat, 'type' => 'play', 'words' => $words, 'score' => $total, 'bingo' => $bingo, 'cells' => array_column($placed, 'i')]; $st['prev'] = $prev;
    $st['log'][] = $who . ' played ' . implode(', ', $words) . ' for ' . $total . ($bingo ? ' (all seven tiles: +50)' : ''); if (count($st['log']) > 120) array_shift($st['log']);
    if (!$st['racks'][$seat] && !$st['bag']) { $st['prev'] = null; scr_end($st, 'A player used their last tile'); return; }
    scr_next($st, $seat);
}
function scr_view(array $st, int $seat): array {
    $v = ['n' => $st['n'], 'board' => $st['board'], 'scores' => $st['scores'], 'turn' => $st['turn'], 'bag' => count($st['bag']), 'counts' => array_map('count', $st['racks']), 'first' => $st['first'], 'last' => $st['last'] ? array_diff_key($st['last'], []) : null, 'over' => $st['over'], 'winners' => $st['winners'], 'result' => $st['result'], 'log' => array_slice($st['log'], -40),
           'rack' => $seat >= 0 ? $st['racks'][$seat] : [], 'challenge' => $st['challenge'], 'can_challenge' => false, 'values' => SCR_VAL];
    $v['can_challenge'] = !$st['over'] && !$st['challenge'] && $st['prev'] && $st['last'] && $st['last']['type'] === 'play' && $st['last']['seat'] !== $seat && $st['turn'] === $seat;
    $v['premium'] = (object)scr_prem(); if ($st['over']) $v['racks'] = $st['racks']; return $v;
}
