<?php
declare(strict_types=1);
// Shared helpers: task file storage, meeting reminders.
function task_storage_dir(): string {
    $dir = (string)(cfg('storage_dir') ?: dirname(__DIR__) . '/storage') . '/tasks';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $root = dirname($dir);
    if (!is_file($root . '/.htaccess')) { @file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n"); @file_put_contents($root . '/index.html', ''); }
    return $dir;
}
function task_store_file(int $taskId, ?int $userId, string $name, string $mime, string $data): int {
    if ($data === '') throw new RuntimeException('That file is empty');
    if (strlen($data) > 8 * 1024 * 1024) throw new RuntimeException('Files are limited to 8 MB');
    $name = mb_substr(preg_replace('/[\x00-\x1f\\\\\/]+/', '_', $name) ?: 'file', 0, 190);
    $rel = bin2hex(random_bytes(16)); $dir = task_storage_dir();
    if (@file_put_contents($dir . '/' . $rel, $data) === false) throw new RuntimeException('Could not save the file on the server (storage folder not writable)');
    q('INSERT INTO task_files (task_id,user_id,name,mime,size,path) VALUES (?,?,?,?,?,?)', [$taskId, $userId, $name, mb_substr($mime ?: 'application/octet-stream', 0, 100), strlen($data), $rel]);
    return (int)db()->lastInsertId();
}
function task_delete_file_blob(string $rel): void { $p = task_storage_dir() . '/' . preg_replace('/[^a-f0-9]/', '', $rel); if (is_file($p)) @unlink($p); }
function meeting_reminders(): void { // 15 minutes before a meeting task starts: notify assignee, creator and everyone it was shared with (once)
    try {
        foreach (all("SELECT id,title,starts_at,assignee_id,creator_id FROM tasks WHERE task_type='meeting' AND status NOT IN ('done','canceled','transferred','deferred') AND remind_sent=0 AND starts_at IS NOT NULL AND starts_at BETWEEN (NOW() - INTERVAL 5 MINUTE) AND (NOW() + INTERVAL 15 MINUTE) LIMIT 50") as $t) {
            q('UPDATE tasks SET remind_sent=1 WHERE id=?', [$t['id']]);
            $ids = array_column(all('SELECT user_id FROM task_shares WHERE task_id=?', [$t['id']]), 'user_id'); $ids[] = $t['assignee_id']; $ids[] = $t['creator_id'];
            foreach (array_unique(array_filter(array_map('intval', $ids))) as $uid) notify($uid, 'meeting_reminder', 'Meeting at ' . date('H:i', strtotime($t['starts_at'])) . ': ' . $t['title'], 'Starting soon', '#/tasks/' . $t['id']);
        }
    } catch (Throwable $e) {}
}
