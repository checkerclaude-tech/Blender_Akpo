<?php
// Run by cPanel > Scheduled Tasks (cron), e.g. every 30 minutes:
//   /usr/local/bin/php /home/CPANELUSER/office.aerosub.co/cron.php
// Or via URL: https://office.aerosub.co/cron.php?key=YOUR_CRON_KEY
declare(strict_types=1);
require __DIR__ . '/api/lib/core.php';
require __DIR__ . '/api/lib/migrate.php';

if (PHP_SAPI !== 'cli' && !hash_equals((string)cfg('cron_key'), (string)($_GET['key'] ?? ''))) { http_response_code(403); exit("Forbidden\n"); }

$made = 0;
// Marketing campaigns: send the next batch (and start scheduled campaigns that are due)
try { require_once __DIR__ . '/api/lib/campcore.php'; if (col_exists('camp_queue', 'status')) { $ct = camp_tick((int)(camp_settings()['batch_size'] ?: 100)); echo 'Campaigns: ' . json_encode($ct) . "\n"; } } catch (Throwable $e) { echo 'Campaigns skipped: ' . $e->getMessage() . "\n"; }
// Prospecting: run the daily market-signal watches (Exa)
try { require_once __DIR__ . '/api/lib/prospect.php'; if (col_exists('pr_watches', 'keywords')) echo pr_cron() . "\n"; } catch (Throwable $e) { echo 'Prospecting skipped: ' . $e->getMessage() . "\n"; }
// News feeds: read the outside RSS / Atom feeds that are due, keep what matches the keywords, drop what has expired
try { require_once __DIR__ . '/api/lib/newsfeeds.php'; echo nf_cron() . "\n"; } catch (Throwable $e) { echo 'News feeds skipped: ' . $e->getMessage() . "\n"; }
// RFQ tracker: read the operations@ mailbox for RFQs and purchase orders, and keep quotations matched to their RFQ
try { require_once __DIR__ . '/api/lib/rfq.php'; echo rfq_cron() . "\n"; } catch (Throwable $e) { echo 'RFQ tracker skipped: ' . $e->getMessage() . "\n"; }
// Inbox automation: read the info@ mailbox, add senders to the CRM, create tasks and draft replies for approval
try { require_once __DIR__ . '/api/lib/inboxauto.php'; if (col_exists('ia_items', 'msg_key')) echo ia_cron() . "\n"; } catch (Throwable $e) { echo 'Inbox automation skipped: ' . $e->getMessage() . "\n"; }
// Agent platform: retry approved items whose pipeline update failed earlier
try { require_once __DIR__ . '/api/lib/agent_writeback.php'; if (col_exists('agent_items', 'sync_state')) echo 'Agent sync: ' . agent_retry_pending() . " retried.\n"; } catch (Throwable $e) { echo 'Agent sync skipped: ' . $e->getMessage() . "\n"; }
// Personal agent mailbox: encrypted messages nobody collected for a week are dropped
try { if (col_exists('agent_relay', 'id')) { require_once __DIR__ . '/api/lib/agent_relay_lib.php'; echo 'Agent relay: ' . relay_purge_old() . " stale message(s) removed.
"; } } catch (Throwable $e) { echo 'Agent relay cleanup skipped: ' . $e->getMessage() . "
"; }

// Agent chat: conversations older than 90 days are removed (the owner can also delete one at any time)
try { if (col_exists('agent_chats', 'updated_at')) { $x = q("DELETE FROM agent_chats WHERE updated_at < (NOW() - INTERVAL 90 DAY)")->rowCount(); echo 'Agent chat: ' . $x . " old conversation(s) removed.
"; } } catch (Throwable $e) { echo 'Agent chat cleanup skipped: ' . $e->getMessage() . "
"; }
// Tasks due today or tomorrow, and newly overdue — one reminder per task per day.
$rows = all("SELECT t.id,t.title,t.due_date,t.assignee_id FROM tasks t
             WHERE t.status IN ('todo','in_progress','blocked') AND t.assignee_id IS NOT NULL AND t.due_date IS NOT NULL AND t.due_date <= (CURDATE() + INTERVAL 1 DAY)");
foreach ($rows as $t) {
    $kind = $t['due_date'] < date('Y-m-d') ? 'overdue' : ($t['due_date'] === date('Y-m-d') ? 'due today' : 'due tomorrow');
    $link = '#/tasks/' . $t['id'];
    $dup = one("SELECT id FROM notifications WHERE user_id=? AND type='task_reminder' AND link=? AND title LIKE ? AND created_at >= CURDATE()",
               [$t['assignee_id'], $link, '%' . $kind . '%']);
    if ($dup) continue;
    notify((int)$t['assignee_id'], 'task_reminder', ucfirst($kind) . ': ' . $t['title'], 'Due ' . date('D j M', strtotime($t['due_date'])), $link);
    $made++;
}
// Events starting within the next 60 minutes
$ev = all("SELECT e.id,e.title,e.starts_at, e.creator_id FROM events e WHERE e.starts_at BETWEEN NOW() AND (NOW() + INTERVAL 60 MINUTE)");
foreach ($ev as $e) {
    $people = array_column(all('SELECT user_id FROM event_attendees WHERE event_id=?', [$e['id']]), 'user_id');
    $people[] = $e['creator_id'];
    foreach (array_unique($people) as $pid) {
        $title = 'Starting soon: ' . $e['title'];
        if (one("SELECT id FROM notifications WHERE user_id=? AND type='event_soon' AND title=? AND created_at > (NOW() - INTERVAL 3 HOUR)", [$pid, $title])) continue;
        notify((int)$pid, 'event_soon', $title, date('D j M, H:i', strtotime($e['starts_at'])), '#/schedule');
        $made++;
    }
}
// Contact follow-ups due today or earlier: remind the owner once a day
if (col_exists('contacts', 'followup_date')) {
    foreach (all('SELECT ct.id,ct.name,ct.owner_id,ct.followup_date,c.name AS company FROM contacts ct LEFT JOIN companies c ON c.id=ct.company_id WHERE ct.followup_date IS NOT NULL AND ct.followup_date <= CURDATE() AND ct.owner_id IS NOT NULL') as $f) {
        $title = 'Follow up: ' . $f['name'] . ($f['company'] ? ' (' . $f['company'] . ')' : '');
        if (one("SELECT id FROM notifications WHERE user_id=? AND type='followup' AND title=? AND created_at >= CURDATE()", [$f['owner_id'], $title])) continue;
        notify((int)$f['owner_id'], 'followup', $title, 'Due ' . date('D j M', strtotime($f['followup_date'])), '#/pipeline');
        $made++;
    }
}
// Archived users are deleted for good 60 days after they were archived
try { if (col_exists('users', 'archived_at')) { require_once __DIR__ . '/api/lib/accounts.php'; echo 'Archive: ' . purge_archived_users() . " account(s) deleted.\n"; } } catch (Throwable $e) { echo 'Archive cleanup skipped: ' . $e->getMessage() . "\n"; }
try { if (col_exists('exec_sources', 'id')) { require_once __DIR__ . '/api/lib/exec.php'; echo 'Executive sources refreshed: ' . ex_refresh_due() . "\n"; } } catch (Throwable $e) { echo 'Executive refresh skipped: ' . $e->getMessage() . "\n"; }
q('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
q('DELETE FROM notifications WHERE read_at IS NOT NULL AND created_at < (NOW() - INTERVAL 90 DAY)');
echo "OK, $made notifications created.\n";
