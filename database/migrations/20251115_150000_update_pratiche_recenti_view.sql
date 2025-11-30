DROP VIEW IF EXISTS pratiche_recenti;

CREATE VIEW pratiche_recenti AS
SELECT
    p.id,
    p.titolo,
    p.descrizione,
    p.categoria,
    p.stato,
    p.data_creazione,
    p.data_aggiornamento,
    p.id_admin,
    p.id_utente_caf_patronato,
    p.cliente_id,
    p.tipo_pratica,
    p.scadenza,
    p.tracking_code,
    p.tracking_code AS tracking_pratica,
    p.tracking_steps,
    p.allegati,
    p.note,
    p.metadati
FROM pratiche p;
