<?php
declare(strict_types=1);

namespace LeenTrack\Http\Middleware;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Repositories\UserRepository;

/** Looks in platform_admins on every request. Nothing the client sends (or any business role) can satisfy it. Non-admins get 404. */
final class PlatformAdminMiddleware implements Middleware
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!$this->users->isPlatformAdmin($request->auth()->userId)) {
            throw ApiException::notFound();
        }
        return $next($request);
    }
}
