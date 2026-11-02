# 08 — Implementation Roadmap

Principle: **the PHP API is the foundation.** Flutter and the Next.js app are both clients of the same `/api/v1` contract; neither gets private endpoints. The production Vercel/Supabase app is untouched throughout; Phase 7 work happens on a branch of `Track` and is deployed only to a preview, pointed at a **staging** API with synthetic data, until the owner authorizes cutover (Phase 10).

## Repository layout (decision D-01)

`umaremlyn/Leentrack-Apo` (this repo, currently an empty v0 stub) becomes the monorepo for the **new platform**:

```
backend/            PHP 8.4 API (public/, app/, config/, routes/, database/migrations, tests/, bin/)
apps/flutter/       single Flutter codebase (android, ios, windows, macos, linux)
docs/               this audit + architecture + runbooks
tools/              fixtures, golden-test harness (Node, dev-only, reads Track's lib/*.ts)
```
`umaremlyn/Track` stays the production web app; its Phase-7 refactor lands there on a feature branch only. *Open question for the owner:* whether Phase 7 should instead live in a copy inside this monorepo (G-14). Default = branch in `Track`, never merged without authorization.

## Environment reality check (verified in this session)

PHP 8.3.6 available (target 8.4 — syntax kept 8.3-compatible and CI on 8.4 later); **no MySQL/MariaDB, no Flutter SDK, no Composer verified**; Node 22 present. Consequences: SQL migrations and MySQL-specific behaviour (row locks, `FOR UPDATE`, isolation) can only be proven where a MySQL 8.4 is available — I will try to install MySQL in the sandbox (Phase 1) and, if impossible, state that DB-level tests were not run. Flutter builds/tests likewise need the SDK (Phase 1 attempts install; iOS/macOS builds need macOS+Xcode and **cannot** be built here — documented, not claimed).

## Phases

| Phase | Deliverable | Exit criteria (must be demonstrated, not asserted) |
|---|---|---|
| **0 Audit** (done this session) | docs 01–10, `PROJECT_STATUS.md` | docs committed on branch; findings cite files |
| **1 Foundation** | `backend/` skeleton: front controller, router, request/response, error handler, config/env, PDO factory, migration runner (+`schema_migrations`), logger, rate limiter, PHPUnit-compatible test runner; Flutter project scaffold + CI scripts; `ARCHITECTURE.md`, `DEVELOPMENT.md`, ADR log | `php bin/test.php` green; `GET /api/v1/health`; migration up/down on real MySQL if available |
| **2 DB & core** | MySQL schema migrations (identity, memberships, businesses, locations, audit, tokens, rate limits, plans/payments/webhooks skeleton), auth endpoints, tenant context, policies, plan guard | security tests T-AUTH-*, T-TENANT-* pass; legacy-bcrypt transition test passes (fixture hash) |
| **3 Business ops** (module by module, each "done" only with API+tests+docs) | 3a catalogue/stock · 3b customers/groups/loyalty · 3c **POS checkout + sales + documents** (golden tests vs TS) · 3d credit/repayments · 3e expenses + profit + dashboard · 3f suppliers/purchases · 3g transfers (+per-location stock, G-05) · 3h reports/CSV · 3i staff/invites · 3j billing (Paystack; Flutterwave after G-09) · 3k admin · 3l settings/branding/cron | per-module acceptance IDs in `10` green |
| **4 Offline engine** | `sync_operations`, conflicts, push/pull endpoints; Flutter SQLite layer (drift or sqflite — decision after plugin check on all 5 platforms), queue, retry/backoff, conflict screen | multi-device simulations: duplicate delivery, last-unit race, crash-restart, clock skew |
| **5 Flutter mobile** | auth, dashboard, POS, inventory, sales, customers, credit, offline/sync UI, then remaining modules | widget/integration tests; Android build produced; iOS build **procedure** documented (needs macOS) |
| **6 Flutter desktop** | adaptive layouts, keyboard, tables, USB/BT printer, scanner | Windows/Linux builds where toolchain exists; macOS procedure documented |
| **7 Web → API** | `lib/api-client.ts`; replace Supabase data access route-by-route behind an env flag `NEXT_PUBLIC_DATA_BACKEND=supabase|php` (default `supabase`) so production behaviour is unchanged by default | every route passes the same workflow tests on both backends against staging |
| **8 Migration tooling** | importer, reconciler, runbooks (`cutover`, `rollback`), fixtures | fixture reconciliation green; **real data run blocked until P1–P7 supplied** |
| **9 Pre-prod** | regression, security (full list in 07 §9), performance (k6/ab on Truehost-like limits), release candidates, accessibility pass | no open Critical/High; load targets met (to be set: e.g. p95 < 400 ms for checkout at 20 req/s on shared PHP) |
| **10 Cutover** | per `06` §4 | **only on explicit owner authorization** |

## Architectural decisions recorded so far

* D-01 monorepo as above · D-02 opaque tokens in MySQL (revocable; no JWT key rotation burden) · D-03 no PHP framework for core (keeps shared-hosting simple; small PSR-4 app + tiny router; Composer used for dev tooling and a few vetted libs such as a PDF/e-mail lib) · D-04 loyalty executes inside the sale transaction (stricter than source) · D-05 money as DECIMAL/strings, bcmath · D-06 logic in services not triggers · D-07 UUID ids preserved from source · D-08 stock per location + global total (pending G-05) · D-09 receipts: server-side PDF (e.g. mPDF/TCPDF, Composer) for parity across clients, plus JSON document model so Flutter can render natively/thermal · D-10 Flutter state = Riverpod, HTTP = Dio, local DB candidate = drift (SQLite) · D-11 BFF cookie proxy for web refresh tokens.

## Risk register (top)

1. Unknown production schema (mitigation: P1 first, importer driven by dump). 2. Sandbox lacks MySQL/Flutter (mitigation: attempt install; be explicit in reports). 3. Shared-hosting limits (max_execution_time, memory, no long workers → chunked importer, cron only). 4. Scope creep: ~15 "NEW" capabilities listed in 02 — each needs owner sign-off on semantics before building. 5. Hardware printing differences per OS. 6. iOS/macOS builds need Apple tooling.

## Next concrete task (after owner reviews the audit)

Phase 1, step 1: create `backend/` skeleton + `bin/test.php` + `GET /api/v1/health` + migration runner, run the tests, and attempt a local MySQL 8.x install to validate migrations.
