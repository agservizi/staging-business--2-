-- Migration: Create posta telematica PEC cache tables
-- Date: 2026-01-24

CREATE TABLE IF NOT EXISTS posta_telematica_pec_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uid BIGINT UNSIGNED NOT NULL,
    mailbox VARCHAR(190) NOT NULL DEFAULT 'INBOX',
    message_id_header VARCHAR(255) NULL,
    sender VARCHAR(255) NULL,
    subject VARCHAR(255) NULL,
    received_at DATETIME NULL,
    seen TINYINT(1) NOT NULL DEFAULT 0,
    snippet TEXT NULL,
    body MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_posta_telematica_pec_uid (uid, mailbox),
    INDEX idx_posta_telematica_pec_seen (seen),
    INDEX idx_posta_telematica_pec_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posta_telematica_pec_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_posta_telematica_pec_attachment_message (message_id),
    CONSTRAINT fk_posta_telematica_pec_attachment_message FOREIGN KEY (message_id) REFERENCES posta_telematica_pec_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
