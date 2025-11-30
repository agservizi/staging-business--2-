CREATE TABLE IF NOT EXISTS energia_email_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contratto_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(20) NOT NULL,
    send_channel VARCHAR(20) NOT NULL DEFAULT 'manual',
    recipient VARCHAR(160) NOT NULL,
    subject VARCHAR(180) NOT NULL,
    status VARCHAR(20) NOT NULL,
    error_message VARCHAR(255) NULL,
    sent_by INT UNSIGNED NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contratto_id) REFERENCES energia_contratti(id) ON DELETE CASCADE,
    FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_email_history_contratto (contratto_id),
    INDEX idx_email_history_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
