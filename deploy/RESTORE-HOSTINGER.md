# Ripristino business.coresuite.it su Hostinger

## ⚠️ Passo obbligatorio: Cloudflare DNS

Il dominio passa ancora da **Cloudflare → CoreHost** (origine in errore → **502**).
Finché non cambi DNS, Hostinger non riceve traffico anche con codice aggiornato.

In **Cloudflare** → `business.coresuite.it`:

1. Record **A** → `188.114.97.7` (IP Hostinger)
2. **Proxy disattivato** (nuvola **grigia**, solo DNS)
3. Elimina CNAME / tunnel verso CoreHost se presenti
4. Attendi 2–5 minuti

Verifica: `nslookup business.coresuite.it` deve mostrare IP Hostinger **senza** proxy Cloudflare attivo sull’host.

## Stato infrastruttura (2026-06-13)

| Componente | Stato |
|---|---|
| CoreHost | Website in **manutenzione**, app fermata |
| Hostinger vhost | Attivo: `/home/u427445037/domains/coresuite.it/public_html/business` |
| DB Hostinger | `u427445037_coresuitebusin` |
| GitHub Actions SFTP | **Timeout** porta 65002 (Hostinger non raggiungibile dai runner GitHub) |

## Deploy codice su Hostinger (consigliato: hPanel Git)

**hPanel** → sito `business.coresuite.it` → **Git**:

1. Repository: `https://github.com/agservizi/staging-business--2-.git`
2. Branch: **`production`** (NON `staging`)
3. Percorso: `domains/coresuite.it/public_html/business`
4. Clicca **Deploy**

## Deploy automatico (quando SFTP funziona)

Secret GitHub: `FTP_HOST`, `FTP_USER`, `FTP_PASS`

```bash
gh workflow run deploy.yml -R agservizi/staging-business--2- --ref production
```

Se fallisce con timeout: aggiungi chiave SSH in hPanel (`deploy/SSH-SETUP.txt`) e secret `SSH_KEY`.

## Verifica post-ripristino

- https://business.coresuite.it/ → login (non 502)
- https://business.coresuite.it/assets/js/staff-notifications.js → HTTP 200
- Login con utente `admin` (DB Hostinger)
