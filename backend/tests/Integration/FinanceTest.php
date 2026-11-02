<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Clock;
use LeenTrack\Core\Uuid;

final class FinanceTest extends ApiTestCase
{
    private function shop(string $email = 'owner@example.com'): array
    {
        $o = $this->signup($email);
        $this->setPlan($o['business_id'], 'growth');
        return $o;
    }

    /** @return array<string,mixed> */
    private function product(array $o, string $name, string $price, string $cost, int $stock = 100, string $type = 'product'): array
    {
        [$s, $j] = $this->call('POST', '/products', ['name' => $name, 'selling_price' => $price, 'cost_price' => $cost, 'stock_quantity' => $stock, 'item_type' => $type], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    /** @param list<array{0:array,1:int}> $lines */
    private function sell(array $o, array $lines, array $over = []): array
    {
        $items = array_map(fn ($l) => ['product_id' => $l[0]['id'], 'quantity' => $l[1]], $lines);
        [$s, $j] = $this->call('POST', '/sales', $over + ['client_operation_id' => Uuid::v4(), 'items' => $items, 'payment' => ['mode' => 'full', 'method' => 'cash', 'amount' => '1000000']], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    private function at(string $saleId, string $utc): void
    {
        $this->pdo->prepare('UPDATE sales SET created_at = ? WHERE id = ?')->execute([$utc, $saleId]);
        $this->pdo->prepare('UPDATE debt_repayments SET created_at = ? WHERE sale_id = ? AND 0')->execute([$utc, $saleId]);
    }

    /** @return array<string,mixed> */
    private function expense(array $o, string $amount, string $date, array $extra = []): array
    {
        [$s, $j] = $this->call('POST', '/expenses', $extra + ['description' => 'Expense ' . bin2hex(random_bytes(2)), 'amount' => $amount, 'expense_date' => $date], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    // ================================================================================================ expenses
    public function testExpenseCrudSoftDeleteAndValidation(): void
    {
        $o = $this->shop();
        [$s, $j] = $this->call('POST', '/expenses', ['description' => '  Shop rent ', 'amount' => '15000.50', 'expense_date' => '2026-03-05', 'notes' => 'March', 'payment_method' => 'transfer'], $o['token'], $o['business_id']);
        $this->assertSame([201, 'Shop rent', '15000.50', 'bank_transfer', 'paid', 'March'], [$s, $j['data']['description'], $j['data']['amount'], $j['data']['payment_method'], $j['data']['payment_status'], $j['data']['notes']], 'notes are saved (the source form silently dropped them)');
        $id = $j['data']['id'];
        [$s, $j] = $this->call('PATCH', "/expenses/$id", ['amount' => 16000, 'payment_status' => 'pending'], $o['token'], $o['business_id']);
        $this->assertSame([200, '16000.00', 'pending', 'Shop rent'], [$s, $j['data']['amount'], $j['data']['payment_status'], $j['data']['description']]);
        foreach ([['amount' => '0'], ['amount' => '-5'], ['amount' => '1.005'], ['amount' => 'x'], ['description' => ''], ['description' => str_repeat('x', 301)], ['expense_date' => '1999-12-31'], ['expense_date' => '2999-01-01'], ['expense_date' => '2026-02-30'], ['expense_date' => '05/03/2026'],
                  ['payment_method' => 'bitcoin'], ['payment_status' => 'maybe'], ['category_id' => Uuid::v4()], ['outlet_id' => Uuid::v4()], ['receipt_image_url' => 'javascript:alert(1)'],
                  ['business_id' => 'x'], ['user_id' => 'x'], ['deleted_at' => '2020-01-01'], ['id' => 'x']] as $bad) {
            $this->assertSame(422, $this->call('PATCH', "/expenses/$id", $bad, $o['token'], $o['business_id'])[0], json_encode($bad));
        }
        $this->assertSame(422, $this->call('POST', '/expenses', ['amount' => '5', 'expense_date' => '2026-03-05'], $o['token'], $o['business_id'])[0], 'description required');
        [$s] = $this->call('DELETE', "/expenses/$id", null, $o['token'], $o['business_id']);
        $this->assertSame(200, $s);
        $this->assertSame(404, $this->call('GET', "/expenses/$id", null, $o['token'], $o['business_id'])[0]);
        $this->assertSame(404, $this->call('DELETE', "/expenses/$id", null, $o['token'], $o['business_id'])[0]);
        $this->assertSame([], $this->call('GET', '/expenses', null, $o['token'], $o['business_id'])[1]['data']);
        $row = $this->pdo->query("SELECT deleted_at, deleted_by FROM expenses WHERE id='$id'")->fetch();
        $this->assertSame($o['user_id'], $row['deleted_by'], 'the row is kept, with who removed it');
        $this->assertNotNull($row['deleted_at']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='expense.delete'")->fetchColumn());
    }

    public function testRolesAndTenantIsolation(): void
    {
        $a = $this->shop('a@example.com');
        $b = $this->shop('b@example.com');
        $e = $this->expense($a, '100', '2026-03-05');
        $cat = $this->call('POST', '/expense-categories', ['name' => 'Rent'], $a['token'], $a['business_id'])[1]['data'];
        $cashier = $this->addStaff($a, 'cash@example.com', 'cashier');
        $mgr = $this->addStaff($a, 'mgr@example.com', 'manager');
        foreach ([['GET', '/expenses'], ['POST', '/expenses'], ['GET', "/expenses/{$e['id']}"], ['PATCH', "/expenses/{$e['id']}"], ['DELETE', "/expenses/{$e['id']}"], ['GET', '/expense-categories'], ['POST', '/expense-categories'], ['POST', '/expense-categories/seed-defaults'], ['PATCH', "/expense-categories/{$cat['id']}"], ['DELETE', "/expense-categories/{$cat['id']}"], ['GET', '/profit'], ['GET', '/dashboard']] as $x) {
            $this->assertSame(403, $this->call($x[0], $x[1], $x[0] === 'GET' || $x[0] === 'DELETE' ? null : ['name' => 'x'], $cashier['token'], $a['business_id'])[0], "cashier {$x[0]} {$x[1]}");
        }
        $this->assertSame(200, $this->call('GET', '/expenses', null, $mgr['token'], $a['business_id'])[0]);
        foreach ([['GET', "/expenses/{$e['id']}"], ['PATCH', "/expenses/{$e['id']}"], ['DELETE', "/expenses/{$e['id']}"], ['PATCH', "/expense-categories/{$cat['id']}"], ['DELETE', "/expense-categories/{$cat['id']}"]] as $x) {
            $this->assertSame(404, $this->call($x[0], $x[1], $x[0] === 'PATCH' ? ['amount' => '1', 'name' => 'z'] : null, $b['token'], $b['business_id'])[0], "B {$x[0]} {$x[1]}");
        }
        $this->assertSame(422, $this->call('POST', '/expenses', ['description' => 'x', 'amount' => '1', 'expense_date' => '2026-03-05', 'category_id' => $cat['id']], $b['token'], $b['business_id'])[0], "A's category");
        [, $l, $res] = $this->call('GET', '/expenses', null, $b['token'], $b['business_id']);
        $this->assertSame([[], '0.00'], [$l['data'], $l['meta']['summary']['total']]);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM expenses WHERE deleted_at IS NULL")->fetchColumn());
    }

    public function testListFiltersSummaryAndPagination(): void
    {
        $o = $this->shop();
        $rent = $this->call('POST', '/expense-categories', ['name' => 'Rent'], $o['token'], $o['business_id'])[1]['data'];
        $this->expense($o, '1000', '2026-03-01', ['description' => 'March rent', 'category_id' => $rent['id']]);
        $this->expense($o, '250.50', '2026-03-31', ['description' => '50% promo flyers', 'payment_method' => 'card']);
        $this->expense($o, '75', '2026-04-01', ['description' => 'April snacks', 'category_id' => $rent['id']]);
        $q = fn (string $qs) => $this->call('GET', '/expenses' . $qs, null, $o['token'], $o['business_id']);
        $desc = fn (array $j) => array_column($j['data'], 'description');
        $this->assertSame(['April snacks', '50% promo flyers', 'March rent'], $desc($q('')[1]), 'newest date first');
        [, $mar] = $q('?month=2026-03');
        $this->assertSame([['50% promo flyers', 'March rent'], 2, '1250.50'], [$desc($mar), $mar['meta']['summary']['count'], $mar['meta']['summary']['total']], 'month boundaries inclusive');
        $this->assertSame(['Rent', 'Uncategorised'], array_column($mar['meta']['summary']['by_category'], 'name'));
        $this->assertSame(['1000.00', '250.50'], array_column($mar['meta']['summary']['by_category'], 'total'));
        $this->assertSame(['April snacks'], $desc($q('?from=2026-04-01&to=2026-04-01')[1]), 'to is inclusive');
        $this->assertSame(['March rent', 'April snacks'] == $desc($q("?category_id={$rent['id']}")[1]) ? 1 : 0, 0);
        $this->assertEqualsCanonicalizing(['March rent', 'April snacks'], $desc($q("?category_id={$rent['id']}")[1]));
        $this->assertSame(['50% promo flyers'], $desc($q('?payment_method=card')[1]));
        $this->assertSame(['50% promo flyers'], $desc($q('?search=' . rawurlencode('%'))[1]), 'wildcards are literal');
        [, $pg] = $q('?per_page=2&page=2');
        $this->assertSame([['March rent'], 3, '1325.50'], [$desc($pg), $pg['meta']['total'], $pg['meta']['summary']['total']], 'summary covers all pages');
        foreach (['?month=2026-13', '?month=March', '?month=2026-03&from=2026-03-01', '?from=yesterday', '?payment_method=gold', '?category_id=zz', '?bogus=1', '?per_page=0'] as $bad) { $this->assertSame(422, $q($bad)[0], $bad); }
    }

    public function testCategoriesDuplicateInUseSeedAndSoftDeletedHistory(): void
    {
        $o = $this->shop();
        $p = '/expense-categories';
        [$s, $c] = $this->call('POST', $p, ['name' => 'Rent', 'description' => 'premises'], $o['token'], $o['business_id']);
        $this->assertSame(201, $s);
        $this->assertSame(409, $this->call('POST', $p, ['name' => 'rent'], $o['token'], $o['business_id'])[0], 'case-insensitive duplicate');
        $this->assertSame(200, $this->call('PATCH', "$p/{$c['data']['id']}", ['name' => 'Premises'], $o['token'], $o['business_id'])[0]);
        $e = $this->expense($o, '10', '2026-03-05', ['category_id' => $c['data']['id']]);
        [$s, $j] = $this->call('DELETE', "$p/{$c['data']['id']}", null, $o['token'], $o['business_id']);
        $this->assertSame([409, 'category_in_use'], [$s, $j['error']['code']], 'refused by the server, not just the browser');
        [, $list] = $this->call('GET', $p, null, $o['token'], $o['business_id']);
        $this->assertSame(1, $list['data'][0]['expense_count']);
        $this->call('DELETE', '/expenses/' . $e['id'], null, $o['token'], $o['business_id']);
        $this->assertSame(200, $this->call('DELETE', "$p/{$c['data']['id']}", null, $o['token'], $o['business_id'])[0], 'only a deleted expense used it');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM expenses WHERE id='{$e['id']}'")->fetchColumn(), 'the deleted expense row still exists');
        $o2 = $this->shop('o2@example.com');
        [$s, $j] = $this->call('POST', "$p/seed-defaults", null, $o2['token'], $o2['business_id']);
        $names = array_column($j['data'], 'name');
        $this->assertSame([201, 10, true, true], [$s, count($names), in_array('Rent & Utilities', $names, true), in_array('Other', $names, true)]);
        $this->assertSame([409, 'already_has_categories'], (fn ($r) => [$r[0], $r[1]['error']['code']])($this->call('POST', "$p/seed-defaults", null, $o2['token'], $o2['business_id'])));
        $this->assertSame(422, $this->call('POST', $p, ['name' => ''], $o['token'], $o['business_id'])[0]);
    }

    public function testOutletRestrictedManagerSeesOnlyTheirOutletsExpenses(): void
    {
        $o = $this->shop();
        [, $loc] = $this->call('POST', '/locations', ['name' => 'Branch'], $o['token'], $o['business_id']);
        $branch = $loc['data']['id'];
        $hq = $this->expense($o, '500', '2026-03-05');                                     // business-wide, no outlet
        $own = $this->expense($o, '70', '2026-03-05', ['outlet_id' => $branch]);
        $main = (string) $this->pdo->query("SELECT id FROM locations WHERE business_id='{$o['business_id']}' AND is_default=1")->fetchColumn();
        $other = $this->expense($o, '30', '2026-03-05', ['outlet_id' => $main]);
        $m = $this->addStaff($o, 'bm@example.com', 'manager');
        $this->pdo->prepare('INSERT INTO member_outlets (member_id, location_id) VALUES (?,?)')->execute([$m['member_id'], $branch]);
        [, $l] = $this->call('GET', '/expenses', null, $m['token'], $o['business_id']);
        $this->assertSame([[$own['id']], '70.00'], [array_column($l['data'], 'id'), $l['meta']['summary']['total']]);
        $this->assertSame(404, $this->call('GET', "/expenses/{$hq['id']}", null, $m['token'], $o['business_id'])[0], 'business-wide expenses are not theirs');
        $this->assertSame(404, $this->call('GET', "/expenses/{$other['id']}", null, $m['token'], $o['business_id'])[0]);
        $this->assertSame(404, $this->call('GET', "/expenses?outlet_id=$main", null, $m['token'], $o['business_id'])[0]);
        [$s, $j] = $this->call('POST', '/expenses', ['description' => 'Branch tea', 'amount' => '5', 'expense_date' => '2026-03-06'], $m['token'], $o['business_id']);
        $this->assertSame([201, $branch], [$s, $j['data']['outlet_id']], 'defaults to their only outlet');
        $this->assertSame(422, $this->call('POST', '/expenses', ['description' => 'x', 'amount' => '5', 'expense_date' => '2026-03-06', 'outlet_id' => $main], $m['token'], $o['business_id'])[0]);
        $this->assertSame(404, $this->call('PATCH', "/expenses/{$other['id']}", ['amount' => '1'], $m['token'], $o['business_id'])[0]);
    }

    // ================================================================================================ profit
    public function testProfitMatchesHandCalculationAndFlagsWhatItCannotKnow(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'Rice', '100.00', '60.00');
        $b = $this->product($o, 'Beans', '50.00', '30.00');
        $svc = $this->product($o, 'Delivery', '20.00', '0', 0, 'service');
        $c = $this->product($o, 'Mystery', '10.00', '0.00');                                // no cost price set
        Clock::freeze(new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC')));
        $s1 = $this->sell($o, [[$a, 2], [$b, 1]], ['discount' => ['manual' => '10']]);        // sold 240, cost 150
        $cust = $this->call('POST', '/customers', ['name' => 'Ada'], $o['token'], $o['business_id'])[1]['data'];
        $s2 = $this->sell($o, [[$a, 1]], ['customer_id' => $cust['id'], 'payment' => ['mode' => 'credit', 'amount' => '0']]);   // credit counts as a sale: sold 100, cost 60
        $void = $this->sell($o, [[$a, 5]]);
        $this->call('POST', "/sales/{$void['id']}/void", ['reason' => 'mistake'], $o['token'], $o['business_id']);
        $legacy = $this->sell($o, [[$b, 1]]);
        $this->pdo->exec("DELETE FROM sale_items WHERE sale_id='{$legacy['id']}'");               // an old sale that has no item rows
        $this->pdo->exec("UPDATE sales SET subtotal=70, discount_amount=0, total_amount=70 WHERE id='{$legacy['id']}'");
        foreach ([$s1, $s2, $void, $legacy] as $i => $s) { $this->at($s['id'], '2026-03-1' . ($i + 1) . ' 10:00:00.000'); }
        $this->expense($o, '30', '2026-03-12');
        $this->expense($o, '20', '2026-03-31');                                                // later in March, outside the custom range below
        $gone = $this->expense($o, '999', '2026-03-12');
        $this->call('DELETE', "/expenses/{$gone['id']}", null, $o['token'], $o['business_id']);
        $this->expense($o, '500', '2026-02-27');                                              // last month
        $p = fn (string $qs) => $this->call('GET', '/profit' . $qs, null, $o['token'], $o['business_id']);
        [$s, $j] = $p('?from=2026-03-01&to=2026-03-15');
        $this->assertSame(200, $s, json_encode($j));
        $d = $j['data'];
        $this->assertSame(['340.00', '210.00', '130.00', '38.24', 2, 1, '70.00', 0], [$d['summary']['sold'], $d['summary']['cost'], $d['summary']['profit'], $d['summary']['margin'], $d['summary']['sales_counted'], $d['summary']['sales_without_items'], $d['summary']['amount_without_items'], $d['summary']['items_missing_cost']]);
        $this->assertSame(['30.00', '100.00'], [$d['expenses'], $d['net_profit']], 'net = 130 − 30; the voided sale, the deleted expense and last month are all excluded');
        $this->assertSame(['2026-03-01', '2026-03-15', 'Africa/Lagos'], [$d['period']['from'], $d['period']['to'], $d['period']['timezone']]);
        $prod = array_column($d['by_product'], null, 'name');
        // discount 10 shared 200:50 => Rice 192, Beans 48
        $this->assertSame(['292.00', '180.00', '112.00', '38.36', 3], [$prod['Rice']['sold'], $prod['Rice']['cost'], $prod['Rice']['profit'], $prod['Rice']['margin'], $prod['Rice']['quantity']]);
        $this->assertSame(['48.00', '30.00', '18.00', '37.50'], [$prod['Beans']['sold'], $prod['Beans']['cost'], $prod['Beans']['profit'], $prod['Beans']['margin']]);
        $this->assertSame(['Rice', 'Beans'], array_column($d['by_product'], 'name'), 'most profitable first');
        $this->assertSame([3, 3], [count($d['by_sale']), $j['meta']['total']]);
        $noItems = array_values(array_filter($d['by_sale'], fn ($x) => !$x['has_items']))[0];
        $this->assertSame(['70.00', '0.00', '0.00', null], [$noItems['sold'], $noItems['cost'], $noItems['profit'], $noItems['margin']], 'unknown profit is not guessed');
        // cost changes later never rewrite history
        $this->call('PATCH', '/products/' . $a['id'], ['cost_price' => '99.00'], $o['token'], $o['business_id']);
        $this->assertSame('130.00', $p('?from=2026-03-01&to=2026-03-15')[1]['data']['summary']['profit']);
        // missing costs are reported, services are not
        $m = $this->sell($o, [[$c, 3], [$svc, 1]]);
        $this->at($m['id'], '2026-03-14 10:00:00.000');
        [, $j2] = $p('?from=2026-03-14&to=2026-03-14');
        $this->assertSame([1, ['Mystery'], '50.00', '0.00'], [$j2['data']['summary']['items_missing_cost'], $j2['data']['summary']['products_missing_cost'], $j2['data']['summary']['sold'], $j2['data']['summary']['cost']], 'a product with no cost is flagged; the service is not');
        $this->assertTrue(array_values(array_filter($j2['data']['by_sale'], fn ($x) => $x['id'] === $m['id']))[0]['cost_missing']);
        // profit unknown when a period has sales but none with item details
        $this->pdo->exec("DELETE FROM sale_items WHERE sale_id='{$m['id']}'");
        [, $j3] = $p('?from=2026-03-14&to=2026-03-14');
        $this->assertSame([null, null, null, 2], [$j3['data']['summary']['profit'], $j3['data']['net_profit'], $j3['data']['summary']['margin'], $j3['data']['summary']['sales_without_items']]);
        // named periods use "now" in the business time zone
        [, $today] = $p('?period=today');
        $this->assertSame(['2026-03-15', '2026-03-15'], [$today['data']['period']['from'], $today['data']['period']['to']]);
        [, $month] = $p('?period=month');
        $this->assertSame('2026-03-01', $month['data']['period']['from']);
        [, $last] = $p('?period=lastMonth');
        $this->assertSame(['2026-02-01', '2026-02-28', '500.00'], [$last['data']['period']['from'], $last['data']['period']['to'], $last['data']['expenses']]);
    }

    public function testProfitTimeZoneBoundariesAndValidationAndPermissions(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'Item', '100.00', '40.00');
        $late = $this->sell($o, [[$a, 1]]);
        $this->at($late['id'], '2026-03-01 23:30:00.000');             // 00:30 on 2 March in Lagos
        $p = fn (string $qs) => $this->call('GET', '/profit' . $qs, null, $o['token'], $o['business_id']);
        $this->assertSame('0.00', $p('?from=2026-03-01&to=2026-03-01')[1]['data']['summary']['sold'], 'not on the 1st');
        $this->assertSame('100.00', $p('?from=2026-03-02&to=2026-03-02')[1]['data']['summary']['sold'], 'on the 2nd, local time');
        foreach (['?period=decade', '?period=custom', '?from=2026-03-01', '?to=2026-03-01', '?period=week&from=2026-03-01&to=2026-03-02', '?from=2026-03-05&to=2026-03-01', '?from=2024-01-01&to=2026-03-01', '?from=bad&to=2026-03-01', '?outlet_id=zz', '?x=1', '?per_page=0'] as $bad) {
            $this->assertSame(422, $p($bad)[0], $bad);
        }
        $cashier = $this->addStaff($o, 'c@example.com', 'cashier');
        $this->assertSame(403, $this->call('GET', '/profit', null, $cashier['token'], $o['business_id'])[0]);
        $other = $this->shop('other@example.com');
        [, $j] = $this->call('GET', '/profit?from=2026-03-01&to=2026-03-31', null, $other['token'], $other['business_id']);
        $this->assertSame(['0.00', 0], [$j['data']['summary']['sold'], $j['data']['summary']['sales_counted']], "B sees none of A's sales");
        [$fs, $fj] = $this->call('GET', '/profit?from=2026-03-01&to=2026-03-31&outlet_id=' . (string) $this->pdo->query("SELECT id FROM locations WHERE business_id='{$o['business_id']}' LIMIT 1")->fetchColumn(), null, $other['token'], $other['business_id']);
        $this->assertSame([200, '0.00'], [$fs, $fj['data']['summary']['sold']], "another business's outlet id exposes nothing");
    }

    // ================================================================================================ dashboard
    public function testDashboardMetricsAreSeparateNamedAndMatchIndependentCalculations(): void
    {
        $o = $this->shop();
        $A = $this->product($o, 'Rice', '100.00', '60.00');
        $B = $this->product($o, 'Beans', '50.00', '30.00');
        $E = $this->product($o, 'Soap', '20.00', '8.00', 10);
        $F = $this->product($o, 'Soap', '30.00', '12.00', 10);                              // a DIFFERENT product with the same name
        $C = $this->product($o, 'Low one', '10.00', '4.00', 3);                              // low stock (reorder 5)
        $D = $this->product($o, 'Empty one', '10.00', '4.00', 0);                            // out of stock
        $this->product($o, 'Service', '5.00', '0', 0, 'service');
        $cust = $this->call('POST', '/customers', ['name' => 'Ada'], $o['token'], $o['business_id'])[1]['data'];
        $s1 = $this->sell($o, [[$A, 2], [$B, 1]], ['discount' => ['manual' => '10']]);        // Mar 2 10:00 local: billed 240
        $s2 = $this->sell($o, [[$A, 1]], ['customer_id' => $cust['id'], 'payment' => ['mode' => 'credit', 'amount' => '0']]);   // today, billed 100, unpaid
        $s3 = $this->sell($o, [[$B, 2], [$E, 1], [$F, 1]]);                                    // Mar 1 23:30Z = Mar 2 local, billed 150
        $s4 = $this->sell($o, [[$A, 3]]);                                                      // Feb 20 (previous period), billed 300
        $s5 = $this->sell($o, [[$A, 5]]);
        $this->call('POST', "/sales/{$s5['id']}/void", ['reason' => 'mistake'], $o['token'], $o['business_id']);
        $s6 = $this->sell($o, [[$B, 2]], ['customer_id' => $cust['id'], 'payment' => ['mode' => 'credit', 'amount' => '20']]);    // Jan 20: old credit, owes 80
        $this->repay($o, $s2['id'], '30');
        foreach ([[$s1, '2026-03-02 09:00:00.000'], [$s2, '2026-03-15 08:00:00.000'], [$s3, '2026-03-01 23:30:00.000'], [$s4, '2026-02-20 10:00:00.000'], [$s5, '2026-03-03 10:00:00.000'], [$s6, '2026-01-20 10:00:00.000']] as [$s, $t]) { $this->at($s['id'], $t); }
        $this->pdo->exec("UPDATE debt_repayments SET created_at='2026-03-15 09:00:00.000'");
        $this->expense($o, '40', '2026-03-05');
        $this->expense($o, '25', '2026-02-20');
        Clock::freeze(new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC')));

        [$st, $j] = $this->call('GET', '/dashboard', null, $o['token'], $o['business_id']);
        $this->assertSame(200, $st, json_encode($j));
        $d = $j['data'];
        $m = $d['metrics'];
        $this->assertSame(['2026-03-01', '2026-03-15', 15], [$d['period']['from'], $d['period']['to'], $d['period']['days']], 'default = month to date in the business zone');
        // billed 240+100+150, collected = at the till 240+0+150 plus the 30 repaid, COGS 150+60+(60+8+12), gross = net sales − COGS
        $this->assertSame([3, '490.00', '420.00', '0.00', '490.00'], [$m['transactions'], $m['revenue_billed'], $m['revenue_collected'], $m['tax'], $m['net_sales']]);
        $this->assertSame(['290.00', '200.00', '40.00', '160.00'], [$m['cogs'], $m['gross_profit'], $m['expenses'], $m['net_profit']]);
        $this->assertNotSame($m['revenue_billed'], $m['gross_profit'], 'revenue is never reported as profit');
        $this->assertNotSame($m['revenue_billed'], $m['revenue_collected'], 'billed is not collected');
        // independent recomputation straight from the tables
        $sql = fn (string $q) => (string) $this->pdo->query($q)->fetchColumn();
        $this->assertSame('490.00', number_format((float) $sql("SELECT SUM(total_amount) FROM sales WHERE payment_status IN ('completed','pending') AND created_at >= '2026-02-28 23:00:00' AND created_at < '2026-03-15 23:00:00'"), 2, '.', ''));
        $this->assertSame($m['expenses'], number_format((float) $sql("SELECT SUM(amount) FROM expenses WHERE expense_date >= '2026-03-01' AND expense_date < '2026-03-16'"), 2, '.', ''));
        $this->assertSame($m['cogs'], number_format((float) $sql("SELECT SUM(i.quantity*i.unit_cost) FROM sale_items i JOIN sales s ON s.id=i.sale_id WHERE s.payment_status IN ('completed','pending') AND s.created_at >= '2026-02-28 23:00:00' AND s.created_at < '2026-03-15 23:00:00'"), 2, '.', ''));
        $this->assertSame(['300.00', '25.00', '120.00', '63.33'], [$d['previous_period']['revenue_billed'], $d['previous_period']['expenses'], $d['previous_period']['gross_profit'], $d['previous_period']['revenue_billed_growth_percent']], 'previous = the same number of days immediately before; growth = (490−300)/300');
        $this->assertSame(['2026-02-14', '2026-02-28'], [$d['previous_period']['from'], $d['previous_period']['to']]);
        $this->assertSame([1, '100.00', '40.00', '0.00'], [$d['today']['transactions'], $d['today']['revenue_billed'], $d['today']['gross_profit'], $d['today']['revenue_collected'] === '30.00' ? '0.00' : 'x'], 'today: the credit sale, with the 30 repaid today as collected');
        // credit: repayments are subtracted (the source dashboard said 100 + 80 and ignored the 30)
        $this->assertSame(['150.00', '80.00', 1, 2], [$d['credit']['outstanding'], $d['credit']['overdue'], $d['credit']['customers_owing'], $d['credit']['open_sales']]);
        [, $cl] = $this->call('GET', '/credit', null, $o['token'], $o['business_id']);
        $this->assertSame([$cl['meta']['summary']['total_owed'], $cl['meta']['summary']['overdue_owed']], [$d['credit']['outstanding'], $d['credit']['overdue']], 'dashboard credit = credit page, always');
        // inventory
        [, $val] = $this->call('GET', '/stock/valuation', null, $o['token'], $o['business_id']);
        $this->assertSame([$val['data']['cost_value'], $val['data']['retail_value']], [$d['inventory']['value_at_cost'], $d['inventory']['value_at_retail']]);
        $this->assertSame(['8682.00', '14630.00', 7, 1, 1], [$d['inventory']['value_at_cost'], $d['inventory']['value_at_retail'], $d['inventory']['active_items'], $d['inventory']['low_stock'], $d['inventory']['out_of_stock']]);
        // lists
        $this->assertSame([$s2['id'], $s1['id'], $s3['id'], $s4['id'], $s6['id']], array_column($d['recent_sales'], 'id'), 'newest first, voided sale excluded');
        $this->assertCount(5, $d['recent_sales']);
        $tp = $d['top_products'];
        $this->assertSame([['Rice', 3, '300.00'], ['Beans', 3, '150.00'], ['Soap', 1, '30.00'], ['Soap', 1, '20.00']], array_map(fn ($p) => [$p['name'], $p['quantity'], $p['revenue']], $tp), 'grouped by PRODUCT: the two "Soap"s stay separate; voided sales excluded');
        // series: local-day buckets
        $ser = array_column($d['series'], null, 'date');
        $this->assertCount(15, $d['series']);
        $this->assertSame(['0.00', '390.00', '100.00'], [$ser['2026-03-01']['revenue_billed'], $ser['2026-03-02']['revenue_billed'], $ser['2026-03-15']['revenue_billed']], '23:30 UTC on the 1st is the 2nd in Lagos (the source put it on the 1st)');
        $this->assertSame(['40.00', '0.00'], [$ser['2026-03-05']['expenses'], $ser['2026-03-06']['expenses']]);
        $this->assertSame('0.00', $ser['2026-03-03']['revenue_billed'], 'voided sale is not revenue');
    }

    /** repay helper local to this test class */
    private function repay(array $o, string $saleId, string $amount): void
    {
        [$s, $j] = $this->call('POST', "/sales/$saleId/repayments", ['client_operation_id' => Uuid::v4(), 'amount' => $amount], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
    }

    public function testDashboardRangesOutletFilterAndPermissions(): void
    {
        $o = $this->shop();
        $A = $this->product($o, 'Item', '100.00', '40.00', 100);
        [, $loc] = $this->call('POST', '/locations', ['name' => 'Branch'], $o['token'], $o['business_id']);
        $branch = $loc['data']['id'];
        $this->call('POST', '/stock/adjustments', ['product_id' => $A['id'], 'location_id' => $branch, 'delta' => 10, 'reason' => 'found'], $o['token'], $o['business_id']);
        $main = $this->sell($o, [[$A, 2]]);
        $br = $this->sell($o, [[$A, 1]], ['outlet_id' => $branch]);
        $this->at($main['id'], '2026-03-10 10:00:00.000'); $this->at($br['id'], '2026-03-10 11:00:00.000');
        $this->expense($o, '10', '2026-03-10', ['outlet_id' => $branch]);
        $this->expense($o, '90', '2026-03-10');
        Clock::freeze(new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC')));
        $q = fn (string $qs) => $this->call('GET', '/dashboard' . $qs, null, $o['token'], $o['business_id']);
        [, $all] = $q('?from=2026-03-10&to=2026-03-10');
        $this->assertSame([2, '300.00', '100.00'], [$all['data']['metrics']['transactions'], $all['data']['metrics']['revenue_billed'], $all['data']['metrics']['expenses']]);
        [, $one] = $q("?from=2026-03-10&to=2026-03-10&outlet_id=$branch");
        $this->assertSame([1, '100.00', '10.00', '60.00', '50.00'], [$one['data']['metrics']['transactions'], $one['data']['metrics']['revenue_billed'], $one['data']['metrics']['expenses'], $one['data']['metrics']['gross_profit'], $one['data']['metrics']['net_profit']], 'with an outlet filter only that outlet\'s sales and expenses count');
        $this->assertSame(9, (int) $one['data']['inventory']['units'], 'inventory is that outlet\'s: 10 recorded, 1 sold');
        [, $long] = $q('?from=2025-06-01&to=2026-03-15');
        $this->assertNull($long['data']['series'], 'no per-day series for ranges over 92 days');
        foreach (['?from=2026-03-10', '?to=2026-03-10', '?from=2026-03-10&to=2026-03-01', '?from=bad&to=2026-03-10', '?outlet_id=zz', '?x=1', '?from=2020-01-01&to=2026-03-10'] as $bad) { $this->assertSame(422, $q($bad)[0], $bad); }
        $cashier = $this->addStaff($o, 'c@example.com', 'cashier');
        $this->assertSame(403, $this->call('GET', '/dashboard', null, $cashier['token'], $o['business_id'])[0]);
        $m = $this->addStaff($o, 'bm@example.com', 'manager');
        $this->pdo->prepare('INSERT INTO member_outlets (member_id, location_id) VALUES (?,?)')->execute([$m['member_id'], $branch]);
        [, $r] = $this->call('GET', '/dashboard?from=2026-03-10&to=2026-03-10', null, $m['token'], $o['business_id']);
        $this->assertSame([1, '100.00', '10.00'], [$r['data']['metrics']['transactions'], $r['data']['metrics']['revenue_billed'], $r['data']['metrics']['expenses']], 'an outlet-restricted manager sees only their outlet, no business-wide figures');
        $this->assertSame(404, $this->call('GET', '/dashboard?outlet_id=' . (string) $this->pdo->query("SELECT id FROM locations WHERE business_id='{$o['business_id']}' AND is_default=1")->fetchColumn(), null, $m['token'], $o['business_id'])[0]);
        $other = $this->shop('other@example.com');
        [, $x] = $this->call('GET', '/dashboard?from=2026-03-10&to=2026-03-10', null, $other['token'], $other['business_id']);
        $this->assertSame([0, '0.00', '0.00'], [$x['data']['metrics']['transactions'], $x['data']['metrics']['revenue_billed'], $x['data']['credit']['outstanding']]);
    }

    public function testEmptyBusinessDashboardIsAllZerosNotErrors(): void
    {
        $o = $this->shop();
        [$s, $j] = $this->call('GET', '/dashboard', null, $o['token'], $o['business_id']);
        $this->assertSame(200, $s);
        $this->assertSame([0, '0.00', '0.00', '0.00', null], [$j['data']['metrics']['transactions'], $j['data']['metrics']['revenue_billed'], $j['data']['metrics']['expenses'], $j['data']['credit']['outstanding'], $j['data']['previous_period']['revenue_billed_growth_percent']]);
        $this->assertSame([], $j['data']['recent_sales']);
        [$s, $p] = $this->call('GET', '/profit', null, $o['token'], $o['business_id']);
        $this->assertSame([200, '0.00', '0.00'], [$s, $p['data']['summary']['profit'], $p['data']['net_profit']], 'no sales at all = a real zero, not "unknown"');
    }
}
