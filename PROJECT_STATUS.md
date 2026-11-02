# LeenTrack Rebuild — Project Status

_Last updated: 2026-11-02 · Branch: `claude/laughing-hypatia-gejtzf` · Current phase: **Phase 2 — Database & backend core: COMPLETE for identity, tenancy, authorization (verified below); Phase 3 next**_

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
| 3 Business operations | ⏳ **next** (3a catalogue/stock first) |
| 4–10 | ⏳ not started |

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
Phase 3a — catalogue & stock: migration `0004` (categories, products with `item_type`, `stock_levels`, `stock_movements`), `ProductService`/`CategoryService`/`StockService` with plan limits (`products`, `categories`), barcode uniqueness per business, archive-if-referenced, stock adjustments with reasons and movement history (invariant `Σ levels = stock_quantity`), CSV import (validate → commit, same alias rules as the source) and injection-safe CSV export; tests T-INV-1, T-STK-1, S-STOCK, S-XBIZ for every new route. Also add `bin/cron.php housekeeping` (purge rate limits and expired tokens).
