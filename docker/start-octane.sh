#!/usr/bin/env sh
set -e

PORT="${PORT:-8080}"
OCTANE_WORKERS="${OCTANE_WORKERS:-4}"
OCTANE_MAX_REQUESTS="${OCTANE_MAX_REQUESTS:-2000}"

php artisan config:cache --ansi

exec php artisan octane:frankenphp \
    --host=0.0.0.0 \
    --port="${PORT}" \
    --workers="${OCTANE_WORKERS}" \
    --max-requests="${OCTANE_MAX_REQUESTS}"
