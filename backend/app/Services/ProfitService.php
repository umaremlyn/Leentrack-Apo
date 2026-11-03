<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Money;
use LeenTrack\Core\Pager;
use LeenTrack\Core\Uuid;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\ReportRepository;
use LeenTrack\Repositories\SaleRepository;
use LeenTrack\Services\Profit\PeriodRange;
use LeenTrack\Services\Profit\ProfitMath;

/** "My Profit": profit on sales (after discount, without tax, minus remembered cost) and net profit after expenses. Docs 04 §8. */
final class ProfitService
{
    public function __construct(private readonly ReportRepository $reports, private readonly SaleRepository $sales)
    {
    }

    /** @return array{0:array{from:\DateTimeImmutable,to:\DateTimeImmutable,fromDate:string,toDate:string},1:\DateTimeZone} */
    public function range(TenantContext $t, ?string $period, ?string $from, ?string $to): array
    {
        $tz = new \DateTimeZone($this->sales->businessTimezone($t->businessId));
        if (($from !== null || $to !== null) && ($period === null || $period === 'custom')) {
            if ($from === null || $to === null) { throw ApiException::validation([$from === null ? 'from' : 'to' => ['Both from and to are required for a custom range.']]); }
            return [PeriodRange::custom($from, $to, $tz), $tz];
        }
        if ($period === 'custom') { throw ApiException::validation(['from' => ['Both from and to are required for a custom range.']]); }
        if ($from !== null || $to !== null) { throw ApiException::validation(['period' => ['Use either period or from/to.']]); }
        return [PeriodRange::of($period ?? 'month', Clock::now(), $tz), $tz];
    }

    /**
     * ProfitMath over the period's sales; returns strings for the API. Also used by the dashboard.
     * @param list<string>|null $outletIds
     * @return array{summary:array<string,mixed>,bySale:list<array<string,mixed>>,byProduct:list<array<string,mixed>>}
     */
    public function compute(TenantContext $t, array $range, ?string $outletId): array
    {
        $bid = $t->businessId;
        $from = Clock::sql($range['from']);
        $to = Clock::sql($range['to']);
        try {
            $sales = array_map(fn ($s) => ['id' => $s['id'], 'receipt' => $s['receipt_number'], 'customer' => $s['customer_name'], 'created_at' => $s['created_at'], 'subtotal' => Money::parse($s['subtotal']), 'discount' => Money::parse($s['discount_amount'])],
                $this->reports->salesInRange($bid, $from, $to, $outletId, $t->outletIds));
            $items = array_map(fn ($i) => ['sale_id' => $i['sale_id'], 'product_id' => $i['product_id'], 'quantity' => (int) $i['quantity'], 'total_price' => Money::parse($i['total_price']),
                'unit_cost' => $i['unit_cost'] === null ? null : Money::parse($i['unit_cost']), 'product_cost' => $i['product_cost'] === null ? null : Money::parse($i['product_cost']), 'product_name' => $i['product_name'], 'item_type' => $i['item_type']],
                $this->reports->itemsInRange($bid, $from, $to, $outletId, $t->outletIds));
            return ProfitMath::compute($sales, $items);
        } catch (\OverflowException) {
            throw new ApiException('range_too_large', 'The amounts in that period are too large to add up. Choose a shorter period.', 413);
        }
    }

    /** @param array<string,mixed> $q @return array{data:array<string,mixed>,meta:array<string,int>} */
    public function report(TenantContext $t, array $q): array
    {
        $t->require('profit.read');
        $allowed = ['period', 'from', 'to', 'outlet_id', 'page', 'per_page'];
        if ($extra = array_diff(array_keys($q), $allowed)) { throw ApiException::validation([(string) reset($extra) => ['Unknown filter.']]); }
        $period = isset($q['period']) ? (is_string($q['period']) && in_array($q['period'], [...PeriodRange::KEYS, 'custom'], true) ? $q['period'] : throw ApiException::validation(['period' => ['Must be one of: ' . implode(', ', PeriodRange::KEYS) . ', custom.']])) : null;
        [$range, $tz] = $this->range($t, $period, $q['from'] ?? null, $q['to'] ?? null);
        $outlet = $this->outlet($t, $q);
        $r = $this->compute($t, $range, $outlet);
        try {
            $expenses = Money::parse($this->reports->expensesTotal($t->businessId, $range['fromDate'], $range['toDate'], $outlet, $t->outletIds));
        } catch (\OverflowException) {
            throw new ApiException('range_too_large', 'The amounts in that period are too large to add up.', 413);
        }
        $s = $r['summary'];
        // Profit is "unknown" (null) when the period has sales but none with item details — never shown as a made-up 0.
        $unknown = count($r['bySale']) > 0 && $s['salesCounted'] === 0;
        $profit = $unknown ? null : $s['profit'];
        $pg = Pager::fromQuery($q, 100);
        $page = array_slice($r['bySale'], $pg->offset(), $pg->perPage);
        $data = [
            'period' => ['key' => $period ?? 'month', 'from' => $range['fromDate'], 'to' => (new \DateTimeImmutable($range['toDate']))->modify('-1 day')->format('Y-m-d'), 'timezone' => $tz->getName()],
            'summary' => [
                'sold' => $s['sold']->toString(), 'cost' => $s['cost']->toString(), 'profit' => $profit?->toString(), 'margin' => $unknown ? null : $s['margin'],
                'sales_counted' => $s['salesCounted'], 'sales_without_items' => $s['salesWithoutItems'], 'amount_without_items' => $s['amountWithoutItems']->toString(),
                'items_missing_cost' => $s['itemsMissingCost'], 'products_missing_cost' => $s['productsMissingCost'],
            ],
            'expenses' => $expenses->toString(),
            'net_profit' => $profit === null ? null : $profit->sub($expenses)->toString(),
            'by_sale' => array_map(fn ($x) => ['id' => $x['id'], 'receipt' => $x['receipt'], 'customer' => $x['customer'], 'created_at' => (new \DateTimeImmutable($x['createdAt'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
                'has_items' => $x['hasItems'], 'sold' => $x['sold']->toString(), 'cost' => $x['cost']->toString(), 'profit' => $x['profit']->toString(), 'margin' => $x['margin'], 'cost_missing' => $x['costMissing']], $page),
            'by_product' => array_map(fn ($x) => ['product_id' => Uuid::isValid($x['key']) ? $x['key'] : null, 'name' => $x['name'], 'quantity' => $x['qty'], 'sold' => $x['sold']->toString(), 'cost' => $x['cost']->toString(), 'profit' => $x['profit']->toString(), 'margin' => $x['margin'], 'cost_missing' => $x['costMissing']], array_slice($r['byProduct'], 0, 200)),
        ];
        return ['data' => $data, 'meta' => $pg->meta(count($r['bySale']))];
    }

    /** @param array<string,mixed> $q */
    private function outlet(TenantContext $t, array $q): ?string
    {
        if (!isset($q['outlet_id'])) { return null; }
        if (!is_string($q['outlet_id']) || !Uuid::isValid($q['outlet_id'])) { throw ApiException::validation(['outlet_id' => ['Must be a valid id.']]); }
        if (!$t->canAccessOutlet($q['outlet_id']) || !$this->reports->locationExists($t->businessId, $q['outlet_id'])) { throw ApiException::notFound('Outlet not found.'); }
        return $q['outlet_id'];
    }
}
