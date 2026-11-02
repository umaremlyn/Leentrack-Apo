# 09 — Known Gaps, Unknowns and Decisions Needed

Each item: what is missing, why it matters, what I did/will do meanwhile, and who must act. IDs are referenced from other docs.

## A. Missing information (blocking or limiting)

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
