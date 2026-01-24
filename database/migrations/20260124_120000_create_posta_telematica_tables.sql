-- Migration: Create posta telematica tables
-- Date: 2026-01-24

CREATE TABLE IF NOT EXISTS posta_telematica_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel ENUM('email','pec') NOT NULL DEFAULT 'email',
    recipient_email VARCHAR(190) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    body LONGTEXT NOT NULL,
    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    cliente_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_posta_telematica_channel (channel),
    INDEX idx_posta_telematica_status (status),
    INDEX idx_posta_telematica_recipient (recipient_email),
    INDEX idx_posta_telematica_cliente (cliente_id),
    INDEX idx_posta_telematica_created_by (created_by),
    CONSTRAINT fk_posta_telematica_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL,
    CONSTRAINT fk_posta_telematica_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posta_telematica_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_posta_telematica_attachment_message (message_id),
    CONSTRAINT fk_posta_telematica_attachment_message FOREIGN KEY (message_id) REFERENCES posta_telematica_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
