CREATE TABLE IF NOT EXISTS admin_security (
 admin_id BIGINT UNSIGNED PRIMARY KEY,
 version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 mfa_secret TEXT NULL,
 last_step BIGINT NOT NULL DEFAULT -1,
 recovery_codes TEXT NOT NULL,
 reset_hash CHAR(64) NULL,
 reset_expires BIGINT NULL,
 FOREIGN KEY (admin_id) REFERENCES admin_users(id)
) ENGINE=InnoDB;
