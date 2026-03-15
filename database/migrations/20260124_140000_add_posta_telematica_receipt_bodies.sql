ALTER TABLE posta_telematica_messages
    ADD COLUMN pec_receipt_invio_body MEDIUMTEXT NULL AFTER pec_receipt_consegna_at,
    ADD COLUMN pec_receipt_accettazione_body MEDIUMTEXT NULL AFTER pec_receipt_invio_body,
    ADD COLUMN pec_receipt_consegna_body MEDIUMTEXT NULL AFTER pec_receipt_accettazione_body;
