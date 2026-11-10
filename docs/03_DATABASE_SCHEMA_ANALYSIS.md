# 03 — Database Schema Analysis

Source: `supabase/migrations/001–019`, `scripts/000–015`, `lib/supabase.ts` types, and column usage in the app. **The production schema was not available** (see 01 §3). Column lists below are therefore the **union** of evidence; each has a confidence tag:
`M` = defined in a migration · `A` = used by app code but not defined in repo · `S` = legacy script only · `?` = must be confirmed by a schema dump.

## 1. Tables (PostgreSQL / Supabase today)

| Table | Key columns | Notes & relationships |
|---|---|---|
| `auth.users` (Supabase-managed, **not in repo**) | id uuid, email, encrypted_password (bcrypt), email_confirmed_at, phone, raw_user_meta_data, … | Source of identity. Password hashes are bcrypt (`$2a$`) in GoTrue — see `06` for migration approach |
| `profiles` M | id→auth.users, email, full_name, business_name, business_type, currency(def NGN), phone_number, address, role(owner/admin/manager/cashier), avatar_url | `role` mixes platform and business role (S-22) |
| `businesses` M/A | id, name, user_id, owner_id, plan (free/starter/pro/gold/growth/business), plan_status (active/cancelled/suspended/expired), plan_expiry, paystack_customer_id, paystack_subscription_code, currency, **tax_rate (A)** | both `user_id` and `owner_id` used; treated as owner if either matches |
| `staff` M | id, business_id, user_id, name, email, role, status (invited/active/inactive/disabled), invited_by, joined_at | access also matched by e-mail |
| `locations` M | id, business_id, user_id, name, address, city, state, postal_code, country, phone, email, manager_name, is_active, location_type (store/warehouse/kiosk/office), timezone(Africa/Lagos), business_hours jsonb | |
| `categories` M | id, business_id, user_id, name, description | |
| `products` M/A | id, business_id, category_id, user_id, default_location_id (+`location_id` S), name, description, sku, barcode, selling_price, cost_price, **price, cost (legacy dupes, A)**, stock_quantity int, min_stock_level, reorder_level(def 5), image_url, is_active, **item_type 'product'/'service'** (013) | stock is a single integer; `CHECK stock_quantity >= 0` in 001 |
| `customer_groups` M | id, business_id, name, description, discount_percentage (0–100) | |
| `customers` M/A | id, business_id, name, email, phone, address, **date_of_birth (A)**, notes, **group_id (A)**, loyalty_points, total_spent, visit_count, last_visit | points guarded by trigger (018) |
| `sales` M/A | id, business_id, user_id→profiles, customer_id, **receipt_number (A)** / sale_number (M), subtotal, tax_amount, discount_amount, total_amount, amount_paid, change_amount, payment_method (**enum A**), **payment_status (enum A: completed/pending/+refunded/cancelled?)**, outlet_id (nullable), notes | unique(business_id, sale_number) only in 001 |
| `sale_items` M | id, sale_id, product_id (RESTRICT), quantity, unit_price, total_price, **unit_cost (019, unapplied)** | |
| `sale_edits` M (017, unapplied) | id, business_id, sale_id, edited_by, edited_at, reason, old_total, new_total, paid_so_far, balance_due, refund_due, extra_paid, extra_method, before/after_discount, before_items jsonb, after_items jsonb | immutable history |
| `debt_repayments` M | id, business_id, sale_id, customer_id, amount>0, payment_method, notes, created_by, created_at | |
| `expenses` A/M(doc) | id, business_id, outlet_id?, category_id, user_id→profiles, amount numeric(12,2), description, receipt_image_url, payment_method, payment_status, payment_reference, expense_date date, sync_status | DDL not in repo |
| `expense_categories` A/M(doc) | id, business_id, name, description | |
| `suppliers` M | id, business_id, name, contact_name, phone, email, address, notes | |
| `purchase_orders` M | id, business_id, supplier_id, order_number, status (draft/ordered/received/cancelled; UI also 'sent'), total_cost, notes, ordered_at, received_at, created_by | |
| `purchase_order_items` M | id, purchase_order_id, business_id, product_id, product_name, quantity_ordered, quantity_received, unit_cost, total_cost GENERATED | |
| `location_transfers` M/A | id, business_id, from/to_location_id, status (pending/in_transit/completed/cancelled), notes, user_id/created_by, transfer_number?, shipped_at?, received_at? | |
| `transfer_items` M/A | id, transfer_id, product_id, quantity (M) / quantity_requested (A) (+quantity_shipped/received? ?) | |
| `billing_history` M | id, business_id, plan, amount, currency, status (successful/failed/refunded/pending), payment_ref, paystack_reference (A), provider (paystack/flutterwave/stripe/manual), paid_at, invoice_url | |
| `audit_logs` M | id, admin_id, user_id, business_id, action, target_type, target_id, details, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at | admin-only writer in app |
| `user_sessions` M | id, user_id, session_token, ip_address, user_agent, device_info, login_time, logout_time, is_active | |
| `settings` M | id, business_id, setting_key, setting_value text, updated_at; unique(business_id,setting_key) | keys seen: `low_stock_alerts_enabled`, `alert_email`, `alert_frequency`, `low_stock_last_alert` |
| `business_branding` M (016, unapplied) | business_id PK, logo_data (data-URL), phone, email, address, payment_details, footer_note, updated_by | |
| `loyalty_settings` M (018, unapplied) | business_id PK, enabled, spend_per_point, point_value, updated_by | CHECK point_value ≤ spend_per_point |
| `loyalty_ledger` M (018, unapplied) | id, seq identity, business_id, customer_id, sale_id, kind earn/redeem, money, points, progress_after, balance_after, spend_per_point, point_value, note, created_by | |
| `inventory_movements`, `stock_levels` S | per-location stock & movement log | unused by app; existence unknown |
| `super_admins` S | user_id unique, permissions jsonb | alternative admin mechanism; unknown if live |

## 2. Database functions / triggers inventory

| Object | Purpose | PHP replacement |
|---|---|---|
| `complete_sale` (003→013, SECURITY DEFINER) | insert sale + items, lock product row, check stock, decrement (skip services), bump customer `total_spent`/`visit_count`/`last_visit` | `SaleService::checkout` in one InnoDB transaction |
| `complete_sale_with_points` (018) | adds balance check and ledger redeem | same service, `pointsRedeemed` |
| `edit_sale` (017) | full recompute, stock delta, refund/extra payment, history | `SaleService::edit` (rules in `04` §6) |
| `sale_payment_info`, `can_edit_sales` | read helpers | `SalePolicy` |
| `receive_purchase_order` (004) | add stock for items; mark received | `PurchaseService::receive` (fixed, S-08) |
| `decrement_stock` (001) | row-locked decrement | `StockRepository::decrement` |
| `handle_new_user` (009) | profile + default business | `RegistrationService` |
| `has_business_access`, `is_admin`, `can_edit_sales`, `is_business_owner`(missing), `is_super_admin` | RLS predicates | `TenantContext` + policies |
| `loyalty_apply`, triggers `loyalty_on_sale`, `loyalty_on_repayment`, `guard_customer_points` | earning logic | `LoyaltyService` (explicit calls, not triggers) |
| `sale_items_cost_guard`/`_remember_cost` triggers (019) | snapshot cost | set in `SaleService` |
| `guard_business_branding`, `guard_loyalty_settings` | server-controlled updated_by | service layer |
| `update_updated_at` / `handle_updated_at` triggers | timestamps | MySQL `ON UPDATE CURRENT_TIMESTAMP` |
| `create_audit_log`, `export_user_data`, `delete_user_and_data` (scripts) | audit/export/delete | `AuditService`, `AccountService` |

PHP/MySQL does **not** replicate logic as triggers; logic is in services inside transactions, which is testable and shared-hosting friendly.

## 3. RLS policy summary (what the rebuild must preserve *intentionally*, not accidentally)

* Tenant boundary: `business_id IN (businesses where user_id/owner_id = me)` **or** staff membership (011). → `TenantContext::requireMembership(businessId)`.
* Owner-only: branding write, loyalty-settings write, staff table management (001), `can_edit_sales` = owner or active manager.
* Admin: read/update/delete all businesses; read all profiles & billing (012).
* Everything else: any member full CRUD (the S-05 weakness). The rebuild replaces this with a permission matrix (`07`).

## 4. Index inventory (existing)

`businesses(user_id)`, `(owner_id)`; `*_business_id` on categories/locations/products/customers/customer_groups/staff/sales/transfers/billing/settings; products `sku`, `barcode` (partial), `is_active`; customers email/phone partial; sales `(business_id, sale_number)` unique, `user_id`, `customer_id`, `created_at desc`; sale_items `sale_id`, `product_id`; poi order/business/product; debt_repayments business/sale/customer; sale_edits `(sale_id, edited_at desc)`; loyalty_ledger `(customer_id, seq desc)`.
Missing and needed for reports: `sales(business_id, created_at)`, `sales(business_id, payment_status, created_at)`, `expenses(business_id, expense_date)`, `sale_items(sale_id)` covering.

## 5. Target MySQL 8.4 design principles (implemented in Phase 2 migrations)

1. **Primary keys:** keep source UUIDs as `CHAR(36)` → store as `BINARY(16)`? Decision: **`CHAR(36)` utf8mb4_bin** for importer simplicity and API parity (IDs are exposed to clients and offline queues). Index size accepted; revisit if >10M rows.
2. **Money:** `DECIMAL(15,2)` (matches source `NUMERIC(15,2)`; `expenses`/`debt_repayments` were `(12,2)` — widened). Quantities: `INT` (source is integer; fractional units not supported by source).
3. **Time:** `DATETIME(3)` UTC; `expense_date` `DATE`. Connection `time_zone='+00:00'`. Business display zone from `locations.timezone` (default `Africa/Lagos`).
4. **Identity:** new `users` (id = Supabase `auth.users.id` preserved), `password_hash` nullable + `legacy_bcrypt_hash` for first-login rehash/transition (`06`), `email_verified_at`, `phone`, `status`. `profiles` fields merged into `users` + `user_profiles`.
5. **Membership:** `business_members(user_id, business_id, role, status, …)` replaces `staff` + implicit owner; owner is a member with role `owner`. Platform admin = `platform_admins(user_id)`.
6. **Plan/billing:** `businesses.plan` canonical `free|growth|business` (legacy `starter→growth`, `pro|gold→business` mapped exactly as `normalizePlan`), plus `subscriptions`, `payments` (provider ref unique), `webhook_events` (unique provider+event id) for idempotency.
7. **Sales ledger integrity:** `sales` + `sale_items` + `sale_payments` (initial payment) + `debt_repayments` (append-only) + `sale_edits`; `receipt_number` unique per business from `document_sequences`; `client_operation_id` unique per business for idempotent offline sync.
8. **Stock:** `products.stock_quantity` kept as the business-wide figure for parity **and** new `stock_levels(product_id, location_id, quantity)` + `stock_movements` (append-only, reason, ref type/id, before/after). Invariant: `products.stock_quantity = Σ stock_levels` (enforced in service, checked by reconciliation job). Decision pending owner confirmation — G-05.
9. **Constraints:** FKs with `ON DELETE RESTRICT` for financial history (no cascade from customer/product); soft delete (`deleted_at`) for products/customers/suppliers; sales are never deleted, only `voided`.
10. **Audit:** `audit_logs` (actor, business, action, entity, before/after JSON, ip, ua) written by services; `schema_migrations` table tracks applied versions.
11. **Charset:** `utf8mb4`, collation `utf8mb4_0900_ai_ci` (text) / `utf8mb4_bin` (ids, tokens).
12. **Engine:** InnoDB; transactions `READ COMMITTED` with explicit `SELECT … FOR UPDATE` on product/stock/sale rows; deterministic lock order (sorted product ids) to avoid deadlocks, mirroring `edit_sale`.

## 6. Entity-relationship overview

```
users 1─* business_members *─1 businesses 1─* locations
businesses 1─* products *─1 categories ;  products 1─* stock_levels *─1 locations
businesses 1─* customers *─1 customer_groups ; customers 1─* loyalty_ledger
businesses 1─* sales 1─* sale_items *─1 products
sales 1─* debt_repayments ; sales 1─* sale_edits ; sales *─1 customers ; sales *─1 users(cashier)
businesses 1─* suppliers 1─* purchase_orders 1─* purchase_order_items *─1 products
businesses 1─* location_transfers 1─* transfer_items *─1 products
businesses 1─* expenses *─1 expense_categories
businesses 1─1 business_branding ; 1─1 loyalty_settings ; 1─* settings
businesses 1─* subscriptions 1─* payments ; webhook_events
users 1─* api_sessions(refresh tokens) ; audit_logs ; sync_operations(device ops)
```
A rendered ERD is generated from the migrations by `backend/tools/erd.php` in Phase 2.
