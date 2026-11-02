<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Unit;

use LeenTrack\Core\Money;
use LeenTrack\Services\Loyalty\LoyaltyMath;
use PHPUnit\Framework\TestCase;

/** Earning rule of loyalty_apply() (migration 018) — worked examples and properties. */
final class LoyaltyMathTest extends TestCase
{
    private function earn(string $progress, string $money, string $rate, int $balance): array
    {
        $r = LoyaltyMath::earn(Money::parse($progress), Money::parse($money), Money::parse($rate), $balance);
        return [$r['points'], $r['progress']->toString(), $r['balance']];
    }

    public function testWorkedExamplesAtDefaultRate(): void
    {
        $this->assertSame([2, '5000.00', 2], $this->earn('0', '25000', '10000', 0));
        $this->assertSame([1, '0.00', 3], $this->earn('5000', '5000', '10000', 2));
        $this->assertSame([0, '9999.99', 0], $this->earn('0', '9999.99', '10000', 0));
        $this->assertSame([1, '0.00', 1], $this->earn('9999.99', '0.01', '10000', 0), 'a cent completes the point');
    }

    public function testCorrectionsTakePointsBackAndNeverGoBelowZero(): void
    {
        $this->assertSame([-1, '0.00', 2], $this->earn('0', '-10000', '10000', 3));
        $this->assertSame([-1, '5000.00', 1], $this->earn('0', '-5000', '10000', 2), 'half a point back = one point gone, 5000 progress restored');
        $this->assertSame([0, '0.00', 0], $this->earn('0', '-20000', '10000', 0), 'balance 0 stays 0 and progress is floored at 0');
        $this->assertSame([-1, '0.00', 0], $this->earn('0', '-20000', '10000', 1), 'only the points that exist can be taken back');
    }

    public function testNonIntegerRateAndTinyRate(): void
    {
        $this->assertSame([3, '0.51', 3], $this->earn('0', '100.50', '33.33', 0));
        $this->assertSame([7, '0.00', 7], $this->earn('0', '7', '1', 0));
    }

    public function testConservationAndBoundsOverRandomPositiveSequences(): void
    {
        mt_srand(20261102);
        for ($run = 0; $run < 200; $run++) {
            $rate = Money::ofMinor(mt_rand(100, 5_000_000));
            $progress = Money::zero();
            $balance = 0;
            $received = Money::zero();
            for ($i = 0; $i < 30; $i++) {
                $m = Money::ofMinor(mt_rand(1, 3_000_000));
                $r = LoyaltyMath::earn($progress, $m, $rate, $balance);
                $progress = $r['progress'];
                $balance = $r['balance'];
                $received = $received->add($m);
                $this->assertTrue($progress->gte(Money::zero()) && $progress->lt($rate), 'progress is always in [0, rate)');
            }
            $this->assertSame($received->minor, $rate->mulInt($balance)->add($progress)->minor, 'money received = points*rate + progress (nothing lost, nothing invented)');
        }
    }

    public function testBalanceNeverNegativeOverRandomSequencesWithCorrections(): void
    {
        mt_srand(7);
        for ($run = 0; $run < 300; $run++) {
            $rate = Money::ofMinor(mt_rand(100, 2_000_000));
            $progress = Money::zero();
            $balance = mt_rand(0, 5);
            for ($i = 0; $i < 40; $i++) {
                $m = Money::ofMinor(mt_rand(-2_500_000, 2_500_000));
                $r = LoyaltyMath::earn($progress, $m, $rate, $balance);
                $progress = $r['progress'];
                $balance = $r['balance'];
                $this->assertGreaterThanOrEqual(0, $balance);
                $this->assertTrue($progress->gte(Money::zero()));
            }
        }
    }

    public function testGroupPercentIsCappedAt99AndNegativeIsZero(): void
    {
        $base = ['subtotal' => Money::parse('1000'), 'manual' => Money::zero(), 'groupOn' => true, 'loyaltyOn' => false, 'customerPoints' => 0, 'pointValue' => Money::zero(), 'pointsWanted' => 0];
        $this->assertSame('990.00', LoyaltyMath::checkoutDiscounts($base + ['groupPct' => '100'])['groupDiscount']->toString());
        $this->assertSame('0.00', LoyaltyMath::checkoutDiscounts($base + ['groupPct' => '-5'])['groupDiscount']->toString());
        $this->assertSame('75.00', LoyaltyMath::checkoutDiscounts($base + ['groupPct' => '7.5'])['groupDiscount']->toString());
    }
}
