DROP TABLE IF EXISTS telefonia;

CREATE TABLE IF NOT EXISTS curriculum (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    titolo VARCHAR(150) NOT NULL,
    professional_summary TEXT NULL,
    key_competences TEXT NULL,
    digital_competences TEXT NULL,
    driving_license VARCHAR(120) NULL,
    additional_information TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Bozza',
    last_generated_at DATETIME NULL,
    generated_file VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_curriculum_cliente (cliente_id),
    INDEX idx_curriculum_status (status),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS curriculum_experiences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_id INT UNSIGNED NOT NULL,
    role_title VARCHAR(160) NOT NULL,
    employer VARCHAR(160) NOT NULL,
    city VARCHAR(120) NULL,
    country VARCHAR(120) NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    description TEXT NULL,
    ordering SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (curriculum_id) REFERENCES curriculum(id) ON DELETE CASCADE,
    INDEX idx_curriculum_experiences_curriculum (curriculum_id),
    INDEX idx_curriculum_experiences_order (curriculum_id, ordering)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS curriculum_education (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    institution VARCHAR(180) NOT NULL,
    city VARCHAR(120) NULL,
    country VARCHAR(120) NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    qualification_level VARCHAR(120) NULL,
    description TEXT NULL,
    ordering SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (curriculum_id) REFERENCES curriculum(id) ON DELETE CASCADE,
    INDEX idx_curriculum_education_curriculum (curriculum_id),
    INDEX idx_curriculum_education_order (curriculum_id, ordering)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS curriculum_languages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_id INT UNSIGNED NOT NULL,
    language VARCHAR(120) NOT NULL,
    overall_level VARCHAR(60) NOT NULL,
    listening VARCHAR(60) NULL,
    reading VARCHAR(60) NULL,
    interaction VARCHAR(60) NULL,
    production VARCHAR(60) NULL,
    writing VARCHAR(60) NULL,
    certification VARCHAR(160) NULL,
    FOREIGN KEY (curriculum_id) REFERENCES curriculum(id) ON DELETE CASCADE,
    INDEX idx_curriculum_languages_curriculum (curriculum_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS curriculum_skills (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_id INT UNSIGNED NOT NULL,
    category VARCHAR(80) NOT NULL,
    skill VARCHAR(160) NOT NULL,
    level VARCHAR(60) NULL,
    description TEXT NULL,
    ordering SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (curriculum_id) REFERENCES curriculum(id) ON DELETE CASCADE,
    INDEX idx_curriculum_skills_curriculum (curriculum_id),
    INDEX idx_curriculum_skills_order (curriculum_id, ordering)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
