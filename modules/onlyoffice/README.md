# Modulo ONLYOFFICE per Coresuite

Questo modulo integra ONLYOFFICE Document Server (Community Edition) nel gestionale Coresuite utilizzando esclusivamente PHP e JavaScript vanilla.

## Struttura cartelle

```
modules/onlyoffice/
├── assets/
│   ├── css/editor.css
│   └── js/editor.js
├── callback.php
├── config.php
├── editor.php
├── filemanager.php
└── index.php
```

## Flusso principale

1. `index.php` elenca i documenti disponibili, consente la creazione basata su template e il caricamento di file esistenti nella cartella cifrata.
2. `editor.php` genera il JSON di configurazione OnlyOffice (document, editorConfig, permissions) e inizializza l'iframe tramite `DocsAPI.DocEditor`.
3. `filemanager.php` espone API REST semplici (`list`, `create`, `upload`, `download`, `info`) utilizzate sia dalla UI sia da ONLYOFFICE per scaricare i file.
4. `callback.php` riceve gli aggiornamenti dal Document Server e richiama `saveCallback()` per salvare cifrando nuovamente il file.
5. `config.php` centralizza configurazione, permessi, gestione JWT, cifratura e template automatici DOCX/XLSX/PPTX.

## Debug e deploy

### Test locale

1. Installare PHP ≥ 8.1 con estensioni `openssl`, `zip`, `json` abilitate.
2. Configurare un virtual host che punti alla root del progetto Coresuite (HTTPS consigliato per evitare problemi mixed-content).
3. Impostare `DOCUMENT_SERVER_URL` in `config.php` verso l'istanza ONLYOFFICE locale (es. `https://office.local`).
4. Aprire `modules/onlyoffice/index.php` autenticati nel gestionale e creare un documento di prova; verificare che si apra in `editor.php`.
5. Monitorare i log PHP (`logs/` della root Coresuite) per catturare eventuali eccezioni sollevate dalle funzioni helper.

### Configurazione ONLYOFFICE Server

1. Installare ONLYOFFICE Document Server Community Edition seguendo la guida ufficiale (Docker o pacchetti Linux).
2. Abilitare HTTPS e, se serve, configurare certificati riconosciuti dal browser usato dagli utenti Coresuite.
3. Facoltativo: impostare `JWT_ENABLED=true`, `JWT_SECRET=...` nel `local.json` di ONLYOFFICE; lo stesso segreto va riportato in `config.php` (`DOCUMENT_SERVER_SECRET`).
4. Aggiungere l'IP del gestionale Coresuite alle `allowed origin` (parametri `services.CoAuthoring.security.allowed-origin`).
5. Riavviare il servizio ONLYOFFICE dopo ogni modifica al file di configurazione.

### Pubblicazione in produzione

1. Copiare l'intera cartella `modules/onlyoffice` sull'ambiente di produzione (deploy via Git o rsync).
2. Configurare `DOCUMENT_SERVER_URL`, `DOCUMENT_SERVER_SECRET`, `ONLYOFFICE_ENCRYPTION_KEY` tramite variabili d'ambiente o modificando `config.php`.
3. Assicurarsi che la cartella cifrata `storage/onlyoffice/files` sia fuori dal webroot pubblico e che l'utente PHP abbia permessi di lettura/scrittura.
4. Abilitare HTTP Strict Transport Security (HSTS) e limitare l'accesso a `filemanager.php?action=download` tramite IP whitelisting se il Document Server risiede sulla stessa LAN.
5. Eseguire uno smoke test: creare i tre tipi di documenti, verificarne l'apertura/salvataggio e controllare i log del Document Server (`/var/log/onlyoffice/documentserver/`).

### Limiti Community Edition

- Massimo 20 connessioni simultanee per documento e 20 documenti aperti contemporaneamente.
- Manca il clustering: per alta disponibilità serve la versione Enterprise.
- Nessun supporto ufficiale da ONLYOFFICE; aggiornamenti tramite community.

## Ottimizzazioni consigliate

1. **Storage S3**: adattare `readDecryptedBinary`/`writeEncryptedBinary` per leggere/scrivere su object storage cifrato.
2. **Audit log**: salvare revisioni in `database/` per tracciare chi ha modificato cosa e quando.
3. **Permessi granulari**: collegare `requireUser()` al sistema ACL del gestionale per assegnare ruoli dinamici (admin/operator/utente).
4. **Cache busting**: integrare un asset pipeline per versionare automaticamente `editor.js`/`editor.css`.
5. **Webhooks**: notificare i team (es. via Telegram) quando viene creato o chiuso un documento importante.
