<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Uuid;

final class CreditTest extends ApiTestCase
{
    private function shop(string $email = 'owner@example.com'): array
    {
        $o = $this->signup($email);
        $this->setPlan($o['business_id'], 'growth');
        return $o;
    }

    /** @return array<string,mixed> */
    private function product(array $o, string $price = '100.00', int $stock = 100): array
    {
        [$s, $j] = $this->call('POST', '/products', ['name' => 'Item ' . bin2hex(random_bytes(3)), 'selling_price' => $price, 'cost_price' => '40', 'stock_quantity' => $stock], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    /** @return array<string,mixed> */
    private function customer(array $o, string $name = 'Ada Obi', string $phone = '08031234567'): array
    {
        [$s, $j] = $this->call('POST', '/customers', ['name' => $name, 'phone' => $phone], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    /** credit sale of $qty items; returns the sale */
    private function creditSale(array $o, array $product, array $customer, int $qty = 3, string $paidNow = '50', ?string $token = null): array
    {
        [$s, $j] = $this->call('POST', '/sales', ['client_operation_id' => Uuid::v4(), 'customer_id' => $customer['id'], 'items' => [['product_id' => $product['id'], 'quantity' => $qty]],
            'payment' => ['mode' => 'credit', 'method' => 'cash', 'amount' => $paidNow]], $token ?? $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function repay(array $o, string $saleId, string $amount, array $extra = [], ?string $token = null): array
    {
        return $this->call('POST', "/sales/$saleId/repayments", $extra + ['client_operation_id' => Uuid::v4(), 'amount' => $amount, 'method' => 'cash'], $token ?? $o['token'], $o['business_id']);
    }

    private function owed(array $o, string $customerId): string
    {
        return $this->call('GET', "/customers/$customerId/debt", null, $o['token'], $o['business_id'])[1]['data']['total_owed'];
    }

    /** customer.total_spent = Σ money received on their non-voided sales + Σ repayments of those sales */
    private function spendInvariant(string $bid): void
    {
        $bad = $this->pdo->query("SELECT c.id, c.total_spent, (SELECT COALESCE(SUM(LEAST(s.amount_paid, s.total_amount)),0) FROM sales s WHERE s.customer_id=c.id AND s.payment_status IN ('pending','completed'))
                + (SELECT COALESCE(SUM(d.amount),0) FROM debt_repayments d JOIN sales s ON s.id=d.sale_id WHERE s.customer_id=c.id AND s.payment_status IN ('pending','completed')) AS expected
            FROM customers c WHERE c.business_id='$bid' HAVING total_spent <> expected")->fetchAll();
        $this->assertSame([], $bad, 'customers.total_spent must equal money actually received');
        $this->assertSame([], $this->pdo->query("SELECT c.id FROM customers c WHERE c.business_id='$bid' AND c.loyalty_points <> (SELECT COALESCE(SUM(points),0) FROM loyalty_ledger WHERE customer_id=c.id)")->fetchAll(), 'points = ledger');
        $this->assertSame([], $this->pdo->query("SELECT s.id FROM sales s WHERE s.business_id='$bid' AND s.payment_status='pending' AND s.total_amount - s.amount_paid - COALESCE((SELECT SUM(amount) FROM debt_repayments WHERE sale_id=s.id),0) < 0.01")->fetchAll(), 'no pending sale may have a zero balance');
        $this->assertSame([], $this->pdo->query("SELECT s.id FROM sales s WHERE s.business_id='$bid' AND s.payment_status='completed' AND s.is_credit=1 AND s.total_amount - s.amount_paid - COALESCE((SELECT SUM(amount) FROM debt_repayments WHERE sale_id=s.id),0) >= 0.01")->fetchAll(), 'no completed credit sale may still be owing');
    }

    // ===================================================================================================== repay
    public function testRepaymentsReduceTheBalanceAndCompleteTheSaleAtZero(): void
    {
        $o = $this->shop();
        $p = $this->product($o, '100.00');
        $c = $this->customer($o);
        $this->call('PUT', '/businesses/' . $o['business_id'] . '/loyalty-settings', ['enabled' => true, 'spend_per_point' => '100', 'point_value' => '10'], $o['token']);
        $sale = $this->creditSale($o, $p, $c, 3, '50');            // total 300, paid 50, owes 250
        $this->assertSame('250.00', $sale['balance_due']);
        [$s, $j] = $this->repay($o, $sale['id'], '100', ['method' => 'transfer', 'notes' => 'part payment']);
        $this->assertSame([201, '100.00', 'bank_transfer', '250.00', '150.00', 'pending'], [$s, $j['data']['repayment']['amount'], $j['data']['repayment']['payment_method'], $j['data']['sale']['previous_balance'], $j['data']['sale']['balance_due'], $j['data']['sale']['status']]);
        $doc = $j['data']['document'];
        $this->assertSame(['payment_receipt', 'PAYMENT RECEIPT', 'PART PAYMENT', '150.00', 'Bank transfer', 'RCT-' . substr($sale['receipt_number'], 5) . '-P' . substr($doc['number'], strrpos($doc['number'], '-P') + 2)], [$doc['kind'], $doc['title'], $doc['status']['label'], $doc['balanceDue'], $doc['paymentMethod'], $doc['number']]);
        $this->assertSame(['100.00', '250.00', '150.00'], [$doc['payment']['amount'], $doc['payment']['previousBalance'], $doc['payment']['newBalance']]);
        $this->assertStringContainsString('Remaining balance', $doc['share_message']);
        $this->assertSame('150.00', $this->owed($o, $c['id']));
        [$s, $j] = $this->repay($o, $sale['id'], '150');
        $this->assertSame([201, '0.00', 'completed'], [$s, $j['data']['sale']['balance_due'], $j['data']['sale']['status']]);
        $this->assertSame('FULLY PAID', $j['data']['document']['status']['label']);
        $this->assertStringContainsString('fully paid', $j['data']['document']['share_message']);
        [, $d] = $this->call('GET', '/sales/' . $sale['id'], null, $o['token'], $o['business_id']);
        $this->assertSame(['completed', '250.00', '300.00', '0.00'], [$d['data']['status'], $d['data']['repaid'], $d['data']['paid_so_far'], $d['data']['balance_due']]);
        [, $cu] = $this->call('GET', '/customers/' . $c['id'], null, $o['token'], $o['business_id']);
        $this->assertSame(['300.00', 1, 3], [$cu['data']['total_spent'], $cu['data']['visit_count'], $cu['data']['loyalty_points']], 'money received 300 at 100/point; repayments do not count as a new visit');
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM loyalty_ledger WHERE note <> 'Sale' AND note <> 'Payment on a credit sale'")->fetchColumn());
        $this->assertSame('0.00', $this->owed($o, $c['id']));
        $this->spendInvariant($o['business_id']);
    }

    public function testRepaymentValidationAndStateGuards(): void
    {
        $o = $this->shop();
        $p = $this->product($o);
        $c = $this->customer($o);
        $sale = $this->creditSale($o, $p, $c, 3, '50');             // owes 250
        $id = $sale['id'];
        foreach (['0', '-5', '250.01', '1.005', 'abc', ''] as $bad) {
            [$s, $j] = $this->repay($o, $id, $bad);
            $this->assertSame(422, $s, "amount '$bad'");
        }
        [$s, $j] = $this->repay($o, $id, '250.01');
        $this->assertStringContainsString('more than what they owe (250.00)', $j['error']['fields']['amount'][0]);
        foreach ([['method' => 'bitcoin'], ['notes' => str_repeat('x', 301)], ['customer_id' => Uuid::v4()], ['balance' => '0'], ['sale_id' => $id], ['created_at' => '2020-01-01']] as $extra) {
            $this->assertSame(422, $this->repay($o, $id, '10', $extra)[0], json_encode($extra));
        }
        $this->assertSame(422, $this->call('POST', "/sales/$id/repayments", ['amount' => '10'], $o['token'], $o['business_id'])[0], 'operation id is required');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM debt_repayments')->fetchColumn(), 'nothing recorded by any rejected request');
        // not owing: a fully paid sale, a sale without customer, a voided sale
        [, $paid] = $this->call('POST', '/sales', ['client_operation_id' => Uuid::v4(), 'items' => [['product_id' => $p['id'], 'quantity' => 1]], 'payment' => ['mode' => 'full', 'amount' => '100']], $o['token'], $o['business_id']);
        [$s, $j] = $this->repay($o, $paid['data']['id'], '10');
        $this->assertSame([409, 'not_open'], [$s, $j['error']['code']]);
        $this->call('POST', "/sales/$id/void", ['reason' => 'entered by mistake'], $o['token'], $o['business_id']);
        [$s, $j] = $this->repay($o, $id, '10');
        $this->assertSame([409, 'not_open'], [$s, $j['error']['code']]);
        $this->assertSame(404, $this->repay($o, Uuid::v4(), '10')[0]);
        $this->assertSame(404, $this->repay($o, 'not-a-uuid', '10')[0]);
    }

    public function testPenniesAndExactBalance(): void
    {
        $o = $this->shop();
        $p = $this->product($o, '0.03');
        $c = $this->customer($o);
        $sale = $this->creditSale($o, $p, $c, 1, '0.02');          // owes 0.01
        [$s, $j] = $this->repay($o, $sale['id'], '0.01');
        $this->assertSame([201, 'completed', '0.00'], [$s, $j['data']['sale']['status'], $j['data']['sale']['balance_due']]);
        $this->spendInvariant($o['business_id']);
    }

    public function testRolesAndTenantIsolation(): void
    {
        $o = $this->shop('a@example.com');
        $b = $this->shop('b@example.com');
        $p = $this->product($o);
        $c = $this->customer($o);
        $sale = $this->creditSale($o, $p, $c);
        $cashier = $this->addStaff($o, 'cash@example.com', 'cashier');
        $mgr = $this->addStaff($o, 'mgr@example.com', 'manager');
        $this->assertSame(403, $this->repay($o, $sale['id'], '10', [], $cashier['token'])[0]);
        foreach (['/credit', "/customers/{$c['id']}/debt", "/customers/{$c['id']}/statement"] as $path) {
            $this->assertSame(403, $this->call('GET', $path, null, $cashier['token'], $o['business_id'])[0], "cashier $path");
            $this->assertSame(200, $this->call('GET', $path, null, $mgr['token'], $o['business_id'])[0], "manager $path");
        }
        $this->assertSame(201, $this->repay($o, $sale['id'], '10', [], $mgr['token'])[0]);
        // another business
        $this->assertSame(404, $this->repay($b, $sale['id'], '10')[0], "B cannot pay A's debt");
        foreach (["/customers/{$c['id']}/debt", "/customers/{$c['id']}/statement", "/customers/{$c['id']}/sales"] as $path) {
            $this->assertSame(404, $this->call('GET', $path, null, $b['token'], $b['business_id'])[0], "B $path");
        }
        [, $l, $res] = $this->call('GET', '/credit', null, $b['token'], $b['business_id']);
        $this->assertSame([[], '0.00'], [$l['data'], $l['meta']['summary']['total_owed']]);
        $this->assertStringNotContainsString('Ada Obi', $res->body);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM debt_repayments')->fetchColumn());
    }

    // ===================================================================================================== idempotency + concurrency
    public function testSameOperationIdIsReplayedWithExactHistoricalBalances(): void
    {
        $o = $this->shop();
        $p = $this->product($o);
        $c = $this->customer($o);
        $sale = $this->creditSale($o, $p, $c, 3, '50');            // owes 250
        $op1 = Uuid::v4();
        [$s1, $j1] = $this->repay($o, $sale['id'], '100', ['client_operation_id' => $op1]);
        $this->repay($o, $sale['id'], '60');                        // a later payment
        [$s2, $j2] = $this->repay($o, $sale['id'], '100', ['client_operation_id' => $op1]);
        $this->assertSame([201, 200, true, $j1['data']['repayment']['id']], [$s1, $s2, $j2['meta']['replayed'], $j2['data']['repayment']['id']]);
        $this->assertSame(['250.00', '150.00'], [$j2['data']['sale']['previous_balance'], $j2['data']['sale']['balance_due']], 'the replay shows the balances AT THAT PAYMENT, not today\'s');
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM debt_repayments')->fetchColumn());
        $this->assertSame('90.00', $this->owed($o, $c['id']));
        [$s3, $j3] = $this->repay($o, $sale['id'], '101', ['client_operation_id' => $op1]);
        $this->assertSame([409, 'idempotency_conflict'], [$s3, $j3['error']['code']]);
        [, $j4] = $this->call('POST', "/sales/{$sale['id']}/repayments", ['amount' => '10', 'method' => 'cash'], $o['token'], $o['business_id'], ['Idempotency-Key' => Uuid::v4()]);
        $this->assertSame('80.00', $j4['data']['sale']['balance_due'], 'Idempotency-Key header works');
        $this->spendInvariant($o['business_id']);
    }

    /** @param list<list<array<string,mixed>>> $perWorker @return list<array{0:int,1:int,2:string}> */
    private function parallel(array $o, array $perWorker): array
    {
        $env = array_merge($_ENV, getenv());
        $startAt = (string) (microtime(true) + 2.5);
        $procs = [];
        foreach ($perWorker as $reqs) {
            $pipes = [];
            $proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/request_worker.php', $o['token'], $o['business_id'], $startAt, json_encode($reqs)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        $out = [];
        foreach ($procs as [$proc, $pipes]) {
            $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
            proc_close($proc);
            $this->assertSame('', trim($stderr), "worker error: $stderr");
            foreach (array_filter(explode("\n", trim($stdout))) as $line) { [$st, $rep, $what] = explode(' ', $line, 3); $out[] = [(int) $st, (int) $rep, $what]; }
        }
        return $out;
    }

    public function testConcurrentPaymentsCanNeverExceedTheBalance(): void
    {
        $o = $this->shop();
        $p = $this->product($o, '100.00');
        $c = $this->customer($o);
        $sale = $this->creditSale($o, $p, $c, 1, '0');              // owes 100
        $workers = [];
        for ($w = 0; $w < 8; $w++) {
            $workers[] = [['method' => 'POST', 'path' => "/sales/{$sale['id']}/repayments", 'body' => ['client_operation_id' => Uuid::v4(), 'amount' => '30.00', 'method' => 'cash']]];
        }
        $res = $this->parallel($o, $workers);
        $ok = array_filter($res, fn ($r) => $r[0] === 201);
        $this->assertCount(3, $ok, 'only 3 × 30 fit into 100: ' . json_encode($res));
        $this->assertSame([], array_filter($res, fn ($r) => !in_array($r[0], [201, 422], true)), 'the others are refused cleanly (never 500): ' . json_encode($res));
        $this->assertSame('90.00', (string) $this->pdo->query("SELECT SUM(amount) FROM debt_repayments WHERE sale_id='{$sale['id']}'")->fetchColumn());
        $this->assertSame('10.00', $this->owed($o, $c['id']));
        $this->spendInvariant($o['business_id']);
    }

    public function testFiveSimultaneousPayOffsExactlyOneWins(): void
    {
        $o = $this->shop();
        $p = $this->product($o, '100.00');
        $c = $this->customer($o);
        $sale = $this->creditSale($o, $p, $c, 1, '0');
        $workers = [];
        for ($w = 0; $w < 5; $w++) { $workers[] = [['method' => 'POST', 'path' => "/sales/{$sale['id']}/repayments", 'body' => ['client_operation_id' => Uuid::v4(), 'amount' => '100.00']]]; }
        $res = $this->parallel($o, $workers);
        $this->assertCount(1, array_filter($res, fn ($r) => $r[0] === 201), json_encode($res));
        $this->assertSame(4, count(array_filter($res, fn ($r) => $r[0] === 409 && $r[2] === 'not_open')), json_encode($res));
        $this->assertSame('completed', $this->pdo->query("SELECT payment_status FROM sales WHERE id='{$sale['id']}'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM debt_repayments')->fetchColumn());
        $this->spendInvariant($o['business_id']);
    }

    public function testSixParallelRetriesOfTheSamePaymentRecordItOnce(): void
    {
        $o = $this->shop();
        $p = $this->product($o, '100.00');
        $c = $this->customer($o);
        $sale = $this->creditSale($o, $p, $c, 1, '0');
        $req = ['method' => 'POST', 'path' => "/sales/{$sale['id']}/repayments", 'body' => ['client_operation_id' => Uuid::v4(), 'amount' => '40.00']];
        $res = $this->parallel($o, array_fill(0, 6, [$req]));
        $this->assertSame([], array_filter($res, fn ($r) => !in_array($r[0], [200, 201], true)), json_encode($res));
        $this->assertCount(1, array_unique(array_column($res, 2)));
        $this->assertCount(1, array_filter($res, fn ($r) => $r[0] === 201));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM debt_repayments')->fetchColumn());
        $this->assertSame('60.00', $this->owed($o, $c['id']));
        $this->spendInvariant($o['business_id']);
    }

    // ===================================================================================================== credit list
    public function testCreditListGroupsByCustomerSortsByDebtAndAppliesTheSourceOverdueRules(): void
    {
        $o = $this->shop();
        $p = $this->product($o, '100.00');
        $ada = $this->customer($o, 'Ada Obi', '08031111111');
        $bola = $this->customer($o, 'Bola Ade', '08032222222');
        $cy = $this->customer($o, 'Cy Paid', '08033333333');
        $s1 = $this->creditSale($o, $p, $ada, 2, '0');             // 200
        $s2 = $this->creditSale($o, $p, $ada, 1, '0');             // 100
        $s3 = $this->creditSale($o, $p, $bola, 5, '100');          // 500 - 100 = 400
        $s4 = $this->creditSale($o, $p, $cy, 1, '0');
        $this->repay($o, $s4['id'], '100');                         // settled -> hidden
        $this->repay($o, $s2['id'], '40');                          // ada: 200 + 60
        $this->pdo->exec("UPDATE sales SET created_at = DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 31 DAY) WHERE id='{$s1['id']}'");
        $this->pdo->exec("UPDATE sales SET created_at = DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 15 DAY) WHERE id='{$s2['id']}'");
        [$s, $l] = $this->call('GET', '/credit', null, $o['token'], $o['business_id']);
        $this->assertSame(200, $s);
        $rows = $l['data'];
        $this->assertSame(['Bola Ade', 'Ada Obi'], array_column($rows, 'name'), 'biggest debt first: Bola 400 > Ada 260');
        $this->assertSame(['400.00', '260.00'], array_column($rows, 'total_owed'));
        $ada = $rows[1];
        $this->assertSame([31, true, 2], [$ada['oldest_days'], $ada['is_overdue'], count($ada['sales'])]);
        $this->assertSame([$s1['id'], $s2['id']], array_column($ada['sales'], 'id'), 'oldest first within a customer');
        $this->assertSame(['overdue', 'two_weeks'], array_column($ada['sales'], 'badge'));
        $this->assertSame(['60.00', '40.00', 1], [$ada['sales'][1]['balance_due'], $ada['sales'][1]['total_repaid'], count($ada['sales'][1]['repayments'])]);
        $this->assertSame(['660.00', '200.00', 2, 3], [$l['meta']['summary']['total_owed'], $l['meta']['summary']['overdue_owed'], $l['meta']['summary']['customers'], $l['meta']['summary']['open_sales']]);
        $this->assertSame(['Ada Obi'], array_column($this->call('GET', '/credit?overdue=1', null, $o['token'], $o['business_id'])[1]['data'], 'name'));
        $this->assertSame(['Bola Ade'], array_column($this->call('GET', '/credit?search=bola', null, $o['token'], $o['business_id'])[1]['data'], 'name'));
        $this->assertSame(['Bola Ade'], array_column($this->call('GET', '/credit?search=0803222', null, $o['token'], $o['business_id'])[1]['data'], 'name'));
        $this->assertSame([], $this->call('GET', '/credit?search=' . rawurlencode('%'), null, $o['token'], $o['business_id'])[1]['data']);
        $this->assertCount(1, $this->call('GET', '/credit?per_page=1', null, $o['token'], $o['business_id'])[1]['data']);
        foreach (['?overdue=maybe', '?customer_id=zzz', '?bogus=1', '?per_page=0'] as $bad) { $this->assertSame(422, $this->call('GET', '/credit' . $bad, null, $o['token'], $o['business_id'])[0], $bad); }
        // voiding removes a debt from the list
        $this->call('POST', "/sales/{$s3['id']}/void", ['reason' => 'cancelled order'], $o['token'], $o['business_id']);
        [, $l] = $this->call('GET', '/credit', null, $o['token'], $o['business_id']);
        $this->assertSame(['Ada Obi'], array_column($l['data'], 'name'));
        $this->spendInvariant($o['business_id']);
    }

    /** Boundaries from credit/page.tsx: 13 d = plain, 14 d = "2 weeks", 29 d = "2 weeks", 30 d = "Overdue!". */
    public function testBadgeAndOverdueBoundariesAreExact(): void
    {
        $o = $this->shop();
        $p = $this->product($o, '10.00');
        $c = $this->customer($o);
        $ids = [];
        foreach ([13, 14, 29, 30] as $days) {
            $sale = $this->creditSale($o, $p, $c, 1, '0');
            $this->pdo->exec("UPDATE sales SET created_at = DATE_SUB(UTC_TIMESTAMP(3), INTERVAL $days DAY) WHERE id='{$sale['id']}'");
            $ids[$days] = $sale['id'];
        }
        [, $l] = $this->call('GET', '/credit', null, $o['token'], $o['business_id']);
        $badges = array_column($l['data'][0]['sales'], 'badge', 'id');
        $this->assertSame(['recent', 'two_weeks', 'two_weeks', 'overdue'], [$badges[$ids[13]], $badges[$ids[14]], $badges[$ids[29]], $badges[$ids[30]]]);
        $this->assertSame('10.00', $l['meta']['summary']['overdue_owed'], 'only the 30-day-old sale counts as overdue money');
        $this->assertTrue($l['data'][0]['is_overdue']);
    }

    /** The source skips rows whose balance is already ≤ 0.005 even if they are still marked pending (legacy / imported data). */
    public function testPendingSalesWithNoBalanceAreNotListedAsDebts(): void
    {
        $o = $this->shop();
        $p = $this->product($o, '100.00');
        $c = $this->customer($o);
        $sale = $this->creditSale($o, $p, $c, 1, '0');
        $this->pdo->exec("UPDATE sales SET amount_paid = total_amount WHERE id='{$sale['id']}'");     // inconsistent legacy row: pending but fully paid
        [, $l] = $this->call('GET', '/credit', null, $o['token'], $o['business_id']);
        $this->assertSame([[], '0.00'], [$l['data'], $l['meta']['summary']['total_owed']]);
        $this->assertSame('0.00', $this->owed($o, $c['id']));
        $this->assertSame(200, $this->call('DELETE', '/customers/' . $c['id'], null, $o['token'], $o['business_id'])[0], 'no real debt => may be archived');
    }

    public function testOutletRestrictedMembersSeeOnlyTheirOutletsDebts(): void
    {
        $o = $this->shop();
        $p = $this->product($o);
        $c = $this->customer($o);
        [, $loc] = $this->call('POST', '/locations', ['name' => 'Branch'], $o['token'], $o['business_id']);
        $this->call('POST', '/stock/adjustments', ['product_id' => $p['id'], 'location_id' => $loc['data']['id'], 'delta' => 10, 'reason' => 'found'], $o['token'], $o['business_id']);
        $mainSale = $this->creditSale($o, $p, $c, 1, '0');
        [, $branchSale] = $this->call('POST', '/sales', ['client_operation_id' => Uuid::v4(), 'outlet_id' => $loc['data']['id'], 'customer_id' => $c['id'], 'items' => [['product_id' => $p['id'], 'quantity' => 1]], 'payment' => ['mode' => 'credit', 'amount' => '0']], $o['token'], $o['business_id']);
        $m = $this->addStaff($o, 'branch@example.com', 'manager');
        $this->pdo->prepare('INSERT INTO member_outlets (member_id, location_id) VALUES (?,?)')->execute([$m['member_id'], $loc['data']['id']]);
        [, $l] = $this->call('GET', '/credit', null, $m['token'], $o['business_id']);
        $this->assertSame([$branchSale['data']['id']], array_column($l['data'][0]['sales'], 'id'));
        $this->assertSame(404, $this->repay($o, $mainSale['id'], '10', [], $m['token'])[0], 'cannot take payment on a sale of another outlet');
        $this->assertSame(201, $this->repay($o, $branchSale['data']['id'], '10', [], $m['token'])[0]);
    }

    // ===================================================================================================== statement / debt / archive guard
    public function testStatementRunningBalanceAlwaysEndsAtTheCurrentDebt(): void
    {
        $o = $this->shop();
        $p = $this->product($o, '100.00');
        $c = $this->customer($o);
        $a = $this->creditSale($o, $p, $c, 3, '50');               // +300 -50
        $b = $this->creditSale($o, $p, $c, 2, '0');                // +200
        $this->repay($o, $a['id'], '100');                          // -100
        $this->repay($o, $b['id'], '200');                          // -200  (b settled, still a credit sale)
        $v = $this->creditSale($o, $p, $c, 1, '0');
        $this->call('POST', "/sales/{$v['id']}/void", ['reason' => 'oops'], $o['token'], $o['business_id']);
        // pin the timeline: day 1 = a, day 2 = b + its payment, day 3 = payment on a
        $this->pdo->exec("UPDATE sales SET created_at='2026-03-01 09:00:00.000' WHERE id='{$a['id']}'");
        $this->pdo->exec("UPDATE sales SET created_at='2026-03-02 09:00:00.000' WHERE id='{$b['id']}'");
        $this->pdo->exec("UPDATE debt_repayments SET created_at='2026-03-02 15:00:00.000' WHERE sale_id='{$b['id']}'");
        $this->pdo->exec("UPDATE debt_repayments SET created_at='2026-03-03 15:00:00.000' WHERE sale_id='{$a['id']}'");
        [$s, $r] = $this->call('GET', "/customers/{$c['id']}/statement", null, $o['token'], $o['business_id']);
        $this->assertSame(200, $s);
        $d = $r['data'];
        $this->assertSame(['sale', 'paid_at_sale', 'sale', 'repayment', 'repayment'], array_column($d['lines'], 'type'), 'voided sale is not on the statement');
        $this->assertSame(['300.00', '250.00', '450.00', '250.00', '150.00'], array_column($d['lines'], 'balance'));
        $this->assertSame(['0.00', '150.00', '150.00'], [$d['opening_balance'], $d['closing_balance'], $d['current_balance']]);
        $this->assertSame('150.00', $this->owed($o, $c['id']));
        [, $r] = $this->call('GET', "/customers/{$c['id']}/statement?from=2026-03-02&to=2026-03-02", null, $o['token'], $o['business_id']);
        $this->assertSame(['250.00', '250.00'], [$r['data']['opening_balance'], $r['data']['lines'][1]['balance']], 'opening balance = everything before the range');
        $this->assertSame(['sale', 'repayment'], array_column($r['data']['lines'], 'type'));
        $this->assertSame('250.00', $r['data']['closing_balance']);
        [, $r] = $this->call('GET', "/customers/{$c['id']}/statement?from=2026-04-01", null, $o['token'], $o['business_id']);
        $this->assertSame([[], '150.00', '150.00'], [$r['data']['lines'], $r['data']['opening_balance'], $r['data']['closing_balance']], 'an empty range still reports where the account stands');
        foreach (['?from=yesterday', '?to=2026-13-01', '?x=1'] as $bad) { $this->assertSame(422, $this->call('GET', "/customers/{$c['id']}/statement$bad", null, $o['token'], $o['business_id'])[0], $bad); }
    }

    public function testCustomerWhoOwesMoneyCannotBeArchivedUntilClear(): void
    {
        $o = $this->shop();
        $p = $this->product($o);
        $c = $this->customer($o);
        $sale = $this->creditSale($o, $p, $c, 1, '0');
        [$s, $j] = $this->call('DELETE', '/customers/' . $c['id'], null, $o['token'], $o['business_id']);
        $this->assertSame([409, 'customer_owes_money'], [$s, $j['error']['code']]);
        $this->assertStringContainsString('100.00', $j['error']['message']);
        $this->repay($o, $sale['id'], '99.99');
        $this->assertSame(409, $this->call('DELETE', '/customers/' . $c['id'], null, $o['token'], $o['business_id'])[0], 'one kobo still owed');
        $this->repay($o, $sale['id'], '0.01');
        $this->assertSame(200, $this->call('DELETE', '/customers/' . $c['id'], null, $o['token'], $o['business_id'])[0]);
        $c2 = $this->customer($o, 'Dee', '08039999999');
        $s2 = $this->creditSale($o, $p, $c2, 1, '0');
        $this->call('POST', "/sales/{$s2['id']}/void", ['reason' => 'wrong customer'], $o['token'], $o['business_id']);
        $this->assertSame(200, $this->call('DELETE', '/customers/' . $c2['id'], null, $o['token'], $o['business_id'])[0], 'a voided sale no longer counts as debt');
    }

    public function testCustomerSalesEndpointListsOnlyThatCustomer(): void
    {
        $o = $this->shop();
        $p = $this->product($o);
        $c1 = $this->customer($o, 'One', '08030000001');
        $c2 = $this->customer($o, 'Two', '08030000002');
        $x = $this->creditSale($o, $p, $c1);
        $this->creditSale($o, $p, $c2);
        $cashier = $this->addStaff($o, 'cash@example.com', 'cashier');
        [$s, $l] = $this->call('GET', "/customers/{$c1['id']}/sales", null, $cashier['token'], $o['business_id']);
        $this->assertSame([200, [$x['id']]], [$s, array_column($l['data'], 'id')]);
    }

    // ===================================================================================================== edit / void against REAL repayments (S-10)
    public function testEditAfterRepaymentsUsesPaidSoFarAndRefusesStaleScreens(): void
    {
        $o = $this->shop();
        $p = $this->product($o, '100.00');
        $c = $this->customer($o);
        $sale = $this->creditSale($o, $p, $c, 3, '50');             // total 300, upfront 50
        $this->repay($o, $sale['id'], '100');                       // paid so far 150, owes 150
        [, $d] = $this->call('GET', '/sales/' . $sale['id'], null, $o['token'], $o['business_id']);
        $this->assertSame(['100.00', '150.00', '150.00'], [$d['data']['repaid'], $d['data']['paid_so_far'], $d['data']['balance_due']]);
        $line = [['product_id' => $p['id'], 'quantity' => 3, 'unit_price' => '100']];
        [$s, $j] = $this->call('POST', "/sales/{$sale['id']}/edit", ['items' => $line, 'expected_paid' => '50.00'], $o['token'], $o['business_id']);
        $this->assertSame([409, 'stale_resource'], [$s, $j['error']['code']], 'the screen predates the repayment');
        // reduce to 1 unit: total 100 < paid 150 -> refund 50, upfront 50 -> 0, repaid stays 100 => nothing owed
        [$s, $j] = $this->call('POST', "/sales/{$sale['id']}/edit", ['items' => [['product_id' => $p['id'], 'quantity' => 1, 'unit_price' => '100']], 'expected_paid' => '150.00', 'expected_updated_at' => $d['data']['updated_at']], $o['token'], $o['business_id']);
        $this->assertSame(200, $s, json_encode($j));
        $this->assertSame(['100.00', '50.00', '0.00', '0.00', '100.00', 'completed'], [$j['data']['total'], $j['data']['refund_due'], $j['data']['amount_paid'], $j['data']['balance_due'], $j['data']['repaid'], $j['data']['payment_status']]);
        $this->spendInvariant($o['business_id']);
        [, $cu] = $this->call('GET', '/customers/' . $c['id'], null, $o['token'], $o['business_id']);
        $this->assertSame('100.00', $cu['data']['total_spent'], 'only 100 of the money received is still earned');
        // raise it again to 4 units with a part payment: total 400, paid so far 100 -> extra 300; customer pays 100 now, 200 goes on account
        [, $d2] = $this->call('GET', '/sales/' . $sale['id'], null, $o['token'], $o['business_id']);
        [$s, $j] = $this->call('POST', "/sales/{$sale['id']}/edit", ['items' => [['product_id' => $p['id'], 'quantity' => 4, 'unit_price' => '100']], 'extra_paid' => '100', 'extra_method' => 'cash', 'expected_paid' => $d2['data']['paid_so_far']], $o['token'], $o['business_id']);
        $this->assertSame([200, '400.00', '100.00', '200.00', 'pending'], [$s, $j['data']['total'], $j['data']['amount_paid'], $j['data']['balance_due'], $j['data']['payment_status']]);
        $this->spendInvariant($o['business_id']);
        $this->assertSame('200.00', $this->owed($o, $c['id']));
        $this->assertSame(201, $this->repay($o, $sale['id'], '200')[0], 'the new balance can be paid off');
        $this->assertSame('completed', $this->pdo->query("SELECT payment_status FROM sales WHERE id='{$sale['id']}'")->fetchColumn());
        $this->spendInvariant($o['business_id']);
    }

    public function testVoidAfterRepaymentsReturnsEverythingAndKeepsPaymentHistory(): void
    {
        $o = $this->shop();
        $p = $this->product($o, '100.00', 10);
        $c = $this->customer($o);
        $this->call('PUT', '/businesses/' . $o['business_id'] . '/loyalty-settings', ['enabled' => true, 'spend_per_point' => '100', 'point_value' => '10'], $o['token']);
        $sale = $this->creditSale($o, $p, $c, 3, '50');
        $this->repay($o, $sale['id'], '100');
        $this->repay($o, $sale['id'], '25');
        $this->spendInvariant($o['business_id']);
        [$s, $j] = $this->call('POST', "/sales/{$sale['id']}/void", ['reason' => 'customer cancelled the order'], $o['token'], $o['business_id']);
        $this->assertSame([200, '175.00'], [$s, $j['data']['refund_due']], 'upfront 50 + repayments 125 are owed back');
        [, $cu] = $this->call('GET', '/customers/' . $c['id'], null, $o['token'], $o['business_id']);
        $this->assertSame(['0.00', 0, 0], [$cu['data']['total_spent'], $cu['data']['visit_count'], $cu['data']['loyalty_points']]);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM debt_repayments')->fetchColumn(), 'repayment history is never deleted');
        $this->assertSame(10, (int) $this->pdo->query("SELECT stock_quantity FROM products WHERE id='{$p['id']}'")->fetchColumn());
        [$s, $j] = $this->repay($o, $sale['id'], '10');
        $this->assertSame([409, 'not_open'], [$s, $j['error']['code']]);
        $this->assertSame('0.00', $this->owed($o, $c['id']));
        $this->assertSame([], $this->call('GET', '/credit', null, $o['token'], $o['business_id'])[1]['data']);
        $this->spendInvariant($o['business_id']);
    }
}
