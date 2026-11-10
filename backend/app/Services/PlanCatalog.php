<?php
declare(strict_types=1);

namespace LeenTrack\Services;

/** Plans, limits and premium features — a faithful port of lib/plan-limits.ts (see docs/04 §1). Enforced server-side. */
final class PlanCatalog
{
    public const INF = PHP_INT_MAX;

    public const LIMITS = [
        'products'   => ['free' => 50,  'growth' => 500,       'business' => self::INF],
        'categories' => ['free' => 5,   'growth' => 50,        'business' => self::INF],
        'outlets'    => ['free' => 1,   'growth' => 3,         'business' => self::INF],
        'customers'  => ['free' => 100, 'growth' => 2000,      'business' => self::INF],
        'sales'      => ['free' => 200, 'growth' => 5000,      'business' => self::INF],
        'staff'      => ['free' => 1,   'growth' => self::INF, 'business' => self::INF],
    ];

    public const PREMIUM = [
        'exportReports'     => ['growth', 'business'],
        'advancedAnalytics' => ['business'],
        'staffManagement'   => ['growth', 'business'],
        'locations'         => ['growth', 'business'],
    ];

    public static function normalize(?string $plan): string
    {
        $p = strtolower(trim($plan ?? 'free'));
        return match ($p) {
            'starter' => 'growth',
            'pro', 'gold' => 'business',
            'growth', 'business', 'free' => $p,
            default => 'free',   // includes expired|cancelled|suspended leaking in, and unknown values
        };
    }

    public static function isPremium(?string $plan, string $feature): bool
    {
        return in_array(self::normalize($plan), self::PREMIUM[$feature] ?? [], true);
    }

    public static function limit(?string $plan, string $resource): int
    {
        $l = self::LIMITS[$resource];
        return $l[self::normalize($plan)] ?? $l['free'];
    }

    /**
     * The plan that actually applies right now: a paid plan past its expiry (or suspended/cancelled past expiry) is treated as free
     * even before the nightly job downgrades it, so access cannot outlive payment.
     * @param array{plan:string,plan_status:string,plan_expiry:?string} $b
     */
    public static function effective(array $b, ?\DateTimeImmutable $now = null): string
    {
        $now ??= \LeenTrack\Core\Clock::now();
        $plan = self::normalize($b['plan']);
        if ($plan === 'free') { return 'free'; }
        if ($b['plan_status'] === 'suspended' || $b['plan_status'] === 'expired') { return 'free'; }
        if ($b['plan_expiry'] !== null && new \DateTimeImmutable($b['plan_expiry'], new \DateTimeZone('UTC')) < $now) { return 'free'; }
        return $plan;
    }
}
