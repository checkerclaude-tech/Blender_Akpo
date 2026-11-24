<?php
declare(strict_types=1);
// Finance: product / service lines, optional signatures and the discussion on a document. Runs from /upgrade.php, and by itself the first time Finance is opened.
require_once __DIR__ . '/migrate.php';

if (!function_exists('migrate_finance3')) {   // (an earlier copy of this function may already be in migrate.php)
/** Finance: product / service lines, optional signatures, and the discussion on a document. */
function migrate_finance3(): array {
    $log = [];
    if (!col_exists('fin_items', 'kind')) { db()->exec("ALTER TABLE fin_items ADD COLUMN kind VARCHAR(8) NULL"); $log[] = 'Finance items: product / service type added.'; }
    if (!col_exists('doc_approvals', 'require_sig')) { db()->exec('ALTER TABLE doc_approvals ADD COLUMN require_sig TINYINT(1) NOT NULL DEFAULT 1'); $log[] = 'Approvals: optional signature added.'; }
    db()->exec("CREATE TABLE IF NOT EXISTS fin_comments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, doc_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, to_user_id INT UNSIGNED NULL, body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (doc_id,id), KEY (to_user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $log[] = 'Finance: product / service types, optional signatures and document comments ready.'; return $log;
}
}
