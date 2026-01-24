ALTER TABLE posta_telematica_messages
    ADD COLUMN message_id_header VARCHAR(255) NULL AFTER error_message,
    ADD COLUMN pec_receipt_invio_at DATETIME NULL AFTER message_id_header,
    ADD COLUMN pec_receipt_accettazione_at DATETIME NULL AFTER pec_receipt_invio_at,
    ADD COLUMN pec_receipt_consegna_at DATETIME NULL AFTER pec_receipt_accettazione_at;
