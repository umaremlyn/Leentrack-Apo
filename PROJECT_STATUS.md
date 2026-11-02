# LeenTrack Rebuild — Project Status

_Last updated: 2026-11-02 · Branch: `claude/laughing-hypatia-gejtzf` · Current phase: **Phase 0 — Source Audit: COMPLETE (awaiting owner review)**_

## Ground rules in force
* Production (Vercel + Supabase) is **never touched**. `umaremlyn/Track` was only read; it has no modifications.
* No production data, schema dump or credentials are available → no data-migration or account-continuity claim can be made (docs/06).
* The PHP API is the single foundation; Flutter and Next.js are both clients of `/api/v1`.

## Phase table
| Phase | State |
|---|---|
| 0 Source audit | ✅ docs 01–10 written (static analysis only) |
| 1 Foundation | ⏳ not started |
| 2–10 | ⏳ not started |

## Session 1 report
**Completed:** read all migrations (001–013, 016–019 + rollbacks), the 4 edge functions, the legacy `scripts/`, all of `lib/`, `contexts/`, `hooks/`, and the Supabase call sites across `app/` and `components/` (178 call sites catalogued); read the POS/payment, auth, billing, dashboard, credit, transfers, reports, staff, admin and CSV-import code paths in detail. Wrote the ten audit documents.

**Files created (all in `Leentrack-Apo`):** `docs/01_SOURCE_CODE_AUDIT.md` … `docs/10_ACCEPTANCE_TESTS.md`, `PROJECT_STATUS.md`.
**Files modified in `Track`:** none.

**Tests executed:** none. No code was written or run. (The only source test, `lib/plan-limits.test.ts`, has no runner and was not executed.)

**Headline findings (details in docs/01):**
* Migrations do **not** describe production (receipt_number/payment_status/outlet_id/tax_rate/group_id etc. are used but undefined; 014/015 missing; 016–019 marked not applied).
* Code-evident critical issues in the current product: self-service platform admin via sign-up metadata role (S-01), owner can set any user's role via `invite-staff` (S-02), unauthenticated-in-effect `SECURITY DEFINER complete_sale` (S-03), plan granted from client metadata without amount check (S-04). **Not confirmed against the live project (not allowed); the owner should review these independently of the rebuild.**
* Transfers move no stock; stock is not per-outlet; offline sync does not work end-to-end; no Flutterwave code exists; roles are UI-only.

**Environment:** PHP 8.3.6, Node 22; **no MySQL, no Flutter SDK** in this sandbox (Phase 1 will try to install; iOS/macOS builds will need Apple tooling regardless).

## Outstanding issues / owner actions (see docs/09)
G-01 schema-only dump · G-02 data + `auth.users` export (when ready) · G-03 which of 016–019 are applied · G-05 per-outlet inventory decision · G-09 Flutterwave yes/no · G-10 SMS gateway · G-11/12 plan-limit and `total_spent` semantics · G-14 where the Next.js refactor lives · G-16 Truehost PHP facts.

## Exact next task
Phase 1 step 1: scaffold `backend/` (front controller, router, request/response envelope, error handler, env config, PDO factory, migration runner with `schema_migrations`, logger, rate-limit table), add `bin/test.php` and `GET /api/v1/health`, run the tests, and attempt a local MySQL 8.x install to validate migrations. Begin after the owner has reviewed `docs/` (particularly `09_KNOWN_GAPS.md` decisions) — or immediately, using the stated defaults, if told to proceed.
