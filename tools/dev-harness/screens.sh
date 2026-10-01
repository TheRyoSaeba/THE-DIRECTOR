#!/usr/bin/env bash
# Full-page screenshots of the main pages at desktop/laptop/mobile viewports.
#   bash tools/dev-harness/screens.sh <outDir> [nameFilterRegex]
# Builds production assets if missing, removes public/hot, starts `php artisan serve` on
# :8010 if nothing is listening, then runs screens.mjs.
set -euo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO"
OUT="${1:?usage: screens.sh <outDir> [filter]}"
PORT="${PORT:-8010}"
STATE_DIR="${DEV_HARNESS_STATE:-/var/tmp/dev-harness}"; mkdir -p "$STATE_DIR"
php tools/dev-harness/lib/guard.php >/dev/null

rm -f public/hot   # with public/hot present @vite points at a dev server that isn't running
if [[ ! -f public/build/manifest.json || "${REBUILD:-0}" == 1 ]]; then
  echo "building production assets..."
  NODE_ENV=production npx vite build >"$STATE_DIR/vite-build.log" 2>&1
fi
if ! curl -s -o /dev/null "http://127.0.0.1:$PORT/dev-login"; then
  nohup php artisan serve --host=127.0.0.1 --port="$PORT" >"$STATE_DIR/serve.log" 2>&1 &
  for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$PORT/dev-login" && break; sleep 0.2; done
fi
BASE_URL="${BASE_URL:-http://127.0.0.1:$PORT}" node tools/dev-harness/screens.mjs "$OUT" "${2:-}"
