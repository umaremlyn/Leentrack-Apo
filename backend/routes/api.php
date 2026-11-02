<?php
declare(strict_types=1);

use LeenTrack\Core\App;
use LeenTrack\Core\Router;
use LeenTrack\Http\Controllers\HealthController;

/** Every route is declared here. `public: true` must be explicit — the security test fails on undeclared public routes. */
return static function (Router $r, App $app): void {
    $health = new HealthController($app);
    $r->get('/health', [$health, 'show'], [], true);
};
