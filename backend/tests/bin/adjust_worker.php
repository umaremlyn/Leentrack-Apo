<?php
declare(strict_types=1);

/**
 * Concurrency worker (spawned by tests): performs N stock decrements of 1 through the real StockService on its own DB connection.
 * Prints "ok:<n>" / "refused:<n>" counts. Not a test itself.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Db;
use LeenTrack\Repositories\CatalogRepository;
use LeenTrack\Services\AuditService;
use LeenTrack\Services\StockService;

[, $bid, $pid, $loc, $n, $startAt] = $argv;
$pdo = Db::connect(['host' => '', 'port' => 0, 'name' => getenv('TEST_DB_NAME'), 'user' => getenv('TEST_DB_USER'), 'pass' => getenv('TEST_DB_PASS'), 'socket' => getenv('TEST_DB_SOCKET')]);
$svc = new StockService($pdo, new CatalogRepository($pdo), new AuditService($pdo));
// Barrier: every worker spins until the same wall-clock instant so they collide instead of running one after another.
while (microtime(true) < (float) $startAt) { usleep(200); }
$ok = 0; $refused = 0;
for ($i = 0; $i < (int) $n; $i++) {
    try {
        Db::transaction($pdo, fn () => $svc->applyDelta($bid, $pid, $loc, -1, 'correction', 'test', null, null, null));
        $ok++;
    } catch (ApiException $e) {
        if ($e->errorCode === 'insufficient_stock') { $refused++; } else { throw $e; }
    }
}
echo "ok:$ok refused:$refused\n";
