CREATE TABLE IF NOT EXISTS telegram_updates (
 update_id BIGINT UNSIGNED PRIMARY KEY,
 payload JSON NOT NULL,
 status ENUM('pending','processed','failed') NOT NULL DEFAULT 'pending',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 processed_at DATETIME(6) NULL,
 INDEX pending_updates (status,update_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS telegram_outbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 update_id BIGINT UNSIGNED NOT NULL,
 message_kind VARCHAR(32) NOT NULL,
 method VARCHAR(32) NOT NULL,
 payload JSON NOT NULL,
 status ENUM('pending','sending','sent','failed','uncertain') NOT NULL DEFAULT 'pending',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 response_code INT NULL,
 available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE update_message (update_id,message_kind),
 FOREIGN KEY (update_id) REFERENCES telegram_updates(update_id),
 INDEX pending_delivery (status,available_at)
) ENGINE=InnoDB;
