<?php
declare(strict_types=1);
// Course content: Modules > Sections > blocks, with progress. Included by courses.php.
//   Blocks: text · image · video (YouTube/Vimeo) · link · cards (learning cards) · quiz (short quiz with instant feedback) · slides (PowerPoint)
//   Learners:  GET courses/{id}/sections/{sid}  ·  POST courses/{id}/sections/{sid}/complete  ·  POST courses/{id}/sections/{sid}/quiz/{blockId}
//   Super Admin: POST|PATCH|DELETE courses/{id}/modules[/{m}]  ·  POST courses/{id}/modules/{m}/move  ·  POST|PATCH|DELETE courses/{id}/sections[/{s}]  ·  POST .../sections/{s}/move
//                POST|PATCH|DELETE courses/{id}/blocks[/{b}]  ·  POST courses/{id}/blocks/{b}/move  ·  POST courses/{id}/sections/{s}/slides (a PowerPoint)
const CRC_KINDS = ['text', 'image', 'video', 'link', 'cards', 'quiz', 'slides'];

/** Every section of a course in reading order. */
function crc_flat(int $cid): array {
    return all('SELECT s.id,s.title,s.module_id,m.title AS module_title FROM course_sections s JOIN course_modules m ON m.id=s.module_id WHERE s.course_id=? ORDER BY m.position,m.id,s.position,s.id', [$cid]);
}
/** Modules with their sections and what this person has finished (counters per module and per section). */
/** Create the module tables on first use, so the feature works even if /upgrade.php was not opened yet. */
function crc_ensure(): void {
    static $done = false; if ($done) return; $done = true;
    try { db()->query('SELECT 1 FROM course_modules LIMIT 1'); if (col_exists('course_questions', 'qtype')) return; } catch (Throwable $e) {}
    require_once __DIR__ . '/migrate.php'; migrate_course_content();
}
function crc_modules(int $cid, int $uid): array {
    crc_ensure();
    $done = array_flip(array_map('intval', array_column(all('SELECT section_id FROM course_progress WHERE course_id=? AND user_id=?', [$cid, $uid]), 'section_id')));
    $quizzes = []; foreach (all("SELECT section_id, COUNT(*) n, SUM(EXISTS(SELECT 1 FROM course_quiz_results r WHERE r.block_id=b.id AND r.user_id=?)) answered FROM course_blocks b WHERE b.course_id=? AND b.kind='quiz' GROUP BY section_id", [$uid, $cid]) as $r) $quizzes[(int)$r['section_id']] = $r;
    $counts = array_column(all('SELECT section_id, COUNT(*) n FROM course_blocks WHERE course_id=? GROUP BY section_id', [$cid]), 'n', 'section_id');
    $secs = []; foreach (all('SELECT id,module_id,title FROM course_sections WHERE course_id=? ORDER BY position,id', [$cid]) as $s) { $sid = (int)$s['id'];
        $secs[(int)$s['module_id']][] = ['id' => $sid, 'title' => $s['title'], 'blocks' => (int)($counts[$sid] ?? 0), 'done' => isset($done[$sid]), 'quizzes' => (int)($quizzes[$sid]['n'] ?? 0), 'quizzes_answered' => (int)($quizzes[$sid]['answered'] ?? 0)]; }
    $out = []; foreach (all('SELECT id,title,summary FROM course_modules WHERE course_id=? ORDER BY position,id', [$cid]) as $m) { $l = $secs[(int)$m['id']] ?? [];
        $out[] = ['id' => (int)$m['id'], 'title' => $m['title'], 'summary' => $m['summary'], 'sections' => $l, 'sections_total' => count($l), 'sections_done' => count(array_filter($l, fn($x) => $x['done']))]; }
    return $out;
}
function crc_progress(array $modules): array { $t = array_sum(array_column($modules, 'sections_total')); $d = array_sum(array_column($modules, 'sections_done')); return ['done' => $d, 'total' => $t, 'percent' => $t ? (int)round($d * 100 / $t) : 0]; }

function crc_block_in(string $kind, array $b): array {
    $title = cr_str($b['title'] ?? '', 200); $d = [];
    if ($kind === 'text') { $d['body'] = cr_str($b['body'] ?? '', 20000); if ($d['body'] === '') fail('Write the text'); }
    elseif ($kind === 'image') { $d['url'] = cr_img($b['url'] ?? ''); if (!$d['url']) fail('Add a picture: paste an https:// link or upload one'); $d['caption'] = cr_str($b['caption'] ?? '', 300); }
    elseif ($kind === 'video') { $url = cr_url($b['url'] ?? ''); $d = cr_video($url) + ['url' => $url]; }
    elseif ($kind === 'link') { $d['url'] = cr_url($b['url'] ?? ''); $d['note'] = cr_str($b['note'] ?? '', 500); if ($title === '') $title = $d['url']; }
    elseif ($kind === 'cards') {
        $cards = []; foreach (array_slice((array)($b['cards'] ?? []), 0, 60) as $c) { if (!is_array($c)) continue; $f = cr_str($c['front'] ?? '', 500); $k = cr_str($c['back'] ?? '', 1000); if ($f === '' && $k === '') continue; if ($f === '' || $k === '') fail('Every card needs a front and a back');
            $cards[] = ['front' => $f, 'back' => $k, 'image' => cr_img($c['image'] ?? '')]; }
        if (!$cards) fail('Add at least one card'); $d['cards'] = $cards; if ($title === '') $title = 'Learning cards';
    } elseif ($kind === 'quiz') {
        $qs = []; foreach (array_slice((array)($b['questions'] ?? []), 0, 15) as $q) { if (!is_array($q)) continue; $text = cr_str($q['q'] ?? '', 500); if ($text === '') continue;
            $opts = array_values(array_filter(array_map(fn($o) => cr_str($o, 300), (array)($q['options'] ?? [])), fn($o) => $o !== '')); if (count($opts) < 2 || count($opts) > 5) fail('Each quiz question needs 2 to 5 answers');
            $correct = (int)($q['correct'] ?? -1); if ($correct < 0 || $correct >= count($opts)) fail('Mark the correct answer of every quiz question');
            $qs[] = ['q' => $text, 'image' => cr_img($q['image'] ?? ''), 'options' => $opts, 'correct' => $correct, 'explain' => cr_str($q['explain'] ?? '', 500)]; }
        if (!$qs) fail('Add at least one quiz question'); $d['questions'] = $qs; if ($title === '') $title = 'Quick quiz';
    } else fail('Choose what to add');
    return [$title ?: null, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
}
function crc_block_out(array $b, int $uid, bool $admin): array {
    $d = json_decode((string)$b['data'], true) ?: []; $o = ['id' => (int)$b['id'], 'kind' => $b['kind'], 'title' => $b['title'], 'position' => (int)$b['position'], 'data' => $d];
    if ($b['kind'] === 'quiz') {
        $qs = $d['questions'] ?? []; $o['data']['questions'] = array_map(fn($q) => $admin ? $q : ['q' => $q['q'], 'image' => $q['image'] ?? null, 'options' => $q['options']], $qs);
        $res = one('SELECT score,correct,total,answers FROM course_quiz_results WHERE user_id=? AND block_id=?', [$uid, $b['id']]);
        if ($res) { $ans = json_decode((string)$res['answers'], true) ?: []; $fb = []; foreach ($qs as $i => $q) { $ch = (int)($ans[$i] ?? -1); $fb[] = ['chosen' => $ch, 'correct' => (int)$q['correct'], 'is_correct' => $ch === (int)$q['correct'], 'explain' => $q['explain'] ?? '']; }
            $o['result'] = ['score' => (float)$res['score'], 'correct' => (int)$res['correct'], 'total' => (int)$res['total'], 'feedback' => $fb]; }
    }
    return $o;
}
function crc_renumber(string $table, string $where, array $params, array $ids): void { foreach ($ids as $n => $x) q("UPDATE $table SET position=? WHERE id=?", [$n + 1, $x]); }
function crc_move(string $table, string $where, array $params, int $id, int $dir): void {
    $ids = array_map('intval', array_column(all("SELECT id FROM $table WHERE $where ORDER BY position,id", $params), 'id')); $i = array_search($id, $ids, true); $j = $i + $dir;
    if ($i !== false && $j >= 0 && $j < count($ids)) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; crc_renumber($table, $where, $params, $ids); }
}

/** Handles the modules / sections / blocks routes. Always ends the request. */
function crc_handle(string $part, int $cid, int $sid, string $act, string $method, array $b, array $u, array $course, bool $isAdmin, bool $ro): never {
    $uid = (int)$u['id'];
    // ----- learners
    if ($part === 'sections' && $sid && $method === 'GET' && $act === '') {
        $s = one('SELECT s.*, m.title AS module_title FROM course_sections s JOIN course_modules m ON m.id=s.module_id WHERE s.id=? AND s.course_id=?', [$sid, $cid]); if (!$s) fail('Section not found', 404);
        $flat = crc_flat($cid); $ids = array_map(fn($x) => (int)$x['id'], $flat); $i = array_search($sid, $ids, true);
        $blocks = array_map(fn($x) => crc_block_out($x, $uid, $isAdmin), all('SELECT * FROM course_blocks WHERE section_id=? ORDER BY position,id', [$sid]));
        out(['id' => $sid, 'title' => $s['title'], 'module' => ['id' => (int)$s['module_id'], 'title' => $s['module_title']], 'blocks' => $blocks, 'done' => (bool)one('SELECT 1 FROM course_progress WHERE user_id=? AND section_id=?', [$uid, $sid]),
             'prev' => $i > 0 ? ['id' => (int)$flat[$i - 1]['id'], 'title' => $flat[$i - 1]['title']] : null, 'next' => $i !== false && $i < count($flat) - 1 ? ['id' => (int)$flat[$i + 1]['id'], 'title' => $flat[$i + 1]['title']] : null,
             'index' => $i === false ? 0 : $i + 1, 'count' => count($flat), 'can_manage' => $isAdmin]);
    }
    if ($part === 'sections' && $sid && $method === 'POST' && ($act === 'complete' || $act === 'quiz')) {
        if ($ro) fail('Founder accounts are read-only', 403); $s = one('SELECT id FROM course_sections WHERE id=? AND course_id=?', [$sid, $cid]); if (!$s) fail('Section not found', 404);
        if ($act === 'quiz') {
            $bid = (int)($GLOBALS['seg'][5] ?? 0); $blk = $bid ? one("SELECT * FROM course_blocks WHERE id=? AND section_id=? AND kind='quiz'", [$bid, $sid]) : null; if (!$blk) fail('Quiz not found', 404);
            $qs = (json_decode((string)$blk['data'], true) ?: [])['questions'] ?? []; $given = array_values(array_map('intval', (array)($b['answers'] ?? []))); $right = 0; $fb = [];
            if (count($given) < count($qs) || in_array(-1, array_slice($given, 0, count($qs)), true)) fail('Answer every question first');
            foreach ($qs as $i => $q) { $ok = (int)$given[$i] === (int)$q['correct']; if ($ok) $right++; $fb[] = ['chosen' => (int)$given[$i], 'correct' => (int)$q['correct'], 'is_correct' => $ok, 'explain' => $q['explain'] ?? '']; }
            $score = $qs ? round($right * 100 / count($qs), 1) : 0.0;
            q('INSERT INTO course_quiz_results (user_id,block_id,score,correct,total,answers) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score), correct=VALUES(correct), total=VALUES(total), answers=VALUES(answers), taken_at=NOW()', [$uid, $bid, $score, $right, count($qs), json_encode(array_slice($given, 0, count($qs)))]);
            out(['score' => $score, 'correct' => $right, 'total' => count($qs), 'feedback' => $fb]);
        }
        $open = one("SELECT COUNT(*) n FROM course_blocks b WHERE b.section_id=? AND b.kind='quiz' AND NOT EXISTS (SELECT 1 FROM course_quiz_results r WHERE r.block_id=b.id AND r.user_id=?)", [$sid, $uid]);
        if ((int)$open['n'] > 0) fail('Answer the short quiz in this section first, then mark it complete', 409);
        q('INSERT IGNORE INTO course_progress (user_id,section_id,course_id) VALUES (?,?,?)', [$uid, $sid, $cid]); out(['ok' => true, 'progress' => crc_progress(crc_modules($cid, $uid))]);
    }
    // ----- the Super Admin builds the content
    if (!$isAdmin) fail('Only the Super Admin edits course content', 403);
    $pos = fn(string $t, string $w, array $p) => (int)(one("SELECT COALESCE(MAX(position),0)+1 p FROM $t WHERE $w", $p)['p']);
    if ($part === 'modules') {
        if ($method === 'POST' && !$sid) { $t = cr_str($b['title'] ?? '', 200); if ($t === '') fail('Give the module a title'); q('INSERT INTO course_modules (course_id,position,title,summary) VALUES (?,?,?,?)', [$cid, $pos('course_modules', 'course_id=' . $cid, []), $t, cr_str($b['summary'] ?? '', 500) ?: null]); out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201); }
        $m = $sid ? one('SELECT * FROM course_modules WHERE id=? AND course_id=?', [$sid, $cid]) : null; if (!$m) fail('Module not found', 404);
        if ($method === 'PATCH') { $t = array_key_exists('title', $b) ? cr_str($b['title'], 200) : $m['title']; if ($t === '') fail('Give the module a title'); q('UPDATE course_modules SET title=?, summary=? WHERE id=?', [$t, array_key_exists('summary', $b) ? (cr_str($b['summary'], 500) ?: null) : $m['summary'], $sid]); out(['ok' => true]); }
        if ($method === 'DELETE') { q('DELETE FROM course_modules WHERE id=?', [$sid]); out(['ok' => true]); }
        if ($method === 'POST' && $act === 'move') { crc_move('course_modules', 'course_id=?', [$cid], $sid, (int)($b['dir'] ?? 0) < 0 ? -1 : 1); out(['ok' => true]); }
    }
    if ($part === 'sections') {
        if ($method === 'POST' && !$sid) { $mid = (int)($b['module_id'] ?? 0); if (!one('SELECT 1 FROM course_modules WHERE id=? AND course_id=?', [$mid, $cid])) fail('Choose the module'); $t = cr_str($b['title'] ?? '', 200); if ($t === '') fail('Give the section a title');
            q('INSERT INTO course_sections (course_id,module_id,position,title) VALUES (?,?,?,?)', [$cid, $mid, $pos('course_sections', 'module_id=' . $mid, []), $t]); out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201); }
        $s = $sid ? one('SELECT * FROM course_sections WHERE id=? AND course_id=?', [$sid, $cid]) : null; if (!$s) fail('Section not found', 404);
        if ($method === 'PATCH') { $t = array_key_exists('title', $b) ? cr_str($b['title'], 200) : $s['title']; if ($t === '') fail('Give the section a title'); $mid = (int)$s['module_id'];
            if (!empty($b['module_id']) && (int)$b['module_id'] !== $mid && one('SELECT 1 FROM course_modules WHERE id=? AND course_id=?', [(int)$b['module_id'], $cid])) { $mid = (int)$b['module_id']; q('UPDATE course_sections SET position=? WHERE id=?', [$pos('course_sections', 'module_id=' . $mid, []), $sid]); }
            q('UPDATE course_sections SET title=?, module_id=? WHERE id=?', [$t, $mid, $sid]); out(['ok' => true]); }
        if ($method === 'DELETE') { q('DELETE FROM course_sections WHERE id=?', [$sid]); out(['ok' => true]); }
        if ($method === 'POST' && $act === 'move') { crc_move('course_sections', 'module_id=?', [(int)$s['module_id']], $sid, (int)($b['dir'] ?? 0) < 0 ? -1 : 1); out(['ok' => true]); }
        if ($method === 'POST' && $act === 'slides') {   // a PowerPoint becomes a "slides" block of this section
            $bin = base64_decode((string)($b['data'] ?? ''), true); if ($bin === false || $bin === '') fail('The file could not be read'); if (strlen($bin) > 15 * 1024 * 1024) fail('That file is larger than 15 MB');
            $slides = cr_parse_pptx($bin, $uid); $title = cr_str($b['title'] ?? '', 200) ?: preg_replace('/\.pptx?$/i', '', cr_str($b['name'] ?? 'Presentation', 200));
            q('INSERT INTO course_blocks (course_id,section_id,position,kind,title,data) VALUES (?,?,?,?,?,?)', [$cid, $sid, $pos('course_blocks', 'section_id=' . $sid, []), 'slides', $title, json_encode(['slides' => $slides], JSON_UNESCAPED_UNICODE)]); out(['ok' => true, 'slides' => count($slides)], 201); }
    }
    if ($part === 'blocks') {
        if ($method === 'POST' && !$sid) { $secId = (int)($b['section_id'] ?? 0); if (!one('SELECT 1 FROM course_sections WHERE id=? AND course_id=?', [$secId, $cid])) fail('Choose the section'); $kind = in((string)($b['kind'] ?? ''), CRC_KINDS, ''); if ($kind === '' || $kind === 'slides') fail('Choose what to add');
            [$title, $data] = crc_block_in($kind, $b); q('INSERT INTO course_blocks (course_id,section_id,position,kind,title,data) VALUES (?,?,?,?,?,?)', [$cid, $secId, $pos('course_blocks', 'section_id=' . $secId, []), $kind, $title, $data]); out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201); }
        $blk = $sid ? one('SELECT * FROM course_blocks WHERE id=? AND course_id=?', [$sid, $cid]) : null; if (!$blk) fail('Not found', 404);
        if ($method === 'DELETE') { q('DELETE FROM course_blocks WHERE id=?', [$sid]); out(['ok' => true]); }
        if ($method === 'PATCH') { if ($blk['kind'] === 'slides') { $t = cr_str($b['title'] ?? $blk['title'], 200); q('UPDATE course_blocks SET title=? WHERE id=?', [$t ?: $blk['title'], $sid]); out(['ok' => true]); }
            [$title, $data] = crc_block_in($blk['kind'], $b); q('UPDATE course_blocks SET title=?, data=? WHERE id=?', [$title, $data, $sid]); if ($blk['kind'] === 'quiz') q('DELETE FROM course_quiz_results WHERE block_id=?', [$sid]); out(['ok' => true]); }   // a changed quiz starts fresh
        if ($method === 'POST' && $act === 'move') { crc_move('course_blocks', 'section_id=?', [(int)$blk['section_id']], $sid, (int)($b['dir'] ?? 0) < 0 ? -1 : 1); out(['ok' => true]); }
    }
    fail('Bad request', 404);
}
