# LeenTrack Rebuild — Project Status

_Last updated: 2026-11-02 · Branch: `claude/laughing-hypatia-gejtzf` · Current phase: **Phase 3 — Business operations: 3a ✅ · 3b ✅ · 3c (POS, sales, documents) COMPLETE; 3d (credit & repayments) next**_

## Ground rules in force
* Production (Vercel + Supabase) is **never touched**. `umaremlyn/Track` is read-only to this project (verified unmodified).
* No production data, schema dump or credentials exist here → no data-migration or account-continuity claim can be made (docs/06).
* The PHP API is the single foundation; Flutter and Next.js are both clients of `/api/v1`.
* Owner approved proceeding with the **stated defaults** in `docs/09_KNOWN_GAPS.md` (G-05…G-14).

## Phase table
| Phase | State |
|---|---|
| 0 Source audit | ✅ docs 01–10 |
| 1 Foundation | ✅ backend skeleton + migrator + rate limiter + Money; Flutter scaffold + adaptive shell + API client; docs 11–12 |
| 2 Database & core (auth, tenancy, policies) | ✅ identity + tenancy + authorization + plan guard + audit; deployment guide drafted |
| 3 Business operations | 🔶 3a catalogue & stock ✅ · 3b customers/groups/loyalty ✅ · 3c POS/sales/documents ✅ · 3d credit ⏳ next · 3e expenses/profit/dashboard · 3f suppliers/purchases · 3g transfers · 3h reports · 3i staff extras/account lifecycle · 3j billing · 3k admin · 3l settings/branding/cron |
| 4–10 | ⏳ not started |

## Session 6 report (Phase 3c — POS, sales, documents)
**Completed**
* Migrations `0006_sales` (sales, sale_items with name/type/cost snapshots, debt_repayments [schema only; endpoints in 3d], sale_edits, document_sequences, FK `loyalty_ledger.sale_id`) and `0007_branding`.
* `POST /sales` (checkout): the client sends only item ids + quantities, discount choices and how the customer pays. The server computes prices, group/manual/points discounts, tax, total, change, balance, status, receipt number; any money field in the request is a 422 (closes S-03, S-12, S-13). Per-outlet stock via `StockService`, customer stats, loyalty earn/redeem, all in one transaction. Idempotent by `client_operation_id`.
* `GET /sales` (local-day filters, status/payment/customer/outlet/search, summary excluding voided), `GET /sales/{id}` (profit/cost only with permission), `POST /sales/{id}/edit` (port of `edit_sale`), `POST /sales/{id}/void` (restores stock, reverses spend/visit/points), `GET /sales/{id}/document` (receipt/invoice model, share text, WhatsApp number), `GET|PUT /businesses/{id}/branding`.
* Pure ports checked against the original TypeScript: `EditMath` (`lib/sales/edit-math.ts`), `DocumentModel`/`DocumentBuilder` (`lib/documents/model.ts`, `build.ts`). `tools/golden/gen_sales.mjs` generates the vectors by running the unmodified originals.
* `Money`: ceiling raised to ₦10bn and `mulRatio` made exact for any inputs (found while testing: ratio tax on very large sales multiplied two cent amounts beyond 64 bits and PHP would have silently used a float).
* ADRs D-29…D-35, gaps G-29…G-33.

**Tests executed (actual results)**
| Suite | Result |
|---|---|
| `vendor/bin/phpunit` (unit + golden + MySQL 8.4.6 integration) | **OK — 177 tests, 45,181 assertions** |
| Golden vs original TypeScript | edit maths **700/700**; documents (10 sales + 2 payment documents), `documentNumber`, `statusFor`, `paymentMethodLabel`, `whatsappNumber`, `money`, `fileNameFor`, `titleFor` and all 5 share messages **character-for-character identical**; loyalty/discount 2,268/2,268 (still) |
| Parallel processes (real concurrent requests through the full Kernel) | 8 identical submissions → exactly 1 sale, stock taken once; 8 devices racing for 5 units → 5 sold / 3 refused (`insufficient_stock`), unique receipt numbers; 6 workers × 5 sales in opposite product order on one customer → 30/30 succeeded, no deadlock |
| Deterministic lock-order test | holds the higher product id and proves the checkout takes the lower id first; passes on the real code, **fails 3/3 when the sort is removed** |
| Mutation check (15 deliberate breakages: tax base, short payment, idempotency hash, lock order, customer lock, void stock restore, stale-screen check, edit/void permissions, outlet restriction, cost visibility, points cap, edit tolerance, document status label, logo signature check) | **14 caught on the first run; 1 survived (lock order — the deadlock retry hid it) → fixed by the new deterministic test.** One mutation of mine was malformed (failed for the wrong reason); redone correctly and caught for the right reason |
| Invariants asserted after every scenario | stock = Σ levels = Σ movements; sale subtotal = Σ items; total = subtotal − discount + tax; loyalty points = Σ ledger |

**Defect found by the new tests (fixed, regression test added):** since Phase 3a, `PATCH /products/{id}` with `{"is_active": false}` returned **500** (PDO sent the boolean as an empty string under MySQL strict mode); locations had the same hazard. Every dynamic-update helper now casts booleans. The 3a tests had only created inactive products, never edited the flag.

**Security findings closed (by test):** S-03 (checkout authorization/tenant scoping — no unauthenticated-in-effect `SECURITY DEFINER` function; business and user come from the token), S-12 (server-issued receipt numbers), S-13 (client totals/prices refused), S-09 partly (duplicate and racing submissions are now idempotent server-side; the offline queue protocol itself is Phase 4), S-11 partly (sales are never deleted: edit keeps history, void keeps the record).

**Not done / not verified**
* **Repayments** (record a payment on a credit sale, customer statement, debt list, "owes money ⇒ cannot archive") are 3d. Table and the edit/void arithmetic already account for repayments, but no endpoint creates them yet, so those code paths are exercised only with seeded rows.
* **Server-side PDF** (G-31), a **till/drawer ledger** for refunds (G-32), refunds as a distinct state (only void).
* The sales **plan limit is opt-in** (G-29) pending the owner's decision.
* Receipt `change_amount` and `payment_method_raw` handling for legacy rows will be settled in the importer (Phase 8).
* The Flutter and web clients still do not call any of this.
* Throughput note: the per-business receipt counter row is locked for the tail of every checkout, serialising sales of one business briefly; fine for shop volumes, to be load-tested in Phase 9.

## Session 5 report (Phase 3b — customers, groups, loyalty)
**Completed**
* Migration `0005_customers_loyalty` (customer_groups, customers, loyalty_settings, append-only loyalty_ledger; DB CHECKs mirror the source: group discount 0–100, spend-per-point 1…1e8, point value ≤ spend, non-negative counters).
* Endpoints: customers CRUD (+ archive/restore, search/sort/filter/paging, loyalty ledger), customer groups CRUD, loyalty settings (defaults when unset, owner-only write, the source's validation rules).
* `LoyaltyMath` (checkout discounts = port of `lib/loyalty/math.ts`; earning = port of SQL `loyalty_apply`) and `LoyaltyService` (`apply`, `redeem` — transaction-only, ledger row per change). `Money::floorDiv`.
* Golden generator `tools/golden/gen_loyalty.mjs` runs the original TypeScript read-only against the production repo checkout.
* Rules: points/counters have no write path via the API; customers archived not deleted; cashiers can create/read customers and read groups/ledger/settings; managers edit customers and groups; owner edits loyalty settings.
* Docs: ADRs D-26…D-28, gaps G-25…G-28, API/test notes.

**Tests executed (actual results)**
| Suite | Result |
|---|---|
| `vendor/bin/phpunit` (unit + golden + MySQL 8.4.6 integration) | **OK — 140 tests, 44,247 assertions** |
| Golden parity vs original TypeScript (G-DISC-1) | **2,268 / 2,268 cases identical** (group cap, manual-discount error, point limits, fractional values); 4 deliberate maths mutations all flagged (80 / 1,224 / 72 / 3,312 differing fields) |
| Loyalty properties (500 randomised sequences) | balance never negative; progress always in [0, rate); money received = points × rate + progress |
| Ledger invariant under 120 random operations with redemptions | balance = Σ ledger points = last `balance_after` at every step |
| Parallel processes: 6 workers × 20 earns on one customer | exactly 120 points / 120 ledger rows, passes 3/3; with the customer lock removed it fails 6/6 |
| Mutation check, 9 deliberate breakages (business scope, update permission, write whitelist, cross-business group, loyalty-write permission, redeem balance check, redeem discount check, negative-balance clamp, customer row lock) | **all 9 caught** |

**Security findings closed (by test):** the source let cashiers reach customer edit/delete and relied on a trigger to stop point edits (S-05/S-11 for customers); there is now no API path that writes points or counters, deletion is archival, and every customer/group/loyalty route is tenant-isolated.

**Not done / not verified**
* Customers' `total_spent`, `visit_count`, `last_visit` are not yet maintained — that happens when sales exist (3c). The "owes money ⇒ cannot archive" rule and the customer statement/purchase history arrive with 3c/3d.
* `loyalty_ledger.sale_id` has no foreign key yet (sales table does not exist); it is added with the sales migration.
* Group-edit permission is a documented default (G-25), not confirmed.
* Customer duplicates are not detected (G-27).
* The Flutter and web clients do not use these endpoints yet.

## Session 4 report (Phase 3a — catalogue & stock)
**Completed**
* Migration `0004_catalogue_stock` (categories, products, per-outlet `stock_levels`, append-only `stock_movements`); applied / rolled back / reapplied.
* Endpoints: categories CRUD; products list (filters, sort allow-list, pagination, LIKE-escaped search), create (opening stock), show, update, archive, restore, CSV **import** (validate/commit) and **export**; stock adjustments, levels, movements, valuation (cost & retail).
* Rules reproduced from the source: service vs product (no stock/barcode/thresholds for services), reorder = given || min || 5, category delete leaves products uncategorised, case-insensitive category matching, CSV aliases / `cleanNumber` / service detection / row messages, RFC-4180 parser.
* Rules improved (documented as D-21…D-25, G-23/G-24): stock only via audited movements, archive instead of delete, cost hidden from cashiers, plan limits on products/categories/import, barcode uniqueness per business, formula-injection-safe export, online stock can never go negative.
* `HousekeepingService` + `bin/cron.php housekeeping`.

**Tests executed (actual results)**
| Suite | Result |
|---|---|
| `vendor/bin/phpunit` (unit + MySQL 8.4.6 integration) | **OK — 118 tests, 1,242 assertions** (27 new: catalogue, tenant isolation on every new route, stock invariants, outlet restriction, import/export, CSV parity, housekeeping, concurrency) |
| Route-table security test (S-UNAUTH) | automatically covers every new route (no edits needed) |
| Concurrency: 8 parallel PHP processes × 30 decrements against 120 units | exactly 120 sold / 120 refused; invariants hold — passes 3/3 |
| Mutation check, 9 deliberate breakages (business scope on lookup, negative-stock guard, row locks, level lock, CSV formula guard, cost-price hiding, import plan limit, outlet-restricted stock, adjust permission) | **all caught** once the lock mutation was done right (see below) |

**Test-quality finding (important):** my first concurrency test detected a deliberate removal of BOTH row locks only 1 time in 4. I rewrote it (synchronised start barrier, 8 workers, attempts = 2× stock); it now detects the removal 6/6 and still passes with locks 3/3. Removing only ONE of the two locks correctly goes unnoticed (the other still serialises), which is intended defence in depth.

**Not done / not verified**
* No sale, purchase or transfer touches stock yet — `StockService::applyDelta` is ready for them (3c/3f/3g). Until then stock changes only via adjustments/import/opening stock.
* Product image upload (only a validated https `image_url`), bulk-edit, product variants (not in source), stock reservations (not in source).
* Inventory valuation by cost uses the *current* cost price (no FIFO/average-cost layers; the source has none either).
* Import is synchronous (≤2000 rows, 2 MB). Large catalogues need several files; chunked/resumable imports belong to the migration tooling (Phase 8).
* The Flutter and web clients do not call these endpoints yet.
* Concurrency was verified for stock adjustments only; refresh-token/invite races are still sequentially tested.

## Session 3 report (Phase 2)
**Completed**
* Migrations `0002_identity`, `0003_tenancy` (with down files); applied, rolled back and re-applied on MySQL 8.4.6.
* Auth: register (generic 202, no enumeration, verified-email login), login (per-account throttle, identical errors, dummy-verify timing), **legacy bcrypt verify-then-upgrade**, refresh rotation with **reuse detection**, logout / logout-all, forgot/reset (single-use, 1 h, revokes sessions), verify-email, change-password, session list/revoke.
* Tenancy: membership-based access re-checked on every request (non-member → 404), roles only from `business_members`, outlet restriction support, plan limits + premium gates enforced server-side with expiry applied immediately (`PlanCatalog::effective`), permission matrix (`Permissions`), platform admin only via `platform_admins` (CLI `bin/make-admin.php`).
* Endpoints: auth/*, me, me/sessions, businesses (list/create/show/update — billing fields untouchable), staff (list/invite/update/remove/resend), invites/accept, locations CRUD (delete = deactivate), admin/stats.
* Audit log for auth, business, staff, location events (no secrets recorded — tested).
* Docs: `13_TRUEHOST_DEPLOYMENT.md` (draft, unexecuted), ADRs D-15…D-20 in `11_ARCHITECTURE.md`, API spec aligned, gaps G-21/G-22.

**Tests executed (actual results)**
| Suite | Result |
|---|---|
| `vendor/bin/phpunit` (unit + MySQL 8.4.6 integration) | **OK — 91 tests, 730 assertions** (19 auth, 24 security/tenancy, 6 plan/permission, plus Phase 1 suites) |
| Mutation check (deliberately broke the code 6 ways: tenant check, mass-assignment guard, refresh-reuse revocation, platform-admin check, invite-status check, permission check) | **all 6 caught** by the suite; code restored and suite green |
| Live HTTP smoke (`php -S` + curl): register → verify → login → /me → tenant isolation (404) → plan tamper (422) → admin 404 → CLI admin grant → admin 200 | passed |
| Flutter | not touched this phase (Phase 1 results stand: analyze clean, 11 tests) |

**Security findings closed in the new backend (by test):** S-01, S-02 (no path can create an admin or set a role from input), S-05 (server-side role enforcement for staff/business/locations — remaining modules follow the same pattern in Phase 3), S-06 (plan columns untouchable), S-07 (plan limits server-side for staff/outlets; others when those modules exist), S-16 (invited e-mail grants nothing). Still open until their modules exist: S-03 (checkout), S-04 (payments), S-08, S-09, S-10, S-11, S-12, S-13.

**Not done / not verified**
* Auth is not yet used by the Flutter or Next.js clients (login screens arrive in Phase 5/7).
* `DELETE /me` (account deletion) and `GET /me/export`, avatar upload, phone verification (G-10), per-member outlet assignment endpoint (table exists, no API yet), platform-admin management beyond `stats`, cron entrypoint (`bin/cron.php`) — all scheduled for Phase 3.
* Argon2id is used here; bcrypt fallback path is not exercised by a test.
* Rate limiting is unit/integration tested for login throttling; per-IP route throttles are wired but only the per-account limit has a dedicated test.
* The Truehost guide has never been executed on a real host. Mail uses `mail()` (G-22) and is untested outside the file-mailer.
* Concurrency (two simultaneous refresh/invite/reset requests) is protected by atomic SQL (`UPDATE … WHERE used_at IS NULL`, `FOR UPDATE`) but tested sequentially, not with parallel processes.
* PHP runtime is still 8.3.6.

## Session 2 report (Phase 1) — summary kept for history
**Completed**
* `backend/`: front controller + `.htaccess`, `Env`, `Request` (body cap, JSON/Content-Type checks, trusted-proxy-only `X-Forwarded-For`), `Response` envelope, `Router` (private-by-default, explicit `public` flag, route table exposed for security tests), `Kernel` (error mapping, generic 500s, security headers, exact-match CORS, preflight), `Logger` (redaction), `Db` (strict PDO, UTC, `READ COMMITTED`, deadlock retry), `Migrator` (checksums, tamper detection, rollback, down-file requirement), `RateLimiter` (MySQL-backed, fails closed), `Money` (integer minor units, half-up), `GET /api/v1/health`, migration `0001_rate_limits`, `bin/migrate.php`, `bin/keygen.php`, `.env.example`.
* `apps/flutter/`: scaffold for android/ios/windows/macos/linux, `ApiClient` + `ApiException`, `AppConfig` (no production default), `Breakpoints`, role-aware navigation model, `AppShell` (bottom bar / rail / grouped sidebar), health provider, `tool/live_check.dart`.
* Docs: `11_ARCHITECTURE.md`, `12_DEVELOPMENT_GUIDE.md`, `apps/flutter/README.md`.
* Sandbox tooling installed under `/home/user/.dl` (not in the repo): **MySQL 8.4.6** (generic tarball, local socket), **Flutter 3.47.9 / Dart 3.13.7**, PHPUnit 11.5 via Composer.

**Tests executed (actual results)**
| Suite | Command | Result |
|---|---|---|
| PHP unit + MySQL 8.4.6 integration | `vendor/bin/phpunit` with `TEST_DB_*` | **OK — 42 tests, 96 assertions** |
| PHP unit only (no DB configured) | `vendor/bin/phpunit` | OK — 42 tests, 35 executed, **7 skipped** (DB tests report SKIPPED, never silently pass) |
| Flutter static analysis | `flutter analyze` | No issues |
| Flutter tests | `flutter test` | **All 11 passed** (API envelope parsing incl. 401/422/HTML-error/network failure; breakpoints; role-based nav; mobile/tablet/desktop shell layouts) |
| Live smoke | `php -S` + `bin/migrate.php up` + `dart run tool/live_check.dart` | health 200 `db: ok`; 404 and 405 use the error envelope; Flutter client parsed both from the real server |

**Defects found and fixed during the phase** (by the tests/smoke checks): migrator test errored instead of skipping without a DB; `X-Powered-By: PHP/8.3.6` was being sent (now removed + `expose_php=0`); Flutter client mislabelled malformed-JSON responses as network errors and lost the HTTP status (now decodes plain text itself).

**Not done / not verified**
* No platform build of the Flutter app (Android/iOS/Windows/macOS/Linux) — only analyze + tests. iOS/macOS cannot be built on Linux.
* PHP runtime here is 8.3.6, not 8.4 (code uses no 8.4-only syntax; CI on 8.4 pending).
* The rate-limit "two connections" test is sequential, not truly parallel; real concurrency tests arrive with checkout (T-POS-5).
* No authentication, tenancy or business endpoints exist yet — nothing in the audit's security list is closed yet; Phase 2 starts closing S-01/S-02/S-05/S-16.
* Production deployment guide for Truehost is not yet written (planned with Phase 2, when there is something to deploy).

## Outstanding owner actions (docs/09)
G-01 schema-only dump · G-02 data + `auth.users` export (only when ready for rehearsal) · G-03 which of 016–019 are applied · G-09 Flutterwave confirmation · G-10 SMS gateway · G-16 Truehost PHP facts (`phpinfo`) — and a **review of S-01/S-03/S-04/S-06 against the live project**, independent of this rebuild.

## Exact next task
Phase 3d — credit & repayments: `POST /sales/{id}/repayments` (atomic: lock customer → sale; amount ≤ balance; immutable rows; sale becomes `completed` at zero; loyalty earn on money received; customer `total_spent`; idempotent by `client_operation_id`; payment receipt document via the already-ported `fromPayment`), `GET /credit` (open/overdue, per-customer totals, 30-day overdue rule), `GET /customers/{id}/debt` and `/statement`, `GET /customers/{id}/sales`, and the "customer who owes money cannot be archived" guard. Tests: T-CRED-1 (bounds, over-payment rejected, concurrent repayments cannot exceed the balance — parallel processes), balance arithmetic against `credit/page.tsx` semantics, statement correctness, payment-document golden (already covered), S-XBIZ on every route, and exercising the edit/void paths against real repayment rows (S-10).
