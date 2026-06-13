# Ripristino business.coresuite.it su Hostinger

## Stato (2026-06-13)

- **CoreHost:** website in `maintenance`, app fermata (non serve più il traffico)
- **Hostinger:** vhost attivo su `/home/u427445037/domains/coresuite.it/public_html/business`
- **Database Hostinger:** `u427445037_coresuitebusin` (dump originale già importato anche su CoreHost)

## Deploy codice (automatico)

Push su branch `production` → GitHub Actions `deploy-hostinger` (SFTP porta 65002).

Secret richiesti (già presenti): `FTP_HOST`, `FTP_USER`, `FTP_PASS`
Opzionale: `SSH_KEY` (chiave in `deploy/hostinger_deploy_ed25519`)

```bash
gh workflow run deploy.yml -R agservizi/staging-business--2- --ref production
```

## DNS / Cloudflare (se il sito non risponde da Hostinger)

In Cloudflare per `business.coresuite.it`:

1. Record **A** → `188.114.97.7` (o IP Hostinger attuale)
2. **Proxy disattivato** (solo DNS, nuvola grigia) oppure origin = Hostinger
3. Rimuovi eventuale CNAME verso tunnel CoreHost

## hPanel (alternativa senza GitHub Actions)

1. Sito `business.coresuite.it` → **Git** → repo GitHub `agservizi/staging-business--2-`
2. Branch **`production`** (NON staging)
3. Directory installazione: `domains/coresuite.it/public_html/business`
4. **Deploy**

## Verifica

- https://business.coresuite.it/ → pagina login
- https://business.coresuite.it/assets/js/staff-notifications.js → HTTP 200
- Login admin funzionante (DB Hostinger `u427445037_coresuitebusin`)

## Chiave SSH (se SFTP Actions fallisce)

Aggiungi in hPanel → SSH Access la chiave da `deploy/SSH-SETUP.txt`, poi:

```bash
gh secret set SSH_KEY -R agservizi/staging-business--2- < deploy/hostinger_deploy_ed25519
```
