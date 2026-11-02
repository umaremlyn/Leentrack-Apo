<?php
declare(strict_types=1);

namespace LeenTrack\Http;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\App;
use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Core\Router;
use LeenTrack\Http\Middleware\Middleware;
use LeenTrack\Http\Middleware\ThrottleMiddleware;

final class Kernel
{
    private Router $router;

    public function __construct(private readonly App $app)
    {
        $this->router = new Router();
        (require dirname(__DIR__, 2) . '/routes/api.php')($this->router, $app);
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (ApiException $e) {
            $response = Response::error($e->errorCode, $e->getMessage(), $e->status, $e->fields, $request->id);
            foreach ($e->headers as $k => $v) {
                $response->withHeader($k, $v);
            }
        } catch (\Throwable $e) {
            // Never leak internals. Full detail goes to the log only.
            $this->app->logger()->error('unhandled exception', [
                'request_id' => $request->id,
                'type' => $e::class,
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()) . ':' . $e->getLine(),
                'path' => $request->path,
            ]);
            $response = Response::error('server_error', 'Something went wrong on our side.', 500, [], $request->id);
        }
        return $this->decorate($request, $response);
    }

    private function dispatch(Request $request): Response
    {
        $prefix = $this->app->config['api_prefix'];
        if ($request->path !== $prefix && !str_starts_with($request->path, $prefix . '/')) {
            throw ApiException::notFound();
        }
        $path = substr($request->path, strlen($prefix)) ?: '/';

        if ($request->method === 'OPTIONS') {             // CORS preflight: no body, headers added in decorate()
            return new Response(204);
        }

        $m = $this->router->match($request->method, $path);
        $request->params = $m['params'];

        $handler = $m['handler'];
        $chain = array_reduce(
            array_reverse($m['middleware']),
            fn (callable $next, string $spec) => fn (Request $r) => $this->makeMiddleware($spec, $path)->handle($r, $next),
            fn (Request $r) => $handler($r)
        );
        return $chain($request);
    }

    private function makeMiddleware(string $spec, string $path): Middleware
    {
        if (str_starts_with($spec, 'throttle:')) {
            [$max, $win] = array_map('intval', explode(',', substr($spec, 9)));
            return new ThrottleMiddleware($this->app, $max, $win, $path);
        }
        throw new \LogicException("Unknown middleware: $spec");
    }

    private function decorate(Request $request, Response $response): Response
    {
        $response->withRequestId($request->id);
        foreach ([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'X-API-Version' => '1',
        ] as $k => $v) {
            $response->withHeader($k, $v);
        }
        if ($this->app->isProduction() || ($this->app->config['env'] === 'staging')) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        // CORS: exact-match allow-list, never "*", no credentials (bearer-token API).
        $origin = $request->header('origin');
        if ($origin !== null && in_array($origin, $this->app->config['cors_origins'], true)) {
            $response->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Vary', 'Origin')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Idempotency-Key, X-Business-Id, X-Outlet-Id, X-Device-Id')
                ->withHeader('Access-Control-Expose-Headers', 'X-Request-Id, Retry-After')
                ->withHeader('Access-Control-Max-Age', '600');
        }
        return $response;
    }
}
