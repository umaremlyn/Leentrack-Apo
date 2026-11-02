# 11 — Architecture (as built in Phase 1)

## Principle
One PHP/MySQL API (`/api/v1`) is the foundation. The Flutter app and the Next.js app are both ordinary clients of it. Nothing in this repository touches the production Vercel/Supabase deployment.

## Backend layout (`backend/`)
```
public/            ONLY directory exposed by the web server (front controller index.php, .htaccess)
app/Core/          framework-free infrastructure: Env, Request, Response, Router, ApiException, Logger,
                   Db (PDO), Migrator, RateLimiter, Money, Uuid, App (service holder)
app/Http/          Kernel (dispatch, error mapping, security headers, CORS), Middleware/, Controllers/
config/app.php     reads env; nothing secret is committed
routes/api.php     the single route table; routes are private unless explicitly marked public
database/migrations/NNNN_name.up.sql / .down.sql   checksummed, tracked in schema_migrations
bin/               CLI entry points (migrate, keygen; cron jobs arrive in later phases)
storage/           logs/cache — outside the web root in production
tests/Unit, tests/Integration (real MySQL)
```
Layering rule for upcoming phases: **Controller (thin: validate → call service → shape response) → Service (business rules, transactions, authorization via Policy) → Repository (SQL, always tenant-scoped).** Controllers never touch PDO; repositories never decide permissions.

## Request lifecycle
`public/index.php` → `bootstrap` (env, UTC, `display_errors=0`) → `Request::fromGlobals` (proxy headers trusted only from configured proxies) → `Kernel::dispatch` (prefix check → router → middleware chain → controller) → `Kernel::decorate` (request id, security headers, exact-match CORS) → `Response::send`. Any `ApiException` becomes the standard error envelope; any other `Throwable` is logged with a request id and returned as a generic 500 (no SQL, paths or traces leave the server). `APP_DEBUG=true` is refused when `APP_ENV=production`.

## Response contract
`{ok:true,data,meta}` / `{ok:false,error:{code,message,fields?},meta:{request_id}}`; money as 2-dp strings; UTC timestamps. Full contract: `05_API_SPECIFICATION.md`.

## Money
`Money` stores integer minor units (no floats, no bcmath/gmp — the sandbox and many shared hosts lack bcmath), rounds half away from zero, caps at 1,000,000,000.00 (the source's own limit) so ratio math cannot overflow. Tax/discount tests mirror the source rules (`04_BUSINESS_RULES.md`). Golden parity tests against the original TypeScript are Phase 3.

## Database conventions
utf8mb4, InnoDB, DATETIME(3) in UTC (connection forced to `+00:00`), strict `sql_mode`, `READ COMMITTED`, real prepared statements (emulation only on the migrator connection, which runs multi-statement files). `Db::transaction` retries once on deadlock/lock-wait timeout. MySQL DDL auto-commits, so migrations are not atomic: keep them small, idempotent, and the runner records a version only after a file fully succeeds.

## Rate limiting
MySQL-backed fixed window (`rate_limits`), usable on shared hosting. Applied per route with `'throttle:max,seconds'`; **fails closed** (503) if the store is unavailable. `TRUSTED_PROXIES` controls whether `X-Forwarded-For` is believed.

## Decisions (ADR log)
| ID | Decision | Why |
|---|---|---|
| D-01 | Monorepo: `backend/`, `apps/flutter/`, `docs/` in Leentrack-Apo | single source for contract + clients |
| D-02 | Opaque revocable tokens in MySQL (Phase 2) | revocation, no key-rotation burden |
| D-03 | No framework for core; Composer only for dev tooling | shared-hosting simplicity, small attack surface |
| D-05 | Money = integer minor units | exactness without bcmath |
| D-06 | Rules in services, not DB triggers | testable, portable, shared-hosting friendly |
| D-12 | Routes private by default; `public` is explicit and asserted by a test | prevents accidentally exposed endpoints |
| D-13 | Rate limiter fails closed | brute-force protection must not silently disappear |
| D-14 | PHP minimum 8.3 in composer.json for now; CI/prod target 8.4 | sandbox has 8.3.6; code uses no 8.4-only features |
