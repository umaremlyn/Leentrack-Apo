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
| D-15 | Staff invite links are e-mailed only and never returned to the inviter | the source returned the link to the owner; that lets an owner accept on the invitee's behalf and set the password of an account on the invitee's e-mail (S-16) |
| D-16 | Platform admin = row in `platform_admins`, created by CLI only; checked on every request; non-admins get 404 | closes S-01/S-02; role can never come from a request or a profile field |
| D-17 | Business role lives only in `business_members`; access needs `status='active' AND user_id=caller`; membership re-checked on every request; non-members get 404 | closes S-05 (server-side roles), S-16; removal/deactivation is immediate without token revocation |
| D-18 | Tokens: opaque, SHA-256 at rest, access 15 min, refresh 30 d rotating; presenting a rotated refresh token revokes the session | theft detection. Clients MUST serialise refresh calls (a concurrent double refresh looks like reuse) |
| D-19 | Billing columns (`plan*`, `paystack_*`) are not writable by any tenant endpoint; `PlanCatalog::effective()` applies expiry on every request | closes S-06 and stops access outliving payment before the nightly job |
| D-20 | Registration/forgot/verify never reveal whether an e-mail exists; login errors are identical for unknown user and wrong password | no account enumeration |
| D-21 | Product `stock_quantity` cannot be edited via product PATCH; all changes go through `StockService` (adjustments with reason, imports, later sales/purchases/transfers) | the source's edit form overwrote stock silently with no history; now every unit is accounted for in `stock_movements` |
| D-22 | `DELETE /products/{id}` archives; barcode uniqueness applies to non-archived items (virtual generated column) | sale items and stock history must keep referencing the item; the source's hard delete either failed (RESTRICT) or destroyed the item |
| D-23 | `cost_price` is hidden from roles without `products.cost.read` | the source exposed cost to cashiers through RLS; they only need the selling price |
| D-24 | Stock mutation lock order: product row, then stock level row (both `FOR UPDATE`) | serialises concurrent changes without deadlocks; verified by an 8-process test |
| D-25 | Online code can never drive stock below zero; only the offline-sync path (Phase 4) may, producing a visible `oversold` conflict | satisfies "two devices sell the last unit" without silently losing a sale |

## Phase 2 additions
* **Schema:** migrations `0002_identity` (users, user_profiles, auth_sessions, user_tokens, platform_admins) and `0003_tenancy` (businesses, locations, business_members, member_outlets, audit_logs).
* **Layers:** Repositories (`UserRepository`, `SessionRepository`, `TenantRepository` — every tenant query takes a `business_id`) → Services (`AuthService`, `SessionService`, `BusinessService`, `StaffService`, `LocationService`, `MeService`, `AuditService`, `PlanCatalog`, `Permissions`) → thin Controllers. `Container` wires services lazily; `Lazy` defers controller construction so a request builds only what it uses.
* **Middleware:** `auth` / `auth:optional`, `tenant` (header) / `tenant:path`, `platform_admin`, `throttle:N,S`. Contexts: `AuthContext`, `TenantContext` (carries role, effective plan, outlet restriction; `require()`, `requireFeature()`, `requireWithinLimit()`).
* **Validation:** `Validator` is whitelist-first (`only([...])`): unknown keys and privileged keys (`role`, `plan`, `status`, `user_id`, …) produce 422 before any work happens.
* **Endpoints implemented:** see the route table in `routes/api.php` (auth, me, sessions, businesses, staff/invites, locations, admin/stats).

## Phase 3a additions
* **Schema:** `0004_catalogue_stock` — `categories`, `products` (with `item_type`, soft-archive, virtual `barcode_live` unique key), `stock_levels` (per outlet), `stock_movements` (append-only ledger).
* **Invariants (tested after every mutation scenario):** `products.stock_quantity = SUM(stock_levels.quantity)`; each level equals the sum of its movements.
* **Services:** `CategoryService`, `ProductService`, `StockService` (the only writer of stock), `ProductImportService`, `HousekeepingService` (+ `bin/cron.php housekeeping`).
* **Shared helpers:** `Pager`, `Csv` (RFC-4180 parser equal to the source's; injection-safe writer), `Validator::money/int/url`.
