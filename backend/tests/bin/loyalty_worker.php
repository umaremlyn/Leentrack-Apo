<?php
declare(strict_types=1);

/** Concurrency worker: N loyalty "earn" applications of a fixed amount on one customer, each in its own transaction. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use LeenTrack\Core\Db;
use LeenTrack\Core\Money;
use LeenTrack\Repositories\CustomerRepository;
use LeenTrack\Services\AuditService;
use LeenTrack\Services\LoyaltyService;

[, $bid, $cid, $n, $amount, $startAt] = $argv;
$pdo = Db::connect(['host' => '', 'port' => 0, 'name' => getenv('TEST_DB_NAME'), 'user' => getenv('TEST_DB_USER'), 'pass' => getenv('TEST_DB_PASS'), 'socket' => getenv('TEST_DB_SOCKET')]);
$svc = new LoyaltyService($pdo, new CustomerRepository($pdo), new AuditService($pdo));
while (microtime(true) < (float) $startAt) { usleep(200); }
$done = 0;
for ($i = 0; $i < (int) $n; $i++) {
    Db::transaction($pdo, fn () => $svc->apply($bid, $cid, null, Money::parse($amount), 'worker', null), 5);
    $done++;
}
echo "done:$done\n";
