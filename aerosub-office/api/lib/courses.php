<?php
declare(strict_types=1);
// Courses (the "Courses" tab of Learn). Route: /api/courses
//   GET  courses                          the courses I can take (the Super Admin also sees drafts)
//   GET  courses/{id}                     one course: content, my attempts
//   POST courses / PATCH courses/{id} / DELETE courses/{id}                       (Super Admin)
//   POST|PATCH|DELETE courses/{id}/items[/{item}]   POST courses/{id}/items/{item}/move   POST courses/{id}/slides   (Super Admin)
//   GET|POST|PATCH|DELETE courses/{id}/questions[/{q}]   POST courses/{id}/questions/import   GET courses/template     (Super Admin)
//   POST courses/{id}/start  ·  POST courses/{id}/attempts/{a}/save  ·  POST courses/{id}/attempts/{a}/submit  ·  GET courses/{id}/attempts/{a}
//   GET  courses/{id}/grades[?user=][&format=csv]  ·  GET courses/grades  ·  POST courses/{id}/grant
require_once __DIR__ . '/xlsx.php';
$u = need_user(); $uid = (int)$u['id']; $role = $u['role']; $isAdmin = $role === 'super_admin'; $ro = $role === 'founder';
$cid = isset($seg[1]) && ctype_digit($seg[1]) ? (int)$seg[1] : 0;
$part = $cid ? ($seg[2] ?? '') : ($seg[1] ?? '');
$sid = isset($seg[3]) && ctype_digit($seg[3]) ? (int)$seg[3] : 0;
$act = $seg[4] ?? '';
$b = in_array($method, ['POST', 'PATCH', 'PUT'], true) ? body() : [];
const CR_LETTERS = ['A', 'B', 'C', 'D', 'E'];

function cr_str($v, int $n): string { return mb_substr(trim((string)($v ?? '')), 0, $n); }
function cr_img($v): ?string { $v = trim((string)($v ?? '')); if ($v === '') return null; if (!preg_match('~^(https://[^\s"<>]+|api/learn/img/\d+)$~i', $v) || strlen($v) > 500) fail('A picture link must start with https:// (or be an uploaded picture)'); return $v; }
function cr_url($v): string { $v = trim((string)($v ?? '')); if (!preg_match('~^https://[^\s"<>]+$~i', $v) || strlen($v) > 500) fail('The link must start with https://'); return $v; }
function cr_course(int $id): array { $c = $id ? one('SELECT * FROM courses WHERE id=?', [$id]) : null; if (!$c) fail('Course not found', 404); return $c; }
function cr_depts(int $id): array { return array_map('intval', array_column(all('SELECT department_id FROM course_departments WHERE course_id=?', [$id]), 'department_id')); }
function cr_visible(array $u, array $c): bool {
    if ($u['role'] === 'super_admin') return true;
    if ($c['status'] !== 'published') return false;
    if ($u['role'] === 'founder') return true;
    $d = cr_depts((int)$c['id']); return !$d || (bool)array_intersect($d, array_map('intval', $u['department_ids'] ?? []));
}
/** Who may see everyone's grades for this course: null = everyone, array = only people in these departments, false = only their own. */
function cr_grade_scope(array $u, array $c) {
    if (in_array($u['role'], ['super_admin', 'founder'], true)) return null;
    if (one('SELECT 1 FROM course_viewers WHERE course_id=? AND user_id=?', [$c['id'], $u['id']])) return null;
    $m = managed_dept_ids($u); if ((int)$c['heads_see_grades'] === 1 && $m) return $m;
    return false;
}
function cr_learners(array $c, $scope): array { // the people this course is for (optionally only those in some departments)
    $d = cr_depts((int)$c['id']);
    $sql = "SELECT DISTINCT u.id,u.name,u.job_title,(SELECT d.name FROM departments d WHERE d.id=u.department_id) AS department FROM users u WHERE u.active=1 AND u.role<>'founder'";
    if ($d) $sql .= ' AND u.id IN ' . rp_member_sql_cr($d);
    if (is_array($scope)) $sql .= ' AND u.id IN ' . rp_member_sql_cr($scope);
    return all($sql . ' ORDER BY u.name');
}
function rp_member_sql_cr(array $deptIds): string { $l = implode(',', array_map('intval', $deptIds)) ?: '0'; return "(SELECT user_id FROM user_departments WHERE department_id IN ($l) UNION SELECT id FROM users WHERE department_id IN ($l))"; }
function cr_extra(int $cid, int $uid): int { $r = one('SELECT extra FROM course_grants WHERE course_id=? AND user_id=?', [$cid, $uid]); return $r ? (int)$r['extra'] : 0; }
/** My attempts at a course. remaining = null when unlimited. */
function cr_mine(array $c, int $uid): array {
    $rows = all('SELECT id,attempt_no,started_at,submitted_at,score,correct_count,total,passed FROM course_attempts WHERE course_id=? AND user_id=? ORDER BY id', [$c['id'], $uid]);
    $done = array_values(array_filter($rows, fn($r) => $r['submitted_at'] !== null)); $best = null; $passed = false;
    foreach ($done as $r) { if ($best === null || (float)$r['score'] > $best) $best = (float)$r['score']; if ((int)$r['passed'] === 1) $passed = true; }
    $max = (int)$c['max_attempts']; $remaining = $max === 0 ? null : max(0, $max + cr_extra((int)$c['id'], $uid) - count($done));
    $open = null; foreach ($rows as $r) if ($r['submitted_at'] === null) $open = (int)$r['id'];
    return ['attempts' => $done, 'count' => count($done), 'best' => $best, 'passed' => $passed, 'remaining' => $remaining, 'open_attempt' => $open, 'last_at' => $done ? end($done)['submitted_at'] : null];
}
function cr_video(string $u): array {
    $u = trim($u);
    if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com|youtube-nocookie\.com)/(?:watch\?(?:[^#]*&)?v=|embed/|shorts/|live/)([A-Za-z0-9_-]{11})~i', $u, $m) || preg_match('~^https?://youtu\.be/([A-Za-z0-9_-]{11})~i', $u, $m)) return ['provider' => 'youtube', 'id' => $m[1]];
    if (preg_match('~^https?://(?:www\.)?vimeo\.com/(?:channels/[^/]+/|groups/[^/]+/videos/)?(\d{5,12})(?:/([a-f0-9]{6,20}))?~i', $u, $m) || preg_match('~^https?://player\.vimeo\.com/video/(\d{5,12})(?:\?h=([a-f0-9]{6,20}))?~i', $u, $m)) return ['provider' => 'vimeo', 'id' => $m[1], 'h' => $m[2] ?? ''];
    fail('Paste a YouTube or Vimeo link, for example https://www.youtube.com/watch?v=… or https://vimeo.com/123456789');
}

/* ---------------- PowerPoint: read a .pptx into slides (text, tables, pictures) ---------------- */
function cr_parse_pptx(string $bin, int $uid): array {
    xlsx_need_zip();
    if (substr($bin, 0, 4) === "\xD0\xCF\x11\xE0") fail('That is an old .ppt file. Open it in PowerPoint and Save As .pptx, then upload it again.');
    $tmp = tempnam(sys_get_temp_dir(), 'pp'); file_put_contents($tmp, $bin); $z = new ZipArchive();
    if ($z->open($tmp) !== true || $z->locateName('ppt/presentation.xml') === false) { @unlink($tmp); fail('That does not look like a PowerPoint (.pptx) file.'); }
    $names = []; for ($i = 0; $i < $z->numFiles; $i++) { $n = $z->getNameIndex($i); if (preg_match('~^ppt/slides/slide(\d+)\.xml$~', $n, $m)) $names[(int)$m[1]] = $n; }
    ksort($names); $slides = []; $imgCount = 0; $ns = ['a' => 'http://schemas.openxmlformats.org/drawingml/2006/main', 'p' => 'http://schemas.openxmlformats.org/presentationml/2006/main', 'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'];
    foreach (array_slice($names, 0, 200, true) as $num => $path) {
        if (($st0 = $z->statName($path)) && $st0['size'] > 8 * 1024 * 1024) continue; // a slide's XML is never this big: skip rather than risk memory
        $dom = new DOMDocument(); if (!@$dom->loadXML((string)$z->getFromName($path), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) continue;
        $xp = new DOMXPath($dom); foreach ($ns as $k => $v) $xp->registerNamespace($k, $v);
        $rels = []; $rx = $z->getFromName('ppt/slides/_rels/slide' . $num . '.xml.rels');
        if ($rx !== false && ($rd = @simplexml_load_string($rx))) foreach ($rd->Relationship as $r) $rels[(string)$r['Id']] = (string)$r['Target'];
        $title = ''; $blocks = [];
        $tree = $xp->query('//p:cSld/p:spTree')->item(0); if (!$tree) continue;
        foreach ($tree->childNodes as $node) {
            if ($node->nodeType !== XML_ELEMENT_NODE) continue; $nm = $node->localName;
            if ($nm === 'sp') {
                $ph = $xp->query('.//p:nvPr/p:ph/@type', $node)->item(0); $isTitle = $ph && in_array($ph->nodeValue, ['title', 'ctrTitle'], true);
                $lines = []; foreach ($xp->query('.//a:p', $node) as $p) { $t = ''; foreach ($xp->query('.//a:t', $p) as $tn) $t .= $tn->textContent; $t = trim($t); if ($t !== '') $lines[] = $t; }
                if ($isTitle && $title === '') $title = implode(' ', $lines); elseif ($lines) $blocks[] = ['k' => 'p', 'v' => implode("\n", $lines)];
            } elseif ($nm === 'graphicFrame') {
                $rows = []; foreach ($xp->query('.//a:tbl/a:tr', $node) as $tr) { $cells = []; foreach ($xp->query('./a:tc', $tr) as $tc) { $t = ''; foreach ($xp->query('.//a:t', $tc) as $tn) $t .= $tn->textContent . ' '; $cells[] = trim($t); } $rows[] = implode(' | ', $cells); }
                if ($rows) $blocks[] = ['k' => 'p', 'v' => implode("\n", $rows)];
            } elseif ($nm === 'pic') {
                $e = $xp->query('.//a:blip/@r:embed', $node)->item(0); $tg = $e ? ($rels[$e->nodeValue] ?? '') : '';
                if ($tg !== '' && $imgCount < 80) { $mp = 'ppt/' . ltrim(preg_replace('~^(\.\./)+~', '', $tg), '/'); $stI = $z->statName($mp); $img = ($stI && $stI['size'] <= 1500000) ? $z->getFromName($mp) : false;
                    if ($img !== false && strlen($img) <= 1500000 && ($info = @getimagesizefromstring($img)) && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
                        q('INSERT INTO sop_images (mime,data,created_by) VALUES (?,?,?)', [image_type_to_mime_type($info[2]), $img, $uid]); $blocks[] = ['k' => 'img', 'v' => 'api/learn/img/' . (int)db()->lastInsertId()]; $imgCount++; } }
            }
        }
        $slides[] = ['n' => count($slides) + 1, 'title' => mb_substr($title, 0, 300), 'blocks' => $blocks];
    }
    $z->close(); @unlink($tmp);
    if (!$slides) fail('No slides could be read from that file.');
    return $slides;
}

/* ---------------- question spreadsheet / CSV import ---------------- */
function cr_norm(string $h): string { return preg_replace('/[^a-z0-9]/', '', mb_strtolower($h)); }
function cr_read_table(string $name, string $bin): array {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') { $tmp = tempnam(sys_get_temp_dir(), 'qi'); file_put_contents($tmp, $bin); try { $rows = xlsx_read($tmp); } catch (RuntimeException $e) { @unlink($tmp); fail($e->getMessage()); } @unlink($tmp); return $rows; }
    if (in_array($ext, ['csv', 'txt', 'tsv'], true)) {
        $bin = preg_replace('/^\xEF\xBB\xBF/', '', $bin); if (!mb_check_encoding($bin, 'UTF-8')) $bin = mb_convert_encoding($bin, 'UTF-8', 'Windows-1252');
        $first = strtok($bin, "\n") ?: ''; $d = ','; foreach (["\t", ';'] as $try) if (substr_count($first, $try) > substr_count($first, $d)) $d = $try;
        $rows = []; $h = fopen('php://temp', 'r+'); fwrite($h, $bin); rewind($h); while (($r = fgetcsv($h, 0, $d, '"', '')) !== false) { if ($r === [null]) continue; $rows[] = array_map(fn($x) => trim((string)$x), $r); } fclose($h); return $rows;
    }
    fail('Upload an Excel (.xlsx) or CSV file. Use "Download template" for the right columns.');
}
/** @return array{0: array, 1: array} [valid rows, errors] */
function cr_parse_questions(array $table): array {
    $col = ['q' => 0, 'correct' => 1, 'img' => 2, 'a' => 3, 'b' => 4, 'c' => 5, 'd' => 6, 'e' => 7, 'hint' => 8]; $start = 0;
    foreach ($table as $i => $row) { // find the header row
        $norm = array_map('cr_norm', $row); if (!array_intersect($norm, ['question', 'questions'])) { if ($i >= 5) break; continue; }
        $map = []; foreach ($norm as $ci => $h) {
            if (in_array($h, ['question', 'questions'], true)) $map['q'] = $ci; elseif (in_array($h, ['correctanswer', 'correct', 'answer', 'rightanswer'], true)) $map['correct'] = $ci;
            elseif (in_array($h, ['imagelink', 'image', 'imageurl', 'picture', 'imagelinkoptional'], true)) $map['img'] = $ci; elseif ($h === 'hint' || $h === 'hints') $map['hint'] = $ci;
            else foreach (['a', 'b', 'c', 'd', 'e'] as $l) if (in_array($h, ['answer' . $l, 'option' . $l, $l, 'choice' . $l], true)) $map[$l] = $ci; }
        if (isset($map['q'])) { $col = $map + ['correct' => -1, 'img' => -1, 'a' => -1, 'b' => -1, 'c' => -1, 'd' => -1, 'e' => -1, 'hint' => -1]; $start = $i + 1; }
        break;
    }
    $ok = []; $err = [];
    for ($i = $start; $i < count($table); $i++) {
        $row = $table[$i]; $g = fn($k) => ($col[$k] ?? -1) >= 0 ? trim((string)($row[$col[$k]] ?? '')) : ''; $line = $i + 1;
        $q = $g('q'); if ($q === '' && !array_filter($row)) continue; if ($q === '') { $err[] = ['row' => $line, 'error' => 'The question is empty']; continue; }
        $opts = []; foreach (['a', 'b', 'c', 'd', 'e'] as $l) $opts[$l] = mb_substr($g($l), 0, 500);
        $have = array_keys(array_filter($opts, fn($x) => $x !== '')); if (count($have) < 2) { $err[] = ['row' => $line, 'error' => 'Give at least two answers']; continue; }
        $cv = $g('correct'); $letter = '';
        if (preg_match('/^(?:answer|option|choice)?\s*([A-Ea-e])$/', $cv, $m)) $letter = strtolower($m[1]);
        else foreach ($opts as $l => $t) if ($t !== '' && mb_strtolower($t) === mb_strtolower($cv)) { $letter = $l; break; }
        $fixed = false;
        if ($letter === '' && mb_strlen($cv) > 1) { // the correct answer is written out but is missing from the choices: add it instead of losing the question
            $free = array_keys(array_filter($opts, fn($x) => $x === ''));
            if ($free) { $letter = $free[0]; $opts[$letter] = mb_substr($cv, 0, 500); }
            else { $opts['e'] = mb_substr($cv, 0, 500); $pick = ['a', 'b', 'c', 'd', 'e'][$i % 5]; [$opts['e'], $opts[$pick]] = [$opts[$pick], $opts['e']]; $letter = $pick === 'e' ? 'e' : $pick; $letter = array_search(mb_substr($cv, 0, 500), $opts, true); } // the last wrong choice makes room; spread the right answer over A-E
            $fixed = true;
        }
        if ($letter === '' || $opts[$letter] === '') { $err[] = ['row' => $line, 'error' => $cv === '' ? 'No correct answer given' : 'The correct answer "' . mb_substr($cv, 0, 40) . '" is not one of the answers']; continue; }
        $img = $g('img'); if ($img !== '' && !preg_match('~^(https://[^\s"<>]+|api/learn/img/\d+)$~i', $img)) { $err[] = ['row' => $line, 'error' => 'The image link must start with https://']; continue; }
        $ok[] = ['question' => mb_substr($q, 0, 2000), 'image_url' => $img !== '' ? mb_substr($img, 0, 500) : null, 'opts' => $opts, 'correct' => strtoupper($letter), 'fixed' => $fixed, 'hint' => $g('hint') !== '' ? mb_substr($g('hint'), 0, 500) : null];
    }
    return [$ok, $err];
}
const CR_TYPES = ['mcq', 'order', 'match'];
function cr_insert_question(int $cid, array $q, int $pos): void {
    q('INSERT INTO course_questions (course_id,position,qtype,question,extra_text,image_url,video,puzzle,opt_a,opt_b,opt_c,opt_d,opt_e,correct,hint) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [$cid, $pos, $q['qtype'] ?? 'mcq', $q['question'], $q['extra_text'] ?? null, $q['image_url'], $q['video'] ?? null, $q['puzzle'] ?? null, $q['opts']['a'] ?: null, $q['opts']['b'] ?: null, $q['opts']['c'] ?: null, $q['opts']['d'] ?: null, $q['opts']['e'] ?: null, $q['correct'], $q['hint']]);
}
/** One question from the editor: multiple choice, or a puzzle (put the steps in order / match the pairs), each with optional text, picture and video. */
function cr_question_in(array $b): array {
    $type = in((string)($b['qtype'] ?? 'mcq'), CR_TYPES, 'mcq'); $q = cr_str($b['question'] ?? '', 2000); if ($q === '') fail('Write the question');
    $vurl = trim((string)($b['video_url'] ?? '')); $video = null; if ($vurl !== '') { $vurl = cr_url($vurl); $video = json_encode(cr_video($vurl) + ['url' => $vurl], JSON_UNESCAPED_SLASHES); }
    $out = ['qtype' => $type, 'question' => $q, 'extra_text' => cr_str($b['extra_text'] ?? '', 5000) ?: null, 'image_url' => cr_img($b['image_url'] ?? ''), 'video' => $video, 'puzzle' => null, 'hint' => cr_str($b['hint'] ?? '', 500) ?: null];
    if ($type === 'mcq') {
        $opts = []; foreach (['a', 'b', 'c', 'd', 'e'] as $l) $opts[$l] = cr_str($b['opt_' . $l] ?? '', 500);
        if (count(array_filter($opts, fn($x) => $x !== '')) < 2) fail('Give at least two answers');
        $c = strtoupper(cr_str($b['correct'] ?? '', 1)); if (!in_array($c, CR_LETTERS, true) || $opts[strtolower($c)] === '') fail('Choose which answer is correct (it must have text)');
        return $out + ['opts' => $opts, 'correct' => $c];
    }
    $none = ['a' => '', 'b' => '', 'c' => '', 'd' => '', 'e' => ''];
    if ($type === 'order') {
        $items = array_values(array_filter(array_map(fn($x) => cr_str($x, 200), (array)($b['items'] ?? [])), fn($x) => $x !== '')); if (count($items) < 2 || count($items) > 8) fail('An ordering puzzle needs 2 to 8 steps, written in the CORRECT order');
        return ['puzzle' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE)] + $out + ['opts' => $none, 'correct' => 'A'];
    }
    $pairs = []; foreach ((array)($b['pairs'] ?? []) as $p) { if (!is_array($p)) continue; $l = cr_str($p['l'] ?? '', 200); $r = cr_str($p['r'] ?? '', 200); if ($l === '' && $r === '') continue; if ($l === '' || $r === '') fail('Every pair needs both sides'); $pairs[] = ['l' => $l, 'r' => $r]; }
    if (count($pairs) < 2 || count($pairs) > 6) fail('A matching puzzle needs 2 to 6 pairs');
    return ['puzzle' => json_encode(['pairs' => $pairs], JSON_UNESCAPED_UNICODE)] + $out + ['opts' => $none, 'correct' => 'A'];
}
function crc_move_q(int $cid, int $id, int $dir): void {
    $ids = array_map('intval', array_column(all('SELECT id FROM course_questions WHERE course_id=? ORDER BY position,id', [$cid]), 'id')); $i = array_search($id, $ids, true); $j = $i + $dir;
    if ($i !== false && $j >= 0 && $j < count($ids)) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; foreach ($ids as $n => $x) q('UPDATE course_questions SET position=? WHERE id=?', [$n + 1, $x]); }
}
function cr_q_out(array $r, bool $withAnswer): array {
    $o = ['id' => (int)$r['id'], 'qtype' => $r['qtype'] ?? 'mcq', 'question' => $r['question'], 'extra_text' => $r['extra_text'] ?? null, 'image_url' => $r['image_url'], 'video' => !empty($r['video']) ? json_decode((string)$r['video'], true) : null, 'hint' => $r['hint'],
          'opt_a' => $r['opt_a'], 'opt_b' => $r['opt_b'], 'opt_c' => $r['opt_c'], 'opt_d' => $r['opt_d'], 'opt_e' => $r['opt_e']];
    if ($withAnswer) { $o['correct'] = $r['correct']; $o['puzzle'] = !empty($r['puzzle']) ? json_decode((string)$r['puzzle'], true) : null; } return $o;
}
/** A question as the person taking the test sees it (puzzles are shuffled; nothing reveals the answer). */
function cr_q_play(array $r, array $x): array {
    $t = $r['qtype'] ?? 'mcq'; $o = ['id' => (int)$r['id'], 'qtype' => $t, 'question' => $r['question'], 'extra_text' => $r['extra_text'] ?? null, 'image_url' => $r['image_url'], 'video' => !empty($r['video']) ? json_decode((string)$r['video'], true) : null, 'hint' => $r['hint']];
    if ($t === 'mcq') { $o['options'] = array_map(fn($k) => ['k' => $k, 'text' => $r['opt_' . strtolower($k)]], $x['map'] ?? []); return $o; }
    $pz = json_decode((string)$r['puzzle'], true) ?: []; $perm = $x['perm'] ?? [];
    if ($t === 'order') { $it = $pz['items'] ?? []; $o['items'] = array_map(fn($i) => ['i' => (int)$i, 'text' => $it[$i] ?? ''], $perm); }
    else { $pr = $pz['pairs'] ?? []; $o['left'] = array_map(fn($i) => ['i' => (int)$i, 'text' => $pr[$i]['l']], array_keys($pr)); $o['right'] = array_map(fn($i) => ['i' => (int)$i, 'text' => $pr[$i]['r'] ?? ''], $perm); }
    return $o;
}
/** Grades one answer. @return array{0: bool, 1: array} [correct?, the review entry] */
function cr_grade_q(array $r, array $x, $given): array {
    $t = $r['qtype'] ?? 'mcq'; $base = ['id' => (int)$r['id'], 'qtype' => $t, 'question' => $r['question'], 'extra_text' => $r['extra_text'] ?? null, 'image_url' => $r['image_url'], 'video' => !empty($r['video']) ? json_decode((string)$r['video'], true) : null, 'hint' => $r['hint']];
    if ($t === 'mcq') { $sel = strtoupper(is_string($given) ? $given : ''); $ok = $sel !== '' && $sel === $r['correct'];
        return [$ok, $base + ['selected' => $sel ?: null, 'correct' => $r['correct'], 'is_correct' => $ok, 'options' => array_map(fn($k) => ['k' => $k, 'text' => $r['opt_' . strtolower($k)]], $x['map'] ?? [])]]; }
    $pz = json_decode((string)$r['puzzle'], true) ?: []; $g = is_array($given) ? array_values(array_map('intval', $given)) : [];
    if ($t === 'order') { $items = $pz['items'] ?? []; $n = count($items); $ok = $n > 0 && $g === range(0, $n - 1);
        return [$ok, $base + ['is_correct' => $ok, 'answered' => (bool)$g, 'correct_items' => $items, 'given_items' => $g ? array_map(fn($i) => $items[$i] ?? '?', $g) : null]]; }
    $pairs = $pz['pairs'] ?? []; $n = count($pairs); $ok = $n > 0 && count($g) === $n; $rows = [];
    foreach ($pairs as $i => $p) { $gv = $g[$i] ?? -1; $good = $gv === $i; if (!$good) $ok = false; $rows[] = ['l' => $p['l'], 'r' => $p['r'], 'given' => ($gv >= 0 && isset($pairs[$gv])) ? $pairs[$gv]['r'] : null, 'ok' => $good]; }
    return [$ok, $base + ['is_correct' => $ok, 'answered' => (bool)array_filter($g, fn($v) => $v >= 0), 'pairs' => $rows]];
}
/** Keeps only well-formed answers: a letter A-E for choice questions, a short list of numbers for puzzles. */
function cr_clean_answers(array $in): array {
    $o = []; foreach ($in as $k => $v) { if (!ctype_digit((string)$k)) continue;
        if (is_string($v) && preg_match('/^[A-Ea-e]$/', $v)) $o[(string)(int)$k] = strtoupper($v);
        elseif (is_array($v) && count($v) <= 12) { $arr = []; foreach ($v as $x) { if (!is_int($x) && !(is_string($x) && preg_match('/^-?\d{1,2}$/', $x))) continue 2; $arr[] = (int)$x; } $o[(string)(int)$k] = $arr; } }
    return $o;
}

/* ---------------- grading ---------------- */
function cr_finish(array $a, array $c, array $answers): array {
    $qs = json_decode((string)$a['questions'], true) ?: []; $ids = array_map(fn($x) => (int)$x['id'], $qs); $rows = [];
    if ($ids) foreach (all('SELECT * FROM course_questions WHERE id IN (' . implode(',', $ids) . ')') as $r) $rows[(int)$r['id']] = $r;
    $total = count($qs); $right = 0; $review = [];
    foreach ($qs as $x) { $r = $rows[(int)$x['id']] ?? null; if (!$r) { $total--; continue; } [$ok, $rv] = cr_grade_q($r, $x, $answers[(string)$x['id']] ?? null); if ($ok) $right++; $review[] = $rv; }
    $score = $total ? round($right * 100 / $total, 2) : 0.0; $passed = $score >= (int)$c['pass_mark'] ? 1 : 0;
    q('UPDATE course_attempts SET submitted_at=NOW(), score=?, correct_count=?, total=?, passed=?, answers=? WHERE id=?', [$score, $right, $total, $passed, json_encode($answers), $a['id']]);
    return ['score' => $score, 'correct' => $right, 'total' => $total, 'passed' => (bool)$passed, 'review' => $review];
}

/* ===================================================================== routes ===================================================================== */

/* ---- spreadsheet template ---- */
if ($method === 'GET' && $part === 'template' && !$cid) {
    if (!$isAdmin) fail('Not allowed', 403);
    $head = ['Questions', 'Correct Answer', 'Image Link', 'Answer A', 'Answer B', 'Answer C', 'Answer D', 'Answer E', 'Hint'];
    $ex = [['Which colour is the Aerosub logo wordmark partly written in?', 'B', '', 'Red', 'Blue', 'Green', 'Yellow', '', 'Think of the sea'], ['A drone pilot must always check the weather before flying.', 'A', 'https://example.com/weather.png', 'True', 'False', '', '', '', 'Safety first']];
    try {
        $rows = [1 => ['h' => 24, 'cells' => []]]; foreach ($head as $i => $h) $rows[1]['cells'][$i + 1] = ['v' => $h, 's' => ['b' => true, 'fill' => 'D9E8F5', 'border' => 'thin', 'font' => 'Arial', 'wrap' => true]];
        foreach ($ex as $ri => $r) foreach ($r as $i => $v) $rows[$ri + 2]['cells'][$i + 1] = ['v' => $v, 's' => ['border' => 'thin', 'font' => 'Arial', 'wrap' => true, 'v' => 'top']];
        $rows[5]['cells'][1] = ['v' => 'Delete the two example rows above and type your questions from row 2. "Correct Answer" is a letter A to E (or the full text of the right answer). Leave unused answers empty.', 's' => ['i' => true, 'color' => '666666', 'font' => 'Arial']];
        $bin = xlsx_write(['name' => 'Questions', 'cols' => [55, 15, 32, 28, 28, 28, 28, 28, 30], 'rows' => $rows]);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="Course_questions_template.xlsx"'); header('Content-Length: ' . strlen($bin)); echo $bin; exit;
    } catch (RuntimeException $e) {
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="Course_questions_template.csv"'); $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, $head, ',', '"', ''); foreach ($ex as $r) fputcsv($o, $r, ',', '"', ''); exit;
    }
}

/* ---- list ---- */
if ($method === 'GET' && !$cid && $part === '') {
    $out = []; $secDone = array_column(all('SELECT course_id, COUNT(*) n FROM course_progress WHERE user_id=? GROUP BY course_id', [$uid]), 'n', 'course_id');
    foreach (all('SELECT c.*, (SELECT COUNT(*) FROM course_questions q WHERE q.course_id=c.id) AS question_count, (SELECT COUNT(*) FROM course_sections i WHERE i.course_id=c.id) AS item_count FROM courses c ORDER BY c.status, c.title') as $c) {
        if (!cr_visible($u, $c)) continue; $depts = cr_depts((int)$c['id']);
        $o = ['id' => (int)$c['id'], 'title' => $c['title'], 'description' => mb_substr((string)$c['description'], 0, 220), 'image_url' => $c['image_url'], 'category' => $c['category'], 'status' => $c['status'], 'pass_mark' => (int)$c['pass_mark'],
              'question_count' => (int)$c['question_count'], 'item_count' => (int)$c['item_count'], 'department_ids' => $depts, 'departments' => $depts ? array_column(all('SELECT name FROM departments WHERE id IN (' . implode(',', $depts) . ') ORDER BY name'), 'name') : []];
        $o['progress'] = ['done' => (int)($secDone[$c['id']] ?? 0), 'total' => (int)$c['item_count']]; $m = cr_mine($c, $uid); $o['mine'] = ['count' => $m['count'], 'best' => $m['best'], 'passed' => $m['passed'], 'remaining' => $m['remaining'], 'last_at' => $m['last_at'], 'open_attempt' => $m['open_attempt']];
        $o['can_grades'] = cr_grade_scope($u, $c) !== false; $out[] = $o;
    }
    out(['courses' => $out, 'can_manage' => $isAdmin, 'can_grades' => $isAdmin || $ro || (bool)managed_dept_ids($u) || (bool)one('SELECT 1 FROM course_viewers WHERE user_id=?', [$uid])]);
}
/* ---- grades overview across courses ---- */
if ($method === 'GET' && $part === 'grades' && !$cid) {
    $out = [];
    foreach (all('SELECT * FROM courses WHERE status=\'published\' OR ' . ($isAdmin ? '1=1' : '0=1') . ' ORDER BY title') as $c) {
        $scope = cr_grade_scope($u, $c); if ($scope === false) continue; $L = cr_learners($c, $scope); $ids = array_map(fn($x) => (int)$x['id'], $L);
        $st = $ids ? all('SELECT user_id, MAX(score) best, MAX(passed) passed FROM course_attempts WHERE course_id=? AND submitted_at IS NOT NULL AND user_id IN (' . implode(',', $ids) . ') GROUP BY user_id', [$c['id']]) : [];
        $out[] = ['id' => (int)$c['id'], 'title' => $c['title'], 'status' => $c['status'], 'assigned' => count($L), 'attempted' => count($st), 'passed' => count(array_filter($st, fn($x) => (int)$x['passed'] === 1)), 'average_best' => $st ? round(array_sum(array_column($st, 'best')) / count($st), 1) : null, 'pass_mark' => (int)$c['pass_mark']];
    }
    out(['courses' => $out]);
}
/* ---- create ---- */
if ($method === 'POST' && !$cid && $part === '') {
    if (!$isAdmin) fail('Only the Super Admin creates courses', 403);
    $t = cr_str($b['title'] ?? '', 200); if ($t === '') fail('Give the course a title');
    q('INSERT INTO courses (title,created_by) VALUES (?,?)', [$t, $uid]); $nid = (int)db()->lastInsertId(); $cid = $nid; $b['_new'] = true;
}
$course = $cid ? cr_course($cid) : null;
if (!$course) fail('Not found', 404);
// people chosen to see grades can open the grades of a course even when it is not assigned to them
$viewerOk = $method === 'GET' && in_array($part, ['grades', 'attempts'], true) && $course['status'] === 'published' && cr_grade_scope($u, $course) !== false;
if (!cr_visible($u, $course) && !$viewerOk) fail('Course not found', 404);

/* ---- update / delete the course itself ---- */
if (($method === 'PATCH' && $part === '') || !empty($b['_new'])) {
    if (!$isAdmin) fail('Only the Super Admin edits courses', 403); $o = $course; $set = [];
    $S = fn($k, $n) => array_key_exists($k, $b) ? cr_str($b[$k], $n) : $o[$k];
    $title = $S('title', 200); if ($title === '') fail('Give the course a title');
    $status = array_key_exists('status', $b) ? in((string)$b['status'], ['draft', 'published'], $o['status']) : $o['status'];
    $img = array_key_exists('image_url', $b) ? cr_img($b['image_url']) : $o['image_url'];
    $N = fn($k, $lo, $hi) => array_key_exists($k, $b) ? max($lo, min($hi, (int)$b[$k])) : (int)$o[$k];
    $T = fn($k) => array_key_exists($k, $b) ? (int)(bool)$b[$k] : (int)$o[$k];
    q('UPDATE courses SET title=?,description=?,image_url=?,category=?,status=?,pass_mark=?,questions_per_test=?,shuffle_questions=?,shuffle_answers=?,time_limit_min=?,max_attempts=?,show_answers=?,heads_see_grades=? WHERE id=?',
      [$title, array_key_exists('description', $b) ? (cr_str($b['description'], 5000) ?: null) : $o['description'], $img, array_key_exists('category', $b) ? (cr_str($b['category'], 80) ?: null) : $o['category'], $status, $N('pass_mark', 1, 100), $N('questions_per_test', 0, 1000),
       $T('shuffle_questions'), $T('shuffle_answers'), $N('time_limit_min', 0, 600), $N('max_attempts', 0, 100), $T('show_answers'), $T('heads_see_grades'), $cid]);
    if (array_key_exists('department_ids', $b)) { $ids = array_values(array_unique(array_filter(array_map('intval', (array)$b['department_ids'])))); if ($ids) $ids = array_map('intval', array_column(all('SELECT id FROM departments WHERE id IN (' . implode(',', $ids) . ')'), 'id'));
        q('DELETE FROM course_departments WHERE course_id=?', [$cid]); foreach ($ids as $d) q('INSERT IGNORE INTO course_departments (course_id,department_id) VALUES (?,?)', [$cid, $d]); }
    if (array_key_exists('viewer_ids', $b)) { $ids = array_values(array_unique(array_filter(array_map('intval', (array)$b['viewer_ids'])))); if ($ids) $ids = array_map('intval', array_column(all('SELECT id FROM users WHERE active=1 AND id IN (' . implode(',', $ids) . ')'), 'id'));
        q('DELETE FROM course_viewers WHERE course_id=?', [$cid]); foreach ($ids as $x) q('INSERT IGNORE INTO course_viewers (course_id,user_id) VALUES (?,?)', [$cid, $x]); }
    log_act($uid, !empty($b['_new']) ? 'course_created' : 'course_updated', $title);
    if ($status === 'published' && ($o['status'] !== 'published' || !empty($b['notify']))) { // tell the people it is for
        $d = cr_depts($cid); $who = $d ? array_column(all('SELECT DISTINCT id FROM users WHERE active=1 AND id IN ' . rp_member_sql_cr($d)), 'id') : array_column(all("SELECT id FROM users WHERE active=1 AND role<>'founder'"), 'id');
        foreach ($who as $x) if ((int)$x !== $uid) notify((int)$x, 'course_new', 'New course: ' . $title, 'Open Learn → Courses to take it.', '#/learn/c/' . $cid);
    }
    out(['ok' => true, 'id' => $cid], !empty($b['_new']) ? 201 : 200);
}
if ($method === 'DELETE' && $part === '') { if (!$isAdmin) fail('Not allowed', 403); q('DELETE FROM courses WHERE id=?', [$cid]); log_act($uid, 'course_deleted', $course['title']); out(['ok' => true]); }

/* ---- one course ---- */
if ($method === 'GET' && $part === '') {
    $c = $course; $m = cr_mine($c, $uid); $depts = cr_depts($cid);
    require_once __DIR__ . '/coursecontent.php'; crc_ensure(); $items = []; $mods = crc_modules($cid, $uid);
    foreach ($items as &$it) { $it['id'] = (int)$it['id']; if (in_array($it['kind'], ['video', 'slides'], true)) $it['data'] = json_decode((string)$it['body'], true); if ($it['kind'] !== 'text' && $it['kind'] !== 'link') $it['body'] = null; } unset($it);
    $o = ['id' => $cid, 'title' => $c['title'], 'description' => $c['description'], 'image_url' => $c['image_url'], 'category' => $c['category'], 'status' => $c['status'], 'pass_mark' => (int)$c['pass_mark'], 'questions_per_test' => (int)$c['questions_per_test'],
          'shuffle_questions' => (int)$c['shuffle_questions'], 'shuffle_answers' => (int)$c['shuffle_answers'], 'time_limit_min' => (int)$c['time_limit_min'], 'max_attempts' => (int)$c['max_attempts'], 'show_answers' => (int)$c['show_answers'], 'heads_see_grades' => (int)$c['heads_see_grades'],
          'question_count' => (int)one('SELECT COUNT(*) c FROM course_questions WHERE course_id=?', [$cid])['c'], 'department_ids' => $depts, 'departments' => $depts ? array_column(all('SELECT name FROM departments WHERE id IN (' . implode(',', $depts) . ') ORDER BY name'), 'name') : [],
          'items' => $items, 'modules' => $mods, 'progress' => crc_progress($mods), 'mine' => $m, 'can_manage' => $isAdmin, 'can_grades' => cr_grade_scope($u, $c) !== false, 'can_take' => !$ro && $c['status'] === 'published'];
    if ($isAdmin) $o['viewer_ids'] = array_map('intval', array_column(all('SELECT user_id FROM course_viewers WHERE course_id=?', [$cid]), 'user_id'));
    out($o);
}

/* ---- modules, sections and blocks ---- */
if (in_array($part, ['modules', 'sections', 'blocks'], true)) { require_once __DIR__ . '/coursecontent.php'; crc_handle($part, $cid, $sid, $act, $method, $b, $u, $course, $isAdmin, $ro); }

/* ---- content items (old flat layout, kept for old links) ---- */
if ($part === 'items' || $part === 'slides') {
    if (!$isAdmin) fail('Only the Super Admin edits course content', 403);
    $pos = (int)(one('SELECT COALESCE(MAX(position),0)+1 p FROM course_items WHERE course_id=?', [$cid])['p']);
    if ($part === 'slides' && $method === 'POST') {
        $bin = base64_decode((string)($b['data'] ?? ''), true); if ($bin === false || $bin === '') fail('The file could not be read'); if (strlen($bin) > 15 * 1024 * 1024) fail('That file is larger than 15 MB');
        $slides = cr_parse_pptx($bin, $uid); $title = cr_str($b['title'] ?? '', 200) ?: preg_replace('/\.pptx?$/i', '', cr_str($b['name'] ?? 'Presentation', 200));
        q('INSERT INTO course_items (course_id,position,kind,title,body) VALUES (?,?,?,?,?)', [$cid, $pos, 'slides', $title, json_encode(['slides' => $slides], JSON_UNESCAPED_UNICODE)]);
        out(['ok' => true, 'id' => (int)db()->lastInsertId(), 'slides' => count($slides)], 201);
    }
    if ($method === 'POST' && !$sid) {
        $kind = in((string)($b['kind'] ?? ''), ['text', 'video', 'link'], ''); if ($kind === '') fail('Choose what to add'); $title = cr_str($b['title'] ?? '', 200); $url = null; $bodyV = null;
        if ($kind === 'text') { $bodyV = cr_str($b['body'] ?? '', 20000); if ($bodyV === '') fail('Write the text'); if ($title === '') $title = 'Notes'; }
        elseif ($kind === 'video') { $url = cr_url($b['url'] ?? ''); $bodyV = json_encode(cr_video($url)); if ($title === '') $title = 'Video'; }
        else { $url = cr_url($b['url'] ?? ''); $bodyV = cr_str($b['body'] ?? '', 500) ?: null; if ($title === '') $title = $url; }
        q('INSERT INTO course_items (course_id,position,kind,title,body,url) VALUES (?,?,?,?,?,?)', [$cid, $pos, $kind, $title, $bodyV, $url]); out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201);
    }
    $it = $sid ? one('SELECT * FROM course_items WHERE id=? AND course_id=?', [$sid, $cid]) : null; if (!$it) fail('Not found', 404);
    if ($method === 'DELETE') { q('DELETE FROM course_items WHERE id=?', [$sid]); out(['ok' => true]); }
    if ($method === 'PATCH') {
        $title = array_key_exists('title', $b) ? cr_str($b['title'], 200) : $it['title']; $url = $it['url']; $bodyV = $it['body'];
        if ($it['kind'] === 'text' && array_key_exists('body', $b)) $bodyV = cr_str($b['body'], 20000);
        if ($it['kind'] === 'link') { if (array_key_exists('url', $b)) $url = cr_url($b['url']); if (array_key_exists('body', $b)) $bodyV = cr_str($b['body'], 500) ?: null; }
        if ($it['kind'] === 'video' && array_key_exists('url', $b)) { $url = cr_url($b['url']); $bodyV = json_encode(cr_video($url)); }
        q('UPDATE course_items SET title=?,body=?,url=? WHERE id=?', [$title ?: $it['title'], $bodyV, $url, $sid]); out(['ok' => true]);
    }
    if ($method === 'POST' && $act === 'move') {
        $dir = (int)($b['dir'] ?? 0) < 0 ? -1 : 1; $list = all('SELECT id FROM course_items WHERE course_id=? ORDER BY position,id', [$cid]); $ids = array_map('intval', array_column($list, 'id')); $i = array_search($sid, $ids, true); $j = $i + $dir;
        if ($i !== false && $j >= 0 && $j < count($ids)) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; foreach ($ids as $n => $x) q('UPDATE course_items SET position=? WHERE id=?', [$n + 1, $x]); } out(['ok' => true]);
    }
    fail('Bad request', 404);
}

/* ---- question bank (admin) ---- */
if ($part === 'questions') {
    if (!$isAdmin) fail('Only the Super Admin manages questions', 403);
    if ($method === 'GET') out(['questions' => array_map(fn($r) => cr_q_out($r, true), all('SELECT * FROM course_questions WHERE course_id=? ORDER BY position,id', [$cid]))]);
    $count = (int)one('SELECT COUNT(*) c FROM course_questions WHERE course_id=?', [$cid])['c']; $pos = (int)(one('SELECT COALESCE(MAX(position),0)+1 p FROM course_questions WHERE course_id=?', [$cid])['p']);
    if ($method === 'POST' && $sid === 0 && ($seg[3] ?? '') === 'import') {
        $bin = base64_decode((string)($b['data'] ?? ''), true); if ($bin === false || $bin === '') fail('The file could not be read'); if (strlen($bin) > 8 * 1024 * 1024) fail('That file is larger than 8 MB');
        [$ok, $err] = cr_parse_questions(cr_read_table((string)($b['name'] ?? 'file.csv'), $bin));
        if (!$ok && !$err) fail('No questions were found in that file. Check it uses the template columns.');
        $replace = ($b['mode'] ?? 'append') === 'replace'; if ($replace && $ok) { q('DELETE FROM course_questions WHERE course_id=?', [$cid]); $count = 0; $pos = 1; }
        if ($count + count($ok) > 1000) fail('A course can hold up to 1000 questions');
        foreach ($ok as $qq) cr_insert_question($cid, $qq, $pos++);
        log_act($uid, 'course_questions_imported', $course['title'] . ' +' . count($ok)); out(['ok' => true, 'added' => count($ok), 'fixed' => count(array_filter($ok, fn($x) => !empty($x['fixed']))), 'skipped' => count($err), 'errors' => array_slice($err, 0, 60), 'total' => (int)one('SELECT COUNT(*) c FROM course_questions WHERE course_id=?', [$cid])['c'], 'replaced' => $replace && $ok]);
    }
    if ($method === 'POST' && !$sid) { if ($count >= 1000) fail('A course can hold up to 1000 questions'); cr_insert_question($cid, cr_question_in($b), $pos); out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201); }
    if ($method === 'DELETE' && !$sid) { q('DELETE FROM course_questions WHERE course_id=?', [$cid]); out(['ok' => true]); }
    $row = $sid ? one('SELECT * FROM course_questions WHERE id=? AND course_id=?', [$sid, $cid]) : null; if (!$row) fail('Not found', 404);
    if ($method === 'DELETE') { q('DELETE FROM course_questions WHERE id=?', [$sid]); out(['ok' => true]); }
    if ($method === 'POST' && $act === 'duplicate') { cr_insert_question($cid, cr_question_in(cr_q_out($row, true) + ['video_url' => !empty($row['video']) ? (json_decode((string)$row['video'], true)['url'] ?? '') : '', 'items' => (json_decode((string)$row['puzzle'], true) ?: [])['items'] ?? [], 'pairs' => (json_decode((string)$row['puzzle'], true) ?: [])['pairs'] ?? [], 'opt_a' => $row['opt_a'], 'opt_b' => $row['opt_b'], 'opt_c' => $row['opt_c'], 'opt_d' => $row['opt_d'], 'opt_e' => $row['opt_e']]), $pos); out(['ok' => true, 'id' => (int)db()->lastInsertId()], 201); }
    if ($method === 'POST' && $act === 'move') { crc_move_q($cid, $sid, (int)($b['dir'] ?? 0) < 0 ? -1 : 1); out(['ok' => true]); }
    if ($method === 'PATCH') { $qq = cr_question_in($b);
        q('UPDATE course_questions SET qtype=?,question=?,extra_text=?,image_url=?,video=?,puzzle=?,opt_a=?,opt_b=?,opt_c=?,opt_d=?,opt_e=?,correct=?,hint=? WHERE id=?', [$qq['qtype'], $qq['question'], $qq['extra_text'], $qq['image_url'], $qq['video'], $qq['puzzle'], $qq['opts']['a'] ?: null, $qq['opts']['b'] ?: null, $qq['opts']['c'] ?: null, $qq['opts']['d'] ?: null, $qq['opts']['e'] ?: null, $qq['correct'], $qq['hint'], $sid]); out(['ok' => true]); }
    fail('Bad request', 404);
}

/* ---- taking the test ---- */
if ($part === 'start' && $method === 'POST') {
    if ($ro) fail('Founder accounts are read-only', 403); if ($course['status'] !== 'published') fail('This course is not open yet');
    $all = all('SELECT * FROM course_questions WHERE course_id=? ORDER BY position,id', [$cid]); if (!$all) fail('This course has no test questions yet');
    // an unfinished attempt is resumed; one that ran out of time is graded as it stands
    $open = one('SELECT * FROM course_attempts WHERE course_id=? AND user_id=? AND submitted_at IS NULL ORDER BY id DESC LIMIT 1', [$cid, $uid]);
    if ($open && $open['ends_at'] && strtotime($open['ends_at']) + 60 < time()) { cr_finish($open, $course, json_decode((string)$open['answers'], true) ?: []); $open = null; }
    if (!$open) {
        $m = cr_mine($course, $uid); if ($m['remaining'] !== null && $m['remaining'] <= 0) fail($m['count'] ? 'You have used all your attempts. Ask your administrator if you may retake it.' : 'You cannot take this test.', 403);
        $pick = $all; if ((int)$course['shuffle_questions']) shuffle($pick); $n = (int)$course['questions_per_test']; if ($n > 0 && $n < count($pick)) $pick = array_slice($pick, 0, $n);
        if (!(int)$course['shuffle_questions']) usort($pick, fn($x, $y) => (int)$x['position'] <=> (int)$y['position'] ?: (int)$x['id'] <=> (int)$y['id']);
        $qs = []; foreach ($pick as $r) { $t = $r['qtype'] ?? 'mcq'; $e = ['id' => (int)$r['id'], 'type' => $t];
            if ($t === 'mcq') { $map = array_values(array_filter(CR_LETTERS, fn($l) => (string)$r['opt_' . strtolower($l)] !== '')); if ((int)$course['shuffle_answers']) shuffle($map); $e['map'] = $map; }
            else { $pz = json_decode((string)$r['puzzle'], true) ?: []; $n = count($t === 'order' ? ($pz['items'] ?? []) : ($pz['pairs'] ?? [])); $perm = range(0, max(0, $n - 1)); $tries = 0; do { shuffle($perm); } while ($n > 1 && $perm === range(0, $n - 1) && ++$tries < 20); $e['perm'] = $perm; $e['map'] = []; }
            $qs[] = $e; }
        $ends = (int)$course['time_limit_min'] > 0 ? date('Y-m-d H:i:s', time() + (int)$course['time_limit_min'] * 60) : null;
        q('INSERT INTO course_attempts (course_id,user_id,attempt_no,ends_at,total,questions,answers) VALUES (?,?,?,?,?,?,?)', [$cid, $uid, $m['count'] + 1, $ends, count($qs), json_encode($qs), '{}']);
        $open = one('SELECT * FROM course_attempts WHERE id=?', [(int)db()->lastInsertId()]);
    }
    $qs = json_decode((string)$open['questions'], true) ?: []; $rows = []; foreach (all('SELECT * FROM course_questions WHERE id IN (' . implode(',', array_map(fn($x) => (int)$x['id'], $qs) ?: [0]) . ')') as $r) $rows[(int)$r['id']] = $r;
    $out = []; foreach ($qs as $x) { $r = $rows[(int)$x['id']] ?? null; if (!$r) continue; $out[] = cr_q_play($r, $x); }
    out(['attempt_id' => (int)$open['id'], 'attempt_no' => (int)$open['attempt_no'], 'ends_at' => $open['ends_at'], 'seconds_left' => $open['ends_at'] ? max(0, strtotime($open['ends_at']) - time()) : null, 'questions' => $out, 'answers' => (object)(json_decode((string)$open['answers'], true) ?: []), 'pass_mark' => (int)$course['pass_mark']]);
}
if ($part === 'attempts' && $sid) {
    $a = one('SELECT * FROM course_attempts WHERE id=? AND course_id=?', [$sid, $cid]); if (!$a) fail('Not found', 404);
    if ($method === 'GET') { // a finished attempt, with the review when the course shows answers
        $own = (int)$a['user_id'] === $uid; $scope = cr_grade_scope($u, $course); $may = $own || $scope === null || (is_array($scope) && manages_user($u, (int)$a['user_id']));
        if (!$may || $a['submitted_at'] === null) fail('Not found', 404);
        $res = ['score' => (float)$a['score'], 'correct' => (int)$a['correct_count'], 'total' => (int)$a['total'], 'passed' => (int)$a['passed'] === 1, 'submitted_at' => $a['submitted_at'], 'attempt_no' => (int)$a['attempt_no'], 'user_name' => one('SELECT name FROM users WHERE id=?', [$a['user_id']])['name']];
        if ($own ? (int)$course['show_answers'] === 1 : true) { $qs = json_decode((string)$a['questions'], true) ?: []; $ans = json_decode((string)$a['answers'], true) ?: []; $rows = []; foreach (all('SELECT * FROM course_questions WHERE id IN (' . implode(',', array_map(fn($x) => (int)$x['id'], $qs) ?: [0]) . ')') as $r) $rows[(int)$r['id']] = $r;
            $res['review'] = []; foreach ($qs as $x) { $r = $rows[(int)$x['id']] ?? null; if (!$r) continue; $res['review'][] = cr_grade_q($r, $x, $ans[(string)$x['id']] ?? null)[1]; } }
        out($res);
    }
    if ((int)$a['user_id'] !== $uid) fail('Not allowed', 403); if ($a['submitted_at'] !== null) fail('This attempt is already finished', 409);
    $given = cr_clean_answers((array)($b['answers'] ?? []));
    $what = $seg[4] ?? '';
    if ($method === 'POST' && $what === 'save') { q('UPDATE course_attempts SET answers=? WHERE id=?', [json_encode($given), $sid]); out(['ok' => true]); }
    if ($method === 'POST' && $what === 'submit') {
        $res = cr_finish($a, $course, $given + (json_decode((string)$a['answers'], true) ?: [])); $m = cr_mine($course, $uid); log_act($uid, 'course_test', $course['title'] . ' ' . $res['score'] . '%');
        if (!(int)$course['show_answers']) unset($res['review']); $res['remaining'] = $m['remaining']; $res['pass_mark'] = (int)$course['pass_mark']; out($res);
    }
    fail('Bad request', 404);
}

/* ---- grades ---- */
if ($part === 'grades' && $method === 'GET') {
    $scope = cr_grade_scope($u, $course); if ($scope === false) fail('You can only see your own results for this course', 403);
    $L = cr_learners($course, $scope); $ids = array_map(fn($x) => (int)$x['id'], $L); $stats = [];
    if ($ids) foreach (all('SELECT user_id, COUNT(*) n, MAX(score) best, MAX(passed) passed, MAX(submitted_at) last_at FROM course_attempts WHERE course_id=? AND submitted_at IS NOT NULL AND user_id IN (' . implode(',', $ids) . ') GROUP BY user_id', [$cid]) as $r) $stats[(int)$r['user_id']] = $r;
    $latest = []; if ($ids) foreach (all('SELECT a.user_id,a.score,a.passed FROM course_attempts a JOIN (SELECT user_id, MAX(id) mid FROM course_attempts WHERE course_id=? AND submitted_at IS NOT NULL GROUP BY user_id) x ON x.mid=a.id', [$cid]) as $r) $latest[(int)$r['user_id']] = $r;
    $rows = []; foreach ($L as $p) { $s = $stats[(int)$p['id']] ?? null; $max = (int)$course['max_attempts']; $rows[] = ['user_id' => (int)$p['id'], 'name' => $p['name'], 'department' => $p['department'], 'job_title' => $p['job_title'], 'attempts' => $s ? (int)$s['n'] : 0, 'best' => $s ? (float)$s['best'] : null,
        'latest' => isset($latest[(int)$p['id']]) ? (float)$latest[(int)$p['id']]['score'] : null, 'passed' => $s ? (int)$s['passed'] === 1 : false, 'last_at' => $s['last_at'] ?? null, 'extra' => cr_extra($cid, (int)$p['id']), 'can_retake' => $max === 0 || !$s || (int)$s['n'] < $max + cr_extra($cid, (int)$p['id'])]; }
    if (!empty($_GET['user'])) { $x = (int)$_GET['user']; if (!in_array($x, $ids, true) && $x !== $uid) fail('Not found', 404); out(['attempts' => all('SELECT id,attempt_no,submitted_at,score,correct_count,total,passed FROM course_attempts WHERE course_id=? AND user_id=? AND submitted_at IS NOT NULL ORDER BY id DESC', [$cid, $x])]); }
    if (($_GET['format'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="Grades_' . (preg_replace('/[^A-Za-z0-9]+/', '_', $course['title']) ?: 'course') . '.csv"'); $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['Name', 'Department', 'Attempts', 'Best score %', 'Latest score %', 'Passed', 'Last attempt'], ',', '"', ''); foreach ($rows as $r) fputcsv($o, [$r['name'], $r['department'], $r['attempts'], $r['best'], $r['latest'], $r['attempts'] ? ($r['passed'] ? 'Yes' : 'No') : 'Not taken', $r['last_at']], ',', '"', ''); exit;
    }
    $tk = array_filter($rows, fn($r) => $r['attempts'] > 0);
    out(['course' => ['id' => $cid, 'title' => $course['title'], 'pass_mark' => (int)$course['pass_mark'], 'max_attempts' => (int)$course['max_attempts']], 'rows' => $rows, 'scope' => $scope === null ? 'all' : 'department', 'can_grant' => $isAdmin,
         'summary' => ['assigned' => count($rows), 'taken' => count($tk), 'passed' => count(array_filter($rows, fn($r) => $r['passed'])), 'average_best' => $tk ? round(array_sum(array_column($tk, 'best')) / count($tk), 1) : null]]);
}
if ($part === 'grant' && $method === 'POST') { // let someone take the test again
    if (!$isAdmin) fail('Only the Super Admin can allow a retake', 403); $x = (int)($b['user_id'] ?? 0); $n = max(1, min(20, (int)($b['extra'] ?? 1)));
    if (!one('SELECT 1 FROM users WHERE id=? AND active=1', [$x])) fail('Choose a person');
    q('INSERT INTO course_grants (course_id,user_id,extra) VALUES (?,?,?) ON DUPLICATE KEY UPDATE extra=extra+VALUES(extra)', [$cid, $x, $n]);
    notify($x, 'course_retake', 'You can retake: ' . $course['title'], 'Your administrator allowed another attempt.', '#/learn/c/' . $cid); log_act($uid, 'course_retake_granted', $course['title']); out(['ok' => true]);
}
fail('Bad request', 404);
