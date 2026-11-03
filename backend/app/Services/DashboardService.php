<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Money;
use LeenTrack\Core\Uuid;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\CatalogRepository;
use LeenTrack\Repositories\ReportRepository;
use LeenTrack\Repositories\SaleRepository;
use LeenTrack\Services\Profit\PeriodRange;

/**
 * Dashboard. Every figure is a separately NAMED metric — billed revenue is not collected cash is not profit:
 *  revenue_billed     Σ total_amount of completed+pending sales created in the period (includes tax and unpaid credit; what the web app called "revenue")
 *  revenue_collected  money actually received in the period: paid at the till + repayments received
 *  tax                Σ tax_amount of those sales (belongs to the tax authority, not the business)
 *  net_sales          Σ (subtotal − discount) = sold ex-tax
 *  cogs / gross_profit  from ProfitMath (remembered cost per item); net_profit = gross_profit − expenses
 * Credit figures come from CreditService::totals() — the same code as the credit page.
 */
final class DashboardService
{
    public function __construct(
        private readonly ReportRepository $reports,
        private readonly CatalogRepository $catalog,
        private readonly SaleRepository $sales,
        private readonly ProfitService $profit,
        private readonly CreditService $credit,
        private readonly \LeenTrack\Repositories\PurchaseRepository $purchases,
    ) {
    }

    /** @param array<string,mixed> $q @return array<string,mixed> */
    public function show(TenantContext $t, array $q): array
    {
        $t->require('dashboard.read');
        if ($extra = array_diff(array_keys($q), ['from', 'to', 'outlet_id'])) { throw ApiException::validation([(string) reset($extra) => ['Unknown filter.']]); }
        $outlet = null;
        if (isset($q['outlet_id'])) {
            if (!is_string($q['outlet_id']) || !Uuid::isValid($q['outlet_id'])) { throw ApiException::validation(['outlet_id' => ['Must be a valid id.']]); }
            if (!$t->canAccessOutlet($q['outlet_id']) || !$this->reports->locationExists($t->businessId, $q['outlet_id'])) { throw ApiException::notFound('Outlet not found.'); }
            $outlet = $q['outlet_id'];
        }
        [$range, $tz] = $this->profit->range($t, null, $q['from'] ?? null, $q['to'] ?? null);
        if (!isset($q['from'])) { $range = PeriodRange::of('month', Clock::now(), $tz); }
        $days = (int) (new \DateTimeImmutable($range['toDate']))->diff(new \DateTimeImmutable($range['fromDate']))->days;
        $prevFromLocal = (new \DateTimeImmutable($range['fromDate'], $tz))->modify("-$days days");
        $prev = PeriodRange::custom($prevFromLocal->format('Y-m-d'), (new \DateTimeImmutable($range['fromDate']))->modify('-1 day')->format('Y-m-d'), $tz);
        $today = PeriodRange::of('today', Clock::now(), $tz);

        try {
            $cur = $this->period($t, $range, $outlet);
            $before = $this->period($t, $prev, $outlet);
            $now = $this->period($t, $today, $outlet);
            $credit = $this->credit->totals($t, $outlet !== null ? [$outlet] : $t->outletIds);
            $val = $this->catalog->valuation($t->businessId, $outlet, $t->outletIds);
            $counts = $this->reports->stockCounts($t->businessId, $outlet, $t->outletIds);
            $growth = Money::parse($before['revenue_billed'])->minor > 0
                ? $this->growth(Money::parse($cur['revenue_billed']), Money::parse($before['revenue_billed'])) : null;
        } catch (\OverflowException) {
            throw new ApiException('range_too_large', 'The amounts in that period are too large to add up. Choose a shorter period.', 413);
        }
        $from = Clock::sql($range['from']);
        $to = Clock::sql($range['to']);
        return [
            'period' => ['from' => $range['fromDate'], 'to' => (new \DateTimeImmutable($range['toDate']))->modify('-1 day')->format('Y-m-d'), 'days' => $days, 'timezone' => $tz->getName(), 'outlet_id' => $outlet],
            'metrics' => $cur,
            'previous_period' => ['from' => $prev['fromDate'], 'to' => (new \DateTimeImmutable($prev['toDate']))->modify('-1 day')->format('Y-m-d')] + $before + ['revenue_billed_growth_percent' => $growth],
            'today' => $now,
            'credit' => ['outstanding' => $credit['total_owed'], 'overdue' => $credit['overdue_owed'], 'customers_owing' => $credit['customers'], 'open_sales' => $credit['open_sales']],
            'inventory' => ['value_at_cost' => $val['cost'], 'value_at_retail' => $val['retail'], 'units' => $val['units'], 'active_items' => $counts['products'], 'low_stock' => $counts['low'], 'out_of_stock' => $counts['out'],
                'pending_purchase_orders' => $this->purchases->pendingCount($t->businessId, $outlet, $t->outletIds)],
            'recent_sales' => array_map(fn ($s) => ['id' => $s['id'], 'receipt_number' => $s['receipt_number'], 'total_amount' => $s['total_amount'], 'payment_method' => $s['payment_method'], 'payment_status' => $s['payment_status'],
                'customer_name' => $s['customer_name'], 'created_at' => (new \DateTimeImmutable($s['created_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z')], $this->reports->recentSales($t->businessId, $outlet, $t->outletIds, 5)),
            'top_products' => array_map(fn ($p) => ['product_id' => $p['product_id'], 'name' => $p['name'], 'quantity' => (int) $p['qty'], 'revenue' => $this->fmt((string) $p['revenue'])], $this->reports->topProducts($t->businessId, $from, $to, $outlet, $t->outletIds, 5)),
            'series' => $days <= 92 ? $this->series($t, $range, $tz, $outlet) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function period(TenantContext $t, array $range, ?string $outlet): array
    {
        $bid = $t->businessId;
        $from = Clock::sql($range['from']);
        $to = Clock::sql($range['to']);
        $tot = $this->reports->salesTotals($bid, $from, $to, $outlet, $t->outletIds);
        $repaid = Money::parse($this->reports->repaymentsTotal($bid, $from, $to, $outlet, $t->outletIds));
        $out = [
            'transactions' => $tot['count'], 'revenue_billed' => $this->fmt($tot['billed']), 'revenue_collected' => Money::parse($tot['paid_at_till'])->add($repaid)->toString(),
            'tax' => $this->fmt($tot['tax']), 'net_sales' => $this->fmt($tot['net_sales']),
        ];
        $expenses = Money::parse($this->reports->expensesTotal($bid, $range['fromDate'], $range['toDate'], $outlet, $t->outletIds));
        $out['expenses'] = $expenses->toString();
        $p = $this->profit->compute($t, $range, $outlet)['summary'];
        $unknown = $tot['count'] > 0 && $p['salesCounted'] === 0;
        $out += [
            'cogs' => $p['cost']->toString(), 'gross_profit' => $unknown ? null : $p['profit']->toString(),
            'net_profit' => $unknown ? null : $p['profit']->sub($expenses)->toString(),
            'sales_without_items' => $p['salesWithoutItems'], 'items_missing_cost' => $p['itemsMissingCost'],
        ];
        return $out;
    }

    /** (cur − prev) / prev × 100, 2 dp, as a string */
    private function growth(Money $cur, Money $prev): string
    {
        $diff = $cur->minor - $prev->minor;
        $q = Money::mulDivRound(abs($diff), 10_000, $prev->minor);
        $h = $diff < 0 ? -$q : $q;
        $a = abs($h);
        return ($h < 0 ? '-' : '') . intdiv($a, 100) . '.' . str_pad((string) ($a % 100), 2, '0', STR_PAD_LEFT);
    }

    /** One entry per LOCAL calendar day (the source bucketed by UTC date, which put late-evening sales on the wrong day). @return list<array<string,string>> */
    private function series(TenantContext $t, array $range, \DateTimeZone $tz, ?string $outlet): array
    {
        $rev = [];
        foreach ($this->reports->saleTimes($t->businessId, Clock::sql($range['from']), Clock::sql($range['to']), $outlet, $t->outletIds) as [$at, $total]) {
            $d = (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
            $rev[$d] = ($rev[$d] ?? Money::zero())->add(Money::parse($total));
        }
        $exp = $this->reports->expensesByDay($t->businessId, $range['fromDate'], $range['toDate'], $outlet, $t->outletIds);
        $out = [];
        for ($d = new \DateTimeImmutable($range['fromDate']); $d->format('Y-m-d') < $range['toDate']; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $out[] = ['date' => $k, 'revenue_billed' => ($rev[$k] ?? Money::zero())->toString(), 'expenses' => isset($exp[$k]) ? $this->fmt((string) $exp[$k]) : '0.00'];
        }
        return $out;
    }

    private function fmt(string $decimal): string
    {
        return preg_match('/^(-?\d+)(?:\.(\d+))?$/', $decimal, $m) ? $m[1] . '.' . str_pad(substr($m[2] ?? '', 0, 2), 2, '0') : '0.00';
    }
}
