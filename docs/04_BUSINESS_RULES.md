# 04 — Business Rules (extracted from source)

Each rule cites its origin. "Rounding" `r2(n)=Math.round((n+EPS)*100)/100` (half away from zero on the binary value). PHP must use `DECIMAL`/string arithmetic (bcmath) with **round-half-up at 2 dp** and the golden tests in `backend/tests/Golden/` (derived from these TypeScript functions) must match to the cent. Where JS floating-point and exact decimal could differ by a cent, the exact-decimal result is authoritative and the difference is documented in the test.

## 1. Plans, limits, gating (`lib/plan-limits.ts`, `lib/plan-access.ts`, `billing-client.tsx`, `paystack-webhook`)

* Canonical plans: `free`, `growth`, `business`. Normalisation: `starter→growth`; `pro|gold→business`; `expired|cancelled|suspended|unknown|null→free`.
* Limits (Infinity = unlimited):

| Resource | free | growth | business |
|---|---|---|---|
| products | 50 | 500 | ∞ |
| categories | 5 | 50 | ∞ |
| outlets | 1 | 3 | ∞ |
| customers | 100 | 2000 | ∞ |
| sales | 200 | 5000 | ∞ |
| staff | 1 | ∞ | ∞ |

* Premium features: `exportReports` growth+business; `advancedAnalytics` business; `staffManagement` growth+business; `locations` growth+business.
* Prices (monthly base, display units): growth — NGN 3500, GHS 38, KES 330, ZAR 46, EGP 120, USD 2.50, GBP 2.00, EUR 2.50; business — NGN 9000, GHS 98, KES 850, ZAR 118, EGP 310, USD 6.50, GBP 5.00, EUR 6.00.
* Cycles: monthly ×1 no discount, +30 days; quarterly ×3 −10%, +92 days; yearly ×12 −20%, +366 days. Total = `base × months × (1−discount)`; Paystack amount = `round(total×100)`.
* Plan expiry: daily job sets `plan='free', plan_status='expired'` where `plan<>'free' AND plan_expiry < now`.
* **Fidelity vs. fix:** limits are *not enforced server-side today* (S-07). PHP enforces them. Existing businesses already over a limit (possible because enforcement was client-only) are **grandfathered read/write on existing rows but blocked from creating more** — policy to be confirmed by owner (G-11).
* Webhook plan derivation by amount (legacy amounts 480000/1280000/3040000/500/1200/2800 map to growth/business monthly) is **a defect, not a rule**; PHP uses server-created payment records (S-04). Legacy amounts are kept only in the importer to classify historical `billing_history` rows.

## 2. POS checkout (`pos/page.tsx`, `lib/loyalty/math.ts`, `payment-modal.tsx`, `complete_sale*`)

Inputs from client: items `{product_id, quantity}`, customer?, discount choices, payment, op-id. Everything else is **recomputed on the server**.

1. `subtotal = Σ r2(qty × unit_price)` with `unit_price` = current catalogue `selling_price` (server-side). A per-line price override is **not** a source feature; permitted only if a permission `sales.override_price` is granted (NEW, default off).
2. Group discount: `pct = clamp(group.discount_percentage, 0, 99)`; `groupDiscount = r2(subtotal×pct/100)` if group applied (cashier may switch it off for the sale).
3. Manual discount: fixed amount ≥ 0; invalid if `manual + groupDiscount >= subtotal` ("Discount must be less than the cart total").
4. Points: `room = max(0, r2(subtotal − 0.01 − groupDiscount − manual))`; `maxPoints = min(floor(customer.points), floor(room / pointValue))` when loyalty enabled and `pointValue>0`; `pointsValue = r2(pointsUsed × pointValue)`.
5. `discount = r2(groupDiscount + manual + pointsValue)`; `taxable = r2(subtotal − discount)`; `tax = r2(taxable × tax_rate/100)`; `total = r2(taxable + tax)`.
6. Full payment: `amount_paid ≥ total` required; `change = max(0, paid − total)`; status `completed`.
7. Credit sale: customer **required**; `0 ≤ paid_now ≤ total`; `balance = total − paid_now`; status `pending` if balance > 0 else `completed`; method = chosen method if paid_now>0 else `other`.
8. Stock: for each **physical** product: row-lock, require `stock ≥ qty` else error "Not enough stock. Only N unit(s) left."; decrement. Services: no stock check/decrement.
9. Customer: `total_spent += total`, `visit_count += 1`, `last_visit = now`. (**[UNVERIFIED]** whether live `total_spent` uses `total` or amount received — commit #16 says "real spent amount". Decision recorded in G-12; default: money actually received, matching the loyalty rule.)
10. Loyalty earn (if enabled): on **money received** (`amount_paid` capped to total) and on each repayment — see §7.
11. Item cost snapshot: `sale_items.unit_cost = coalesce(nullif(cost_price,0), nullif(cost,0), cost_price)` from the product *of the same business*; client value ignored.
12. Receipt number: server-generated, per business, monotonic. Display forms `INV-<rest>` / `RCT-<rest>` derived by `documentNumber()` (strip `SALE-/INV-/RCT-/RCP-` prefix). Historic numbers imported verbatim; new numbers keep the `SALE-<yyyymmddhhmmss>-<seq>` shape? **Decision:** new format `SALE-<epoch_ms>-<4-digit>` retained for look-and-feel continuity but guaranteed unique by `UNIQUE(business_id, receipt_number)` and retry; plus sequential `sale_seq` internal column.
13. Idempotency (NEW): `client_operation_id` unique per business; replay returns the original sale, HTTP 200 with `replayed:true`.

## 3. Payment methods

Stored values observed: `cash`, `card`, `bank_transfer`/`transfer`, `mobile_money`, `other`; label map in `paymentMethodLabel()`. `edit_sale` accepts `cash|card|transfer|other`. PHP normalises to enum `cash|card|bank_transfer|mobile_money|other` on write and accepts `transfer` as an alias; historic raw values preserved in `payment_method_raw` by the importer.

## 4. Sale status

`payment_status ∈ {completed, pending}` count as sales everywhere (dashboard, profit). Values meaning cancelled/refunded exist (`017` message) but no flow sets them. PHP adds `voided` and `refunded` with explicit endpoints (NEW) and excludes them from revenue/profit.

## 5. Credit & repayments (`credit/page.tsx`, `017`)

* Per sale: `upfront = amount_paid` (money at till); `repaid = Σ debt_repayments.amount`; **balance = max(0, total − upfront − repaid)**.
* Customer outstanding = Σ balance over their `pending` sales. Overdue = pending sales older than 30 days (dashboard).
* Repayment: `0 < amount ≤ balance (+0.005 tolerance)`; when `balance ≤ 0.005` after payment → sale `completed`. Atomic in PHP with row lock; over-payment rejected server-side (S-10). Repayments are immutable.
* Documents: payment receipt shows previous balance, amount, new balance; `FULLY PAID` / `PART PAYMENT`.

## 6. Editing a sale (`017_edit_sale.sql`, `lib/sales/edit-math.ts`) — must be reproduced exactly

* Who: business owner, or **active manager**. Others → 403.
* Allowed only if status ∈ {pending, completed}; sale must have item rows; Σ item totals must equal stored subtotal (±0.01) else "no longer add up".
* Concurrency: reject if `updated_at` (ms) ≠ expected, or if `paid_so_far` ≠ expected (±0.005) → 409.
* `paid_so_far`: completed → `total`; pending → `min(total, upfront + repaid)`; (`upfront = clamp(amount_paid, 0, total)`, defaulting to `total` if completed and null; for completed sales `upfront = max(upfront, min(total, max(0, total − repaid)))`).
* Items: 1–200 lines; qty integer 1–100000; price numeric ≥0 (≤10 integer digits); `line = r2(qty×r2(price))`; `subtotal ≤ 1e9`.
* Discount (fixed, before tax): `0 ≤ d < subtotal` (if d>0).
* Tax: recover original rate `rate = r2(old_tax / (old_sub − old_disc) × 100)`; if `|r2(base×rate/100) − old_tax| ≤ 0.011` use the rate, else keep the same *ratio*. `tax = r2((subtotal−d) × rate/100)` (or × ratio); none if old tax ≤0 or base ≤0.
* Stock: per product net delta `new − old`; services skip; lock products in id order; if delta>0 and stock<delta → "Not enough stock for "X". Only N left."; inactive products can't be newly added; product must belong to the business.
* Money: `diff = r2(total − paid_so_far)`. Extra payment (method `cash|card|transfer|other`) only if diff>0.005 and ≤ diff+0.005. If diff < −0.005: `refund = −diff`, `new_upfront = max(0, upfront − refund)`, extra = 0. Else `new_upfront = upfront + extra`. `balance = max(0, total − (new_upfront + repaid))`; if balance>0.005 a customer is required. Status = balance>0.005 ? pending : completed.
* Customer `total_spent += (new_total − old_total)` floored at 0. Loyalty reacts to the change in money received.
* A `sale_edits` row records who/when/why/before-after items and money. No physical refund is executed — the UI displays "give the customer ₦X back".

## 7. Loyalty (`018`, `lib/loyalty/math.ts`)

Defaults: enabled, `spend_per_point=10000`, `point_value=100` (constraint `point_value ≤ spend_per_point`; `spend_per_point ∈ [1, 1e8]`, `point_value ∈ [0, 1e6]`). Earn: `total = progress + money_received; pts = floor(total / spend_per_point)`; points never below 0; `progress_after = max(0, total − pts×rate)`; ledger row per change; redeem deducts points and adds a `redeem` ledger row; redeem requires `points×value ≤ discount + 0.005`, balance sufficient, customer in business. Failure in loyalty must **not** block a sale in the source — in PHP it runs inside the sale transaction (all-or-nothing), which is stricter and deliberately different (documented decision D-04).

## 8. Profit (`lib/profit/compute.ts`, `load.ts`)

* Counted sales: `payment_status ∈ {completed, pending}` in `[from,to)` on `created_at`.
* `soldExTax(s) = subtotal − discount` (subtotal missing → `total − tax + discount`); i.e. after discount, **excluding tax**.
* Per sale with items: `cost = Σ unitCost × qty`; `unitCost = item.unit_cost ?? product.cost_price`; missing cost = non-service item with cost null or 0 (counted, cost treated as 0, flagged).
* `profit = sold − cost`; `margin = profit/sold×100` (null if sold ≤ 0). Discount shared across lines pro-rata by line value for per-product figures.
* Sales with **no item rows** are *not* counted in profit; reported as `salesWithoutItems` and `amountWithoutItems`.
* Summary: `salesCounted`, `itemsMissingCost`, `productsMissingCost[]`.
* Net profit = `profit − Σ expenses.amount` where `expense_date ∈ [localDate(from), localDate(to))`; null if profit unknown.
* Periods (user's local zone): today; last 7 days (today−6 … today); this month (1st → tomorrow); last month. **PHP uses the business timezone, not server/UTC**, with `[from,to)` semantics.
* "Cost of goods sold" = Σ `unit_cost×qty` for counted sales. "Gross profit" = profit on sales (before expenses); "Net" = after expenses. No other tiers (no payroll/depreciation) exist.

## 9. Inventory

* `item_type='service'`: no stock, excluded from low-stock, inventory value, "cost missing".
* Low-stock threshold: `reorder_level ?? min_stock_level ?? 5`; low = `0 < stock ≤ threshold`; out = `stock = 0`. (The Reports page uses `min_stock_level||5` — a bug; PHP uses the dashboard rule everywhere.)
* Inventory value = Σ `selling_price × stock` of active physical products (dashboard) — **retail value**; PHP additionally exposes cost valuation (NEW).
* Product limits per plan; CSV import aliases & rules in `bulk-csv-import-dialog.tsx` (verified in `bulk-csv-import-dialog.tsx:296-325`: services get barcode `''`, stock 0, min/reorder 0; physical `reorder_level` defaults to `min_stock_level || 5`; selling price required ≥0; physical stock required ≥0; rows with errors are shown and skipped); chunked inserts.
* Barcode: per-business uniqueness is **not** enforced in the source (partial index only) — PHP adds `UNIQUE(business_id, barcode)` where not null, with importer duplicate-report rather than silent failure.

## 10. Purchases

Statuses `draft → ordered|sent → received | cancelled`; only `draft` editable (items deleted & re-inserted on edit). Receiving: per item received qty added to `products.stock_quantity` for linked products; order marked received. **PHP rules (fix S-08):** order must be `ordered`; `0 < receive ≤ ordered − received` per item; additive; row-locked; idempotent with op-id; status `partially_received` when some outstanding; `received` when all complete; audit + stock movement rows. Order number: client-generated `PO-<epoch_ms>` (`purchases/page.tsx:377`); PHP issues `PO-<epoch_ms>`-style numbers server-side with a per-business unique constraint.

## 11. Transfers

Source: status machine only. PHP rule set (NEW, to satisfy "no stock created/destroyed"): create (`pending`) → approve (optional by setting) → dispatch (`in_transit`: debit source `stock_levels`, hold) → receive (`completed`: credit destination by received qty; any shortfall recorded as `transfer_variance` movement, never silently dropped) → cancel (only before dispatch, or after dispatch returns stock to source). Invariant tested: Σ stock across locations unchanged by a completed transfer except explicit, audited variance.

## 12. Staff

Roles: `owner`, `admin` (legacy; treated as platform-admin sidebar label), `manager`, `cashier`. Visibility per sidebar: cashier → POS, Sales, Customers; manager adds Dashboard, Inventory, Categories, Purchases, Credit, Profit, Expenses, Reports, Locations; owner adds Staff; platform admin → Admin Portal. Staff status `invited|active|inactive|disabled`. Cashier landing page `/pos`, admin → `/admin`. Edit-sale: owner/active manager. Branding/loyalty settings write: owner. PHP turns this matrix into enforced permissions (`07`).

## 13. Documents

Kinds `invoice` (credit with balance), `receipt`, `payment_receipt`; status pill `PAID`/`PART PAID`/`UNPAID`/`PART PAYMENT`/`FULLY PAID` (`statusFor`); WhatsApp number normalisation default country 234; file name `Invoice|Receipt|Payment-Receipt-<number>-<CUSTOMER>.pdf` (≤24 chars name, ≤40 chars number); message templates in `shareMessage`. Branding limits: logo ≤200,000 chars data-URL png/jpeg/webp; phone ≤40; email ≤120; address ≤200; payment details ≤300; footer ≤160.

## 14. Currency

Stored per profile (`NGN` default); display only. Symbols: NGN ₦, GHS ₵ (billing uses GH₵), KES KSh, ZAR R, EGP "EGP ", USD $, GBP £, EUR €, XOF CFA. All supported currencies have 2 decimals in this product. No FX conversion exists; none will be invented.

## 15. Low-stock e-mail alert

Settings: enabled (default off unless `"true"`), `alert_email` (default owner e-mail), frequency `daily` (cooldown 20 h) or other (1 h); `force` bypasses; includes active physical products with `stock ≤ (reorder_level ?? min_stock_level ?? 5)` sorted ascending; out-of-stock first emoji severity (0→🔴, ≤2→🟠, else 🟡); subject differs if any out of stock; writes `low_stock_last_alert`. PHP: cron-driven, HTML-escaped (S-15).
