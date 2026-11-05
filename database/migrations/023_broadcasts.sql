CREATE TABLE IF NOT EXISTS telegram_admin_sessions (
 telegram_id BIGINT UNSIGNED PRIMARY KEY,
 nonce CHAR(32) NOT NULL,
 intent JSON NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS telegram_broadcasts (
 id CHAR(32) PRIMARY KEY,
 admin_telegram_id BIGINT UNSIGNED NOT NULL,
 audience VARCHAR(255) NOT NULL,
 message TEXT NOT NULL,
 recipient_count INT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS telegram_broadcast_recipients (
 broadcast_id CHAR(32) NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 notification_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(broadcast_id,user_id),
 FOREIGN KEY(broadcast_id) REFERENCES telegram_broadcasts(id),
 FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(notification_id) REFERENCES financial_notifications(id)
) ENGINE=InnoDB;
