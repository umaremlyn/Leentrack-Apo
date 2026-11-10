<?php
declare(strict_types=1);

use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Http\Kernel;

try {
    $app = require dirname(__DIR__) . '/app/bootstrap.php';
    $body = file_get_contents('php://input', false, null, 0, Request::MAX_BODY + 1) ?: '';
    $request = Request::fromGlobals($_SERVER, $body, $app->config['trusted_proxies']);
    (new Kernel($app))->handle($request)->send();
} catch (\Throwable $e) {
    // Boot failure (bad config, missing vendor/). Generic response; details to the PHP error log.
    error_log('LeenTrack boot failure: ' . $e::class . ': ' . $e->getMessage());
    Response::error('server_error', 'Service unavailable.', 500)->send();
}
