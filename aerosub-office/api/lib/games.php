<?php
declare(strict_types=1);
// Team games. Route: /api/games
//   GET  games                      lobby: my games, invites, who I can invite
//   GET  games/turns                what needs my attention now (invites and games waiting for my move): polled by the page for pop-ups
//   GET|PATCH games/settings        Super Admin: who sees Games, when they are open, which games are on
//   POST games                      start a game and invite people {game, invite_ids[]}
//   GET  games/{id}[?v=N]           one game (the state I am allowed to see)
//   POST games/{id}/respond {accept}  ·  /start  ·  /cancel  ·  /move {version, move{...}}
// The server owns the rules: every move is validated here, and each player only receives what they may see (their own cards or tiles).
$u = need_user(); $uid = (int)$u['id']; $isAdmin = $u['role'] === 'super_admin';
$mid = isset($seg[1]) && ctype_digit($seg[1]) ? (int)$seg[1] : 0; $act = $mid ? ($seg[2] ?? '') : ($seg[1] ?? '');
const GAME_DEFS = [
    'chess' => ['name' => 'Chess', 'min' => 2, 'max' => 2, 'emoji' => '♟', 'desc' => 'Classic chess for two, with every rule: castling, en passant, promotion.', 'prefix' => 'chess'],
    'ludo' => ['name' => 'Ludo', 'min' => 2, 'max' => 4, 'emoji' => '🎲', 'desc' => 'Throw the dice, race four tokens home and send rivals back to the yard.', 'prefix' => 'ludo'],
    'scrabble' => ['name' => 'Scrabble', 'min' => 2, 'max' => 4, 'emoji' => '🔤', 'desc' => 'Build words on the board. Words are not checked for you: challenge any you doubt.', 'prefix' => 'scr'],
    'cards' => ['name' => 'Crazy Eights', 'min' => 2, 'max' => 6, 'emoji' => '🃏', 'desc' => 'The classic card game: match the suit or number, and 8s are wild.', 'prefix' => 'cards'],
];
function game_load(string $g): string { if (!isset(GAME_DEFS[$g])) fail('Unknown game', 400); require_once __DIR__ . '/game_' . $g . '.php'; return GAME_DEFS[$g]['prefix']; }
function game_notify(int $to, string $kind, string $title, string $body, int $match): void {
    q('INSERT INTO notifications (user_id,type,title,body,link,read_at) VALUES (?,?,?,?,?,NOW())', [$to, 'game_' . $kind, mb_substr($title, 0, 200), mb_substr($body, 0, 500), '#/games/' . $match]);
    push_try($to, $title, $body, '#/games/' . $match, 'game-' . $kind . '-' . $match);
}
function game_players(int $id): array { return all('SELECT p.user_id,p.seat,p.status,u.name FROM game_players p JOIN users u ON u.id=p.user_id WHERE p.match_id=? ORDER BY COALESCE(p.seat,99), u.name', [$id]); }
function game_who(array $players, int $seat): int { foreach ($players as $p) if ($p['seat'] !== null && (int)$p['seat'] === $seat) return (int)$p['user_id']; return 0; }
function game_visible_users(array $s): array { // people who may be invited
    $base = "SELECT u.id,u.name,u.job_title,(SELECT d.name FROM departments d WHERE d.id=u.department_id) AS department FROM users u WHERE u.active=1 AND u.archived_at IS NULL";
    if ($s['visibility'] === 'selected') $base .= " AND (u.role='super_admin' OR u.id IN (SELECT user_id FROM game_users))"; elseif ($s['visibility'] !== 'all') $base .= " AND u.role='super_admin'";
    return all($base . ' ORDER BY u.name');
}
function game_need_open(array $u): array {
    $a = games_access($u); if (!$a['visible']) fail('Games are not available to you', 403);
    if (!$a['open']) fail('Games are closed right now. ' . $a['text'] . '.', 403); return $a;
}
function game_start(int $id, array $match, array $players): void {
    $acc = array_values(array_filter($players, fn($p) => $p['status'] === 'accepted')); $def = GAME_DEFS[$match['game']]; $pre = game_load($match['game']);
    if (count($acc) < $def['min']) fail('Not enough players have joined yet', 400);
    $ids = array_map(fn($p) => (int)$p['user_id'], $acc); shuffle($ids); $ids = array_slice($ids, 0, $def['max']);
    $state = ($pre . '_setup')($ids, []);
    foreach ($players as $p) { $k = array_search((int)$p['user_id'], $ids, true); if ($k === false) q("UPDATE game_players SET status='declined', seat=NULL WHERE match_id=? AND user_id=?", [$id, $p['user_id']]); else q('UPDATE game_players SET seat=? WHERE match_id=? AND user_id=?', [$k, $id, $p['user_id']]); }
    $turn = $state['turn'] ?? 0;
    q("UPDATE game_matches SET status='active', state=?, turn_user_id=?, version=1 WHERE id=?", [json_encode($state), $ids[$turn], $id]);
    foreach ($ids as $k => $x) game_notify($x, $k === $turn ? 'turn' : 'start', ($k === $turn ? 'Your turn: ' : 'Game started: ') . $def['name'], ($k === $turn ? 'You go first.' : 'Waiting for ' . (one('SELECT name FROM users WHERE id=?', [$ids[$turn]])['name'] ?? 'the first player') . ' to move.'), $id);
}
function game_summary(array $m, array $players, int $uid): array {
    $mine = null; foreach ($players as $p) if ((int)$p['user_id'] === $uid) $mine = $p;
    return ['id' => (int)$m['id'], 'game' => $m['game'], 'name' => GAME_DEFS[$m['game']]['name'], 'emoji' => GAME_DEFS[$m['game']]['emoji'], 'status' => $m['status'], 'creator_id' => (int)$m['creator_id'], 'version' => (int)$m['version'], 'turn_user_id' => $m['turn_user_id'] ? (int)$m['turn_user_id'] : null,
            'my_turn' => $m['status'] === 'active' && (int)$m['turn_user_id'] === $uid, 'mine' => $mine['status'] ?? null, 'result' => $m['result'], 'winners' => $m['winners'] ? array_map('intval', explode(',', $m['winners'])) : [], 'updated_at' => $m['updated_at'],
            'players' => array_map(fn($p) => ['user_id' => (int)$p['user_id'], 'name' => $p['name'], 'seat' => $p['seat'] === null ? null : (int)$p['seat'], 'status' => $p['status']], $players)];
}

/* ---------- admin settings ---------- */
if ($act === 'settings' && !$mid) {
    if (!$isAdmin) fail('Only the Super Admin can change the games settings', 403);
    if ($method === 'GET') { $s = games_settings(); out(['settings' => $s, 'user_ids' => array_map('intval', array_column(all('SELECT user_id FROM game_users'), 'user_id')), 'defs' => GAME_DEFS, 'now' => date('H:i'), 'open_now' => games_window_open($s)]); }
    if ($method === 'PATCH') {
        $b = body(); $s = games_settings();
        $vis = in((string)($b['visibility'] ?? $s['visibility']), ['none', 'all', 'selected'], $s['visibility']); $mode = in((string)($b['mode'] ?? $s['mode']), ['always', 'window'], $s['mode']);
        $tm = fn($v, $d) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$v) ? (string)$v : $d;
        $start = $tm($b['start_time'] ?? $s['start_time'], $s['start_time']); $end = $tm($b['end_time'] ?? $s['end_time'], $s['end_time']);
        $days = array_values(array_unique(array_filter(array_map('intval', (array)($b['days'] ?? explode(',', (string)$s['days']))), fn($d) => $d >= 1 && $d <= 7))); sort($days); if (!$days) $days = [1, 2, 3, 4, 5, 6, 7];
        $games = array_values(array_intersect(array_keys(GAME_DEFS), (array)($b['games'] ?? explode(',', (string)$s['games']))));
        $preset = in((string)($b['preset'] ?? $s['preset']), ['always', 'midday', 'close', 'custom'], 'custom');
        q('UPDATE game_settings SET visibility=?,mode=?,start_time=?,end_time=?,days=?,games=?,preset=? WHERE id=1', [$vis, $mode, $start, $end, implode(',', $days), implode(',', $games), $preset]);
        if (array_key_exists('user_ids', $b)) { $ids = array_values(array_unique(array_filter(array_map('intval', (array)$b['user_ids'])))); if ($ids) $ids = array_map('intval', array_column(all('SELECT id FROM users WHERE active=1 AND id IN (' . implode(',', $ids) . ')'), 'id'));
            q('DELETE FROM game_users'); foreach ($ids as $x) q('INSERT IGNORE INTO game_users (user_id) VALUES (?)', [$x]); }
        log_act($uid, 'games_settings', $vis . ' / ' . $mode); out(['ok' => true]);
    }
    fail('Bad request', 405);
}

/* ---------- what needs my attention (polled) ---------- */
if ($act === 'turns' && !$mid && $method === 'GET') {
    $a = games_access($u); if (!$a['visible']) out(['items' => [], 'open' => false, 'visible' => false]);
    $items = [];
    foreach (all("SELECT m.id,m.game,m.version,m.updated_at,c.name AS creator FROM game_players p JOIN game_matches m ON m.id=p.match_id JOIN users c ON c.id=m.creator_id WHERE p.user_id=? AND p.status='invited' AND m.status='waiting'", [$uid]) as $r)
        $items[] = ['key' => 'invite:' . $r['id'], 'kind' => 'invite', 'match_id' => (int)$r['id'], 'game' => $r['game'], 'name' => GAME_DEFS[$r['game']]['name'] ?? $r['game'], 'title' => $r['creator'] . ' invited you to play ' . (GAME_DEFS[$r['game']]['name'] ?? ''), 'at' => $r['updated_at']];
    foreach (all("SELECT m.id,m.game,m.version,m.updated_at FROM game_matches m WHERE m.status='active' AND m.turn_user_id=?", [$uid]) as $r) {
        $opp = array_column(all('SELECT u.name FROM game_players p JOIN users u ON u.id=p.user_id WHERE p.match_id=? AND p.user_id<>? AND p.status=\'accepted\'', [$r['id'], $uid]), 'name');
        $items[] = ['key' => 'turn:' . $r['id'] . ':' . $r['version'], 'kind' => 'turn', 'match_id' => (int)$r['id'], 'game' => $r['game'], 'name' => GAME_DEFS[$r['game']]['name'] ?? $r['game'], 'title' => 'Your turn in ' . (GAME_DEFS[$r['game']]['name'] ?? $r['game']) . ' with ' . implode(', ', array_slice($opp, 0, 3)), 'at' => $r['updated_at']]; }
    out(['items' => $items, 'open' => $a['open'], 'visible' => true]);
}

/* ---------- lobby ---------- */
if ($method === 'GET' && !$mid && $act === '') {
    $a = games_access($u); if (!$a['visible']) fail('Games are not available to you', 403); $s = games_settings();
    q("UPDATE game_matches SET status='cancelled', result='Closed after 14 days without a move' WHERE status IN ('waiting','active') AND updated_at < (NOW() - INTERVAL 14 DAY)");
    $rows = all("SELECT m.* FROM game_matches m JOIN game_players p ON p.match_id=m.id WHERE p.user_id=? AND p.status<>'declined' AND (m.status IN ('waiting','active') OR (m.status='finished' AND m.updated_at > (NOW() - INTERVAL 3 DAY))) ORDER BY FIELD(m.status,'active','waiting','finished'), m.updated_at DESC LIMIT 60", [$uid]);
    $list = []; foreach ($rows as $m) $list[] = game_summary($m, game_players((int)$m['id']), $uid);
    $defs = []; foreach ($a['games'] as $g) if (isset(GAME_DEFS[$g])) $defs[] = ['id' => $g] + array_diff_key(GAME_DEFS[$g], ['prefix' => 1]);
    out(['access' => ['open' => $a['open'], 'window_open' => $a['window_open'], 'text' => $a['text'], 'visibility' => $a['visibility']], 'admin' => $isAdmin, 'defs' => $defs, 'matches' => $list, 'people' => array_values(array_filter(game_visible_users($s), fn($x) => (int)$x['id'] !== $uid))]);
}

/* ---------- create ---------- */
if ($method === 'POST' && !$mid && $act === '') {
    $a = game_need_open($u); $b = body(); $g = (string)($b['game'] ?? ''); if (!in_array($g, $a['games'], true)) fail('That game is not switched on', 400); $def = GAME_DEFS[$g];
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($b['invite_ids'] ?? [])), fn($x) => $x !== $uid)));
    $ok = array_map(fn($x) => (int)$x['id'], game_visible_users(games_settings())); $ids = array_values(array_intersect($ids, $ok));
    if (count($ids) < $def['min'] - 1) fail('Invite at least ' . ($def['min'] - 1) . ' ' . ($def['min'] === 2 ? 'person' : 'people') . ' to play ' . $def['name'], 400); if (count($ids) > $def['max'] - 1) fail($def['name'] . ' takes up to ' . $def['max'] . ' players', 400);
    q("INSERT INTO game_matches (game,creator_id) VALUES (?,?)", [$g, $uid]); $id = (int)db()->lastInsertId();
    q("INSERT INTO game_players (match_id,user_id,status) VALUES (?,?,'accepted')", [$id, $uid]);
    foreach ($ids as $x) { q("INSERT INTO game_players (match_id,user_id,status) VALUES (?,?,'invited')", [$id, $x]); game_notify($x, 'invite', $u['name'] . ' invited you to play ' . $def['name'], 'Open Games to accept.', $id); }
    log_act($uid, 'game_created', $def['name']); out(['ok' => true, 'id' => $id], 201);
}
if (!$mid) fail('Not found', 404);
$match = one('SELECT * FROM game_matches WHERE id=?', [$mid]); if (!$match) fail('Game not found', 404);
$players = game_players($mid); $me = null; foreach ($players as $p) if ((int)$p['user_id'] === $uid) $me = $p;
if (!$me && !$isAdmin) fail('Game not found', 404);
$def = GAME_DEFS[$match['game']];

/* ---------- read one game ---------- */
if ($method === 'GET' && $act === '') {
    if (isset($_GET['v']) && (int)$_GET['v'] === (int)$match['version'] && (string)($_GET['s'] ?? '') === $match['status']) out(['unchanged' => true]);
    $o = game_summary($match, $players, $uid); $o['seat'] = ($me && $me['seat'] !== null) ? (int)$me['seat'] : -1; $o['is_creator'] = (int)$match['creator_id'] === $uid; $o['can_start'] = $o['is_creator'] && $match['status'] === 'waiting' && count(array_filter($players, fn($p) => $p['status'] === 'accepted')) >= $def['min'];
    $o['min'] = $def['min']; $o['max'] = $def['max']; $o['desc'] = $def['desc'];
    if ($match['status'] !== 'waiting' && $match['state']) { $pre = game_load($match['game']); $o['view'] = ($pre . '_view')(json_decode($match['state'], true), $o['seat']); }
    out($o);
}

/* ---------- actions ---------- */
if ($method !== 'POST') fail('Bad request', 405);
$b = body();
if ($act === 'respond') {
    game_need_open($u); if ($match['status'] !== 'waiting') fail('This game has already started or was cancelled', 409); if (!$me || $me['status'] !== 'invited') fail('You were not invited to this game', 400);
    $yes = !empty($b['accept']); q('UPDATE game_players SET status=? WHERE match_id=? AND user_id=?', [$yes ? 'accepted' : 'declined', $mid, $uid]);
    $players = game_players($mid); $acc = count(array_filter($players, fn($p) => $p['status'] === 'accepted')); $pending = count(array_filter($players, fn($p) => $p['status'] === 'invited'));
    game_notify((int)$match['creator_id'], 'reply', $u['name'] . ($yes ? ' joined' : ' declined') . ' your ' . $def['name'] . ' game', '', $mid);
    if (!$yes && $acc + $pending < $def['min']) { q("UPDATE game_matches SET status='cancelled', result='Not enough players' WHERE id=?", [$mid]); out(['ok' => true, 'cancelled' => true]); }
    if ($acc >= $def['max'] || ($pending === 0 && $acc >= $def['min'])) { $match = one('SELECT * FROM game_matches WHERE id=?', [$mid]); game_start($mid, $match, $players); out(['ok' => true, 'started' => true]); }
    out(['ok' => true]);
}
if ($act === 'start') {
    game_need_open($u); if ((int)$match['creator_id'] !== $uid) fail('Only the person who invited everyone can start the game', 403); if ($match['status'] !== 'waiting') fail('This game has already started or was cancelled', 409);
    game_start($mid, $match, $players); out(['ok' => true, 'started' => true]);
}
if ($act === 'cancel') {
    if ((int)$match['creator_id'] !== $uid && !$isAdmin) fail('Only the person who started the game can end it', 403); if (!in_array($match['status'], ['waiting', 'active'], true)) fail('This game is already over', 409);
    q("UPDATE game_matches SET status='cancelled', result=? WHERE id=?", [$u['name'] . ' ended the game', $mid]);
    foreach ($players as $p) if ((int)$p['user_id'] !== $uid && $p['status'] !== 'declined') game_notify((int)$p['user_id'], 'end', $u['name'] . ' ended the ' . $def['name'] . ' game', '', $mid);
    out(['ok' => true]);
}
if ($act === 'move') {
    game_need_open($u); if (!$me || $me['seat'] === null) fail('You are not playing in this game', 403);
    $pdo = db(); $pdo->beginTransaction();
    try {
        $m = one('SELECT * FROM game_matches WHERE id=? FOR UPDATE', [$mid]);
        if ($m['status'] !== 'active') { $pdo->rollBack(); fail('This game is not in progress', 409); }
        if (isset($b['version']) && (int)$b['version'] !== (int)$m['version']) { $pdo->rollBack(); fail('The game changed while you were deciding. It has been refreshed.', 409); }
        $pre = game_load($m['game']); $st = json_decode((string)$m['state'], true); $seat = (int)$me['seat'];
        try { ($pre . '_move')($st, $seat, (array)($b['move'] ?? [])); } catch (RuntimeException $e) { $pdo->rollBack(); fail($e->getMessage(), 400); }
        $over = !empty($st['over']); $turnUser = $over ? null : game_who($players, (int)$st['turn']); $win = $over ? implode(',', array_map(fn($s) => game_who($players, (int)$s), $st['winners'] ?? [])) : null;
        q('UPDATE game_matches SET state=?, turn_user_id=?, version=version+1, status=?, winners=?, result=? WHERE id=?', [json_encode($st), $turnUser, $over ? 'finished' : 'active', $win, $over ? mb_substr((string)($st['result'] ?? ''), 0, 200) : null, $mid]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    // tell whoever is next, or everyone when it ends
    if ($over) { $names = []; foreach ($st['winners'] ?? [] as $s) foreach ($players as $p) if ($p['seat'] !== null && (int)$p['seat'] === (int)$s) $names[] = $p['name'];
        foreach ($players as $p) if ($p['seat'] !== null && (int)$p['user_id'] !== $uid) game_notify((int)$p['user_id'], 'end', $def['name'] . ' finished', $names ? implode(' and ', $names) . ' won.' : (string)($st['result'] ?? 'It ended in a draw.'), $mid); }
    elseif ($turnUser && $turnUser !== $uid) game_notify($turnUser, 'turn', 'Your turn: ' . $def['name'], $u['name'] . ' has moved.', $mid);
    $o = ['ok' => true, 'version' => (int)$m['version'] + 1]; out($o);
}
fail('Bad request', 404);
