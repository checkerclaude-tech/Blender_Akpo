<?php
declare(strict_types=1);
// Agent platform tables and columns. Safe to run many times; existing data is untouched.
// Called from install.php and upgrade.php. Needs col_exists() from migrate.php.

function migrate_agent(): array {
    $log = [];

    // --- columns on existing pipeline tables (so approved agent rows can be told apart) ---
    $cols = [
        'companies' => ['source' => 'VARCHAR(30) NULL'],
        'contacts'  => ['source' => 'VARCHAR(30) NULL', 'source_note' => 'VARCHAR(300) NULL', 'lawful_basis' => 'VARCHAR(60) NULL', 'retain_until' => 'DATE NULL'],
    ];
    foreach ($cols as $table => $defs) foreach ($defs as $c => $def) {
        if (!col_exists($table, $c)) { db()->exec("ALTER TABLE $table ADD COLUMN $c $def"); $log[] = "Agent: $table.$c added."; }
    }

    $T = [
// Stage changes per company, so the Agent site does not have to read text out of activity_log.
"CREATE TABLE IF NOT EXISTS company_stage_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  from_stage VARCHAR(30) NULL,
  to_stage VARCHAR(30) NOT NULL,
  changed_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (company_id, created_at),
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// The laptop's credential. Only a hash is stored; the token itself is shown once when created.
"CREATE TABLE IF NOT EXISTS agent_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(80) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// One row per token per minute, used to rate-limit the agent endpoints.
"CREATE TABLE IF NOT EXISTS agent_rate (
  token_id INT UNSIGNED NOT NULL,
  window_start DATETIME NOT NULL,
  hits SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (token_id, window_start),
  FOREIGN KEY (token_id) REFERENCES agent_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Single row: when the laptop last reported in and what it is doing (drives the health view).
"CREATE TABLE IF NOT EXISTS agent_heartbeat (
  id TINYINT UNSIGNED PRIMARY KEY,
  last_seen_at DATETIME NOT NULL,
  queue_length INT UNSIGNED NOT NULL DEFAULT 0,
  current_job VARCHAR(160) NULL,
  agent_version VARCHAR(40) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// A retried write with the same key returns the first answer instead of writing twice (PRD N4).
"CREATE TABLE IF NOT EXISTS agent_idempotency (
  idem_key VARCHAR(80) NOT NULL,
  token_id INT UNSIGNED NOT NULL,
  route VARCHAR(60) NOT NULL,
  response MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_id, idem_key),
  FOREIGN KEY (token_id) REFERENCES agent_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NULL,
  task_id INT UNSIGNED NULL,
  original_name VARCHAR(200) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  content_sha256 CHAR(64) NOT NULL,
  storage_path VARCHAR(160) NOT NULL,
  status ENUM('queued','checking','ready','failed') NOT NULL DEFAULT 'queued',
  status_note VARCHAR(200) NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  checked_at DATETIME NULL,
  INDEX (content_sha256), INDEX (company_id), INDEX (status),
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
  FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS document_reviews (
  document_id INT UNSIGNED PRIMARY KEY,
  scorecard MEDIUMTEXT NOT NULL,
  summary TEXT NULL,
  action_points MEDIUMTEXT NULL,
  conflicts MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS document_claims (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL,
  claim_text TEXT NOT NULL,
  claim_kind VARCHAR(30) NOT NULL DEFAULT 'other',
  label ENUM('supported','partly_supported','not_supported','cannot_check','cited_not_online','source_suggested','no_source','author_flagged') NOT NULL,
  reason VARCHAR(30) NULL,
  source_url VARCHAR(500) NULL,
  source_tier ENUM('primary','news','weak') NULL,
  quote TEXT NULL,
  check_priority SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  INDEX (document_id, position),
  FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS research_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NULL,
  task_id INT UNSIGNED NULL,
  subject_name VARCHAR(160) NULL,
  requested_by INT UNSIGNED NULL,
  note VARCHAR(500) NULL,
  status ENUM('waiting','researching','ready','failed') NOT NULL DEFAULT 'waiting',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (status), INDEX (company_id),
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Things the agent proposes. Nothing here is visible to the team; approval copies it into the pipeline.
"CREATE TABLE IF NOT EXISTS agent_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('signal','company','contact','task','research') NOT NULL,
  headline VARCHAR(300) NOT NULL,
  score TINYINT UNSIGNED NULL,
  payload MEDIUMTEXT NOT NULL,
  status ENUM('pending','approved','rejected','stale') NOT NULL DEFAULT 'pending',
  sync_state ENUM('none','syncing','synced') NOT NULL DEFAULT 'none',
  request_id INT UNSIGNED NULL,
  idempotency_key VARCHAR(80) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  decided_by INT UNSIGNED NULL,
  INDEX (status, score), INDEX (created_at),
  FOREIGN KEY (request_id) REFERENCES research_requests(id) ON DELETE SET NULL,
  FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// The owner's decisions (PRD L1). Team ratings live in agent_ratings, kept apart on purpose (R11).
"CREATE TABLE IF NOT EXISTS agent_feedback (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  actor_id INT UNSIGNED NULL,
  action ENUM('approve','edit_approve','reject','clear_flag') NOT NULL,
  reason_code VARCHAR(30) NULL,
  before_json MEDIUMTEXT NULL,
  after_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (item_id),
  FOREIGN KEY (item_id) REFERENCES agent_items(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS agent_ratings (
  user_id INT UNSIGNED NOT NULL,
  subject_type ENUM('company','contact','task') NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  rating ENUM('useful','not_useful') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, subject_type, subject_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Agent site sign-in for the owner: authenticator-app secret (encrypted), recovery codes (hashed), "sign out everywhere" counter.
"CREATE TABLE IF NOT EXISTS agent_owner_auth (
  user_id INT UNSIGNED PRIMARY KEY,
  totp_secret_enc TEXT NOT NULL,
  confirmed_at DATETIME NULL,
  last_step BIGINT UNSIGNED NOT NULL DEFAULT 0,
  recovery_json TEXT NULL,
  session_epoch INT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Owner-editable settings (scoring weights, public source list, schedules). The laptop reads them; the Agent site edits them.
"CREATE TABLE IF NOT EXISTS agent_settings (
  k VARCHAR(40) PRIMARY KEY,
  v MEDIUMTEXT NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Where each agent-created pipeline row came from: the approved item, its score and who approved it (for the Agent badge).
"CREATE TABLE IF NOT EXISTS agent_origins (
  subject_type ENUM('company','contact','task') NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  score TINYINT UNSIGNED NULL,
  approved_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (subject_type, subject_id),
  FOREIGN KEY (item_id) REFERENCES agent_items(id) ON DELETE CASCADE,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// Chat with the Research agent (owner only). Business content, so stored readable. The Personal agent's chat is NOT stored here (it uses the encrypted relay).
"CREATE TABLE IF NOT EXISTS agent_chats (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agent VARCHAR(20) NOT NULL DEFAULT 'research',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (agent, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// A message is the owner's or the agent's. An agent message may carry an action for the owner to approve (research a company, check a link).
"CREATE TABLE IF NOT EXISTS agent_chat_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chat_id INT UNSIGNED NOT NULL,
  role ENUM('owner','agent') NOT NULL,
  body TEXT NOT NULL,
  sources TEXT NULL,
  action_kind VARCHAR(30) NULL,
  action_args TEXT NULL,
  action_state ENUM('pending','approved','done','dismissed','failed') NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (chat_id, id), INDEX (action_state),
  FOREIGN KEY (chat_id) REFERENCES agent_chats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// The Personal agent's end-to-end encrypted mailbox (PRD N12). The server stores ONLY opaque encrypted text, never a key,
// and a row is deleted as soon as the other side has collected it (or after 7 days if nobody does).
"CREATE TABLE IF NOT EXISTS agent_relay (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  direction ENUM('to_laptop','to_phone') NOT NULL,
  tag CHAR(11) CHARACTER SET ascii COLLATE ascii_bin NULL,
  lasting TINYINT(1) NOT NULL DEFAULT 0,
  envelope MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (direction, id), INDEX relay_tag_idx (direction, tag, id), INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($T as $s) db()->exec($s);
    // Several browsers share one mailbox: a v2 envelope starts "v2.<11-char tag>." and the tag says which browser a reply is for.
    // Compared exactly (ascii_bin) because the tags are case-sensitive. NULL = the older v1 messages, which have no tag.
    if (!col_exists('agent_relay', 'tag')) {
        db()->exec('ALTER TABLE agent_relay ADD tag CHAR(11) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER direction, ADD INDEX relay_tag_idx (direction, tag, id)');
        $log[] = 'Agent: agent_relay.tag added.';
    }
    // A removal notice ("this browser was removed") must still be there when a browser that was offline for weeks reconnects, so the
    // laptop marks it and the clean-up keeps it 90 days instead of 7. The server cannot read it; the mark is the laptop's word.
    if (!col_exists('agent_relay', 'lasting')) {
        db()->exec('ALTER TABLE agent_relay ADD lasting TINYINT(1) NOT NULL DEFAULT 0 AFTER tag');
        $log[] = 'Agent: agent_relay.lasting added.';
    }
    if (!col_exists('agent_tokens', 'scope')) { db()->exec("ALTER TABLE agent_tokens ADD scope ENUM('research','personal') NOT NULL DEFAULT 'research' AFTER label"); $log[] = 'Agent: agent_tokens.scope added.'; }
    if (!col_exists('research_requests', 'subject_name')) { db()->exec('ALTER TABLE research_requests ADD subject_name VARCHAR(160) NULL AFTER task_id'); $log[] = 'Agent: research_requests.subject_name added.'; }
    $log[] = 'Agent platform tables ready.';
    return $log;
}
