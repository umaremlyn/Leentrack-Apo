# 02 — Feature Inventory

Legend — **Status in source**: ✅ implemented and wired · 🟡 partial / UI-only / inconsistent · ❌ not present in the repo (requested in the brief but not found) · ❓ cannot be determined without production.
Every ✅/🟡 row cites the file that proves it. Sidebar roles come from `components/dashboard-sidebar.tsx`.

## A. Authentication & accounts

| Feature | Status | Evidence / notes |
|---|---|---|
| Register (e-mail + password, business name/type, currency, phone, address, plan) | ✅ | `app/register`, `auth-context.signUp` → `supabase.auth.signUp` with user metadata; DB trigger `handle_new_user` creates profile + default business (plan from metadata, default `starter` in trigger / `free` in client fallback — **inconsistent**) |
| Login | ✅ | `signInWithPassword` |
| Logout | ✅ | `signOut` |
| Forgot / reset password | ✅ | `resetPasswordForEmail`, `/reset-password` uses `getSession` + `updateUser({password})` |
| E-mail verification | ✅ | `/verify-email` OTP via `verifyOtp` (+ resend) |
| Phone verification | 🟡 | `/verify-phone` `verifyOtp({type:'sms'})`; needs Supabase SMS provider **[UNVERIFIED]** |
| Auth callback (code exchange, invite/recovery/signup types) | ✅ | `/auth/callback` |
| Profile (name, phone, address, currency, avatar upload to storage) | ✅ | `app/profile` |
| Notification preferences | 🟡 | toggles in profile (`low_stock_alerts`, …) — where persisted is unclear; only low-stock e-mail has a backend |
| Session list ("View Sessions") | 🟡 | `lib/user-sessions.ts` CRUD on `user_sessions`; advisory, no revoke effect |
| Account deletion | ✅ | `lib/delete-account.ts` (client deletes businesses+profile, signs out) |
| User data export | ✅ | `lib/export-user-data.ts` → profile + related |
| Staff invitation & onboarding | ✅ | `app/staff` + edge fn `invite-staff` + `/auth/callback?type=invite` |
| Token issuance/revocation (JWT) | ✅ (Supabase-managed) | Replaced by PHP (design in `07`) |

## B. Business management

| Feature | Status | Evidence |
|---|---|---|
| Business auto-provision on first login | ✅ | `auth-context.fetchUserData`, trigger 009 |
| Create additional business, switch business | ✅ (owners) | `business-context.tsx` (insert + `refreshBusinesses`) |
| Business profile / branding for documents (logo ≤200 KB data-URL, phone, email, address, payment details, footer ≤160) | ✅ (migration 016 **not yet applied**) | `hooks/use-business-branding.ts`, `components/settings/receipt-branding-card.tsx` |
| Business settings (low-stock alert: enabled, email, frequency) | ✅ | `app/settings` → key/value `settings` table |
| Tax rate setting | 🟡 | `business.tax_rate` is **read** by POS/sales; no UI or migration to set it found |
| Multiple outlets/locations (store, warehouse, kiosk, office) | ✅ | `app/locations`; plan-gated |
| Business ownership; staff access | ✅ | `businesses.user_id/owner_id`, `staff` |
| Multi-business per staff | ❌ | client loads one staff row |
| Subscription / plan info | ✅ | `businesses.plan/plan_status/plan_expiry` |

## C. Dashboard (`/dashboard`, roles owner/admin/manager)

✅ today & month revenue + tx counts; prior-month comparison; credit owed and overdue (>30 days); month expenses; last-7-day sales/expense series; recent 5 transactions; top products this month; product count, low-stock count (`stock>0 && stock<=reorder_level??min_stock_level??5`), out-of-stock count, inventory value (**at selling price**); pending purchase orders count (`status='sent'` — never produced by schema); today/month profit and net profit via `loadProfit`.
🟡 No date picker and no outlet filter exist on the dashboard (brief asks for them — new work). Revenue = `SUM(total_amount)` of `completed+pending` sales (includes tax and unpaid credit).

## D. POS (`/pos`, all roles)

| Feature | Status | Evidence |
|---|---|---|
| Product search, category filter, grid | ✅ | `pos/page.tsx` |
| Cart, qty adjust, per-sale fixed discount | ✅ | `pos/page.tsx`, `lib/loyalty/math.ts` |
| Customer select / quick create | ✅ | `components/pos/customer-select.tsx` |
| Group discount (auto %, removable per sale, cap 99%) | ✅ | `checkoutDiscounts()` |
| Loyalty points redemption | ✅ (018 unapplied) | `payment-modal`, `complete_sale_with_points` |
| Tax % on (subtotal − discount) | ✅ | `pos/page.tsx:288-291` |
| Pay in full (cash/card/transfer…), change | ✅ | `payment-modal.tsx` |
| Credit sale (customer required; pay-now amount ≥ 0 and ≤ total) | ✅ | same |
| **Multiple payment methods on one sale** | ❌ | single `payment_method` per sale; extra payments only via repayments |
| Barcode scanning | 🟡 | search matches barcode string; no camera scanner component found |
| Service vs product (no stock for services) | ✅ | `item_type`, migration 013 |
| Receipt / invoice document, PDF, share, print, WhatsApp | ✅ | `lib/documents/*` |
| Offline sale capture | 🟡 | IndexedDB stores exist; sync inserts a bare `sales` row (S-09) — **does not work end-to-end** |
| Reference generation | ✅ | client `SALE-<ms>-<rand>` |

## E. Inventory (`/inventory`, `/categories`)

✅ create/edit/delete product (name, description, sku, barcode, category, cost, selling price, stock, min level, reorder level, image_url, is_active, item_type); category CRUD (delete nulls product.category_id); CSV bulk import with header aliasing + category auto-resolution, chunked insert (`bulk-csv-import-dialog.tsx`); low-stock banner; plan limits for products/categories (client-only).
❌ stock adjustment screen with reason; stock movement history; per-outlet stock (tables in `scripts/004` unused); inventory valuation beyond dashboard number; CSV **export** of inventory exists only in Reports.
🟡 product images: `image_url` text field only; no upload pipeline found for products.

## F. Sales & history (`/sales`, all roles)

✅ list with search/filter, detail with items, repayments and edit-history info (`sale_payment_info`), receipt/invoice view, print/PDF/share, **edit items on a sale** (owner or active manager only; `edit_sale`), CSV export (premium).
❌ void/cancel/refund action (only status values exist); date-range & outlet & customer filters beyond what the page offers [partial: check page filters during Phase 3].

## G. Customers & credit (`/customers`, `/credit`)

✅ customer CRUD (name, email, phone, address, DOB, notes, group), groups with discount %, loyalty balance & ledger, purchase history per customer; credit page lists `pending` sales with balance = `total − amount_paid − Σrepayments`; record repayment (cash/card/transfer…, notes); payment receipt document; customer statement view via sale history.
🟡 "payment reminders" — no reminder sender found; WhatsApp share of invoice exists.
🟡 `customers.total_spent` semantics uncertain (see 01 §5).

## H. Expenses & profit (`/expenses`, `/profit`)

✅ expense CRUD (amount, description, category, payment_method/status/reference, date, receipt_image_url, outlet_id nullable), expense categories CRUD + default seeding, filters; Profit page: periods today/7d/this month/last month, per-sale and per-product profit, margin, missing-cost warnings, net = profit − expenses. Reproduced exactly in `04`.

## I. Suppliers & purchases (`/purchases`)

✅ supplier CRUD; purchase orders draft→ordered/sent→received/cancelled; edit draft (items replaced); receive via `receive_purchase_order` RPC; delete order.
🟡 "partial receiving" — RPC accepts per-item received qty but sets status to `received` unconditionally; no true partial state. Supplier balances/payments: ❌.

## J. Transfers (`/transfers`)

🟡 create transfer (from/to location, items with `quantity_requested`), status buttons pending→in_transit→completed/cancelled with timestamps. **No stock effect.** Approval step ❌. Reconciliation ❌.

## K. Reports (`/reports`)

🟡 totals (revenue = sum of **all** sales incl. any status), counts, low-stock (uses `min_stock_level||5`, differs from dashboard), top-5 products (first 200 sales only), three CSV exports (sales/inventory/customers; no quote or formula-injection escaping). Gated: export = growth+business; advanced analytics = business. Charts via Recharts on dashboard.
❌ dedicated credit / purchase / expense / outlet-performance reports (brief lists them — new work).

## L. Staff (`/staff`, owner/admin)

✅ invite (name, email, role cashier/manager), resend link, edit role/status, delete; invite link shown for manual share; plan gate (growth+) and staff limit.
🟡 Only roles `owner/admin/manager/cashier` exist; "role-based permissions" in the Business plan blurb = sidebar visibility only (S-05). Outlet assignment for staff ❌.

## M. Billing (`/billing`)

✅ plans Free/Growth/Business; cycles monthly (30d) / quarterly (−10%, 92d) / yearly (−20%, 366d); 8 currencies; Paystack inline; history list. Exact price table in `04`. 🟡 plan changes: upgrade = pay; downgrade/cancel = only `subscription.disable` webhook + expiry cron. Flutterwave ❌ (G-09).

## N. Admin (`/admin`, `profiles.role='admin'`)

✅ tabs: businesses (change plan, change status, deactivate, delete), users (list, delete profile), audit log viewer, governance (broadcast notice = an `audit_logs` row; pricing form = display-only toast). Platform stats (counts, billing sum).

## O. Documents

✅ invoice / receipt / payment-receipt layouts with logo, bank details, status pill, balance; canvas→JPEG→hand-built PDF; Web Share API; WhatsApp deep link (Nigerian number normalisation); file-name builder. Thermal/Bluetooth printing ❌.

## P. Other discovered

Loyalty (settings, ledger, earn/redeem triggers) ✅ (018 unapplied); customer groups visible to staff ✅ (018); low-stock e-mail alerts via Resend ✅; plan-expiry downgrade job ✅; audit log writer `lib/audit-logs.ts` ✅ (admin actions only); PWA manifest ✅; SEO sitemap/robots ✅; privacy/terms pages ✅; Google site-verification file.

## Items in the brief with **no** source equivalent (new scope, not "reproduction")

Multi-payment per sale · void/refund workflow · stock adjustment & movement history · per-outlet inventory & functional transfers · supplier balances · staff outlet assignment & granular permissions · camera/hardware barcode & thermal printing · push notifications · Flutterwave · dashboard date/outlet filters · credit/purchase/outlet reports · server-issued receipt sequence · offline queue that actually syncs. These are tracked in `09_KNOWN_GAPS.md` and flagged "NEW" in the roadmap so nobody mistakes them for parity features.
