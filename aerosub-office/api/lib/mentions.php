<?php
declare(strict_types=1);
// @mentions in the text of a task (its details and its comments): @Person, @Department, @everyone.
// A person who is tagged is added to the task (so they can open it) and gets a notification. A department tags all of its members,
// @everyone tags every active person (Super Admin, Founder, managers, department heads and the Root Admin only).
// The text stays plain: "@Emma Employee please check @Finance". Names are matched against the real people and departments.

function mn_can_tag_all(array $u): bool { return in_array($u['role'] ?? '', ['super_admin', 'founder', 'manager'], true) || !empty($u['is_root']) || !empty($u['head_of']); }

/** What is written after each @ in the text: ['users' => ids, 'depts' => ids, 'all' => bool]. */
function mn_parse(string $text): array {
    $out = ['users' => [], 'depts' => [], 'all' => false]; if (strpos($text, '@') === false) return $out;
    $cands = []; foreach (all('SELECT id,name FROM users WHERE active=1 AND archived_at IS NULL') as $r) $cands[] = [mb_strtolower((string)$r['name']), 'user', (int)$r['id']];
    foreach (all('SELECT id,name FROM departments') as $r) $cands[] = [mb_strtolower((string)$r['name']), 'dept', (int)$r['id']];
    foreach (['everyone', 'everybody', 'all'] as $w) $cands[] = [$w, 'all', 0];
    usort($cands, fn($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));      // the longest name wins (@Emma Employee before @Emma)
    $low = mb_strtolower($text); $pos = 0; $len = mb_strlen($low);
    while (($at = mb_strpos($low, '@', $pos)) !== false) {
        $pos = $at + 1; if ($at > 0 && preg_match('/[\p{L}\p{N}._%+-]/u', mb_substr($low, $at - 1, 1))) continue;   // an email address, not a tag
        $rest = mb_substr($low, $at + 1, 80);
        foreach ($cands as [$nm, $type, $id]) { if ($nm === '' || mb_substr($rest, 0, mb_strlen($nm)) !== $nm) continue; $after = mb_substr($rest, mb_strlen($nm), 1); if ($after !== '' && preg_match('/[\p{L}\p{N}]/u', $after)) continue;
            if ($type === 'user') $out['users'][$id] = $id; elseif ($type === 'dept') $out['depts'][$id] = $id; else $out['all'] = true; $pos = $at + 1 + mb_strlen($nm); break; }
    }
    $out['users'] = array_values($out['users']); $out['depts'] = array_values($out['depts']); return $out;
}
function mn_people(array $m, array $author): array {
    $ids = array_map('intval', $m['users']);
    if ($m['all'] && mn_can_tag_all($author)) $ids = array_merge($ids, array_map('intval', array_column(all('SELECT id FROM users WHERE active=1 AND archived_at IS NULL LIMIT 500'), 'id')));
    foreach ($m['depts'] as $d) { $ids = array_merge($ids, array_map('intval', array_column(all('SELECT id FROM users WHERE active=1 AND archived_at IS NULL AND department_id=? LIMIT 300', [$d]), 'id')));
        try { $ids = array_merge($ids, array_map('intval', array_column(all('SELECT user_id FROM user_departments WHERE department_id=? LIMIT 300', [$d]), 'user_id'))); } catch (Throwable $e) {}
        $h = (int)(one('SELECT head_id FROM departments WHERE id=?', [$d])['head_id'] ?? 0); if ($h) $ids[] = $h; }
    $ids = array_values(array_unique(array_filter($ids, fn($i) => $i > 0 && $i !== (int)$author['id']))); if (!$ids) return [];
    return array_map('intval', array_column(all('SELECT id FROM users WHERE active=1 AND archived_at IS NULL AND id IN (' . implode(',', $ids) . ')'), 'id'));
}
/** Tag the people named in $new (and not already named in $old): add them to the task and tell them. Returns how many were told. */
function mn_apply(array $task, array $author, string $new, string $old = '', string $where = 'task'): int {
    if (strpos($new, '@') === false) return 0; $tid = (int)$task['id'];
    $before = $old !== '' ? array_flip(mn_people(mn_parse($old), $author)) : []; $told = 0; $skip = array_filter([(int)($task['assignee_id'] ?? 0) && $where === 'task_new' ? (int)$task['assignee_id'] : 0]);
    foreach (mn_people(mn_parse($new), $author) as $pid) {
        if (isset($before[$pid]) || in_array($pid, $skip, true)) continue;
        if ($pid !== (int)($task['assignee_id'] ?? 0) && $pid !== (int)($task['creator_id'] ?? 0)) q('INSERT IGNORE INTO task_shares (task_id,user_id,shared_by) VALUES (?,?,?)', [$tid, $pid, (int)$author['id']]);
        notify($pid, 'task_mention', $author['name'] . ' mentioned you ' . ($where === 'comment' ? 'in a comment on' : 'in') . ': ' . $task['title'], mb_substr(trim(preg_replace('/\s+/', ' ', $new)), 0, 160), '#/tasks/' . $tid); $told++;
    }
    return $told;
}
