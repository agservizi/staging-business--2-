#!/usr/bin/env bash
set -euo pipefail

HOST="${DEPLOY_HOST:-Carmine@192.168.1.50}"
APP_DIR="${DEPLOY_DIR:-/opt/coresuite/business}"
AUTOMATA_DOMAIN="${AUTOMATA_DOMAIN:-automa.coresuite.it}"

echo "==> Deploy Coresuite Business + Automata su ${HOST}"

ssh "${HOST}" "mkdir -p ${APP_DIR}"

rsync -avz --delete \
  --exclude '.git' \
  --exclude 'vendor' \
  --exclude 'node_modules' \
  --exclude '.env' \
  ./ "${HOST}:${APP_DIR}/"

ssh "${HOST}" bash -s <<EOF
set -euo pipefail
cd ${APP_DIR}
if [ ! -f .env ]; then
  cp .env.example .env 2>/dev/null || true
  php tools/setup_caf_encryption_key.php || true
fi
docker compose pull automata || true
docker compose up -d --build
docker compose exec -T business php composer.phar install --no-interaction || php composer.phar install --no-interaction || true
echo "Automata atteso su https://${AUTOMATA_DOMAIN}"
EOF

echo "Deploy completato."
