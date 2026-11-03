#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Scheduled jobs. CLI only (never exposed over HTTP).
 *   php bin/cron.php list                 show the jobs and a suggested schedule
 *   php bin/cron.php <job>                run one job
 * One run per job at a time (lock file); every run is recorded in job_runs and visible at GET /admin/jobs.
 * Exit codes: 0 ok or already running, 1 job failed, 2 usage error, 4 job ran but reported findings (reconcile).
 */
if (PHP_SAPI !== 'cli') { exit(1); }
$app = require dirname(__DIR__) . '/app/bootstrap.php';
use LeenTrack\Services\Container;
use LeenTrack\Services\JobService;

$job = $argv[1] ?? '';
if ($job === 'list') {
    foreach (JobService::JOBS as $name => [$what, $when]) { printf("%-16s %-18s %s\n", $name, $when, $what); }
    exit(0);
}
if (!isset(JobService::JOBS[$job])) {
    fwrite(STDERR, "Usage: cron.php list | " . implode('|', array_keys(JobService::JOBS)) . "\n");
    exit(2);
}
$c = new Container($app);
$handlers = [
    'housekeeping' => fn () => (new LeenTrack\Services\HousekeepingService($app->pdo()))->run() + ['job_runs' => $c->jobs()->prune()],
    'expire-plans' => fn () => $c->billing()->expirePlans(),
    'purge-accounts' => fn () => $c->account()->purgeDue(),
    'low-stock-alert' => fn () => $c->stockAlerts()->runDue(),
    'reconcile' => function () use ($c, $app) {
        $r = $c->reconciliation()->run();
        $bad = array_values(array_filter($r['checks'], fn ($k) => $k['violations'] > 0));
        if ($bad) {      // tell the operators (ids and counts only, no business data)
            $lines = array_map(fn ($k) => "- {$k['name']}: {$k['violations']} (e.g. " . implode(', ', $k['samples']) . ')', $bad);
            foreach ($app->pdo()->query('SELECT u.email FROM platform_admins a JOIN users u ON u.id = a.user_id')->fetchAll(PDO::FETCH_COLUMN) as $to) {
                $c->mailer()->send((string) $to, 'LeenTrack reconciliation found ' . $r['findings'] . ' issue(s)', "The nightly check found records that do not add up:\n\n" . implode("\n", $lines) . "\n\nNothing was changed. Open GET /admin/jobs/reconcile for the full report.\n");
            }
        }
        return ['findings' => $r['findings'], 'checks' => $r['checks']];
    },
];
$res = $c->jobs()->run($job, $handlers[$job]);
$app->logger()->{$res['status'] === 'failed' ? 'error' : 'info'}("cron $job", ['status' => $res['status']] + array_filter($res['summary'], 'is_scalar'));
echo json_encode(['job' => $job, 'status' => $res['status']] + ['summary' => $job === 'reconcile' ? ['findings' => $res['summary']['findings'] ?? null] : $res['summary']]), "\n";
exit($res['status'] === 'failed' ? 1 : (($res['summary']['findings'] ?? 0) > 0 ? 4 : 0));
