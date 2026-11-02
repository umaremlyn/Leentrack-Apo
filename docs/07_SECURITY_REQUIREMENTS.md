# 07 — Security Requirements

Release gate: **no open Critical/High finding** (list in `01` §4 plus anything found later). Each requirement below has an owner phase and a test ID in `10_ACCEPTANCE_TESTS.md`.

## 1. Identity & sessions

* Passwords: `password_hash()` Argon2id if the host's PHP has it (check `PASSWORD_ARGON2ID`), else bcrypt cost ≥12; `password_needs_rehash` on login; minimum length 8 (matches current UX) + breach-list/common-password check; max 72 bytes for bcrypt (pre-hash with SHA-256+base64 is **not** used — reject >72 bytes explicitly).
* Tokens: opaque 256-bit random (`random_bytes(32)`), only **SHA-256 hash** stored; access 15 min; refresh 30 d, rotating, reuse of a rotated token revokes the whole family; bound to `device_id`; list/revoke endpoints; revocation on password change/reset, role change, member removal, account deletion.
* Login throttling: per-IP and per-account sliding window in MySQL (`rate_limits` table, shared-hosting safe); progressive delay; uniform error + timing for unknown e-mail; no account enumeration on register/forgot (register may reveal "email in use" only after e-mail ownership flow — decision: respond 202 and e-mail the owner).
* E-mail verification & reset tokens: random, single-use, hashed at rest, short expiry, invalidated on use/new request.
* Web (Next.js) stores access token in memory and refresh token in an `HttpOnly; Secure; SameSite=Lax` cookie set by a **Next.js route handler proxy** (BFF) so the refresh token is never reachable by JS (XSS blast-radius reduction). Flutter uses OS secure storage (Keychain / Keystore / DPAPI / libsecret).
* CSRF: API is bearer-token based and does not rely on cookies → no CSRF surface on `api.*`; the BFF cookie endpoints require `SameSite` + custom header + Origin check.

## 2. Authorization (server-side, deny-by-default)

* `TenantContext` resolved per request: user → active `business_members` row for `X-Business-Id` (404 if none). Every repository query includes `business_id = :ctx` — **no repository method accepts an id without a business scope**. Child tables are reached only via a parent scoped query (e.g. `sale_items` via `sales.business_id`).
* Outlet scope: members may be limited to outlet ids; list/aggregate queries add `location_id IN (…)` when restricted; owner/manager default = all.
* Permission matrix (config, enforced by `Policy` classes; roles come only from `business_members.role`, never from the request):

| Permission | owner | manager | cashier |
|---|---|---|---|
| sales.create / sales.read | ✔ | ✔ | ✔ (own + all POS history as today) |
| sales.edit | ✔ | ✔ (active) | ✖ |
| sales.void/refund (NEW) | ✔ | ✔ (limit configurable) | ✖ |
| customers.read/create | ✔ | ✔ | ✔ |
| customers.update/delete, groups.write | ✔ | ✔ | ✖ |
| products.read | ✔ | ✔ | ✔ |
| products.write, stock.adjust, categories | ✔ | ✔ | ✖ |
| purchases/suppliers | ✔ | ✔ | ✖ |
| expenses, reports, profit, dashboard | ✔ | ✔ | ✖ |
| credit.repay | ✔ | ✔ | ✖ (matches sidebar today) |
| staff.manage, billing, branding.write, loyalty.write, business.delete | ✔ | ✖ | ✖ |
| platform admin endpoints | separate `platform_admins` check — not a business role |

  The matrix mirrors what the UI shows today but, unlike today, is enforced on the server (S-05). Adjustments (e.g. whether a cashier may see all sales) require owner confirmation and are logged as decisions.
* Plan enforcement in services (`PlanGuard`): limits & premium features from `04` §1; subscription state read from `businesses` which only the billing service and platform admin can write.
* Platform admin: table `platform_admins`; created only via CLI (`bin/make-admin.php <email>`) or by another admin with step-up re-authentication; every admin action audited; destructive admin actions are soft and reversible for 30 days.
* Mass-assignment: each endpoint has an explicit field allow-list; unknown/forbidden fields (`role`, `plan`, `business_id` in body, `user_id`, totals, `unit_cost`, `loyalty_points`) → 422.

## 3. Input, output, injection

* PDO, `ATTR_EMULATE_PREPARES=false`, `ERRMODE_EXCEPTION`, utf8mb4; no string-built SQL; dynamic `ORDER BY` only from allow-lists; `LIKE` escaped.
* Validators per endpoint (type, range, length, enum, decimal scale); body size cap (1 MB JSON, 5 MB uploads); strict `Content-Type`.
* JSON output only; `X-Content-Type-Options: nosniff`; errors never include SQL/stack/paths (`APP_DEBUG=false` enforced in production by a boot check).
* E-mail templates and generated HTML/PDF: all dynamic text HTML-escaped (S-15).
* CSV import/export: formula-injection guard on export (prefix `'` to cells starting `= + - @ \t \r`), correct RFC-4180 quoting (S: reports export lacks it), size/row caps on import, encoding detection to UTF-8.

## 4. Files & uploads

Avatars, expense receipts, product images, logo: extension **and** magic-byte check (png/jpeg/webp only), re-encode through GD/Imagick to strip metadata/payloads, random server names, stored **outside the web root** and served via an authenticated/signed streaming endpoint (or a non-executable public `uploads/` dir with `php_flag engine off` where public delivery is unavoidable), per-file ≤2 MB, per-business quota, no SVG.

## 5. Payments

* Server holds the catalogue (plan/cycle/currency/price in `plans` config) — the client sends only `{plan,cycle,currency}`.
* Flow: `checkout` creates `payments(status=initiated, reference=LT-<uuid>, expected_amount, currency, business_id, user_id)` → provider init → client pays → **either** the verified webhook **or** `GET /billing/verify` calls the provider's verify API, and activates only if: provider status success ∧ `reference` known ∧ `amount == expected_amount` ∧ `currency == expected` ∧ payment not already applied. Everything else → `needs_review`.
* Webhook: raw-body HMAC-SHA512 (Paystack, `hash_equals`) / `verif-hash` (Flutterwave) validation; replay window; `webhook_events UNIQUE(provider, event_id)`; processing in a transaction; response 200 for duplicates; unknown/mismatched events logged, not applied.
* Metadata supplied by the client is **never** used to decide plan, business, or amount (fixes S-04).
* Renewal/expiry cron; failed payment → grace period then downgrade (grace length: owner decision, default 3 days, G-11).

## 6. Sync / offline

Per-op authorization at apply time (membership may have been revoked since the op was queued → `permission_revoked` conflict, not silent apply), op-id uniqueness, batch size/rate caps, payload schema validation identical to online endpoints, device registration with ability to revoke a lost device (kills its tokens and quarantines unsynced ops for review).

## 7. Platform / hosting

* Document root = `backend/public/` only (Truehost: subdomain `api.` pointing to that folder, or `public_html/api` with an `.htaccess` front controller and `app/`, `config/`, `storage/`, `.env` kept **above** `public_html`). `.env` outside webroot, `chmod 600`; no secrets in git; `.env.example` only.
* HTTPS enforced (HSTS preload-ready), `Strict-Transport-Security`, `Referrer-Policy: no-referrer`, `Permissions-Policy`, CSP on any HTML we serve (receipt viewer).
* Logging: structured JSON to `storage/logs` (outside webroot), request-id correlation, **no** passwords/tokens/PAN/full e-mail; rotation by cron; 30-day retention.
* Backups: nightly `mysqldump --single-transaction` via cron to off-host storage, encrypted (age/gpg), restore drill documented and rehearsed before cutover.
* Dependencies: Composer lockfile, `composer audit` in CI, minimal deps (prefer none for core).
* Secrets rotation procedure documented (APP_KEY, provider keys, DB password).

## 8. Privacy & account lifecycle

Export (`/me/export`) includes user + owned-business data in JSON; deletion: owner deletion requires password + typed phrase, 14-day grace (soft-delete + login disabled), then purge. Default: purge all business data except the platform's own subscription payment records, which are retained with pseudonymised identity for tax/accounting. The retention period must be confirmed with counsel (G-13); until then the default is 7 years for payment records only. Staff removal revokes their sessions immediately.

## 9. Mandatory security test list → see `10_ACCEPTANCE_TESTS.md` §S

Unauthorized access · cross-business read/write on **every** resource (parameterised test iterates all routes) · cross-outlet · staff escalation (cashier → manager endpoints; body `role` tampering) · admin escalation (register/invite with `role:admin`) · plan tamper · forged/replayed/underpaid payment · duplicate sale (same op-id; concurrent requests) · unauthorized stock adjust · unauthorized/duplicate PO receive · IDOR sweep · invalid/expired/revoked/rotated-reused tokens · upload abuse (polyglot, oversize, SVG, path traversal) · SQLi corpus on filters · rate limiting · CORS · CSV injection.
