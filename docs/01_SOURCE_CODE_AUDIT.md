# 01 — Source Code Audit

**Scope:** `umaremlyn/Track` (production LeenTrack web app), branch HEAD `b51572b` ("customer groups, loyalty points and the real spent amount (#16)").
**Method:** static reading only. No production access, no live database, no credentials were used. `Track` was not modified.
**Evidence level:** every statement below is from files in the repo. Anything inferred is labelled **[INFERRED]**; anything that needs the live database is labelled **[UNVERIFIED]**.

## 1. Repository layout (verified)

| Path | Size | Notes |
|---|---|---|
| `app/` | 37 files, ~15.4k lines | Next.js 14 App Router pages, all `"use client"`, all query Supabase directly from the browser |
| `components/` | 49 files, ~6k lines | UI + POS/sales/inventory dialogs |
| `lib/` | 27 files, ~3k lines | business maths, documents, offline DB, plan gating |
| `contexts/`, `hooks/` | auth + business contexts, branding/loyalty hooks | |
| `supabase/migrations/` | 21 files (001–013, 016–019 + 2 rollbacks) | **014 and 015 are missing**; 016–019 are headed `[NOT YET APPLIED]` |
| `supabase/functions/` | 4 Deno edge functions | `paystack-webhook`, `invite-staff`, `expire-plans`, `low-stock-alert` |
| `scripts/` | 000–015 legacy SQL | older, different schema generation |
| tests | **one** file: `lib/plan-limits.test.ts` (plain `assert`, no runner configured in `package.json`) | |

`middleware.ts` is a pass-through (`NextResponse.next()`); **there is no server-side route protection**. Auth is enforced only by a client `ProtectedRoute` component and Supabase RLS.

`lib/mock-auth.ts` (627 lines) and `lib/sample-data.ts` are **not imported anywhere** (grep of app/components/contexts/hooks) — dead code, not a feature.

## 2. Architecture as built

* Browser → Supabase directly (`@supabase/supabase-js`, anon key). ~178 distinct query/RPC/auth call sites (catalogued in `02`/`05`).
* Authorization = Postgres RLS + `SECURITY DEFINER` functions + **client-side UI gating**.
* Money-critical logic lives in Postgres functions (`complete_sale`, `edit_sale`, `receive_purchase_order`, loyalty triggers) and in duplicated TypeScript (`lib/sales/edit-math.ts`, `lib/loyalty/math.ts`, `lib/profit/compute.ts`).
* Billing: Paystack inline JS in the browser → webhook edge function updates `businesses`.
* Offline: `lib/offline-db.ts` (IndexedDB via `idb`).
* Documents (invoice/receipt/payment receipt): canvas rendering → hand-built PDF in the browser (`lib/documents/*`).

## 3. Schema drift between migrations and application (verified)

The repo's migrations **do not describe the production schema**. Evidence:

1. `001` creates `sales.sale_number` and `sales.payment_method` as a CHECK-constrained text; `003`'s `complete_sale` inserts `receipt_number`, `payment_status`, and casts to enum types `payment_method` / `payment_status` that no migration creates. `017`, `018` read `sales.payment_status::text` and `receipt_number`. → production `sales` has columns/enums defined outside the repo.
2. `003` says `ALTER COLUMN outlet_id DROP NOT NULL` on `sales`; `001` has no `outlet_id`. `002`/`008` do the same for `expenses.outlet_id`. `002` documents `expenses` and `expense_categories` as "already created" — their DDL is not in the repo.
3. `products.price`, `products.cost` (legacy duplicate price columns) are used by `019` (`coalesce(nullif(p.cost_price,0), nullif(p.cost,0), …)`) but are not in `001`. `item_type` is added in `013`.
4. `business.tax_rate` is read by POS and sales pages (`(business as any)?.tax_rate`) — **no migration defines it**.
5. `transfer_items`: migration `001` has `quantity`; app code inserts `quantity_requested`. `location_transfers`: `001` has `user_id`; app/`supabase.ts` use `created_by`, `transfer_number`, `shipped_at`, `received_at`.
6. `purchase_orders.status` CHECK in `004` is `draft|ordered|received|cancelled`; dashboard counts `status = 'sent'`.
7. `billing_history`: webhook writes `paystack_reference` (not in `001`).
8. `customers.group_id`, `customers.date_of_birth` used by app/`supabase.ts`, absent from `001`.
9. Helper functions `is_business_owner()` is referenced as "already in production" (016/018) but defined nowhere in the repo.
10. `lib/supabase.ts` hand-written `Database` types disagree with both of the above (e.g. `sales.sale_number`, no `payment_status`).
11. Legacy `scripts/004` defines `inventory_movements` and `stock_levels` (per-location stock). **No application code reads or writes them** (grep). They may or may not exist in production **[UNVERIFIED]**.
12. `scripts/013` defines `super_admins` + `is_super_admin()`; migration `012` instead uses `profiles.role = 'admin'`. The admin UI uses the `profiles.role` path. Which is live is **[UNVERIFIED]**.

**Consequence:** the PHP schema cannot be derived from migrations alone. A schema-only dump (`pg_dump --schema-only`) from production is a hard prerequisite for finalising the mapping — see `09_KNOWN_GAPS.md` G-01.

## 4. Security findings in the existing implementation

Severity: **C** critical, **H** high, **M** medium, **L** low. All verified by reading the cited file. None were exploited; no production system was touched.

| ID | Sev | Finding | Evidence | PHP rebuild response |
|---|---|---|---|---|
| S-01 | C | **Self-service platform admin.** `handle_new_user` copies `role` from client-controlled `raw_user_meta_data` into `profiles.role`; `is_admin()` trusts `profiles.role='admin'`. A sign-up passing `role:'admin'` in `signUp({options:{data}})` becomes platform admin with read access to all businesses, profiles, billing. `auth-context` also upserts `role: metadata.role` | `009_handle_new_user.sql`, `012`, `auth-context.tsx:155-170` | Platform admin is a separate `platform_admins` table; role never read from request |
| S-02 | C | **Owner can mint platform admins / rewrite any user's role.** `invite-staff` takes `role` from the body unvalidated, then service-role `profiles.upsert({role})` for the invitee, and for already-registered users overwrites their profile role and auth metadata (lookup by e-mail across `listUsers()`) | `invite-staff/index.ts` | Staff roles validated against enum; roles live on `business_members`, not on the user |
| S-03 | C | **`complete_sale` is `SECURITY DEFINER` with no authorization check** and takes `p_business_id`, `p_user_id` from the caller. Unless execute is revoked (migration 014 is missing) any authenticated user can write sales and decrement stock in any business. `complete_sale_with_points` checks access but then calls it with the caller-supplied `p_user_id` | `003`, `013`, `018` | Server derives user and business from the token; membership checked per request |
| S-04 | C | **Subscription grant trusts client metadata.** Webhook sets `plan` from `metadata.plan`/reference/amount-guess **without checking the amount paid matches the plan**, never calls Paystack verify, and accepts a `business_id` from metadata (any business) or falls back to matching a profile by e-mail. Pay the Growth price with `metadata.plan='business'` → Business plan | `paystack-webhook/index.ts` | Server creates the payment (price from server-side catalogue), verifies with the provider, matches amount + currency + reference, idempotent |
| S-05 | H | **Roles are UI-only.** Sidebar hides links by role, but RLS grants every business member full CRUD on products, customers, expenses, staff, suppliers, etc. A cashier can call PostgREST directly to delete staff, change prices, delete sales. Only `edit_sale` and branding/loyalty-settings check role in DB | `011`, `dashboard-sidebar.tsx` | Policy layer: every route checks a permission matrix server-side |
| S-06 | H | **Client can change plan/status/expiry of own business.** `businesses` UPDATE policy allows owner full-row update; `lib/subscription.ts` itself does `update({plan, plan_status:'active', plan_expiry:+30d})` from the browser. An owner can self-upgrade by a PostgREST call | `001`, `lib/subscription.ts` | Plan columns writable only by billing service / platform admin |
| S-07 | H | **Plan limits are client-side only** (`getLimit` / `isPremium` in page components); no DB enforcement | `plan-limits.ts` + page usage | Enforced in services (`PlanGuard`) |
| S-08 | H | **Duplicate / over-receiving of purchase orders.** `receive_purchase_order` sets `quantity_received = v_qty` (overwrite, not add), re-adds stock every call, and does not check `status`. Calling twice doubles stock. Also does not verify the item belongs to the order's business beyond the order check | `004` | Row-locked, status-checked, additive receiving with idempotency key |
| S-09 | H | **Offline sync bypasses the sale function.** `syncOfflineSales` does `supabase.from("sales").insert([sale])` — no items, no stock decrement, no idempotency, and the stored object shape is not the `sales` row shape. Offline checkout is not actually implemented end-to-end | `app/pos/page.tsx:303-320`, `offline-db.ts` | Proper operation-queue protocol (see `05`, `08`) |
| S-10 | H | **Repayments are not server-validated.** Over-payment cap and "mark completed" are decided in the browser; two repayments racing can exceed the balance; sale status update is a separate non-atomic write. Staff (not just owner) can insert; table is also UPDATE/DELETE-able by any member (`011`) so history can be silently edited/deleted | `credit/page.tsx:265-285`, `011` | Atomic repayment endpoint; repayments immutable (reversal entries only) |
| S-11 | H | **Financial history is deletable.** Members can DELETE sales, customers (SET NULL on sales), sale_items(`ON DELETE RESTRICT` on products is the only brake), expenses; admin page deletes businesses/profiles with a plain `.delete()` | `001`, `011`, `admin/page.tsx:337,359` | Soft-void with audit; hard delete restricted |
| S-12 | M | **Receipt numbers are client-generated** (`SALE-${Date.now()}-${random}`) — collision possible, non-sequential, forgeable | `payment-modal.tsx` | Server-issued per-business sequence; client op-ID for idempotency |
| S-13 | M | **Totals are client-computed.** `complete_sale` stores `p_subtotal/p_tax/p_discount/p_total/p_amount_paid` as sent; `unit_price` is client-sent (only `edit_sale` recomputes). A modified client can sell at any price/discount | `003`, `payment-modal.tsx` | Server recomputes everything from catalogue + rules |
| S-14 | M | `expire-plans` function is reachable with the public anon key (documented cron uses ANON key) and has `Access-Control-Allow-Origin: *`; idempotent but unauthenticated trigger | `expire-plans/index.ts` | Cron CLI entry, shared-secret, not HTTP-exposed |
| S-15 | M | `low-stock-alert` interpolates product names into HTML e-mail unescaped (stored HTML injection into owner's mailbox); `invite-staff` redirect URL built from client-supplied `app_url` and params (open-redirect/phishing vector) | both functions | Output escaping; allow-listed redirect origins |
| S-16 | M | Staff "invited" status already grants access (`has_business_access` accepts `status IN ('active','invited')` matched **by e-mail**). If the invitee's e-mail is registered by someone else first (unverified e-mail), that person gets access | `011` | Access only after verified acceptance of a signed single-use invite |
| S-17 | M | Profile avatar upload goes to a Supabase Storage bucket `avatars` with `upsert:true`; bucket policy not in repo **[UNVERIFIED]** | `profile/page.tsx:322` | Server-side validated upload |
| S-18 | M | Logo stored as base64 data-URL in DB (≤200 KB) — regexp-validated (good), but not content-sniffed | `016` | Keep the limit, add magic-byte verification |
| S-19 | L | `delete_user_and_data` swallows all errors (`RETURN FALSE`) and references tables that may not exist; client `delete-account.ts` deletes businesses/profile with the user's JWT then signs out (the auth user is not deleted from the client) | `scripts/008`, `lib/delete-account.ts` | Transactional account deletion with retention rules |
| S-20 | L | `user_sessions.session_token` stored client-supplied; sessions table is advisory — it does not actually revoke anything | `lib/user-sessions.ts` | Real server sessions, revocable |
| S-21 | L | Secrets in source: **none found** (anon key/URL read from env; Paystack **public** key `NEXT_PUBLIC_PAYSTACK_KEY`). A hard-coded production URL appears (`track.example.com`, `leentrack.vercel.app`) — not secret | greps | — |
| S-22 | L | `profiles.role` conflates *platform role* (`admin`) and *business role* (`owner/manager/cashier`) in a single user-level field, so a user cannot hold different roles in different businesses | schema | Separate concepts |

## 5. Business-logic findings that affect fidelity

* **"Revenue" on the dashboard is `SUM(total_amount)` over `completed`+`pending` sales** — it includes tax and unpaid credit. It is "billed value", not cash collected and not profit. The Profit page uses a different, correct definition (ex-tax, after-discount, minus per-item remembered cost). The rebuild must expose these as distinct, named metrics. (`dashboard/page.tsx:100-215`, `lib/profit/compute.ts`)
* **Inventory is not outlet-specific in the application.** Stock is a single `products.stock_quantity`. POS never sets `sales.outlet_id`. Transfers change **only** `location_transfers.status` (+timestamps); **no stock moves** (`transfers/page.tsx:114-121`). The requirement "a transfer must not create or destroy stock" is currently satisfied only vacuously. Per-location stock is a **new** capability requiring a decision (see `09` G-05).
* **Profit** uses `sale_items.unit_cost` (introduced by 019, not yet applied) with fallback to current `cost_price`; services excluded from "cost missing"; discounts allocated pro-rata to lines; sales without item rows are counted separately, not guessed. Rebuild must reproduce this exactly.
* **Customer `total_spent`** is maintained by `complete_sale` (+= total) and `edit_sale` (+= delta); 013 adds it on sale creation using `total_amount` (incl. unpaid). The latest commit message says "the real spent amount" — the live definition may differ **[UNVERIFIED]**.
* **No sale void/refund workflow exists in the UI**; `payment_status` enum has refund/void-like values per `017` ("cancelled or refunded") but nothing sets them. `edit_sale` records a "refund due" without moving money.
* **Staff are single-business** in the client (`maybeSingle()` on staff by user/email) while owners can create several businesses (`business-context.tsx` insert). "Multiple businesses per owner" exists; "multiple businesses per staff" does not.
* **Tax**: single percentage `business.tax_rate`, applied after discount on the whole cart; no per-item tax.
* **Currency**: profile-level currency stored in `localStorage`; formatting only (`utils/currency.ts`), no conversion. Billing supports NGN, GHS, KES, ZAR, EGP, USD, GBP, EUR; POS symbol map: NGN ₦, GHS ₵, KES KSh, ZAR R, XOF CFA, USD $.
* **Flutterwave**: the task brief says Paystack *and Flutterwave*. Only `provider` CHECK values include `'flutterwave'` (`001`); **no Flutterwave code exists in the repo** (grep). It is a gap, not a feature to port. See `09` G-09.
* **Phone verification** (`/verify-phone`) calls `supabase.auth.verifyOtp({type:'sms'})`; requires a Supabase SMS provider — not representable in shared-hosting PHP without choosing an SMS gateway (G-10).
* **Push notifications**: none exist in the web app; only e-mail low-stock alerts (Resend).
* **Loyalty** (018, not applied to prod per header): 1 point per `spend_per_point` (default ₦10,000) of money *received*, point worth `point_value` (default ₦100), fractional progress carried in the ledger, redemption via discount, max group discount 99%. Triggers are best-effort (errors swallowed).

## 6. What is NOT in the repository (cannot be audited)

Production data; production schema dump; migrations 014–015; `is_business_owner`; exact enum definitions; storage bucket policies; Supabase Auth configuration (e-mail templates, providers, password policy, SMTP); Vercel env vars; the live state of 016–019 (headers say not applied); Paystack dashboard configuration; any Flutterwave configuration.

## 7. Conclusion

The repo is a faithful, mostly-readable specification of **UI behaviour and money rules**, but a weak specification of the **database** and an **unsafe** specification of **authorization**. The PHP backend must (a) re-implement the rules in `04_BUSINESS_RULES.md`, (b) not reproduce S-01…S-16, and (c) wait for a schema dump before the importer is considered complete.
