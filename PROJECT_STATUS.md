# LeenTrack Rebuild — Project Status

_Last updated: 2026-11-02 · Branch: `claude/laughing-hypatia-gejtzf` · Current phase: **Phase 1 — Foundation: COMPLETE (verified below); Phase 2 next**_

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
| 2 Database & core (auth, tenancy, policies) | ⏳ **next** |
| 3–10 | ⏳ not started |

## Session 2 report (Phase 1)
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
Phase 2, step 1: MySQL migrations `0002…` for identity (`users`, `user_profiles`, `api_sessions`/refresh tokens, `password_resets`, `email_verifications`, `platform_admins`), tenancy (`businesses`, `business_members`, `locations`, `member_outlets`), `audit_logs`; then `AuthService` (register / login with legacy-bcrypt verify-then-upgrade / refresh rotation with reuse detection / logout / forgot-reset), `TenantContext` + `Policy` classes, and the security tests T-AUTH-1…5, S-UNAUTH, S-XBIZ, S-ESC-ADMIN, S-TOKEN.
