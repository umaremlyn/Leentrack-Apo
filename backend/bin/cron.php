#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Scheduled jobs. CLI only (never exposed over HTTP).  Usage: php bin/cron.php housekeeping */
if (PHP_SAPI !== 'cli') { exit(1); }
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$job = $argv[1] ?? '';
try {
    switch ($job) {
        case 'housekeeping':
            $r = (new LeenTrack\Services\HousekeepingService($app->pdo()))->run();
            $app->logger()->info('cron housekeeping', $r);
            echo json_encode($r), "\n";
            break;
        default:
            fwrite(STDERR, "Usage: cron.php housekeeping\n");
            exit(2);
    }
} catch (Throwable $e) {
    $app->logger()->error('cron failed', ['job' => $job, 'error' => $e->getMessage()]);
    fwrite(STDERR, "Job failed.\n");
    exit(1);
}
