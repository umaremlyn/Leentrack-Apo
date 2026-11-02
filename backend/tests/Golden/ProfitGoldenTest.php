<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Golden;

use LeenTrack\Core\Money;
use LeenTrack\Services\Profit\PeriodRange;
use LeenTrack\Services\Profit\ProfitMath;
use PHPUnit\Framework\TestCase;

/** G-PROF-1 / G-PROF-2: tests/Golden/profit.json was produced by running the ORIGINAL lib/profit/compute.ts (tools/golden/gen_profit.mjs). */
final class ProfitGoldenTest extends TestCase
{
    private static function m(float|int|null $n): ?Money
    {
        return $n === null ? null : Money::parse(rtrim(rtrim(sprintf('%.6F', $n), '0'), '.') ?: '0', true);
    }

    /** @return array<string,mixed> */
    private static function load(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/profit.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function near(mixed $want, mixed $got, string $label): void
    {
        if ($want === null) { $this->assertNull($got, $label); return; }
        $g = $got instanceof Money ? (float) $got->toString() : (is_string($got) ? (float) $got : $got);
        $this->assertEqualsWithDelta((float) $want, (float) $g, 0.0000001, $label);
    }

    public function testComputeProfitMatchesTheOriginalForEveryCase(): void
    {
        $g = self::load();
        $this->assertGreaterThanOrEqual(150, count($g['cases']));
        foreach ($g['cases'] as $n => $c) {
            $sales = array_map(fn ($s) => ['id' => $s['id'], 'receipt' => $s['receipt_number'], 'customer' => $s['customer']['name'] ?? null, 'created_at' => $s['created_at'], 'subtotal' => self::m($s['subtotal']), 'discount' => self::m($s['discount_amount'])], $c['input']['sales']);
            $items = array_map(fn ($i) => ['sale_id' => $i['sale_id'], 'product_id' => $i['product_id'], 'quantity' => $i['quantity'], 'total_price' => self::m($i['total_price']),
                'unit_cost' => self::m($i['unit_cost']), 'product_cost' => self::m($i['products']['cost_price']), 'product_name' => $i['products']['name'], 'item_type' => $i['products']['item_type']], $c['input']['items']);
            $r = ProfitMath::compute($sales, $items);
            $w = $c['output'];
            foreach (['sold', 'cost', 'profit', 'margin', 'amountWithoutItems'] as $k) { $this->near($w['summary'][$k], $r['summary'][$k], "case $n summary.$k"); }
            foreach (['salesCounted', 'salesWithoutItems', 'itemsMissingCost', 'productsMissingCost'] as $k) { $this->assertSame($w['summary'][$k], $r['summary'][$k], "case $n summary.$k"); }
            $this->assertCount(count($w['bySale']), $r['bySale'], "case $n bySale");
            foreach ($w['bySale'] as $i => $ws) {
                $gs = $r['bySale'][$i];
                $this->assertSame([$ws['id'], $ws['receipt'], $ws['customer'], $ws['hasItems'], $ws['costMissing']], [$gs['id'], $gs['receipt'], $gs['customer'], $gs['hasItems'], $gs['costMissing']], "case $n sale $i");
                foreach (['sold', 'cost', 'profit', 'margin'] as $k) { $this->near($ws[$k], $gs[$k], "case $n sale $i $k"); }
            }
            $this->assertCount(count($w['byProduct']), $r['byProduct'], "case $n byProduct");
            foreach ($w['byProduct'] as $i => $wp) {
                $gp = $r['byProduct'][$i];
                $this->assertSame([$wp['key'], $wp['name'], $wp['qty'], $wp['costMissing']], [$gp['key'], $gp['name'], $gp['qty'], $gp['costMissing']], "case $n product $i");
                foreach (['sold', 'cost', 'profit', 'margin'] as $k) { $this->near($wp[$k], $gp[$k], "case $n product $i $k"); }
            }
        }
    }

    public function testPeriodRangesMatchTheOriginalInTheLagosZone(): void
    {
        $g = self::load();
        $this->assertSame('Africa/Lagos', $g['tz']);
        $tz = new \DateTimeZone('Africa/Lagos');
        foreach ($g['periods'] as $p) {
            $r = PeriodRange::of($p['key'], new \DateTimeImmutable($p['now']), $tz);
            $fmt = fn (\DateTimeImmutable $d) => $d->format('Y-m-d\TH:i:s.v\Z');
            $this->assertSame([$p['from'], $p['to'], $p['fromDate'], $p['toDate']], [$fmt($r['from']), $fmt($r['to']), $r['fromDate'], $r['toDate']], "{$p['key']} at {$p['now']}");
        }
    }

    public function testCustomRangeIsWholeLocalDaysInclusiveOfTheLastDay(): void
    {
        $r = PeriodRange::custom('2026-03-02', '2026-03-02', new \DateTimeZone('Africa/Lagos'));
        $this->assertSame(['2026-03-01 23:00:00', '2026-03-02 23:00:00'], [$r['from']->format('Y-m-d H:i:s'), $r['to']->format('Y-m-d H:i:s')]);
    }
}
