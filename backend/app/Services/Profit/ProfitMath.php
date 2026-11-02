<?php
declare(strict_types=1);

namespace LeenTrack\Services\Profit;

use LeenTrack\Core\Money;

/**
 * Profit on sales = what customers paid for the goods (after discounts, WITHOUT tax) minus what those goods cost.
 * Port of lib/profit/compute.ts. Per-sale figures are exact cents. Per-product figures share each sale's discount between its lines
 * in proportion to line value, which produces fractions of a cent; those are carried at 1/10,000 cent (integer) and rounded once at
 * the end, like the source rounds once at the end of its float sum.
 */
final class ProfitMath
{
    private const U = 10_000;   // fractional-cent precision for per-product allocation

    /**
     * @param list<array{id:string,receipt:?string,customer:?string,created_at:string,subtotal:Money,discount:Money}> $sales
     * @param list<array{sale_id:string,product_id:?string,quantity:int,total_price:Money,unit_cost:?Money,product_cost:?Money,product_name:?string,item_type:?string}> $items
     * @return array{summary:array<string,mixed>,bySale:list<array<string,mixed>>,byProduct:list<array<string,mixed>>}
     */
    public static function compute(array $sales, array $items): array
    {
        $bySaleItems = [];
        foreach ($items as $it) { $bySaleItems[$it['sale_id']][] = $it; }

        $bySale = [];
        $prod = [];
        $missingNames = [];
        $itemsMissingCost = 0;
        $withoutItems = 0;
        $amountWithoutItems = Money::zero();

        foreach ($sales as $s) {
            $lines = $bySaleItems[$s['id']] ?? [];
            $sold = $s['subtotal']->sub($s['discount']);                 // after discount, without tax
            $base = ['id' => $s['id'], 'receipt' => $s['receipt'] ?: '—', 'customer' => $s['customer'], 'createdAt' => $s['created_at']];
            if (!$lines) {
                $withoutItems++;
                $amountWithoutItems = $amountWithoutItems->add($sold);
                $bySale[] = $base + ['hasItems' => false, 'sold' => $sold, 'cost' => Money::zero(), 'profit' => Money::zero(), 'margin' => null, 'costMissing' => false];
                continue;
            }
            $subtotalLines = Money::zero();
            foreach ($lines as $l) { $subtotalLines = $subtotalLines->add($l['total_price']); }
            $cost = Money::zero();
            $missing = false;
            foreach ($lines as $l) {
                $unit = $l['unit_cost'] ?? $l['product_cost'];            // remembered cost first, else the product's current cost
                $lineCost = ($unit ?? Money::zero())->mulInt($l['quantity']);
                $isService = $l['item_type'] === 'service';
                $lineMiss = !$isService && ($unit === null || $unit->isZero());
                if ($lineMiss) { $missing = true; $itemsMissingCost++; $missingNames[(string) ($l['product_name'] ?: 'Item')] = true; }
                $cost = $cost->add($lineCost);
                // the sale's discount is shared between its lines in proportion to their value
                $shareU = $subtotalLines->minor > 0 ? Money::mulDivRound(max(0, $l['total_price']->minor), max(0, $s['discount']->minor) * self::U, $subtotalLines->minor) : 0;
                $lineSoldU = $l['total_price']->minor * self::U - $shareU;
                $key = (string) ($l['product_id'] ?: ($l['product_name'] ?: 'item'));
                $prod[$key] ??= ['key' => $key, 'name' => (string) ($l['product_name'] ?: 'Item'), 'qty' => 0, 'soldU' => 0, 'costU' => 0, 'costMissing' => false];
                $prod[$key]['qty'] += $l['quantity'];
                $prod[$key]['soldU'] += $lineSoldU;
                $prod[$key]['costU'] += $lineCost->minor * self::U;
                $prod[$key]['costMissing'] = $prod[$key]['costMissing'] || $lineMiss;
            }
            $profit = $sold->sub($cost);
            $bySale[] = $base + ['hasItems' => true, 'sold' => $sold, 'cost' => $cost, 'profit' => $profit, 'margin' => $sold->minor > 0 ? self::pct($profit->minor, $sold->minor) : null, 'costMissing' => $missing];
        }

        $sold = Money::zero();
        $cost = Money::zero();
        $counted = 0;
        foreach ($bySale as $r) { if ($r['hasItems']) { $counted++; $sold = $sold->add($r['sold']); $cost = $cost->add($r['cost']); } }
        $profit = $sold->sub($cost);
        $names = array_keys($missingNames);
        sort($names, SORT_STRING);

        $byProduct = [];
        foreach ($prod as $p) {
            $profitU = $p['soldU'] - $p['costU'];
            $byProduct[] = [
                'key' => $p['key'], 'name' => $p['name'], 'qty' => $p['qty'],
                'sold' => self::fromU($p['soldU']), 'cost' => self::fromU($p['costU']), 'profit' => self::fromU($profitU),
                'margin' => $p['soldU'] > 0 ? self::pctU($profitU, $p['soldU']) : null, 'costMissing' => $p['costMissing'],
            ];
        }
        usort($byProduct, fn ($a, $b) => $b['profit']->minor <=> $a['profit']->minor);   // stable (PHP ≥ 8.0), like Array.sort

        return [
            'summary' => [
                'sold' => $sold, 'cost' => $cost, 'profit' => $profit, 'margin' => $sold->minor > 0 ? self::pct($profit->minor, $sold->minor) : null,
                'salesCounted' => $counted, 'salesWithoutItems' => $withoutItems, 'amountWithoutItems' => $amountWithoutItems,
                'itemsMissingCost' => $itemsMissingCost, 'productsMissingCost' => $names,
            ],
            'bySale' => $bySale, 'byProduct' => $byProduct,
        ];
    }

    /** profit / sold × 100 rounded to 2 decimals, as a string like "37.50". Handles negative profit (a loss). */
    private static function pct(int $profitMinor, int $soldMinor): string
    {
        return self::fmtHundredths(self::signedMulDiv($profitMinor, 10_000, $soldMinor));
    }

    private static function pctU(int $profitU, int $soldU): string
    {
        return self::fmtHundredths(self::signedMulDiv($profitU, 10_000, $soldU));
    }

    private static function signedMulDiv(int $a, int $b, int $d): int
    {
        $q = Money::mulDivRound(abs($a), $b, abs($d));
        return ($a < 0) !== ($d < 0) ? -$q : $q;
    }

    private static function fmtHundredths(int $h): string
    {
        $a = abs($h);
        return ($h < 0 ? '-' : '') . intdiv($a, 100) . '.' . str_pad((string) ($a % 100), 2, '0', STR_PAD_LEFT);
    }

    /** units of 1/10,000 cent -> Money (cents), rounded half away from zero */
    private static function fromU(int $u): Money
    {
        $q = Money::mulDivRound(abs($u), 1, self::U);
        return Money::ofMinor($u < 0 ? -$q : $q);
    }
}
