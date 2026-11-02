<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Unit;

use LeenTrack\Services\Permissions;
use LeenTrack\Services\PlanCatalog;
use PHPUnit\Framework\TestCase;

/** G-PLAN-1: every assertion of the original lib/plan-limits.test.ts, plus the rest of plan-limits.ts. */
final class PlanCatalogTest extends TestCase
{
    public function testNormalizeLegacyNames(): void
    {
        $this->assertSame('growth', PlanCatalog::normalize('starter'));
        $this->assertSame('business', PlanCatalog::normalize('pro'));
        $this->assertSame('business', PlanCatalog::normalize('gold'));
        $this->assertSame('business', PlanCatalog::normalize('business'));
        $this->assertSame('growth', PlanCatalog::normalize('growth'));
        foreach ([null, '', 'expired', 'cancelled', 'suspended', 'enterprise', ' PRO '] as $x) {
            $this->assertSame($x === ' PRO ' ? 'business' : 'free', PlanCatalog::normalize($x), (string) $x);
        }
    }

    public function testPremiumLocationsMatchesSourceTests(): void
    {
        foreach (['pro', 'gold', 'business', 'growth', 'starter'] as $p) { $this->assertTrue(PlanCatalog::isPremium($p, 'locations'), $p); }
        $this->assertFalse(PlanCatalog::isPremium('free', 'locations'));
        $this->assertFalse(PlanCatalog::isPremium(null, 'locations'));
    }

    public function testOutletLimitsMatchSourceTests(): void
    {
        $this->assertSame(1, PlanCatalog::limit('free', 'outlets'));
        $this->assertSame(3, PlanCatalog::limit('starter', 'outlets'));
        $this->assertSame(3, PlanCatalog::limit('growth', 'outlets'));
        foreach (['pro', 'gold', 'business'] as $p) { $this->assertSame(PlanCatalog::INF, PlanCatalog::limit($p, 'outlets'), $p); }
        $this->assertFalse(3 >= PlanCatalog::limit('business', 'outlets'));
    }

    public function testFullLimitTableAndPremiumTable(): void
    {
        $exp = ['products' => [50, 500], 'categories' => [5, 50], 'outlets' => [1, 3], 'customers' => [100, 2000], 'sales' => [200, 5000], 'staff' => [1, PlanCatalog::INF]];
        foreach ($exp as $res => [$free, $growth]) {
            $this->assertSame($free, PlanCatalog::limit('free', $res), "$res free");
            $this->assertSame($growth, PlanCatalog::limit('growth', $res), "$res growth");
            $this->assertSame(PlanCatalog::INF, PlanCatalog::limit('business', $res), "$res business");
        }
        $this->assertTrue(PlanCatalog::isPremium('growth', 'exportReports'));
        $this->assertFalse(PlanCatalog::isPremium('growth', 'advancedAnalytics'));
        $this->assertTrue(PlanCatalog::isPremium('business', 'advancedAnalytics'));
        $this->assertFalse(PlanCatalog::isPremium('free', 'staffManagement'));
    }

    public function testEffectivePlanHonoursExpiryAndStatusImmediately(): void
    {
        $now = new \DateTimeImmutable('2026-06-01 12:00:00', new \DateTimeZone('UTC'));
        $b = fn (string $plan, string $status, ?string $exp) => ['plan' => $plan, 'plan_status' => $status, 'plan_expiry' => $exp];
        $this->assertSame('business', PlanCatalog::effective($b('business', 'active', '2026-06-01 12:00:01'), $now));
        $this->assertSame('free', PlanCatalog::effective($b('business', 'active', '2026-06-01 11:59:59'), $now));
        $this->assertSame('free', PlanCatalog::effective($b('growth', 'suspended', '2027-01-01 00:00:00'), $now));
        $this->assertSame('free', PlanCatalog::effective($b('growth', 'expired', null), $now));
        $this->assertSame('growth', PlanCatalog::effective($b('starter', 'active', null), $now), 'no expiry = does not lapse');
        $this->assertSame('free', PlanCatalog::effective($b('free', 'active', null), $now));
    }

    public function testPermissionMatrixMirrorsSourceSidebarAndEditRules(): void
    {
        $this->assertTrue(Permissions::can('cashier', 'sales.create'));
        $this->assertFalse(Permissions::can('cashier', 'sales.edit'));
        $this->assertFalse(Permissions::can('cashier', 'expenses.manage'));
        $this->assertFalse(Permissions::can('cashier', 'credit.repay'));
        $this->assertTrue(Permissions::can('manager', 'sales.edit'));
        $this->assertTrue(Permissions::can('manager', 'dashboard.read'));
        $this->assertFalse(Permissions::can('manager', 'staff.manage'));
        $this->assertFalse(Permissions::can('manager', 'billing.manage'));
        $this->assertTrue(Permissions::can('owner', 'staff.manage'));
        $this->assertSame([], Permissions::for('admin'), 'unknown / platform roles carry no business permissions');
        $this->assertSame([], Permissions::for(''));
    }
}
