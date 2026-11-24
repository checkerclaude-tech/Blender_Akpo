<?php
declare(strict_types=1);
// SEO autopilot feed (api/lib/seo.php). Safe to run again.
function migrate_seo(): array {
    $log = [];
    db()->exec("CREATE TABLE IF NOT EXISTS seo_entries (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      kind ENUM('ranking','change','approval','report') NOT NULL,
      title VARCHAR(200) NOT NULL,
      body MEDIUMTEXT NULL,
      url VARCHAR(500) NULL,
      data MEDIUMTEXT NULL,
      status ENUM('info','pending','approved','rejected','done') NOT NULL DEFAULT 'info',
      ext_id VARCHAR(80) NULL,
      token_id INT UNSIGNED NULL,
      decided_by INT UNSIGNED NULL,
      decided_at DATETIME NULL,
      decision_comment VARCHAR(1000) NULL,
      decision_seq INT UNSIGNED NULL,
      done_note VARCHAR(500) NULL,
      done_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY seo_ext (ext_id),
      KEY seo_kind (kind, id),
      KEY seo_status (status),
      KEY seo_dseq (decision_seq)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS seo_settings (k VARCHAR(40) PRIMARY KEY, v TEXT NOT NULL, updated_by INT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $col = one("SELECT COLUMN_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_tokens' AND COLUMN_NAME = 'scope'");
    if ($col && !str_contains((string)$col['t'], "'seo'")) {
        db()->exec("ALTER TABLE agent_tokens MODIFY scope ENUM('research','personal','seo') NOT NULL DEFAULT 'research'");
        $log[] = "SEO: agent_tokens.scope now allows 'seo'.";
    }
    $log[] = 'SEO autopilot feed ready.';
    return $log;
}
