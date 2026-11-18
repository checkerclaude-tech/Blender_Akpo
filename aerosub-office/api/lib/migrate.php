<?php
declare(strict_types=1);
// Safe to run many times. Adds the Pipeline tables/columns without touching existing data.

function col_exists(string $table, string $col): bool {
    return (bool)one('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $col]);
}

function migrate_pipeline(): array {
    $log = [];
    $stmts = [
"CREATE TABLE IF NOT EXISTS companies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  sector VARCHAR(80) NULL,
  location VARCHAR(120) NULL,
  website VARCHAR(190) NULL,
  stage ENUM('researching','contact_identified','outreach_sent','in_discussion','proposal_sent','negotiating','won','on_hold') NOT NULL DEFAULT 'researching',
  priority ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
  owner_id INT UNSIGNED NULL,
  pain_points TEXT NULL,
  current_solution TEXT NULL,
  notes TEXT NULL,
  flag_note VARCHAR(300) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (stage), INDEX (owner_id), INDEX (name),
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS contacts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  position VARCHAR(160) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(60) NULL,
  linkedin VARCHAR(300) NULL,
  location VARCHAR(120) NULL,
  followup_date DATE NULL,
  notes TEXT NULL,
  owner_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (company_id), INDEX (followup_date), INDEX (name),
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($stmts as $s) db()->exec($s);
    $log[] = 'Pipeline tables ready.';
    if (!col_exists('tasks', 'company_id')) {
        db()->exec('ALTER TABLE tasks ADD COLUMN company_id INT UNSIGNED NULL AFTER department_id, ADD COLUMN contact_id INT UNSIGNED NULL AFTER company_id,
                    ADD INDEX (company_id), ADD FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL, ADD FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL');
        $log[] = 'Tasks can now be linked to companies and contacts.';
    }
    return $log;
}

function migrate_depts(): array {
    $log = [];
    db()->exec('CREATE TABLE IF NOT EXISTS user_departments (user_id INT UNSIGNED NOT NULL, department_id INT UNSIGNED NOT NULL, PRIMARY KEY (user_id, department_id), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    db()->exec('INSERT IGNORE INTO user_departments (user_id, department_id) SELECT id, department_id FROM users WHERE department_id IS NOT NULL');
    foreach (["Marketing", "C-Class"] as $d) { $r = db()->prepare('INSERT IGNORE INTO departments (name) VALUES (?)'); $r->execute([$d]); }
    $log[] = 'Departments: people can now belong to several departments; Marketing and C-Class departments ready.';
    return $log;
}

function migrate_mail(): array {
    $log = [];
    if (!col_exists('mail_accounts', 'signature')) {
        db()->exec('ALTER TABLE mail_accounts ADD COLUMN signature TEXT NULL');
        $log[] = 'Mail: signature column added.';
    }
    foreach (['from_name' => 'VARCHAR(120) NULL', 'sig_html' => 'TEXT NULL'] as $c => $def) {
        if (!col_exists('mail_accounts', $c)) { db()->exec("ALTER TABLE mail_accounts ADD COLUMN $c $def"); $log[] = "Mail: $c column added."; }
    }
    // allow several mailboxes per person (and the same mailbox for several people): unique per (person, address)
    $idx = db()->query("SHOW INDEX FROM mail_accounts WHERE Key_name='uq_user_email'")->fetchAll();
    if (!$idx) {
        db()->exec('ALTER TABLE mail_accounts ADD UNIQUE INDEX uq_user_email (user_id, email)');
        foreach (db()->query("SHOW INDEX FROM mail_accounts WHERE Non_unique=0 AND Column_name='user_id' AND Seq_in_index=1")->fetchAll() as $r) {
            if ($r['Key_name'] !== 'uq_user_email' && $r['Key_name'] !== 'PRIMARY') {
                $cols = db()->query("SHOW INDEX FROM mail_accounts WHERE Key_name=" . db()->quote($r['Key_name']))->fetchAll();
                if (count($cols) === 1) { db()->exec('ALTER TABLE mail_accounts DROP INDEX `' . str_replace('`', '', $r['Key_name']) . '`'); $log[] = 'Mail: one-mailbox limit removed.'; }
            }
        }
    }
    db()->exec('CREATE TABLE IF NOT EXISTS user_avatars (user_id INT UNSIGNED PRIMARY KEY, mime VARCHAR(40) NOT NULL, data MEDIUMBLOB NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $log[] = 'Mail tables ready.';
    return $log;
}

function migrate_campaigns(): array {
    $T = [
"CREATE TABLE IF NOT EXISTS camp_settings (
  id TINYINT UNSIGNED PRIMARY KEY, smtp_host VARCHAR(190) NULL, smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587, smtp_user VARCHAR(190) NULL, secret_enc TEXT NULL,
  from_email VARCHAR(190) NULL, from_name VARCHAR(120) NULL, reply_to VARCHAR(190) NULL, address VARCHAR(300) NULL,
  batch_size SMALLINT UNSIGNED NOT NULL DEFAULT 100, hour_cap SMALLINT UNSIGNED NOT NULL DEFAULT 400, app_url VARCHAR(190) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS camp_lists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, description VARCHAR(300) NULL, created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS camp_contacts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, list_id INT UNSIGNED NOT NULL, email VARCHAR(190) NOT NULL, first_name VARCHAR(100) NULL, last_name VARCHAR(100) NULL, company VARCHAR(160) NULL,
  status ENUM('subscribed','unsubscribed','bounced') NOT NULL DEFAULT 'subscribed', verify ENUM('unchecked','valid','risky','invalid') NOT NULL DEFAULT 'unchecked', verify_note VARCHAR(160) NULL,
  token CHAR(32) NOT NULL, source VARCHAR(30) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_list_email (list_id, email), UNIQUE KEY uq_token (token), INDEX (email), INDEX (list_id, status, verify),
  FOREIGN KEY (list_id) REFERENCES camp_lists(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS camp_suppress (
  email VARCHAR(190) PRIMARY KEY, reason VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS camp_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, mime VARCHAR(40) NOT NULL, data MEDIUMBLOB NOT NULL, k CHAR(16) NOT NULL, created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS camp_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, subject VARCHAR(200) NULL, design MEDIUMTEXT NOT NULL, created_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS camp_campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(140) NOT NULL, subject VARCHAR(200) NOT NULL, preheader VARCHAR(200) NULL, design MEDIUMTEXT NOT NULL,
  list_id INT UNSIGNED NULL, from_name VARCHAR(120) NULL, from_email VARCHAR(190) NULL, include_risky TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('draft','scheduled','sending','paused','sent') NOT NULL DEFAULT 'draft', scheduled_at DATETIME NULL, started_at DATETIME NULL, finished_at DATETIME NULL,
  total INT UNSIGNED NOT NULL DEFAULT 0, sent INT UNSIGNED NOT NULL DEFAULT 0, failed INT UNSIGNED NOT NULL DEFAULT 0, last_error VARCHAR(300) NULL,
  created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (status),
  FOREIGN KEY (list_id) REFERENCES camp_lists(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS camp_queue (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, campaign_id INT UNSIGNED NOT NULL, contact_id INT UNSIGNED NOT NULL, status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  error VARCHAR(200) NULL, sent_at DATETIME NULL, UNIQUE KEY uq_cc (campaign_id, contact_id), INDEX (campaign_id, status), INDEX (sent_at),
  FOREIGN KEY (campaign_id) REFERENCES camp_campaigns(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS camp_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kind ENUM('open','click','unsub') NOT NULL, campaign_id INT UNSIGNED NOT NULL DEFAULT 0, step_id INT UNSIGNED NOT NULL DEFAULT 0, contact_id INT UNSIGNED NOT NULL,
  url VARCHAR(500) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (campaign_id, kind, contact_id), INDEX (step_id, kind, contact_id), INDEX (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS camp_autos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(140) NOT NULL, list_id INT UNSIGNED NULL, from_name VARCHAR(120) NULL, from_email VARCHAR(190) NULL,
  status ENUM('draft','active','paused') NOT NULL DEFAULT 'draft', include_existing TINYINT(1) NOT NULL DEFAULT 0, activated_at DATETIME NULL, created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (list_id) REFERENCES camp_lists(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS camp_auto_steps (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, auto_id INT UNSIGNED NOT NULL, position SMALLINT UNSIGNED NOT NULL, delay_hours INT UNSIGNED NOT NULL DEFAULT 0, subject VARCHAR(200) NOT NULL, preheader VARCHAR(200) NULL, design MEDIUMTEXT NOT NULL,
  UNIQUE KEY uq_pos (auto_id, position), FOREIGN KEY (auto_id) REFERENCES camp_autos(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS camp_auto_state (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, auto_id INT UNSIGNED NOT NULL, contact_id INT UNSIGNED NOT NULL, next_pos SMALLINT UNSIGNED NOT NULL, next_at DATETIME NOT NULL,
  status ENUM('active','done','stopped') NOT NULL DEFAULT 'active', UNIQUE KEY uq_ac (auto_id, contact_id), INDEX (status, next_at),
  FOREIGN KEY (auto_id) REFERENCES camp_autos(id) ON DELETE CASCADE, FOREIGN KEY (contact_id) REFERENCES camp_contacts(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS camp_auto_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, state_id INT UNSIGNED NOT NULL, auto_id INT UNSIGNED NOT NULL, step_id INT UNSIGNED NOT NULL, contact_id INT UNSIGNED NOT NULL,
  status ENUM('sent','failed') NOT NULL, error VARCHAR(200) NULL, sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (step_id, status), INDEX (sent_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($T as $sql) db()->exec($sql);
    db()->exec('INSERT IGNORE INTO camp_settings (id) VALUES (1)');
    return ['Campaigns tables ready.'];
}

function migrate_learn(): array {
    $log = [];
    db()->exec("CREATE TABLE IF NOT EXISTS sop_pages (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, department_id INT UNSIGNED NULL, title VARCHAR(200) NOT NULL, body MEDIUMTEXT NOT NULL, updated_by INT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $add = ['category' => "VARCHAR(80) NULL", 'status' => "ENUM('draft','published') NOT NULL DEFAULT 'published'", 'pinned' => 'TINYINT(1) NOT NULL DEFAULT 0', 'requires_ack' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'version' => 'INT UNSIGNED NOT NULL DEFAULT 1', 'created_by' => 'INT UNSIGNED NULL', 'created_at' => 'DATETIME NULL'];
    foreach ($add as $c => $def) if (!col_exists('sop_pages', $c)) { db()->exec("ALTER TABLE sop_pages ADD COLUMN $c $def"); $log[] = "Learn: $c column added."; }
    db()->exec("CREATE TABLE IF NOT EXISTS sop_versions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, page_id INT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL, title VARCHAR(200) NOT NULL, body MEDIUMTEXT NOT NULL, saved_by INT UNSIGNED NULL,
        saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (page_id, version), FOREIGN KEY (page_id) REFERENCES sop_pages(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS sop_acks (page_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL, acked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (page_id, user_id),
        FOREIGN KEY (page_id) REFERENCES sop_pages(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS sop_images (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, mime VARCHAR(40) NOT NULL, data MEDIUMBLOB NOT NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $log[] = 'Learn (SOP) tables ready.';
    return $log;
}

function migrate_market(): array {
    $log = [];
    $t = [
    "CREATE TABLE IF NOT EXISTS competitors (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, website VARCHAR(255) NULL, category VARCHAR(80) NULL,
        threat ENUM('low','medium','high') NOT NULL DEFAULT 'medium', offering TEXT NULL, pricing TEXT NULL, strengths TEXT NULL, weaknesses TEXT NULL, our_edge TEXT NULL,
        created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS competitor_intel (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, competitor_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NULL, kind VARCHAR(30) NOT NULL DEFAULT 'note',
        note TEXT NOT NULL, source_url VARCHAR(500) NULL, company_id INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (competitor_id),
        FOREIGN KEY (competitor_id) REFERENCES competitors(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS trade_events (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, kind VARCHAR(40) NOT NULL DEFAULT 'trade show', location VARCHAR(200) NULL, url VARCHAR(500) NULL,
        starts_on DATE NOT NULL, ends_on DATE NULL, status ENUM('considering','registered','attending','done','skipped') NOT NULL DEFAULT 'considering', budget DECIMAL(12,2) NULL, actual_cost DECIMAL(12,2) NULL,
        goals TEXT NULL, notes TEXT NULL, recap TEXT NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY (starts_on)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS trade_event_people (event_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, PRIMARY KEY (event_id,user_id), FOREIGN KEY (event_id) REFERENCES trade_events(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS trade_event_leads (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_id INT UNSIGNED NOT NULL, name VARCHAR(160) NOT NULL, company VARCHAR(160) NULL, email VARCHAR(190) NULL, phone VARCHAR(60) NULL,
        note TEXT NULL, followed_up TINYINT(1) NOT NULL DEFAULT 0, user_id INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (event_id),
        FOREIGN KEY (event_id) REFERENCES trade_events(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($t as $s) db()->exec($s);
    $log[] = 'Competition and Events tables ready.';
    return $log;
}

function migrate_invites(): array {
    db()->exec("CREATE TABLE IF NOT EXISTS invites (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token_hash CHAR(64) NOT NULL UNIQUE, email VARCHAR(190) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'employee',
        department_ids VARCHAR(200) NULL, job_title VARCHAR(120) NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NOT NULL, used_at DATETIME NULL, KEY (email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return ['Invite links ready.'];
}

function migrate_tasks2(): array {
    $log = [];
    $add = ['task_type' => "VARCHAR(20) NOT NULL DEFAULT 'general'", 'project_id' => 'INT UNSIGNED NULL', 'starts_at' => 'DATETIME NULL', 'ends_at' => 'DATETIME NULL',
            'defer_count' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0', 'remind_sent' => 'TINYINT(1) NOT NULL DEFAULT 0', 'source' => 'VARCHAR(30) NULL'];
    foreach ($add as $c => $def) if (!col_exists('tasks', $c)) { db()->exec("ALTER TABLE tasks ADD COLUMN $c $def"); $log[] = "Tasks: $c added."; }
    foreach ([
    "CREATE TABLE IF NOT EXISTS task_files (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NULL, name VARCHAR(200) NOT NULL, mime VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
        size INT UNSIGNED NOT NULL DEFAULT 0, path VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (task_id), FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS task_shares (task_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, shared_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (task_id,user_id),
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS task_updates (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NULL, kind VARCHAR(20) NOT NULL, data MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (task_id), FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS projects (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, client VARCHAR(160) NULL, status ENUM('active','closed') NOT NULL DEFAULT 'active',
        created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS project_members (project_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, PRIMARY KEY (project_id,user_id), FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS field_reports (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, project_id INT UNSIGNED NOT NULL, task_id INT UNSIGNED NULL, user_id INT UNSIGNED NULL, type VARCHAR(30) NOT NULL, data MEDIUMTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (project_id), FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ] as $s) db()->exec($s);
    $log[] = 'Task files, sharing, updates, projects and field reports ready.';
    return $log;
}

function migrate_finance(): array {
    foreach (['Finance'] as $d) { $r = db()->prepare('INSERT IGNORE INTO departments (name) VALUES (?)'); $r->execute([$d]); }
    foreach ([
    "CREATE TABLE IF NOT EXISTS fin_settings (id TINYINT UNSIGNED PRIMARY KEY, company_name VARCHAR(160) NULL, address TEXT NULL, email VARCHAR(190) NULL, phone VARCHAR(60) NULL, tax_id VARCHAR(80) NULL,
        bank_details TEXT NULL, currency VARCHAR(8) NOT NULL DEFAULT 'USD', tax_label VARCHAR(30) NOT NULL DEFAULT 'VAT', tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0, terms TEXT NULL, footer VARCHAR(300) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS fin_docs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, doc_type ENUM('commercial','proforma','invoice','receipt') NOT NULL, number VARCHAR(40) NOT NULL, task_id INT UNSIGNED NULL,
        company_id INT UNSIGNED NULL, po_number VARCHAR(80) NULL, rfq_ref VARCHAR(80) NULL, client_name VARCHAR(190) NOT NULL, client_address TEXT NULL, client_email VARCHAR(190) NULL, currency VARCHAR(8) NOT NULL DEFAULT 'USD',
        issue_date DATE NOT NULL, due_date DATE NULL, valid_until DATE NULL, status ENUM('draft','sent','paid','void') NOT NULL DEFAULT 'draft', tax_label VARCHAR(30) NOT NULL DEFAULT 'VAT', tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
        discount DECIMAL(14,2) NOT NULL DEFAULT 0, notes TEXT NULL, terms TEXT NULL, parent_id INT UNSIGNED NULL, amount_paid DECIMAL(14,2) NULL, pay_method VARCHAR(60) NULL, pay_ref VARCHAR(120) NULL,
        created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_number (number), KEY (task_id), KEY (doc_type,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS fin_items (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, doc_id INT UNSIGNED NOT NULL, position SMALLINT UNSIGNED NOT NULL DEFAULT 0, description VARCHAR(500) NOT NULL,
        qty DECIMAL(12,3) NOT NULL DEFAULT 1, unit VARCHAR(30) NULL, unit_price DECIMAL(14,2) NOT NULL DEFAULT 0, KEY (doc_id), FOREIGN KEY (doc_id) REFERENCES fin_docs(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS products (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kind ENUM('product','service') NOT NULL DEFAULT 'service', name VARCHAR(190) NOT NULL, sku VARCHAR(60) NULL, category VARCHAR(80) NULL,
        description TEXT NULL, unit VARCHAR(30) NULL, price DECIMAL(14,2) NULL, currency VARCHAR(8) NOT NULL DEFAULT 'USD', published TINYINT(1) NOT NULL DEFAULT 1, image_url VARCHAR(500) NULL,
        created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY (kind,category)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS banners (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, body TEXT NULL, link VARCHAR(500) NULL, level ENUM('info','success','warning') NOT NULL DEFAULT 'info',
        department_id INT UNSIGNED NULL, starts_on DATE NULL, ends_on DATE NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ] as $q) db()->exec($q);
    db()->exec("INSERT IGNORE INTO fin_settings (id,company_name) VALUES (1,'Aerosub')");
    return ['Finance documents, products & services and news banners ready.'];
}

// Daily task reports, department heads and the extra task statuses. Safe to run repeatedly.
function migrate_reports(): array {
    $log = [];
    $ct = one("SELECT COLUMN_TYPE ct FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tasks' AND COLUMN_NAME='status'");
    if ($ct && strpos((string)$ct['ct'], 'transferred') === false) {
        // todo = Not yet started, in_progress = Open / ongoing, done = Completed; blocked is kept as before.
        db()->exec("ALTER TABLE tasks MODIFY COLUMN status ENUM('todo','in_progress','blocked','done','deferred','canceled','transferred') NOT NULL DEFAULT 'todo'");
        $log[] = 'Tasks: new statuses Deferred, Canceled and Transferred added.';
    }
    foreach (['status_reason' => 'VARCHAR(300) NULL', 'transferred_to_id' => 'INT UNSIGNED NULL', 'transferred_from_id' => 'INT UNSIGNED NULL'] as $c => $def)
        if (!col_exists('tasks', $c)) { db()->exec("ALTER TABLE tasks ADD COLUMN $c $def"); $log[] = "Tasks: $c added."; }
    if (!col_exists('departments', 'head_id')) {
        db()->exec('ALTER TABLE departments ADD COLUMN head_id INT UNSIGNED NULL, ADD FOREIGN KEY (head_id) REFERENCES users(id) ON DELETE SET NULL');
        $log[] = 'Departments: head of department added.';
    }
    foreach ([
    "CREATE TABLE IF NOT EXISTS daily_reports (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, report_date DATE NOT NULL,
        status VARCHAR(20) NOT NULL, progress TINYINT UNSIGNED NOT NULL DEFAULT 0, hours DECIMAL(4,1) NULL, summary TEXT NOT NULL, blockers TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_report (task_id,user_id,report_date), KEY (report_date), KEY (user_id,report_date),
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS report_comments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, report_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (report_id),
        FOREIGN KEY (report_id) REFERENCES daily_reports(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS report_shares (report_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, shared_by INT UNSIGNED NULL, note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (report_id,user_id), KEY (user_id),
        FOREIGN KEY (report_id) REFERENCES daily_reports(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ] as $s) db()->exec($s);
    $log[] = 'Daily reports, report comments and report sharing ready.';
    return $log;
}

// Password recovery and archived (soft-deleted) users. Safe to run repeatedly.
function migrate_accounts(): array {
    $log = [];
    foreach (['archived_at' => 'DATETIME NULL', 'archived_by' => 'INT UNSIGNED NULL'] as $c => $def)
        if (!col_exists('users', $c)) { db()->exec("ALTER TABLE users ADD COLUMN $c $def"); $log[] = "Users: $c added."; }
    db()->exec("CREATE TABLE IF NOT EXISTS password_resets (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY (token_hash), KEY (user_id), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $log[] = 'Password recovery and user archive ready.';
    return $log;
}

// Invoice and commercial templates: extra document fields, structured bank details, automatic invoice numbers. Safe to run repeatedly.
function migrate_templates(): array {
    $log = [];
    db()->exec('INSERT IGNORE INTO fin_settings (id) VALUES (1)');
    $docCols = ['customer_id' => 'VARCHAR(60) NULL', 'sales_rep' => 'VARCHAR(80) NULL', 'attention' => 'VARCHAR(120) NULL', 'terms_instr' => 'VARCHAR(120) NULL', 'payment_terms' => 'VARCHAR(120) NULL',
                'ship_to' => 'VARCHAR(500) NULL', 'shipping_method' => 'VARCHAR(80) NULL', 'delivery_leadtime' => 'VARCHAR(120) NULL', 'subject' => 'VARCHAR(300) NULL', 'days' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 1', 'intro' => 'TEXT NULL'];
    foreach ($docCols as $c => $def) if (!col_exists('fin_docs', $c)) { db()->exec("ALTER TABLE fin_docs ADD COLUMN $c $def"); $log[] = "Finance documents: $c added."; }
    foreach (['category' => 'VARCHAR(120) NULL', 'freq' => 'VARCHAR(40) NULL', 'amount_note' => 'VARCHAR(40) NULL'] as $c => $def)
        if (!col_exists('fin_items', $c)) { db()->exec("ALTER TABLE fin_items ADD COLUMN $c $def"); $log[] = "Finance items: $c added."; }
    foreach (['bank_name' => 'VARCHAR(120) NULL', 'account_name' => 'VARCHAR(160) NULL', 'account_number' => 'VARCHAR(60) NULL', 'website' => 'VARCHAR(120) NULL', 'rc_number' => 'VARCHAR(40) NULL', 'inv_next' => 'BIGINT UNSIGNED NOT NULL DEFAULT 100026706'] as $c => $def)
        if (!col_exists('fin_settings', $c)) { db()->exec("ALTER TABLE fin_settings ADD COLUMN $c $def"); $log[] = "Finance settings: $c added."; }
    // brand details taken from the invoice template, filled in only where nothing has been entered yet
    foreach (['company_name' => 'Aerosub Solutions Limited', 'phone' => '+234 703 310 0499', 'email' => 'info@aerosub.co', 'website' => 'www.aerosub.co', 'rc_number' => '1757563', 'address' => '16 Amadi Close, Rumuomasi, Port Harcourt.'] as $c => $v)
        q("UPDATE fin_settings SET $c=? WHERE id=1 AND ($c IS NULL OR $c='')", [$v]);
    q("UPDATE fin_settings SET company_name='Aerosub Solutions Limited' WHERE id=1 AND company_name='Aerosub'");
    // continue after the highest numeric invoice number already used
    $m = one("SELECT MAX(CAST(number AS UNSIGNED)) m FROM fin_docs WHERE doc_type='invoice' AND number REGEXP '^[0-9]{6,}$'");
    if ($m && $m['m']) q('UPDATE fin_settings SET inv_next=GREATEST(inv_next, ?) WHERE id=1', [(int)$m['m'] + 1]);
    $log[] = 'Invoice and commercial templates ready (next invoice number: ' . (one('SELECT inv_next n FROM fin_settings WHERE id=1')['n'] ?? '?') . ').';
    return $log;
}
