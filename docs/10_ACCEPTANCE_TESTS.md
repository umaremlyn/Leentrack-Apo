# 10 — Acceptance Tests

A feature is complete only when its tests exist **and have been run with recorded results** (`PROJECT_STATUS.md` records actual pass/fail counts). IDs are stable. Types: **U** unit · **G** golden (output must equal values produced by the original TypeScript on the same input) · **I** API integration against MySQL · **S** security · **F** Flutter test · **W** web workflow · **M** migration.

Status log (authoritative record is `PROJECT_STATUS.md`):
* End of Phase 0: nothing written.
* End of Phase 2: **implemented and passing** — G-PLAN-1 (`PlanCatalogTest`), T-AUTH-1…5 (`AuthTest`), S-UNAUTH (route-table driven), S-XBIZ, S-ESC-STAFF, S-ESC-ADMIN, S-PLAN, S-TOKEN (partial: tampered/truncated/query/refresh-as-access/expired/revoked), S-SESSION (removal, deactivation, disabled user, password change/reset), S-IDOR for locations/staff/businesses, S-SQLI (auth + business name), error-leak test, audit-redaction test, M-AUTH fixture (legacy `$2a$` bcrypt). * End of Phase 3a: **implemented and passing** — T-INV-1 (CRUD, plan limits, barcode uniqueness, archive/restore, filters, import validate/commit, export), T-STK-1 (adjustments, per-outlet stock, invariants), S-STOCK, S-XBIZ for every catalogue/stock route, S-CSV (formula injection), valuation, outlet restriction, and a real **8-process concurrency test** (240 competing decrements, exactly the stock sold). * End of Phase 3b: **implemented and passing** — G-DISC-1 (2,268 vectors from the original TypeScript), G-LOY-1 (worked examples + 500 randomised sequences: balance never negative, progress in [0, rate), money conserved), T-CUST-1 (CRUD, validation, plan limit, archive/restore, filters, group rules, cashier/manager/owner permissions), S-XBIZ for every customer/group/loyalty route, counters-not-writable tests, ledger = balance invariant, redeem guards, and a 6-process parallel test (120 earns → exactly 120 points). * End of Phase 3c: **implemented and passing** — G-EDIT-1 (700 vectors from the original `edit-math.ts`), G-DOC-1 (documents, payment documents, all helpers and every share message character-for-character), T-POS-1…6 (server-side recomputation, refused client money, atomic rollback, credit rules, group/points/loyalty, idempotent replay, per-business op ids), T-EDIT (rules, history, refund, stale screens, customer requirement, unavailable items), void, branding validation, per-outlet stock and restriction, S-XBIZ for all sale routes, plus **real parallel-process tests**: 8 identical submissions → exactly 1 sale; 8 devices racing for 5 units → 5 sold / 3 refused; 6 workers × 5 sales with opposite product order on one customer → no deadlock. * End of Phase 3d: **implemented and passing** — T-CRED-1 (balance arithmetic, completion at zero, loyalty/spend on repayments, bounds, idempotent replays with exact historical balances, roles, S-XBIZ on every new route, outlet restriction), parallel-process tests (8 simultaneous 30.00 payments on a 100.00 debt → exactly 3 accepted; 5 simultaneous pay-offs → exactly 1; 6 identical retries → 1 row), statement reconciliation (closing = live debt), credit-list rules from `credit/page.tsx` (sorting, 14/30-day badges, overdue totals), archive guard, and **edit / void against real repayments (S-10)** with a money-received invariant (`total_spent` = Σ money received) asserted after every scenario. Everything else below is still **not written**.

## A. Golden parity (derived from the source — prevents regressions in business meaning)

| ID | Test | Source oracle |
|---|---|---|
| G-PLAN-1 | `normalizePlan`, `getLimit`, `isPremium` table incl. legacy names, null, `expired` | `lib/plan-limits.ts` + existing `plan-limits.test.ts` assertions |
| G-DISC-1 | `checkoutDiscounts` over a grid (group 0/10/99/150 %, manual, points, caps) | `lib/loyalty/math.ts` |
| G-EDIT-1 | `computeEdit` incl. tax rate recovery (7.5 %, odd ratio, zero tax) | `lib/sales/edit-math.ts` + SQL rules in 017 |
| G-PROF-1 | `computeProfit` fixtures: services, missing cost, no-item sales, discounts pro-rata, snapshot vs current cost | `lib/profit/compute.ts` |
| G-PROF-2 | `periodRange` boundaries incl. month ends, DST-less Lagos zone | same |
| G-DOC-1 | `documentNumber`, `statusFor`, `paymentMethodLabel`, `whatsappNumber`, `fileNameFor`, `shareMessage` | `lib/documents/model.ts` |
| G-PRICE-1 | Billing totals for 3 cycles × 8 currencies × 2 plans equal `billing-client.calcTotal` and Paystack kobo amounts | `billing-client.tsx`, webhook table |
| G-LOY-1 | Loyalty earn/progress/redeem sequences vs SQL logic of `loyalty_apply` | 018 |

Harness: `tools/golden/` runs the unmodified TS (via Node 22 type-stripping or `tsc`) and emits JSON; `backend/tests/Golden` asserts PHP output equals it (exact string equality at 2 dp).

## B. Functional (API, with DB)

| ID | Scenario |
|---|---|
| T-AUTH-1 | register creates user+business(free)+owner membership; body `role`/`plan` ignored |
| T-AUTH-2 | login ok / wrong pw / unknown email (same response + similar time) / throttled after N |
| T-AUTH-3 | legacy bcrypt user logs in once, hash upgraded, legacy hash nulled; second login uses new hash |
| T-AUTH-4 | refresh rotation; replay of old refresh revokes family; logout; logout-all; expired access |
| T-AUTH-5 | forgot/reset single-use, expiry, session revocation, no enumeration |
| T-POS-1 | checkout full payment: totals recomputed server-side, stock decremented, services untouched, customer stats, unit_cost snapshot |
| T-POS-2 | tampered client totals/price rejected (422) |
| T-POS-3 | credit sale requires customer; pay-now bounds; status pending/completed |
| T-POS-4 | insufficient stock → 409 `insufficient_stock`, nothing written (rollback proven) |
| T-POS-5 | same `client_operation_id` twice (sequential and 20 concurrent) → exactly one sale |
| T-POS-6 | group discount cap 99 %, points redeem, ledger rows |
| T-EDIT-1…8 | each rule in 04 §6 (who, status, stale revision, stale paid, stock delta, refund, extra payment, balance needs customer) |
| T-CRED-1 | repayment bounds; over-payment rejected; concurrent repayments cannot exceed balance; completes sale at 0 |
| T-INV-1 | product CRUD, plan limit, barcode uniqueness, archive-if-referenced, CSV import validate/commit, export injection-safe |
| T-STK-1 | adjustments + movements consistent; `Σ levels = stock_quantity` invariant |
| T-PO-1 | receive additive, partial → `partially_received`, over-receive rejected, second identical request idempotent, cross-business item rejected |
| T-TRF-1 | transfer conserves stock across dispatch/receive/cancel; variance explicit |
| T-EXP-1 | expense CRUD/filter; categories |
| T-DASH-1 | dashboard metrics equal independent SQL recomputation on a seeded dataset; revenue ≠ profit; named metrics |
| T-RPT-1 | each report vs seed; date/outlet filters; CSV |
| T-BILL-1 | server catalogue; checkout creates payment; verify activates only on match; plan expiry cron; downgrade; history |
| T-ADM-1 | admin endpoints only for platform admins; actions audited |
| T-DOC-1 | receipt/invoice JSON+PDF contains branding, balance, status pill; logo limits |
| T-ACCT-1 | export contains user's data only; deletion grace and purge |

## S. Security (all mandatory — brief §7)

| ID | Attack |
|---|---|
| S-UNAUTH | every route without/with garbage token → 401 (route-table driven test; fails if a route is missing from the allow-list of public routes) |
| S-XBIZ | for **each** resource: user of business A reads/updates/deletes business B's id → 404 (generated from route table) |
| S-XOUT | outlet-restricted member cannot read/write other outlets' data |
| S-ESC-STAFF | cashier calls manager/owner endpoints; body `role` tampering; changing own membership role |
| S-ESC-ADMIN | register/invite/update-profile with `role:admin`; hitting `/admin/*` as owner; `platform_admins` insertion only via CLI |
| S-PLAN | PATCH business with `plan`, `plan_expiry`, `plan_status` → 422; direct subscription mutation impossible |
| S-PAY-FORGE | webhook with bad/missing signature → 401 and no state change; valid signature but wrong amount/currency/unknown ref → `needs_review`, no activation |
| S-PAY-DUP | same webhook ×N and verify+webhook race → one activation, one billing row |
| S-PAY-META | paying Growth price with `plan=business` metadata → Growth (or rejected) |
| S-DUP-SALE | see T-POS-5, plus offline replay of the same op after crash |
| S-STOCK | cashier `POST /stock/adjustments` → 403; cross-business product id → 404 |
| S-PO | cashier receive → 403; foreign order → 404; double receive no double stock |
| S-IDOR | sequential/UUID guessing on sales, customers, receipts, documents, uploads, exports |
| S-TOKEN | tampered, truncated, expired, revoked, other-device tokens; token in query string rejected |
| S-SESSION | password change/reset revokes sessions; removed staff loses access immediately |
| S-UPLOAD | SVG, polyglot GIF/PHP, double extension, oversize, traversal in filename, zip bomb image dimensions |
| S-SQLI | injection corpus on every filter/sort/search param (no 500s, no data change) |
| S-RATE | login/register/forgot/webhook/sync endpoints enforce limits with `429` + `Retry-After` |
| S-CORS | disallowed Origin gets no ACAO; preflight limited |
| S-HEADERS | security headers present; no stack/SQL in any error body (fuzz) |
| S-CSV | exported cells beginning with `=,+,-,@` neutralised |
| S-SECRETS | repo scan (gitleaks-style) clean; `.env` not under webroot (deployment check script) |

## C. Offline / sync (Phase 4)

| ID | Scenario |
|---|---|
| SYN-1 | queue survives app kill/restart/device reboot (SQLite durability test) |
| SYN-2 | batch delivered twice (network drop after server commit) → no duplicates |
| SYN-3 | two devices sell last unit offline → both recorded, `oversold` conflict raised, visible in app, resolvable |
| SYN-4 | price changed server-side while offline → `price_changed` flag, sale kept |
| SYN-5 | membership revoked while offline → `permission_revoked`, ops held for review not applied silently |
| SYN-6 | clock skew ±2 h, ±3 days; future-dated op clamped/flagged |
| SYN-7 | pull cursor: tombstones, pagination, resume after partial apply |
| SYN-8 | retry/backoff schedule; failed op manual retry; status screen lists failed/unresolved |
| SYN-9 | reconciliation job detects injected drift |

## D. Flutter (Phase 5/6)

F-LOGIN/LOGOUT, F-NAV (mobile bottom nav / desktop sidebar), F-DASH, F-PROD, F-POS (cart maths equals golden vectors; checkout online/offline), F-SYNC-UI, F-CUST, F-CRED, F-PRINT (receipt bytes ESC/POS snapshot + PDF), F-SCAN (unit-level; hardware test checklist), F-RESP (golden screenshots at 360×800, 768×1024, 1280×800, 1920×1080), F-A11Y basic.

## E. Web (Phase 7) — same workflow list executed twice (backend=supabase, backend=php) against non-production targets

W-LOGIN, W-REGISTER, W-DASH, W-POS, W-INV, W-CAT, W-SALES (+edit), W-CREDIT, W-CUST, W-EXP, W-PROFIT, W-REPORTS, W-PURCH, W-TRF, W-LOC, W-STAFF (invite→accept), W-SETTINGS, W-BILLING (sandbox), W-ADMIN, W-PROFILE (sessions/export/delete). Visual regression screenshots on key pages show no unintended UI change.

## M. Migration (Phase 8; fixture-only until real export exists)

| ID | Check |
|---|---|
| M-ROW | counts source = imported + quarantined |
| M-FK | zero orphans |
| M-MONEY | per-business totals identical (decimal-exact) |
| M-PROFIT | PHP profit == original `computeProfit` for all businesses/periods |
| M-CREDIT | open balances per customer identical |
| M-STOCK | Σ levels = stock_quantity; per-product equal |
| M-IDEMP | running import twice yields identical DB state |
| M-RESUME | kill mid-run, resume → complete & consistent |
| M-DELTA | modified/added/deleted source rows after baseline reflected |
| M-AUTH | fixture legacy hashes (locally generated `$2a$`) verify and upgrade |
| M-ROLLBACK | rollback of a run removes only that run's rows |
| M-LOG | log scan: no hashes, tokens, passwords, raw PII |

## F. Release gates (all must be true)

Feature = working client + API + persistence + authN + authZ + validation + error handling + tests (run, recorded) + docs. No mocked/hard-coded data paths. No open Critical/High security findings. Migration rehearsal reconciliation PASS signed by owner. Rollback rehearsed.


## Phase 3e — implemented tests
`ProfitGoldenTest` (196 vectors from the original TS), `FinanceTest` (10 tests, 303 assertions): expense CRUD/validation/soft delete, roles + S-XBIZ on every new route, filters/summary/pagination, categories (duplicate, in-use, seed), outlet-restricted manager, profit hand-calculation (discount allocation, voids, deleted expenses, missing cost, no-item sales, history not rewritten), time-zone boundaries, dashboard vs independent SQL, credit pinned to `/credit`, local-day series, top products by id, outlet filter, empty business.


## Phase 3f — implemented tests (`PurchaseTest`)
Supplier CRUD/archive/restore/search/validation · roles + S-XBIZ on all 15 routes · server-side totals/numbering and 20+ validation cases (forged totals/status/number, duplicates, services, overflow) · draft edit/delete/place rules · partial → complete receiving with exact stock, movements and invariants · **S-08**: second delivery, over-receipt, foreign item ids, all-or-nothing, no receipt row left by refusals · status machine (cancel/close/final states) · outlet landing + opt-in cost update · archived product rollback · idempotency (replay, header key, conflicts on content and on another order) · outlet-restricted manager · list filters + dashboard tile · **real parallel processes**: 8×3 competing deliveries against 20 ordered → exactly 4 accepted; 8 identical retries → 1 applied; receiving racing with sales never oversells.


## Phase 3g — implemented tests (`TransferTest`)
Full flow with stock/movement checks and conservation · partial shipment + shortfall variance (view and audit) · all-or-nothing dispatch with insufficient stock · bounds, status machine, cancel (in-transit return) · create/edit validation incl. forged fields · idempotency (replay, content conflict, other transfer) · roles, S-XBIZ, outlet-restricted manager · list filters · **parallel processes:** 8 transfers racing for 10 units (4 each) → exactly 2 leave; 8 duplicate dispatches → 1 applied; 8 duplicate receives with one op id → 1 applied + 7 replays; 8 opposite-direction two-product transfers dispatched and received concurrently → no deadlock, nothing created or destroyed.


## Phase 3h — implemented tests (`ReportTest`)
Permissions, plan gate for CSV, unknown reports/filters, tenant isolation · sales report vs independent SQL (voided excluded from totals, local-time dates, paging vs full CSV, CSV escaping and formula-injection neutralisation) · profit/products/outlets pinned to `/profit`, outlets reconcile with unallocated expenses · profit unknown ≠ 0 · inventory pinned to `/stock/valuation` and the dashboard counts (boundary, inactive, service rows, outlet scoping, CSV) · expenses/credit/purchases pinned to their modules (deleted expense, formula-looking text, drafts/cancelled excluded, received value) · outlet-restricted manager · empty business.


## Phase 3i + gap fixes — implemented tests
* `AccountTest` (9): outlet assignment (takes effect next request, owner never restricted, cross-business, plan gate) · weak legacy password flag set/cleared and not cleared by a silent re-hash · personal export has no secrets · business export paged (1,005 rows), owner-only, no other business's rows, every section reachable · deletion rules (wrong password/phrase, owner with team, platform admin), sessions revoked, pending state revealed only to the right password, cancel, cancelled accounts never purged · **purge removes every row of the owner's business in every table (checked against information_schema) and leaves another business untouched except the removed membership; the user is anonymised, sales by that user in another business survive; idempotent; e-mail reusable** · completeness guard for new tables · avatar upload validation (SVG/PHP/GIF/oversize/polyglot rejected), replacement, removal, isolation.
* `GapFixesTest` (12): foreign outlet → 404 on 9 routes · purchase/transfer date filters in business time zone · customers report + CSV injection · supplier payments (no prepayment, bounds, balances, statement, report, idempotency, roles, tenants) with an 8-process race (100 owed, 30 each → exactly 3 accepted) · SMTP (AUTH PLAIN and LOGIN, dot-stuffing, header-injection refusal, failures logged without the password, driver selection) against a local fake SMTP server · report row cap · supplier_return.
* `PdfRendererTest` (4) + PDF assertions in `SalesTest`: xref offsets verified byte-for-byte, escaping, pagination, hostile text cannot terminate the stream; output parsed by `pdftotext` (skipped if absent).
