# 05 — API Specification (v1 draft, contract-first)

Base: `https://api.<domain>/api/v1` · JSON only (`application/json; charset=utf-8`) · UTC ISO-8601 timestamps · money as **strings** with 2 dp (`"1500.00"`) to avoid float drift · ids are UUID strings (source ids preserved).

This is the single contract for Flutter **and** the Next.js app (`lib/api-client.ts` in Phase 7). It is derived from the 178 Supabase call sites; section 9 maps each source operation to its endpoint so nothing is dropped. Status: **design** — endpoints become "implemented" only when the PHP code, tests and docs exist (tracked in `PROJECT_STATUS.md`).

## 1. Conventions

**Envelope**
```json
{ "ok": true,  "data": { ... }, "meta": { "page": 1, "per_page": 50, "total": 123, "request_id": "..." } }
{ "ok": false, "error": { "code": "validation_failed", "message": "Human text", "fields": { "email": ["invalid"] } }, "meta": { "request_id": "..." } }
```
Error codes → HTTP: `validation_failed` 422 · `unauthenticated` 401 · `token_expired` 401 · `forbidden` 403 · `not_found` 404 (also used for cross-tenant ids — never 403, to avoid id probing) · `conflict` 409 · `stale_resource` 409 · `insufficient_stock` 409 · `plan_limit_reached` 402 · `feature_not_in_plan` 402 · `rate_limited` 429 (+`Retry-After`) · `server_error` 500 (generic text only).

**Auth:** `Authorization: Bearer <access_token>` (opaque, 15 min, SHA-256 stored). Refresh token (opaque, 30 d, rotating, family-revocation on reuse). Business context: `X-Business-Id: <uuid>` header, verified against `business_members` on **every** request; outlet context optional `X-Outlet-Id`. Device: `X-Device-Id` (client-generated stable UUID) required for sync endpoints.
**Idempotency:** mutating money endpoints accept `Idempotency-Key` header (or `client_operation_id` body field); replays return the stored result.
**Pagination:** `?page=1&per_page=50` (max 200) and `?cursor=` for large feeds; `Link`-style info in `meta`. **Filtering:** documented per endpoint; unknown filters rejected (422). **Sorting:** `?sort=-created_at`.
**Versioning:** URI major version; additive changes only within v1; `X-API-Version` response header.
**CORS:** allow-list from env (`CORS_ALLOWED_ORIGINS`), no wildcard, credentials not used (bearer tokens).

## 2. Auth & account

| Method | Path | Notes |
|---|---|---|
| POST | `/auth/register` | `{email,password,full_name,business_name,business_type,currency,phone?,address?}` — `role`, `plan`, `status` and any unknown field are **rejected with 422**; always responds `202` with a generic message (no account enumeration); creates user, business (plan `free`), owner membership, default "Main" outlet, sends verification e-mail; login requires a verified e-mail |
| POST | `/auth/login` | `{email,password,device:{id,name,platform}}` → `{access_token,refresh_token,expires_in,user,memberships[]}`; legacy-hash transition path (06) |
| POST | `/auth/refresh` | rotating refresh |
| POST | `/auth/logout` | revokes current session; `?all=1` revokes all |
| POST | `/auth/forgot-password` | always 202 (no account enumeration) |
| POST | `/auth/reset-password` | `{token,password}` single-use, 60 min |
| POST | `/auth/verify-email` · `/auth/resend-verification` | OTP/link |
| POST | `/auth/verify-phone` · `/auth/resend-phone-code` | **blocked on SMS provider decision (G-10)** |
| GET/PATCH | `/me` | profile incl. avatar upload `POST /me/avatar` (multipart, validated) |
| POST | `/me/change-password` | requires current password; revokes other sessions |
| GET | `/me/sessions` · DELETE `/me/sessions/{id}` | real sessions |
| GET | `/me/export` | async-safe JSON export (queued by cron if large) |
| DELETE | `/me` | `{password,confirm}` — retention rules in 07 |
| POST | `/invites/accept` | `{token,password?,full_name?}` — signed single-use staff invite (replaces `/auth/callback?type=invite`) |

## 3. Business & settings

`GET /businesses` (memberships) · `POST /businesses` (plan-limited) · `GET/PATCH /businesses/{id}` (name, currency, tax_rate… — **never plan fields**) · `GET/PUT /businesses/{id}/branding` (owner write) · `GET/PUT /businesses/{id}/loyalty-settings` (owner write) · `GET/PUT /businesses/{id}/settings` (key allow-list: low-stock alert keys) · `POST /businesses/{id}/low-stock-alert` (`force`) · `DELETE /businesses/{id}` (owner, typed confirmation, soft-delete + grace).
Locations: `GET/POST /locations`, `GET/PATCH/DELETE /locations/{id}` (delete blocked if stock/transfers exist → deactivate).

## 4. Catalogue & stock

* Categories `GET/POST /categories`, `PATCH/DELETE /categories/{id}`.
* Products `GET /products?search=&category_id=&type=&active=&low_stock=1&barcode=&location_id=`, `POST`, `GET/PATCH/DELETE /products/{id}` (delete = archive if referenced), `POST /products/import?mode=validate|commit[&location_id=]` (body `text/csv`, or JSON `{"csv":"..."}`; ≤2 MB, ≤2000 rows, UTF-8; same column aliases and row rules as the web dialog; all-or-nothing commit; plan limits enforced; response lists per-row errors), `GET /products/export` (CSV with BOM, formula-injection-safe, plan-gated `exportReports`, needs `reports.read`).
* Stock (NEW) `POST /stock/adjustments` `{product_id,location_id,delta|new_quantity,reason}`, `GET /stock/movements?product_id=&location_id=&from=&to=`, `GET /stock/levels?location_id=`, `GET /stock/valuation?method=cost|retail`.
* Transfers `GET/POST /transfers`, `GET /transfers/{id}`, `POST /transfers/{id}/approve|dispatch|receive|cancel` (receive takes per-item `quantity_received`).

## 5. Sales, customers, credit

* **Checkout** `POST /sales` — body:
```json
{ "client_operation_id":"uuid", "outlet_id":"uuid|null", "customer_id":"uuid|null",
  "items":[{"product_id":"uuid","quantity":2}],
  "discount":{"manual":"0.00","apply_group":true,"points_redeemed":0},
  "payment":{"mode":"full|credit","method":"cash","amount":"3000.00"},
  "notes":null, "client_created_at":"2026-11-02T10:00:00Z" }
```
 Server returns the **authoritative** sale (totals, receipt_number, balance, change, points earned). Totals sent by a client are not accepted fields (rejected if present).
* `GET /sales?from=&to=&customer_id=&outlet_id=&status=&method=&search=`, `GET /sales/{id}` (items, repayments, edits, `can_edit`, `paid_so_far`, `revision`), `POST /sales/{id}/edit` (rules §6 of 04; body `{items,discount,extra_paid,extra_method,reason,expected_revision,expected_paid}`), `POST /sales/{id}/void` / `/refund` (NEW; permission `sales.void`), `GET /sales/{id}/document?kind=receipt|invoice&format=json|pdf`.
* Customers `GET/POST /customers`, `GET/PATCH/DELETE /customers/{id}` (archive), `GET /customers/{id}/sales`, `/loyalty-ledger`, `/statement?from=&to=`; Groups `GET/POST /customer-groups`, `PATCH/DELETE /customer-groups/{id}` (write: owner/manager).
* Credit `GET /credit?status=open|overdue&customer_id=`, `POST /sales/{id}/repayments` `{amount,method,notes,client_operation_id}` → `{balance,status,receipt}`; `GET /customers/{id}/debt`.

## 6. Money-out & procurement

Expenses `GET/POST /expenses`, `PATCH/DELETE /expenses/{id}`, categories `GET/POST /expense-categories`, `DELETE`. Suppliers `GET/POST /suppliers`, `PATCH/DELETE`. Purchases `GET/POST /purchase-orders`, `GET/PATCH /purchase-orders/{id}` (draft only), `POST /purchase-orders/{id}/order|cancel|receive` (`{items:[{item_id,quantity}],client_operation_id}`), `DELETE` (draft only).

## 7. Analytics

`GET /dashboard?from=&to=&outlet_id=` → distinct named metrics: `revenue_billed`, `revenue_collected`, `tax_collected`, `cogs`, `gross_profit`, `expenses`, `net_profit`, `credit_outstanding`, `credit_overdue`, `inventory_value_cost`, `inventory_value_retail`, `low_stock_count`, `out_of_stock_count`, `recent_sales[]`, `top_products[]`, `series[]`.
`GET /reports/{sales|profit|inventory|expenses|credit|purchases|outlets|products}?from=&to=&outlet_id=&format=json|csv`. `GET /profit?period=today|week|month|lastMonth|custom` returns `ProfitResult` shape (`summary`, `bySale`, `byProduct`) identical to `lib/profit/compute.ts`.

## 8. Team, billing, admin, ops

* Staff `GET/POST /staff` (creates an invite; the link is **e-mailed only, never returned to the inviter**), `PATCH/DELETE /staff/{id}`, `POST /staff/{id}/resend-invite`; roles validated against enum; outlet assignment `PUT /staff/{id}/outlets` (NEW).
* Billing `GET /billing/plans` (server catalogue), `GET /billing/subscription`, `POST /billing/checkout` `{plan,cycle,currency,provider}` → provider init `{reference,authorization_url|access_code}`, `GET /billing/verify?reference=` (server-side provider verify; **the only path that can activate**), `GET /billing/history`, `POST /billing/cancel`. Webhooks (unauthenticated, signature-verified): `POST /webhooks/paystack`, `POST /webhooks/flutterwave` (**only after G-09 is resolved**).
* Admin (platform_admins only) `GET /admin/stats|businesses|users|payments|audit-logs`, `PATCH /admin/businesses/{id}/plan|status`, `POST /admin/notices`, `DELETE /admin/users/{id}` (soft, audited, step-up confirmation).
* Sync `POST /sync/push` (batch ≤50 ops), `GET /sync/pull?since=<cursor>&entities=products,customers,…`, `GET /sync/status`, `POST /sync/ops/{id}/resolve` — protocol in §10.
* Ops `GET /health` (no secrets), CLI cron entrypoints (not HTTP): `bin/cron.php expire-plans|low-stock|cleanup|reconcile`.

## 9. Source-operation → endpoint traceability (every Supabase call site)

| Source call (file) | Endpoint |
|---|---|
| `auth.signUp/signInWithPassword/signOut/resetPasswordForEmail/updateUser/verifyOtp/resend/getSession/exchangeCodeForSession` (auth-context, register, login, forgot/reset/verify-*, auth/callback, settings) | `/auth/*`, `/me/change-password`, `/invites/accept` |
| `profiles` select/upsert/update (auth-context, profile) | `/me` |
| `storage.avatars` (profile) | `/me/avatar` |
| `businesses` select/insert/update (business-context, auth-context, profile, lib/subscription) | `/businesses*` (plan fields excluded) |
| `staff` select/insert/update/delete + `functions.invoke('invite-staff')` (staff, contexts, callback) | `/staff*`, `/invites/accept` |
| `locations` CRUD (locations, transfers) | `/locations*` |
| `categories` CRUD (categories, inventory, pos, csv-import) | `/categories*` |
| `products` CRUD / select (inventory, pos, transfers, purchases, reports, dashboard, low-stock-banner, edit-sale-dialog, payment-modal stock check) | `/products*` |
| `customers`, `customer_groups` (customers, pos, customer-select, payment-modal points re-read) | `/customers*`, `/customer-groups*` (points read-back returned inside checkout response) |
| `loyalty_ledger`, `loyalty_settings` (customers, use-loyalty-settings) | `/customers/{id}/loyalty-ledger`, `/businesses/{id}/loyalty-settings` |
| `rpc complete_sale / complete_sale_with_points` (payment-modal) | `POST /sales` |
| `from('sales').insert` offline (pos) | `POST /sync/push` (op `sale.create`) |
| `sales` select (sales, credit, dashboard, reports, profit/load) | `/sales`, `/credit`, `/dashboard`, `/reports/*`, `/profit` |
| `sale_items` select (sales, reports, dashboard, profit/load) | embedded in the above |
| `rpc sale_payment_info`, `rpc edit_sale` (sales, edit-sale-dialog) | `GET /sales/{id}`, `POST /sales/{id}/edit` |
| `debt_repayments` select/insert + sales status update (credit, sales) | `POST /sales/{id}/repayments` |
| `expenses`, `expense_categories` (expenses, dashboard, profit/load) | `/expenses*`, `/expense-categories*` |
| `suppliers`, `purchase_orders`, `purchase_order_items`, `rpc receive_purchase_order` (purchases, dashboard) | `/suppliers*`, `/purchase-orders*` |
| `location_transfers`, `transfer_items` (transfers) | `/transfers*` |
| `settings` upsert/select + `functions.invoke('low-stock-alert')` (settings) | `/businesses/{id}/settings`, `/low-stock-alert` |
| `business_branding` (use-business-branding) | `/businesses/{id}/branding` |
| `billing_history` (profile, billing, lib/billing, admin) | `/billing/history`, `/admin/payments` |
| Paystack inline + `paystack-webhook` | `/billing/checkout`, `/billing/verify`, `/webhooks/paystack` |
| `audit_logs` (lib/audit-logs, admin) | server-side `AuditService`; `/admin/audit-logs` |
| `user_sessions` (lib/user-sessions) | `/me/sessions` |
| `delete-account.ts`, `export-user-data.ts` | `DELETE /me`, `GET /me/export` |
| admin page: businesses update/delete, profiles delete, audit insert | `/admin/*` |
| `expire-plans` function | `bin/cron.php expire-plans` |

## 10. Offline synchronisation protocol

**Client queue row** (SQLite): `op_id (uuid v4, PK)`, `business_id`, `outlet_id`, `device_id`, `user_id`, `type`, `created_at` (device clock, UTC), `payload` (JSON), `status` ∈ `pending|sending|synced|conflict|failed|rejected`, `attempts`, `next_retry_at`, `last_error`, `server_ref`.
**Types:** `sale.create`, `sale.repayment`, `customer.create`, `expense.create`, `stock.adjust` (conflict-prone, restricted), others read-only offline.
**Push:** `POST /sync/push {device_id, ops:[{op_id,type,created_at,payload}]}` → per-op result `{op_id, result:"applied|replayed|conflict|rejected", server_ref, detail}`. Server stores each `op_id` in `sync_operations` (UNIQUE(business_id, op_id)) in the **same transaction** as its effect → exactly-once effect, at-least-once delivery. HTTP 200 for the batch even if some ops conflict.
**Offline sale semantics:** the sale happened; it is **never discarded**. Server revalidates prices? No — an offline sale is accepted at the **price the customer was quoted** when it is within a configurable tolerance of the catalogue price, else flagged `price_changed` for review but still recorded (the till already took the money). Stock: if stock is insufficient at sync time the sale is still recorded and stock goes **negative-flagged**: `stock_levels.quantity` may drop below 0 only via `sync` ops, producing an `oversold` conflict ticket (owner resolves: adjust stock, or void). This is the explicit answer to "two devices sell the last unit". Normal online checkout still refuses to oversell.
**Conflict types:** `oversold`, `price_changed`, `product_deleted`, `customer_missing`, `plan_limit`, `permission_revoked`, `period_closed`. Each yields a `sync_conflicts` row visible in `GET /sync/status` and in-app "Sync issues" screen with resolve actions; never silently dropped, never overwritten.
**Pull:** monotonically increasing `change_seq` per business (`entity_changes` table); `GET /sync/pull?since=n` returns upserts/tombstones for allow-listed entities, paginated; client applies in one SQLite transaction and stores the new cursor.
**Reconciliation:** nightly `reconcile` cron recomputes `products.stock_quantity` vs `Σ stock_levels` vs `Σ stock_movements` and reports drift (never auto-corrects money).
**Clock skew:** `created_at` is advisory (stored as `client_created_at`); ordering and reports use server `received_at` for audit and `client_created_at` for the business date of the sale, clamped to ≤ now+5 min and ≥ now−30 days (beyond → needs owner approval).


> **Phase 3a as built:** products/categories/stock endpoints follow §4 with these specifics — `DELETE /products/{id}` archives (restore via `POST /products/{id}/restore`); product `PATCH` rejects `stock_quantity`; list filters `search, category_id, type, active, barcode, stock_status=low|out|ok, archived, sort=name|created_at|selling_price|stock_quantity, dir, page, per_page` (unknown filters → 422); `cost_price` is omitted for roles without `products.cost.read` (cashiers); outlet-restricted members see stock only for their outlets.
