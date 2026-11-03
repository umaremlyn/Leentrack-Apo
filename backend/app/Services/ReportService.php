<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Csv;
use LeenTrack\Core\Money;
use LeenTrack\Core\Pager;
use LeenTrack\Core\Response;
use LeenTrack\Core\Uuid;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\CatalogRepository;
use LeenTrack\Repositories\PurchaseRepository;
use LeenTrack\Repositories\ReportRepository;
use LeenTrack\Repositories\SaleRepository;
use LeenTrack\Services\Profit\PeriodRange;

/**
 * Reports are VIEWS over the same code that answers the dashboard and the module pages: profit comes from ProfitService, credit from
 * CreditService, expenses from ExpenseService. There is never a second definition of a number. JSON is paged; CSV is the whole report
 * (plan-gated `exportReports`, formula-injection-safe, UTF-8 BOM so Excel opens it correctly).
 */
final class ReportService
{
    public const REPORTS = ['sales', 'profit', 'inventory', 'expenses', 'credit', 'purchases', 'outlets', 'products'];
    private const EXTRA_PERMISSION = ['profit' => 'profit.read', 'outlets' => 'profit.read', 'expenses' => 'expenses.manage', 'credit' => 'credit.read'];

    public function __construct(
        private readonly ReportRepository $reports,
        private readonly SaleRepository $sales,
        private readonly CatalogRepository $catalog,
        private readonly ProfitService $profit,
        private readonly ExpenseService $expenses,
        private readonly CreditService $credit,
    ) {
    }

    /** What this user may run. @return list<array<string,mixed>> */
    public function index(TenantContext $t): array
    {
        $t->require('reports.read');
        return array_map(fn ($n) => ['key' => $n, 'allowed' => $t->can(self::EXTRA_PERMISSION[$n] ?? 'reports.read'), 'timed' => !in_array($n, ['inventory', 'credit'], true)], self::REPORTS);
    }

    /** @param array<string,mixed> $q */
    public function run(TenantContext $t, string $name, array $q): Response
    {
        $t->require('reports.read');
        if (!in_array($name, self::REPORTS, true)) { throw ApiException::notFound(); }
        $t->require(self::EXTRA_PERMISSION[$name] ?? 'reports.read');
        $timed = !in_array($name, ['inventory', 'credit'], true);
        $own = match ($name) { 'inventory' => ['stock_status'], 'expenses' => ['category_id', 'payment_method', 'search'], 'credit' => ['search', 'overdue'], default => [] };
        $allowed = array_merge(['format', 'page', 'per_page'], $timed ? ['period', 'from', 'to'] : [], $name === 'credit' ? [] : ['outlet_id'], $own);
        if ($extra = array_diff(array_keys($q), $allowed)) { throw ApiException::validation([(string) reset($extra) => ['Unknown filter.']]); }
        $format = $q['format'] ?? 'json';
        if (!is_string($format) || !in_array($format, ['json', 'csv'], true)) { throw ApiException::validation(['format' => ['Must be json or csv.']]); }
        if ($format === 'csv') { $t->requireFeature('exportReports'); }
        $outlet = $this->outlet($t, $q);
        $range = null; $tz = null;
        if ($timed) {
            $period = isset($q['period']) ? (is_string($q['period']) && in_array($q['period'], [...PeriodRange::KEYS, 'custom'], true) ? $q['period'] : throw ApiException::validation(['period' => ['Must be one of: ' . implode(', ', PeriodRange::KEYS) . ', custom.']])) : null;
            [$range, $tz] = $this->profit->range($t, $period, $q['from'] ?? null, $q['to'] ?? null);
        }
        $pg = Pager::fromQuery($q, 100);
        try {
            $r = match ($name) {
                'sales' => $this->salesReport($t, $range, $tz, $outlet),
                'profit' => $this->profitReport($t, $range, $outlet),
                'inventory' => $this->inventoryReport($t, $outlet, $q),
                'expenses' => $this->expensesReport($t, $range, $outlet, $q),
                'credit' => $this->creditReport($t, $q),
                'purchases' => $this->purchasesReport($t, $range, $tz, $outlet),
                'outlets' => $this->outletsReport($t, $range),
                'products' => $this->productsReport($t, $range, $outlet),
            };
        } catch (\OverflowException) {
            throw new ApiException('range_too_large', 'The amounts in that report are too large to add up. Choose a shorter period.', 413);
        }
        $period = $range === null ? null : ['from' => $range['fromDate'], 'to' => (new \DateTimeImmutable($range['toDate']))->modify('-1 day')->format('Y-m-d'), 'timezone' => $tz->getName()];
        if ($format === 'csv') {
            $out = Csv::line(array_column($r['columns'], 0));
            foreach ($r['rows'] as $row) {
                $out .= Csv::line(array_map(fn ($c) => ($c[1] ?? false) ? Csv::safeText((string) ($row[$c[0]] ?? '')) : $row[$c[0]] ?? '', $r['columns']));
            }
            $suffix = $period === null ? gmdate('Ymd') : $period['from'] . '_' . $period['to'];
            return Response::csv("\xEF\xBB\xBF" . $out, "$name-$suffix.csv");
        }
        $total = count($r['rows']);
        return Response::ok(array_slice($r['rows'], $pg->offset(), $pg->perPage), $pg->meta($total) + ['report' => $name, 'period' => $period, 'columns' => array_column($r['columns'], 0), 'summary' => $r['summary']]);
    }

    // ================================================================================================ reports
    /** @return array{columns:list<array{0:string,1?:bool}>,rows:list<array<string,mixed>>,summary:array<string,mixed>} */
    private function salesReport(TenantContext $t, array $range, \DateTimeZone $tz, ?string $outlet): array
    {
        $rows = $this->reports->salesReport($t->businessId, Clock::sql($range['from']), Clock::sql($range['to']), $outlet, $t->outletIds);
        $this->cap($rows);
        $count = 0; $billed = $tax = $disc = $paid = $voided = Money::zero(); $voidedN = 0; $by = [];
        $out = [];
        foreach ($rows as $s) {
            $void = $s['payment_status'] === 'voided';
            if ($void) { $voidedN++; $voided = $voided->add(Money::parse($s['total_amount'])); }
            else {
                $count++; $billed = $billed->add(Money::parse($s['total_amount'])); $tax = $tax->add(Money::parse($s['tax_amount'])); $disc = $disc->add(Money::parse($s['discount_amount'])); $paid = $paid->add(Money::parse($s['amount_paid']));
                $m = (string) $s['payment_method'];
                $by[$m] ??= ['count' => 0, 'total' => Money::zero()];
                $by[$m]['count']++; $by[$m]['total'] = $by[$m]['total']->add(Money::parse($s['total_amount']));
            }
            $out[] = ['receipt' => $s['receipt_number'], 'date' => $this->local($s['created_at'], $tz), 'customer' => $s['customer_name'], 'outlet' => $s['outlet_name'], 'cashier' => $s['cashier_name'],
                'subtotal' => $s['subtotal'], 'discount' => $s['discount_amount'], 'tax' => $s['tax_amount'], 'total' => $s['total_amount'], 'paid_at_till' => $s['amount_paid'],
                'payment_method' => $s['payment_method'], 'status' => $s['payment_status'], 'credit' => $s['is_credit'] ? 'yes' : 'no'];
        }
        ksort($by);
        return ['columns' => [['receipt', true], ['date'], ['customer', true], ['outlet', true], ['cashier', true], ['subtotal'], ['discount'], ['tax'], ['total'], ['paid_at_till'], ['payment_method'], ['status'], ['credit']], 'rows' => $out,
            'summary' => ['sales' => $count, 'billed' => $billed->toString(), 'discount' => $disc->toString(), 'tax' => $tax->toString(), 'paid_at_till' => $paid->toString(),
                'by_payment_method' => array_map(fn ($m, $v) => ['method' => $m, 'count' => $v['count'], 'total' => $v['total']->toString()], array_keys($by), $by),
                'voided_sales' => $voidedN, 'voided_total' => $voided->toString(), 'note' => 'Voided sales are listed but excluded from every total.']];
    }

    private function profitReport(TenantContext $t, array $range, ?string $outlet): array
    {
        $r = $this->profit->compute($t, $range, $outlet);
        $s = $r['summary'];
        $unknown = count($r['bySale']) > 0 && $s['salesCounted'] === 0;
        $expenses = Money::parse($this->reports->expensesTotal($t->businessId, $range['fromDate'], $range['toDate'], $outlet, $t->outletIds));
        $profit = $unknown ? null : $s['profit'];
        $rows = array_map(fn ($x) => ['product' => $x['name'], 'quantity' => $x['qty'], 'sold' => $x['sold']->toString(), 'cost' => $x['cost']->toString(), 'profit' => $x['profit']->toString(), 'margin_percent' => $x['margin'], 'cost_missing' => $x['costMissing'] ? 'yes' : 'no'], $r['byProduct']);
        return ['columns' => [['product', true], ['quantity'], ['sold'], ['cost'], ['profit'], ['margin_percent'], ['cost_missing']], 'rows' => $rows,
            'summary' => ['sold' => $s['sold']->toString(), 'cost' => $s['cost']->toString(), 'profit' => $profit?->toString(), 'margin_percent' => $unknown ? null : $s['margin'], 'expenses' => $expenses->toString(),
                'net_profit' => $profit === null ? null : $profit->sub($expenses)->toString(), 'sales_counted' => $s['salesCounted'], 'sales_without_items' => $s['salesWithoutItems'], 'amount_without_items' => $s['amountWithoutItems']->toString(),
                'items_missing_cost' => $s['itemsMissingCost']]];
    }

    private function inventoryReport(TenantContext $t, ?string $outlet, array $q): array
    {
        $want = null;
        if (isset($q['stock_status'])) { $want = is_string($q['stock_status']) && in_array($q['stock_status'], ['low', 'out', 'ok'], true) ? $q['stock_status'] : throw ApiException::validation(['stock_status' => ['Must be low, out or ok.']]); }
        $scope = $outlet !== null ? [$outlet] : $t->outletIds;
        $rows = []; $cost = $retail = Money::zero(); $units = 0; $low = $out = 0;
        foreach ($this->catalog->exportRows($t->businessId, $scope) as $p) {
            $service = $p['item_type'] === 'service';
            $stock = $service ? 0 : max(0, (int) $p['stock']);
            $threshold = (int) $p['reorder_level'];
            $status = $service ? 'n/a' : ($stock <= 0 ? 'out' : ($stock <= $threshold ? 'low' : 'ok'));
            $vc = $service ? Money::zero() : Money::parse($p['cost_price'])->mulInt($stock);
            $vr = $service ? Money::zero() : Money::parse($p['selling_price'])->mulInt($stock);
            if ($p['is_active'] && !$service) { $cost = $cost->add($vc); $retail = $retail->add($vr); $units += $stock; if ($status === 'low') { $low++; } elseif ($status === 'out') { $out++; } }
            if ($want !== null && $status !== $want) { continue; }
            $rows[] = ['name' => $p['name'], 'sku' => $p['sku'], 'barcode' => $p['barcode'], 'category' => $p['category'], 'type' => $p['item_type'], 'active' => $p['is_active'] ? 'yes' : 'no', 'cost_price' => $p['cost_price'], 'selling_price' => $p['selling_price'],
                'stock' => $service ? null : $stock, 'reorder_level' => $service ? null : $threshold, 'status' => $status, 'value_at_cost' => $vc->toString(), 'value_at_retail' => $vr->toString()];
        }
        return ['columns' => [['name', true], ['sku', true], ['barcode', true], ['category', true], ['type'], ['active'], ['cost_price'], ['selling_price'], ['stock'], ['reorder_level'], ['status'], ['value_at_cost'], ['value_at_retail']], 'rows' => $rows,
            'summary' => ['value_at_cost' => $cost->toString(), 'value_at_retail' => $retail->toString(), 'units' => $units, 'low_stock' => $low, 'out_of_stock' => $out,
                'note' => 'Totals cover active stocked items, like the dashboard. Low stock = 0 < stock <= reorder level.']];
    }

    private function expensesReport(TenantContext $t, array $range, ?string $outlet, array $q): array
    {
        $f = ['from' => $range['fromDate'], 'to' => (new \DateTimeImmutable($range['toDate']))->modify('-1 day')->format('Y-m-d'), 'per_page' => '200'];
        foreach (['category_id', 'payment_method', 'search'] as $k) { if (isset($q[$k])) { $f[$k] = $q[$k]; } }
        if ($outlet !== null) { $f['outlet_id'] = $outlet; }
        $rows = []; $summary = [];
        for ($page = 1; ; $page++) {
            $x = $this->expenses->list($t, $f + ['page' => (string) $page]);
            $summary = $x['meta']['summary'];
            foreach ($x['rows'] as $e) {
                $rows[] = ['date' => $e['expense_date'], 'description' => $e['description'], 'category' => $e['category_name'], 'amount' => $e['amount'], 'payment_method' => $e['payment_method'], 'payment_status' => $e['payment_status'], 'reference' => $e['payment_reference'], 'notes' => $e['notes']];
            }
            $this->cap($rows);
            if ($page * 200 >= $x['meta']['total']) { break; }
        }
        return ['columns' => [['date'], ['description', true], ['category', true], ['amount'], ['payment_method'], ['payment_status'], ['reference', true], ['notes', true]], 'rows' => $rows, 'summary' => $summary];
    }

    private function creditReport(TenantContext $t, array $q): array
    {
        $f = ['per_page' => '200'];
        foreach (['search', 'overdue'] as $k) { if (isset($q[$k])) { $f[$k] = $q[$k]; } }
        $rows = []; $summary = [];
        for ($page = 1; ; $page++) {
            $x = $this->credit->list($t, $f + ['page' => (string) $page]);
            $summary = $x['meta']['summary'];
            foreach ($x['rows'] as $c) {
                $rows[] = ['customer' => $c['name'], 'phone' => $c['phone'], 'open_sales' => count($c['sales']), 'total_owed' => $c['total_owed'], 'oldest_debt_date' => $c['oldest_debt_date'], 'oldest_days' => $c['oldest_days'], 'overdue' => $c['is_overdue'] ? 'yes' : 'no'];
            }
            $this->cap($rows);
            if ($page * 200 >= $x['meta']['total']) { break; }
        }
        return ['columns' => [['customer', true], ['phone', true], ['open_sales'], ['total_owed'], ['oldest_debt_date'], ['oldest_days'], ['overdue']], 'rows' => $rows, 'summary' => $summary];
    }

    private function purchasesReport(TenantContext $t, array $range, \DateTimeZone $tz, ?string $outlet): array
    {
        $rows = $this->reports->purchasesReport($t->businessId, Clock::sql($range['from']), Clock::sql($range['to']), $outlet, $t->outletIds);
        $this->cap($rows);
        $by = []; $ordered = $received = Money::zero(); $out = [];
        foreach ($rows as $o) {
            $by[$o['status']] ??= ['count' => 0, 'total' => Money::zero(), 'received' => Money::zero()];
            $by[$o['status']]['count']++; $by[$o['status']]['total'] = $by[$o['status']]['total']->add(Money::parse($o['total_cost'])); $by[$o['status']]['received'] = $by[$o['status']]['received']->add(Money::parse((string) $o['received_value']));
            if (!in_array($o['status'], ['draft', 'cancelled'], true)) { $ordered = $ordered->add(Money::parse($o['total_cost'])); $received = $received->add(Money::parse((string) $o['received_value'])); }
            $out[] = ['order_number' => $o['order_number'], 'date' => $this->local($o['created_at'], $tz), 'supplier' => $o['supplier_name'], 'outlet' => $o['outlet_name'], 'status' => $o['status'],
                'ordered_value' => $o['total_cost'], 'received_value' => Money::parse((string) $o['received_value'])->toString(), 'units_ordered' => (int) $o['units_ordered'], 'units_received' => (int) $o['units_received']];
        }
        ksort($by);
        return ['columns' => [['order_number'], ['date'], ['supplier', true], ['outlet', true], ['status'], ['ordered_value'], ['received_value'], ['units_ordered'], ['units_received']], 'rows' => $out,
            'summary' => ['orders' => count($out), 'ordered_value' => $ordered->toString(), 'received_value' => $received->toString(), 'outstanding_value' => $ordered->sub($received)->toString(),
                'by_status' => array_map(fn ($s, $v) => ['status' => $s, 'count' => $v['count'], 'ordered_value' => $v['total']->toString(), 'received_value' => $v['received']->toString()], array_keys($by), $by),
                'note' => 'Totals exclude drafts and cancelled orders. Outstanding includes orders that were closed short.']];
    }

    private function outletsReport(TenantContext $t, array $range): array
    {
        $rows = []; $sumExp = Money::zero();
        foreach ($this->reports->outlets($t->businessId, $t->outletIds) as $l) {
            $r = $this->profit->compute($t, $range, $l['id']);
            $s = $r['summary'];
            $unknown = count($r['bySale']) > 0 && $s['salesCounted'] === 0;
            $exp = Money::parse($this->reports->expensesTotal($t->businessId, $range['fromDate'], $range['toDate'], $l['id'], $t->outletIds));
            $sumExp = $sumExp->add($exp);
            $profit = $unknown ? null : $s['profit'];
            $rows[] = ['outlet_id' => $l['id'], 'outlet' => $l['name'], 'sales' => count($r['bySale']), 'sold' => $s['sold']->toString(), 'cost' => $s['cost']->toString(), 'profit' => $profit?->toString(), 'margin_percent' => $unknown ? null : $s['margin'],
                'expenses' => $exp->toString(), 'net_profit' => $profit === null ? null : $profit->sub($exp)->toString()];
        }
        $all = $this->profit->compute($t, $range, null);
        $total = Money::parse($this->reports->expensesTotal($t->businessId, $range['fromDate'], $range['toDate'], null, $t->outletIds));
        $unknown = count($all['bySale']) > 0 && $all['summary']['salesCounted'] === 0;
        $summary = ['sales' => count($all['bySale']), 'sold' => $all['summary']['sold']->toString(), 'profit' => $unknown ? null : $all['summary']['profit']->toString(), 'expenses' => $total->toString()];
        if ($t->outletIds === null) { $summary['unallocated_expenses'] = $total->sub($sumExp)->toString(); $summary['note'] = 'Unallocated expenses are not tied to an outlet, so they appear only in the totals.'; }
        return ['columns' => [['outlet_id'], ['outlet', true], ['sales'], ['sold'], ['cost'], ['profit'], ['margin_percent'], ['expenses'], ['net_profit']], 'rows' => $rows, 'summary' => $summary];
    }

    private function productsReport(TenantContext $t, array $range, ?string $outlet): array
    {
        $top = $this->reports->topProducts($t->businessId, Clock::sql($range['from']), Clock::sql($range['to']), $outlet, $t->outletIds, 5000);
        $total = Money::zero();
        foreach ($top as $p) { $total = $total->add(Money::parse((string) $p['revenue'])); }
        $rows = array_map(function ($p) use ($total) {
            $rev = Money::parse((string) $p['revenue']);
            return ['product' => $p['name'], 'quantity' => (int) $p['qty'], 'revenue' => $rev->toString(), 'share_percent' => $total->isZero() ? '0.0' : number_format($rev->minor * 100 / $total->minor, 1, '.', '')];
        }, $top);
        return ['columns' => [['product', true], ['quantity'], ['revenue'], ['share_percent']], 'rows' => $rows,
            'summary' => ['products' => count($rows), 'units' => array_sum(array_column($rows, 'quantity')), 'revenue' => $total->toString(), 'note' => 'Line totals of non-voided sales, grouped by product (two products with one name stay separate).']];
    }

    // ================================================================================================ helpers
    /** @param array<array-key,mixed> $rows */
    private function cap(array $rows): void
    {
        if (count($rows) > ReportRepository::MAX_REPORT_ROWS) { throw new ApiException('range_too_large', 'That report has too many rows. Choose a shorter period.', 413); }
    }

    private function local(string $utc, \DateTimeZone $tz): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $q */
    private function outlet(TenantContext $t, array $q): ?string
    {
        if (!isset($q['outlet_id'])) { return null; }
        if (!is_string($q['outlet_id']) || !Uuid::isValid($q['outlet_id'])) { throw ApiException::validation(['outlet_id' => ['Must be a valid id.']]); }
        if (!$t->canAccessOutlet($q['outlet_id'])) { throw ApiException::notFound('Outlet not found.'); }
        return $q['outlet_id'];
    }
}
