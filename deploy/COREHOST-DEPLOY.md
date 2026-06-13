# CoreHost — business.coresuite.it

## Due pipeline distinte

| | Pipeline A (repo GitHub) | Pipeline B (CoreHost live) |
|---|---|---|
| **Trigger** | push su `production` / `main` / `staging` | Deploy pannello / webhook Gitea / `autoDeploy` |
| **Destinazione** | Hostinger FTP `/public_html/business/` | Container `corehost-t4vgas0xmg` |
| **Effetto sul sito pubblico** | Nessuno (DNS punta a CoreHost) | Sì — serve `https://business.coresuite.it/` |
| **Sorgente codice** | GitHub `agservizi/staging-business--2-` | Gitea `Carmine/coresuite-business` |

## Stato mirror (aggiornare dopo ogni sync)

| Sorgente | Branch | Commit atteso |
|---|---|---|
| GitHub `production` | production | `4fe0d3d` (fix PHP 8.4 E_STRICT) |
| Gitea mirror | production | deve coincidere con GitHub |
| CoreHost deployato | production | deve coincidere con Gitea |

## Pubblicare una nuova versione (manuale)

```bash
# 1) Sync GitHub production → Gitea (dal pannello CoreHost o push mirror)
php tools/gitea_push_production.php   # richiede token Gitea valido

# 2) Deploy + fix runtime
php tools/corehost_sync_production.php

# Stato
php tools/corehost_deploy_cli.php status
```

## Pubblicare dal progetto (CI)

Il workflow `.github/workflows/deploy.yml` su branch `production` ora:

1. **deploy-corehost** — push mirror su Gitea + `POST /node-apps/{id}/deploy`
2. **deploy-hostinger** — rsync FTP (legacy, non aggiorna il sito pubblico)

### Secret GitHub richiesti (repo → Settings → Secrets)

- `COREHOST_API_TOKEN` — token API pannello (`chk_...`)
- `COREHOST_APP_ID` — `cmqbf9y2q07q66ht4vgas0xmg`

Il token CoreHost **non** autentica su `git.coresuite.it` via HTTPS; per il push mirror in CI serve che il token Gitea sia configurato nel pannello o un PAT Gitea dedicato.

## Risorse CoreHost

| Risorsa | ID |
|---|---|
| Website `business.coresuite.it` | `cmqbddt4v078v6ht4hm8posiz` |
| App `coresuite-business` | `cmqbf9y2q07q66ht4vgas0xmg` |
| MySQL `coresuite_business` | `cmqbdh0iw079g6ht49c20gmw3` |
| Gitea repo | `Carmine/coresuite-business` |

## Problema noto: 404 Apache sul reverse proxy

Il container sito (Apache) fa proxy verso `corehost-t4vgas0xmg:80`, mentre l'app PHP built-in è configurata su `8080`. I siti Node funzionanti (es. shop) hanno `proxyConfig: null` e porta allineata all'app.

**Fix dal pannello:** allineare `startCmd` a `php -S 0.0.0.0:80 -t .` oppure resettare il reverse proxy come sullo shop (senza `gitRepo` sul website).
