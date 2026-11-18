<?php
declare(strict_types=1);
// Chess. Board index = row*8+col, row 0 is rank 8 (top), col 0 is file a. Pieces are 'wP','bN' ... Seat 0 plays white, seat 1 black.
// Full rules: check, checkmate, stalemate, castling, en passant, promotion, 50-move rule, threefold repetition, insufficient material.

function chess_setup(array $ids, array $o): array {
    $b = array_fill(0, 64, ''); $back = ['R', 'N', 'B', 'Q', 'K', 'B', 'N', 'R'];
    for ($c = 0; $c < 8; $c++) { $b[$c] = 'b' . $back[$c]; $b[8 + $c] = 'bP'; $b[48 + $c] = 'wP'; $b[56 + $c] = 'w' . $back[$c]; }
    $st = ['board' => $b, 'turn' => 0, 'castle' => ['wK' => 1, 'wQ' => 1, 'bK' => 1, 'bQ' => 1], 'ep' => -1, 'half' => 0, 'full' => 1, 'last' => null, 'check' => false, 'over' => false, 'winners' => [], 'result' => '', 'draw_offer' => null, 'log' => [], 'rep' => []];
    $st['rep'][chess_key($st)] = 1; return $st;
}
function chess_key(array $st): string { return md5(implode(',', $st['board']) . '|' . $st['turn'] . '|' . implode('', $st['castle']) . '|' . $st['ep']); }
function chess_sq(int $i): string { return chr(97 + $i % 8) . (8 - intdiv($i, 8)); }

function chess_attacked(array $b, int $sq, string $by): bool {
    $r = intdiv($sq, 8); $c = $sq % 8; $dr = $by === 'w' ? 1 : -1;
    foreach ([-1, 1] as $dc) { $rr = $r + $dr; $cc = $c + $dc; if ($rr >= 0 && $rr < 8 && $cc >= 0 && $cc < 8 && $b[$rr * 8 + $cc] === $by . 'P') return true; }
    foreach ([[-2, -1], [-2, 1], [-1, -2], [-1, 2], [1, -2], [1, 2], [2, -1], [2, 1]] as [$a, $d]) { $rr = $r + $a; $cc = $c + $d; if ($rr >= 0 && $rr < 8 && $cc >= 0 && $cc < 8 && $b[$rr * 8 + $cc] === $by . 'N') return true; }
    foreach ([[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]] as [$a, $d]) { $rr = $r + $a; $cc = $c + $d; if ($rr >= 0 && $rr < 8 && $cc >= 0 && $cc < 8 && $b[$rr * 8 + $cc] === $by . 'K') return true; }
    foreach ([[-1, 0], [1, 0], [0, -1], [0, 1], [-1, -1], [-1, 1], [1, -1], [1, 1]] as $k => [$a, $d]) {
        $rr = $r + $a; $cc = $c + $d;
        while ($rr >= 0 && $rr < 8 && $cc >= 0 && $cc < 8) { $p = $b[$rr * 8 + $cc]; if ($p !== '') { if ($p[0] === $by && ($p[1] === 'Q' || ($k < 4 ? $p[1] === 'R' : $p[1] === 'B'))) return true; break; } $rr += $a; $cc += $d; }
    }
    return false;
}
/** Pseudo-legal moves for a colour: [from, to, flag] with flag '', 'e' (en passant), 'K'/'Q' (castling), 'P' (promotion). */
function chess_pseudo(array $st, string $col): array {
    $b = $st['board']; $opp = $col === 'w' ? 'b' : 'w'; $out = [];
    for ($i = 0; $i < 64; $i++) {
        $p = $b[$i]; if ($p === '' || $p[0] !== $col) continue; $t = $p[1]; $r = intdiv($i, 8); $c = $i % 8;
        if ($t === 'P') {
            $dir = $col === 'w' ? -1 : 1; $startRow = $col === 'w' ? 6 : 1; $promoRow = $col === 'w' ? 0 : 7; $rr = $r + $dir;
            if ($rr >= 0 && $rr < 8 && $b[$rr * 8 + $c] === '') {
                $out[] = [$i, $rr * 8 + $c, $rr === $promoRow ? 'P' : ''];
                if ($r === $startRow && $b[($r + 2 * $dir) * 8 + $c] === '') $out[] = [$i, ($r + 2 * $dir) * 8 + $c, ''];
            }
            foreach ([-1, 1] as $dc) { $cc = $c + $dc; if ($cc < 0 || $cc > 7 || $rr < 0 || $rr > 7) continue; $to = $rr * 8 + $cc; $q = $b[$to];
                if ($q !== '' && $q[0] === $opp) $out[] = [$i, $to, $rr === $promoRow ? 'P' : '']; elseif ($to === $st['ep']) $out[] = [$i, $to, 'e']; }
        } elseif ($t === 'N' || $t === 'K') {
            $offs = $t === 'N' ? [[-2, -1], [-2, 1], [-1, -2], [-1, 2], [1, -2], [1, 2], [2, -1], [2, 1]] : [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]];
            foreach ($offs as [$a, $d]) { $rr = $r + $a; $cc = $c + $d; if ($rr < 0 || $rr > 7 || $cc < 0 || $cc > 7) continue; $q = $b[$rr * 8 + $cc]; if ($q === '' || $q[0] === $opp) $out[] = [$i, $rr * 8 + $cc, '']; }
            if ($t === 'K') { $home = $col === 'w' ? 60 : 4;
                if ($i === $home && !chess_attacked($b, $home, $opp)) {
                    if ($st['castle'][$col . 'K'] && $b[$home + 1] === '' && $b[$home + 2] === '' && $b[$home + 3] === $col . 'R' && !chess_attacked($b, $home + 1, $opp) && !chess_attacked($b, $home + 2, $opp)) $out[] = [$i, $home + 2, 'K'];
                    if ($st['castle'][$col . 'Q'] && $b[$home - 1] === '' && $b[$home - 2] === '' && $b[$home - 3] === '' && $b[$home - 4] === $col . 'R' && !chess_attacked($b, $home - 1, $opp) && !chess_attacked($b, $home - 2, $opp)) $out[] = [$i, $home - 2, 'Q'];
                } }
        } else {
            $dirs = $t === 'R' ? [[-1, 0], [1, 0], [0, -1], [0, 1]] : ($t === 'B' ? [[-1, -1], [-1, 1], [1, -1], [1, 1]] : [[-1, 0], [1, 0], [0, -1], [0, 1], [-1, -1], [-1, 1], [1, -1], [1, 1]]);
            foreach ($dirs as [$a, $d]) { $rr = $r + $a; $cc = $c + $d;
                while ($rr >= 0 && $rr < 8 && $cc >= 0 && $cc < 8) { $q = $b[$rr * 8 + $cc]; if ($q === '') $out[] = [$i, $rr * 8 + $cc, '']; else { if ($q[0] === $opp) $out[] = [$i, $rr * 8 + $cc, '']; break; } $rr += $a; $cc += $d; } }
        }
    }
    return $out;
}
function chess_make(array $b, array $mv, string $promo = 'Q'): array {
    [$from, $to, $flag] = $mv; $p = $b[$from]; $col = $p[0]; $b[$from] = '';
    if ($flag === 'e') $b[intdiv($from, 8) * 8 + $to % 8] = '';
    if ($flag === 'K') { $b[$to - 1] = $b[$to + 1]; $b[$to + 1] = ''; }
    if ($flag === 'Q') { $b[$to + 1] = $b[$to - 2]; $b[$to - 2] = ''; }
    $b[$to] = $flag === 'P' ? $col . $promo : $p; return $b;
}
function chess_legal(array $st, string $col): array {
    $opp = $col === 'w' ? 'b' : 'w'; $out = [];
    foreach (chess_pseudo($st, $col) as $mv) { $nb = chess_make($st['board'], $mv); $k = array_search($col . 'K', $nb, true); if ($k !== false && !chess_attacked($nb, (int)$k, $opp)) $out[] = $mv; }
    return $out;
}
function chess_insufficient(array $b): bool {
    $left = []; foreach ($b as $p) if ($p !== '' && $p[1] !== 'K') $left[] = $p[1];
    return !$left || (count($left) === 1 && in_array($left[0], ['B', 'N'], true));
}
function chess_move(array &$st, int $seat, array $mv): void {
    if ($st['over']) throw new RuntimeException('This game is over');
    $type = (string)($mv['type'] ?? 'move');
    if ($type === 'resign') { $st['over'] = true; $st['winners'] = [1 - $seat]; $st['result'] = ($seat === 0 ? 'White' : 'Black') . ' resigned'; $st['log'][] = $st['result']; return; }
    if ($type === 'offer_draw') { $st['draw_offer'] = $seat; return; }
    if ($type === 'decline_draw') { $st['draw_offer'] = null; return; }
    if ($type === 'accept_draw') { if ($st['draw_offer'] === null || $st['draw_offer'] === $seat) throw new RuntimeException('There is no draw offer to accept'); $st['over'] = true; $st['winners'] = []; $st['result'] = 'Draw agreed'; $st['log'][] = 'Draw agreed'; return; }
    if ($st['turn'] !== $seat) throw new RuntimeException('It is not your turn');
    $from = (int)($mv['from'] ?? -1); $to = (int)($mv['to'] ?? -1); $col = $seat === 0 ? 'w' : 'b'; $opp = $seat === 0 ? 'b' : 'w'; $found = null;
    foreach (chess_legal($st, $col) as $m) if ($m[0] === $from && $m[1] === $to) { $found = $m; break; }
    if (!$found) throw new RuntimeException('That move is not allowed');
    $promo = strtoupper((string)($mv['promo'] ?? 'Q')); if ($found[2] === 'P' && !in_array($promo, ['Q', 'R', 'B', 'N'], true)) throw new RuntimeException('Choose a piece to promote to'); if ($found[2] !== 'P') $promo = 'Q';
    $b = $st['board']; $p = $b[$from]; $cap = $found[2] === 'e' ? $b[intdiv($from, 8) * 8 + $to % 8] : $b[$to];
    $st['board'] = chess_make($b, $found, $promo);
    // castling rights
    if ($p[1] === 'K') { $st['castle'][$col . 'K'] = 0; $st['castle'][$col . 'Q'] = 0; }
    foreach ([[63, 'wK'], [56, 'wQ'], [7, 'bK'], [0, 'bQ']] as [$sq, $k]) if ($from === $sq || $to === $sq) $st['castle'][$k] = 0;
    $st['ep'] = ($p[1] === 'P' && abs($to - $from) === 16) ? intdiv($from + $to, 2) : -1;
    $st['half'] = ($p[1] === 'P' || $cap !== '') ? 0 : $st['half'] + 1; if ($seat === 1) $st['full']++;
    $name = $found[2] === 'K' ? 'O-O' : ($found[2] === 'Q' ? 'O-O-O' : ($p[1] === 'P' ? '' : $p[1]) . chess_sq($from) . ($cap !== '' ? 'x' : '-') . chess_sq($to) . ($found[2] === 'P' ? '=' . $promo : ''));
    $st['last'] = [$from, $to]; $st['turn'] = 1 - $seat; $st['draw_offer'] = null;
    $k = chess_key($st); $st['rep'][$k] = ($st['rep'][$k] ?? 0) + 1;
    $ksq = array_search($opp . 'K', $st['board'], true); $st['check'] = $ksq !== false && chess_attacked($st['board'], (int)$ksq, $col);
    $replies = chess_legal($st, $opp);
    if (!$replies) { $st['over'] = true; if ($st['check']) { $st['winners'] = [$seat]; $st['result'] = 'Checkmate'; $name .= '#'; } else { $st['winners'] = []; $st['result'] = 'Stalemate: a draw'; } }
    elseif (chess_insufficient($st['board'])) { $st['over'] = true; $st['winners'] = []; $st['result'] = 'Draw: not enough pieces to checkmate'; }
    elseif ($st['half'] >= 100) { $st['over'] = true; $st['winners'] = []; $st['result'] = 'Draw: 50 moves without a capture or pawn move'; }
    elseif ($st['rep'][$k] >= 3) { $st['over'] = true; $st['winners'] = []; $st['result'] = 'Draw: same position three times'; }
    elseif ($st['check']) $name .= '+';
    $st['log'][] = $name; if (count($st['log']) > 300) array_shift($st['log']);
}
function chess_view(array $st, int $seat): array {
    $v = ['board' => $st['board'], 'turn' => $st['turn'], 'check' => $st['check'], 'last' => $st['last'], 'over' => $st['over'], 'winners' => $st['winners'], 'result' => $st['result'], 'draw_offer' => $st['draw_offer'], 'log' => array_slice($st['log'], -60), 'legal' => (object)[], 'promo' => []];
    if (!$st['over'] && $st['turn'] === $seat && $seat >= 0) { $legal = []; $promo = [];
        foreach (chess_legal($st, $seat === 0 ? 'w' : 'b') as $m) { $legal[$m[0]][] = $m[1]; if ($m[2] === 'P') $promo[] = $m[0] . '-' . $m[1]; }
        $v['legal'] = (object)$legal; $v['promo'] = array_values(array_unique($promo)); }
    $count = ['P' => 8, 'N' => 2, 'B' => 2, 'R' => 2, 'Q' => 1]; $have = ['w' => $count, 'b' => $count];
    foreach ($st['board'] as $p) if ($p !== '' && $p[1] !== 'K') $have[$p[0]][$p[1]]--;
    $v['captured'] = ['w' => [], 'b' => []]; // pieces each colour has lost
    foreach (['w', 'b'] as $c) foreach ($have[$c] as $t => $n) for ($i = 0; $i < max(0, $n); $i++) $v['captured'][$c][] = $t;
    return $v;
}
