# 12 — Development Guide

## Backend quick start
```bash
cd backend
composer install
cp .env.example .env            # local only; production keeps the env file outside the web root
php bin/keygen.php              # paste into APP_KEY
php bin/migrate.php up          # needs DB_* in .env
php -S 127.0.0.1:8080 -t public public/index.php
curl -s http://127.0.0.1:8080/api/v1/health
```

## Tests
```bash
cd backend
vendor/bin/phpunit                                   # unit tests only; DB tests are reported as SKIPPED
export TEST_DB_NAME=leentrack_test TEST_DB_USER=lt TEST_DB_PASS=... TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3306
vendor/bin/phpunit                                   # + MySQL integration tests (drops ALL tables in that database!)
```
**Use a throw-away database for `TEST_DB_NAME` — the integration base class drops every table in it.**

## Local MySQL 8.4.6 without Docker (what the sandbox uses)
Download the generic Linux tarball from dev.mysql.com, `mysqld --initialize-insecure --datadir=...`, run with `--socket=/tmp/x.sock --port=3307`, create a `lt` user and a `leentrack_test` database, then set `TEST_DB_SOCKET=/tmp/x.sock`. (Needs `libaio1t64` on Ubuntu 24.04.)

## Conventions
* `declare(strict_types=1)` everywhere; PSR-4 `LeenTrack\` → `app/`.
* New endpoint = route in `routes/api.php` + controller + service + validator + test + entry in `05_API_SPECIFICATION.md`. A feature is not "done" until all exist (release gate in `10_ACCEPTANCE_TESTS.md`).
* Never read `role`, `plan`, `business_id`, `user_id`, totals from the request body for authorization or pricing.
* Schema changes: new numbered migration with a `.down.sql`; never edit an applied one (the runner refuses).
* No secrets in git. `.env`, logs and `vendor/` are git-ignored.
* Commit messages: imperative, one concern each; docs updated in the same commit as behaviour.

## Flutter
See `apps/flutter/README.md`.
