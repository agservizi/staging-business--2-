CREATE TABLE IF NOT EXISTS office_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    titolo VARCHAR(180) NOT NULL,
    slug VARCHAR(200) NOT NULL UNIQUE,
    categoria VARCHAR(80) NOT NULL DEFAULT 'Generico',
    stato ENUM('draft','review','published','archived') NOT NULL DEFAULT 'draft',
    owner_id INT UNSIGNED NULL,
    cliente_id INT UNSIGNED NULL,
    tags JSON NULL,
    notes TEXT NULL,
    current_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_office_documents_owner (owner_id),
    INDEX idx_office_documents_cliente (cliente_id),
    INDEX idx_office_documents_stato (stato),
    INDEX idx_office_documents_updated_at (updated_at),
    CONSTRAINT fk_office_documents_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_office_documents_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS office_document_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    versione INT UNSIGNED NOT NULL,
    titolo_snapshot VARCHAR(180) NOT NULL,
    contenuto LONGTEXT NOT NULL,
    metadata JSON NULL,
    commento TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office_document_revisions_document (document_id),
    INDEX idx_office_document_revisions_user (created_by),
    UNIQUE KEY uniq_office_document_revision (document_id, versione),
    CONSTRAINT fk_office_document_revisions_document FOREIGN KEY (document_id) REFERENCES office_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_office_document_revisions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS office_spreadsheets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    titolo VARCHAR(180) NOT NULL,
    slug VARCHAR(200) NOT NULL UNIQUE,
    owner_id INT UNSIGNED NULL,
    categoria VARCHAR(80) NOT NULL DEFAULT 'Standard',
    stato ENUM('draft','review','published','archived') NOT NULL DEFAULT 'draft',
    tags JSON NULL,
    current_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_office_spreadsheets_owner (owner_id),
    INDEX idx_office_spreadsheets_stato (stato),
    INDEX idx_office_spreadsheets_updated_at (updated_at),
    CONSTRAINT fk_office_spreadsheets_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS office_spreadsheet_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    spreadsheet_id BIGINT UNSIGNED NOT NULL,
    versione INT UNSIGNED NOT NULL,
    titolo_snapshot VARCHAR(180) NOT NULL,
    grid_state LONGTEXT NOT NULL,
    metadata JSON NULL,
    commento TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_office_spreadsheet_revisions_sheet (spreadsheet_id),
    INDEX idx_office_spreadsheet_revisions_user (created_by),
    UNIQUE KEY uniq_office_spreadsheet_revision (spreadsheet_id, versione),
    CONSTRAINT fk_office_spreadsheet_revisions_sheet FOREIGN KEY (spreadsheet_id) REFERENCES office_spreadsheets(id) ON DELETE CASCADE,
    CONSTRAINT fk_office_spreadsheet_revisions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
