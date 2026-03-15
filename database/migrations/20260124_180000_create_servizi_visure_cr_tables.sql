-- Create Visure CR tables
CREATE TABLE IF NOT EXISTS servizi_visure_cr_pratiche (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo_visura VARCHAR(30) NOT NULL,
    stato VARCHAR(30) NOT NULL DEFAULT 'Inviata',
    nome VARCHAR(150) DEFAULT NULL,
    cognome VARCHAR(150) DEFAULT NULL,
    codice_fiscale VARCHAR(32) DEFAULT NULL,
    data_nascita DATE DEFAULT NULL,
    luogo_nascita VARCHAR(150) DEFAULT NULL,
    provincia_nascita VARCHAR(10) DEFAULT NULL,
    residenza TEXT DEFAULT NULL,
    email VARCHAR(190) DEFAULT NULL,
    telefono VARCHAR(30) DEFAULT NULL,
    ragione_sociale VARCHAR(190) DEFAULT NULL,
    partita_iva VARCHAR(32) DEFAULT NULL,
    codice_fiscale_giuridico VARCHAR(32) DEFAULT NULL,
    forma_giuridica VARCHAR(80) DEFAULT NULL,
    sede_legale TEXT DEFAULT NULL,
    email_aziendale VARCHAR(190) DEFAULT NULL,
    telefono_aziendale VARCHAR(30) DEFAULT NULL,
    richiedente_stesso TINYINT(1) NOT NULL DEFAULT 1,
    richiedente_nome VARCHAR(150) DEFAULT NULL,
    richiedente_cognome VARCHAR(150) DEFAULT NULL,
    richiedente_codice_fiscale VARCHAR(32) DEFAULT NULL,
    richiedente_qualifica VARCHAR(120) DEFAULT NULL,
    consenso_privacy TINYINT(1) NOT NULL DEFAULT 0,
    consenso_richiesta TINYINT(1) NOT NULL DEFAULT 0,
    consenso_veridicita TINYINT(1) NOT NULL DEFAULT 0,
    note TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_visure_cr_stato (stato),
    INDEX idx_visure_cr_tipo (tipo_visura),
    INDEX idx_visure_cr_created (created_at)
);

CREATE TABLE IF NOT EXISTS servizi_visure_cr_allegati (
    id INT AUTO_INCREMENT PRIMARY KEY,
    richiesta_id INT NOT NULL,
    categoria VARCHAR(50) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT NOT NULL DEFAULT 0,
    mime_type VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_visure_cr_allegati_richiesta (richiesta_id),
    CONSTRAINT fk_visure_cr_allegati_richiesta
        FOREIGN KEY (richiesta_id) REFERENCES servizi_visure_cr_pratiche(id)
        ON DELETE CASCADE
);
