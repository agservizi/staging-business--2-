CREATE TABLE IF NOT EXISTS tipologie_pratiche (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(160) NOT NULL,
    categoria ENUM('CAF','Patronato') NOT NULL,
    campi_personalizzati JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tipologie_pratiche_nome_categoria (nome, categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS utenti_caf_patronato (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    nome VARCHAR(80) NOT NULL,
    cognome VARCHAR(80) NOT NULL,
    email VARCHAR(160) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    ruolo ENUM('CAF','Patronato') NOT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_utenti_caf_patronato_email (email),
    UNIQUE KEY uniq_utenti_caf_patronato_user (user_id),
    CONSTRAINT fk_utenti_caf_patronato_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pratiche_stati (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(60) NOT NULL UNIQUE,
    nome VARCHAR(160) NOT NULL,
    colore VARCHAR(32) NOT NULL DEFAULT 'slate',
    ordering SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pratiche (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    titolo VARCHAR(200) NOT NULL,
    descrizione TEXT NULL,
    tipo_pratica INT UNSIGNED NOT NULL,
    categoria ENUM('CAF','Patronato') NOT NULL,
    stato VARCHAR(60) NOT NULL,
    data_creazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_aggiornamento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    id_admin INT UNSIGNED NOT NULL,
    id_utente_caf_patronato INT UNSIGNED NULL,
    allegati JSON NULL,
    note TEXT NULL,
    metadati JSON NULL,
    scadenza DATE NULL,
    cliente_id INT UNSIGNED NULL,
    CONSTRAINT fk_pratiche_tipologie FOREIGN KEY (tipo_pratica) REFERENCES tipologie_pratiche(id) ON DELETE CASCADE,
    CONSTRAINT fk_pratiche_admin FOREIGN KEY (id_admin) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pratiche_operatore FOREIGN KEY (id_utente_caf_patronato) REFERENCES utenti_caf_patronato(id) ON DELETE SET NULL,
    CONSTRAINT fk_pratiche_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL,
    INDEX idx_pratiche_categoria (categoria),
    INDEX idx_pratiche_stato (stato),
    INDEX idx_pratiche_tipo (tipo_pratica),
    INDEX idx_pratiche_utente (id_utente_caf_patronato),
    INDEX idx_pratiche_scadenza (scadenza)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pratiche_documenti (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pratica_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by INT UNSIGNED NULL,
    uploaded_operatore_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pratiche_documenti_pratica FOREIGN KEY (pratica_id) REFERENCES pratiche(id) ON DELETE CASCADE,
    CONSTRAINT fk_pratiche_documenti_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pratiche_documenti_operatore FOREIGN KEY (uploaded_operatore_id) REFERENCES utenti_caf_patronato(id) ON DELETE SET NULL,
    INDEX idx_pratiche_documenti_pratica (pratica_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pratiche_note (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pratica_id BIGINT UNSIGNED NOT NULL,
    autore_user_id INT UNSIGNED NULL,
    autore_operatore_id INT UNSIGNED NULL,
    contenuto TEXT NOT NULL,
    visibile_admin TINYINT(1) NOT NULL DEFAULT 1,
    visibile_operatore TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pratiche_note_pratica FOREIGN KEY (pratica_id) REFERENCES pratiche(id) ON DELETE CASCADE,
    CONSTRAINT fk_pratiche_note_user FOREIGN KEY (autore_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pratiche_note_operatore FOREIGN KEY (autore_operatore_id) REFERENCES utenti_caf_patronato(id) ON DELETE SET NULL,
    INDEX idx_pratiche_note_pratica (pratica_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pratiche_eventi (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pratica_id BIGINT UNSIGNED NOT NULL,
    evento VARCHAR(120) NOT NULL,
    messaggio TEXT NULL,
    payload JSON NULL,
    creato_da INT UNSIGNED NULL,
    creato_operatore_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pratiche_eventi_pratica FOREIGN KEY (pratica_id) REFERENCES pratiche(id) ON DELETE CASCADE,
    CONSTRAINT fk_pratiche_eventi_user FOREIGN KEY (creato_da) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pratiche_eventi_operatore FOREIGN KEY (creato_operatore_id) REFERENCES utenti_caf_patronato(id) ON DELETE SET NULL,
    INDEX idx_pratiche_eventi_pratica (pratica_id),
    INDEX idx_pratiche_eventi_evento (evento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pratiche_notifiche (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pratica_id BIGINT UNSIGNED NOT NULL,
    destinatario_user_id INT UNSIGNED NULL,
    destinatario_operatore_id INT UNSIGNED NULL,
    tipo VARCHAR(60) NOT NULL,
    messaggio VARCHAR(255) NOT NULL,
    channel ENUM('dashboard','email','both') NOT NULL DEFAULT 'dashboard',
    stato ENUM('nuova','letta') NOT NULL DEFAULT 'nuova',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    CONSTRAINT fk_pratiche_notifiche_pratica FOREIGN KEY (pratica_id) REFERENCES pratiche(id) ON DELETE CASCADE,
    CONSTRAINT fk_pratiche_notifiche_user FOREIGN KEY (destinatario_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pratiche_notifiche_operatore FOREIGN KEY (destinatario_operatore_id) REFERENCES utenti_caf_patronato(id) ON DELETE CASCADE,
    INDEX idx_pratiche_notifiche_destinatario_user (destinatario_user_id),
    INDEX idx_pratiche_notifiche_destinatario_operatore (destinatario_operatore_id),
    INDEX idx_pratiche_notifiche_stato (stato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
