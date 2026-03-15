# Webhook Express Legacy

Questo documento resta solo come riferimento storico.

Dal 13/03/2026 il flusso Express esterno via webhook non e' piu' parte dell'architettura supportata del gestionale. Il modulo Express e' stato assorbito in modalita' nativa single-tenant dentro Business.

Stato attuale:
- api/express_webhook.php e' dismesso e risponde con 410.
- api/sim_sale.php legacy e' dismesso e risponde con 410.
- Le vendite Express devono essere registrate da modules/servizi/express.
- L'area cliente Express vive dentro customer-portal/ senza alcun tenant separato.

Conseguenza operativa:
- Non configurare webhook esterni verso Business per Express.
- Non usare credenziali o URL del vecchio Express remoto.
- Usare solo il modulo nativo e il portale cliente integrato.