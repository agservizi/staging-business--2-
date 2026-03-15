ALTER TABLE users
    MODIFY ruolo ENUM('Admin','Manager','Operatore','Cliente','Collaboratore') NOT NULL DEFAULT 'Operatore';

CREATE TABLE IF NOT EXISTS opportunity_statuses (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL UNIQUE,
    label VARCHAR(160) NOT NULL,
    color VARCHAR(32) NOT NULL DEFAULT 'slate',
    is_core TINYINT(1) NOT NULL DEFAULT 0,
    ordering SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO opportunity_statuses (code, label, color, is_core, ordering)
VALUES
    ('in_verifica', 'In verifica', 'warning', 1, 10),
    ('documenti_ok', 'Documenti ok', 'info', 1, 20),
    ('in_firma_otp', 'In firma OTP', 'primary', 1, 30),
    ('annullato', 'Annullato', 'danger', 1, 40),
    ('attivato', 'Attivato', 'success', 1, 50)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    color = VALUES(color),
    is_core = VALUES(is_core),
    ordering = VALUES(ordering);

CREATE TABLE IF NOT EXISTS opportunity_providers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category ENUM('telefonia','luce','gas') NOT NULL,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    ordering SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    default_commission DECIMAL(10,2) NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_opportunity_provider (category, slug),
    INDEX idx_opportunity_providers_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opportunity_offers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    commission DECIMAL(10,2) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    ordering SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_opportunity_offer (provider_id, slug),
    INDEX idx_opportunity_offers_provider (provider_id),
    CONSTRAINT fk_opportunity_offers_provider FOREIGN KEY (provider_id) REFERENCES opportunity_providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opportunities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code CHAR(10) NOT NULL UNIQUE,
    category ENUM('telefonia','luce','gas') NOT NULL,
    status_code VARCHAR(60) NOT NULL DEFAULT 'in_verifica',
    provider_id INT UNSIGNED NOT NULL,
    offer_id INT UNSIGNED NULL,
    provider_label VARCHAR(160) NOT NULL,
    offer_label VARCHAR(160) NULL,
    collaborator_id INT UNSIGNED NOT NULL,
    managed_by INT UNSIGNED NULL,
    commission_amount DECIMAL(10,2) NULL,
    customer_first_name VARCHAR(80) NOT NULL,
    customer_last_name VARCHAR(80) NOT NULL,
    customer_tax_code VARCHAR(32) NOT NULL,
    customer_birth_date DATE NULL,
    customer_birth_place VARCHAR(120) NULL,
    customer_phone VARCHAR(40) NOT NULL,
    customer_email VARCHAR(160) NOT NULL,
    customer_address VARCHAR(255) NULL,
    customer_city VARCHAR(120) NULL,
    customer_postal_code VARCHAR(12) NULL,
    customer_province VARCHAR(8) NULL,
    document_type VARCHAR(60) NULL,
    document_number VARCHAR(60) NULL,
    document_issued_by VARCHAR(160) NULL,
    document_issued_at DATE NULL,
    document_expires_at DATE NULL,
    telefonia_current_operator VARCHAR(160) NULL,
    telefonia_line_number VARCHAR(32) NULL,
    luce_pod VARCHAR(32) NULL,
    gas_pdr VARCHAR(32) NULL,
    payment_method ENUM('iban','bollettino') NOT NULL DEFAULT 'iban',
    payment_iban VARCHAR(34) NULL,
    payment_holder_is_customer TINYINT(1) NOT NULL DEFAULT 1,
    payment_holder_first_name VARCHAR(80) NULL,
    payment_holder_last_name VARCHAR(80) NULL,
    payment_holder_tax_code VARCHAR(32) NULL,
    additional_notes TEXT NULL,
    admin_notes TEXT NULL,
    contract_code VARCHAR(64) NULL,
    client_code VARCHAR(64) NULL,
    last_status_change DATETIME NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_opportunities_category (category),
    INDEX idx_opportunities_status (status_code),
    INDEX idx_opportunities_provider (provider_id),
    INDEX idx_opportunities_collaborator (collaborator_id),
    INDEX idx_opportunities_email (customer_email),
    CONSTRAINT fk_opportunities_status FOREIGN KEY (status_code) REFERENCES opportunity_statuses(code) ON DELETE RESTRICT,
    CONSTRAINT fk_opportunities_provider FOREIGN KEY (provider_id) REFERENCES opportunity_providers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opportunities_offer FOREIGN KEY (offer_id) REFERENCES opportunity_offers(id) ON DELETE SET NULL,
    CONSTRAINT fk_opportunities_collaborator FOREIGN KEY (collaborator_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_opportunities_manager FOREIGN KEY (managed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opportunity_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    checksum CHAR(64) NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_opportunity_files_opportunity (opportunity_id),
    CONSTRAINT fk_opportunity_files_opportunity FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE,
    CONSTRAINT fk_opportunity_files_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
