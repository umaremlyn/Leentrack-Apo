<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Db;
use LeenTrack\Core\Money;
use LeenTrack\Core\Uuid;
use LeenTrack\Repositories\CustomerRepository;
use LeenTrack\Services\AuditService;
use LeenTrack\Services\LoyaltyService;

final class SalesTest extends ApiTestCase
{
    /** @return array<string,mixed> owner on GROWTH, 7.5 % tax unless told otherwise */
    private function shop(string $email = 'owner@example.com', string $tax = '0'): array
    {
        $o = $this->signup($email);
        $this->setPlan($o['business_id'], 'growth');
        if ($tax !== '0') { $this->call('PATCH', '/businesses/' . $o['business_id'], ['tax_rate' => $tax], $o['token']); }
        return $o;
    }

    private function mainLoc(array $o): string
    {
        return (string) $this->pdo->query("SELECT id FROM locations WHERE business_id='{$o['business_id']}' AND is_default=1")->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function product(array $o, string $name, string $price, string $cost = '0', int $stock = 50, string $type = 'product'): array
    {
        [$s, $j] = $this->call('POST', '/products', ['name' => $name, 'selling_price' => $price, 'cost_price' => $cost, 'stock_quantity' => $stock, 'item_type' => $type], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    /** @return array<string,mixed> */
    private function customer(array $o, array $extra = []): array
    {
        [$s, $j] = $this->call('POST', '/customers', $extra + ['name' => 'Ada Obi', 'phone' => '08031234567'], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    /** @param array<string,mixed> $over @return array{0:int,1:array<string,mixed>} */
    private function sell(array $o, array $items, array $over = [], ?string $token = null): array
    {
        $body = $over + ['client_operation_id' => Uuid::v4(), 'items' => $items, 'payment' => ['mode' => 'full', 'method' => 'cash', 'amount' => '100000']];
        [$s, $j] = $this->call('POST', '/sales', $body, $token ?? $o['token'], $o['business_id']);
        return [$s, $j];
    }

    private function stockOf(string $productId): int
    {
        return (int) $this->pdo->query("SELECT stock_quantity FROM products WHERE id='$productId'")->fetchColumn();
    }

    private function invariants(string $bid): void
    {
        $this->assertSame([], $this->pdo->query("SELECT p.id, p.stock_quantity AS q FROM products p LEFT JOIN stock_levels l ON l.product_id=p.id WHERE p.business_id='$bid' GROUP BY p.id, p.stock_quantity HAVING q <> COALESCE(SUM(l.quantity),0)")->fetchAll(), 'products.stock_quantity = SUM(levels)');
        $this->assertSame([], $this->pdo->query("SELECT l.product_id FROM stock_levels l LEFT JOIN (SELECT product_id, location_id, SUM(delta) d FROM stock_movements GROUP BY product_id, location_id) m ON m.product_id=l.product_id AND m.location_id=l.location_id WHERE l.business_id='$bid' AND l.quantity <> COALESCE(m.d,0)")->fetchAll(), 'level = sum of movements');
        $this->assertSame([], $this->pdo->query("SELECT s.id, s.subtotal AS st FROM sales s LEFT JOIN sale_items i ON i.sale_id=s.id WHERE s.business_id='$bid' GROUP BY s.id, s.subtotal HAVING st <> COALESCE(SUM(i.total_price),0)")->fetchAll(), 'sale.subtotal = SUM(items)');
        $this->assertSame([], $this->pdo->query("SELECT id FROM sales WHERE business_id='$bid' AND total_amount <> subtotal - discount_amount + tax_amount")->fetchAll(), 'total = subtotal - discount + tax');
        $this->assertSame([], $this->pdo->query("SELECT c.id FROM customers c WHERE c.business_id='$bid' AND c.loyalty_points <> (SELECT COALESCE(SUM(points),0) FROM loyalty_ledger WHERE customer_id=c.id)")->fetchAll(), 'points = ledger');
    }

    private function loyalty(): LoyaltyService
    {
        return new LoyaltyService($this->pdo, new CustomerRepository($this->pdo), new AuditService($this->pdo));
    }

    // =========================================================================================== T-POS-1: server-side maths
    public function testCheckoutRecomputesEverythingOnTheServer(): void
    {
        $o = $this->shop('owner@example.com', '7.5');
        $a = $this->product($o, 'Rice', '100.00', '60.00', 20);
        $b = $this->product($o, 'Beans', '33.33', '20.00', 10);
        $svc = $this->product($o, 'Delivery', '5.00', '0', 0, 'service');
        [$s, $j] = $this->sell($o, [['product_id' => $a['id'], 'quantity' => 3], ['product_id' => $b['id'], 'quantity' => 1], ['product_id' => $svc['id'], 'quantity' => 1]], ['discount' => ['manual' => '10.00'], 'payment' => ['mode' => 'full', 'method' => 'cash', 'amount' => '400']]);
        $this->assertSame(201, $s, json_encode($j));
        $d = $j['data'];
        // subtotal 300 + 33.33 + 5 = 338.33; discount 10 -> taxable 328.33; 7.5% = 24.62475 -> 24.62; total 352.95
        $this->assertSame(['338.33', '10.00', '24.62', '352.95', '352.95', '47.05', 'completed', false], [$d['subtotal'], $d['discount_amount'], $d['tax_amount'], $d['total_amount'], $d['amount_paid'], $d['change_amount'], $d['status'], $d['is_credit']]);
        $this->assertSame('0.00', $d['balance_due']);
        $this->assertSame(1, preg_match('/^SALE-\d{8}-000001$/', $d['receipt_number']), $d['receipt_number']);
        $this->assertSame([17, 9, 0], [$this->stockOf($a['id']), $this->stockOf($b['id']), $this->stockOf($svc['id'])], 'physical stock down, service untouched');
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reason='sale' AND ref_id='{$d['id']}'")->fetchColumn(), 'one movement per physical product, none for the service');
        $costs = array_column($this->pdo->query("SELECT product_name, unit_cost FROM sale_items WHERE sale_id='{$d['id']}'")->fetchAll(), 'unit_cost', 'product_name');
        $this->assertSame(['Rice' => '60.00', 'Beans' => '20.00', 'Delivery' => '0.00'], $costs, 'cost remembered at the moment of sale');
        $this->assertSame($this->mainLoc($o), $d['outlet_id']);
        [, $j2] = $this->sell($o, [['product_id' => $a['id'], 'quantity' => 1]]);
        $this->assertStringEndsWith('-000002', $j2['data']['receipt_number'], 'server-issued sequence');
        // later price changes do not rewrite history
        $this->call('PATCH', '/products/' . $a['id'], ['selling_price' => '999', 'name' => 'Rice (renamed)'], $o['token'], $o['business_id']);
        [, $again] = $this->call('GET', '/sales/' . $d['id'], null, $o['token'], $o['business_id']);
        $this->assertSame(['Rice', '100.00'], [$again['data']['items'][0]['name'], $again['data']['items'][0]['unit_price']]);
        $this->invariants($o['business_id']);
    }

    public function testClientSuppliedMoneyIsRefusedNotTrusted(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Rice', '100.00', '60', 20);
        $line = ['product_id' => $p['id'], 'quantity' => 1];
        foreach ([['total' => '1.00'], ['subtotal' => '1.00'], ['tax_amount' => '0'], ['discount_amount' => '99'], ['total_amount' => '1'], ['user_id' => $o['user_id']], ['business_id' => 'x'], ['status' => 'completed']] as $extra) {
            [$s] = $this->sell($o, [$line], $extra);
            $this->assertSame(422, $s, json_encode($extra));
        }
        foreach ([['unit_price' => '1.00'], ['price' => '1.00'], ['total_price' => '1.00'], ['name' => 'Free'], ['unit_cost' => '0']] as $extra) {
            [$s] = $this->sell($o, [$line + $extra]);
            $this->assertSame(422, $s, 'item field ' . json_encode($extra));
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        $this->assertSame(20, $this->stockOf($p['id']));
        foreach ([['quantity' => 0], ['quantity' => -1], ['quantity' => 1.5], ['quantity' => '1e3'], ['quantity' => 100001], ['product_id' => 'nope']] as $bad) {
            [$s] = $this->sell($o, [array_merge($line, $bad)]);
            $this->assertSame(422, $s, json_encode($bad));
        }
        $this->assertSame(422, $this->sell($o, [])[0]);
        $this->assertSame(422, $this->call('POST', '/sales', ['items' => [$line], 'payment' => ['mode' => 'full', 'amount' => '100']], $o['token'], $o['business_id'])[0], 'client_operation_id is mandatory');
    }

    public function testStockProductAvailabilityAndAtomicRollback(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'Plenty', '10', '5', 100);
        $b = $this->product($o, 'Scarce', '10', '5', 2);
        [$s, $j] = $this->sell($o, [['product_id' => $a['id'], 'quantity' => 5], ['product_id' => $b['id'], 'quantity' => 3]]);
        $this->assertSame([409, 'insufficient_stock'], [$s, $j['error']['code']]);
        $this->assertStringContainsString('Only 2 left', $j['error']['message']);
        $this->assertSame([100, 2], [$this->stockOf($a['id']), $this->stockOf($b['id'])], 'the first line must be rolled back too');
        $this->assertSame([0, 0], [(int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn(), (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reason='sale'")->fetchColumn()]);
        $this->call('PATCH', '/products/' . $a['id'], ['is_active' => false], $o['token'], $o['business_id']);
        $this->assertSame(422, $this->sell($o, [['product_id' => $a['id'], 'quantity' => 1]])[0], 'switched-off product');
        $this->call('DELETE', '/products/' . $b['id'], null, $o['token'], $o['business_id']);
        $this->assertSame(422, $this->sell($o, [['product_id' => $b['id'], 'quantity' => 1]])[0], 'archived product');
        $other = $this->shop('other@example.com');
        $x = $this->product($other, 'Theirs', '1', '0', 5);
        $this->assertSame(422, $this->sell($o, [['product_id' => $x['id'], 'quantity' => 1]])[0], "another business's product");
        $this->assertSame(5, $this->stockOf($x['id']));
    }

    public function testDuplicateLinesAreMergedAndCountedOnce(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Pen', '2.50', '1', 10);
        [$s, $j] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 2], ['product_id' => $p['id'], 'quantity' => 3]]);
        $this->assertSame([201, '12.50', 1, 5], [$s, $j['data']['total_amount'], count($j['data']['items']), $j['data']['items'][0]['quantity']]);
        $this->assertSame(5, $this->stockOf($p['id']));
    }

    // =========================================================================================== payment / credit
    public function testFullPaymentMustCoverTheTotalAndChangeIsReturned(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Item', '100', '10', 10);
        [$s, $j] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 1]], ['payment' => ['mode' => 'full', 'method' => 'card', 'amount' => '99.99']]);
        $this->assertSame(422, $s);
        $this->assertArrayHasKey('payment.amount', $j['error']['fields']);
        [$s, $j] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 1]], ['payment' => ['mode' => 'full', 'method' => 'transfer', 'amount' => '150']]);
        $this->assertSame([201, 'bank_transfer', '100.00', '50.00'], [$s, $j['data']['payment_method'], $j['data']['amount_paid'], $j['data']['change_amount']]);
        foreach ([['mode' => 'cheque', 'amount' => '100'], ['mode' => 'full', 'method' => 'bitcoin', 'amount' => '100'], ['mode' => 'full', 'amount' => '-5'], ['mode' => 'full', 'amount' => 'lots']] as $bad) {
            $this->assertSame(422, $this->sell($o, [['product_id' => $p['id'], 'quantity' => 1]], ['payment' => $bad])[0], json_encode($bad));
        }
    }

    public function testCreditSaleRules(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Item', '100', '10', 20);
        $c = $this->customer($o);
        $line = [['product_id' => $p['id'], 'quantity' => 2]];
        [$s, $j] = $this->sell($o, $line, ['payment' => ['mode' => 'credit', 'amount' => '0']]);
        $this->assertSame([422, 'customer_required'], [$s, $j['error']['code']]);
        [$s] = $this->sell($o, $line, ['customer_id' => $c['id'], 'payment' => ['mode' => 'credit', 'amount' => '200.01']]);
        $this->assertSame(422, $s, 'cannot pay more than the total on credit');
        [$s, $j] = $this->sell($o, $line, ['customer_id' => $c['id'], 'payment' => ['mode' => 'credit', 'method' => 'cash', 'amount' => '0']]);
        $this->assertSame([201, 'pending', 'other', '200.00', true], [$s, $j['data']['status'], $j['data']['payment_method'], $j['data']['balance_due'], $j['data']['is_credit']], 'nothing paid now -> method other');
        [$s, $j] = $this->sell($o, $line, ['customer_id' => $c['id'], 'payment' => ['mode' => 'credit', 'method' => 'card', 'amount' => '75.50']]);
        $this->assertSame([201, 'pending', 'card', '124.50', '75.50'], [$s, $j['data']['status'], $j['data']['payment_method'], $j['data']['balance_due'], $j['data']['paid_so_far']]);
        [$s, $j] = $this->sell($o, $line, ['customer_id' => $c['id'], 'payment' => ['mode' => 'credit', 'method' => 'cash', 'amount' => '200']]);
        $this->assertSame([201, 'completed', '0.00', true], [$s, $j['data']['status'], $j['data']['balance_due'], $j['data']['is_credit']], 'credit sale paid up front is simply complete');
        [, $cu] = $this->call('GET', '/customers/' . $c['id'], null, $o['token'], $o['business_id']);
        $this->assertSame(['275.50', 3], [$cu['data']['total_spent'], $cu['data']['visit_count']], 'total_spent counts money actually received (G-12)');
        $this->assertNotNull($cu['data']['last_visit']);
        $this->invariants($o['business_id']);
    }

    // =========================================================================================== customer / group / loyalty
    public function testGroupDiscountPointsAndLoyaltyEarning(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Big item', '5000', '3000', 50);
        $g = $this->call('POST', '/customer-groups', ['name' => 'VIP', 'discount_percentage' => '10'], $o['token'], $o['business_id'])[1]['data'];
        $c = $this->customer($o, ['group_id' => $g['id']]);
        $this->pdo->prepare('UPDATE customers SET loyalty_points = 5 WHERE id = ?')->execute([$c['id']]);
        $this->pdo->prepare("INSERT INTO loyalty_ledger (business_id,customer_id,kind,money,points,progress_after,balance_after) VALUES (?,?, 'earn', 0, 5, 0, 5)")->execute([$o['business_id'], $c['id']]);
        $line = [['product_id' => $p['id'], 'quantity' => 4]];                              // subtotal 20,000
        [$s, $j] = $this->sell($o, $line, ['customer_id' => $c['id'], 'discount' => ['apply_group' => false]]);
        $this->assertSame([201, '0.00', '20000.00'], [$s, $j['data']['group_discount'], $j['data']['total_amount']], 'cashier removed the group discount for this sale');
        [$s, $j] = $this->sell($o, $line, ['customer_id' => $c['id'], 'discount' => ['manual' => '500', 'points_redeemed' => 3]]);
        // group 10% of 20,000 = 2,000; manual 500; 3 points x 100 = 300 -> discount 2,800 -> total 17,200
        $this->assertSame([201, '2000.00', '500.00', '300.00', 3, '2800.00', '17200.00'], [$s, $j['data']['group_discount'], $j['data']['manual_discount'], $j['data']['points_discount'], $j['data']['points_redeemed'], $j['data']['discount_amount'], $j['data']['total_amount']]);
        [, $cu] = $this->call('GET', '/customers/' . $c['id'], null, $o['token'], $o['business_id']);
        // paid 20,000 (first, full tender capped to total) + 17,200 => points: first sale earned 2 (20,000/10,000), second 17,200 -> carry 0+... see ledger
        $rows = $this->pdo->query("SELECT kind, points FROM loyalty_ledger WHERE customer_id='{$c['id']}' ORDER BY id")->fetchAll();
        $this->assertSame(['earn', 'earn', 'earn', 'redeem'], array_column($rows, 'kind'));
        $this->assertSame(5 + 2 + 1 - 3, $cu['data']['loyalty_points'], '2 pts for 20,000, then 17,200 + 0 carry = 1 pt, minus 3 spent');
        $this->assertSame(1 + 1, $cu['data']['visit_count']);
        [$s, $j] = $this->sell($o, $line, ['customer_id' => $c['id'], 'discount' => ['points_redeemed' => 99]]);
        $this->assertSame(422, $s);
        $this->assertArrayHasKey('discount.points_redeemed', $j['error']['fields']);
        [$s] = $this->sell($o, $line, ['discount' => ['points_redeemed' => 1]]);
        $this->assertSame(422, $s, 'points need a customer');
        [$s, $j] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 1]], ['discount' => ['manual' => '5000']]);
        $this->assertSame(422, $s, 'discount may not take the whole cart');
        $this->assertArrayHasKey('discount.manual', $j['error']['fields']);
        $this->invariants($o['business_id']);
    }

    public function testLoyaltyOffMeansNoPointsAndNoRedemption(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Item', '20000', '1', 10);
        $c = $this->customer($o);
        $this->call('PUT', '/businesses/' . $o['business_id'] . '/loyalty-settings', ['enabled' => false, 'spend_per_point' => '10000', 'point_value' => '100'], $o['token']);
        [$s] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 1]], ['customer_id' => $c['id']]);
        $this->assertSame(201, $s);
        $this->assertSame(0, (int) $this->pdo->query("SELECT loyalty_points FROM customers WHERE id='{$c['id']}'")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM loyalty_ledger')->fetchColumn());
    }

    public function testArchivedOrForeignCustomerIsRefused(): void
    {
        $o = $this->shop();
        $other = $this->shop('other@example.com');
        $p = $this->product($o, 'Item', '10', '1', 10);
        $theirs = $this->customer($other);
        $mine = $this->customer($o);
        $this->call('DELETE', '/customers/' . $mine['id'], null, $o['token'], $o['business_id']);
        foreach ([$theirs['id'], $mine['id'], Uuid::v4()] as $cid) {
            $this->assertSame(422, $this->sell($o, [['product_id' => $p['id'], 'quantity' => 1]], ['customer_id' => $cid])[0]);
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    }

    // =========================================================================================== T-POS-5: idempotency
    public function testSameOperationIdIsReplayedNotRepeated(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Item', '10', '1', 10);
        $c = $this->customer($o);
        $body = ['client_operation_id' => Uuid::v4(), 'customer_id' => $c['id'], 'items' => [['product_id' => $p['id'], 'quantity' => 2]], 'payment' => ['mode' => 'full', 'amount' => '20']];
        [$s1, $j1] = $this->call('POST', '/sales', $body, $o['token'], $o['business_id']);
        [$s2, $j2] = $this->call('POST', '/sales', $body, $o['token'], $o['business_id']);
        $this->assertSame([201, false, 200, true], [$s1, $j1['meta']['replayed'], $s2, $j2['meta']['replayed']]);
        $this->assertSame($j1['data']['id'], $j2['data']['id']);
        $this->assertSame($j1['data']['receipt_number'], $j2['data']['receipt_number']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        $this->assertSame(8, $this->stockOf($p['id']), 'stock taken once');
        $this->assertSame(1, (int) $this->pdo->query("SELECT visit_count FROM customers WHERE id='{$c['id']}'")->fetchColumn());
        $changed = $body; $changed['items'][0]['quantity'] = 3;
        [$s3, $j3] = $this->call('POST', '/sales', $changed, $o['token'], $o['business_id']);
        $this->assertSame([409, 'idempotency_conflict'], [$s3, $j3['error']['code']], 'same id, different sale');
        // header form
        $key = Uuid::v4();
        $nb = ['items' => [['product_id' => $p['id'], 'quantity' => 1]], 'payment' => ['mode' => 'full', 'amount' => '10']];
        [, $h1] = $this->call('POST', '/sales', $nb, $o['token'], $o['business_id'], ['Idempotency-Key' => $key]);
        [, $h2] = $this->call('POST', '/sales', $nb, $o['token'], $o['business_id'], ['Idempotency-Key' => $key]);
        $this->assertSame([false, true, $h1['data']['id']], [$h1['meta']['replayed'], $h2['meta']['replayed'], $h2['data']['id']]);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE receipt_number LIKE 'PENDING-%'")->fetchColumn());
    }

    public function testOperationIdsAreScopedPerBusiness(): void
    {
        $a = $this->shop('a@example.com');
        $b = $this->shop('b@example.com');
        $pa = $this->product($a, 'A', '10', '1', 5);
        $pb = $this->product($b, 'B', '10', '1', 5);
        $op = Uuid::v4();
        [$s1, $j1] = $this->call('POST', '/sales', ['client_operation_id' => $op, 'items' => [['product_id' => $pa['id'], 'quantity' => 1]], 'payment' => ['mode' => 'full', 'amount' => '10']], $a['token'], $a['business_id']);
        [$s2, $j2] = $this->call('POST', '/sales', ['client_operation_id' => $op, 'items' => [['product_id' => $pb['id'], 'quantity' => 1]], 'payment' => ['mode' => 'full', 'amount' => '10']], $b['token'], $b['business_id']);
        $this->assertSame([201, 201, false], [$s1, $s2, $j2['meta']['replayed']], "B must not get A's sale back (and cannot learn it exists)");
        $this->assertNotSame($j1['data']['id'], $j2['data']['id']);
    }

    /** @param list<array<string,mixed>> $bodies @return list<array{0:int,1:int,2:string}> */
    private function parallel(array $o, array $perWorkerBodies): array
    {
        $env = array_merge($_ENV, getenv());
        $startAt = (string) (microtime(true) + 2.5);
        $procs = [];
        foreach ($perWorkerBodies as $bodies) {
            $pipes = [];
            $proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/checkout_worker.php', $o['token'], $o['business_id'], $startAt, json_encode($bodies)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        $out = [];
        foreach ($procs as [$proc, $pipes]) {
            $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
            proc_close($proc);
            $this->assertSame('', trim($stderr), "worker error: $stderr");
            foreach (array_filter(explode("\n", trim($stdout))) as $line) {
                [$st, $rep, $what] = explode(' ', $line, 3);
                $out[] = [(int) $st, (int) $rep, $what];
            }
        }
        return $out;
    }

    public function testEightParallelSubmissionsOfTheSameSaleCreateExactlyOne(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Item', '10', '1', 20);
        $c = $this->customer($o);
        $body = ['client_operation_id' => Uuid::v4(), 'customer_id' => $c['id'], 'items' => [['product_id' => $p['id'], 'quantity' => 3]], 'payment' => ['mode' => 'full', 'amount' => '30']];
        $res = $this->parallel($o, array_fill(0, 8, [$body]));
        $this->assertCount(8, $res);
        $this->assertSame([], array_filter($res, fn ($r) => !in_array($r[0], [200, 201], true)), 'no request may fail: ' . json_encode($res));
        $this->assertCount(1, array_unique(array_column($res, 2)), 'everyone gets the same sale back');
        $this->assertSame(1, count(array_filter($res, fn ($r) => $r[0] === 201)), 'exactly one created it');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        $this->assertSame(17, $this->stockOf($p['id']), 'stock taken exactly once');
        $this->assertSame([1, 30], [(int) $this->pdo->query("SELECT visit_count FROM customers WHERE id='{$c['id']}'")->fetchColumn(), (int) $this->pdo->query("SELECT total_spent FROM customers WHERE id='{$c['id']}'")->fetchColumn()]);
        $this->invariants($o['business_id']);
    }

    public function testTwoDevicesSellingTheLastUnitExactlyOneWins(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Last ones', '10', '1', 5);
        $workers = [];
        for ($w = 0; $w < 8; $w++) {
            $workers[] = [['client_operation_id' => Uuid::v4(), 'items' => [['product_id' => $p['id'], 'quantity' => 1]], 'payment' => ['mode' => 'full', 'amount' => '10']]];
        }
        $res = $this->parallel($o, $workers);
        $ok = array_filter($res, fn ($r) => $r[0] === 201);
        $refused = array_filter($res, fn ($r) => $r[0] === 409 && $r[2] === 'insufficient_stock');
        $this->assertCount(5, $ok, json_encode($res));
        $this->assertCount(3, $refused, json_encode($res));
        $this->assertSame(0, $this->stockOf($p['id']));
        $this->assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        $this->assertSame(5, (int) $this->pdo->query("SELECT COUNT(DISTINCT receipt_number) FROM sales")->fetchColumn(), 'unique receipt numbers under contention');
        $this->invariants($o['business_id']);
    }

    public function testOppositeProductOrderAndSharedCustomerNeverDeadlock(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'A', '10', '1', 500);
        $b = $this->product($o, 'B', '10', '1', 500);
        $c = $this->customer($o);
        $workers = [];
        for ($w = 0; $w < 6; $w++) {
            $bodies = [];
            for ($i = 0; $i < 5; $i++) {
                $items = $w % 2 === 0 ? [['product_id' => $a['id'], 'quantity' => 1], ['product_id' => $b['id'], 'quantity' => 1]] : [['product_id' => $b['id'], 'quantity' => 1], ['product_id' => $a['id'], 'quantity' => 1]];
                $bodies[] = ['client_operation_id' => Uuid::v4(), 'customer_id' => $c['id'], 'items' => $items, 'payment' => ['mode' => 'full', 'amount' => '20']];
            }
            $workers[] = $bodies;
        }
        $res = $this->parallel($o, $workers);
        $this->assertCount(30, $res);
        $this->assertSame([], array_filter($res, fn ($r) => $r[0] !== 201), 'a deadlock would surface as a 500: ' . json_encode(array_slice(array_filter($res, fn ($r) => $r[0] !== 201), 0, 3)));
        $this->assertSame([470, 470], [$this->stockOf($a['id']), $this->stockOf($b['id'])]);
        $this->assertSame([30, 600], [(int) $this->pdo->query("SELECT visit_count FROM customers WHERE id='{$c['id']}'")->fetchColumn(), (int) $this->pdo->query("SELECT total_spent FROM customers WHERE id='{$c['id']}'")->fetchColumn()]);
        $this->invariants($o['business_id']);
    }

    /**
     * Deterministic proof of the global lock order (D-30). A deadlock-retry would hide a wrong order in the stress test above, so:
     * hold the HIGHER product id locked, send a checkout whose items are listed [high, low]. With ascending locking the worker takes
     * `low` first and then waits for `high`; with input-order locking it would wait at `high` while NOT holding `low`.
     */
    public function testProductsAreLockedInAscendingIdOrderRegardlessOfCartOrder(): void
    {
        $o = $this->shop();
        $p1 = $this->product($o, 'One', '10', '1', 50);
        $p2 = $this->product($o, 'Two', '10', '1', 50);
        [$low, $high] = $p1['id'] < $p2['id'] ? [$p1['id'], $p2['id']] : [$p2['id'], $p1['id']];
        $holder = Db::connect(self::cfg());
        $holder->beginTransaction();
        $holder->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE')->execute([$high]);

        $env = array_merge($_ENV, getenv());
        $body = [['client_operation_id' => Uuid::v4(), 'items' => [['product_id' => $high, 'quantity' => 1], ['product_id' => $low, 'quantity' => 1]], 'payment' => ['mode' => 'full', 'amount' => '20']]];
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/checkout_worker.php', $o['token'], $o['business_id'], (string) microtime(true), json_encode($body)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc);
        $blockedBehindHigh = false;
        $heldLow = false;
        for ($i = 0; $i < 40 && !$heldLow; $i++) {            // up to ~8 s for the worker to boot and reach the lock
            usleep(200_000);
            $probe = Db::connect(self::cfg());
            $probe->beginTransaction();
            try {
                $probe->query("SELECT id FROM products WHERE id = '$low' FOR UPDATE NOWAIT");
                $probe->rollBack();                           // lock was free: worker has not taken `low` yet (or took `high` first)
            } catch (\PDOException $e) {
                $heldLow = (int) ($e->errorInfo[1] ?? 0) === 3572;   // ER_LOCK_NOWAIT: the worker holds `low` while waiting for `high`
                $probe->rollBack();
            }
        }
        $holder->commit();                                    // release `high`: the worker can finish
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        proc_close($proc);
        $this->assertSame('', trim($err));
        $this->assertStringStartsWith('201 ', $out, $out);
        $this->assertTrue($heldLow, 'the checkout must acquire the lower product id first even though the cart lists the higher one first');
    }

    // =========================================================================================== listing / detail / visibility
    public function testListFiltersSummaryAndTimezone(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Item', '100', '40', 100);
        $c = $this->customer($o, ['name' => 'Zainab Bello']);
        [, $s1] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 1]]);                                                 // 100 cash
        [, $s2] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 2]], ['customer_id' => $c['id'], 'payment' => ['mode' => 'credit', 'amount' => '50']]);  // 200 credit
        [, $s3] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 3]], ['payment' => ['mode' => 'full', 'method' => 'card', 'amount' => '300']]);        // 300 card
        // place them on known local days (Africa/Lagos = UTC+1): 23:30 UTC on Mar 1 is already Mar 2 locally
        $this->pdo->exec("UPDATE sales SET created_at='2026-03-01 23:30:00.000' WHERE id='{$s1['data']['id']}'");
        $this->pdo->exec("UPDATE sales SET created_at='2026-03-02 10:00:00.000' WHERE id='{$s2['data']['id']}'");
        $this->pdo->exec("UPDATE sales SET created_at='2026-03-05 10:00:00.000' WHERE id='{$s3['data']['id']}'");
        $q = fn (string $qs) => $this->call('GET', '/sales' . $qs, null, $o['token'], $o['business_id']);
        $ids = fn (array $j) => array_column($j['data'], 'id');
        [, $all] = $q('');
        $this->assertSame([$s3['data']['id'], $s2['data']['id'], $s1['data']['id']], $ids($all), 'newest first');
        $this->assertSame([3, '600.00'], [$all['meta']['summary']['count'], $all['meta']['summary']['total']]);
        $this->assertEqualsCanonicalizing([$s1['data']['id'], $s2['data']['id']], $ids($q('?from=2026-03-02&to=2026-03-02')[1]), 'local-day boundaries: 23:30 UTC on the 1st belongs to the 2nd in Lagos');
        $this->assertSame([$s3['data']['id']], $ids($q('?from=2026-03-03')[1]));
        $this->assertSame([$s2['data']['id']], $ids($q('?payment=credit')[1]));
        $this->assertSame([$s3['data']['id']], $ids($q('?payment=card')[1]));
        $this->assertSame([$s2['data']['id']], $ids($q('?status=pending')[1]));
        $this->assertSame([$s2['data']['id']], $ids($q('?customer_id=' . $c['id'])[1]));
        $this->assertSame([$s2['data']['id']], $ids($q('?search=zainab')[1]));
        $this->assertSame([$s1['data']['id']], $ids($q('?search=' . substr($s1['data']['receipt_number'], -6))[1]));
        $this->assertSame([], $ids($q('?search=' . rawurlencode('%'))[1]), 'wildcards are literal');
        [, $pg] = $q('?per_page=2&page=2');
        $this->assertSame([[$s1['data']['id']], 3], [$ids($pg), $pg['meta']['total']]);
        foreach (['?from=yesterday', '?status=gone', '?payment=cheque', '?outlet_id=zzz', '?bogus=1', '?per_page=0', '?search=' . str_repeat('x', 101)] as $bad) { $this->assertSame(422, $q($bad)[0], $bad); }
        $this->assertFalse($all['meta']['sales_limit_reached']);
    }

    public function testCashierSeesSalesButNotCostsOrProfitAndCannotEditOrVoid(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Item', '100', '40', 20);
        $cashier = $this->addStaff($o, 'cashier@example.com', 'cashier');
        $manager = $this->addStaff($o, 'mgr@example.com', 'manager');
        [$s, $j] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 1]], [], $cashier['token']);
        $this->assertSame([201, $cashier['user_id']], [$s, $j['data']['cashier_id']]);
        $id = $j['data']['id'];
        [, $d] = $this->call('GET', "/sales/$id", null, $cashier['token'], $o['business_id']);
        $this->assertArrayNotHasKey('profit_info', $d['data']);
        $this->assertArrayNotHasKey('unit_cost', $d['data']['items'][0]);
        $this->assertFalse($d['data']['can_edit']);
        [, $m] = $this->call('GET', "/sales/$id", null, $manager['token'], $o['business_id']);
        $this->assertSame(['100.00', '40.00', '60.00', false], [$m['data']['profit_info']['sold'], $m['data']['profit_info']['cost'], $m['data']['profit_info']['profit'], $m['data']['profit_info']['cost_missing']]);
        $this->assertTrue($m['data']['can_edit']);
        $this->assertSame(403, $this->call('POST', "/sales/$id/edit", ['items' => [['product_id' => $p['id'], 'quantity' => 1, 'unit_price' => '1']]], $cashier['token'], $o['business_id'])[0]);
        $this->assertSame(403, $this->call('POST', "/sales/$id/void", ['reason' => 'because'], $cashier['token'], $o['business_id'])[0]);
        $this->assertSame(200, $this->call('GET', '/sales', null, $cashier['token'], $o['business_id'])[0]);
    }

    public function testNothingIsReachableAcrossBusinessesOrOutsideARestrictedOutlet(): void
    {
        $a = $this->shop('a@example.com');
        $b = $this->shop('b@example.com');
        $p = $this->product($a, 'Item', '10', '1', 10);
        [, $sale] = $this->sell($a, [['product_id' => $p['id'], 'quantity' => 1]]);
        $id = $sale['data']['id'];
        foreach ([['GET', "/sales/$id"], ['GET', "/sales/$id/document"], ['POST', "/sales/$id/edit", ['items' => [['product_id' => $p['id'], 'quantity' => 1, 'unit_price' => '1']]]], ['POST', "/sales/$id/void", ['reason' => 'abc']]] as $x) {
            $this->assertSame(404, $this->call($x[0], $x[1], $x[2] ?? null, $b['token'], $b['business_id'])[0], "{$x[0]} {$x[1]} as B");
        }
        [, $list, $res] = $this->call('GET', '/sales', null, $b['token'], $b['business_id']);
        $this->assertSame([], $list['data']);
        $this->assertSame('completed', $this->pdo->query("SELECT payment_status FROM sales WHERE id='$id'")->fetchColumn());
        // outlet restriction
        [, $loc] = $this->call('POST', '/locations', ['name' => 'Branch'], $a['token'], $a['business_id']);
        $branch = $loc['data']['id'];
        $this->call('POST', '/stock/adjustments', ['product_id' => $p['id'], 'location_id' => $branch, 'delta' => 5, 'reason' => 'found'], $a['token'], $a['business_id']);
        $m = $this->addStaff($a, 'branchmgr@example.com', 'manager');
        $this->pdo->prepare('INSERT INTO member_outlets (member_id, location_id) VALUES (?,?)')->execute([$m['member_id'], $branch]);
        $this->assertSame(404, $this->call('GET', "/sales/$id", null, $m['token'], $a['business_id'])[0], 'main-outlet sale hidden from branch-only member');
        [, $l] = $this->call('GET', '/sales', null, $m['token'], $a['business_id']);
        $this->assertSame([], $l['data']);
        [$s, $j] = $this->sell($a, [['product_id' => $p['id'], 'quantity' => 1]], ['outlet_id' => $this->mainLoc($a)], $m['token']);
        $this->assertSame(422, $s, 'cannot sell from an outlet outside the restriction');
        [$s, $j] = $this->sell($a, [['product_id' => $p['id'], 'quantity' => 2]], [], $m['token']);
        $this->assertSame([201, $branch], [$s, $j['data']['outlet_id']], 'defaults to the only outlet they have');
        $this->assertSame(3, (int) $this->pdo->query("SELECT quantity FROM stock_levels WHERE product_id='{$p['id']}' AND location_id='$branch'")->fetchColumn(), 'stock is taken from the selling outlet');
        $this->assertSame(9, (int) $this->pdo->query("SELECT quantity FROM stock_levels WHERE product_id='{$p['id']}' AND location_id='" . $this->mainLoc($a) . "'")->fetchColumn());
        $this->invariants($a['business_id']);
    }

    public function testSellingFromAnOutletWithoutStockThereIsRefused(): void
    {
        $o = $this->shop();
        [, $loc] = $this->call('POST', '/locations', ['name' => 'Branch'], $o['token'], $o['business_id']);
        $p = $this->product($o, 'Item', '10', '1', 10);          // all stock at Main
        [$s, $j] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 1]], ['outlet_id' => $loc['data']['id']]);
        $this->assertSame([409, 'insufficient_stock'], [$s, $j['error']['code']], 'per-outlet stock (G-05)');
    }

    public function testSalesLimitIsOptInBecauseTheSourceNeverBlockedTheTill(): void
    {
        $o = $this->signup('free@example.com');              // free plan: 200 sales
        $p = $this->product($o, 'Item', '1', '0', 1000);
        $ins = $this->pdo->prepare("INSERT INTO sales (id,business_id,receipt_number,subtotal,total_amount) VALUES (UUID(),?,?,1,1)");
        for ($i = 0; $i < 200; $i++) { $ins->execute([$o['business_id'], "OLD-$i"]); }
        [$s] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 1]]);
        $this->assertSame(201, $s, 'default: allowed, like the source');
        [, $l] = $this->call('GET', '/sales?per_page=1', null, $o['token'], $o['business_id']);
        $this->assertTrue($l['meta']['sales_limit_reached'], 'clients can show the same banner as the web app');
        $this->boot(['enforce_sales_limit' => true]);
        [$s, $j] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 1]]);
        $this->assertSame([402, 'plan_limit_reached'], [$s, $j['error']['code']]);
    }

    // =========================================================================================== T-EDIT
    /** @return array{0:array,1:array,2:string} owner, product ids, sale id of a 2-item taxed sale */
    private function editable(): array
    {
        $o = $this->shop('owner@example.com', '7.5');
        $a = $this->product($o, 'Rice', '100.00', '60', 20);
        $b = $this->product($o, 'Beans', '50.00', '30', 20);
        $c = $this->customer($o);
        [$s, $j] = $this->sell($o, [['product_id' => $a['id'], 'quantity' => 2], ['product_id' => $b['id'], 'quantity' => 1]], ['customer_id' => $c['id'], 'discount' => ['manual' => '10'], 'payment' => ['mode' => 'full', 'amount' => '500']]);
        $this->assertSame(201, $s, json_encode($j));
        // subtotal 250, discount 10, taxable 240, tax 18.00, total 258.00
        $this->assertSame('258.00', $j['data']['total_amount']);
        return [$o, ['a' => $a['id'], 'b' => $b['id'], 'c' => $c['id']], $j['data']['id']];
    }

    public function testEditMatchesTheOriginalRulesAndRecordsHistory(): void
    {
        [$o, $ids, $sid] = $this->editable();
        [, $before] = $this->call('GET', "/sales/$sid", null, $o['token'], $o['business_id']);
        // quantity 2 -> 5 for Rice (+300), drop Beans; same discount 10 => subtotal 500, taxable 490, tax 36.75, total 526.75; customer owes the extra 268.75
        $body = ['items' => [['product_id' => $ids['a'], 'quantity' => 5, 'unit_price' => '100.00']], 'discount' => '10', 'extra_paid' => '268.75', 'extra_method' => 'cash', 'reason' => 'customer added items',
            'expected_updated_at' => $before['data']['updated_at'], 'expected_paid' => '258.00'];
        [$s, $j] = $this->call('POST', "/sales/$sid/edit", $body, $o['token'], $o['business_id']);
        $this->assertSame(200, $s, json_encode($j));
        $r = $j['data'];
        $this->assertSame(['258.00', '500.00', '10.00', '36.75', '526.75', '258.00', '526.75', '0.00', '0.00', '0.00', 'completed'], [$r['old_total'], $r['subtotal'], $r['discount'], $r['tax'], $r['total'], $r['paid_so_far'], $r['amount_paid'], $r['repaid'], $r['balance_due'], $r['refund_due'], $r['payment_status']]);
        $this->assertSame('268.75', $r['extra_paid']);
        $this->assertSame([15, 20], [$this->stockOf($ids['a']), $this->stockOf($ids['b'])], 'Rice -3 more, Beans +1 returned');
        [, $after] = $this->call('GET', "/sales/$sid", null, $o['token'], $o['business_id']);
        $this->assertSame([1, 'completed'], [count($after['data']['items']), $after['data']['status']]);
        $this->assertSame(1, $after['data']['edit_count']);
        $this->assertSame('60.00', $after['data']['items'][0]['unit_cost'], 'cost memory survives the edit');
        $edit = $this->pdo->query("SELECT * FROM sale_edits WHERE sale_id='$sid'")->fetch();
        $this->assertSame(['258.00', '526.75', 'cash', $o['user_id']], [$edit['old_total'], $edit['new_total'], $edit['extra_method'], $edit['edited_by']]);
        $this->assertCount(2, json_decode($edit['before_items'], true));
        $this->assertCount(1, json_decode($edit['after_items'], true));
        [, $cu] = $this->call('GET', '/customers/' . $ids['c'], null, $o['token'], $o['business_id']);
        $this->assertSame('526.75', $cu['data']['total_spent'], 'money received follows the edit');
        $this->invariants($o['business_id']);
    }

    public function testEditDownwardsGivesARefundInstructionAndTakesPointsBack(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'Big', '10000', '1', 10);
        $c = $this->customer($o);
        [, $j] = $this->sell($o, [['product_id' => $a['id'], 'quantity' => 3]], ['customer_id' => $c['id']]);      // 30,000 -> 3 points
        $sid = $j['data']['id'];
        $this->assertSame(3, (int) $this->pdo->query("SELECT loyalty_points FROM customers WHERE id='{$c['id']}'")->fetchColumn());
        [$s, $r] = $this->call('POST', "/sales/$sid/edit", ['items' => [['product_id' => $a['id'], 'quantity' => 1, 'unit_price' => '10000']]], $o['token'], $o['business_id']);
        $this->assertSame([200, '20000.00', '10000.00', '10000.00', '0.00'], [$s, $r['data']['refund_due'], $r['data']['total'], $r['data']['amount_paid'], $r['data']['balance_due']]);
        $this->assertSame(9, $this->stockOf($a['id']));
        $this->assertSame(1, (int) $this->pdo->query("SELECT loyalty_points FROM customers WHERE id='{$c['id']}'")->fetchColumn(), 'refunded money takes its points back');
        $this->assertSame('10000.00', $this->pdo->query("SELECT total_spent FROM customers WHERE id='{$c['id']}'")->fetchColumn());
        $this->invariants($o['business_id']);
    }

    public function testEditCanLeaveABalanceOnlyWhenThereIsACustomer(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'Item', '100', '1', 10);
        [, $walkin] = $this->sell($o, [['product_id' => $a['id'], 'quantity' => 1]]);
        [$s, $j] = $this->call('POST', "/sales/{$walkin['data']['id']}/edit", ['items' => [['product_id' => $a['id'], 'quantity' => 2, 'unit_price' => '100']]], $o['token'], $o['business_id']);
        $this->assertSame([422, 'customer_required'], [$s, $j['error']['code']]);
        $this->assertSame(9, $this->stockOf($a['id']), 'rolled back, stock untouched by the failed edit');
        $c = $this->customer($o);
        [, $withCust] = $this->sell($o, [['product_id' => $a['id'], 'quantity' => 1]], ['customer_id' => $c['id']]);
        [$s, $j] = $this->call('POST', "/sales/{$withCust['data']['id']}/edit", ['items' => [['product_id' => $a['id'], 'quantity' => 3, 'unit_price' => '100']]], $o['token'], $o['business_id']);
        $this->assertSame([200, 'pending', '200.00'], [$s, $j['data']['payment_status'], $j['data']['balance_due']], 'the extra goes on the customer\'s account');
        [, $d] = $this->call('GET', '/sales/' . $withCust['data']['id'], null, $o['token'], $o['business_id']);
        $this->assertTrue($d['data']['is_credit']);
    }

    public function testEditRefusesStaleScreensBadMoneyAndUnavailableItems(): void
    {
        [$o, $ids, $sid] = $this->editable();
        $line = [['product_id' => $ids['a'], 'quantity' => 2, 'unit_price' => '100']];
        $post = fn (array $b) => $this->call('POST', "/sales/$sid/edit", $b, $o['token'], $o['business_id']);
        [$s, $j] = $post(['items' => $line, 'expected_updated_at' => '2020-01-01T00:00:00.000Z']);
        $this->assertSame([409, 'stale_resource'], [$s, $j['error']['code']]);
        [$s, $j] = $post(['items' => $line, 'expected_paid' => '1.00']);
        $this->assertSame([409, 'stale_resource'], [$s, $j['error']['code']]);
        foreach ([['items' => []], ['items' => [['product_id' => $ids['a'], 'quantity' => 0, 'unit_price' => '1']]], ['items' => [['product_id' => $ids['a'], 'quantity' => 1, 'unit_price' => '-1']]],
                  ['items' => [['product_id' => $ids['a'], 'quantity' => 1]]], ['items' => $line, 'discount' => '-1'], ['items' => $line, 'discount' => '999'], ['items' => $line, 'extra_paid' => '5'],
                  ['items' => $line, 'extra_method' => 'bitcoin'], ['items' => $line, 'status' => 'completed'], ['items' => $line, 'total' => '1']] as $bad) {
            $this->assertSame(422, $post($bad)[0], json_encode($bad));
        }
        [$s, $j] = $post(['items' => [['product_id' => $ids['a'], 'quantity' => 1000, 'unit_price' => '1']]]);
        $this->assertSame([409, 'insufficient_stock'], [$s, $j['error']['code']]);
        $other = $this->shop('other@example.com');
        $foreign = $this->product($other, 'Theirs', '1', '0', 5);
        $this->assertSame(422, $post(['items' => [['product_id' => $foreign['id'], 'quantity' => 1, 'unit_price' => '1']]])[0], "another business's product");
        $this->call('PATCH', '/products/' . $ids['b'], ['is_active' => false], $o['token'], $o['business_id']);
        $this->assertSame(200, $post(['items' => [['product_id' => $ids['a'], 'quantity' => 2, 'unit_price' => '100'], ['product_id' => $ids['b'], 'quantity' => 1, 'unit_price' => '50']], 'discount' => '10'])[0], 'an item already on the sale may stay even if switched off');
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM sale_edits WHERE sale_id='$sid' AND reason IS NOT NULL")->fetchColumn());
        $this->invariants($o['business_id']);
    }

    public function testEditIsNotAllowedOnVoidedSales(): void
    {
        [$o, $ids, $sid] = $this->editable();
        $this->call('POST', "/sales/$sid/void", ['reason' => 'mistake'], $o['token'], $o['business_id']);
        [$s, $j] = $this->call('POST', "/sales/$sid/edit", ['items' => [['product_id' => $ids['a'], 'quantity' => 1, 'unit_price' => '1']]], $o['token'], $o['business_id']);
        $this->assertSame([422, 'not_editable'], [$s, $j['error']['code']]);
    }

    // =========================================================================================== void
    public function testVoidRestoresStockStatsAndPointsAndIsOneWay(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Item', '10000', '1', 10);
        $c = $this->customer($o);
        $this->tx(fn () => $this->loyalty()->apply($o['business_id'], $c['id'], null, Money::parse('50000'), 'seed', null));    // 5 points
        [, $j] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 2]], ['customer_id' => $c['id'], 'discount' => ['points_redeemed' => 2]]);   // 20,000 - 200 = 19,800
        $sid = $j['data']['id'];
        $this->assertSame('19800.00', $j['data']['total_amount']);
        [, $cu] = $this->call('GET', '/customers/' . $c['id'], null, $o['token'], $o['business_id']);
        $this->assertSame([5 + 1 - 2, '19800.00', 1], [$cu['data']['loyalty_points'], $cu['data']['total_spent'], $cu['data']['visit_count']]);
        $this->assertSame(8, $this->stockOf($p['id']));
        $this->assertSame(422, $this->call('POST', "/sales/$sid/void", ['reason' => ''], $o['token'], $o['business_id'])[0], 'a void needs a reason');
        [$s, $v] = $this->call('POST', "/sales/$sid/void", ['reason' => 'Customer returned everything'], $o['token'], $o['business_id']);
        $this->assertSame([200, 'voided', '19800.00', 1], [$s, $v['data']['status'], $v['data']['refund_due'], $v['data']['stock_restored_items']]);
        $this->assertSame(10, $this->stockOf($p['id']), 'stock came back');
        [, $cu] = $this->call('GET', '/customers/' . $c['id'], null, $o['token'], $o['business_id']);
        $this->assertSame(['0.00', 0, 5], [$cu['data']['total_spent'], $cu['data']['visit_count'], $cu['data']['loyalty_points']], 'spend and visit reversed; earned point taken back, 2 spent points returned');
        [, $d] = $this->call('GET', "/sales/$sid", null, $o['token'], $o['business_id']);
        $this->assertSame(['voided', 'Customer returned everything', false], [$d['data']['status'], $d['data']['void_reason'], $d['data']['can_edit']]);
        [, $l] = $this->call('GET', '/sales', null, $o['token'], $o['business_id']);
        $this->assertSame([1, 0, '0.00'], [count($l['data']), $l['meta']['summary']['count'], $l['meta']['summary']['total']], 'listed, but not counted in revenue');
        [$s, $v] = $this->call('POST', "/sales/$sid/void", ['reason' => 'again'], $o['token'], $o['business_id']);
        $this->assertSame([409, 'already_voided'], [$s, $v['error']['code']]);
        $this->assertSame(10, $this->stockOf($p['id']), 'a second void must not restore stock twice');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='sale.void'")->fetchColumn());
        $this->invariants($o['business_id']);
    }

    private function tx(callable $fn): mixed { return Db::transaction($this->pdo, $fn); }

    public function testManagerMayVoidButReasonIsRequired(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Item', '10', '1', 10);
        $m = $this->addStaff($o, 'mgr@example.com', 'manager');
        [, $j] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 1]]);
        $this->assertSame(422, $this->call('POST', "/sales/{$j['data']['id']}/void", [], $m['token'], $o['business_id'])[0]);
        $this->assertSame(422, $this->call('POST', "/sales/{$j['data']['id']}/void", ['reason' => 'x'], $m['token'], $o['business_id'])[0]);
        $this->assertSame(200, $this->call('POST', "/sales/{$j['data']['id']}/void", ['reason' => 'wrong item rung up'], $m['token'], $o['business_id'])[0]);
    }

    // =========================================================================================== documents + branding
    public function testReceiptInvoiceAndBranding(): void
    {
        $o = $this->shop('owner@example.com', '7.5');
        $p = $this->product($o, 'Rice 5kg', '6500', '4800', 10);
        $c = $this->customer($o, ['name' => 'Mr. Mahmud', 'phone' => '0803 542 4318']);
        $bpath = '/businesses/' . $o['business_id'] . '/branding';
        $png = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\0", 64));
        [$s, $j] = $this->call('PUT', $bpath, ['logo_data' => $png, 'phone' => '0803 000 1111', 'address' => '1 Market Rd', 'payment_details' => 'GTB 0123456789', 'footer_note' => 'Thank you!'], $o['token']);
        $this->assertSame([200, 'Thank you!'], [$s, $j['data']['footer_note']]);
        [, $paid] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 2]], ['customer_id' => $c['id']]);
        [, $credit] = $this->sell($o, [['product_id' => $p['id'], 'quantity' => 1]], ['customer_id' => $c['id'], 'payment' => ['mode' => 'credit', 'method' => 'cash', 'amount' => '2000']]);
        [$s, $r] = $this->call('GET', '/sales/' . $paid['data']['id'] . '/document', null, $o['token'], $o['business_id']);
        $d = $r['data'];
        $this->assertSame(200, $s);
        $this->assertSame(['receipt', 'RCT-' . substr($paid['data']['receipt_number'], 5), 'RECEIPT', 'PAID', 'Cash'], [$d['kind'], $d['number'], $d['title'], $d['status']['label'], $d['paymentMethod']]);
        $this->assertSame(['13975.00', '0.00', '₦', 'NGN', '7.5'], [$d['total'], $d['balanceDue'], $d['currencySymbol'], $d['currencyCode'], $d['taxRate']]);
        $this->assertSame(['Acme Stores', '0803 000 1111', 'GTB 0123456789', 'Thank you!'], [$d['business']['name'], $d['business']['phone'], $d['business']['paymentDetails'], $d['business']['footerNote']]);
        $this->assertSame($png, $d['business']['logoDataUrl']);
        $this->assertSame('2348035424318', $d['whatsapp_number']);
        $this->assertStringContainsString('Thank you for your purchase at Acme Stores.', $d['share_message']);
        [, $r] = $this->call('GET', '/sales/' . $credit['data']['id'] . '/document', null, $o['token'], $o['business_id']);
        $d = $r['data'];
        // 6500 + 7.5% = 6987.50; paid 2000 -> owes 4987.50
        $this->assertSame(['invoice', 'PART PAID', '4987.50', 'On credit', '2000.00'], [$d['kind'], $d['status']['label'], $d['balanceDue'], $d['paymentMethod'], $d['amountPaid']]);
        $this->assertStringContainsString('How to pay:', $d['share_message']);
        $this->assertStringStartsWith('Invoice-INV-', $d['file_name']);
        [$ps, , $pdf] = $this->call('GET', '/sales/' . $credit['data']['id'] . '/document?format=pdf', null, $o['token'], $o['business_id']);
        $this->assertSame([200, 'application/pdf', 'nosniff'], [$ps, $pdf->headers['Content-Type'], $pdf->headers['X-Content-Type-Options']]);
        $this->assertStringStartsWith('%PDF-1.4', $pdf->body);
        $this->assertStringContainsString('attachment; filename="Invoice-INV-', $pdf->headers['Content-Disposition']);
        $this->assertStringContainsString('4,987.50', $pdf->body, 'the balance due is printed on the invoice');
        $this->assertSame(404, $this->call('GET', '/sales/' . Uuid::v4() . '/document?format=pdf', null, $o['token'], $o['business_id'])[0]);
        $this->assertSame(422, $this->call('GET', '/sales/' . $paid['data']['id'] . '/document?format=zip', null, $o['token'], $o['business_id'])[0], 'unknown formats are a validation error');
    }

    public function testBrandingPermissionsAndLogoValidation(): void
    {
        $o = $this->shop();
        $mgr = $this->addStaff($o, 'mgr@example.com', 'manager');
        $cash = $this->addStaff($o, 'cash@example.com', 'cashier');
        $path = '/businesses/' . $o['business_id'] . '/branding';
        $this->assertSame(200, $this->call('GET', $path, null, $cash['token'])[0], 'every member can read (cashiers print receipts)');
        $this->assertSame(403, $this->call('PUT', $path, ['phone' => '1'], $mgr['token'])[0], 'owner only');
        $this->assertSame(403, $this->call('PUT', $path, ['phone' => '1'], $cash['token'])[0]);
        $png = fn (string $body) => 'data:image/png;base64,' . base64_encode($body);
        $okPng = $png("\x89PNG\r\n\x1a\n" . str_repeat('a', 100));
        $this->assertSame(200, $this->call('PUT', $path, ['logo_data' => $okPng], $o['token'])[0]);
        foreach ([
            'text pretending to be png' => $png('<?php echo 1; ?>'),
            'svg' => 'data:image/svg+xml;base64,' . base64_encode('<svg onload=alert(1)/>'),
            'gif' => 'data:image/gif;base64,' . base64_encode('GIF89a....'),
            'jpeg header on png type' => $png("\xFF\xD8\xFF\xE0" . str_repeat('a', 50)),
            'not base64' => 'data:image/png;base64,@@@@',
            'javascript url' => 'javascript:alert(1)',
            'too big' => 'data:image/png;base64,' . str_repeat('A', 200_001),
        ] as $label => $bad) {
            $this->assertSame(422, $this->call('PUT', $path, ['logo_data' => $bad], $o['token'])[0], $label);
        }
        $this->assertSame(422, $this->call('PUT', $path, ['email' => 'nope'], $o['token'])[0]);
        $this->assertSame(422, $this->call('PUT', $path, ['footer_note' => str_repeat('x', 161)], $o['token'])[0]);
        $this->assertSame(422, $this->call('PUT', $path, ['business_id' => 'x'], $o['token'])[0]);
        [, $j] = $this->call('PUT', $path, ['phone' => '123'], $o['token']);
        $this->assertNull($j['data']['logo_data'], 'PUT replaces the whole branding (omitted = cleared), as the source form does');
        $other = $this->shop('other@example.com');
        $this->assertSame(404, $this->call('GET', $path, null, $other['token'])[0]);
        $this->assertSame(404, $this->call('PUT', $path, ['phone' => '9'], $other['token'])[0]);
    }
}
