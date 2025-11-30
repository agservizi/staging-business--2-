ALTER TABLE brt_shipments
    ADD COLUMN manifest_id INT UNSIGNED NULL AFTER last_tracking_at,
    ADD COLUMN manifest_generated_at DATETIME NULL AFTER manifest_id,
    ADD KEY idx_brt_shipments_manifest (manifest_id);

CREATE TABLE IF NOT EXISTS brt_manifests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(30) NOT NULL,
    generated_at DATETIME NOT NULL,
    shipments_count INT UNSIGNED NOT NULL,
    total_parcels INT UNSIGNED NOT NULL,
    total_weight_kg DECIMAL(12,3) NOT NULL,
    total_volume_m3 DECIMAL(12,3) NOT NULL DEFAULT 0,
    pdf_path VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_brt_manifest_reference (reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE brt_shipments
    ADD CONSTRAINT fk_brt_shipments_manifest FOREIGN KEY (manifest_id)
    REFERENCES brt_manifests(id) ON DELETE SET NULL ON UPDATE CASCADE;
