# 16 — Web app (Next.js "Track") migration to the PHP API

Status: **Phase 7 started.** Work lives on a **local branch `claude/laughing-hypatia-gejtzf` of the `Track` checkout and has NOT been pushed** (see §7). A patch of it is kept here as `docs/phase7/track-api-mode.patch` (apply with `git am` on top of `Track` `main` at `b51572b`).

## 1. What the web app is today (verified by reading the code)
* Next.js 14 app (≈14,600 lines in `app/`), talking to **Supabase directly from the browser** through one singleton, `lib/supabase.ts`.
* **39 files** call Supabase: **26 tables**, **3 database functions** (`edit_sale`, `receive_purchase_order`, `sale_payment_info`; `complete_sale*` is called through a dynamic name), Supabase Auth (11 different calls), Storage (avatars, branding) and Edge Functions (`invite-staff`, `paystack-webhook`, `expire-plans`, `low-stock-alert`).
* Business rules (totals, tax, stock, loyalty) run **in the browser** and in Postgres functions; the PHP API runs them on the server. That is the whole point of the rebuild, and it is why this is not a find-and-replace.
* There is no automated test suite in `Track` (one unit test file, no runner).

## 2. Strategy: one build, two backends, switched at build time
`NEXT_PUBLIC_API_BASE_URL` (inlined at build time, `lib/api/mode.ts`):
* **unset → legacy.** The existing Supabase code, byte-for-byte behaviour (the legacy providers were only renamed and wrapped). **This is what production keeps building until the owner decides otherwise.**
* **set → API mode.** The same screens read and write through the PHP API.

Pages are moved **one module at a time**:
1. Data access moves out of the page into `lib/data/<module>.ts`, which has a *legacy* implementation (the page's original Supabase queries, moved unchanged) and an *API* implementation that returns the **same shapes the page already renders** (numbers instead of strings, `categories.name`, `sale_number`, …).
2. The route is added to `MIGRATED_ROUTES`. In API mode every route not on the list shows "this screen is moving to the new backend" instead of silently calling Supabase.
3. A real-browser test for the module is added and must pass (§5).

Why a build-time switch and not a runtime flag: a deployment can never flip backend by accident, and customers cannot end up half on one system.

## 3. Inventory: every file that calls Supabase
| File | Lines | Tables | DB functions | Auth | Other |
|---|---:|---|---|---|---|
| `app/admin/page.tsx` | 1329 | audit_logs×3, billing_history×1, businesses×4, profiles×2 | — | — | — |
| `app/auth/callback/page.tsx` | 131 | profiles×1, staff×1 | — | exchangeCodeForSession, getSession, updateUser, verifyOtp | — |
| `app/categories/page.tsx` | 458 | categories×4, products×2 | — | — | — |
| `app/credit/page.tsx` | 688 | debt_repayments×2, sales×2 | — | — | — |
| `app/customers/page.tsx` | 965 | customer_groups×2, customers×4, loyalty_ledger×1, sales×1 | — | — | — |
| `app/dashboard/page.tsx` | 584 | expenses×3, products×1, purchase_orders×1, sale_items×1, sales×6 | — | — | — |
| `app/expenses/page.tsx` | 958 | expense_categories×4, expenses×4 | — | — | — |
| `app/inventory/page.tsx` | 817 | categories×1, products×4 | — | — | — |
| `app/locations/page.tsx` | 739 | locations×4 | — | — | — |
| `app/pos/page.tsx` | 730 | categories×1, customers×2, products×1, sales×1 | — | — | — |
| `app/profile/page.tsx` | 698 | avatars×2, billing_history×1, businesses×1, profiles×4 | — | — | storage |
| `app/profit/page.tsx` | 220 | — | — | — | — |
| `app/purchases/page.tsx` | 1319 | products×1, purchase_order_items×3, purchase_orders×6, suppliers×4 | receive_purchase_order | — | — |
| `app/reports/page.tsx` | 289 | customers×2, products×2, sale_items×1, sales×2 | — | — | — |
| `app/reset-password/page.tsx` | 180 | — | — | getSession, updateUser | — |
| `app/sales/page.tsx` | 644 | debt_repayments×1, sale_items×1, sales×2 | sale_payment_info | — | — |
| `app/settings/page.tsx` | 703 | settings×2 | — | updateUser | functions |
| `app/staff/page.tsx` | 596 | staff×5 | — | — | functions |
| `app/transfers/page.tsx` | 471 | location_transfers×3, locations×1, products×1, transfer_items×2 | — | — | — |
| `app/verify-email/page.tsx` | 338 | — | — | getSession, getUser, resend, verifyOtp | — |
| `app/verify-phone/page.tsx` | 108 | — | — | resend, verifyOtp | — |
| `components/inventory/bulk-csv-import-dialog.tsx` | 803 | categories×1, products×1 | — | — | — |
| `components/low-stock-banner.tsx` | 167 | products×1 | — | — | — |
| `components/pos/customer-select.tsx` | 207 | customers×1 | — | — | — |
| `components/pos/payment-modal.tsx` | 496 | customers×1, products×1 | complete_sale, complete_sale_with_points | — | — |
| `components/sales/edit-sale-dialog.tsx` | 401 | products×1 | edit_sale | — | — |
| `contexts/auth-context.tsx` | 446 | businesses×4, profiles×4, staff×2 | — | getSession, onAuthStateChange, resetPasswordForEmail, signInWithPassword, signOut, signUp | — |
| `contexts/business-context.tsx` | 247 | businesses×5, staff×1 | — | — | — |
| `hooks/use-business-branding.ts` | 44 | business_branding×2 | — | — | — |
| `hooks/use-loyalty-settings.ts` | 58 | loyalty_settings×2 | — | — | — |
| `lib/audit-logs.ts` | 17 | audit_logs×1 | — | — | — |
| `lib/billing.ts` | 11 | billing_history×1 | — | — | — |
| `lib/delete-account.ts` | 58 | businesses×2, profiles×1 | — | signOut | — |
| `lib/export-user-data.ts` | 23 | profiles×1 | — | — | — |
| `lib/profit/load.ts` | 61 | expenses×1, sale_items×1, sales×1 | — | — | — |
| `lib/sales/edit-math.ts` | 44 | — | — | — | — |
| `lib/subscription.ts` | 24 | businesses×1 | — | — | — |
| `lib/supabase.ts` | 572 | — | — | — | — |
| `lib/user-sessions.ts` | 116 | user_sessions×4 | — | — | — |

Counts are call sites of `.from("table")`; "DB functions" are `.rpc(...)`.

## 4. Differences between the two backends that the screens must absorb
| Topic | Supabase app | PHP API | How it is handled |
|---|---|---|---|
| Identity | profile + `businesses` + `staff` rows stitched together in the browser | `/me` returns user + memberships; one business chosen per session (`X-Business-Id`) | `contexts/api-session.tsx` builds the same `useAuth()`/`useBusiness()` shapes |
| Money | JS numbers | decimal **strings** | converted in the data layer; never computed in the page for the API path |
| Stock edit | `stock_quantity` written on the product | read-only on the product; changed by **stock adjustments** | an edit that changes stock becomes an audited *count* |
| Delete product | hard delete | **archive** (old sales keep their item names) | data layer calls `DELETE`, which archives |
| Sale number | `sale_number` | `receipt_number` | adapter (sales module) |
| Sales totals | computed in the browser, sent to `complete_sale` | server computes; client sends items + payment (+ `expected_total`) | POS module rewrite |
| Sign-up e-mail check | Supabase OTP / confirmation link pages | verification **link** token (`/auth/verify-email`) | `verify-email`, `reset-password`, `auth/callback`, `verify-phone` pages need new flows (open) |
| Password rules | web form says "at least 6 characters" | server policy is stricter and explained by the server's message | server message is shown; the hint text on the sign-up form must be updated (open) |
| Pagination | everything in one query | max 200 per page | `fetchAll` helper |
| Realtime/offline | `idb` offline store in the web app | server-side sync protocol (docs/14) | POS offline in the web app is a later decision (open) |
| File storage | Supabase Storage (avatars, branding) | `/me/avatar`, branding endpoints | profile/settings modules |
| Edge functions | invite-staff, paystack-webhook, expire-plans, low-stock-alert | replaced by API endpoints + `bin/cron.php` jobs | no web work, but production crons must exist on the host |

## 5. How it is tested (all run in this repository's tooling, against a throw-away database)
* `tools/e2e/run-web.sh <Track>` — node unit tests of `lib/api/client.ts` (17 tests: headers, query encoding, single-flight refresh, single-flight resume, offline ≠ signed-out, two-tab lock with a control test that proves the collision without it) **plus one test against the real PHP API** (register → sign in → products → forced token expiry → refresh → sign out).
* `tools/e2e/run-web-browser.sh <Track> [scripts…]` — starts the PHP API + the Next.js dev server in API mode and drives **real headless Chromium** (Playwright):
  * `tools/e2e/web/inventory.mjs`: wrong password wording → sign in → add a product → **reload keeps the session** → edit stock (an audited count) → archive → asserts **0 Supabase requests** and that the only failing API call is the deliberate wrong password.
  * `tools/e2e/web/auth.mjs`: real sign-up form → "Check your email" → sign in → an unmigrated screen shows the notice → migrated screen shows the signed-in person → sign out clears the tokens and a protected page redirects to sign-in.
* `next build` in **both** modes (legacy and API) compiles; `tsc --noEmit` is clean.

## 6. Bugs found by these tests (and fixed)
1. **Session lost on every full page load in development**: React runs effects twice, two `resume()` calls used the same single-use refresh token and the second signed the user out. `resume()` is now single-flight.
2. **Two tabs could sign each other out**: the server revokes a session whose rotated refresh token is reused (theft protection). Refresh/resume now run under a Web Locks lock and read the token inside it.
3. **A layout-level component (`LowStockBanner`) still queried Supabase** on every page; found by the "0 Supabase requests" assertion; moved to the data layer.
4. Test-tooling: a stale `next dev` left holding the port made results meaningless; the runner now stops the whole process group and refuses to start if the port is already served.

## 7. Production safety (read this)
* **Nothing in `Track` has been pushed.** Pushing a branch to a repository connected to Vercel can create a *preview deployment*, and previews can be given production environment variables. I could not confirm from the Vercel API which project is `Track` or its preview settings. Before the first push to `Track`: in Vercel, set the project's **Preview** environment to have **no** Supabase production variables (or turn off preview builds / use an Ignored Build Step) — or tell me the branch is safe to push.
* API mode must only ever be built with a **staging** API address. The e2e runners use a throw-away `leentrack_e2e*` database and refuse any other name.
* No change was made to `main`, to Vercel, to Supabase, or to DNS.

## 8. Security notes for the web client
* The refresh token is kept in `localStorage` (same exposure as supabase-js had). Stronger option: an httpOnly cookie set by a Next.js route handler acting as a small backend-for-frontend. Not built; recommended before real customers use API mode.
* The access token lives only in memory.

## 9. Migration order (smallest risk first) and status
| # | Module | Pages | State |
|---|---|---|---|
| 0 | Foundation: client, providers, mode switch, route guard, test harness | — | ✅ done, tested |
| 1 | Auth shell: sign-in, sign-up, sign-out, session restore | login, register | ✅ (browser-tested) |
| 2 | Inventory | inventory (+ low-stock banner) | ✅ (browser-tested) |
| 3 | Categories | categories | next |
| 4 | Customers + groups + loyalty | customers, loyalty hook | |
| 5 | POS (+ decision on offline in the browser) | pos, payment modal, customer select | |
| 6 | Sales, credit, expenses | sales, credit, expenses | |
| 7 | Dashboard, profit, reports | dashboard, profit, reports | |
| 8 | Purchases, suppliers, transfers, locations | purchases, transfers, locations | |
| 9 | Staff, settings, profile, branding, billing | staff, settings, profile, billing | |
| 10 | Verify/reset/callback flows, admin console | verify-email, verify-phone, reset-password, auth/callback, admin | |
Estimate: modules 3–10 are each a session of work with the same pattern (data layer + adapter + browser test).

## 10. Not done / not verified
* Only **inventory** and the **auth shell** are migrated; everything else shows a notice in API mode.
* The sign-up form's password hint and the e-mail verification screens still describe the Supabase flow.
* Not tested on a phone-sized viewport, in Safari/Firefox, or against `api.leenc0.com` (that server was returning HTTP 500 when last checked).
* No visual review beyond one screenshot of the Inventory page running on the new API.
