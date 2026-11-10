<?php
declare(strict_types=1);

namespace LeenTrack\Http\Middleware;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\App;
use LeenTrack\Core\RateLimiter;
use LeenTrack\Core\Request;
use LeenTrack\Core\Response;

/** Usage in routes: 'throttle:30,60' = 30 hits per 60 s per IP+route. Fails CLOSED if the store is down. */
final class ThrottleMiddleware implements Middleware
{
    public function __construct(private readonly App $app, private readonly int $max, private readonly int $window, private readonly string $bucket)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!$this->app->config['rate_limit_enabled']) {
            return $next($request);
        }
        try {
            $r = (new RateLimiter($this->app->pdo()))->hit($this->bucket . '|' . $request->ip, $this->max, $this->window);
        } catch (\Throwable $e) {
            $this->app->logger()->error('rate limiter unavailable', ['error' => $e->getMessage()]);
            throw new ApiException('service_unavailable', 'Service temporarily unavailable.', 503);
        }
        if (!$r['allowed']) {
            throw ApiException::tooMany($r['retry_after']);
        }
        return $next($request)->withHeader('X-RateLimit-Remaining', (string) $r['remaining']);
    }
}
