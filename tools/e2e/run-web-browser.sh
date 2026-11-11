#!/usr/bin/env bash
# Real browser check: Track (Next.js, API mode) + the PHP API + MySQL, driven by headless Chromium (Playwright).
# Usage: tools/e2e/run-web-browser.sh /path/to/Track [script ...]   (default: tools/e2e/web/inventory.mjs)
# Throw-away database only (leentrack_e2e*). Never points at production; the web app is built with the LOCAL api address.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TRACK="${1:?path to the Track checkout}"; shift || true
SCRIPTS=("${@:-$ROOT/tools/e2e/web/inventory.mjs}")
: "${TEST_DB_USER:?}" "${TEST_DB_PASS:?}"
TEST_DB_SOCKET="${TEST_DB_SOCKET:-}"; TEST_DB_HOST="${TEST_DB_HOST:-127.0.0.1}"; TEST_DB_PORT="${TEST_DB_PORT:-3306}"
if [ -n "$TEST_DB_SOCKET" ]; then CONN=(-S "$TEST_DB_SOCKET"); APP_HOST=""; APP_PORT=0; else CONN=(-h "$TEST_DB_HOST" -P "$TEST_DB_PORT" --protocol=tcp); APP_HOST="$TEST_DB_HOST"; APP_PORT="$TEST_DB_PORT"; fi
DB="${E2E_DB_NAME:-leentrack_e2e_browser}"
case "$DB" in leentrack_e2e*) ;; *) echo "refusing: database name must start with leentrack_e2e"; exit 2;; esac
API_PORT="${E2E_PORT:-8097}"; WEB_PORT="${E2E_WEB_PORT:-3100}"
WEB="http://localhost:$WEB_PORT"; API="http://127.0.0.1:$API_PORT/api/v1"
export APP_ENV=testing DB_HOST="$APP_HOST" DB_PORT="$APP_PORT" DB_SOCKET="$TEST_DB_SOCKET" DB_NAME="$DB" DB_USER="$TEST_DB_USER" DB_PASS="$TEST_DB_PASS" \
  MAIL_DRIVER=file REQUIRE_EMAIL_VERIFICATION=false RATE_LIMIT_ENABLED=false LOG_LEVEL=error LOCK_DIR="$(mktemp -d)" CORS_ALLOWED_ORIGINS="$WEB"
MYSQL="${MYSQL_BIN:-mysql} --no-defaults"
$MYSQL -u"$TEST_DB_USER" -p"$TEST_DB_PASS" "${CONN[@]}" -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4;" 2>&1 | grep -v "Using a password" || true
php "$ROOT/backend/bin/migrate.php" up | tail -1
php -S "127.0.0.1:$API_PORT" -t "$ROOT/backend/public" "$ROOT/backend/public/index.php" >/tmp/lt-e2e-browser-php.log 2>&1 &
PHP_PID=$!
if curl -s -o /dev/null "http://localhost:$WEB_PORT/" 2>/dev/null; then echo "port $WEB_PORT is already served by something else (a stale dev server?). Stop it or set E2E_WEB_PORT."; exit 3; fi
cd "$TRACK"
# the API address is baked in at build time; the Supabase variables are deliberately left unset
# own process group, so cleanup stops next-server too (killing only npx leaves the real server holding the port)
NEXT_PUBLIC_API_BASE_URL="$API" setsid npx next dev -p "$WEB_PORT" >/tmp/lt-e2e-browser-next.log 2>&1 &
NEXT_PID=$!
trap 'kill $PHP_PID 2>/dev/null || true; kill -- -"$NEXT_PID" 2>/dev/null || pkill -P "$NEXT_PID" 2>/dev/null || true' EXIT
for _ in $(seq 1 60); do curl -sf "$API/health" >/dev/null 2>&1 && break; sleep 0.5; done
for _ in $(seq 1 120); do curl -s -o /dev/null "$WEB/login" && break; sleep 1; done
# warm the pages so first-compile time does not count against the browser steps
for p in login dashboard inventory; do curl -s -o /dev/null "$WEB/$p" || true; done
for s in "${SCRIPTS[@]}"; do LT_API="$API" LT_WEB="$WEB" NODE_PATH="${NODE_PATH:-}" node "$s"; done
