CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 telegram_id BIGINT UNSIGNED NOT NULL UNIQUE,
 username VARCHAR(64) NULL,
 first_name VARCHAR(255) NOT NULL,
 last_name VARCHAR(255) NULL,
 referral_code VARCHAR(64) NOT NULL UNIQUE,
 referrer_id BIGINT UNSIGNED NULL,
 status ENUM('active','suspended') NOT NULL DEFAULT 'active',
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (referrer_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(254) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('super_admin','administrator','finance_manager','support_manager','analyst') NOT NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ledger_accounts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(100) NOT NULL UNIQUE,
 user_id BIGINT UNSIGNED NULL,
 normal_side ENUM('debit','credit') NOT NULL,
 prevent_negative BOOLEAN NOT NULL DEFAULT TRUE,
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ledger_journals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 idempotency_key VARCHAR(191) NOT NULL UNIQUE,
 payload_hash CHAR(64) NOT NULL,
 reference VARCHAR(191) NOT NULL,
 reason VARCHAR(500) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ledger_entries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 journal_id BIGINT UNSIGNED NOT NULL,
 account_id BIGINT UNSIGNED NOT NULL,
 side ENUM('debit','credit') NOT NULL,
 amount DECIMAL(36,18) NOT NULL,
 FOREIGN KEY (journal_id) REFERENCES ledger_journals(id),
 FOREIGN KEY (account_id) REFERENCES ledger_accounts(id),
 INDEX account_journal (account_id,journal_id),
 CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_activity_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 admin_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(100) NOT NULL,
 reason VARCHAR(500) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (admin_id) REFERENCES admin_users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
 setting_key VARCHAR(100) PRIMARY KEY,
 setting_value JSON NOT NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO system_settings VALUES
 ('real_money_deposits', 'false'),
 ('real_money_withdrawals', 'false'),
 ('real_money_investments', 'false'),
 ('chain_id', '97');
