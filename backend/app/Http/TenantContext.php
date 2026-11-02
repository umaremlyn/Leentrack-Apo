<?php
declare(strict_types=1);

namespace LeenTrack\Http;

use LeenTrack\Core\ApiException;
use LeenTrack\Services\PlanCatalog;
use LeenTrack\Services\Permissions;

/** Everything a service may trust about "who is acting in which business". Built only by TenantMiddleware. */
final class TenantContext
{
    /** @param list<string>|null $outletIds null = unrestricted */
    public function __construct(
        public readonly string $businessId,
        public readonly string $userId,
        public readonly string $memberId,
        public readonly string $role,
        public readonly string $plan,        // effective plan (expiry applied)
        public readonly ?array $outletIds,
    ) {
    }

    public function can(string $permission): bool
    {
        return Permissions::can($this->role, $permission);
    }

    /** 403 when the role lacks the permission. (Object existence is hidden with 404 elsewhere.) */
    public function require(string $permission): void
    {
        if (!$this->can($permission)) {
            throw ApiException::forbidden();
        }
    }

    public function requireFeature(string $feature): void
    {
        if (!PlanCatalog::isPremium($this->plan, $feature)) {
            throw new ApiException('feature_not_in_plan', 'This feature is not available on your current plan.', 402);
        }
    }

    /** Throws plan_limit_reached when creating one more would exceed the plan limit. */
    public function requireWithinLimit(string $resource, int $currentCount): void
    {
        if ($currentCount >= PlanCatalog::limit($this->plan, $resource)) {
            throw new ApiException('plan_limit_reached', 'You have reached the limit of your current plan.', 402);
        }
    }

    public function canAccessOutlet(string $locationId): bool
    {
        return $this->outletIds === null || in_array($locationId, $this->outletIds, true);
    }
}
