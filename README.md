# Coresuite Business

Piattaforma gestionale PHP con frontend Bootstrap. Questa guida riassume setup locale, testing, build e note di sicurezza.

## Requisiti
- PHP 8.1/8.2 con estensioni pdo_mysql, mbstring, json, gd
- Composer
- Node.js 20 + npm
- MySQL/MariaDB

## Setup rapido
1. `cp .env.example .env` e aggiorna le variabili (APP_URL, DB_*, CORS_ALLOWED_ORIGINS, ecc.).
2. `composer install`
3. `npm install`
4. Esegui migrazioni/schema SQL in `database/` sul database configurato.
5. `npm run build:js` per minificare gli asset JS (output in `assets/js/dist`).
6. Avvia l'app da webserver/PHP built-in puntando alla root del progetto.

## OpenAPI Automotive (ACI)
Per abilitare la ricerca targa nelle pratiche ACI configura:
- `OPENAPI_AUTOMOTIVE_TOKEN` (produzione) oppure `OPENAPI_AUTOMOTIVE_SANDBOX_TOKEN` (sandbox)
- opzionale: `OPENAPI_AUTOMOTIVE_BASE_URI`, `OPENAPI_AUTOMOTIVE_TIMEOUT`, `OPENAPI_AUTOMOTIVE_VERIFY_SSL`

## Comandi utili
- Lint PHP: `composer lint`
- Static analysis: `composer stan`
- Test PHP + coverage: `XDEBUG_MODE=coverage vendor/bin/phpunit`
- Lint JS: `npm run lint` (fix: `npm run lint:fix`)
- Format: `npm run format`
- Build JS: `npm run build:js`

## CI
GitHub Actions esegue: phpcs, phpstan, phpunit (coverage), npm lint/build/test su PHP 8.1/8.2. Variabile opzionale `MIN_COVERAGE` consente gate di copertura.

## Sicurezza (sintesi)
- CSP enforced con report-uri `api/csp-report.php`.
- Rate limiting login per IP/username; audit su login/MFA/password.
- Upload con whitelist MIME/estensioni e limite dimensione configurabile (`DOCUMENT_UPLOAD_MAX_BYTES`).
- CORS limitato via `CORS_ALLOWED_ORIGINS` e `PORTAL_CORS_ALLOWED_ORIGINS`.
- Healthcheck pubblico: `api/health.php` (verifica DB).
- Segreti tramite `.env` (già ignorato).

## Struttura cartelle
- `app/` codice applicativo PHP
- `includes/` bootstrap/helpers
- `modules/` feature UI
- `api/` endpoint pubblici/protetti
- `assets/` risorse frontend
- `tests/` suite PHPUnit
- `docs/performance.md` note su caching/compressione/opcache
- `docs/a11y.md` checklist accessibilità/UX

## Note operative
- Genera ganci pre-commit (opzionale): `npx husky install` e `npx husky add .husky/pre-commit "npx lint-staged"`.
- Assicurati che la tabella `login_audit` sia presente (migrazione 20251019_120000).
- Per MFA serve OTPlib/BaconQrCode già in composer.
