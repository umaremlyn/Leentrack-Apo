<?php
declare(strict_types=1);

namespace LeenTrack\Http\Middleware;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Core\Uuid;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\TenantRepository;
use LeenTrack\Services\PlanCatalog;

/**
 * Resolves the acting business from X-Business-Id (or the {id} path param for /businesses/{id}) and verifies an ACTIVE
 * membership of the authenticated user on EVERY request. Not a member => 404 (indistinguishable from "does not exist").
 */
final class TenantMiddleware implements Middleware
{
    public function __construct(private readonly TenantRepository $repo, private readonly bool $fromPath = false)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $auth = $request->auth();
        $bid = $this->fromPath ? ($request->params['id'] ?? null) : $request->header('x-business-id');
        if (!is_string($bid) || !Uuid::isValid($bid)) {
            throw $this->fromPath ? ApiException::notFound() : new ApiException('business_required', 'X-Business-Id header is required.', 400);
        }
        $m = $this->repo->activeMembership($auth->userId, $bid);
        if ($m === null) {
            throw ApiException::notFound();
        }
        $outlets = $this->repo->memberOutlets((string) $m['id']);
        $request->attributes['tenant'] = new TenantContext(
            (string) $m['business_id'], $auth->userId, (string) $m['id'], (string) $m['role'],
            PlanCatalog::effective($m), $outlets === [] ? null : $outlets,
        );
        return $next($request);
    }
}
