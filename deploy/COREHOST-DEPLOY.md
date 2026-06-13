# CoreHost — business.coresuite.it

## Stato attuale (2026-06-13)

| Risorsa | ID | Stato |
|---|---|---|
| Website `business.coresuite.it` | `cmqbz5nuq00ju101cegni8luy` | RUNNING, REVERSE_PROXY |
| App `coresuite-business-v2` | `cmqbzop1t00rk101c788vjnmd` | RUNNING, giteaManaged |
| MySQL `coresuite_business` | `cmqbdh0iw079g6ht49c20gmw3` | RUNNING (import dump completato) |
| Gitea mirror | `Carmine/coresuite-business` | branch `production` |

Il server CoreHost **non risolve github.com** → deploy solo via **Gitea** `Carmine/coresuite-business`.

## Pubblicare in live (manuale)

```bash
# 1) Push GitHub production → Gitea mirror
php tools/gitea_push_production.php

# 2) Deploy container app + verifica
php tools/corehost_deploy_cli.php deploy
php tools/corehost_deploy_cli.php status

# DB (solo se serve re-import o verifica)
php tools/corehost_db_verify.php
php tools/corehost_db_empty_check.php
```

## Pubblicare dal progetto (CI)

Il workflow `.github/workflows/deploy.yml` su branch `production`:

1. **deploy-corehost** — push mirror Gitea + `POST /node-apps/{id}/deploy`
2. **deploy-hostinger** — rsync FTP (legacy, DNS non punta più qui)

### Secret GitHub richiesti

- `COREHOST_API_TOKEN` — token API pannello (`chk_...`)
- `COREHOST_APP_ID` — `cmqbzop1t00rk101c788vjnmd`

## Import database via API

L'endpoint `POST /databases/{id}/query` spezza gli SQL sui `;` anche dentro le stringhe.
Usare `tools/corehost_import_sql.php` e `corehost_encode_sql_for_api()` in `tools/corehost_sql_split.php`.

## Risorse CoreHost

| Risorsa | ID |
|---|---|
| Website | `cmqbz5nuq00ju101cegni8luy` |
| App | `cmqbzop1t00rk101c788vjnmd` |
| MySQL | `cmqbdh0iw079g6ht49c20gmw3` |
| Gitea | `Carmine/coresuite-business` |

## Runtime app

- `startCmd`: `php -S 0.0.0.0:80 -t .`
- `nodeVersion`: `8.4`
- `installCmd`: `composer install --no-dev --no-interaction`
- Website: `REVERSE_PROXY` verso porta app, **senza** `gitRepo` sul website
