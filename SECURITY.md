# Security Policy

## Segnalazione vulnerabilità
Invia segnalazioni in modo riservato al team di manutenzione (preferibilmente via canale privato e-mail). Includi:
- Descrizione e impatto
- Versione/commit coinvolti
- Passi per riprodurre

Non aprire issue pubblici con dettagli di exploit.

## Superfici protette
- Autenticazione con rate limiting per IP/utente, lockout temporaneo, MFA opzionale (TOTP).
- CSP enforced con report-uri `api/csp-report.php`; header XFO, X-Content-Type-Options, HSTS, Referrer-Policy.
- CORS limitato tramite env `CORS_ALLOWED_ORIGINS` e `PORTAL_CORS_ALLOWED_ORIGINS`.
- Upload: whitelist MIME/estensioni, limite dimensione (`DOCUMENT_UPLOAD_MAX_BYTES`), nome file sanificato.
- Audit: login, MFA enable, password change registrati in `login_audit`.
- Healthcheck pubblico `api/health.php` verifica DB.

## Segreti e configurazione
- Usa `.env` (già ignorato) popolato a partire da `.env.example`.
- `require_env` blocca avvio se mancano chiavi DB.
- Evita credenziali hardcoded in codice o asset.

## Operatività
- Esegui `composer install` e `npm install` in ambienti isolati.
- Monitora log CSP (`logs/csp-report.log`) e login audit per anomalie.
- Aggiorna dipendenze regolarmente; CI già esegue phpcs, phpstan, phpunit, npm lint/build.

## Backup e DR (da definire)
- Stabilire piano backup DB e files upload con test di restore periodici.
