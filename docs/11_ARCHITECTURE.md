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
| D-26 | The sale transaction (3c) must lock the customer row `FOR UPDATE` **before** inserting the sale; `LoyaltyService` re-locks (no-op) and refuses to run outside a transaction | MySQL's FK check takes a shared lock on the customer row; two concurrent sales would both hold it and deadlock when loyalty tries to update. (Postgres avoided this with `FOR NO KEY UPDATE`; MySQL has no equivalent.) |
| D-27 | Customers are archived, never deleted (sales, repayments and the points ledger reference them); archived customers keep their points | the source's hard delete set `sales.customer_id` to NULL, silently orphaning financial history |
| D-28 | No endpoint accepts `loyalty_points`, `total_spent`, `visit_count`, `last_visit`; they change only inside `LoyaltyService` / the sale service, each change with an append-only ledger row | the source needed triggers to stop cashiers editing points; here there is simply no write path |
| D-29 | `customers.total_spent` = money actually received (checkout payment + later repayments ± edits/voids), not billed totals | consistent with loyalty (earned on money received); resolves G-12 |
| D-30 | Global lock order: customer row → sale row → product rows (ascending id) → stock level rows; the receipt-number counter is touched last | every flow follows it, so concurrent checkouts/edits/voids cannot deadlock; verified by a 6-process opposite-order test |
| D-31 | Idempotency: `UNIQUE(business_id, client_operation_id)` + SHA-256 of the request; the sale row is inserted BEFORE stock is touched; a replay returns the original sale (200, `meta.replayed=true`), same id with different content is 409 | safe retries and offline sync; verified with 8 parallel identical submissions |
| D-32 | The plan's sales limit is opt-in (`ENFORCE_SALES_LIMIT`), default off; `meta.sales_limit_reached` lets clients show the source's banner | the source never blocked the till; blocking existing free-plan customers at cutover is an owner decision (G-29) |
| D-33 | `sale_items` snapshot product name, type and unit cost | receipts and profit stay true after renames, archiving or cost changes |
| D-34 | Money ceiling raised to ₦10bn and `Money::mulRatio` is exact for any inputs (big-number fallback) | ratio tax on very large sales multiplied two cent amounts beyond 64 bits and PHP would have silently used a float |
| D-35 | Receipt numbers are `SALE-YYYYMMDD-NNNNNN` from a per-business counter, allocated last in the transaction | server-issued, sequential, unforgeable (S-12); the source's `SALE-<ms>-<rand>` was client-made |
| D-36 | Repayments use the global lock order customer → sale, are append-only (no update/delete code path), idempotent by `client_operation_id` (replay returns the exact historical balances), and amount ≤ balance is checked under the locks | concurrent payments cannot exceed the balance; verified with 8 parallel processes |
| D-37 | A debt is OPEN while the sale is `pending` and total − paid-at-till − Σ repayments ≥ 0.01; the sale flips to `completed` in the same transaction as the payment that clears it | same rule as `credit/page.tsx`, enforced atomically instead of in the browser |
| D-38 | Statements list CREDIT sales only (cash sales never touch the account); voided sales are excluded; the closing balance always equals the live debt and the test asserts it | an account statement that disagrees with the credit list is worse than none |
| D-39 | A customer who owes money cannot be archived (409 `customer_owes_money`) | archiving would hide a live debt |

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

## Phase 3b additions
* **Schema:** `0005_customers_loyalty` — `customer_groups`, `customers` (DB column `customer_group_id`, API name `group_id`), `loyalty_settings` (defaults when no row, DB CHECKs mirror the source), append-only `loyalty_ledger`.
* **Pure maths:** `LoyaltyMath::checkoutDiscounts` (port of `lib/loyalty/math.ts`) and `LoyaltyMath::earn` (port of SQL `loyalty_apply`), exact integer-cent arithmetic via `Money::floorDiv`.
* **Services:** `CustomerService`, `CustomerGroupService`, `LoyaltyService` (settings, `apply`, `redeem`; both mutate only inside a transaction).
* **Golden parity:** `tools/golden/gen_loyalty.mjs` runs the unmodified original TS and writes `tests/Golden/loyalty_checkout.json` (2,268 cases covering the 99% cap, the manual-discount error, point limits and fractional point values).

## Phase 3c additions
* **Schema:** `0006_sales` (sales, sale_items with snapshots, debt_repayments [endpoints in 3d], sale_edits, document_sequences, FK `loyalty_ledger.sale_id`), `0007_branding`.
* **`SaleService`:** `checkout` (server computes every figure; only item ids/quantities, discount choices and payment are accepted — any price/total/tax field is a 422), `list`, `get`, `document`, `edit` (port of `edit_sale`), `void`.
* **Pure ports with golden tests against the original TypeScript:** `Sales\EditMath` (`lib/sales/edit-math.ts`, 700 cases), `Documents\DocumentModel` + `DocumentBuilder` (`lib/documents/model.ts`, `build.ts`).
* **Money:** ceiling ₦10bn; `Money::mulDivRound` exact big-number fallback.

## Phase 3d additions
* **Endpoints:** `POST /sales/{id}/repayments`, `GET /credit`, `GET /customers/{id}/debt`, `GET /customers/{id}/statement`, `GET /customers/{id}/sales`.
* **`CreditService`:** repay (with payment receipt via the already-ported `DocumentBuilder::fromPayment`), grouped credit list with the source's 14/30-day badges, per-customer debt, statement with opening/closing balance. `credit.read` permission added (managers + owner, as the sidebar).

## Phase 3e additions
* **Schema:** `0008_expenses` (`expense_categories` unique per business+name; `expenses` with `expense_date` DATE, soft delete via `deleted_at/deleted_by`).
* **Services:** `ExpenseService` (CRUD, categories, `seedDefaults`), `ProfitService` (`range`, `compute`, `report`), `DashboardService`; pure `Profit\ProfitMath` and `Profit\PeriodRange` (golden-tested against the original TS: 160 + 36 period cases).
* **Decisions:**
  * D-40 Expenses are soft-deleted (row kept with who/when); lists and totals ignore them. A category used by a live expense cannot be deleted (409 `category_in_use`, enforced on the server).
  * D-41 Dashboard shows distinct named metrics: `revenue_billed` (sales totals), `revenue_collected` (cash actually received incl. repayments), `gross_profit`, `net_profit`. Revenue is never labelled profit.
  * D-42 Dashboard credit figures come from `CreditService::totals()` — the same code as `/credit` — and a test pins them equal (fixes the source's repayment-blind dashboard).
  * D-43 Daily series and "today" use local days in the business time zone, not UTC.
  * D-44 Top products group by `product_id` (two products with one name stay separate); uncapped by a 200-sale window.
  * D-45 Profit is `null` (unknown), never 0, when a period has sales but none with item details; such sales are reported separately.
