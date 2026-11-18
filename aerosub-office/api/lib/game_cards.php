<?php
declare(strict_types=1);
// Crazy Eights for 2 to 6 players. Cards are rank+suit: A 2..9 T J Q K and S H D C (e.g. "8H", "TS").
// Play a card that matches the suit or the rank of the top card; an 8 is wild and you name the suit. If you cannot play, draw one card
// (you may then play it if it fits; otherwise your turn passes). First to empty their hand wins.

function cards_deck(): array { $d = []; foreach (['A', '2', '3', '4', '5', '6', '7', '8', '9', 'T', 'J', 'Q', 'K'] as $r) foreach (['S', 'H', 'D', 'C'] as $s) $d[] = $r . $s; shuffle($d); return $d; }
function cards_setup(array $ids, array $o): array {
    $n = count($ids); $deck = cards_deck(); $per = $n === 2 ? 7 : 5; $hands = [];
    foreach ($ids as $i => $_) $hands[$i] = array_splice($deck, 0, $per);
    $top = null; foreach ($deck as $k => $c) if ($c[0] !== '8') { $top = $c; array_splice($deck, $k, 1); break; }
    if ($top === null) { $top = array_shift($deck); }
    return ['n' => $n, 'hands' => $hands, 'stock' => $deck, 'discard' => [$top], 'suit' => $top[1], 'turn' => 0, 'drawn' => false, 'over' => false, 'winners' => [], 'result' => '', 'last' => null, 'log' => []];
}
function cards_ok(array $st, string $card): bool { $top = end($st['discard']); return $card[0] === '8' || $card[1] === $st['suit'] || $card[0] === $top[0]; }
function cards_playable(array $st, int $seat): array { return array_values(array_filter($st['hands'][$seat], fn($c) => cards_ok($st, $c))); }
function cards_refill(array &$st): void { // the discard pile (except the top card) is shuffled to become the new stock
    if ($st['stock'] || count($st['discard']) < 2) return; $top = array_pop($st['discard']); $st['stock'] = $st['discard']; shuffle($st['stock']); $st['discard'] = [$top];
}
function cards_next(array &$st, int $seat): void { $st['turn'] = ($seat + 1) % $st['n']; $st['drawn'] = false; }
function cards_move(array &$st, int $seat, array $mv): void {
    if ($st['over']) throw new RuntimeException('This game is over');
    if ($st['turn'] !== $seat) throw new RuntimeException('It is not your turn');
    $type = (string)($mv['type'] ?? ''); $who = 'P' . ($seat + 1);
    if ($type === 'play') {
        $card = strtoupper((string)($mv['card'] ?? '')); $i = array_search($card, $st['hands'][$seat], true); if ($i === false) throw new RuntimeException('You do not have that card');
        if (!cards_ok($st, $card)) throw new RuntimeException('That card does not match the suit or number');
        $suit = $card[0] === '8' ? strtoupper((string)($mv['suit'] ?? '')) : $card[1]; if (!in_array($suit, ['S', 'H', 'D', 'C'], true)) throw new RuntimeException('Choose a suit for the 8');
        array_splice($st['hands'][$seat], (int)$i, 1); $st['discard'][] = $card; $st['suit'] = $suit; $st['last'] = ['seat' => $seat, 'card' => $card, 'suit' => $suit];
        $st['log'][] = $who . ' played ' . $card . ($card[0] === '8' ? ' and chose ' . $suit : ''); if (count($st['log']) > 100) array_shift($st['log']);
        if (!$st['hands'][$seat]) { $st['over'] = true; $st['winners'] = [$seat]; $st['result'] = 'Played the last card'; return; }
        cards_next($st, $seat); return;
    }
    if ($type === 'draw') {
        if ($st['drawn']) throw new RuntimeException('You already drew a card'); if (cards_playable($st, $seat)) throw new RuntimeException('You have a card you can play');
        cards_refill($st);
        if (!$st['stock']) { $st['log'][] = $who . ' had nothing to draw and passed'; $st['last'] = ['seat' => $seat, 'pass' => true]; cards_next($st, $seat); return; }
        $c = array_shift($st['stock']); $st['hands'][$seat][] = $c; $st['last'] = ['seat' => $seat, 'drew' => true]; $st['log'][] = $who . ' drew a card';
        if (cards_ok($st, $c)) { $st['drawn'] = true; return; } // it fits: the player may play it
        $st['log'][] = $who . ' could not play it and passed'; cards_next($st, $seat); return;
    }
    if ($type === 'pass') { if (!$st['drawn']) throw new RuntimeException('Draw a card first'); $st['log'][] = $who . ' passed'; cards_next($st, $seat); return; }
    throw new RuntimeException('Unknown action');
}
function cards_view(array $st, int $seat): array {
    $v = ['n' => $st['n'], 'top' => end($st['discard']), 'suit' => $st['suit'], 'stock' => count($st['stock']), 'counts' => array_map('count', $st['hands']), 'turn' => $st['turn'], 'drawn' => $st['drawn'], 'last' => $st['last'], 'over' => $st['over'], 'winners' => $st['winners'], 'result' => $st['result'], 'log' => array_slice($st['log'], -30), 'hand' => [], 'playable' => [], 'can_draw' => false];
    if ($seat >= 0) { $v['hand'] = $st['hands'][$seat]; if (!$st['over'] && $st['turn'] === $seat) { $v['playable'] = cards_playable($st, $seat); $v['can_draw'] = !$st['drawn'] && !$v['playable']; } }
    if ($st['over']) $v['hands'] = $st['hands']; // everyone's cards are shown at the end
    return $v;
}
