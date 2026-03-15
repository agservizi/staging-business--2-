ALTER TABLE posta_telematica_messages
    ADD COLUMN pec_receipt_invio_message_id BIGINT UNSIGNED NULL AFTER pec_receipt_consegna_body,
    ADD COLUMN pec_receipt_accettazione_message_id BIGINT UNSIGNED NULL AFTER pec_receipt_invio_message_id,
    ADD COLUMN pec_receipt_consegna_message_id BIGINT UNSIGNED NULL AFTER pec_receipt_accettazione_message_id;
