<?php
declare(strict_types=1);

/**
 * Generic concurrency worker: performs real HTTP-level requests through the full Kernel on its own DB connection.
 * Usage: request_worker.php <token> <businessId> <startAt> <json list of {method,path,body}>
 * Prints one line per request: "<status> <replayed 0|1> <data.id | data.repayment.id | error.code>".
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use LeenTrack\Core\App;
use LeenTrack\Core\Db;
use LeenTrack\Core\Request;
use LeenTrack\Http\Kernel;
use LeenTrack\Mail\FileMailer;
use LeenTrack\Services\Container;

[, $token, $bid, $startAt, $reqs] = $argv;
$dbc = ['host' => '', 'port' => 0, 'name' => getenv('TEST_DB_NAME'), 'user' => getenv('TEST_DB_USER'), 'pass' => getenv('TEST_DB_PASS'), 'socket' => getenv('TEST_DB_SOCKET')];
$app = new App([
    'env' => 'testing', 'debug' => false, 'url' => '', 'version' => 't', 'api_prefix' => '/api/v1', 'log_dir' => sys_get_temp_dir() . '/lt-worker-logs', 'log_level' => 'error',
    'web_url' => 'https://web.test', 'mail_from' => 'x@test', 'cors_origins' => [], 'trusted_proxies' => [], 'rate_limit_enabled' => false, 'enforce_sales_limit' => false, 'paystack' => ['secret' => (string) getenv('TEST_PAYSTACK_SECRET'), 'base_url' => 'https://api.paystack.co', 'callback_url' => ''],
    'auth' => ['access_ttl' => 900, 'refresh_ttl' => 2592000, 'require_email_verification' => true, 'invite_ttl' => 604800, 'reset_ttl' => 3600, 'verify_ttl' => 172800],
    'db' => $dbc,
]);
$app->setPdo(Db::connect($dbc));
$c = new Container($app);
$c->setMailer(new FileMailer());
$kernel = new Kernel($app, $c);
while (microtime(true) < (float) $startAt) { usleep(200); }
foreach (json_decode($reqs, true) as $r) {
    $server = ['REQUEST_METHOD' => $r['method'], 'REQUEST_URI' => '/api/v1' . $r['path'], 'REMOTE_ADDR' => '203.0.113.88', 'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => "Bearer $token", 'HTTP_X_BUSINESS_ID' => $bid];
    foreach ($r['headers'] ?? [] as $hk => $hv) { $server['HTTP_' . strtoupper(str_replace('-', '_', $hk))] = $hv; }
    $req = Request::fromGlobals($server, $r['raw'] ?? (isset($r['body']) ? json_encode($r['body']) : ''));
    $res = $kernel->handle($req);
    $j = json_decode($res->body, true);
    echo $res->status, ' ', ($j['meta']['replayed'] ?? false) ? 1 : 0, ' ', $j['data']['repayment']['id'] ?? $j['data']['id'] ?? ($j['error']['code'] ?? '?'), "\n";
    if ($r['dump'] ?? false) { echo 'BODY ', base64_encode($res->body), "\n"; }      // tests that need the whole answer (sync results)
}
