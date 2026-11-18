<?php
declare(strict_types=1);
// Ludo for 2 to 4 players. A token's position: -1 = in the yard, 0..50 = on the shared 52-cell ring (0 is that colour's start cell),
// 51..55 = the home column, 56 = home. Colours: 0 red, 1 green, 2 yellow, 3 blue. Each colour starts 13 cells further round the ring.
// Rules used: a 6 brings a token out and gives another throw; landing on a lone opponent token (not on a safe cell) sends it back to its yard
// and gives another throw; reaching home also gives another throw; three sixes in a row lose the turn; home needs the exact number. First to bring all four home wins.
const LUDO_SAFE = [0, 13, 26, 39, 8, 21, 34, 47];

function ludo_setup(array $ids, array $o): array {
    $n = count($ids); $colors = $n === 2 ? [0, 2] : array_slice([0, 1, 2, 3], 0, $n); $tokens = [];
    foreach ($ids as $i => $_) $tokens[$i] = [-1, -1, -1, -1];
    return ['n' => $n, 'colors' => $colors, 'tokens' => $tokens, 'turn' => 0, 'phase' => 'roll', 'dice' => null, 'sixes' => 0, 'last' => null, 'over' => false, 'winners' => [], 'result' => '', 'log' => []];
}
function ludo_abs(int $color, int $pos): int { return ($color * 13 + $pos) % 52; }
function ludo_movable(array $st, int $seat): array {
    $d = (int)$st['dice']; $out = [];
    foreach ($st['tokens'][$seat] as $i => $p) { if ($p === -1 ? $d === 6 : $p + $d <= 56) $out[] = $i; }
    return $out;
}
function ludo_next(array &$st): void { $st['turn'] = ($st['turn'] + 1) % $st['n']; $st['phase'] = 'roll'; $st['sixes'] = 0; $st['dice'] = null; }
function ludo_move(array &$st, int $seat, array $mv): void {
    if ($st['over']) throw new RuntimeException('This game is over');
    if ($st['turn'] !== $seat) throw new RuntimeException('It is not your turn');
    $type = (string)($mv['type'] ?? '');
    if ($type === 'roll') {
        if ($st['phase'] !== 'roll') throw new RuntimeException('Move a token first');
        $d = random_int(1, 6); $st['dice'] = $d; $st['sixes'] = $d === 6 ? $st['sixes'] + 1 : 0;
        if ($st['sixes'] >= 3) { $st['log'][] = 'P' . ($seat + 1) . ' threw three sixes in a row and loses the turn'; $st['last'] = ['seat' => $seat, 'dice' => $d, 'note' => 'Three sixes: turn lost']; ludo_next($st); return; }
        if (!ludo_movable($st, $seat)) { $st['last'] = ['seat' => $seat, 'dice' => $d, 'note' => 'No move']; $st['log'][] = 'P' . ($seat + 1) . ' threw ' . $d . ': no move';
            if ($d === 6) { $st['phase'] = 'roll'; $st['dice'] = null; } else ludo_next($st); return; }
        $st['phase'] = 'move'; $st['last'] = ['seat' => $seat, 'dice' => $d]; return;
    }
    if ($type !== 'move') throw new RuntimeException('Unknown action');
    if ($st['phase'] !== 'move') throw new RuntimeException('Throw the dice first');
    $t = (int)($mv['token'] ?? -1); if (!in_array($t, ludo_movable($st, $seat), true)) throw new RuntimeException('That token cannot move');
    $d = (int)$st['dice']; $color = $st['colors'][$seat]; $p = $st['tokens'][$seat][$t]; $np = $p === -1 ? 0 : $p + $d; $st['tokens'][$seat][$t] = $np; $cap = false;
    if ($np <= 50) { $cell = ludo_abs($color, $np);
        if (!in_array($cell, LUDO_SAFE, true)) foreach ($st['tokens'] as $s2 => $tk) { if ($s2 === $seat) continue; foreach ($tk as $i2 => $p2) if ($p2 >= 0 && $p2 <= 50 && ludo_abs($st['colors'][$s2], $p2) === $cell) { $st['tokens'][$s2][$i2] = -1; $cap = true; } } }
    $home = $np === 56; $st['last'] = ['seat' => $seat, 'dice' => $d, 'token' => $t, 'capture' => $cap, 'home' => $home];
    $st['log'][] = 'P' . ($seat + 1) . ' threw ' . $d . ($p === -1 ? ' and brought a token out' : '') . ($cap ? ' and captured a token' : '') . ($home ? ' and got a token home' : ''); if (count($st['log']) > 120) array_shift($st['log']);
    if (!array_diff($st['tokens'][$seat], [56])) { $st['over'] = true; $st['winners'] = [$seat]; $st['result'] = 'All four tokens are home'; $st['dice'] = null; $st['phase'] = 'roll'; return; }
    if ($d === 6 || $cap || $home) { $st['phase'] = 'roll'; $st['dice'] = null; } else ludo_next($st);
}
function ludo_view(array $st, int $seat): array {
    $v = ['n' => $st['n'], 'colors' => $st['colors'], 'tokens' => $st['tokens'], 'turn' => $st['turn'], 'phase' => $st['phase'], 'dice' => $st['dice'], 'last' => $st['last'], 'over' => $st['over'], 'winners' => $st['winners'], 'result' => $st['result'], 'log' => array_slice($st['log'], -40), 'movable' => []];
    if (!$st['over'] && $st['turn'] === $seat && $st['phase'] === 'move') $v['movable'] = ludo_movable($st, $seat);
    return $v;
}
