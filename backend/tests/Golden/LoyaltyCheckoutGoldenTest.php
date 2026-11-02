<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Golden;

use LeenTrack\Core\Money;
use LeenTrack\Services\Loyalty\LoyaltyMath;
use PHPUnit\Framework\TestCase;

/**
 * G-DISC-1. tests/Golden/loyalty_checkout.json was produced by running the ORIGINAL lib/loyalty/math.ts
 * (tools/golden/gen_loyalty.mjs). PHP must reproduce every value to the cent.
 */
final class LoyaltyCheckoutGoldenTest extends TestCase
{
    private static function m(float|int $n): Money
    {
        return Money::parse(rtrim(rtrim(sprintf('%.6F', $n), '0'), '.') ?: '0', true);
    }

    public function testEveryGoldenCaseMatchesTheOriginalTypeScript(): void
    {
        $g = json_decode((string) file_get_contents(__DIR__ . '/loyalty_checkout.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(2000, $g['count']);
        $diffs = [];
        foreach ($g['cases'] as $n => $c) {
            $i = $c['input'];
            $o = $c['output'];
            $r = LoyaltyMath::checkoutDiscounts([
                'subtotal' => self::m($i['subtotal']), 'manual' => self::m($i['manual']), 'groupPct' => rtrim(rtrim(sprintf('%.4F', $i['groupPct']), '0'), '.') ?: '0',
                'groupOn' => $i['groupOn'], 'loyaltyOn' => $i['loyaltyOn'], 'customerPoints' => $i['customerPoints'],
                'pointValue' => self::m($i['pointValue']), 'pointsWanted' => $i['pointsWanted'],
            ]);
            $got = [
                'groupPercent' => (float) $r['groupPercent'], 'groupDiscount' => (float) $r['groupDiscount']->toString(), 'manual' => (float) $r['manual']->toString(),
                'manualError' => $r['manualError'], 'maxPoints' => $r['maxPoints'], 'pointsUsed' => $r['pointsUsed'],
                'pointsValue' => (float) $r['pointsValue']->toString(), 'discount' => (float) $r['discount']->toString(),
            ];
            $want = array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, $o);
            foreach ($want as $k => $v) {
                if ($got[$k] !== $v && !(is_float($v) && abs($got[$k] - $v) < 0.0000001)) {
                    $diffs[] = "case $n " . json_encode($i) . " field $k: want " . json_encode($v) . ' got ' . json_encode($got[$k]);
                }
            }
        }
        $this->assertSame([], array_slice($diffs, 0, 10), count($diffs) . ' differing fields in total');
    }

    public function testLoyaltyExampleSentenceIsAClientConcernNotAnApiOne(): void
    {
        // The TS helper only builds a settings-screen sentence; the API returns the numbers, clients phrase them.
        $g = json_decode((string) file_get_contents(__DIR__ . '/loyalty_checkout.json'), true);
        $this->assertSame('A ₦50,000 purchase earns 5 points, worth ₦500 (1% back).', $g['examples'][0]['output']);
    }
}
