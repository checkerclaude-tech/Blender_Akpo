<?php
// Speed check: sign in as Super Admin, then open /speed.php. It times the server's own work and shows where the delay is.
// Safe to leave in place (Super Admin only, read-only). Delete it when you are done.
declare(strict_types=1);
$t0 = microtime(true);
require __DIR__ . '/api/lib/core.php';
header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store');
$ms = fn(float $a): string => number_format((microtime(true) - $a) * 1000, 1) . ' ms';
$u = me();
if (!$u || $u['role'] !== 'super_admin') { http_response_code(403); exit("Sign in to the app as Super Admin first, then reload this page.\n"); }
echo "SPEED CHECK  (" . date('c') . ")\nPHP " . PHP_VERSION . ' · ' . PHP_SAPI . ' · opcache: ' . (function_exists('opcache_get_status') && @opcache_get_status(false) ? 'on' : 'OFF') . ' · zlib/deflate output: ' . (ini_get('zlib.output_compression') ? 'on' : 'off') . "\n";
echo 'Loading the code and signing in: ' . $ms($t0) . "\n\n";
$time = function (string $label, callable $f) use ($ms) { $a = microtime(true); try { $f(); $r = $ms($a); } catch (Throwable $e) { $r = 'ERROR ' . $e->getMessage(); } echo str_pad($label, 52) . $r . "\n"; };
$time('Database connection', fn() => db());
$time('SELECT 1 x 50 (round trips)', function () { for ($i = 0; $i < 50; $i++) one('SELECT 1'); });
$time('Count users / tasks / notifications', function () { one('SELECT COUNT(*) FROM users'); one('SELECT COUNT(*) FROM tasks'); try { one('SELECT COUNT(*) FROM notifications'); } catch (Throwable $e) {} });
$time('Sign-in lookups (me) x 10', function () { for ($i = 0; $i < 10; $i++) { one('SELECT u.id FROM users u LEFT JOIN departments d ON d.id=u.department_id WHERE u.id=?', [1]); user_dept_ids(1, 0); head_dept_ids(1); } });
$time('information_schema lookup (slow on some hosts)', fn() => one("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id'"));
$time('Games access check', function () use ($u) { games_access($u); });
$time('Write a temp file in the session folder', function () { $p = session_save_path() ?: sys_get_temp_dir(); $f = rtrim($p, '/') . '/speedtest_' . bin2hex(random_bytes(4)); file_put_contents($f, 'x'); @unlink($f); });
$time('Write to the uploads/storage folder', function () { $d = __DIR__ . '/api/storage'; if (!is_dir($d)) return; $f = $d . '/speedtest_' . bin2hex(random_bytes(4)); file_put_contents($f, 'x'); @unlink($f); });
$time('Look up this server by name (DNS)', fn() => gethostbyname('fonts.googleapis.com'));
$a = microtime(true); $ok = @fsockopen('ssl://fonts.gstatic.com', 443, $en, $es, 3); echo str_pad('Outbound connection (web push / mail use this)', 52) . ($ok ? $ms($a) : 'BLOCKED or slow: ' . $ms($a)) . "\n"; if ($ok) fclose($ok);
$cnt = []; foreach (['remember_tokens', 'activity_log', 'push_subscriptions', 'notifications', 'tasks', 'course_progress'] as $tb) { try { $cnt[] = $tb . '=' . (int)one("SELECT COUNT(*) c FROM `$tb`")['c']; } catch (Throwable $e) {} }
echo "\nTable sizes: " . implode(', ', $cnt) . "\n";
echo "\nTotal for this page: " . $ms($t0) . "\n\nHow to read it: each line should be a few ms. Database round trips (50 x SELECT 1) above ~150 ms, the information_schema line above ~100 ms, a slow outbound connection, or opcache OFF explain a slow site. Send me this whole page.\n";
