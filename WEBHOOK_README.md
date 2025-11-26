# Configurazione Webhook Coresuite Express → Business

## Panoramica
Quando viene venduta una SIM in Coresuite Express, il sistema invia automaticamente una richiesta webhook a Coresuite Business per registrare l'entrata corrispondente.

## Endpoint Webhook
**URL:** `https://business.coresuite.it/api/express_webhook.php`

## Sicurezza
Il webhook è protetto da un secret token configurato in entrambe le applicazioni:
- **Header:** `X-Coresuite-Webhook-Secret: webhook_secret_coresuite_business`
- **Alternativa:** Parametro GET `secret=webhook_secret_coresuite_business`

## Payload JSON
```json
{
  "customer_email": "cliente@example.com",
  "customer_name": "Mario Rossi",
  "customer_phone": "+391234567890",
  "iccid": "8901234567890123456",
  "product": "SIM Vodafone 50GB",
  "amount": 15.00,
  "sale_date": "2025-11-26T10:00:00Z",
  "payment_method": "Contanti",
  "notes": "Note aggiuntive"
}
```

## Campi Obbligatori
- `customer_email`: Email del cliente
- `iccid`: ICCID della SIM venduta
- `product`: Descrizione del prodotto
- `amount`: Importo della vendita
- `sale_date`: Data e ora della vendita (formato ISO 8601)

## Campi Opzionali
- `customer_name`: Nome completo del cliente
- `customer_phone`: Numero di telefono
- `payment_method`: Metodo di pagamento
- `notes`: Note aggiuntive

## Comportamento
1. **Ricerca Cliente:** Cerca il cliente per email in Business
2. **Creazione Cliente:** Se non trovato, crea un nuovo cliente con i dati forniti
3. **Registrazione Entrata:** Inserisce una nuova entrata nella tabella `entrate_uscite`
4. **Risposta:** Restituisce conferma con ID dell'entrata creata

## Configurazione in Express
Configurare il webhook nelle impostazioni di Coresuite Express:
- **URL Webhook:** `https://business.coresuite.it/api/express_webhook.php`
- **Secret:** `webhook_secret_coresuite_business`
- **Eventi:** `sim_sale_completed`

## Risposta di Successo
```json
{
  "success": true,
  "id": 163,
  "message": "Entrata per vendita SIM da Express registrata con successo."
}
```

## Gestione Errori
Il webhook gestisce automaticamente:
- Clienti non esistenti (li crea)
- Errori di validazione (restituisce 422)
- Errori interni (restituisce 500)
- Webhook secret non valido (restituisce 401)