#!/usr/bin/env bash
# Web migration checks: the Track (Next.js) code against the real PHP API + MySQL, in a throw-away database named leentrack_e2e*.
# Usage: tools/e2e/run-web.sh /path/to/Track [extra node --test args]
# Needs MySQL (TEST_DB_USER/TEST_DB_PASS + TEST_DB_SOCKET or TEST_DB_HOST/PORT), php, node >= 22. Never points at production.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TRACK="${1:?path to the Track checkout}"; shift || true
: "${TEST_DB_USER:?}" "${TEST_DB_PASS:?}"
TEST_DB_SOCKET="${TEST_DB_SOCKET:-}"; TEST_DB_HOST="${TEST_DB_HOST:-127.0.0.1}"; TEST_DB_PORT="${TEST_DB_PORT:-3306}"
if [ -n "$TEST_DB_SOCKET" ]; then CONN=(-S "$TEST_DB_SOCKET"); APP_HOST=""; APP_PORT=0; else CONN=(-h "$TEST_DB_HOST" -P "$TEST_DB_PORT" --protocol=tcp); APP_HOST="$TEST_DB_HOST"; APP_PORT="$TEST_DB_PORT"; fi
DB="${E2E_DB_NAME:-leentrack_e2e_web}"
case "$DB" in leentrack_e2e*) ;; *) echo "refusing: database name must start with leentrack_e2e"; exit 2;; esac
PORT="${E2E_PORT:-8097}"
WEB_ORIGIN="${E2E_WEB_ORIGIN:-http://localhost:3100}"
export APP_ENV=testing DB_HOST="$APP_HOST" DB_PORT="$APP_PORT" DB_SOCKET="$TEST_DB_SOCKET" DB_NAME="$DB" DB_USER="$TEST_DB_USER" DB_PASS="$TEST_DB_PASS" \
  MAIL_DRIVER=file REQUIRE_EMAIL_VERIFICATION=false RATE_LIMIT_ENABLED=false LOG_LEVEL=error LOCK_DIR="$(mktemp -d)" CORS_ALLOWED_ORIGINS="$WEB_ORIGIN"
MYSQL="${MYSQL_BIN:-mysql} --no-defaults"
$MYSQL -u"$TEST_DB_USER" -p"$TEST_DB_PASS" "${CONN[@]}" -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4;" 2>&1 | grep -v "Using a password" || true
php "$ROOT/backend/bin/migrate.php" up | tail -1
php -S "127.0.0.1:$PORT" -t "$ROOT/backend/public" "$ROOT/backend/public/index.php" >/tmp/lt-e2e-web-php.log 2>&1 &
PID=$!
trap 'kill $PID 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do curl -sf "http://127.0.0.1:$PORT/api/v1/health" >/dev/null 2>&1 && break; sleep 0.3; done
cd "$TRACK"
export LT_E2E_URL="http://127.0.0.1:$PORT/api/v1" LT_E2E_WEB_ORIGIN="$WEB_ORIGIN"
node --test --experimental-strip-types "${@:-lib/api/client.test.ts}"
