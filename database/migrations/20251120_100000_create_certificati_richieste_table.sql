CREATE TABLE IF NOT EXISTS certificati_richieste (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    categoria ENUM('comunali', 'catastali', 'camerali') NOT NULL,
    tipo VARCHAR(100) NOT NULL,
    dati_richiesta JSON NOT NULL,
    request_id VARCHAR(100) NULL,
    stato ENUM('pending', 'processing', 'done', 'error') NOT NULL DEFAULT 'pending',
    errore TEXT NULL,
    documenti JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_categoria (user_id, categoria),
    INDEX idx_request_id (request_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;