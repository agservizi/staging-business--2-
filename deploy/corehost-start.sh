#!/usr/bin/env sh
# CoreHost entrypoint: ascolta sulla porta interna del proxy (80) o su PORT assegnato.
set -eu
PORT="${COREHOST_INTERNAL_PORT:-${PORT:-80}}"
exec php -S "0.0.0.0:${PORT}" -t .
