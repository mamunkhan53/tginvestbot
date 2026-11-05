CREATE TABLE IF NOT EXISTS performance_results (
 investment_id CHAR(32) PRIMARY KEY,
 payout DECIMAL(36,18) NOT NULL,
 evidence VARCHAR(500) NOT NULL,
 approved_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (investment_id) REFERENCES investments(id),
 FOREIGN KEY (approved_by) REFERENCES admin_users(id),
 CHECK(payout>=0)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS reserve_funding (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 tx_hash CHAR(66) NOT NULL,
 log_index INT UNSIGNED NOT NULL,
 amount DECIMAL(36,18) NOT NULL,
 journal_id BIGINT UNSIGNED NOT NULL UNIQUE,
 evidence VARCHAR(500) NOT NULL,
 UNIQUE reserve_transfer(tx_hash,log_index),
 FOREIGN KEY (journal_id) REFERENCES ledger_journals(id)
) ENGINE=InnoDB;
