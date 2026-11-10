# 13 — Deployment on Truehost cPanel (PHP 8.4 / MySQL 8.4)

> **Status: written from the code's requirements, NOT yet executed on a real Truehost account.** Every step is marked with what it assumes. The first real deployment must follow this guide, and anything that differs gets corrected here. Cutover to production data is a separate, owner-authorised step (docs/06).

## 0. What must be true of the host (G-16 — please confirm via cPanel → *MultiPHP Manager* / `phpinfo()`)
PHP **8.4** (`ea-php84`) with `pdo_mysql`, `mbstring`, `sodium`, `json`, `openssl`, `curl`; `mail()` working (or SMTP credentials); outbound HTTPS to the payment provider (Phase 3j); cron; ideally SSH or cPanel *Terminal* (needed to run `php bin/migrate.php`). `bcmath`/`gmp` are **not** needed. Argon2id is used when available, else bcrypt cost 12.

## 1. Directory layout (secrets and source stay outside the web root)
```
/home/CPUSER/leentrack/            <- NOT web-accessible
    leentrack.env                  <- secrets, chmod 600  (bootstrap looks here)
    backend/
        app/ config/ routes/ database/ bin/ vendor/ storage/
        public/                    <- THE ONLY web-exposed directory
```
Create a subdomain **api.yourdomain.com** in cPanel → *Domains* and set its **Document Root to `/home/CPUSER/leentrack/backend/public`** (not under `public_html`). If cPanel will not allow a custom document root, use the fallback in §6.

## 2. Steps
1. **PHP version**: cPanel → *MultiPHP Manager* → select `api.yourdomain.com` → `ea-php84`; *MultiPHP INI Editor*: `display_errors=Off`, `expose_php=Off`, `memory_limit>=128M`, `max_execution_time>=60`.
2. **Database**: cPanel → *MySQL® Databases* → create database + user (strong random password), grant ALL on that database only. (Host `localhost`, port 3306.)
3. **Upload code**: build locally `cd backend && composer install --no-dev --optimize-autoloader`, zip `backend/` **excluding** `.env`, `tests/`, `.phpunit.cache`, then upload/extract to `/home/CPUSER/leentrack/backend` (File Manager or `git clone` + composer on SSH).
4. **Config**: copy `backend/.env.example` to `/home/CPUSER/leentrack/leentrack.env`; fill `APP_ENV=production`, `APP_DEBUG=false` (the app **refuses to boot** if debug is true in production), `APP_URL`, `WEB_URL`, DB_*, `CORS_ALLOWED_ORIGINS` (exact origins, no `*`), `MAIL_FROM`. Run `chmod 600 leentrack.env`. Generate a key: `php bin/keygen.php`.
5. **Migrate** (SSH/Terminal): `cd ~/leentrack/backend && php bin/migrate.php up` then `php bin/migrate.php status`. Never edit an applied migration; the runner refuses.
6. **HTTPS**: cPanel → *SSL/TLS Status* → run AutoSSL for the subdomain; then force HTTPS (the API also sends HSTS in production).
7. **First admin** (CLI only): register normally, verify the e-mail, then `php bin/make-admin.php you@example.com`.
8. **Permissions**: `storage/` writable by the PHP user (`chmod 750 storage storage/logs`), everything else read-only.

## 3. Post-deploy verification checklist (all must pass before real users)
- [ ] `curl -s https://api.yourdomain.com/api/v1/health` → `{"ok":true,"data":{"status":"ok","db":"ok",...}}`
- [ ] `curl -sI https://api.yourdomain.com/api/v1/health` shows no `X-Powered-By`, shows `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`.
- [ ] `https://api.yourdomain.com/leentrack.env`, `/../config/app.php`, `/composer.json`, `/vendor/autoload.php`, `/storage/logs/` → all 404/403 (document root is `public/`, so these must not resolve).
- [ ] `https://api.yourdomain.com/.git/` and `/.env` → 404/403.
- [ ] Register → e-mail arrives (check spam/SPF/DKIM — G-18) → verify → login works.
- [ ] A wrong origin gets no `Access-Control-Allow-Origin`.
- [ ] Error path: temporarily break DB password in a *staging* copy → API returns a generic 500/503, never SQL text.
- [ ] `php -v` in cPanel Terminal reports 8.4.x for the CLI used by cron (cron may use a different PHP: call `/opt/cpanel/ea-php84/root/usr/bin/php` explicitly).

## 4. Cron (jobs are added as they are built; none are required for Phase 2 except housekeeping)
```
*/30 * * * *  /opt/cpanel/ea-php84/root/usr/bin/php /home/CPUSER/leentrack/backend/bin/cron.php housekeeping   # purge rate limits / expired tokens  (arrives in Phase 3)
15 2 * * *    /opt/cpanel/ea-php84/root/usr/bin/php /home/CPUSER/leentrack/backend/bin/cron.php purge-accounts   # permanently deletes accounts whose 14-day grace has ended (Phase 3i) — without it, deleted accounts stay pending
0 1 * * *     /opt/cpanel/ea-php84/root/usr/bin/php .../bin/cron.php expire-plans                             # Phase 3j
```
Cron jobs run the CLI, never an HTTP URL.

## 5. Backups and recovery (to be rehearsed before cutover)
Nightly `mysqldump --single-transaction --routines DBNAME | gzip` via cron to a directory outside the web root, then copied off-host (encrypted). Restore drill: create a scratch DB, `gunzip -c dump.sql.gz | mysql SCRATCH`, run `php bin/migrate.php status` against it, and compare row counts. Keep 14 daily + 8 weekly.

## 6. Fallback if the subdomain document root cannot be customised
Put the *contents* of `backend/public/` in `public_html/api/`, keep `app/ config/ routes/ vendor/ storage/ bin/ database/` in `/home/CPUSER/leentrack/backend/` and edit the two `require` paths in `public/index.php` to point there. Add an `.htaccess` deny for dotfiles (already in `public/.htaccess`). Re-run the §3 checklist: the "must not be reachable" checks are the ones that matter.

## 7. Updating
Upload new code to a fresh directory, `composer install --no-dev`, run `php bin/migrate.php up`, then swap the directory (or rsync). Roll back code by swapping back; roll back schema only with the migration's `.down.sql` if it has one (`php bin/migrate.php down` refuses when a migration has none).

## 8. Known unknowns (to be resolved on the first real deployment)
`mail()` deliverability on Truehost; whether outbound HTTPS to Paystack is allowed; `max_execution_time` for the importer (it is chunked and resumable by design); whether cron can be set to 1-minute granularity.


## Phase 3i settings
* **E-mail:** set `MAIL_DRIVER=smtp` with `SMTP_HOST`, `SMTP_PORT` (587 + `SMTP_SECURE=tls`, or 465 + `ssl`), `SMTP_USER`, `SMTP_PASS`, and a `MAIL_FROM` on a domain that has SPF and DKIM. Use a cPanel mailbox on your own domain. `native` (PHP `mail()`) remains available but is less reliable.
* **Profile pictures:** `AVATAR_DIR` (default `backend/storage/avatars`) must be writable by PHP and **outside the web root**.
* **Reports:** `REPORT_MAX_ROWS` (default 50000) bounds memory for the largest report; lower it on small plans.
* **Not yet verified on a real host:** the STARTTLS/TLS handshake of the SMTP client, and that the cPanel PHP has `iconv`/`mbstring` (the PDF writer uses `mbstring`).


## Phase 3j settings (billing)
1. Set `PAYSTACK_SECRET_KEY` (server only) and `PAYSTACK_CALLBACK_URL` (the web/app page that calls `POST /billing/verify`).
2. In the Paystack dashboard register the webhook URL `https://YOUR-API-HOST/api/v1/webhooks/paystack`. Use **test** keys first and make one test payment end to end (docs G-60).
3. Schedule `php bin/cron.php expire-plans` daily (it also removes payment records past their retention period). Without it, plans still stop at expiry (access is checked live) but the stored plan stays "growth" until it runs.
4. Outbound HTTPS from the host to `api.paystack.co` must be allowed (see G-16).


## Phase 3k: platform administrators
Create the first operator after registering normally through the app: `php bin/make-admin.php you@example.com`. List with `--list`; remove with `--revoke you@example.com` (the last admin cannot be revoked). Use a strong, unique password and a verified e-mail: admin actions ask for that password again.


## Phase 3l: the complete cron table
`php bin/cron.php list` prints it. Suggested cPanel entries (use the full PHP path from the checklist above):

| Schedule | Command | Purpose |
|---|---|---|
| every 30 minutes | `cron.php housekeeping` | expired tokens/sessions/rate limits, old job history |
| hourly | `cron.php low-stock-alert` | low-stock e-mails (honours each owner's daily/hourly choice) |
| daily 01:00 | `cron.php expire-plans` | move ended paid plans to free; drop old retained payment records |
| daily 02:15 | `cron.php purge-accounts` | finish account deletions after the 14-day grace |
| daily 03:30 | `cron.php reconcile` | read-only audit; exit code 4 + e-mail to admins when something does not add up |

Overlapping runs are impossible (lock files in `LOCK_DIR`, default `backend/storage/locks`, must be writable). After the first day open `GET /admin/jobs` and `GET /admin/health` as the platform admin: every job should show a recent `ok` run and the health report should have no `fail`.


## Common first-deploy mistakes (seen in practice)
* **Secrets in `config/app.php` defaults.** Never put passwords or keys as the second argument of `Env::get(...)`: that file is in git. Put them in the `.env` (outside the web root, `chmod 600`) and leave the defaults empty. If a secret was ever committed or pasted anywhere, change it.
* **A typo in `config/app.php` gives an empty HTTP 500.** Check with `php -l config/app.php` before uploading.
* **`APP_URL` / `WEB_URL` need the scheme**, e.g. `https://api.example.com` (not `api.example.com`). `WEB_URL` is the *web app*, not the API (it is used in e-mailed links).
* **`CORS_ALLOWED_ORIGINS` needs the scheme** and is compared exactly: `https://app.example.com` (no spaces needed, no wildcard). Mobile/desktop apps do not use CORS; only browsers do.
* **SMTP port and mode must match:** port 465 = `SMTP_SECURE=ssl`; port 587 = `SMTP_SECURE=tls` (STARTTLS). `SMTP_HOST` is the mail server (often `mail.<domain>`), not the app's address.
* **Use a Paystack TEST key (`sk_test_...`) on staging.** A live key makes staging charge real cards.
* **The app's `API_BASE_URL` / `STAGING_API_URL` must be the full API root** including `/api/v1`, no trailing slash: `https://api.example.com/api/v1`.
* Smoke check from any computer with Dart: `dart run apps/flutter/tool/live_check.dart https://api.example.com/api/v1` (expects health 200, `db: ok`).
