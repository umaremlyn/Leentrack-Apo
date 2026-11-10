<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Unit;

use LeenTrack\Billing\PriceTable;
use PHPUnit\Framework\TestCase;

/** The 48 amounts the ORIGINAL billing page charges (tools/golden/gen_billing.mjs reads them out of the source text). */
final class BillingGoldenTest extends TestCase
{
    public function testEveryPlanCurrencyAndCycleCostsExactlyWhatTheSourceCharged(): void
    {
        $g = json_decode((string) file_get_contents(__DIR__ . '/../Golden/billing.json'), true);
        $this->assertCount(48, $g['cases']);
        foreach ($g['cases'] as $c) {
            $label = "{$c['plan']} {$c['currency']} {$c['cycle']}";
            $this->assertSame((int) $c['amount_minor'], PriceTable::amountMinor($c['plan'], $c['cycle'], $c['currency']), $label);
            $this->assertSame((int) $c['days'], PriceTable::days($c['cycle']), "$label days");
            $this->assertSame((int) round($c['base'] * 100), PriceTable::monthly($c['plan'], $c['currency']), "$label base");
        }
        $this->assertEqualsCanonicalizing(['NGN', 'GHS', 'KES', 'ZAR', 'EGP', 'USD', 'GBP', 'EUR'], PriceTable::currencies());
    }

    public function testUnknownInputsAreRefusedNotPricedAtZero(): void
    {
        foreach ([['enterprise', 'monthly', 'NGN'], ['growth', 'weekly', 'NGN'], ['growth', 'monthly', 'XXX'], ['free', 'monthly', 'NGN']] as [$p, $c, $cur]) {
            try { PriceTable::amountMinor($p, $c, $cur); $this->fail("$p/$c/$cur priced"); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }
}
