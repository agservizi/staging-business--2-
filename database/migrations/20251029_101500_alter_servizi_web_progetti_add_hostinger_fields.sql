ALTER TABLE servizi_web_progetti
    ADD COLUMN IF NOT EXISTS dominio_richiesto VARCHAR(191) NULL AFTER include_stampa;

ALTER TABLE servizi_web_progetti
    ADD COLUMN IF NOT EXISTS hostinger_datacenter VARCHAR(120) NULL AFTER dominio_richiesto;

ALTER TABLE servizi_web_progetti
    ADD COLUMN IF NOT EXISTS hostinger_plan VARCHAR(120) NULL AFTER hostinger_datacenter;

ALTER TABLE servizi_web_progetti
    ADD COLUMN IF NOT EXISTS hostinger_email_plan VARCHAR(120) NULL AFTER hostinger_plan;

ALTER TABLE servizi_web_progetti
    ADD COLUMN IF NOT EXISTS hostinger_domain_status VARCHAR(40) NULL AFTER hostinger_email_plan;

ALTER TABLE servizi_web_progetti
    ADD COLUMN IF NOT EXISTS hostinger_order_reference VARCHAR(120) NULL AFTER hostinger_domain_status;
