<?php
// Run after uploading a new version: sign in as Super Admin first, then open /upgrade.php.
// Adds any new tables/columns. Existing data is untouched. Safe to run repeatedly.
declare(strict_types=1);
require __DIR__ . '/api/lib/core.php';
require __DIR__ . '/api/lib/migrate.php';
require __DIR__ . '/api/lib/migrate_agent.php';
header('Content-Type: text/plain; charset=utf-8');
$u = me();
if (!$u || $u['role'] !== 'super_admin') { http_response_code(403); exit("Sign in to the app as Super Admin first, then reload this page.\n"); }
try { foreach (array_merge(migrate_pipeline(), migrate_mail(), migrate_depts(), migrate_campaigns(), migrate_learn(), migrate_market(), migrate_invites(), migrate_tasks2(), migrate_finance(), migrate_agent(), migrate_reports(), migrate_accounts(), migrate_templates(), migrate_courses(), migrate_weekly(), migrate_games(), migrate_privileges(), migrate_exec(), migrate_approvals(), migrate_remember(), migrate_workweek(), migrate_meetings(), migrate_course_content(), migrate_share_msgs(), migrate_requisitions(), migrate_inbox_auto(), migrate_fin3(), migrate_prospecting(), migrate_mailtest(), migrate_finance3()) as $l) echo $l . "\n"; echo "\nUpgrade complete.\n"; }
catch (Throwable $e) { http_response_code(500); echo 'Upgrade failed: ' . $e->getMessage() . "\n"; }
