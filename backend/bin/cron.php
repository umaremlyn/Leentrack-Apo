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
        case 'purge-accounts':
            $r = (new LeenTrack\Services\Container($app))->account()->purgeDue();
            $app->logger()->info('cron purge-accounts', $r);
            echo json_encode($r), "\n";
            if ($r['failed'] > 0) { exit(1); }
            break;
        default:
            fwrite(STDERR, "Usage: cron.php housekeeping|purge-accounts\n");
            exit(2);
    }
} catch (Throwable $e) {
    $app->logger()->error('cron failed', ['job' => $job, 'error' => $e->getMessage()]);
    fwrite(STDERR, "Job failed.\n");
    exit(1);
}
