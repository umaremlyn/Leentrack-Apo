<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Unit;

use LeenTrack\Core\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[DataProvider('parseCases')]
    public function testParse(string $in, string $out): void
    {
        $this->assertSame($out, Money::parse($in, true)->toString());
    }

    /** @return array<string,array{string,string}> */
    public static function parseCases(): array
    {
        return [
            'int' => ['1500', '1500.00'],
            'commas' => ['1,500.5', '1500.50'],
            'two dp' => ['0.07', '0.07'],
            'half up' => ['1.005', '1.01'],
            'down' => ['1.004', '1.00'],
            'negative' => ['-2.5', '-2.50'],
        ];
    }

    public function testStrictParseRejectsExtraDecimalsAndGarbage(): void
    {
        foreach (['1.005', '1e3', 'abc', '', '1.2.3', '99999999999999'] as $bad) {
            try {
                Money::parse($bad);
                $this->fail("accepted $bad");
            } catch (\InvalidArgumentException | \OverflowException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** Values mirror the source's tax rule: tax = r2((subtotal - discount) * rate / 100). */
    #[DataProvider('percentCases')]
    public function testPercent(string $base, string $pct, string $expected): void
    {
        $this->assertSame($expected, Money::parse($base)->percent($pct)->toString());
    }

    /** @return array<string,array{string,string,string}> */
    public static function percentCases(): array
    {
        return [
            '7.5% of 1000' => ['1000.00', '7.5', '75.00'],
            '7.5% of 33.33' => ['33.33', '7.5', '2.50'],      // 2.49975 -> 2.50
            '7.5% of 0.10' => ['0.10', '7.5', '0.01'],        // 0.0075 -> 0.01
            '10% of 0.05' => ['0.05', '10', '0.01'],          // 0.005 -> 0.01 (half up)
            '0%' => ['999.99', '0', '0.00'],
            '99% group cap' => ['12345.67', '99', '12222.21'], // 12222.2133
            '0.25%' => ['10000.00', '0.25', '25.00'],
        ];
    }

    public function testLineTotalIsExact(): void
    {
        // 3 × 19.99 — floats give 59.97000000000001; integer cents give exactly 59.97
        $this->assertSame('59.97', Money::parse('19.99')->mulInt(3)->toString());
    }

    public function testMulRatioNegativeRoundsAwayFromZero(): void
    {
        $this->assertSame('-0.01', Money::ofMinor(-1)->mulRatio(1, 2)->toString());
    }

    public function testOverflowGuard(): void
    {
        $this->expectException(\OverflowException::class);
        Money::parse('999999999.99')->mulInt(1000);
    }

    public function testCompareAndArithmetic(): void
    {
        $a = Money::parse('10.00');
        $b = Money::parse('2.50');
        $this->assertSame('7.50', $a->sub($b)->toString());
        $this->assertTrue($a->gt($b));
        $this->assertSame('2.50', $a->min($b)->toString());
        $this->assertSame('0.00', Money::zero()->toString());
    }

    public function testFloorDivIsExactAndFloorsTowardMinusInfinity(): void
    {
        $m = fn (string $x) => Money::parse($x);
        $this->assertSame(3, $m('0.30')->floorDiv($m('0.10')), 'floats give 2.9999999999999996 -> 2; exact cents give 3');
        $this->assertSame(0, $m('9999.99')->floorDiv($m('10000')));
        $this->assertSame(5, $m('50000')->floorDiv($m('10000')));
        $this->assertSame(-1, Money::ofMinor(-1)->floorDiv($m('10000')));
        $this->assertSame(-1, $m('-10000')->floorDiv($m('10000')));
        $this->assertSame(-2, $m('-10000.01')->floorDiv($m('10000')));
        $this->assertSame(0, Money::zero()->floorDiv($m('1')));
        $this->expectException(\DivisionByZeroError::class);
        $m('5')->floorDiv(Money::zero());
    }

    /** Expected values computed independently with Python big integers: round_half_up(a*b/d). Includes products far beyond 64 bits. */
    public function testMulDivRoundIsExactEvenWhenTheProductExceeds64Bits(): void
    {
        $cases = [
            [999_999_999_999, 999_999_999_999, 999_999_999_999, 999_999_999_999],   // a*b ≈ 1e24
            [999_999_999_999, 999_999_999_998, 1_000_000_000_000, 999_999_999_997],
            [100_000_000_000, 33_333, 100_000_000_000, 33_333],
            [5, 1, 2, 3],                                                          // 2.5 -> 3 (half up)
            [7, 1, 2, 4],
            [1, 1, 3, 0],
            [2, 1, 3, 1],
            [0, 123_456_789, 7, 0],
            [123_456_789_012, 987_654_321_098, 555_555_555_555, 219_478_736_046],
        ];
        foreach ($cases as [$a, $b, $d, $want]) {
            $this->assertSame($want, Money::mulDivRound($a, $b, $d), "$a*$b/$d");
        }
    }

    public function testRatioTaxOnVeryLargeAmountsDoesNotOverflowOrGoFloat(): void
    {
        // taxable ₦9.99bn, stored tax/base ratio of an "unusual" old sale: the intermediate product is ~1e23
        $taxable = Money::ofMinor(999_000_000_000);
        $r = $taxable->mulRatio(9_999_999_999, 9_999_999_999);
        $this->assertSame(999_000_000_000, $r->minor);
        $half = $taxable->mulRatio(4_999_999_999, 9_999_999_999);
        $this->assertSame(499_499_999_950, $half->minor, 'exact: 999,000,000,000 × 4,999,999,999 / 9,999,999,999');
    }
}
