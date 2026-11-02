<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\App;
use LeenTrack\Core\Request;
use LeenTrack\Core\Response;

final class HealthController
{
    public function __construct(private readonly App $app)
    {
    }

    /** Public, unauthenticated, reveals nothing but liveness + db reachability. */
    public function show(Request $request): Response
    {
        $db = 'not_configured';
        if ($this->app->dbConfigured()) {
            try {
                $this->app->pdo()->query('SELECT 1');
                $db = 'ok';
            } catch (\Throwable) {
                $db = 'down';
            }
        }
        $status = $db === 'down' ? 'degraded' : 'ok';
        return Response::ok(['status' => $status, 'db' => $db, 'version' => $this->app->config['version']], [], $status === 'ok' ? 200 : 503);
    }
}
