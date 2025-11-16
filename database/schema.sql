-- Schema Coresuite Business
-- Generato il 2025-10-19

-- Cleanup tabelle legacy pagoPA rimosse dal progetto
DROP TABLE IF EXISTS pagopa_avvisi;
DROP TABLE IF EXISTS pagopa_avvisi_eventi;
DROP TABLE IF EXISTS pagopa_bollettini;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(160) NOT NULL UNIQUE,
    nome VARCHAR(80) NOT NULL DEFAULT '',
    cognome VARCHAR(80) NOT NULL DEFAULT '',
    password VARCHAR(255) NOT NULL,
    mfa_secret VARCHAR(128) NULL,
    mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
    mfa_recovery_codes TEXT NULL,
    mfa_enabled_at DATETIME NULL,
    ruolo ENUM('Admin','Manager','Operatore','Cliente') NOT NULL DEFAULT 'Operatore',
    theme_preference ENUM('dark','light') NOT NULL DEFAULT 'dark',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    selector CHAR(18) NOT NULL UNIQUE,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    INDEX idx_remember_tokens_user_id (user_id),
    INDEX idx_remember_tokens_expires_at (expires_at),
    CONSTRAINT fk_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configurazioni (
    chiave VARCHAR(120) PRIMARY KEY,
    valore TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS log_attivita (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    modulo VARCHAR(120) NOT NULL,
    azione VARCHAR(160) NOT NULL,
    dettagli TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_modulo (modulo),
    INDEX idx_log_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clienti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ragione_sociale VARCHAR(160) NOT NULL DEFAULT '',
    nome VARCHAR(80) NOT NULL,
    cognome VARCHAR(80) NOT NULL,
    cf_piva VARCHAR(32) NULL,
    email VARCHAR(160) NULL,
    telefono VARCHAR(40) NULL,
    indirizzo VARCHAR(255) NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_clienti_ragione (ragione_sociale)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entrate_uscite (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NULL,
    tipo_movimento ENUM('Entrata','Uscita') NOT NULL DEFAULT 'Entrata',
    descrizione VARCHAR(180) NOT NULL,
    riferimento VARCHAR(80) NULL,
    metodo VARCHAR(60) NOT NULL DEFAULT 'Bonifico',
    stato VARCHAR(40) NOT NULL DEFAULT 'In lavorazione',
    importo DECIMAL(10,2) NOT NULL DEFAULT 0,
    quantita INT UNSIGNED NOT NULL DEFAULT 1,
    prezzo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    data_scadenza DATE NULL,
    data_pagamento DATE NULL,
    note TEXT NULL,
    allegato_path VARCHAR(255) NULL,
    allegato_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_entrate_uscite_cliente (cliente_id),
    INDEX idx_entrate_uscite_stato (stato),
    INDEX idx_entrate_uscite_scadenza (data_scadenza),
    INDEX idx_entrate_uscite_pagamento (data_pagamento),
    INDEX idx_entrate_uscite_cliente_stato (cliente_id, stato),
    INDEX idx_entrate_uscite_tipo (tipo_movimento),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_appuntamenti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    titolo VARCHAR(160) NOT NULL,
    tipo_servizio VARCHAR(80) NOT NULL,
    responsabile VARCHAR(120) NULL,
    luogo VARCHAR(160) NULL,
    stato VARCHAR(40) NOT NULL DEFAULT 'Programmato',
    data_inizio DATETIME NOT NULL,
    data_fine DATETIME NULL,
    reminder_sent_at DATETIME NULL,
    google_event_id VARCHAR(128) NULL,
    google_event_synced_at DATETIME NULL,
    google_event_sync_error TEXT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_appuntamenti_cliente (cliente_id),
    INDEX idx_appuntamenti_stato (stato),
    INDEX idx_appuntamenti_responsabile (responsabile),
    INDEX idx_appuntamenti_inizio (data_inizio),
    INDEX idx_appuntamenti_reminder_sent (reminder_sent_at),
    INDEX idx_appuntamenti_google_event (google_event_id),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fedelta_movimenti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    tipo_movimento VARCHAR(120) NOT NULL,
    descrizione VARCHAR(255) NOT NULL,
    punti INT NOT NULL DEFAULT 0,
    saldo_post_movimento INT NOT NULL DEFAULT 0,
    ricompensa VARCHAR(160) NULL,
    operatore VARCHAR(120) NULL,
    note TEXT NULL,
    data_movimento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fedelta_cliente (cliente_id),
    INDEX idx_fedelta_cliente_data (cliente_id, data_movimento),
    INDEX idx_fedelta_tipo (tipo_movimento),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_financial_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_date DATE NOT NULL UNIQUE,
    total_entrate DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_uscite DECIMAL(12,2) NOT NULL DEFAULT 0,
    saldo DECIMAL(12,2) NOT NULL DEFAULT 0,
    file_path VARCHAR(255) NOT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_daily_reports_date (report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_digitali (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    tipo VARCHAR(60) NOT NULL,
    stato VARCHAR(40) NOT NULL,
    note TEXT NULL,
    documento_path VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_digitali_cliente (cliente_id),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS spedizioni (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    tipo_spedizione VARCHAR(80) NOT NULL,
    mittente VARCHAR(160) NOT NULL,
    destinatario VARCHAR(160) NOT NULL,
    tracking_number VARCHAR(120) NULL,
    stato VARCHAR(40) NOT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_spedizioni_cliente (cliente_id),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    titolo VARCHAR(180) NOT NULL,
    descrizione TEXT NULL,
    cliente_id INT UNSIGNED NULL,
    modulo VARCHAR(120) NOT NULL DEFAULT 'Altro',
    stato VARCHAR(40) NOT NULL DEFAULT 'Bozza',
    owner_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_documents_cliente (cliente_id),
    INDEX idx_documents_modulo (modulo),
    INDEX idx_documents_stato (stato),
    INDEX idx_documents_updated_at (updated_at),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    versione INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_document_versions_document (document_id),
    INDEX idx_document_versions_uploaded_by (uploaded_by),
    UNIQUE KEY uniq_document_version (document_id, versione),
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_tag_map (
    document_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (document_id, tag_id),
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES document_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NULL,
    titolo VARCHAR(180) NOT NULL,
    descrizione TEXT NOT NULL,
    stato VARCHAR(40) NOT NULL DEFAULT 'Aperto',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ticket_cliente (cliente_id),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_messaggi (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    utente_id INT UNSIGNED NOT NULL,
    messaggio TEXT NOT NULL,
    allegato_path VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket_messaggi_ticket (ticket_id),
    FOREIGN KEY (ticket_id) REFERENCES ticket(id) ON DELETE CASCADE,
    FOREIGN KEY (utente_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_subscribers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(160) NOT NULL UNIQUE,
    first_name VARCHAR(80) NULL,
    last_name VARCHAR(80) NULL,
    tags JSON NULL,
    status ENUM('active','unsubscribed','bounced') NOT NULL DEFAULT 'active',
    source VARCHAR(60) NOT NULL DEFAULT 'manual',
    last_engagement_at DATETIME NULL,
    unsubscribed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email_subscribers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_lists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_list_subscribers (
    list_id INT UNSIGNED NOT NULL,
    subscriber_id INT UNSIGNED NOT NULL,
    status ENUM('active','unsubscribed','bounced') NOT NULL DEFAULT 'active',
    subscribed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unsubscribed_at DATETIME NULL,
    PRIMARY KEY (list_id, subscriber_id),
    FOREIGN KEY (list_id) REFERENCES email_lists(id) ON DELETE CASCADE,
    FOREIGN KEY (subscriber_id) REFERENCES email_subscribers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    preheader VARCHAR(200) NULL,
    html MEDIUMTEXT NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email_templates_created_by (created_by),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    from_name VARCHAR(160) NOT NULL,
    from_email VARCHAR(160) NOT NULL,
    reply_to VARCHAR(160) NULL,
    template_id INT UNSIGNED NULL,
    content_html MEDIUMTEXT NULL,
    content_plain MEDIUMTEXT NULL,
    audience_type ENUM('all_clients','list','manual') NOT NULL DEFAULT 'all_clients',
    audience_filters JSON NULL,
    status ENUM('draft','scheduled','sending','sent','cancelled','failed') NOT NULL DEFAULT 'draft',
    scheduled_at DATETIME NULL,
    sent_at DATETIME NULL,
    metrics_summary JSON NULL,
    last_error TEXT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email_campaigns_status (status),
    INDEX idx_email_campaigns_scheduled (scheduled_at),
    FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_campaign_recipients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    subscriber_id INT UNSIGNED NULL,
    email VARCHAR(160) NOT NULL,
    first_name VARCHAR(80) NULL,
    last_name VARCHAR(80) NULL,
    status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    sent_at DATETIME NULL,
    last_error TEXT NULL,
    opens SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_open_at DATETIME NULL,
    clicks SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_click_at DATETIME NULL,
    unsubscribe_token CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_email_campaign_recipient (campaign_id, email),
    INDEX idx_email_campaign_recipient_status (campaign_id, status),
    FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (subscriber_id) REFERENCES email_subscribers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_campaign_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    recipient_id INT UNSIGNED NOT NULL,
    event_type ENUM('open','click','bounce','complaint','unsubscribe') NOT NULL,
    meta JSON NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_campaign_events_type (campaign_id, event_type),
    FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES email_campaign_recipients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    tracking_code VARCHAR(32) NULL UNIQUE,
    tracking_steps JSON NULL,
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

CREATE TABLE IF NOT EXISTS consulenze_fiscali (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(40) NOT NULL UNIQUE,
    cliente_id INT UNSIGNED NULL,
    intestatario_nome VARCHAR(180) NOT NULL,
    codice_fiscale VARCHAR(20) NOT NULL,
    tipo_modello VARCHAR(10) NOT NULL,
    anno_riferimento SMALLINT UNSIGNED NOT NULL,
    periodo_riferimento VARCHAR(60) NULL,
    importo_totale DECIMAL(12,2) NOT NULL,
    numero_rate TINYINT UNSIGNED NOT NULL DEFAULT 1,
    frequenza_rate VARCHAR(20) NOT NULL DEFAULT 'unica',
    prima_scadenza DATE NOT NULL,
    stato VARCHAR(40) NOT NULL DEFAULT 'bozza',
    promemoria_scadenza DATE NULL,
    promemoria_inviato_at DATETIME NULL,
    note TEXT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_consulenze_cliente (cliente_id),
    INDEX idx_consulenze_stato (stato),
    INDEX idx_consulenze_scadenza (prima_scadenza),
    INDEX idx_consulenze_promemoria (promemoria_scadenza),
    CONSTRAINT fk_consulenze_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL,
    CONSTRAINT fk_consulenze_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_consulenze_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consulenze_fiscali_rate (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consulenza_id INT UNSIGNED NOT NULL,
    numero TINYINT UNSIGNED NOT NULL,
    importo DECIMAL(12,2) NOT NULL,
    scadenza DATE NOT NULL,
    stato VARCHAR(20) NOT NULL DEFAULT 'pending',
    pagato_il DATE NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_consulenze_rate (consulenza_id, numero),
    INDEX idx_consulenze_rate_scadenza (scadenza),
    INDEX idx_consulenze_rate_stato (stato),
    CONSTRAINT fk_consulenze_rate_consulenza FOREIGN KEY (consulenza_id) REFERENCES consulenze_fiscali(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consulenze_fiscali_documenti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consulenza_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    signed TINYINT(1) NOT NULL DEFAULT 1,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_consulenze_documenti_consulenza (consulenza_id),
    CONSTRAINT fk_consulenze_documenti_consulenza FOREIGN KEY (consulenza_id) REFERENCES consulenze_fiscali(id) ON DELETE CASCADE,
    CONSTRAINT fk_consulenze_documenti_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Utente amministratore di default
INSERT INTO users (username, email, password, ruolo)
SELECT 'admin', 'admin@example.com', '$2y$12$2xHnRJMh1zsmC1WmvMRGcuE9zraFMvx6bMpiKFFitvolG/GpNZgb2', 'Admin'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');

-- Configurazioni di base
INSERT INTO configurazioni (chiave, valore) VALUES
    ('ragione_sociale', 'Coresuite Business SRL'),
    ('indirizzo', 'Via Plinio 72, Milano'),
    ('telefono', '+39 02 1234567'),
    ('email', 'info@coresuitebusiness.com'),
    ('ui_theme', 'navy')
ON DUPLICATE KEY UPDATE valore = VALUES(valore);
