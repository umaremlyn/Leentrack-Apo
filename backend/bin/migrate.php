#!/usr/bin/env php
<?php
declare(strict_types=1);

use LeenTrack\Core\Db;
use LeenTrack\Core\Migrator;

if (PHP_SAPI !== 'cli') { exit(1); }
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$cmd = $argv[1] ?? 'status';
$pdo = Db::connect($app->config['db'], multiStatement: true);
$m = new Migrator($pdo, dirname(__DIR__) . '/database/migrations');
try {
    switch ($cmd) {
        case 'up':     $d = $m->up();   echo $d ? 'Applied: ' . implode(', ', $d) : 'Nothing to migrate.', "\n"; break;
        case 'down':   $d = $m->down(); echo $d ? 'Rolled back: ' . implode(', ', $d) : 'Nothing to roll back.', "\n"; break;
        case 'status': foreach ($m->status() as $r) { printf("%s  %-8s %s\n", $r['version'], $r['state'], $r['name']); } break;
        default: fwrite(STDERR, "Usage: migrate.php up|down|status\n"); exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
