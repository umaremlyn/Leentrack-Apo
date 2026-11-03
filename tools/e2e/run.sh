#!/usr/bin/env bash
# End-to-end: real Dart client code against the real PHP API and a real MySQL. Test infrastructure only; touches nothing outside a throw-away database.
# Needs: MySQL running (see TEST_DB_* below), php, flutter. Never points at production: the database name must start with leentrack_e2e.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
: "${TEST_DB_USER:?}" "${TEST_DB_PASS:?}"
# Either a unix socket (TEST_DB_SOCKET) or TCP (TEST_DB_HOST + TEST_DB_PORT, default 127.0.0.1:3306).
TEST_DB_SOCKET="${TEST_DB_SOCKET:-}"; TEST_DB_HOST="${TEST_DB_HOST:-127.0.0.1}"; TEST_DB_PORT="${TEST_DB_PORT:-3306}"
if [ -n "$TEST_DB_SOCKET" ]; then CONN=(-S "$TEST_DB_SOCKET"); APP_HOST=""; APP_PORT=0; else CONN=(-h "$TEST_DB_HOST" -P "$TEST_DB_PORT" --protocol=tcp); APP_HOST="$TEST_DB_HOST"; APP_PORT="$TEST_DB_PORT"; fi
DB="${E2E_DB_NAME:-leentrack_e2e}"
case "$DB" in leentrack_e2e*) ;; *) echo "refusing: database name must start with leentrack_e2e"; exit 2;; esac
PORT="${E2E_PORT:-8099}"
export APP_ENV=testing DB_HOST="$APP_HOST" DB_PORT="$APP_PORT" DB_SOCKET="$TEST_DB_SOCKET" DB_NAME="$DB" DB_USER="$TEST_DB_USER" DB_PASS="$TEST_DB_PASS" \
  MAIL_DRIVER=file REQUIRE_EMAIL_VERIFICATION=false RATE_LIMIT_ENABLED=false LOG_LEVEL=error LOCK_DIR="$(mktemp -d)"
MYSQL="${MYSQL_BIN:-mysql} --no-defaults"
$MYSQL -u"$TEST_DB_USER" -p"$TEST_DB_PASS" "${CONN[@]}" -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4;"
php "$ROOT/backend/bin/migrate.php" up | tail -1
php -S "127.0.0.1:$PORT" -t "$ROOT/backend/public" "$ROOT/backend/public/index.php" >/tmp/lt-e2e-php.log 2>&1 &
PID=$!
trap 'kill $PID 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do curl -sf "http://127.0.0.1:$PORT/api/v1/health" >/dev/null 2>&1 && break; sleep 0.3; done
cd "$ROOT/apps/flutter"
LT_E2E_URL="http://127.0.0.1:$PORT/api/v1" flutter test test/e2e --tags e2e "$@"
