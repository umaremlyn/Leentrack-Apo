<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Uuid;
use LeenTrack\Services\HousekeepingService;

/**
 * Offline sync (docs/14_SYNC_PROTOCOL.md): duplicate delivery, out-of-order delivery, the last-unit race between two devices,
 * crash-restart replay, clock skew, big backlogs, tenant isolation, and the pull side (paging, increments, tombstones, scoping).
 */
final class SyncTest extends ApiTestCase
{
    private function shop(string $email = 'owner@example.com'): array
    {
        $o = $this->signup($email);
        $this->setPlan($o['business_id'], 'growth', '2999-01-01 00:00:00');
        return $o;
    }

    private function iso(int $offsetSeconds = 0): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', time() + $offsetSeconds);
    }

    private function main(array $o): string
    {
        return (string) $this->pdo->query("SELECT id FROM locations WHERE business_id='{$o['business_id']}' AND is_default=1")->fetchColumn();
    }

    private function product(array $o, string $name, string $price = '100.00', int $stock = 50, string $cost = '60.00'): array
    {
        [$s, $j] = $this->call('POST', '/products', ['name' => $name, 'selling_price' => $price, 'cost_price' => $cost, 'stock_quantity' => $stock], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    /** @param array<string,mixed> $payload */
    private function op(string $type, array $payload, int $ageSeconds = 5, ?string $id = null): array
    {
        return ['id' => $id ?? Uuid::v4(), 'type' => $type, 'client_created_at' => $this->iso(-$ageSeconds), 'payload' => $payload];
    }

    /** @param list<array{0:array,1:int}> $lines */
    private function sale(array $lines, array $extra = []): array
    {
        return $extra + ['items' => array_map(fn ($l) => ['product_id' => $l[0]['id'], 'quantity' => $l[1]], $lines), 'payment' => ['mode' => 'full', 'method' => 'cash', 'amount' => '1000000']];
    }

    /** @param list<array<string,mixed>> $ops @return array{0:int,1:array<string,mixed>} */
    private function push(array $who, array $ops, array $extra = [], string $device = 'device-0000001'): array
    {
        return $this->call('POST', '/sync/push', $extra + ['device_id' => $device, 'device_time' => $this->iso(0), 'operations' => $ops], $who['token'], $who['business_id']);
    }

    /** @param array<string,mixed> $j @return list<array<string,mixed>> */
    private function results(array $j): array
    {
        return $j['data']['results'];
    }

    private function stock(string $pid): int
    {
        return (int) $this->pdo->query("SELECT stock_quantity FROM products WHERE id='$pid'")->fetchColumn();
    }

    private function rows(string $table, string $where = '1=1'): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM `$table` WHERE $where")->fetchColumn();
    }

    private function invariant(string $bid): void
    {
        $this->assertSame([], $this->pdo->query("SELECT p.id FROM products p LEFT JOIN stock_levels l ON l.product_id=p.id WHERE p.business_id='$bid' GROUP BY p.id, p.stock_quantity HAVING p.stock_quantity <> COALESCE(SUM(l.quantity),0)")->fetchAll(), 'total = Σ levels');
        $this->assertSame([], $this->pdo->query("SELECT l.product_id FROM stock_levels l LEFT JOIN (SELECT product_id, location_id, SUM(delta) d FROM stock_movements GROUP BY product_id, location_id) m ON m.product_id=l.product_id AND m.location_id=l.location_id WHERE l.business_id='$bid' AND l.quantity <> COALESCE(m.d,0)")->fetchAll(), 'level = Σ movements');
        $this->assertSame([], $this->pdo->query("SELECT quantity FROM stock_levels WHERE business_id='$bid' AND quantity < 0")->fetchAll(), 'never negative');
    }

    // ================================================================================================ basics
    public function testInfoDescribesTheProtocol(): void
    {
        $o = $this->shop();
        [$s, $j] = $this->call('GET', '/sync/info', null, $o['token'], $o['business_id']);
        $this->assertSame([200, 1, 100, 60], [$s, $j['data']['protocol'], $j['data']['max_operations_per_push'], $j['data']['max_operation_age_days']]);
        $this->assertSame(['sale.checkout', 'credit.repay', 'customer.create', 'expense.create', 'stock.adjust'], $j['data']['operation_types']);
        $this->assertSame(401, $this->call('GET', '/sync/info')[0]);
    }

    public function testEveryOperationTypeIsAppliedThroughTheNormalRules(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Rice', '100.00', 50);
        $cust = Uuid::v4();
        $ops = [
            $this->op('customer.create', ['id' => $cust, 'name' => 'Ada Obi', 'phone' => '08031234567'], 60),
            $this->op('sale.checkout', $this->sale([[$p, 3]], ['customer_id' => $cust, 'payment' => ['mode' => 'credit', 'amount' => '50'], 'expected_total' => '300.00']), 50),
            $this->op('expense.create', ['id' => $e = Uuid::v4(), 'description' => 'Generator fuel', 'amount' => '40.00', 'expense_date' => gmdate('Y-m-d')], 40),
            $this->op('stock.adjust', ['product_id' => $p['id'], 'location_id' => $this->main($o), 'delta' => 10, 'reason' => 'found', 'note' => 'counted at the back'], 30),
        ];
        [$s, $j] = $this->push($o, $ops);
        $this->assertSame(200, $s, json_encode($j));
        $r = $this->results($j);
        $this->assertSame(['applied', 'applied', 'applied', 'applied'], array_column($r, 'status'), json_encode($r));
        $this->assertSame([false, false, false, false], array_column($r, 'duplicate'));
        $this->assertSame($cust, $r[0]['result']['customer_id']);
        $this->assertSame('300.00', $r[1]['result']['total_amount']);
        $this->assertSame('pending', $r[1]['result']['payment_status']);
        $this->assertMatchesRegularExpression('/^SALE-\d{8}-\d{6}$/', $r[1]['result']['receipt_number']);
        // effects: the same as if the till had been online
        $this->assertSame('Ada Obi', $this->pdo->query("SELECT name FROM customers WHERE id='$cust'")->fetchColumn());
        $this->assertSame(50 - 3 + 10, $this->stock($p['id']));
        $this->assertSame('40.00', $this->pdo->query("SELECT amount FROM expenses WHERE id='$e'")->fetchColumn());
        $mv = $this->pdo->query("SELECT ref_type, ref_id, reason FROM stock_movements WHERE product_id='{$p['id']}' AND reason='found'")->fetch();
        $this->assertSame(['sync', $ops[3]['id'], 'found'], [$mv['ref_type'], $mv['ref_id'], $mv['reason']]);
        $sale = $this->pdo->query("SELECT customer_id, total_amount, amount_paid, user_id, client_operation_id FROM sales")->fetch();
        $this->assertSame([$cust, '300.00', '50.00', $o['user_id'], $ops[1]['id']], [$sale['customer_id'], $sale['total_amount'], $sale['amount_paid'], $sale['user_id'], $sale['client_operation_id']]);
        // a repayment of that sale, in a later push
        $repay = $this->op('credit.repay', ['sale_id' => $r[1]['result']['sale_id'], 'amount' => '120.00', 'method' => 'cash']);
        $rr = $this->results($this->push($o, [$repay])[1])[0];
        $this->assertSame('applied', $rr['status'], json_encode($rr));
        $owed = $this->pdo->query("SELECT s.total_amount - s.amount_paid - COALESCE((SELECT SUM(amount) FROM debt_repayments WHERE sale_id = s.id),0) FROM sales s")->fetchColumn();
        $this->assertSame('130.00', number_format((float) $owed, 2, '.', ''), '300 − 50 paid at the till − 120 repaid');
        $this->assertSame(1, $this->rows('debt_repayments'));
        $this->invariant($o['business_id']);
        $this->assertSame(5, $this->rows('sync_operations', "status = 'applied'"));
        $this->assertSame(0, $this->rows('sync_operations', 'payload IS NOT NULL'), 'applied operations keep no copy of their payload');
    }

    // ================================================================================================ duplicates, retries, crashes
    public function testDeliveringTheSameBatchAgainChangesNothingAndReturnsTheSameAnswers(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Rice', '100.00', 50);
        $cust = Uuid::v4();
        $ops = [$this->op('customer.create', ['id' => $cust, 'name' => 'Ada']), $this->op('sale.checkout', $this->sale([[$p, 2]], ['customer_id' => $cust])),
            $this->op('expense.create', ['id' => Uuid::v4(), 'description' => 'Fuel', 'amount' => '5', 'expense_date' => gmdate('Y-m-d')]),
            $this->op('stock.adjust', ['product_id' => $p['id'], 'location_id' => $this->main($o), 'delta' => -1, 'reason' => 'damaged'])];
        $first = $this->results($this->push($o, $ops)[1]);
        for ($i = 0; $i < 3; $i++) {
            $again = $this->results($this->push($o, $ops)[1]);
            $this->assertSame(array_column($first, 'status'), array_column($again, 'status'));
            $this->assertSame([true, true, true, true], array_column($again, 'duplicate'));
            $this->assertEquals(array_column($first, 'result'), array_column($again, 'result'), 'the same answer, including ids and receipt numbers');
        }
        $this->assertSame([1, 1, 1, 1], [$this->rows('sales'), $this->rows('customers'), $this->rows('expenses'), $this->rows('stock_movements', "reason='damaged'")]);
        $this->assertSame(50 - 2 - 1, $this->stock($p['id']));
        $this->assertSame(4, $this->rows('sync_operations'));
        $this->invariant($o['business_id']);
    }

    public function testSameIdTwiceInOneBatchAndIdReuseWithDifferentContentAreCaught(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Rice', '100.00', 50);
        $a = $this->op('sale.checkout', $this->sale([[$p, 1]]));
        $b = $a; $b['payload'] = $this->sale([[$p, 5]]);                         // same id, different content
        $r = $this->results($this->push($o, [$a, $a, $b])[1]);
        $this->assertSame(['applied', 'applied', 'rejected'], array_column($r, 'status'));
        $this->assertSame([false, true, false], array_column($r, 'duplicate'));
        $this->assertSame('idempotency_conflict', $r[2]['code']);
        $this->assertSame(1, $this->rows('sales'));
        $r = $this->results($this->push($o, [$b])[1]);                           // later, in another request
        $this->assertSame(['rejected', 'idempotency_conflict'], [$r[0]['status'], $r[0]['code']]);
        $this->assertSame(49, $this->stock($p['id']), 'only the first content was ever applied');
    }

    public function testCrashAfterApplyingButBeforeRecordingIsHarmlessBecauseEveryOperationIsIdempotentItself(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Rice', '100.00', 50);
        $cust = Uuid::v4();
        $ops = [$this->op('customer.create', ['id' => $cust, 'name' => 'Ada']), $this->op('sale.checkout', $this->sale([[$p, 2]], ['customer_id' => $cust])),
            $this->op('expense.create', ['id' => Uuid::v4(), 'description' => 'Fuel', 'amount' => '5', 'expense_date' => gmdate('Y-m-d')]),
            $this->op('stock.adjust', ['product_id' => $p['id'], 'location_id' => $this->main($o), 'delta' => -1, 'reason' => 'damaged'])];
        $first = $this->results($this->push($o, $ops)[1]);
        $this->pdo->exec('DELETE FROM sync_operations');                          // the server "crashed" after applying: the ledger row is gone
        $again = $this->results($this->push($o, $ops)[1]);
        $this->assertSame(['applied', 'applied', 'applied', 'applied'], array_column($again, 'status'));
        $this->assertSame([true, true, true, true], array_column(array_column($again, 'result'), 'replayed'), 'each one recognised its own earlier effect');
        $this->assertSame($first[1]['result']['sale_id'], $again[1]['result']['sale_id']);
        $this->assertSame([1, 1, 1, 1], [$this->rows('sales'), $this->rows('customers'), $this->rows('expenses'), $this->rows('stock_movements', "reason='damaged'")]);
        $this->assertSame(47, $this->stock($p['id']));
        $this->assertSame(4, $this->rows('sync_operations'), 'and the ledger is rebuilt');
        $this->invariant($o['business_id']);
    }

    public function testARepeatedNewRecordIsRecognisedEvenWhenThePlanLimitIsNowReached(): void
    {
        $o = $this->signup('free@example.com');                                          // free plan: 100 customers
        $ins = $this->pdo->prepare('INSERT INTO customers (id, business_id, name) VALUES (?,?,?)');
        for ($i = 0; $i < 99; $i++) { $ins->execute([Uuid::v4(), $o['business_id'], "C$i"]); }
        $cust = Uuid::v4();
        $op = $this->op('customer.create', ['id' => $cust, 'name' => 'The hundredth']);
        $this->assertSame('applied', $this->results($this->push($o, [$op])[1])[0]['status']);
        $this->pdo->exec('DELETE FROM sync_operations');                                // crash: the answer was lost, the device sends it again
        $r = $this->results($this->push($o, [$op])[1])[0];
        $this->assertSame(['applied', true], [$r['status'], $r['result']['replayed']], json_encode($r));
        $this->assertSame(100, $this->rows('customers'));
        $extra = $this->results($this->push($o, [$this->op('customer.create', ['id' => Uuid::v4(), 'name' => 'One too many'])])[1])[0];
        $this->assertSame('rejected', $extra['status'], 'a genuinely new 101st customer is still refused by the plan');
    }

    public function testServerTroubleIsReportedAsErrorAndNothingIsRememberedSoTheDeviceCanSendAgain(): void
    {
        $o = $this->shop();
        $op = $this->op('expense.create', ['id' => Uuid::v4(), 'description' => 'Fuel', 'amount' => '5', 'expense_date' => gmdate('Y-m-d')]);
        $this->pdo->exec('RENAME TABLE expenses TO expenses_away');                     // the database misbehaves
        try {
            $r = $this->results($this->push($o, [$op])[1])[0];
        } finally {
            $this->pdo->exec('RENAME TABLE expenses_away TO expenses');
        }
        $this->assertSame(['error', 'server_error'], [$r['status'], $r['code']]);
        $this->assertSame(0, $this->rows('sync_operations'), 'a server fault is not a verdict on the operation');
        $r = $this->results($this->push($o, [$op])[1])[0];
        $this->assertSame('applied', $r['status'], 'sent again once the server is healthy, it simply works');
        $this->assertSame(1, $this->rows('expenses'));
    }

    // ================================================================================================ order, dependencies, conflicts
    public function testOutOfOrderDeliveryConflictsThenHealsOnResend(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Rice', '100.00', 50);
        $cust = Uuid::v4();
        $sale = $this->op('sale.checkout', $this->sale([[$p, 1]], ['customer_id' => $cust, 'payment' => ['mode' => 'credit', 'amount' => '0']]));
        $repay = $this->op('credit.repay', ['sale_id' => Uuid::v4(), 'amount' => '10']);              // a sale the server has never seen
        $r = $this->results($this->push($o, [$sale, $repay])[1]);
        $this->assertSame([['conflict', 'dependency_missing'], ['conflict', 'not_found']], [[$r[0]['status'], $r[0]['code']], [$r[1]['status'], $r[1]['code']]]);
        $this->assertSame([0, 50], [$this->rows('sales'), $this->stock($p['id'])], 'a conflict changes nothing');
        $this->assertSame(2, $this->rows('sync_operations', "status = 'conflict' AND payload IS NOT NULL"), 'but it is kept, with its payload');
        // the customer arrives; the device re-sends the same sale
        $this->assertSame('applied', $this->results($this->push($o, [$this->op('customer.create', ['id' => $cust, 'name' => 'Ada'])])[1])[0]['status']);
        $r = $this->results($this->push($o, [$sale])[1]);
        $this->assertSame(['applied', false], [$r[0]['status'], $r[0]['duplicate']], 'a conflict is not remembered as final: it is tried again');
        $this->assertSame('applied', $this->pdo->query("SELECT status FROM sync_operations WHERE client_operation_id='{$sale['id']}'")->fetchColumn());
        $this->assertNull($this->pdo->query("SELECT payload FROM sync_operations WHERE client_operation_id='{$sale['id']}'")->fetchColumn(), 'the stored copy is dropped once it is applied');
        $this->assertSame([1, 49], [$this->rows('sales'), $this->stock($p['id'])]);
    }

    public function testTwoDevicesRaceForTheLastUnitAndTheLoserWaitsForAManager(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Last one', '100.00', 1);
        $c1 = $this->addStaff($o, 'c1@example.com', 'cashier'); $c1['business_id'] = $o['business_id'];
        $c2 = $this->addStaff($o, 'c2@example.com', 'cashier'); $c2['business_id'] = $o['business_id'];
        $a = $this->op('sale.checkout', $this->sale([[$p, 1]]));
        $b = $this->op('sale.checkout', $this->sale([[$p, 1]]));
        $this->assertSame('applied', $this->results($this->push($c1, [$a], [], 'tablet-A000001')[1])[0]['status']);
        $rb = $this->results($this->push($c2, [$b], [], 'phone-B0000001')[1])[0];
        $this->assertSame(['conflict', 'insufficient_stock'], [$rb['status'], $rb['code']]);
        $this->assertSame([1, 0], [$this->rows('sales'), $this->stock($p['id'])]);
        // the inbox: the loser sees her own, the other cashier sees nothing, a manager sees all
        $mine = $this->call('GET', '/sync/operations', null, $c2['token'], $o['business_id'])[1];
        $this->assertSame([[$b['id']], 'conflict'], [array_column($mine['data'], 'id'), $mine['data'][0]['status']]);
        $this->assertEquals($this->sale([[$p, 1]]), $mine['data'][0]['payload'], 'the payload is kept so it can be retried');
        $this->assertSame([], $this->call('GET', '/sync/operations', null, $c1['token'], $o['business_id'])[1]['data']);
        $this->assertSame([$b['id']], array_column($this->call('GET', '/sync/operations', null, $o['token'], $o['business_id'])[1]['data'], 'id'));
        $this->assertSame(403, $this->call('POST', "/sync/operations/{$b['id']}/retry", [], $c2['token'], $o['business_id'])[0], 'a cashier cannot overrule the conflict');
        $this->assertSame(403, $this->call('POST', "/sync/operations/{$b['id']}/discard", ['reason' => 'because I say so'], $c2['token'], $o['business_id'])[0]);
        // retrying while the stock is still 0 changes nothing
        [$s, $j] = $this->call('POST', "/sync/operations/{$b['id']}/retry", [], $o['token'], $o['business_id']);
        $this->assertSame([200, 'conflict', 'insufficient_stock'], [$s, $j['data']['status'], $j['data']['code']]);
        // the manager finds one more unit (online) and retries: recorded as the CASHIER's sale
        $this->assertSame(201, $this->call('POST', '/stock/adjustments', ['product_id' => $p['id'], 'location_id' => $this->main($o), 'delta' => 1, 'reason' => 'found'], $o['token'], $o['business_id'])[0]);
        [$s, $j] = $this->call('POST', "/sync/operations/{$b['id']}/retry", [], $o['token'], $o['business_id']);
        $this->assertSame([200, 'applied'], [$s, $j['data']['status']], json_encode($j));
        $this->assertSame([2, 0], [$this->rows('sales'), $this->stock($p['id'])]);
        $this->assertSame($c2['user_id'], $this->pdo->query("SELECT user_id FROM sales WHERE client_operation_id='{$b['id']}'")->fetchColumn());
        $this->assertSame([], $this->call('GET', '/sync/operations', null, $o['token'], $o['business_id'])[1]['data'], 'nothing left waiting');
        $this->assertSame('applied', $this->results($this->push($c2, [$b], [], 'phone-B0000001')[1])[0]['status'], 'and the phone, sending it again, is told it is done');
        $this->assertSame(409, $this->call('POST', "/sync/operations/{$b['id']}/retry", [], $o['token'], $o['business_id'])[0], 'nothing to decide any more');
        $this->invariant($o['business_id']);
    }

    public function testDiscardKeepsTheRecordAndStopsTheNagging(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Gone', '100.00', 0);
        $a = $this->op('sale.checkout', $this->sale([[$p, 1]]));
        $this->assertSame('conflict', $this->results($this->push($o, [$a])[1])[0]['status']);
        $this->assertSame(422, $this->call('POST', "/sync/operations/{$a['id']}/discard", ['reason' => 'x'], $o['token'], $o['business_id'])[0]);
        [$s, $j] = $this->call('POST', "/sync/operations/{$a['id']}/discard", ['reason' => 'Customer left without the goods'], $o['token'], $o['business_id']);
        $this->assertSame([200, 'discarded', 'Customer left without the goods'], [$s, $j['data']['status'], $j['data']['message']]);
        $r = $this->results($this->push($o, [$a])[1])[0];
        $this->assertSame(['discarded', true], [$r['status'], $r['duplicate']], 'a device that sends it again is told to drop it');
        $this->assertSame(1, $this->rows('audit_logs', "action = 'sync.discard'"));
        $this->assertSame(1, $this->rows('sync_operations', "status = 'discarded'"), 'kept, not erased');
        $this->assertSame(0, $this->rows('sales'));
    }

    public function testPriceChangedIsRefusedUntilAManagerAcceptsTheNewPrice(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Rice', '100.00', 50);
        $a = $this->op('sale.checkout', $this->sale([[$p, 2]], ['expected_total' => '180.00']));      // the device quoted 90 each; today it is 100
        $r = $this->results($this->push($o, [$a])[1])[0];
        $this->assertSame(['conflict', 'price_changed', ['200.00'], ['180.00']], [$r['status'], $r['code'], $r['fields']['actual_total'], $r['fields']['expected_total']]);
        $this->assertSame([0, 50], [$this->rows('sales'), $this->stock($p['id'])]);
        [$s, $j] = $this->call('POST', "/sync/operations/{$a['id']}/accept-prices", [], $o['token'], $o['business_id']);
        $this->assertSame([200, 'applied'], [$s, $j['data']['status']], json_encode($j));
        $this->assertSame('200.00', $this->pdo->query("SELECT total_amount FROM sales")->fetchColumn(), 'recorded at the server price, never at the number the device claimed');
        // accept-prices is only for price conflicts
        $q = $this->product($o, 'Empty', '10.00', 0);
        $b = $this->op('sale.checkout', $this->sale([[$q, 1]]));
        $this->push($o, [$b]);
        $this->assertSame(409, $this->call('POST', "/sync/operations/{$b['id']}/accept-prices", [], $o['token'], $o['business_id'])[0]);
    }

    public function testAcceptingHigherPricesWhenTheCashHandedOverIsNoLongerEnoughKeepsTheHeldOperationUntouched(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Rice', '100.00', 50);
        $a = $this->op('sale.checkout', $this->sale([[$p, 2]], ['expected_total' => '180.00', 'payment' => ['mode' => 'full', 'method' => 'cash', 'amount' => '180.00']]));
        $this->assertSame('price_changed', $this->results($this->push($o, [$a])[1])[0]['code']);
        [$s, $j] = $this->call('POST', "/sync/operations/{$a['id']}/accept-prices", [], $o['token'], $o['business_id']);
        $this->assertSame([409, 'payment_short'], [$s, $j['error']['code']], json_encode($j));
        $this->assertSame([0, 50], [$this->rows('sales'), $this->stock($p['id'])]);
        $row = $this->pdo->query("SELECT status, code FROM sync_operations WHERE client_operation_id = '{$a['id']}'")->fetch();
        $this->assertSame(['conflict', 'price_changed'], [$row['status'], $row['code']], 'the held operation must not be turned into a terminal rejection');
        $this->assertSame(0, $this->rows('audit_logs', "action = 'sync.accept_prices'"));
        // the manager can still discard it
        $this->assertSame(200, $this->call('POST', "/sync/operations/{$a['id']}/discard", ['reason' => 'Customer paid at the old price'], $o['token'], $o['business_id'])[0]);
    }

    public function testExpectedTotalAlsoGuardsTheOnlineEndpointAndIsNotARequiredField(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Rice', '100.00', 50);
        $body = fn (string $exp) => ['client_operation_id' => Uuid::v4(), 'items' => [['product_id' => $p['id'], 'quantity' => 1]], 'payment' => ['mode' => 'full', 'method' => 'cash', 'amount' => '500'], 'expected_total' => $exp];
        [$s, $j] = $this->call('POST', '/sales', $body('99.00'), $o['token'], $o['business_id']);
        $this->assertSame([409, 'price_changed'], [$s, $j['error']['code'] ?? null]);
        $this->assertSame(201, $this->call('POST', '/sales', $body('100.00'), $o['token'], $o['business_id'])[0]);
        $this->assertSame(422, $this->call('POST', '/sales', $body('abc'), $o['token'], $o['business_id'])[0]);
        $this->assertSame(1, $this->rows('sales'));
    }

    // ================================================================================================ time
    public function testSalesAreDatedByWhenTheyHappenedCorrectedForTheDeviceClock(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Rice', '100.00', 50);
        // the phone's clock is 3 hours behind; the sale was rung 30 minutes ago
        $a = $this->op('sale.checkout', $this->sale([[$p, 1]]), 3 * 3600 + 1800);
        [$s, $j] = $this->push($o, [$a], ['device_time' => $this->iso(-3 * 3600)]);
        $this->assertSame(200, $s);
        $this->assertEqualsWithDelta(10800, $j['data']['clock_skew_seconds'], 10, 'the server reports how far behind the device clock was');
        $this->assertSame('applied', $this->results($j)[0]['status']);
        $at = strtotime($this->pdo->query("SELECT created_at FROM sales")->fetchColumn() . ' UTC');
        $this->assertEqualsWithDelta(time() - 1800, $at, 15, 'dated 30 minutes ago, not three hours ago and not "now"');
        $this->assertStringContainsString(gmdate('Ymd', $at), $this->pdo->query('SELECT receipt_number FROM sales')->fetchColumn());
        // a device claiming the future is clamped to now
        $f = $this->op('sale.checkout', $this->sale([[$p, 1]]), -7200);
        $this->push($o, [$f]);
        $at2 = strtotime($this->pdo->query("SELECT created_at FROM sales WHERE client_operation_id='{$f['id']}'")->fetchColumn() . ' UTC');
        $this->assertEqualsWithDelta(time(), $at2, 15, 'never dated in the future');
    }

    public function testBadTimesAreRefusedClearly(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Rice', '100.00', 50);
        $old = $this->op('sale.checkout', $this->sale([[$p, 1]]), 61 * 86400);
        $r = $this->results($this->push($o, [$old])[1])[0];
        $this->assertSame(['rejected', 'too_old'], [$r['status'], $r['code']]);
        $none = ['id' => Uuid::v4(), 'type' => 'sale.checkout', 'payload' => $this->sale([[$p, 1]])];
        $r = $this->results($this->push($o, [$none])[1])[0];
        $this->assertSame(['rejected', 'invalid_operation'], [$r['status'], $r['code']]);
        $bad = $this->op('sale.checkout', $this->sale([[$p, 1]])); $bad['client_created_at'] = 'yesterday-ish';
        $this->assertSame('invalid_operation', $this->results($this->push($o, [$bad])[1])[0]['code']);
        $this->assertSame(422, $this->push($o, [$this->op('sale.checkout', $this->sale([[$p, 1]]))], ['device_time' => $this->iso(-61 * 86400)])[0], 'a wildly wrong device clock must be fixed first');
        $this->assertSame(422, $this->push($o, [$this->op('sale.checkout', $this->sale([[$p, 1]]))], ['device_time' => 'zzzzzzzzzzzz'])[0]);
        $this->assertSame(0, $this->rows('sales'));
        $this->assertSame(50, $this->stock($p['id']));
    }

    // ================================================================================================ batches and malformed input
    public function testABacklogOfOneHundredSalesIsAppliedInOrderAndOneHundredAndOneIsRefused(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Rice', '10.00', 500);
        $ops = [];
        for ($i = 0; $i < 100; $i++) { $ops[] = $this->op('sale.checkout', $this->sale([[$p, 1]]), 1000 - $i * 5); }
        [$s, $j] = $this->push($o, $ops);
        $this->assertSame(200, $s);
        $this->assertSame(100, $j['data']['summary']['applied']);
        $this->assertSame(array_column($ops, 'id'), array_column($this->results($j), 'id'), 'answers come back in the order sent');
        $this->assertSame(400, $this->stock($p['id']));
        $nums = $this->pdo->query('SELECT receipt_number FROM sales ORDER BY receipt_number')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(100, array_unique($nums));
        $this->assertSame(array_column($ops, 'id'), $this->pdo->query('SELECT client_operation_id FROM sales ORDER BY receipt_number')->fetchAll(\PDO::FETCH_COLUMN), 'receipt numbers follow the order the cashier worked in');
        $this->assertSame(422, $this->push($o, array_merge($ops, [$this->op('sale.checkout', $this->sale([[$p, 1]]))]))[0]);
        $this->assertSame(422, $this->push($o, [])[0]);
        $this->assertSame(422, $this->call('POST', '/sync/push', ['operations' => $ops], $o['token'], $o['business_id'])[0], 'device_id is required');
        $this->assertSame(100, $this->rows('sales'));
        $this->invariant($o['business_id']);
    }

    public function testMalformedOperationsAreRejectedIndividuallyWithoutHarmingTheRest(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Rice', '100.00', 50);
        $good = $this->op('sale.checkout', $this->sale([[$p, 1]]));
        $ops = [
            ['type' => 'sale.checkout', 'client_created_at' => $this->iso(), 'payload' => $this->sale([[$p, 1]])],                      // no id
            $this->op('sale.void', ['sale_id' => Uuid::v4()]),                                                                           // online only
            $this->op('purchase.receive', []),
            ['id' => Uuid::v4(), 'type' => 'sale.checkout', 'client_created_at' => $this->iso(), 'payload' => 'not an object'],
            $this->op('stock.adjust', ['product_id' => $p['id'], 'location_id' => $this->main($o), 'new_quantity' => 3, 'reason' => 'count']),         // a count cannot be queued
            $this->op('sale.checkout', ['items' => [['product_id' => $p['id'], 'quantity' => 1, 'price' => '1.00']], 'payment' => ['mode' => 'full', 'amount' => '5']]),   // a client price
            $this->op('sale.checkout', $this->sale([[$p, 1]], ['total_amount' => '1.00'])),                                              // a client total
            $this->op('customer.create', ['name' => 'No id']),
            $this->op('expense.create', ['id' => 'not-a-uuid', 'description' => 'x', 'amount' => '1', 'expense_date' => gmdate('Y-m-d')]),
            $good,
            'a string',
        ];
        $r = $this->results($this->push($o, $ops)[1]);
        $this->assertSame(['rejected', 'invalid_operation'], [$r[0]['status'], $r[0]['code']]);
        $this->assertSame(['unsupported_type', 'unsupported_type'], [$r[1]['code'], $r[2]['code']]);
        $this->assertSame('invalid_operation', $r[3]['code']);
        foreach ([4, 5, 6, 7, 8] as $i) { $this->assertSame('rejected', $r[$i]['status'], "operation $i: " . json_encode($r[$i])); $this->assertSame('validation_failed', $r[$i]['code'], "operation $i"); }
        $this->assertSame('applied', $r[9]['status']);
        $this->assertSame('invalid_operation', $r[10]['code']);
        $this->assertSame([1, 49], [$this->rows('sales'), $this->stock($p['id'])], 'only the one good sale happened');
        $this->assertStringContainsString('Stock counts cannot be queued', json_encode($r[4]));
    }

    // ================================================================================================ permissions, tenants
    public function testPermissionsApplyAtApplyTimeAndTenantsAreIsolated(): void
    {
        $a = $this->shop('a@example.com'); $b = $this->shop('b@example.com');
        $pa = $this->product($a, 'A-item', '100.00', 10);
        $cashier = $this->addStaff($a, 'cash@example.com', 'cashier'); $cashier['business_id'] = $a['business_id'];
        $ops = [$this->op('sale.checkout', $this->sale([[$pa, 1]])),
            $this->op('expense.create', ['id' => Uuid::v4(), 'description' => 'Not for cashiers', 'amount' => '5', 'expense_date' => gmdate('Y-m-d')]),
            $this->op('stock.adjust', ['product_id' => $pa['id'], 'location_id' => $this->main($a), 'delta' => 100, 'reason' => 'found']),
            $this->op('customer.create', ['id' => Uuid::v4(), 'name' => 'Walk-in'])];
        $r = $this->results($this->push($cashier, $ops)[1]);
        $this->assertSame(['applied', 'rejected', 'rejected', 'applied'], array_column($r, 'status'));
        $this->assertSame(['forbidden', 'forbidden'], [$r[1]['code'], $r[2]['code']]);
        $this->assertSame(9, $this->stock($pa['id']));
        // business B cannot sell A's product, reuse A's operation ids, or touch A's inbox
        $own = $this->op('sale.checkout', $this->sale([[$pa, 1]]));
        $r = $this->results($this->push($b, [$own])[1])[0];
        $this->assertSame(['rejected', 'validation_failed'], [$r['status'], $r['code']]);
        $this->assertSame(9, $this->stock($pa['id']));
        $pb = $this->product($b, 'B-item', '50.00', 10);
        $same = $ops[0]; $same['payload'] = $this->sale([[$pb, 1]]);                     // A's operation UUID, B's content: independent namespaces
        $this->assertSame('applied', $this->results($this->push($b, [$same])[1])[0]['status']);
        $this->assertSame(2, $this->rows('sales'));
        $conflict = $this->op('sale.checkout', $this->sale([[$pa, 50]]));
        $this->assertSame('conflict', $this->results($this->push($a, [$conflict])[1])[0]['status']);
        $this->assertSame(404, $this->call('POST', "/sync/operations/{$conflict['id']}/retry", [], $b['token'], $b['business_id'])[0]);
        $this->assertNotContains($conflict['id'], array_column($this->call('GET', '/sync/operations', null, $b['token'], $b['business_id'])[1]['data'], 'id'), "B's inbox never shows A's operations (it holds only B's own rejection)");
        $this->assertSame(401, $this->call('POST', '/sync/push', ['device_id' => 'device-0000001', 'operations' => [$conflict]])[0]);
    }

    // ================================================================================================ housekeeping
    public function testHousekeepingForgetsOldFinishedOperationsAndReconciliationFlagsStuckOnes(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Rice', '100.00', 50);
        $done = $this->op('sale.checkout', $this->sale([[$p, 1]]));
        $stuck = $this->op('sale.checkout', $this->sale([[$p, 500]]));
        $this->push($o, [$done, $stuck]);
        $this->pdo->exec("UPDATE sync_operations SET processed_at = '2020-01-01', received_at = '2020-01-01' WHERE client_operation_id IN ('{$done['id']}','{$stuck['id']}')");
        $r = $this->container->reconciliation()->run();
        $chk = array_column($r['checks'], null, 'name')['sync_operations_waiting_for_a_person'];
        $this->assertSame([1, [$stuck['id']]], [$chk['violations'], $chk['samples']], 'a conflict waiting more than a week is reported');
        $out = (new HousekeepingService($this->pdo))->run();
        $this->assertSame(1, $out['sync_operations'], 'only the finished one is old enough to forget');
        $this->assertSame([$stuck['id']], $this->pdo->query('SELECT client_operation_id FROM sync_operations')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame(1, $this->rows('sales'), 'the sale itself is untouched');
    }

    // ================================================================================================ pull
    private function pull(array $o, array $body = []): array
    {
        [$s, $j] = $this->call('POST', '/sync/pull', $body, $o['token'], $o['business_id']);
        $this->assertSame(200, $s, json_encode($j));
        return $j['data'];
    }

    public function testFirstPullIsACompleteSnapshotWithTheRightShapes(): void
    {
        $o = $this->shop();
        $cat = $this->call('POST', '/categories', ['name' => 'Food'], $o['token'], $o['business_id'])[1]['data'];
        $grp = $this->call('POST', '/customer-groups', ['name' => 'VIP', 'discount_percentage' => 5], $o['token'], $o['business_id'])[1]['data'];
        $p = $this->product($o, 'Rice', '100.00', 7);
        $this->call('POST', '/customers', ['name' => 'Ada', 'phone' => '08031234567', 'group_id' => $grp['id']], $o['token'], $o['business_id']);
        $this->call('PUT', "/businesses/{$o['business_id']}/branding", ['phone' => '0800', 'footer_note' => 'Thanks'], $o['token'], $o['business_id']);
        $d = $this->pull($o);
        $this->assertFalse($d['has_more']);
        $this->assertSame(['products', 'categories', 'customers', 'customer_groups', 'locations', 'stock_levels', 'settings'], array_keys($d['changes']));
        $prod = $d['changes']['products'][0];
        $this->assertSame(['Rice', '100.00', '60.00', null], [$prod['name'], $prod['selling_price'], $prod['cost_price'], $prod['archived_at']]);
        $this->assertArrayNotHasKey('stock_quantity', $prod, 'stock comes per outlet from stock_levels');
        $this->assertSame([$p['id'] . '|' . $this->main($o), 7], [$d['changes']['stock_levels'][0]['id'], (int) $d['changes']['stock_levels'][0]['quantity']]);
        $this->assertSame(['Ada', $grp['id']], [$d['changes']['customers'][0]['name'], $d['changes']['customers'][0]['customer_group_id']]);
        $this->assertSame(['5.00'], array_column($d['changes']['customer_groups'], 'discount_percentage'));
        $this->assertSame([$this->main($o)], array_column($d['changes']['locations'], 'id'));
        $s = $d['changes']['settings'][0];
        $this->assertSame([$o['business_id'], 'NGN', 'Thanks'], [$s['id'], $s['currency'], $s['branding']['footer_note']]);
        $this->assertSame([[$cat['id']], [$grp['id']]], [$d['reference_ids']['categories'], $d['reference_ids']['customer_groups']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\|/', $d['cursors']['products']);
        $this->assertSame(['products', 'categories', 'customers', 'customer_groups', 'locations', 'stock_levels', 'settings'], array_keys($d['cursors']));
    }

    public function testPagingWalksEveryRowExactlyOnceWithinASweep(): void
    {
        $o = $this->shop();
        $ids = [];
        for ($i = 0; $i < 7; $i++) { $ids[] = $this->product($o, "Item $i", '10.00', 5)['id']; }
        $cursors = []; $seen = []; $pages = 0;
        do {
            $d = $this->pull($o, ['resources' => ['products'], 'limit' => 3, 'cursors' => $cursors]);
            $seen = array_merge($seen, array_column($d['changes']['products'], 'id'));
            $cursors = $d['cursors'];
            $pages++;
        } while ($d['has_more'] && $pages < 10);
        $this->assertSame(3, $pages, '7 rows at 3 per page');
        sort($ids); $sorted = $seen; sort($sorted);
        $this->assertSame($ids, $sorted, 'every product, none missing');
        $this->assertCount(7, array_unique($seen), 'none twice inside the sweep');
    }

    public function testIncrementalPullReturnsOnlyWhatChangedAndAlsoRowsFromTheOverlapWindow(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'Old', '10.00', 5); $b = $this->product($o, 'Newer', '10.00', 5);
        $this->pdo->exec("UPDATE products SET updated_at = '2020-01-01 00:00:00.000'");            // everything is old news
        $first = $this->pull($o, ['resources' => ['products']]);
        $this->assertCount(2, $first['changes']['products']);
        $this->assertStringStartsWith('2020-01-01 00:00:00.000|', $first['cursors']['products'], 'old rows: the cursor sits exactly after the last one, no rewinding, so an idle device is not re-sent them');
        $this->assertSame([], $this->pull($o, ['resources' => ['products'], 'cursors' => $first['cursors']])['changes']['products'], 'idle: nothing');
        $this->assertSame(200, $this->call('PATCH', "/products/{$b['id']}", ['selling_price' => '12.00'], $o['token'], $o['business_id'])[0]);
        $inc = $this->pull($o, ['resources' => ['products'], 'cursors' => $first['cursors']]);
        $this->assertSame([[$b['id'], '12.00']], array_map(fn ($r) => [$r['id'], $r['selling_price']], $inc['changes']['products']), 'only the one that changed');
        // the cursor was rewound ~10 s, so the same recent row comes again next time: harmless, upsert by id
        $again = $this->pull($o, ['resources' => ['products'], 'cursors' => $inc['cursors']]);
        $this->assertSame([$b['id']], array_column($again['changes']['products'], 'id'));
        // a transaction that committed late, with a timestamp slightly in the past, is still caught
        $late = Uuid::v4();
        $this->pdo->prepare("INSERT INTO products (id,business_id,name,item_type,selling_price,cost_price,stock_quantity,min_stock_level,reorder_level,is_active,updated_at) VALUES (?,?,?,'product',1,1,0,5,5,1,?)")
            ->execute([$late, $o['business_id'], 'Late commit', gmdate('Y-m-d H:i:s', time() - 4) . '.000']);
        $this->assertContains($late, array_column($this->pull($o, ['resources' => ['products'], 'cursors' => $inc['cursors']])['changes']['products'], 'id'));
    }

    public function testRemovalsTravelAsTombstonesAndReferenceLists(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Doomed', '10.00', 5);
        $cat = $this->call('POST', '/categories', ['name' => 'Temp'], $o['token'], $o['business_id'])[1]['data'];
        $cust = $this->call('POST', '/customers', ['name' => 'Leaver'], $o['token'], $o['business_id'])[1]['data'];
        $first = $this->pull($o);
        $this->assertSame([$cat['id']], $first['reference_ids']['categories']);
        $this->pdo->exec("UPDATE products SET updated_at = '2020-01-01'; UPDATE customers SET updated_at = '2020-01-01'");
        $c = $this->pull($o)['cursors'];
        $this->assertSame(200, $this->call('DELETE', "/products/{$p['id']}", null, $o['token'], $o['business_id'])[0]);
        $this->assertSame(200, $this->call('DELETE', "/customers/{$cust['id']}", null, $o['token'], $o['business_id'])[0]);
        $this->assertSame(200, $this->call('DELETE', "/categories/{$cat['id']}", null, $o['token'], $o['business_id'])[0]);
        $d = $this->pull($o, ['cursors' => $c]);
        $this->assertNotNull($d['changes']['products'][0]['archived_at'], 'an archived product arrives marked, so the device removes it');
        $this->assertNotNull($d['changes']['customers'][0]['archived_at']);
        $this->assertSame([], $d['reference_ids']['categories'], 'a deleted category is simply absent from the list');
    }

    public function testPullIsScopedToWhatThePersonMaySee(): void
    {
        $o = $this->shop();
        [, $l] = $this->call('POST', '/locations', ['name' => 'Branch'], $o['token'], $o['business_id']);
        $branch = $l['data']['id'];
        $p = $this->product($o, 'Rice', '100.00', 20);
        $this->call('POST', '/stock/adjustments', ['product_id' => $p['id'], 'location_id' => $branch, 'delta' => 5, 'reason' => 'found'], $o['token'], $o['business_id']);
        $cashier = $this->addStaff($o, 'cash@example.com', 'cashier'); $cashier['business_id'] = $o['business_id'];
        $mgr = $this->addStaff($o, 'bm@example.com', 'manager'); $mgr['business_id'] = $o['business_id'];
        $this->pdo->prepare('INSERT INTO member_outlets (member_id, location_id) VALUES (?,?)')->execute([$mgr['member_id'], $branch]);
        $full = $this->pull($o);
        $this->assertCount(2, $full['changes']['locations']);
        $this->assertCount(2, $full['changes']['stock_levels']);
        $this->assertArrayHasKey('cost_price', $full['changes']['products'][0]);
        $cs = $this->pull($cashier);
        $this->assertArrayNotHasKey('cost_price', $cs['changes']['products'][0], 'a cashier never receives what the goods cost');
        $this->assertStringNotContainsString('"60.00"', json_encode($cs['changes']['products']));
        $r = $this->pull($mgr);
        $this->assertSame([$branch], array_column($r['changes']['locations'], 'id'), 'a restricted manager receives only their outlet');
        $this->assertSame([$p['id'] . '|' . $branch], array_column($r['changes']['stock_levels'], 'id'));
        $b = $this->shop('b@example.com');
        $this->assertSame([], $this->pull($b)['changes']['products'], 'another business gets none of it');
    }

    public function testSettingsAreSentOnlyWhenTheyChanged(): void
    {
        $o = $this->shop();
        $this->pdo->exec("UPDATE businesses SET updated_at = '2020-01-01'");
        $first = $this->pull($o, ['resources' => ['settings']]);
        $this->assertCount(1, $first['changes']['settings']);
        $this->assertSame([], $this->pull($o, ['resources' => ['settings'], 'cursors' => $first['cursors']])['changes']['settings'], 'unchanged: not sent again');
        $this->assertSame(200, $this->call('PATCH', "/businesses/{$o['business_id']}", ['tax_rate' => '7.5'], $o['token'], $o['business_id'])[0]);
        $d = $this->pull($o, ['resources' => ['settings'], 'cursors' => $first['cursors']]);
        $this->assertSame('7.50', $d['changes']['settings'][0]['tax_rate']);
    }

    public function testPullValidatesItsInput(): void
    {
        $o = $this->shop();
        foreach ([['cursors' => ['products' => 'garbage']], ['cursors' => ['nope' => '2020-01-01 00:00:00.000|']], ['cursors' => 'x'], ['cursors' => ['products' => "2020-01-01 00:00:00.000|'; DROP TABLE products;--"]],
                  ['limit' => 0], ['limit' => 5000], ['limit' => 'many'], ['resources' => ['users']], ['resources' => []], ['resources' => 'products'], ['bogus' => 1]] as $bad) {
            $this->assertSame(422, $this->call('POST', '/sync/pull', $bad, $o['token'], $o['business_id'])[0], json_encode($bad));
        }
        $this->assertSame(200, $this->call('POST', '/sync/pull', ['cursors' => ['products' => null], 'limit' => 1000], $o['token'], $o['business_id'])[0]);
        $this->assertSame(401, $this->call('POST', '/sync/pull', [])[0]);
        $this->assertSame(400, $this->call('POST', '/sync/pull', [], $o['token'])[0], 'a business must be named');
    }

    // ================================================================================================ real parallelism
    /** @param list<array<string,mixed>> $reqs @return array{0:resource,1:array} */
    private function spawn(array $who, array $reqs, string $startAt): array
    {
        $env = array_merge($_ENV, getenv(), ['TEST_DB_NAME' => getenv('TEST_DB_NAME'), 'TEST_DB_USER' => getenv('TEST_DB_USER'), 'TEST_DB_PASS' => getenv('TEST_DB_PASS'), 'TEST_DB_SOCKET' => getenv('TEST_DB_SOCKET')]);
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/request_worker.php', $who['token'], $who['business_id'], $startAt, json_encode($reqs)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc);
        return [$proc, $pipes];
    }

    /** @param list<array{0:resource,1:array}> $procs @return list<array<string,mixed>> decoded response bodies */
    private function bodies(array $procs): array
    {
        $out = [];
        foreach ($procs as [$proc, $pipes]) {
            $o = stream_get_contents($pipes[1]); $e = stream_get_contents($pipes[2]);
            proc_close($proc);
            $this->assertSame('', trim($e), "worker stderr: $e");
            foreach (explode("\n", trim($o)) as $line) { if (str_starts_with($line, 'BODY ')) { $out[] = json_decode(base64_decode(substr($line, 5)), true); } }
        }
        return $out;
    }

    public function testManyDevicesSendingTheSameBatchAtTheSameMomentCauseOneEffect(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Rice', '100.00', 50);
        $cust = Uuid::v4();
        $ops = [$this->op('customer.create', ['id' => $cust, 'name' => 'Ada']), $this->op('sale.checkout', $this->sale([[$p, 2]], ['customer_id' => $cust])),
            $this->op('expense.create', ['id' => Uuid::v4(), 'description' => 'Fuel', 'amount' => '5', 'expense_date' => gmdate('Y-m-d')]),
            $this->op('stock.adjust', ['product_id' => $p['id'], 'location_id' => $this->main($o), 'delta' => -1, 'reason' => 'damaged'])];
        $raw = json_encode(['device_id' => 'device-0000001', 'device_time' => $this->iso(0), 'operations' => $ops]);
        $startAt = (string) (microtime(true) + 2.0);
        $procs = [];
        for ($w = 0; $w < 6; $w++) { $procs[] = $this->spawn($o, [['method' => 'POST', 'path' => '/sync/push', 'raw' => $raw, 'dump' => true]], $startAt); }
        $bodies = $this->bodies($procs);
        $this->assertCount(6, $bodies);
        foreach ($bodies as $b) { $this->assertSame(['applied', 'applied', 'applied', 'applied'], array_column($b['data']['results'], 'status'), json_encode($b)); }
        $this->assertSame([1, 1, 1, 1], [$this->rows('sales'), $this->rows('customers'), $this->rows('expenses'), $this->rows('stock_movements', "reason='damaged'")], 'six deliveries, one effect');
        $this->assertSame(47, $this->stock($p['id']));
        $this->assertSame(4, $this->rows('sync_operations'));
        $this->invariant($o['business_id']);
    }

    public function testSixDevicesRacingForTheLastUnitProduceExactlyOneSale(): void
    {
        $o = $this->shop();
        $p = $this->product($o, 'Last one', '100.00', 1);
        $startAt = (string) (microtime(true) + 2.0);
        $procs = [];
        for ($w = 0; $w < 6; $w++) {
            $raw = json_encode(['device_id' => "device-race-$w-0000", 'device_time' => $this->iso(0), 'operations' => [$this->op('sale.checkout', $this->sale([[$p, 1]]))]]);
            $procs[] = $this->spawn($o, [['method' => 'POST', 'path' => '/sync/push', 'raw' => $raw, 'dump' => true]], $startAt);
        }
        $statuses = array_map(fn ($b) => $b['data']['results'][0]['status'] . '/' . ($b['data']['results'][0]['code'] ?? ''), $this->bodies($procs));
        sort($statuses);
        $this->assertSame(['applied/', 'conflict/insufficient_stock', 'conflict/insufficient_stock', 'conflict/insufficient_stock', 'conflict/insufficient_stock', 'conflict/insufficient_stock'], $statuses);
        $this->assertSame([1, 0], [$this->rows('sales'), $this->stock($p['id'])]);
        $this->assertSame(5, $this->rows('sync_operations', "status = 'conflict'"), 'the five losers are waiting for a manager, with their payloads');
        $this->invariant($o['business_id']);
    }
}
