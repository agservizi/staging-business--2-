# Customer Portal - Pickup System

Portale cliente per la gestione e segnalazione dei pacchi collegato al sistema pickup di Coresuite Business.

## Caratteristiche

- ✅ **Autenticazione sicura** con OTP via email/SMS
- ✅ **Dashboard intuitiva** con statistiche in tempo reale
- ✅ **Segnalazione pacchi** con codice tracking
- ✅ **Notifiche automatiche** per arrivo e stato pacchi
- ✅ **Integrazione completa** con sistema pickup esistente
- ✅ **API RESTful** per integrazione esterna
- ✅ **Design responsive** ottimizzato per mobile

## Struttura del Progetto

```
customer-portal/
├── api/                    # API REST per il frontend
│   ├── auth/              # Endpoint di autenticazione
│   ├── packages.php       # Gestione pacchi
│   ├── notifications.php  # Notifiche
│   └── stats.php         # Statistiche
├── assets/                # CSS, JS e immagini
│   ├── css/portal.css     # Stili personalizzati
│   └── js/portal.js       # JavaScript principale
├── includes/              # File PHP comuni
│   ├── config.php         # Configurazione
│   ├── database.php       # Gestione database
│   ├── auth.php          # Autenticazione clienti
│   ├── pickup_service.php # Servizi pickup
│   ├── header.php        # Header comune
│   ├── footer.php        # Footer comune
│   └── sidebar.php       # Menu laterale
├── login.php             # Pagina di login
├── dashboard.php         # Dashboard principale
├── report.php           # Segnalazione pacchi
├── logout.php           # Logout
├── migrate.php          # Script migrazione database
└── README.md           # Questa documentazione
```

## Installazione

### 1. Requisiti

- PHP 8.1 o superiore
- MySQL 5.7 o superiore
- Server web (Apache/Nginx)
- Estensioni PHP: PDO, MySQLi, cURL, JSON

### 2. Configurazione Database

Esegui lo script di migrazione per creare le tabelle necessarie:

```bash
cd customer-portal
php migrate.php
```

### 3. Configurazione Web Server

#### Apache (.htaccess incluso)

Il file `.htaccess` è già configurato per:
- Redirect HTTPS automatico
- Sicurezza headers
- URL rewriting
- Routing API

#### Nginx

Configurazione esempio per Nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name pickup.coresuite.it;
    root /path/to/customer-portal;
    index index.php;

    # SSL Configuration
    ssl_certificate /path/to/ssl/cert.pem;
    ssl_certificate_key /path/to/ssl/private.key;

    # Security headers
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";

    # PHP files
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # API routing
    location ^~ /api/ {
        try_files $uri $uri/ /api/index.php?$query_string;
    }

    # Static files
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Deny sensitive files
    location ~ /\. {
        deny all;
    }
    
    location ~ \.(log|sql)$ {
        deny all;
    }
}
```

### 4. Configurazione Dominio

Per configurare il dominio `pickup.coresuite.it`:

1. **DNS**: Punta il dominio al server
2. **SSL**: Configura certificato SSL/TLS
3. **Firewall**: Apri porte 80 e 443

### 5. Test dell'Installazione

1. Visita `https://pickup.coresuite.it`
2. Dovresti vedere la pagina di login
3. In modalità debug, usa le credenziali di test:
   - Email: `test@example.com`
   - Il codice OTP verrà mostrato nei log

## Configurazione

### Variabili Ambiente (.env)

Il portale utilizza le stesse configurazioni del sistema principale:

```env
# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=coresuite
DB_USERNAME=root
DB_PASSWORD=your_password

# Email (per OTP)
MAIL_FROM_ADDRESS=no-reply@coresuite.it
MAIL_FROM_NAME="Pickup Portal"
RESEND_API_KEY=your_resend_api_key

# Debug
APP_DEBUG=false
```

### Personalizzazione

#### Modificare i Colori

Modifica le variabili CSS in `assets/css/portal.css`:

```css
:root {
    --pickup-primary: #0d6efd;    /* Colore principale */
    --pickup-success: #198754;    /* Successo */
    --pickup-warning: #ffc107;    /* Avviso */
    --pickup-danger: #dc3545;     /* Errore */
}
```

#### Modificare i Timeout

Modifica le costanti in `includes/config.php`:

```php
const SESSION_TIMEOUT = 1800;        // 30 minuti
const OTP_VALIDITY_TIME = 300;       // 5 minuti
const MAX_LOGIN_ATTEMPTS = 5;        // Tentativi login
```

## Utilizzo

### Per i Clienti

1. **Accesso**: Inserire email (e opzionalmente telefono e nome)
2. **Verifica**: Inserire il codice OTP ricevuto via email
3. **Dashboard**: Visualizzare statistiche e pacchi
4. **Segnalazione**: Segnalare nuovi pacchi con codice tracking
5. **Notifiche**: Ricevere aggiornamenti automatici

### Per gli Operatori

Il portale si integra automaticamente con il sistema pickup esistente:

1. **Collegamento automatico**: I pacchi segnalati vengono collegati automaticamente quando arrivano
2. **Notifiche**: I clienti ricevono notifiche automatiche per arrivo/ritiro
3. **Sincronizzazione**: Dati sincronizzati in tempo reale

## API

### Autenticazione

```bash
# Login
POST /api/auth/login
{
    "email": "cliente@example.com",
    "name": "Nome Cliente",
    "csrf_token": "token"
}

# Verifica OTP
POST /api/auth/verify-otp  
{
    "customer_id": 123,
    "otp": "123456",
    "csrf_token": "token"
}
```

### Pacchi

```bash
# Lista pacchi
GET /api/packages?limit=20&offset=0

# Segnala pacco
POST /api/packages
{
    "tracking_code": "1Z999AA1234567890",
    "courier_name": "UPS",
    "recipient_name": "Mario Rossi",
    "csrf_token": "token"
}
```

### Notifiche

```bash
# Lista notifiche
GET /api/notifications?unread=1&limit=10

# Marca come letta
PUT /api/notifications
{
    "notification_id": 123,
    "csrf_token": "token"
}
```

## Sicurezza

- ✅ **CSRF Protection**: Token su tutti i form
- ✅ **Rate Limiting**: Limite richieste per IP
- ✅ **Input Validation**: Validazione rigorosa input
- ✅ **SQL Injection Protection**: Query preparate
- ✅ **Session Security**: Sessioni sicure con timeout
- ✅ **HTTPS Only**: Solo connessioni cifrate
- ✅ **Security Headers**: Headers di sicurezza completi

## Manutenzione

### Log

I log sono salvati in:
- `logs/portal.log` - Log applicazione
- Database: `pickup_customer_activity_logs` - Attività utenti

### Pulizia Automatica

Il sistema effettua pulizia automatica di:
- OTP scaduti
- Sessioni scadute  
- Log vecchi (180 giorni)
- Notifiche vecchie (90 giorni)

### Backup

Assicurati di includere nel backup:
- Database (tutte le tabelle `pickup_customer_*`)
- File di configurazione
- Log applicazione

## Supporto

Per supporto tecnico:
- Email: support@coresuite.it
- Sistema interno: Modulo ticket

## Changelog

### v1.0.0 (2025-10-27)
- ✅ Release iniziale
- ✅ Sistema autenticazione OTP
- ✅ Dashboard e gestione pacchi
- ✅ API REST complete
- ✅ Integrazione sistema pickup
- ✅ Design responsive