<?php
declare(strict_types=1);

namespace LeenTrack\Http\Middleware;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Services\SessionService;

/** `auth` = required; `auth:optional` = anonymous allowed, but a PRESENTED token must still be valid. */
final class AuthMiddleware implements Middleware
{
    public function __construct(private readonly SessionService $sessions, private readonly bool $optional = false)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            if ($request->header('authorization') !== null) { throw ApiException::unauthenticated('Malformed Authorization header.'); }
            if ($this->optional) { return $next($request); }
            throw ApiException::unauthenticated();
        }
        $ctx = $this->sessions->authenticate($token);
        if ($ctx === null) {
            throw new ApiException('token_invalid', 'Invalid or expired token.', 401);
        }
        $request->attributes['auth'] = $ctx;
        return $next($request);
    }
}
