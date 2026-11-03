<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Clock;
use LeenTrack\Core\Uuid;

final class ReportTest extends ApiTestCase
{
    private function shop(string $email = 'owner@example.com'): array
    {
        $o = $this->signup($email);
        $this->setPlan($o['business_id'], 'growth');
        return $o;
    }

    private function main(array $o): string
    {
        return (string) $this->pdo->query("SELECT id FROM locations WHERE business_id='{$o['business_id']}' AND is_default=1")->fetchColumn();
    }

    private function product(array $o, string $name, string $price, string $cost, int $stock = 100, string $type = 'product'): array
    {
        [$s, $j] = $this->call('POST', '/products', ['name' => $name, 'selling_price' => $price, 'cost_price' => $cost, 'stock_quantity' => $stock, 'item_type' => $type], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    private function sell(array $o, array $lines, array $over = [], string $at = '2026-03-10 10:00:00.000'): array
    {
        $items = array_map(fn ($l) => ['product_id' => $l[0]['id'], 'quantity' => $l[1]], $lines);
        [$s, $j] = $this->call('POST', '/sales', $over + ['client_operation_id' => Uuid::v4(), 'items' => $items, 'payment' => ['mode' => 'full', 'method' => 'cash', 'amount' => '1000000']], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        $this->pdo->prepare('UPDATE sales SET created_at = ? WHERE id = ?')->execute([$at, $j['data']['id']]);
        return $j['data'];
    }

    private function expense(array $o, string $amount, string $date, array $extra = []): array
    {
        [$s, $j] = $this->call('POST', '/expenses', $extra + ['description' => 'Exp ' . bin2hex(random_bytes(2)), 'amount' => $amount, 'expense_date' => $date], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    private function rep(array $o, string $name, string $qs = '', ?string $token = null): array
    {
        return $this->call('GET', "/reports/$name" . $qs, null, $token ?? $o['token'], $o['business_id']);
    }

    private function csv(array $o, string $name, string $qs = ''): array
    {
        $req = \LeenTrack\Core\Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/api/v1/reports/$name?format=csv" . $qs, 'REMOTE_ADDR' => $this->ip, 'HTTP_AUTHORIZATION' => "Bearer {$o['token']}", 'HTTP_X_BUSINESS_ID' => $o['business_id']], '');
        $res = $this->kernel->handle($req);
        $body = $res->body;
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'UTF-8 BOM so Excel reads it correctly');
        $rows = \LeenTrack\Core\Csv::parse(substr($body, 3));
        return [$res->status, $rows, $res];
    }

    /** a business with a spread of data used by several tests */
    private function seed(): array
    {
        $o = $this->shop();
        Clock::freeze(new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC')));
        $main = $this->main($o);
        [, $loc] = $this->call('POST', '/locations', ['name' => 'Branch'], $o['token'], $o['business_id']);
        $br = $loc['data']['id'];
        $A = $this->product($o, 'Rice', '100.00', '60.00'); $B = $this->product($o, 'Beans', '50.00', '30.00');
        $S1 = $this->product($o, 'Soap', '20.00', '8.00', 10); $S2 = $this->product($o, 'Soap', '30.00', '12.00', 10);
        $this->call('POST', '/stock/adjustments', ['product_id' => $A['id'], 'location_id' => $br, 'delta' => 40, 'reason' => 'found'], $o['token'], $o['business_id']);
        $cust = $this->call('POST', '/customers', ['name' => '=HYPERLINK("http://x")'], $o['token'], $o['business_id'])[1]['data'];
        $c2 = $this->call('POST', '/customers', ['name' => 'Smith, "Bob"'], $o['token'], $o['business_id'])[1]['data'];
        $s1 = $this->sell($o, [[$A, 2], [$B, 1]], ['discount' => ['manual' => '10'], 'customer_id' => $c2['id']], '2026-03-02 09:00:00.000');
        $s2 = $this->sell($o, [[$A, 1]], ['customer_id' => $cust['id'], 'payment' => ['mode' => 'credit', 'amount' => '0']], '2026-03-12 08:00:00.000');
        $s3 = $this->sell($o, [[$S1, 1], [$S2, 1], [$B, 2]], [], '2026-03-01 23:30:00.000');
        $s4 = $this->sell($o, [[$A, 3]], ['outlet_id' => $br], '2026-03-14 10:00:00.000');
        $s5 = $this->sell($o, [[$A, 5]], [], '2026-03-03 10:00:00.000');
        $this->call('POST', "/sales/{$s5['id']}/void", ['reason' => 'mistake'], $o['token'], $o['business_id']);
        $this->expense($o, '40', '2026-03-05', ['category_id' => $this->call('POST', '/expense-categories', ['name' => 'Rent'], $o['token'], $o['business_id'])[1]['data']['id'], 'description' => '=SUM(A1)']);
        $this->expense($o, '25', '2026-03-06', ['outlet_id' => $br]);
        $gone = $this->expense($o, '999', '2026-03-07');
        $this->call('DELETE', "/expenses/{$gone['id']}", null, $o['token'], $o['business_id']);
        $this->expense($o, '500', '2026-02-20');
        $this->expense($o, '77', '2026-04-01');                                               // first day AFTER the March range
        return compact('o', 'main', 'br', 'A', 'B', 'S1', 'S2', 'cust', 'c2', 's1', 's2', 's3', 's4', 's5');
    }

    // ================================================================================================ access
    public function testPermissionsPlanTenantsAndInputValidation(): void
    {
        $a = $this->shop('a@example.com'); $b = $this->shop('b@example.com');
        $this->assertSame(8, count($this->call('GET', '/reports', null, $a['token'], $a['business_id'])[1]['data']));
        $cashier = $this->addStaff($a, 'cash@example.com', 'cashier');
        $this->assertSame(403, $this->call('GET', '/reports', null, $cashier['token'], $a['business_id'])[0]);
        foreach (['sales', 'profit', 'inventory', 'expenses', 'credit', 'purchases', 'outlets', 'products'] as $n) {
            $this->assertSame(403, $this->rep($a, $n, '', $cashier['token'])[0], "cashier $n");
            $this->assertSame(200, $this->rep($a, $n)[0], "owner $n");
        }
        $this->assertSame(404, $this->rep($a, 'nope')[0]);
        $this->assertSame(404, $this->rep($a, '..%2Fx')[0]);
        foreach (['?bogus=1', '?format=xml', '?period=decade', '?from=2026-03-01', '?period=week&from=2026-03-01&to=2026-03-02', '?outlet_id=zz', '?per_page=0', '?stock_status=x'] as $bad) {
            $this->assertSame(422, $this->rep($a, 'sales', $bad)[0], $bad);
        }
        $this->assertSame(422, $this->rep($a, 'inventory', '?period=month')[0], 'inventory has no period');
        $this->assertSame(422, $this->rep($a, 'credit', '?outlet_id=' . $this->main($a))[0]);
        $this->assertSame(422, $this->rep($a, 'inventory', '?stock_status=x')[0]);
        $this->assertSame(422, $this->rep($a, 'credit', '?overdue=maybe')[0]);
        // free plan: JSON works, CSV is a paid feature (like the source)
        $f = $this->signup('free@example.com');
        $this->assertSame(200, $this->rep($f, 'sales')[0]);
        [$s, $j] = $this->rep($f, 'sales', '?format=csv');
        $this->assertSame([402, 'feature_not_in_plan'], [$s, $j['error']['code']]);
        // another business's outlet id is not usable
        $this->assertSame(['sales' => 0], ['sales' => $this->rep($b, 'sales', '?outlet_id=' . $this->main($a))[1]['meta']['summary']['sales']], "B sees none of A's sales");
    }

    // ================================================================================================ sales
    public function testSalesReportMatchesIndependentSqlAndExportsSafeCsv(): void
    {
        $d = $this->seed(); $o = $d['o'];
        [$s, $j] = $this->rep($o, 'sales', '?from=2026-03-01&to=2026-03-31');
        $this->assertSame(200, $s, json_encode($j));
        $sum = $j['meta']['summary'];
        $q = fn (string $sql) => $this->pdo->query($sql)->fetchColumn();
        $this->assertSame([4, 5], [$sum['sales'], $j['meta']['total']], 'four counted + one voided are listed');
        $this->assertSame(number_format((float) $q("SELECT SUM(total_amount) FROM sales WHERE payment_status IN ('completed','pending')"), 2, '.', ''), $sum['billed']);
        $this->assertSame(number_format((float) $q("SELECT SUM(tax_amount) FROM sales WHERE payment_status IN ('completed','pending')"), 2, '.', ''), $sum['tax']);
        $this->assertSame([1, number_format((float) $q("SELECT total_amount FROM sales WHERE payment_status='voided'"), 2, '.', '')], [$sum['voided_sales'], $sum['voided_total']]);
        $this->assertEqualsCanonicalizing(['cash' => 3, 'credit' => 1], array_column($sum['by_payment_method'], 'count', 'method') + [], 'by method');
        $this->assertSame($sum['billed'], number_format(array_sum(array_map(fn ($m) => (float) $m['total'], $sum['by_payment_method'])), 2, '.', ''));
        // 23:30 UTC on the 1st is 00:30 on the 2nd in Lagos, and the row shows local time
        $row = array_values(array_filter($j['data'], fn ($r) => $r['total'] === '150.00'))[0];
        $this->assertSame('2026-03-02 00:30:00', $row['date']);
        $this->assertSame('Main', $row['outlet']);
        $this->assertSame(['2026-03-01', '2026-03-31'], [$j['meta']['period']['from'], $j['meta']['period']['to']]);
        // paging only affects JSON
        [, $p] = $this->rep($o, 'sales', '?from=2026-03-01&to=2026-03-31&per_page=2&page=3');
        $this->assertSame([1, 5], [count($p['data']), $p['meta']['total']]);
        // CSV: the whole report, escaped, injection-safe
        [$s, $rows, $res] = $this->csv($o, 'sales', '&from=2026-03-01&to=2026-03-31');
        $this->assertSame(200, $s);
        $this->assertStringContainsString('attachment; filename="sales-2026-03-01_2026-03-31.csv"', $res->headers['Content-Disposition']);
        $this->assertSame(6, count($rows), 'header + all 5 rows regardless of paging');
        $this->assertSame(['receipt', 'date', 'customer', 'outlet', 'cashier', 'subtotal', 'discount', 'tax', 'total', 'paid_at_till', 'payment_method', 'status', 'credit'], $rows[0]);
        $byCust = array_column(array_slice($rows, 1), null, 2);
        $this->assertArrayHasKey("'=HYPERLINK(\"http://x\")", $byCust, 'a customer named like a formula cannot execute in Excel');
        $this->assertArrayHasKey('Smith, "Bob"', $byCust, 'commas and quotes survive');
        $this->assertSame('voided', array_values(array_filter($rows, fn ($r) => $r[11] === 'voided'))[0][11]);
        // range filter and outlet filter
        $this->assertSame(1, $this->rep($o, 'sales', "?from=2026-03-14&to=2026-03-14")[1]['meta']['total']);
        $this->assertSame(1, $this->rep($o, 'sales', "?from=2026-03-01&to=2026-03-31&outlet_id={$d['br']}")[1]['meta']['total']);
        Clock::freeze(null);
    }

    // ================================================================================================ profit / products / outlets
    public function testProfitProductsAndOutletsReportsAgreeWithProfitEndpoint(): void
    {
        $d = $this->seed(); $o = $d['o'];
        $range = '?from=2026-03-01&to=2026-03-31';
        $profit = $this->call('GET', '/profit' . $range, null, $o['token'], $o['business_id'])[1]['data'];
        [$s, $j] = $this->rep($o, 'profit', $range);
        $this->assertSame(200, $s, json_encode($j));
        $sm = $j['meta']['summary'];
        $this->assertSame([$profit['summary']['sold'], $profit['summary']['cost'], $profit['summary']['profit'], $profit['expenses'], $profit['net_profit']], [$sm['sold'], $sm['cost'], $sm['profit'], $sm['expenses'], $sm['net_profit']], 'the report IS the profit page');
        $this->assertSame(array_column($profit['by_product'], 'name'), array_column($this->rep($o, 'profit', $range . '&per_page=200')[1]['data'], 'product'));
        $this->assertSame('65.00', $sm['expenses']);
        // products: grouped by product id (two "Soap"s), voided sale excluded
        [, $pr] = $this->rep($o, 'products', $range);
        $names = array_column($pr['data'], 'product');
        $this->assertSame(2, count(array_keys($names, 'Soap', true)), 'two different products named Soap stay separate');
        $this->assertSame(['Rice' => 6, 'Beans' => 3, 'Soap' => 2], array_reduce($pr['data'], function ($c, $r) { $c[$r['product']] = ($c[$r['product']] ?? 0) + $r['quantity']; return $c; }, []));
        $this->assertSame(number_format(array_sum(array_map(fn ($r) => (float) $r['revenue'], $pr['data'])), 2, '.', ''), $pr['meta']['summary']['revenue']);
        $this->assertEqualsWithDelta(100.0, array_sum(array_map(fn ($r) => (float) $r['share_percent'], $pr['data'])), 0.2);
        // outlets: reconcile with the business totals
        [, $ou] = $this->rep($o, 'outlets', $range);
        $rows = array_column($ou['data'], null, 'outlet');
        $this->assertSame(['Main', 'Branch'], array_column($ou['data'], 'outlet'));
        $this->assertSame('300.00', $rows['Branch']['sold'], 'branch sold 3 × 100');
        $this->assertSame('25.00', $rows['Branch']['expenses']);
        $this->assertSame(number_format((float) $rows['Main']['sold'] + (float) $rows['Branch']['sold'], 2, '.', ''), $ou['meta']['summary']['sold'], 'every sale belongs to an outlet, so outlets add up to the total');
        $this->assertSame('40.00', $ou['meta']['summary']['unallocated_expenses'], 'the 40 rent has no outlet: shown, not lost');
        $this->assertSame(number_format((float) $rows['Main']['expenses'] + (float) $rows['Branch']['expenses'] + 40, 2, '.', ''), $ou['meta']['summary']['expenses']);
        [$s, $csv] = $this->csv($o, 'profit', '&from=2026-03-01&to=2026-03-31');
        $this->assertSame([200, 'product', count($profit['by_product']) + 1], [$s, $csv[0][0], count($csv)]);
        Clock::freeze(null);
    }

    public function testProfitIsUnknownNotZeroWhenNoSaleHasItemDetails(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'A', '100.00', '40.00');
        $s = $this->sell($o, [[$a, 1]]);
        $this->pdo->exec("DELETE FROM sale_items WHERE sale_id='{$s['id']}'");
        foreach (['profit', 'outlets'] as $n) {
            [$st, $j] = $this->rep($o, $n, '?from=2026-03-01&to=2026-03-31');
            $this->assertSame(200, $st);
            $this->assertNull($n === 'profit' ? $j['meta']['summary']['profit'] : $j['data'][0]['profit'], "$n: unknown, not a made-up 0");
        }
    }

    // ================================================================================================ inventory
    public function testInventoryReportMatchesValuationAndStatusRules(): void
    {
        $d = $this->seed(); $o = $d['o'];
        $low = $this->product($o, 'Low', '10.00', '4.00', 3); $out = $this->product($o, 'Out', '10.00', '4.00', 0);
        $this->product($o, 'Consulting', '500.00', '0', 0, 'service');
        $edge = $this->product($o, 'Edge', '10.00', '4.00', 5); $above = $this->product($o, 'Above', '10.00', '4.00', 6);       // default reorder level is 5
        $dormant = $this->product($o, 'Dormant', '10.00', '4.00', 10);
        $this->assertSame(200, $this->call('PATCH', "/products/{$dormant['id']}", ['is_active' => false], $o['token'], $o['business_id'])[0]);
        [$s, $j] = $this->rep($o, 'inventory', '?per_page=200');
        $this->assertSame(200, $s);
        $val = $this->call('GET', '/stock/valuation', null, $o['token'], $o['business_id'])[1]['data'];
        $this->assertSame([$val['cost_value'], $val['retail_value']], [$j['meta']['summary']['value_at_cost'], $j['meta']['summary']['value_at_retail']], 'identical to the valuation endpoint');
        $dash = $this->call('GET', '/dashboard', null, $o['token'], $o['business_id'])[1]['data']['inventory'];
        $this->assertSame([$dash['low_stock'], $dash['out_of_stock'], $dash['units']], [$j['meta']['summary']['low_stock'], $j['meta']['summary']['out_of_stock'], $j['meta']['summary']['units']], 'identical to the dashboard counts');
        $by = array_column($j['data'], null, 'name');
        $this->assertSame(['low', 'out', 'n/a', 'low', 'ok', 'ok'], [$by['Low']['status'], $by['Out']['status'], $by['Consulting']['status'], $by['Edge']['status'], $by['Above']['status'], $by['Dormant']['status']], 'stock equal to the reorder level is low; one above is ok');
        $this->assertSame('no', $by['Dormant']['active']);
        $this->assertNull($by['Consulting']['stock']);
        $this->assertSame(['Edge', 'Low'], array_column($this->rep($o, 'inventory', '?stock_status=low')[1]['data'], 'name'));
        $this->assertSame(['Out'], array_column($this->rep($o, 'inventory', '?stock_status=out')[1]['data'], 'name'));
        // one outlet only
        [, $b] = $this->rep($o, 'inventory', "?outlet_id={$d['br']}&per_page=200");
        $rice = array_column($b['data'], null, 'name')['Rice'];
        $this->assertSame(40 - 3, $rice['stock'], 'branch stock only: 40 found − 3 sold there');
        [$s, $csv] = $this->csv($o, 'inventory');
        $this->assertSame([200, 'name', 'sku'], [$s, $csv[0][0], $csv[0][1]]);
        $this->assertSame(count($j['data']) + 1, count($csv));
        Clock::freeze(null);
    }

    // ================================================================================================ expenses / credit / purchases
    public function testExpensesCreditAndPurchasesReportsReuseTheirModules(): void
    {
        $d = $this->seed(); $o = $d['o'];
        $range = '?from=2026-03-01&to=2026-03-31';
        $exp = $this->call('GET', '/expenses?from=2026-03-01&to=2026-03-31', null, $o['token'], $o['business_id'])[1];
        [$s, $j] = $this->rep($o, 'expenses', $range);
        $this->assertSame(200, $s);
        $this->assertSame([$exp['meta']['summary']['total'], '65.00', 2], [$j['meta']['summary']['total'], $j['meta']['summary']['total'], count($j['data'])], 'deleted expense excluded, same total as /expenses');
        $csv = $this->csv($o, 'expenses', '&from=2026-03-01&to=2026-03-31')[1];
        $this->assertContains("'=SUM(A1)", array_column($csv, 1), 'expense description that looks like a formula is neutralised');
        $this->assertSame(['Rent'], array_column($this->rep($o, 'expenses', $range . '&category_id=' . $this->pdo->query("SELECT id FROM expense_categories LIMIT 1")->fetchColumn())[1]['data'], 'category'));
        // credit
        $credit = $this->call('GET', '/credit', null, $o['token'], $o['business_id'])[1];
        [$s, $c] = $this->rep($o, 'credit');
        $this->assertSame([200, $credit['meta']['summary']['total_owed'], $credit['meta']['summary']['overdue_owed'], '100.00'], [$s, $c['meta']['summary']['total_owed'], $c['meta']['summary']['overdue_owed'], $c['data'][0]['total_owed']]);
        $this->assertSame(1, count($c['data']));
        $this->assertSame('=HYPERLINK("http://x")', $c['data'][0]['customer']);
        [, $cc] = $this->csv($o, 'credit');
        $this->assertSame("'=HYPERLINK(\"http://x\")", $cc[1][0]);
        // purchases
        $sup = $this->call('POST', '/suppliers', ['name' => 'Acme'], $o['token'], $o['business_id'])[1]['data'];
        $mk = function (array $items) use ($o, $sup) {
            $po = $this->call('POST', '/purchase-orders', ['supplier_id' => $sup['id'], 'items' => $items], $o['token'], $o['business_id'])[1]['data'];
            $this->pdo->prepare('UPDATE purchase_orders SET created_at = ? WHERE id = ?')->execute(['2026-03-10 10:00:00.000', $po['id']]);
            return $po;
        };
        $p1 = $mk([['product_id' => $d['A']['id'], 'quantity' => 10, 'unit_cost' => '50.00']]);
        $this->call('POST', "/purchase-orders/{$p1['id']}/order", null, $o['token'], $o['business_id']);
        $this->call('POST', "/purchase-orders/{$p1['id']}/receive", ['client_operation_id' => Uuid::v4(), 'items' => [['item_id' => $p1['items'][0]['id'], 'quantity' => 4]]], $o['token'], $o['business_id']);
        $p2 = $mk([['product_id' => $d['B']['id'], 'quantity' => 2, 'unit_cost' => '10.00']]);                  // stays draft
        $p3 = $mk([['product_id' => $d['B']['id'], 'quantity' => 5, 'unit_cost' => '10.00']]);
        $this->call('POST', "/purchase-orders/{$p3['id']}/cancel", null, $o['token'], $o['business_id']);
        [$s, $p] = $this->rep($o, 'purchases', $range);
        $this->assertSame(200, $s, json_encode($p));
        $sm = $p['meta']['summary'];
        $this->assertSame([3, '500.00', '200.00', '300.00'], [$sm['orders'], $sm['ordered_value'], $sm['received_value'], $sm['outstanding_value']], 'drafts and cancelled orders are not commitments');
        $this->assertEqualsCanonicalizing(['partially_received' => 1, 'draft' => 1, 'cancelled' => 1], array_column($sm['by_status'], 'count', 'status'));
        $row = array_values(array_filter($p['data'], fn ($r) => $r['status'] === 'partially_received'))[0];
        $this->assertSame(['Acme', '2026-03-10 11:00:00', 10, 4, '200.00'], [$row['supplier'], $row['date'], $row['units_ordered'], $row['units_received'], $row['received_value']]);
        $this->assertSame(0, $this->rep($o, 'purchases', '?from=2026-04-01&to=2026-04-30')[1]['meta']['total']);
        Clock::freeze(null);
    }

    // ================================================================================================ restricted + empty
    public function testOutletRestrictedManagerOnlyGetsTheirOutletEverywhere(): void
    {
        $d = $this->seed(); $o = $d['o'];
        $m = $this->addStaff($o, 'bm@example.com', 'manager');
        $this->pdo->prepare('INSERT INTO member_outlets (member_id, location_id) VALUES (?,?)')->execute([$m['member_id'], $d['br']]);
        $range = '?from=2026-03-01&to=2026-03-31';
        $this->assertSame([1, '300.00'], (fn ($j) => [$j['meta']['total'], $j['meta']['summary']['billed']])($this->rep($o, 'sales', $range, $m['token'])[1]), 'only the branch sale');
        [, $ou] = $this->rep($o, 'outlets', $range, $m['token']);
        $this->assertSame(['Branch'], array_column($ou['data'], 'outlet'));
        $this->assertArrayNotHasKey('unallocated_expenses', $ou['meta']['summary'], 'business-wide figures are not shown to an outlet-restricted manager');
        $this->assertSame(404, $this->rep($o, 'sales', $range . '&outlet_id=' . $d['main'], $m['token'])[0]);
        [, $inv] = $this->rep($o, 'inventory', '?per_page=200', $m['token']);
        $this->assertSame(37, array_column($inv['data'], null, 'name')['Rice']['stock'], 'sees branch stock only');
        Clock::freeze(null);
    }

    public function testEmptyBusinessReportsAreZerosNotErrors(): void
    {
        $o = $this->shop();
        foreach (['sales', 'profit', 'inventory', 'expenses', 'credit', 'purchases', 'outlets', 'products'] as $n) {
            [$s, $j] = $this->rep($o, $n);
            $this->assertSame([200, 0], [$s, $n === 'outlets' ? 0 : count($j['data'])], $n);
            [$cs, $rows] = $this->csv($o, $n);
            $this->assertSame([200, 1], [$cs, $n === 'outlets' ? 1 : count($rows)], "$n csv has just the header");
        }
    }
}
