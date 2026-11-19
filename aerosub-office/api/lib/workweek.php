<?php
declare(strict_types=1);
// The work week, set by the Super Admin (Admin page): which day a week starts on, which day it ends on, and which days are working days.
// Used for the weekly report, "this week", counting weeks, and working days in the analytics.
//   GET   /api/workweek   {week_start, week_end, work_days, length, labels}     (everyone signed in)
//   PATCH /api/workweek   {week_start, week_end, work_days: [1..7]}              (Super Admin / Root Admin)
// Days are numbered like PHP's date('N'): 1 = Monday ... 7 = Sunday.

const WW_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

function ww_cfg(bool $fresh = false): array {
    static $c = null; if ($fresh) $c = null; if ($c !== null) return $c; $r = null;
    try { $r = one('SELECT week_start,week_end,work_days FROM work_settings WHERE id=1'); } catch (Throwable $e) {}
    $days = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($r['work_days'] ?? '1,2,3,4,5'))), fn($x) => $x >= 1 && $x <= 7))); sort($days); if (!$days) $days = [1, 2, 3, 4, 5];
    $s = (int)($r['week_start'] ?? 1); $e = (int)($r['week_end'] ?? 5);
    return $c = ['week_start' => $s >= 1 && $s <= 7 ? $s : 1, 'week_end' => $e >= 1 && $e <= 7 ? $e : 5, 'work_days' => $days];
}
/** Number of days a week runs (start day to end day, counting both). */
function ww_len(): int { $c = ww_cfg(); return (($c['week_end'] - $c['week_start'] + 7) % 7) + 1; }
function rp_week_start(string $any): string { $t = strtotime($any . ' 12:00:00') ?: time(); $diff = ((int)date('N', $t) - ww_cfg()['week_start'] + 7) % 7; return date('Y-m-d', strtotime("-$diff days", $t)); }
function rp_week_end(string $start): string { return date('Y-m-d', strtotime($start . ' +' . (ww_len() - 1) . ' days')); }
function ww_is_workday(string $date): bool { return in_array((int)date('N', strtotime($date . ' 12:00:00')), ww_cfg()['work_days'], true); }
function ww_workdays(string $from, string $to): int { $n = 0; for ($t = strtotime($from . ' 12:00:00'); $t <= strtotime($to . ' 12:00:00'); $t += 86400) if (in_array((int)date('N', $t), ww_cfg()['work_days'], true)) $n++; return $n; }
/** The start dates of every week that overlaps from..to. */
function ww_weeks(string $from, string $to): array { $out = []; $s = rp_week_start($from); for ($g = 0; $s <= $to && $g < 120; $g++) { $out[] = $s; $s = date('Y-m-d', strtotime($s . ' +7 days')); } return $out; }
function ww_out(): array { $c = ww_cfg(); return $c + ['length' => ww_len(), 'labels' => ['start' => WW_NAMES[$c['week_start']], 'end' => WW_NAMES[$c['week_end']], 'days' => array_map(fn($d) => WW_NAMES[$d], $c['work_days'])], 'names' => WW_NAMES]; }

if (isset($seg[0]) && $seg[0] === 'workweek' && isset($method)) {
    $u = need_user();
    if ($method === 'GET') out(ww_out());
    if ($method === 'PATCH' || $method === 'POST') {
        if (($u['role'] ?? '') !== 'super_admin' && !is_root($u)) fail('Only a Super Admin sets the work week', 403);
        $b = body(); $s = (int)($b['week_start'] ?? 0); $e = (int)($b['week_end'] ?? 0); $days = array_values(array_unique(array_filter(array_map('intval', (array)($b['work_days'] ?? [])), fn($x) => $x >= 1 && $x <= 7))); sort($days);
        if ($s < 1 || $s > 7 || $e < 1 || $e > 7) fail('Choose the day the week starts and the day it ends'); if (!$days) fail('Choose at least one working day');
        q('INSERT INTO work_settings (id,week_start,week_end,work_days) VALUES (1,?,?,?) ON DUPLICATE KEY UPDATE week_start=VALUES(week_start), week_end=VALUES(week_end), work_days=VALUES(work_days)', [$s, $e, implode(',', $days)]);
        log_act((int)$u['id'], 'workweek_changed', WW_NAMES[$s] . ' to ' . WW_NAMES[$e] . '; working days ' . implode(',', array_map(fn($d) => substr(WW_NAMES[$d], 0, 3), $days)));
        ww_cfg(true); out(['ok' => true] + ww_out());
    }
    fail('Bad request', 404);
}
