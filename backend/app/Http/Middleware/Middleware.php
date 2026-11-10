<?php
declare(strict_types=1);

namespace LeenTrack\Http\Middleware;

use LeenTrack\Core\Request;
use LeenTrack\Core\Response;

interface Middleware
{
    /** @param callable(Request):Response $next */
    public function handle(Request $request, callable $next): Response;
}
