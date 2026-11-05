CREATE TABLE IF NOT EXISTS investment_packages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(64) NOT NULL UNIQUE,
 description TEXT NOT NULL,
 minimum DECIMAL(36,18) NOT NULL,
 maximum DECIMAL(36,18) NOT NULL,
 duration_hours INT UNSIGNED NOT NULL,
 rate DECIMAL(9,4) NOT NULL DEFAULT 0,
 model ENUM('fixed','performance') NOT NULL DEFAULT 'fixed',
 active BOOLEAN NOT NULL DEFAULT FALSE,
 terms_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 CHECK (minimum>0 AND maximum>=minimum AND duration_hours>0 AND rate>=0)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS user_compliance (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 kyc_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
 risk_status ENUM('pending','clear','blocked') NOT NULL DEFAULT 'pending',
 country_code CHAR(2) NULL,
 review_reason VARCHAR(500) NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (user_id) REFERENCES users(id),
 FOREIGN KEY (reviewed_by) REFERENCES admin_users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS user_settings (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 language ENUM('en','ru','bn') NOT NULL DEFAULT 'en',
 auto_withdraw BOOLEAN NOT NULL DEFAULT FALSE,
 auto_reinvest BOOLEAN NOT NULL DEFAULT FALSE,
 terms_accepted BOOLEAN NOT NULL DEFAULT FALSE,
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS withdrawal_addresses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 address CHAR(42) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 eligible_at DATETIME(6) NOT NULL,
 verified BOOLEAN NOT NULL DEFAULT FALSE,
 FOREIGN KEY (user_id) REFERENCES users(id),
 INDEX user_addresses(user_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS deposit_addresses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 address CHAR(42) NOT NULL UNIQUE,
 custody_reference VARCHAR(191) NOT NULL UNIQUE,
 user_id BIGINT UNSIGNED NULL UNIQUE,
 assigned_at DATETIME(6) NULL,
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS investments (
 id CHAR(32) PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 package_id BIGINT UNSIGNED NOT NULL,
 principal DECIMAL(36,18) NOT NULL,
 terms JSON NOT NULL,
 status ENUM('active','matured','completed','cancelled','refunded','suspended') NOT NULL DEFAULT 'active',
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 matures_at DATETIME(6) NOT NULL,
 settled_at DATETIME(6) NULL,
 settlement_amount DECIMAL(36,18) NULL,
 journal_id BIGINT UNSIGNED NOT NULL UNIQUE,
 settlement_journal_id BIGINT UNSIGNED NULL UNIQUE,
 FOREIGN KEY (user_id) REFERENCES users(id),
 FOREIGN KEY (package_id) REFERENCES investment_packages(id),
 FOREIGN KEY (journal_id) REFERENCES ledger_journals(id),
 FOREIGN KEY (settlement_journal_id) REFERENCES ledger_journals(id),
 INDEX maturity_queue(status,matures_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS referral_commissions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 investment_id CHAR(32) NOT NULL,
 beneficiary_id BIGINT UNSIGNED NOT NULL,
 level TINYINT UNSIGNED NOT NULL,
 amount DECIMAL(36,18) NOT NULL,
 journal_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE investment_level(investment_id,level),
 FOREIGN KEY (investment_id) REFERENCES investments(id),
 FOREIGN KEY (beneficiary_id) REFERENCES users(id),
 FOREIGN KEY (journal_id) REFERENCES ledger_journals(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS deposits (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 tx_hash CHAR(66) NOT NULL,
 log_index INT UNSIGNED NOT NULL,
 address CHAR(42) NOT NULL,
 amount DECIMAL(36,18) NOT NULL,
 confirmations INT UNSIGNED NOT NULL DEFAULT 0,
 block_number BIGINT UNSIGNED NOT NULL,
 status ENUM('confirming','credited','failed','rejected') NOT NULL DEFAULT 'confirming',
 journal_id BIGINT UNSIGNED NULL UNIQUE,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE chain_transfer(tx_hash,log_index),
 FOREIGN KEY (user_id) REFERENCES users(id),
 FOREIGN KEY (journal_id) REFERENCES ledger_journals(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS withdrawals (
 id CHAR(32) PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 address_id BIGINT UNSIGNED NOT NULL,
 address CHAR(42) NOT NULL,
 net_amount DECIMAL(36,18) NOT NULL,
 fee DECIMAL(36,18) NOT NULL,
 total DECIMAL(36,18) NOT NULL,
 status ENUM('pending','approved','processing','completed','rejected','failed','cancelled','uncertain') NOT NULL DEFAULT 'pending',
 journal_id BIGINT UNSIGNED NOT NULL UNIQUE,
 settlement_journal_id BIGINT UNSIGNED NULL UNIQUE,
 tx_hash CHAR(66) NULL UNIQUE,
 signed_transaction MEDIUMTEXT NULL,
 approved_by BIGINT UNSIGNED NULL,
 reason VARCHAR(500) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (user_id) REFERENCES users(id),
 FOREIGN KEY (address_id) REFERENCES withdrawal_addresses(id),
 FOREIGN KEY (journal_id) REFERENCES ledger_journals(id),
 FOREIGN KEY (settlement_journal_id) REFERENCES ledger_journals(id),
 FOREIGN KEY (approved_by) REFERENCES admin_users(id),
 INDEX processing_queue(status,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS financial_notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_key VARCHAR(191) NOT NULL UNIQUE,
 user_id BIGINT UNSIGNED NOT NULL,
 text TEXT NOT NULL,
 status ENUM('pending','sending','sent','failed','uncertain') NOT NULL DEFAULT 'pending',
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (user_id) REFERENCES users(id),
 INDEX pending_notifications(status,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS job_runs (
 name VARCHAR(100) PRIMARY KEY,
 last_started_at DATETIME(6) NULL,
 last_succeeded_at DATETIME(6) NULL,
 status ENUM('running','ok','failed') NOT NULL,
 summary VARCHAR(500) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS reconciliation_records (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 findings JSON NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;
INSERT IGNORE INTO system_settings(setting_key,setting_value) VALUES
 ('testnet_deposits_enabled','false'),('testnet_investments_enabled','false'),
 ('testnet_withdrawals_enabled','false'),('minimum_withdrawal','"1"'),
 ('withdrawal_fee_percent','"1"'),('daily_withdrawal_limit','"1000"'),
 ('withdrawal_delay_hours','24'),('referral_enabled','false'),
 ('referral_level_1','"3"'),('referral_level_2','"1"'),
 ('required_confirmations','15'),('blocked_countries','[]');
INSERT IGNORE INTO ledger_accounts(code,normal_side) VALUES
 ('platform:custody','debit'),('platform:reserve','credit'),('platform:fees','credit');
INSERT IGNORE INTO investment_packages(name,description,minimum,maximum,duration_hours,rate,active) VALUES
 ('START','Configure legally reviewed terms before activation.',10,10000,168,0,0),
 ('PRO','Configure legally reviewed terms before activation.',10,10000,168,0,0),
 ('ELITE','Configure legally reviewed terms before activation.',10,10000,168,0,0),
 ('PREMIUM','Configure legally reviewed terms before activation.',10,10000,168,0,0),
 ('LEGEND','Configure legally reviewed terms before activation.',10,10000,168,0,0);
