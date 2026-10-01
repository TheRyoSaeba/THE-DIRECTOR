#!/usr/bin/env bash
# One-command local dev/measurement environment for THE-DIRECTOR.
#
#   bash tools/dev-harness/setup.sh            # install deps, start PG+Redis, .env, migrate, seed
#   bash tools/dev-harness/setup.sh --reseed   # drop + recreate the local DB, then migrate + seed
#   bash tools/dev-harness/setup.sh --build    # additionally build production assets (vite build)
#
# Idempotent: re-running skips what is already done.
# SAFETY: refuses to run unless the DB host is 127.0.0.1/localhost. It seeds synthetic data.
set -euo pipefail

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HARNESS_DIR/../.." && pwd)"
cd "$REPO"

PG_BIN=/usr/lib/postgresql/16/bin
PG_ROOT=/var/lib/pgdirector
PG_DATA=$PG_ROOT/data
DB_NAME=thedirector
STATE_DIR="${DEV_HARNESS_STATE:-/var/tmp/dev-harness}"   # redis dump, logs - never the repo

RESEED=0; BUILD=0
for a in "$@"; do
  case "$a" in
    --reseed) RESEED=1 ;;
    --build) BUILD=1 ;;
    *) echo "unknown arg $a"; exit 2 ;;
  esac
done

log() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
die() { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

is_local_host() { [[ "$1" == "127.0.0.1" || "$1" == "localhost" ]]; }

# ---------------------------------------------------------------------------
# 0. Local-DB guard (before touching anything)
# ---------------------------------------------------------------------------
if [[ -n "${DB_HOST:-}" ]] && ! is_local_host "$DB_HOST"; then
  die "DB_HOST env var is '$DB_HOST'. This harness only runs against 127.0.0.1/localhost."
fi
if [[ -n "${DATABASE_URL:-}${DB_URL:-}" ]]; then
  die "DATABASE_URL/DB_URL is set in the environment; unset it (harness is local-only)."
fi
if [[ -f .env ]]; then
  envhost="$(grep -E '^DB_HOST=' .env | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
  if [[ -n "$envhost" ]] && ! is_local_host "$envhost"; then
    die ".env has DB_HOST=$envhost (remote). Refusing to run: this would seed synthetic data. Move that .env away first."
  fi
  if grep -qE '^DB_URL=.+|^DATABASE_URL=.+' .env; then
    die ".env sets DB_URL/DATABASE_URL. Refusing (local-only harness)."
  fi
fi

mkdir -p "$STATE_DIR"
export COMPOSER_ALLOW_SUPERUSER=1

# ---------------------------------------------------------------------------
# 1. PHP dependencies (GitHub zip downloads 403 through the proxy -> clone from source)
# ---------------------------------------------------------------------------
if [[ ! -f vendor/autoload.php ]]; then
  log "composer install (--prefer-source, --no-dev; this takes a while)"
  composer config -g use-github-api false
  composer config -g github-protocols https
  COMPOSER_PROCESS_TIMEOUT=1800 composer install --no-dev --prefer-source --no-interaction --no-scripts
else
  log "vendor/ present - skipping composer install"
fi

# ---------------------------------------------------------------------------
# 2. Postgres 16 + Redis
# ---------------------------------------------------------------------------
log "Postgres"
if [[ ! -f "$PG_DATA/PG_VERSION" ]]; then
  mkdir -p "$PG_ROOT"; chown postgres:postgres "$PG_ROOT"; chmod 700 "$PG_ROOT"
  su postgres -c "$PG_BIN/initdb -D $PG_DATA -U postgres --auth=trust -E UTF8" >/dev/null
fi
if ! su postgres -c "$PG_BIN/pg_ctl -D $PG_DATA status" >/dev/null 2>&1; then
  su postgres -c "$PG_BIN/pg_ctl -D $PG_DATA -o '-p 5432 -k /tmp' -l $PG_ROOT/pg.log -w start" >/dev/null
fi
PSQL=("$PG_BIN/psql" -h 127.0.0.1 -p 5432 -U postgres -v ON_ERROR_STOP=1 -qAt)
if [[ $RESEED == 1 ]]; then
  log "--reseed: dropping database $DB_NAME"
  "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS $DB_NAME WITH (FORCE)"
fi
if [[ -z "$("${PSQL[@]}" -d postgres -c "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'")" ]]; then
  "$PG_BIN/createdb" -h 127.0.0.1 -p 5432 -U postgres "$DB_NAME"
fi

log "Redis"
if ! redis-cli -p 6379 ping >/dev/null 2>&1; then
  redis-server --daemonize yes --port 6379 --dir "$STATE_DIR" --logfile "$STATE_DIR/redis.log" >/dev/null
  for _ in $(seq 1 20); do redis-cli -p 6379 ping >/dev/null 2>&1 && break; sleep 0.2; done
fi
[[ $RESEED == 1 ]] && redis-cli -p 6379 FLUSHALL >/dev/null

# ---------------------------------------------------------------------------
# 3. .env
# ---------------------------------------------------------------------------
log ".env"
if [[ ! -f .env ]]; then cp .env.example .env; fi
setenv() { # setenv KEY VALUE  (replace or append)
  if grep -qE "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
}
setenv APP_ENV local
setenv APP_DEBUG true
setenv DB_CONNECTION pgsql
setenv DB_HOST 127.0.0.1
setenv DB_PORT 5432
setenv DB_DATABASE "$DB_NAME"
setenv DB_USERNAME postgres
setenv DB_PASSWORD ""
setenv DB_SSLMODE disable
setenv CACHE_STORE redis
setenv SESSION_DRIVER redis
setenv SESSION_CONNECTION session
setenv REDIS_CACHE_DB 1
setenv REDIS_SESSION_DB 2
setenv QUEUE_CONNECTION sync
sed -i '/^NODE_ENV=/d' .env
grep -qE '^APP_KEY=base64:' .env || php artisan key:generate --force
rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php
php artisan package:discover --ansi >/dev/null

# Second guard: what does Laravel actually resolve?
php "$HARNESS_DIR/lib/guard.php"

# ---------------------------------------------------------------------------
# 4. Migrations (with the patches a fresh DB needs - the real schema drifted)
# ---------------------------------------------------------------------------
log "Migrations"
php "$HARNESS_DIR/lib/migrate.php"

# ---------------------------------------------------------------------------
# 5. Synthetic seed (skipped when already seeded; use --reseed to start over)
# ---------------------------------------------------------------------------
log "Seed"
php "$HARNESS_DIR/lib/seed.php"

# ---------------------------------------------------------------------------
# 6. Optional production asset build (needed for screenshots / php artisan serve)
# ---------------------------------------------------------------------------
rm -f public/hot
if [[ $BUILD == 1 || ! -f public/build/manifest.json ]]; then
  log "vite build (production)"
  NODE_ENV=production npx vite build >"$STATE_DIR/vite-build.log" 2>&1 || { tail -30 "$STATE_DIR/vite-build.log"; die "vite build failed"; }
fi

log "Done."
cat <<EOF
  Logins:   p_<career>@test.local / password  (e.g. p_police@test.local) via /dev-login
  Measure:  php tools/dev-harness/measure.php police /work /dashboard /journal
  Serve:    php artisan serve --host=127.0.0.1 --port=8010
  Screens:  bash tools/dev-harness/screens.sh <outDir>   (REBUILD=1 to rebuild assets first)
EOF
