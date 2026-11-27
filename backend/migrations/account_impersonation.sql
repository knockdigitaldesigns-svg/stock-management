CREATE TABLE IF NOT EXISTS user_account_switches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id_hash CHAR(64) NOT NULL,
    acting_user_id INT NOT NULL,
    represented_user_id INT NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME DEFAULT NULL,
    end_reason VARCHAR(40) DEFAULT NULL,
    INDEX idx_user_account_switch_session (session_id_hash, acting_user_id, ended_at),
    INDEX idx_user_account_switch_actor (acting_user_id, started_at),
    INDEX idx_user_account_switch_target (represented_user_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE history
    ADD COLUMN represented_by_user_id INT DEFAULT NULL AFTER changed_by_user_id,
    ADD INDEX idx_history_represented_user (represented_by_user_id);
