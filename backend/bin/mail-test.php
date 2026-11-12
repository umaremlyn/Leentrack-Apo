#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Sends ONE test e-mail through the configured mailer and shows what happened (never prints passwords).
 *   php bin/mail-test.php you@example.com
 */

use LeenTrack\Services\Container;

if (PHP_SAPI !== 'cli') { exit(1); }
$to = $argv[1] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Usage: php bin/mail-test.php you@example.com\n"); exit(2); }

$root = dirname(__DIR__);
$app = require $root . '/app/bootstrap.php';
$cfg = $app->config;
$driver = (string) (($cfg['mail_driver'] ?? '') ?: ($app->isProduction() ? 'native' : 'file'));
echo "mail driver: $driver\n";
if ($driver === 'smtp') {
    $s = $cfg['smtp'];
    echo "smtp: host " . ($s['host'] !== '' ? 'set' : 'EMPTY') . ", port {$s['port']}, mode {$s['secure']}, user " . ($s['user'] !== '' ? 'set' : 'EMPTY') . ", password " . ($s['pass'] !== '' ? 'set' : 'EMPTY') . "\n";
    if ((int) $s['port'] === 465 && $s['secure'] !== 'ssl') { echo "PROBLEM: port 465 needs SMTP_SECURE=ssl (you have {$s['secure']}).\n"; }
    if ((int) $s['port'] === 587 && $s['secure'] !== 'tls') { echo "PROBLEM: port 587 needs SMTP_SECURE=tls (you have {$s['secure']}).\n"; }
}
echo "from: " . preg_replace('/^.*<|>.*$/', '', (string) $cfg['mail_from']) . "\n";

$logDir = (string) $cfg['log_dir'];
$before = [];
foreach (glob($logDir . '/*.log') ?: [] as $f) { $before[$f] = filesize($f); }

$c = new Container($app);
$started = microtime(true);
try {
    $c->mailer()->send($to, 'LeenTrack test e-mail', "If you can read this, LeenTrack can send e-mail.\nSent at " . gmdate('c') . " UTC.\n");
    $note = 'the mailer accepted the message (the SMTP mailer logs, rather than throws, when the server refuses it: see below)';
} catch (Throwable $e) {
    $note = 'FAILED: ' . get_class($e) . ': ' . preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 160));
}
printf("result after %.1fs: %s\n", microtime(true) - $started, $note);

// what the mailer logged during this run (reason only, no addresses or secrets)
$seen = false;
foreach (glob($logDir . '/*.log') ?: [] as $f) {
    $from = $before[$f] ?? 0;
    if (filesize($f) <= $from) { continue; }
    $h = fopen($f, 'rb'); fseek($h, $from); $new = (string) stream_get_contents($h); fclose($h);
    foreach (preg_split('/\R/', $new) ?: [] as $l) {
        if ($l !== '' && stripos($l, 'mail') !== false || stripos($l, 'smtp') !== false) { echo 'log: ' . substr($l, 0, 300) . "\n"; $seen = true; }
    }
}
if (!$seen) { echo "log: nothing was logged, so no error was reported.\n"; }
echo "\nNow check the inbox of $to (and the spam folder). If nothing arrives within a few minutes, the 'log:' lines above say why.\n";
