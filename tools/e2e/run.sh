#!/usr/bin/env bash
# End-to-end: real Dart client code against the real PHP API and a real MySQL. Test infrastructure only; touches nothing outside a throw-away database.
# Needs: MySQL running (see TEST_DB_* below), php, flutter. Never points at production: the database name must start with leentrack_e2e.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
: "${TEST_DB_SOCKET:?set TEST_DB_SOCKET}" "${TEST_DB_USER:?}" "${TEST_DB_PASS:?}"
DB="${E2E_DB_NAME:-leentrack_e2e}"
case "$DB" in leentrack_e2e*) ;; *) echo "refusing: database name must start with leentrack_e2e"; exit 2;; esac
PORT="${E2E_PORT:-8099}"
export APP_ENV=testing DB_HOST= DB_PORT=0 DB_SOCKET="$TEST_DB_SOCKET" DB_NAME="$DB" DB_USER="$TEST_DB_USER" DB_PASS="$TEST_DB_PASS" \
  MAIL_DRIVER=file REQUIRE_EMAIL_VERIFICATION=false RATE_LIMIT_ENABLED=false LOG_LEVEL=error LOCK_DIR="$(mktemp -d)"
MYSQL="${MYSQL_BIN:-mysql} --no-defaults"
$MYSQL -u"$TEST_DB_USER" -p"$TEST_DB_PASS" -S "$TEST_DB_SOCKET" -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4;"
php "$ROOT/backend/bin/migrate.php" up | tail -1
php -S "127.0.0.1:$PORT" -t "$ROOT/backend/public" "$ROOT/backend/public/index.php" >/tmp/lt-e2e-php.log 2>&1 &
PID=$!
trap 'kill $PID 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do curl -sf "http://127.0.0.1:$PORT/api/v1/health" >/dev/null 2>&1 && break; sleep 0.3; done
cd "$ROOT/apps/flutter"
LT_E2E_URL="http://127.0.0.1:$PORT/api/v1" flutter test test/e2e --tags e2e "$@"
