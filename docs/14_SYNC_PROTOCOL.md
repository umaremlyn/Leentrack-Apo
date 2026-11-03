# 14 — Offline Sync Protocol (version 1)

Status: server side implemented in Phase 4 (see `backend/app/Sync/`). The Flutter queue (Phase 5) and the web client (Phase 7) are built against this document.

## 1. Principles

1. **The server decides every number.** A device never sends prices, totals, tax, discounts-in-money or stock levels as facts. It sends *what the cashier did* (items and quantities, the amount tendered); the server prices it with the catalogue it has *now*.
2. **Every operation has an id made on the device** (UUID v4) and is **safe to send again**. Sending an operation twice, ten times, in a different order, or after a crash never produces two sales.
3. **Nothing is silently lost.** An operation that cannot be applied is kept, with its reason, in an inbox a manager works through (`conflict` / `rejected`). A sale that physically happened is never discarded by the server on its own.
4. **No server invariant is bent for offline.** Stock never goes negative, prices are the server's, permissions are checked at apply-time against the cashier's *current* role.
5. **Time is the server's, corrected.** Records are dated when the cashier did the thing, corrected for the device's clock error, never in the future, never older than 60 days.

## 2. Endpoints (all need `Authorization: Bearer …` and `X-Business-Id`)

| Method & path | Purpose |
|---|---|
| `GET /sync/info` | protocol version, limits, supported operation types, server time |
| `POST /sync/push` | send queued operations (≤ 100 per request) |
| `POST /sync/pull` | download changes since the cursors the device holds |
| `GET /sync/operations?status=attention\|conflict\|rejected\|applied\|discarded` | the inbox (managers: everyone's; others: their own) |
| `POST /sync/operations/{id}/retry` | manager: try the stored operation again now |
| `POST /sync/operations/{id}/accept-prices` | manager: re-apply a `price_changed` sale at today's prices |
| `POST /sync/operations/{id}/discard` `{reason}` | manager: give up on it (kept, audited) |

## 3. Push

Request:
```json
{ "device_id": "android-7f3a9c2e",
  "device_time": "2026-03-01T10:05:00Z",
  "operations": [
    { "id": "0b9c…", "type": "customer.create", "client_created_at": "2026-03-01T09:12:00Z",
      "payload": { "id": "5e1d…", "name": "Ada Obi", "phone": "08031234567" } },
    { "id": "7a44…", "type": "sale.checkout", "client_created_at": "2026-03-01T09:14:30Z",
      "payload": { "customer_id": "5e1d…", "expected_total": "1250.00",
                   "items": [ { "product_id": "…", "quantity": 2 } ],
                   "payment": { "mode": "full", "method": "cash", "amount": "1500" } } } ] }
```
* `device_time` is the device clock **at the moment of sending**. The server computes `skew = server_now − device_time` and corrects every `client_created_at` by it. A device whose clock is hours wrong therefore still dates its sales correctly. A skew larger than 60 days is refused (the device clock must be fixed).
* Operations are applied **in the order sent**. Put dependencies first (a new customer before the sale that uses it). A failed operation does not stop the ones after it; a dependent operation simply reports `conflict / dependency_missing` and can be sent again.
* Response (HTTP 200 even when some operations fail — read each result):
```json
{ "server_time": "…", "clock_skew_seconds": -10800,
  "results": [ { "id": "0b9c…", "status": "applied", "duplicate": false, "result": { "customer_id": "5e1d…", "replayed": false } },
               { "id": "7a44…", "status": "conflict", "duplicate": false, "code": "price_changed",
                 "message": "The total is now 1300.00, not 1250.00. …", "fields": { "actual_total": ["1300.00"], "expected_total": ["1250.00"] } } ],
  "summary": { "applied": 1, "conflict": 1, "rejected": 0, "discarded": 0, "error": 0 } }
```

### 3.1 Result statuses

| status | meaning | the device should |
|---|---|---|
| `applied` | done (also returned for a repeat: `duplicate: true`) | remove it from the queue; store `result` ids |
| `conflict` | the world disagrees *right now* (`insufficient_stock`, `price_changed`, `not_found`, `dependency_missing`). It may succeed later or after a manager decides | keep it, show "needs attention"; **re-send later** (it is retried automatically) |
| `rejected` | will not succeed as sent (validation, permission, too old, wrong type) | stop retrying; show the reason; a manager may retry or discard |
| `discarded` | a manager gave up on it | remove from queue |
| `error` | server trouble (`server_error`). Nothing was recorded | **re-send** with back-off |

Conflict codes: `insufficient_stock` (the last unit went to another device first), `price_changed` (only when the operation carried `expected_total`), `not_found` (e.g. repaying a sale that has not arrived yet), `dependency_missing` (the customer has not arrived yet).

### 3.2 Operation types (version 1)

| type | payload | idempotent by | notes |
|---|---|---|---|
| `sale.checkout` | the `POST /sales` body (items, discount, payment, customer_id, outlet_id, notes) plus optional `expected_total` | operation id | `expected_total` = what the customer was quoted; if the server's total differs the sale is **not** recorded and comes back as `price_changed`. Without it the sale is recorded at server prices |
| `credit.repay` | `{ sale_id, amount, method?, notes? }` | operation id | the sale must already exist on the server |
| `customer.create` | `{ id, name, phone?, email?, … }` — `id` chosen by the device | the `id` | an existing record with that id in the same business = the same record arriving again |
| `expense.create` | `{ id, description, amount, expense_date, … }` | the `id` | managers/owners only |
| `stock.adjust` | `{ product_id, location_id, delta, reason, note? }` | operation id (stock movement reference) | `new_quantity` (a count) is **refused** offline: it depends on stock the device may not see |

**Online only (version 1):** sale edits and voids, purchases, transfers, billing, staff, product edits, reports. They need the whole picture or money moving back.

### 3.3 Rules the server enforces on every operation
* `operations` 1…100; `client_created_at` required; unknown type → `rejected / unsupported_type`; same id twice in a batch with different content → `rejected / idempotency_conflict`; an id reused for different content later → `rejected / idempotency_conflict`.
* Older than **60 days** (after skew correction) → `rejected / too_old`. In the future → clamped to "now".
* The cashier's **current** role and plan limits apply (an expense queued by someone who has since been demoted is `rejected / forbidden`).
* Outlet restriction applies (a cashier limited to the Lekki outlet cannot sync a sale for another).

## 4. Pull

`POST /sync/pull` with `{ "cursors": { "<resource>": "<cursor>" }, "limit": 500, "resources": ["products", …] }`.
Resources: `products`, `categories`, `customers`, `customer_groups`, `locations`, `stock_levels`, `settings`.

* First pull: send no cursors → full snapshot, paged (`has_more: true` until every resource is exhausted; pass back the `cursors` from the previous response).
* Later pulls: pass back the stored cursors → only what changed.
* **Cursor** is opaque (`"<UTC time>|<id>"`); store exactly what was returned. Within one download it is an exact position. When a resource is exhausted: if its newest change is older than ~10 seconds the cursor sits exactly after it (an idle device is re-sent nothing); if the newest change is more recent the cursor is rewound to "now − 10 s", so that a transaction that committed late cannot be missed. **Recent rows can therefore repeat: always upsert by `id`.**
* **Deletions:** products and customers arrive with `archived_at` set (remove them locally). Categories and customer groups that no longer exist are the ones missing from `reference_ids`. Locations arrive with `is_active=false`.
* `stock_levels` rows are `{id: "<product>|<location>", product_id, location_id, quantity}` — per outlet; a restricted user receives only their outlets. `products.cost_price` is sent only to users who may read costs.
* `settings` is one object (business name/currency/tax rate/effective plan, loyalty settings, branding for receipts), sent only when something in it changed.

## 5. What the device may show while offline
A sale is **provisional** until `push` answers `applied`. The receipt shown offline should say so; the final receipt number comes from the server (`result.receipt_number`). Local stock should be decremented on the device immediately (so the same device does not oversell) and corrected by the next pull.

## 6. Conflict policy in plain words
| Situation | What happens | Who resolves |
|---|---|---|
| Two devices sell the last unit | first push wins; the second is `conflict / insufficient_stock` and waits | manager: restock/adjust, then **retry**; or discard |
| Price changed after the customer was quoted | `conflict / price_changed` (only with `expected_total`) | manager: **accept-prices** (records at today's prices) or discard |
| Customer created offline arrives after the sale that uses it | `conflict / dependency_missing`, then fine on re-send | automatic |
| Cashier no longer on the team when retried | `409 user_inactive` | manager records manually, discards |
| Device clock wrong | corrected by `clock_skew_seconds` | automatic |
| Operation over 60 days old | `rejected / too_old` | manager records online |
| Same operation delivered twice, at the same time or later | one effect, same answer (`duplicate: true`) | automatic |

A conflicted or rejected operation older than 7 days is reported by the nightly reconciliation (`sync_operations_waiting_for_a_person`).

## 7. Data kept
Outcomes of `applied`/`discarded` operations are kept 90 days (longer than the 60-day acceptance window, so a very late replay still finds its answer); the sale/repayment tables keep their own idempotency forever. Operations needing a person keep their payload until resolved.

## 8. Not covered in version 1 (documented, not forgotten)
* Offline edits/voids/refunds, purchases, transfers (they need a consistent view or move money back).
* Merging two devices' edits to the *same* customer/product record (only creation is offline).
* Push notifications to wake a device when a conflict is resolved (the device polls `GET /sync/operations`).
