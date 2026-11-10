# 09 — Known Gaps, Unknowns and Decisions Needed

Each item: what is missing, why it matters, what I did/will do meanwhile, and who must act. IDs are referenced from other docs.

## 0. Resolution log (updated in Phase 3i, 2026-11-03)

Rule applied to every gap: if the production product does something and the rebuild differed, or the rebuild had a limitation that is fixable in code, it was fixed; if it needs a fact only the owner has, it stays open and says exactly what is needed; if it is a business decision, the implemented default is stated so it can be changed.

**Legend:** ✅ fixed in code (tested) · 🟡 implemented default — your decision, easy to change · ⏳ deferred to a named phase · ❓ needs information or a decision from the owner · ➖ intentional difference from production (a fix; see section C)

| ID | Status | What was done / what is still needed |
|---|---|---|
| G-01 | ❓ | Needs `pg_dump --schema-only` of production. Nothing in code can replace it. |
| G-02 | ❓ | Needs the data + `auth.users` export (rehearsal only, never production writes). |
| G-03 | ❓ | Needs the list of applied migrations (016–019). |
| G-04 | ❓ | Needs Supabase Auth settings (password policy, e-mail templates). Defaults documented. |
| G-05 | ➖ | Per-outlet stock is implemented (production had one global number). |
| G-06 | 🟡 | One payment method per sale (as production). Split payments only if you want them. |
| G-07 | 🟡 | Managers and owners can void; stock and customer totals are reversed. |
| G-08 | 🟡 | Cashiers see Sales and Customers only (as production). |
| G-09 | ❓ | Flutterwave does not exist in production. Build only if you confirm it is wanted and give sandbox keys. |
| G-10 | ❓ | SMS verification needs a gateway choice (Termii/Twilio…). |
| G-11 | 🟡 | Over-limit businesses keep what they have; new items are blocked. **Payment-failure grace period: none, exactly like production** (access stops at expiry; the earlier "3-day grace" default was dropped). Say if you want one. |
| G-12 | 🟡 | `total_spent` = money actually received. |
| G-13 | ✅/❓ | Retention is now implemented: 14-day grace, then the purge job deletes the owner's business data and anonymises the person (Phase 3i). The legal period for payment records (7 years default) still needs counsel and applies when billing exists (3j). |
| G-14 | 🟡 | Web migration lives on a branch of `Track`, never auto-deployed. |
| G-15 | ✅/❓ | Profile pictures: upload/read/remove API built (type decided from the bytes, 1 MB, stored outside the web root). Importing existing Supabase avatars needs the storage export. |
| G-16 | ❓ | Needs `phpinfo()` / cPanel facts. Code uses only common extensions (PDO, mbstring); the PDF writer needs no GD. |
| G-17 | ❓ | Apple / Google / signing accounts. |
| G-18 | ❓ | Sender domain + SPF/DKIM. Code is ready (see G-22). |
| G-19 | 🟡 | One currency per business (as production). |
| G-20 | 🟡 | One tax rate per business (as production). |
| G-21 | ✅ | Soft enforcement built: a migrated user whose password fails today's policy is signed in (never locked out) and `must_change_password=true` is returned by login and `/me`; changing the password clears it. Forcing the change is a one-line client decision. |
| G-22 | ✅/❓ | `SmtpMailer` built (AUTH PLAIN/LOGIN, STARTTLS or implicit TLS, injection-safe, failures logged without secrets). Tested against a local fake SMTP server. **Not tested:** the TLS handshake against a real server. Needs SMTP credentials and SPF/DKIM from you. |
| G-23 | ⏳ | Client forms must call `/stock/adjustments` — done in the web migration (Phase 7). |
| G-24 | ➖ | Imports now enforce plan limits and unique barcodes (production did not). |
| G-25 | 🟡 | Owner + manager edit groups; cashiers read only. Production was contradictory. |
| G-26 | ➖ | Exact-cent arithmetic instead of floats (2,268 + 700 golden cases match the original). |
| G-27 | 🟡 | Duplicate customers are allowed, exactly as in production. Say if you want a warning or a merge tool. |
| G-28 | ✅ | `total_spent`, `visit_count`, `last_visit` are maintained since Phase 3c. |
| G-29 | 🟡 | Server-side sales-limit blocking is OFF (production only showed a banner). `ENFORCE_SALES_LIMIT=true` turns it on. |
| G-30 | ➖ | Selling takes stock from the selling outlet (required for correct outlet stock). Transfers (3g) move stock between outlets. |
| G-31 | ✅ | `GET /sales/{id}/document?format=pdf` now returns a real PDF (dependency-free writer, Courier typeface; verified with `pdftotext` and `pypdf`). Limits: Latin-1 text, currency code instead of symbol, no logo (JSON still carries it). The source's pixel-perfect canvas design is not reproduced. |
| G-32 | 🟡 | `refund_due` is returned and audited; no till/cash drawer ledger (production had none). Decide if you want one. |
| G-33 | 🟡 | Discount breakdown after an edit is informational; totals are exact. |
| G-34 | ⏳ | Importer rule recorded for Phase 8. |
| G-35 | ✅ | Repayment immutability stays application-level (DB triggers are not used: shared hosting often withholds the TRIGGER privilege), and the nightly `reconcile` job now independently re-checks 16 invariants (stock, customer totals, loyalty, credit balances, repayments, purchase receipts, supplier payments, transfers, paid plans, payments) and tells the platform admins by e-mail when anything does not add up. It reports; it never edits. |
| G-36 | ➖ | Expense notes are saved (production dropped them). Expenses are not plan-gated (as production). |
| G-37 | 🟡 | An outlet filter excludes expenses that belong to no outlet; they are reported as `unallocated_expenses` in the outlets report. |
| G-38 | ✅ | A filter naming another business's outlet (or a non-existent one) is now 404 on every list/report/dashboard route. |
| G-39 | ✅ | Dashboard `pending_purchase_orders` built in 3f. |
| G-40 | ✅ | Supplier payments built: pay against received goods only (no prepayment, no overpayment), idempotent, immutable, supplier balance + statement + report columns, race-safe. Payments are not expenses. |
| G-41 | ✅ | Purchase date filters use the business time zone. |
| G-42 | ➖ | Purchase routes are not plan-gated (as production). |
| G-43 | ✅ | Returning goods to a supplier: stock adjustment reason `supplier_return` (removes stock, audited). A full credit-note workflow with the supplier is not built. |
| G-44 | 🟡 | Receiving into an archived product is refused with an instruction to restore it. |
| G-45 | 🟡 | No transfer approval step (production had none; the brief called it optional). |
| G-46 | 🟡 | Stock lost in transit is recorded as variance (view + audit); write-off is a manual adjustment. |
| G-47 | 🟡 | Archived products already in a transfer still complete. |
| G-48 | ✅ | Transfer date filters use the business time zone. |
| G-49 | ✅/🟡 | Customers CSV added (the one production export that was missing from `/reports`). No charts, pivots, scheduled e-mail or XLSX (production had none). |
| G-50 | ✅ | The report row cap is configurable (`REPORT_MAX_ROWS`) and now tested. |
| G-51 | 🟡 | Profit/outlets reports require `profit.read`; today equal to `reports.read` for all roles. |

New in Phase 4 (offline sync):

| ID | Status | Note |
|---|---|---|
| G-78 | ✅ | **Owner decision (Phase 6): offline sales are forced in.** If two devices sell the last unit, both sales are recorded; the shelf count goes negative (e.g. −1) and is shown as such until someone counts and corrects it. Nothing waits for a manager. The online till is **not** relaxed: it still refuses to sell stock the server does not have. The nightly reconciliation lists negative levels as "count and correct the stock". `insufficient_stock` can therefore no longer come from a sync push. Cost: costs/profit for forced sales use the product's current cost and stock reports are briefly wrong until the count. |
| G-79 | 🟡 | Offline operations in version 1: sale checkout, credit repayment, new customer, new expense, stock *change*. Sale edits/voids, purchases, transfers, stock *counts*, product edits and billing stay online-only (docs/14 §8). |
| G-80 | 🟡 | A device that quotes prices offline can send `expected_total`; if today's price differs the sale is held as `price_changed` until a manager accepts the new price. Without `expected_total` the sale is simply recorded at server prices. Which one the app uses is a Phase 5 product decision. |
| G-81 | 🟡 | The pull cursor protects against transactions that commit late with a 10-second overlap. A change committed more than 10 seconds late (a stalled request) could still be missed until the next full resync; the device should do a full resync (no cursors) occasionally, e.g. daily. |
| G-82 | 🟡 | Provisional offline receipts carry no server receipt number until the sale syncs. |
| G-83 | ⏳ | Not built: push notification when a conflict is resolved (devices poll `GET /sync/operations`); merging two devices' edits to the same existing record (only creation is offline). |

New in Phase 3l (settings, jobs, reconciliation):

| ID | Status | Note |
|---|---|---|
| G-71 | 🟡 | The low-stock alert address is not verified: an owner could point alerts at someone else's mailbox. Mitigated by a 5-per-hour limit on test mails, the 20 h / 1 h cool-down on the job, and a plain text body that carries only product names. A confirmation link would be the next step. |
| G-72 | 🟡 | The alert e-mail is plain text (production sent a styled HTML mail) and covers the whole business, not one outlet. |
| G-73 | ⏳ | `paid_plans_have_an_expiry` will flag any imported business that production left on a paid plan without an expiry (its admin tool allowed that). Phase 8's importer must give such businesses an expiry or downgrade them, and say so in its report. |
| G-74 | 🟡 | The SMS and marketing preferences are stored (as production stored them in the browser) but nothing sends SMS (G-10) and there are no marketing e-mails. Transactional mail (verification, reset, deletion notice, receipts) is always sent. |
| G-75 | 🟡 | Language and notification/privacy preferences now live on the server, per person (production: per browser). The time zone stays a property of the business (its default outlet), as everywhere else in the API. |
| G-76 | 🟡 | Job locks are files on the host's disk. On a single shared-hosting account that is exact; if the API ever runs on several servers with separate disks, overlapping runs would need a database lock instead. |
| G-77 | 🟡 | `GET /admin/health` replaces the guesswork of G-16 for the host: PHP version and extensions, DB version, pending migrations, writable folders, mail/payment/CORS configuration and job freshness, never secrets. |

New in Phase 3k (platform administration):

| ID | Status | Note |
|---|---|---|
| G-65 | ➖ | Production's admin could set a paid plan with **no expiry** (it would then never end), delete a business or a profile with one click and no record of why, and a "broadcast notice" was only an audit-log row nobody saw. Now: a paid plan needs an expiry, every change needs a written reason, destructive ones need the admin's password again, business deletion needs the business name typed, user deletion uses the same 14-day grace as self-service, and notices are really shown to users (`GET /notices`). |
| G-66 | 🟡 | There is no "impersonate / sign in as" feature, on purpose. Admins see cross-tenant data only through `/admin/*`; admin rights do not open any tenant route. |
| G-67 | 🟡 | The source's "pricing form" only showed a message; prices are code constants (`PriceTable`) validated against production by tests, so changing a price is a deliberate code change + deploy, not an admin form. Say if you want editable prices. |
| G-68 | 🟡 | Notices go to every signed-in user; no per-business targeting, no e-mail broadcast. |
| G-69 | 🟡 | Suspending a business makes it behave as the free plan (production's `plan_status` semantics); it does not lock the owner out. A full lock-out would be a different status — say if you want it. |
| G-70 | 🟡 | Admins are created/revoked only with `php bin/make-admin.php` (the last admin cannot be revoked). There is no admin-management endpoint, deliberately. |

New in Phase 3j (billing):

| ID | Status | Note |
|---|---|---|
| G-58 | 🟡 | Buying a **different** plan starts it today; the unused time of the previous plan is not credited (production reset the expiry the same way). Renewing the **same** plan adds time after the current expiry (production lost the remaining days). |
| G-59 | 🟡 | You cannot buy a cheaper plan while a dearer one is running (409 `downgrade_not_allowed`); production let you, silently wasting the dearer plan. |
| G-60 | ❓ | **Nothing has been run against the real Paystack.** The gateway is tested against a local stand-in server and recorded-shape fixtures. Needs a Paystack **test** secret key and your webhook URL registered, then one end-to-end test payment. |
| G-61 | 🟡 | Subscriptions are one-off charges renewed by the owner (as production: its `subscription.disable` handler had nothing to disable). No automatic card recurring billing and no "payment due" reminders. |
| G-62 | ✅ | A refund notice marks the payment `refunded` but does not remove access by itself; the platform admin now decides with `POST /admin/businesses/{id}/plan` or `/status` (reason + password, audited). |
| G-63 | 🟡 | Flutterwave remains unbuilt (G-09); the gateway interface is ready for it. |
| G-64 | ❓ | The webhook endpoint is public by design (authenticated by the provider's HMAC-SHA512 signature). Paystack also publishes source IP addresses; an IP allow-list at the web server would add a second layer but is host-specific. |

New in Phase 5 (Flutter app), recorded here so nothing is silent:

| ID | Status | Note |
|---|---|---|
| G-84 | ✅ | Found by the end-to-end test and fixed. **Owner decision (Phase 6): never put a debt on a customer.** Accepting a higher price when the cash handed over is short is refused with `409 payment_short` and the held operation is left untouched (record the difference with the customer and sell online, or discard). No automatic credit conversion will be built. |
| G-85 | 🟡 | (Updated in Phase 6 — see G-93 for the online screens now built.) Offline screens built in Phase 5: sign-in, business choice, POS (search, cart, discount inputs, customer, cash/card/transfer, credit), provisional receipt, customers (create offline), stock view, sync status and the manager inbox. **Not built yet (needs the server's full picture, Phases 6–7):** sales history, credit sales, expenses, purchases, transfers, reports, staff, billing, settings editing, barcode camera scanning, printing. These show an honest "later phase" message. |
| G-86 | 🟡 | Amounts on screen have no currency symbol/code yet (the business currency is stored from `settings`). Cosmetic, but a till should show it; planned with the Phase 6 design pass. |
| G-87 | 🟡 | Manual discount, points redemption and group discount are *inputs* the till sends; it shows a provisional total from a port of the server maths (verified against the 2,268 golden vectors) and sends it as `expected_total`. **Owner decision G-80 stays open:** the app sends `expected_total` always, so a price change after the quote holds the sale for a manager instead of silently charging a different amount. |
| G-88 | 🟡 | Device id is created once per installation and kept in the platform keychain/keystore; it survives sign-out. Reinstalling creates a new device id (harmless: operations are idempotent by their own id). |
| G-89 | 🟡 | One SQLite file per user per business. Signing out keeps the file (so queued sales are not lost); there is no "wipe this device" button yet, and the file is not encrypted at rest (the OS app sandbox protects it; SQLCipher is a Phase 9 hardening option). |
| G-90 | ❓ | **No native build was possible in this sandbox** (no Android SDK, no Linux desktop toolchain; iOS/macOS need a Mac). Verified: `flutter analyze` clean, 68 unit/widget tests, 6 end-to-end tests against the real PHP API and MySQL. Not verified: that the app compiles and runs as an APK / IPA / Windows / macOS / Linux binary, `flutter_secure_storage` and `sqlite3` native libraries on each OS, and real-device behaviour (camera, keyboard, offline switching). |

New in Phase 6 (desktop & more screens):

| ID | Status | Note |
|---|---|---|
| G-91 | 🟡 | Keyboard-first POS: **F2** focus search · **Enter** adds (a barcode scanner that types the code + Enter adds the exact barcode/SKU match; one remaining match also adds; several matches or none show a message and change nothing) · **F4** clear cart · **F9** payment · **Esc** clear search. Tested with simulated key events, **not with a physical scanner or on a desktop OS**. |
| G-92 | ❓ | Receipts: the app downloads the server's PDF (`GET /sales/{id}/document?format=pdf`) and saves it in the documents folder. **Sending it to a printer is not built** (it needs a native printing plugin I cannot test here); open the saved PDF and print it with the system. Thermal/ESC-POS printers: not built. An offline sale has only the provisional on-screen receipt until it syncs (G-82). |
| G-93 | 🟡 | In the app (online-only, server figures never recomputed): sales history, **credit sales with repayments**, **expenses** (list, add, delete), **purchases and suppliers** (create/place/receive/pay/cancel/close orders; add suppliers), **sale detail and void** (managers; the app tells you the refund due, it never moves money), **staff** (owner: invite, change role, suspend/reactivate, resend invitation, remove), **reports** (every report the server allows, with period choice), **dashboard**. Still not in the app: **sale edit** (void and re-record, or use the web app), transfers, supplier edit/archive/statement, order editing, profit page, staff outlet restrictions, locations, billing, settings editing, expense categories management, expense editing, sale edit/void, CSV/PDF report export. Use the web app for those meanwhile. These screens were tested with widget tests (HTTP stub using response shapes captured from the real API) and the field names they read are checked against the real API in the end-to-end suite; **never seen on a real screen**. |
| G-94 | ❓ | Build and release procedures are written (`docs/15_BUILD_AND_RELEASE.md`) but **no platform binary has been built** (G-90). |

New in Phase 3i, recorded here so nothing is silent:

| ID | Status | Note |
|---|---|---|
| G-52 | 🟡 | There is no **ownership transfer**. A business owner who still has team members cannot request account deletion (409 `owns_business_with_staff`); they must remove the team first. |
| G-53 | ✅/❓ | The cron jobs still have to be scheduled on the host (`php bin/cron.php list` prints the table), but a job that stops running is now visible: `GET /admin/jobs` flags it and `reconcile` reports accounts whose deletion date passed. |
| G-54 | ✅/❓ | Payment records are now kept when a business is deleted, pseudonymised (business and payer removed), and deleted by the expiry job after `PAYMENT_RETENTION_YEARS` (default 7). The legal period needs counsel (G-13). |
| G-55 | 🟡 | `GET /me/export` is personal data only; business records are exported by the owner section by section (`GET /businesses/{id}/export`, 1,000 rows per page) to keep memory flat on shared hosting. |
| G-56 | 🟡 | Staff outlet restriction (`PUT /staff/{id}/outlets`) needs the paid `locations` feature, like multi-outlet itself. Owners are never restricted. |
| G-57 | 🟡 | PDF documents exist for sales receipts/invoices; payment receipts (repayments) are still JSON only. |

## A. Missing information (blocking or limiting)

> The rows below keep their original wording for traceability. **The resolution log above is authoritative** where the two differ.

| ID | Gap | Impact | Meanwhile | Needs |
|---|---|---|---|---|
| G-01 | **No production schema dump.** Migrations ≠ production (01 §3: `receipt_number`, `payment_status` enum, `outlet_id`, expenses DDL, `tax_rate`, `group_id`, `quantity_requested`, `is_business_owner`, migrations 014/015). | Importer column map can't be finalised; some target columns are guesses | Mapper written against *union of evidence*, every guessed column tagged `?`; importer fails loudly on unknown/missing columns | Owner: `pg_dump --schema-only` (P1) |
| G-02 | **No production data / `auth.users` export.** | Cannot certify migration, row counts, financial totals, or that legacy bcrypt hashes verify | Fixture-only tests; tooling labelled unverified | Owner: P3, P4 |
| G-03 | Which of migrations 016–019 are applied in production. | Whether `unit_cost`, loyalty, branding, `sale_edits` exist; affects profit history accuracy and importer | Importer tolerates absence of each | Owner (P2) |
| G-04 | Supabase Auth configuration (password policy, e-mail templates, confirm-e-mail setting, SMTP, SMS provider). | UX parity of verification/reset e-mails; whether unverified users can log in | Templates re-created from app copy; verify-required setting assumed ON | Owner screenshots/export |
| G-09 | **Flutterwave**: brief requires it "where the existing product requires" — repo contains **no Flutterwave integration** (only an allowed `provider` value). | Don't build speculative payment code | Provider-abstracted `PaymentGateway` interface; Paystack implemented; Flutterwave deferred | Owner: confirm whether Flutterwave is wanted (new feature) and supply sandbox keys |
| G-10 | **Phone/SMS verification**: web calls Supabase SMS OTP; no SMS gateway known. | `/verify-phone` can't be reproduced | Endpoint contract reserved; feature-flagged off | Owner: choose gateway (e.g. Termii/Twilio) |
| G-15 | Storage bucket `avatars` contents & policy; any product images in storage. | Avatar continuity | Importer step for files, optional | Owner export (P5) |
| G-16 | Hosting facts for Truehost plan: PHP extensions (`pdo_mysql`, `mbstring`, `gd`/`imagick`, `bcmath`, `sodium`), `max_execution_time`, memory, cron granularity, SSH availability, outbound HTTPS to Paystack, Composer availability. | Library choices, importer chunking | Code uses only common extensions and degrades (`bcmath` fallback to pure-PHP decimal) | Owner: `phpinfo()` or cPanel details |
| G-17 | Apple developer account, Google Play account, code-signing for Windows/macOS. | Store/distribution | Build docs only | Owner |
| G-18 | Domain/sub-domain plan (`api.`?), e-mail sender domain (SPF/DKIM) for transactional mail (source uses Resend with `onboarding@resend.dev` sandbox sender). | Deliverability | Mailer interface: SMTP via cPanel or Resend API | Owner |
| G-21 | **Weak legacy passwords.** Supabase allowed shorter/weaker passwords than our policy. Migrated users keep access on their first login (no strength check, by design, so nobody is locked out), but their password stays weak. | Weak credentials persist | Planned: `must_change_password` flag + prompt after legacy login (Phase 3i) | Owner decision on enforcement (prompt vs. force) |
| G-22 | **Mail transport.** Production default is PHP `mail()` via the host's sendmail (no SPF/DKIM guarantee). Source used Resend. | Verification/reset mails may land in spam | `Mailer` interface ready for SMTP/Resend | Owner: choose SMTP/Resend and set up SPF/DKIM |
| G-23 | **Client edit forms must change.** The web/Flutter product edit form sends `stock_quantity`; the API now rejects it. Clients must call `/stock/adjustments` (with a reason) when stock is edited. | Phase 7 web refactor must split the form submit into PATCH + adjustment | API contract documented | — |
| G-24 | Source CSV import has no plan-limit check and accepts duplicate barcodes; the API enforces both. Imports that "worked" before may now be refused. | Behaviour change (security/consistency fix) | Row-level messages explain why | Owner awareness |
| G-25 | **Who may edit customer groups.** Source is contradictory: migration 018 says "owner-only (unchanged)" but the live policy from 011 lets any business member write. | Cashiers could (or could not) change discounts | Implemented owner + manager; cashiers read-only (they need the discount at checkout) | Owner confirmation |
| G-26 | The original maths uses floating point; PHP uses exact cents. 2,268 golden cases matched exactly, but a rare float artefact (e.g. `0.30 / 0.10 = 2.9999…`) would make the TS give 2 points where exact maths gives 3. | ≤1 point / 1 cent on freak inputs | Exact result is authoritative; documented in `MoneyTest` | — |
| G-27 | No duplicate detection for customers (same phone/e-mail twice). The source allowed duplicates. | Messy lists | Not enforced; a merge tool is a future feature | Owner decision |
| G-28 | `total_spent`, `visit_count`, `last_visit` stay 0 for new customers until the sale service (3c) maintains them; imported values are kept as-is. | Customer stats incomplete until 3c | Scheduled | — |
| G-29 | **Plan sales limit** (free 200 / growth 5,000). The source showed a banner but never blocked a sale. Server enforcement exists behind `ENFORCE_SALES_LIMIT` (default **off**). Turning it on would stop the till for any free business already past 200 sales. | Revenue-affecting decision | Off by default; `meta.sales_limit_reached` for the banner | Owner decision |
| G-30 | **Per-outlet stock now gates selling.** A sale takes stock from the selling outlet; a business that sells from a branch whose stock was never recorded there will see "Only 0 left". The importer seeds all legacy stock at the default outlet. | Multi-outlet customers must record/transfer stock to branches before selling from them | Documented; transfers move stock in 3g | Cutover communication |
| G-31 | **Server-side PDF is not built.** `GET /sales/{id}/document` returns the full document model (JSON, incl. share text and WhatsApp number); `format=pdf` answers 501. Web keeps its canvas→PDF; Flutter renders/prints from the JSON. | No server PDF | Planned with the Flutter print work (Phase 5/6) | — |
| G-32 | **Voids and refunds record what is owed back, but no cash is tracked.** `refund_due` is returned and audited; there is no till/drawer ledger (the source had none). | Cash-out must be handled manually | As the source | Owner decision if a till ledger is wanted |
| G-33 | After an edit the discount breakdown (`group/manual/points`) is informational: group discount is zeroed and the rest is attributed to manual/points. | Reporting by discount type is approximate for edited sales | Totals are exact | — |
| G-34 | Legacy customers may have only `first_name`/`last_name` (the web app's `customerDisplayName` falls back to them). The importer must set `name = COALESCE(NULLIF(TRIM(name),''), TRIM(CONCAT_WS(' ', first_name, last_name)), 'Unknown')`. | Customers could import without a name | Recorded for Phase 8 | Needs the schema dump (G-01) to confirm columns |
| G-35 | Repayment immutability is enforced by the application (no update/delete path), not by database triggers, because shared hosting often withholds the TRIGGER privilege. | A person with direct DB access could alter history | Audit log + nightly reconciliation job (Phase 3l) will flag drift | — |
| G-36 | Expense routes are not plan-gated (same as the source). Expense `notes` were never saved by the source form; the API saves them. | Behaviour parity / fix | — | — |
| G-37 | An outlet filter excludes outlet-less (business-wide) expenses; outlet-restricted managers therefore never see them. | Business-wide costs visible to unrestricted roles only | Documented | Owner confirmation |
| G-38 | `outlet_id` filter naming another business's outlet returns zeros (200), not 404, for unrestricted roles. No data leaks. | Cosmetic | Could add an existence check | — |
| G-39 | ~~Dashboard `pending_purchase_orders` deferred~~ — done in 3f (`inventory.pending_purchase_orders`). | — | — | — |
| G-40 | **Supplier balances / supplier payments are not built** (absent in the source; listed as NEW in the brief). Orders carry a cost but nothing tracks what is owed to a supplier. | No payables | Owner decision on semantics (pay per order? statements?) | Owner |
| G-41 | Purchase order list `from`/`to` filters use UTC day boundaries, not the business time zone. | Orders near midnight may fall on the neighbouring day | Documented | — |
| G-42 | Purchase routes are not plan-gated (same as the source) and there is no purchase export/report yet (Phase 3h). | — | — | — |
| G-43 | A received delivery cannot be reversed (no "return to supplier"). A wrong delivery is corrected with a stock adjustment. | Manual correction | Documented | Owner decision if returns are wanted |
| G-44 | Order lines whose product was later archived cannot be received until the product is restored (409 `product_unavailable`). | Intentional | Message tells the user what to do | — |
| G-45 | No transfer **approval** step and no per-item shipping documents (waybill/PDF). | Brief mentions an optional approval | Not built; status machine pending→in_transit→completed | Owner decision |
| G-46 | Stock lost in transit is recorded as variance only; there is no automatic write-off movement or claim workflow. | Variance must be investigated manually | Visible in view + audit | Owner decision |
| G-47 | Transfers of an item whose product is archived mid-flight still complete (the goods physically exist); archived products cannot be added to new transfers. | — | Intentional | — |
| G-48 | Transfer list `from`/`to` filters use UTC day boundaries (same as G-41). | — | — | — |
| G-49 | Reports have no charts/pivots, no scheduled e-mail and no PDF/XLSX output (CSV + JSON only). The source exported CSV for sales, inventory and customers; the brief's extra reports (profit, expenses, credit, purchases, outlets, products) are new. A **customers** CSV is not part of `/reports` (customers have their own list endpoint). | Feature scope | CSV/JSON only | Owner decision |
| G-50 | The 50,000-row report cap (413) is implemented but not exercised by a test (would need 50k seeded rows). | Untested limit | Documented | — |
| G-51 | `profit` and `outlets` reports require `profit.read`; today only owner and manager hold it, and both also hold `reports.read`, so that extra permission check cannot be distinguished in a test. | None today | Revisit if granular permissions arrive (3i) | — |

## B. Semantics the source leaves ambiguous (owner decisions)

| ID | Question | Default I will implement unless told otherwise |
|---|---|---|
| G-05 | Outlet-specific inventory: source has **one global stock number**; transfers move nothing. Brief demands outlet stock and non-destructive transfers. | Add `stock_levels` per location + global total; existing stock seeded to a default "Main" location; transfers move stock. **This is a behaviour change for existing multi-outlet customers.** |
| G-06 | Multiple payment methods on one sale (not in source). | Single method per sale + initial payment; split payments as an explicit NEW feature later. |
| G-07 | Void/refund semantics and who may do it. | Manager+ may void (restores stock, reverses customer stats/loyalty, keeps record). |
| G-08 | Cashier visibility of other cashiers' sales / customer data. | As today (cashier sees Sales + Customers). |
| G-11 | Plan limits on already-over-limit businesses; payment-failure grace period. | Grandfather existing rows, block new creation; 3-day grace. |
| G-12 | `customers.total_spent`: amount billed vs money received. | Money received (consistent with loyalty); historic values imported as-is. |
| G-13 | Data retention / deletion policy & legal basis (NDPR, GDPR). | Section 07 §8 default; needs counsel review. |
| G-14 | Where Phase 7 (Next.js refactor) lives. | Feature branch in `Track`, never auto-deployed. |
| G-19 | Currency handling: single currency per business/profile; billing multi-currency. | Same as source; no FX. |
| G-20 | Tax: single rate per business; per-item/ inclusive tax not in source. | Same as source. |

## C. Defects in the source I will **not** reproduce (documented, with the fix)

* **Dashboard credit figures ignore repayments.** `dashboard/page.tsx` computes "credit owed" and "overdue credit" as `total_amount − amount_paid` over pending sales, never subtracting `debt_repayments`, so every partly-repaid debt is overstated on the dashboard while the Credit page shows the right figure. The rebuild uses one definition everywhere (`CreditService`/`customerBalance`); Phase 3e's dashboard must reuse it and a test will pin the two together.

S-01…S-22 in `01`. Plus behaviours: reports revenue ignores status; reports low-stock threshold inconsistent; top-products limited to 200 sales; CSV export unescaped; purchase `sent` status mismatch; dashboard "revenue" mixes billed/unpaid/tax; client-generated receipt numbers; offline sync inserts bare rows; `getLimit` etc. never enforced server-side; `profiles.role` conflation; `invited` staff gain access by e-mail match.

## D. Capabilities in the brief that don't exist in the source (new work, needs sign-off)

Multi-payment sales · void/refund · stock adjustments + movement history · per-outlet stock/transfers · supplier balances · staff outlet assignment + granular permissions · camera/hardware barcode · thermal printing (Bluetooth/USB) · push notifications · dashboard date/outlet filters · credit/purchase/outlet/product reports · working offline queue + conflict resolution · server-issued receipt numbers · Flutterwave · SMS verification · real session management.

## E. Environment limitations observed in this session

* No MySQL/MariaDB binary found (`mysql --version` failed) → SQL migrations not yet executed anywhere.
* No Flutter SDK (`flutter: command not found`) → no Flutter build/test possible until installed; iOS/macOS builds need macOS/Xcode regardless.
* PHP is 8.3.6, not 8.4.
* The only existing test in the source (`plan-limits.test.ts`) has no runner configured; it was not executed.
* I have **not** run, installed or built any part of `Track`; no `npm install` was performed.

## F. Things verified vs. not

Verified by reading: everything cited in 01–04. **Not verified at runtime:** any claim about live behaviour (RLS execution, grants on `complete_sale`, actual enum values, whether S-01/S-03/S-06 are exploitable in the deployed project). They are *code-evident* vulnerabilities; confirming them would require testing production, which is out of bounds. Recommendation to the owner (independent of this rebuild): review S-01, S-03, S-04, S-06 against the live project and consider urgent mitigations — these affect customers today.
