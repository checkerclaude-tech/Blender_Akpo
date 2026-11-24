<?php
declare(strict_types=1);
// Finance: product / service lines, optional signatures and the discussion on a document. Runs from /upgrade.php, and by itself the first time Finance is opened.
require_once __DIR__ . '/migrate.php';

/** Finance: product / service lines, optional signatures, vendors, document looks, templates and the discussion on a document. Safe to run again. */
function migrate_fin_ext(): array {
    $log = [];
    if (!col_exists('fin_items', 'kind')) { db()->exec("ALTER TABLE fin_items ADD COLUMN kind VARCHAR(8) NULL"); $log[] = 'Finance items: product / service type added.'; }
    if (!col_exists('doc_approvals', 'require_sig')) { db()->exec('ALTER TABLE doc_approvals ADD COLUMN require_sig TINYINT(1) NOT NULL DEFAULT 1'); $log[] = 'Approvals: optional signature added.'; }
    db()->exec("CREATE TABLE IF NOT EXISTS vendors (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, contact_name VARCHAR(160) NULL, email VARCHAR(190) NULL, phone VARCHAR(60) NULL, address VARCHAR(500) NULL, notes TEXT NULL,
        created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!col_exists('products', 'vendor_id')) { db()->exec('ALTER TABLE products ADD COLUMN vendor_id INT UNSIGNED NULL, ADD KEY (vendor_id)'); $log[] = 'Products and services: vendor added.'; }
    db()->exec("CREATE TABLE IF NOT EXISTS fin_styles (scope VARCHAR(40) NOT NULL PRIMARY KEY, data MEDIUMTEXT NOT NULL, updated_by INT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS fin_templates (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, doc_type VARCHAR(20) NOT NULL, data MEDIUMTEXT NOT NULL, styles MEDIUMTEXT NULL, created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY (doc_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS fin_comments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, doc_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, to_user_id INT UNSIGNED NULL, body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (doc_id,id), KEY (to_user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $log[] = 'Finance: product / service types, optional signatures and document comments ready.'; return $log;
}
if (!function_exists('migrate_finance3')) { function migrate_finance3(): array { return migrate_fin_ext(); } }   // (an older migrate_finance3 may already sit in migrate.php; migrate_fin_ext has its own name so it can never be shadowed)
