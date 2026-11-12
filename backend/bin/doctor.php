#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Deployment self-check. Run on the server:   php bin/doctor.php
 * Prints WHICH settings are present, never their values (safe to paste into a support chat).
 */

use LeenTrack\Core\Db;
use LeenTrack\Core\Env;

if (PHP_SAPI !== 'cli') { exit(1); }

$root = dirname(__DIR__);
$bad = 0;
$line = function (string $state, string $what, string $detail = '') use (&$bad): void {
    if ($state === 'FAIL') { $bad++; }
    printf("[%-4s] %s%s\n", $state, $what, $detail !== '' ? ' — ' . $detail : '');
};

echo "LeenTrack doctor (" . $root . ")\n\n";

// ---- PHP
$line(version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'FAIL', 'PHP version ' . PHP_VERSION, 'needs 8.3 or 8.4 (cPanel > MultiPHP Manager)');
foreach (['pdo_mysql', 'mbstring', 'openssl', 'sodium', 'json'] as $ext) {
    $line(extension_loaded($ext) ? 'ok' : 'FAIL', "extension $ext", extension_loaded($ext) ? '' : 'enable it (cPanel > Select PHP Version)');
}

// ---- env file: same search order as app/bootstrap.php
$candidates = array_filter([getenv('LEENTRACK_ENV_FILE') ?: null, dirname($root) . '/leentrack.env', $root . '/.env']);
$found = null;
foreach ($candidates as $c) {
    $exists = is_file($c);
    $line($exists ? ($found === null ? 'ok' : 'info') : 'info', ($exists ? 'env file found: ' : 'no env file at: ') . $c, $exists ? (is_readable($c) ? 'readable, ' . filesize($c) . ' bytes' : 'NOT READABLE (fix permissions, 600 or 644)') : '');
    if ($exists && $found === null) { $found = $c; }
}
if ($found === null) { $line('FAIL', 'no env file', 'create leentrack.env NEXT TO the backend folder (see docs/13)'); }
else {
    $raw = (string) @file_get_contents($found);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) { $line('FAIL', 'env file starts with a UTF-8 BOM', 'save it as plain UTF-8 without BOM; the first setting is being ignored'); }
    if (str_contains($raw, "\r")) { $line('info', 'env file has Windows line endings', 'supported'); }
    $names = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#') { continue; }
        if (!str_contains($l, '=')) { $line('warn', 'a line without "=" is ignored', substr($l, 0, 3) . '…'); continue; }
        $names[] = trim(explode('=', $l, 2)[0]);
    }
    $line('info', count($names) . ' setting name(s) in the file', implode(', ', $names));
    $odd = array_filter($names, fn ($n) => !preg_match('/^[A-Z][A-Z0-9_]*$/', $n));
    if ($odd) { $line('FAIL', 'setting names must be UPPER_CASE with no spaces or "export"', implode(', ', array_map(fn ($n) => '"' . $n . '"', $odd))); }
}

// ---- boot the app (loads the env file exactly like production)
try {
    $app = require $root . '/app/bootstrap.php';
} catch (Throwable $e) {
    $line('FAIL', 'the application does not start', get_class($e) . ': ' . $e->getMessage());
    exit(1);
}
$cfg = $app->config;
$line('ok', 'application boots');

$need = [
    'APP_URL' => $cfg['url'] ?? '', 'WEB_URL' => $cfg['web_url'] ?? '',
    'DB_NAME' => $cfg['db']['name'] ?? '', 'DB_USER' => $cfg['db']['user'] ?? '', 'DB_PASS' => $cfg['db']['pass'] ?? '',
];
foreach ($need as $k => $v) {
    $state = $v !== '' ? 'ok' : ($k === 'DB_PASS' || $k === 'WEB_URL' ? 'warn' : 'FAIL');
    $line($state, "$k is " . ($v !== '' ? 'set (' . strlen((string) $v) . ' characters)' : 'EMPTY'));
}
$line('info', 'DB_HOST = ' . ($cfg['db']['host'] ?? '') . ', DB_PORT = ' . ($cfg['db']['port'] ?? '') . ($cfg['db']['socket'] ? ', socket set' : ''));
foreach (['APP_URL' => $cfg['url'] ?? '', 'WEB_URL' => $cfg['web_url'] ?? ''] as $k => $v) {
    if ($v !== '' && !preg_match('#^https?://#', (string) $v)) { $line('FAIL', "$k must start with https://", 'currently without a scheme'); }
}
foreach (($cfg['cors_origins'] ?? []) as $o) {
    if (!preg_match('#^https?://[^/]+$#', $o)) { $line('warn', 'a CORS origin is not like https://host (no path, no spaces)', ''); }
}
$line(($cfg['debug'] ?? false) ? 'FAIL' : 'ok', 'APP_DEBUG is ' . (($cfg['debug'] ?? false) ? 'ON (must be false in production)' : 'off'));
$line(($cfg['env'] ?? '') === 'production' ? 'ok' : 'info', 'APP_ENV = ' . ($cfg['env'] ?? ''));
$smtp = $cfg['smtp'] ?? null;
if (($cfg['mail_driver'] ?? '') === 'smtp') {
    $line('info', 'SMTP port ' . ($smtp['port'] ?? '?') . ' mode ' . ($smtp['secure'] ?? '?'), 'port 465 needs ssl, port 587 needs tls');
    if ((int) ($smtp['port'] ?? 0) === 465 && ($smtp['secure'] ?? '') !== 'ssl') { $line('FAIL', 'SMTP port 465 needs SMTP_SECURE=ssl'); }
    if ((int) ($smtp['port'] ?? 0) === 587 && ($smtp['secure'] ?? '') !== 'tls') { $line('FAIL', 'SMTP port 587 needs SMTP_SECURE=tls'); }
}
$ps = (string) ($cfg['paystack']['secret'] ?? '');
$line('info', 'Paystack key is ' . ($ps === '' ? 'not set' : (str_starts_with($ps, 'sk_live') ? 'a LIVE key' : (str_starts_with($ps, 'sk_test') ? 'a test key' : 'set'))), ($cfg['env'] ?? '') !== 'production' && str_starts_with($ps, 'sk_live') ? 'use a TEST key on staging' : '');

// ---- folders
foreach (['storage', 'storage/logs'] as $d) {
    $p = $root . '/' . $d;
    $line(is_dir($p) && is_writable($p) ? 'ok' : 'FAIL', "$d is writable", is_dir($p) ? '' : 'folder missing (create it)');
}

// ---- database
if (($cfg['db']['name'] ?? '') === '') {
    $line('FAIL', 'database not configured', 'DB_NAME is empty: the env file was not found, or has no DB_NAME= line (see the lines above)');
} else {
    try {
        $pdo = Db::connect($cfg['db']);
        $line('ok', 'database connection');
        $n = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
        $has = (bool) $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'")->fetchColumn();
        $line($n > 0 ? 'ok' : 'FAIL', "$n table(s) in the database", $n > 0 ? '' : 'import database/install/leentrack-install.sql (phpMyAdmin) or run: php bin/migrate.php up');
        $have = array_map('strtolower', $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll(PDO::FETCH_COLUMN));
        $recorded = $has ? array_map('strval', $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)) : [];
        $owned = ['schema_migrations'];
        $pending = [];
        foreach (glob($root . '/database/migrations/*.up.sql') ?: [] as $f) {
            if (!preg_match('/^(\d{4})_([a-z0-9_]+)\.up\.sql$/', basename($f), $m)) { continue; }
            preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?([a-z0-9_]+)`?/i', (string) file_get_contents($f), $t);
            $tables = array_map('strtolower', $t[1]);
            $owned = array_merge($owned, $tables);
            $present = array_values(array_intersect($tables, $have));
            $isRecorded = in_array($m[1], $recorded, true);
            if (!$isRecorded) { $pending[] = $m[1]; }
            if (!$isRecorded || count($present) !== count($tables)) {
                $line($isRecorded ? 'FAIL' : 'warn', "migration {$m[1]} {$m[2]}: " . ($isRecorded ? 'recorded' : 'NOT recorded') . ', tables present ' . count($present) . '/' . count($tables), 'missing: ' . (implode(', ', array_diff($tables, $present)) ?: 'none') . ($isRecorded || !$present ? '' : '; ALREADY EXIST: ' . implode(', ', $present)));
            }
        }
        $line($pending ? 'FAIL' : 'ok', 'migrations applied: ' . count($recorded) . ' of ' . count(glob($root . '/database/migrations/*.up.sql') ?: []), $pending ? 'not yet applied: ' . implode(', ', $pending) : '');
        $extra = array_values(array_diff($have, $owned));
        if ($extra) { $line('info', count($extra) . ' table(s) in this database that LeenTrack did not create (left alone)', implode(', ', $extra)); }
    } catch (Throwable $e) {
        // class + driver message only: PDO messages never contain the password
        $line('FAIL', 'database connection failed', get_class($e) . ': ' . preg_replace('/\s+/', ' ', $e->getMessage()));
        echo "       hints: 'Access denied' = wrong DB_USER/DB_PASS or the user is not added to the database (cPanel > MySQL Databases > Add User To Database);\n";
        echo "              'Unknown database' = wrong DB_NAME (cPanel prefixes names with your account, e.g. account_dbname);\n";
        echo "              'Connection refused' / 'No such file' = try DB_HOST=localhost or 127.0.0.1.\n";
    }
}

echo "\n" . ($bad === 0 ? "No problems found.\n" : "$bad problem(s) found; fix the FAIL lines above and run it again.\n");
exit($bad === 0 ? 0 : 1);
