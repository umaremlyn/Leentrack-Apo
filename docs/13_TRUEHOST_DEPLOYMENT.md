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
