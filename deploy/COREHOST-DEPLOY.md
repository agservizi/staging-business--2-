# CoreHost — business.coresuite.it

## Stato attuale (2026-06-13)

| Risorsa | ID | Stato |
|---|---|---|
| Website `business.coresuite.it` | `cmqbz5nuq00ju101cegni8luy` | RUNNING, REVERSE_PROXY, SSL da riattivare |
| App `coresuite-business-v2` | `cmqbzop1t00rk101c788vjnmd` | ERROR — serve **giteaManaged** dal pannello |
| App precedente (funzionante) | `cmqbf9y2q07q66ht4vgas0xmg` | **eliminata** durante tentativo fix API |
| MySQL | `cmqbdh0iw079g6ht49c20gmw3` | RUNNING |

**GitHub `production`:** commit `6393df9` (CI CoreHost + `corehost-start.sh`)

Il server CoreHost **non risolve github.com** → deploy solo via **Gitea** `Carmine/coresuite-business`.

## Ripristino urgente (pannello CoreHost)

Copia questo prompt per l'agente del pannello:

> Ripristina `business.coresuite.it`:
> 1. Elimina app duplicate in errore (`coresuite-business-v2`, vecchie `coresuite-business` se presenti)
> 2. Crea app PHP `coresuite-business` collegata a GitHub `agservizi/staging-business--2-` branch `production` (**giteaManaged=true**, mirror `Carmine/coresuite-business`)
> 3. Sync Gitea da GitHub fino a commit `6393df9`
> 4. Collega website `cmqbz5nuq00ju101cegni8luy` all'app (REVERSE_PROXY, `proxyConfig` auto/null, **senza** `phpVersion`)
> 5. `startCmd`: `php -S 0.0.0.0:80 -t .` — `nodeVersion` 8.4
> 6. Deploy SUCCESS + SSL Let's Encrypt + verifica HTTP 200 su `/`

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
| GitHub `production` | production | `6393df9` (CI CoreHost + runtime fix) |
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
- `COREHOST_APP_ID` — ID app dopo ripristino pannello (attuale provvisorio: `cmqbzop1t00rk101c788vjnmd`)

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
