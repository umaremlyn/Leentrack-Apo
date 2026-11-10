<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Db;
use LeenTrack\Core\Money;
use LeenTrack\Repositories\CustomerRepository;
use LeenTrack\Services\AuditService;
use LeenTrack\Services\LoyaltyService;

final class CustomerTest extends ApiTestCase
{
    private function owner(string $email = 'owner@example.com', string $plan = 'growth'): array
    {
        $o = $this->signup($email);
        $this->setPlan($o['business_id'], $plan);
        return $o;
    }

    /** @return array<string,mixed> */
    private function customer(array $o, array $extra = [], ?string $token = null): array
    {
        [$s, $j] = $this->call('POST', '/customers', $extra + ['name' => 'Cust ' . bin2hex(random_bytes(3))], $token ?? $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    private function loyalty(): LoyaltyService
    {
        return new LoyaltyService($this->pdo, new CustomerRepository($this->pdo), new AuditService($this->pdo));
    }

    /** Runs $fn in a transaction like the sale service will. */
    private function tx(callable $fn): mixed
    {
        return Db::transaction($this->pdo, $fn);
    }

    // ------------------------------------------------------------------------------------------------ customers
    public function testCreateReadUpdateWithValidation(): void
    {
        $o = $this->owner();
        $g = $this->call('POST', '/customer-groups', ['name' => 'VIP', 'discount_percentage' => '10'], $o['token'], $o['business_id'])[1]['data'];
        $c = $this->customer($o, ['name' => 'Ada Obi', 'email' => 'ADA@Example.com', 'phone' => '+234 803 542 4318', 'address' => '1 Market Rd', 'notes' => 'prefers cash', 'date_of_birth' => '1990-05-17', 'group_id' => $g['id']]);
        $this->assertSame(['ada@example.com', 0, '0.00', 0, false, null], [$c['email'], $c['loyalty_points'], $c['total_spent'], $c['visit_count'], $c['is_loyal'], $c['last_visit']]);
        $this->assertSame(['id' => $g['id'], 'name' => 'VIP', 'discount_percentage' => '10.00'], $c['group']);
        [$s, $j] = $this->call('PATCH', '/customers/' . $c['id'], ['name' => 'Ada O.', 'group_id' => null, 'phone' => null], $o['token'], $o['business_id']);
        $this->assertSame([200, 'Ada O.', null, null], [$s, $j['data']['name'], $j['data']['group'], $j['data']['phone']]);
        foreach ([['name' => ''], ['email' => 'nope'], ['phone' => 'abc'], ['date_of_birth' => '2999-01-01'], ['date_of_birth' => '1990-02-30'], ['date_of_birth' => '17/05/1990'], ['group_id' => 'zzz'], ['group_id' => '99999999-9999-4999-8999-999999999999']] as $bad) {
            [$s] = $this->call('PATCH', '/customers/' . $c['id'], $bad, $o['token'], $o['business_id']);
            $this->assertSame(422, $s, json_encode($bad));
        }
        $this->assertSame(422, $this->call('POST', '/customers', [], $o['token'], $o['business_id'])[0]);
    }

    public function testCountersAndPointsCanNeverBeWrittenThroughTheApi(): void
    {
        $o = $this->owner();
        foreach (['loyalty_points' => 9999, 'total_spent' => '1.00', 'visit_count' => 50, 'last_visit' => '2026-01-01', 'business_id' => 'x', 'archived_at' => 'now', 'id' => 'x'] as $k => $v) {
            [$s, $j] = $this->call('POST', '/customers', ['name' => 'Evil', $k => $v], $o['token'], $o['business_id']);
            $this->assertSame(422, $s, "create with $k");
        }
        $c = $this->customer($o);
        foreach (['loyalty_points' => 9999, 'total_spent' => '1.00', 'visit_count' => 50] as $k => $v) {
            [$s] = $this->call('PATCH', '/customers/' . $c['id'], [$k => $v], $o['token'], $o['business_id']);
            $this->assertSame(422, $s, "update with $k");
        }
        $row = $this->pdo->query("SELECT loyalty_points,total_spent,visit_count FROM customers WHERE id='{$c['id']}'")->fetch();
        $this->assertSame([0, '0.00', 0], [(int) $row['loyalty_points'], $row['total_spent'], (int) $row['visit_count']]);
    }

    public function testCashiersCanCreateAndReadButNotChangeOrArchive(): void
    {
        $o = $this->owner();
        $cashier = $this->addStaff($o, 'cashier@example.com', 'cashier');
        $manager = $this->addStaff($o, 'mgr@example.com', 'manager');
        $c = $this->customer($o, ['name' => 'Walk-in']);
        $made = $this->customer($o, ['name' => 'Made by cashier'], $cashier['token']);   // POS quick-add
        [$s, $j] = $this->call('GET', '/customers', null, $cashier['token'], $o['business_id']);
        $this->assertSame([200, 2], [$s, count($j['data'])]);
        $this->assertSame(200, $this->call('GET', '/customers/' . $c['id'], null, $cashier['token'], $o['business_id'])[0]);
        foreach ([['PATCH', '/customers/' . $c['id'], ['name' => 'x']], ['DELETE', '/customers/' . $c['id'], null], ['POST', '/customers/' . $c['id'] . '/restore', null], ['GET', '/customers?archived=1', null]] as $x) {
            $this->assertSame(403, $this->call($x[0], $x[1], $x[2], $cashier['token'], $o['business_id'])[0], "cashier {$x[0]} {$x[1]}");
        }
        $this->assertSame(200, $this->call('PATCH', '/customers/' . $c['id'], ['name' => 'Renamed'], $manager['token'], $o['business_id'])[0]);
        $this->assertNotSame($c['id'], $made['id']);
    }

    public function testArchiveRestoreKeepsTheRowAndHistory(): void
    {
        $o = $this->owner();
        $c = $this->customer($o);
        $this->tx(fn () => $this->loyalty()->apply($o['business_id'], $c['id'], null, Money::parse('30000'), 'seed', $o['user_id']));
        [$s] = $this->call('DELETE', '/customers/' . $c['id'], null, $o['token'], $o['business_id']);
        $this->assertSame(200, $s);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM customers WHERE id='{$c['id']}'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM loyalty_ledger WHERE customer_id='{$c['id']}'")->fetchColumn());
        $this->assertSame(404, $this->call('GET', '/customers/' . $c['id'], null, $o['token'], $o['business_id'])[0]);
        [, $j] = $this->call('GET', '/customers', null, $o['token'], $o['business_id']);
        $this->assertSame([], $j['data']);
        [, $j] = $this->call('GET', '/customers?archived=1', null, $o['token'], $o['business_id']);
        $this->assertSame([$c['id']], array_column($j['data'], 'id'));
        [$s, $j] = $this->call('POST', '/customers/' . $c['id'] . '/restore', null, $o['token'], $o['business_id']);
        $this->assertSame([200, 3], [$s, $j['data']['loyalty_points']], 'points survive archiving');
    }

    public function testCustomerPlanLimit(): void
    {
        $o = $this->owner('lim@example.com', 'free');
        $ins = $this->pdo->prepare("INSERT INTO customers (id,business_id,name) VALUES (UUID(),?,?)");
        for ($i = 0; $i < 100; $i++) { $ins->execute([$o['business_id'], "C$i"]); }
        [$s, $j] = $this->call('POST', '/customers', ['name' => 'C101'], $o['token'], $o['business_id']);
        $this->assertSame([402, 'plan_limit_reached'], [$s, $j['error']['code']]);
        $id = $this->pdo->query('SELECT id FROM customers LIMIT 1')->fetchColumn();
        $this->call('DELETE', "/customers/$id", null, $o['token'], $o['business_id']);
        $this->assertSame(201, $this->call('POST', '/customers', ['name' => 'C101'], $o['token'], $o['business_id'])[0]);
        $this->assertSame(402, $this->call('POST', "/customers/$id/restore", null, $o['token'], $o['business_id'])[0]);
    }

    public function testListSearchSortFilterPaginationAndEscaping(): void
    {
        $o = $this->owner();
        $g = $this->call('POST', '/customer-groups', ['name' => 'Wholesale'], $o['token'], $o['business_id'])[1]['data']['id'];
        $this->customer($o, ['name' => 'Zainab', 'phone' => '08031112222', 'group_id' => $g]);
        $this->customer($o, ['name' => 'Bola', 'email' => 'bola@shop.ng']);
        $this->customer($o, ['name' => '50% Off Club']);
        $q = fn (string $qs) => $this->call('GET', '/customers' . $qs, null, $o['token'], $o['business_id']);
        $names = fn (array $j) => array_column($j['data'], 'name');
        $this->assertSame(['50% Off Club', 'Bola', 'Zainab'], $names($q('')[1]));
        $this->assertSame(['50% Off Club'], $names($q('?search=' . rawurlencode('%'))[1]));
        $this->assertSame(['Zainab'], $names($q('?search=0803111')[1]));
        $this->assertSame(['Bola'], $names($q('?search=SHOP.ng')[1]));
        $this->assertSame(['Zainab'], $names($q("?group_id=$g")[1]));
        $this->assertSame(['Zainab', 'Bola', '50% Off Club'], $names($q('?sort=name&dir=desc')[1]));
        [, $j] = $q('?per_page=2&page=2');
        $this->assertSame([['Zainab'], 3], [$names($j), $j['meta']['total']]);
        foreach (['?sort=notes', '?dir=up', '?per_page=500', '?bogus=1', '?group_id=zzz', '?archived=maybe'] as $bad) { $this->assertSame(422, $q($bad)[0], $bad); }
    }

    // ------------------------------------------------------------------------------------------------ groups
    public function testGroupsReadableByAllWritableByManagersAndDeleteUngroupsMembers(): void
    {
        $o = $this->owner();
        $cashier = $this->addStaff($o, 'cashier@example.com', 'cashier');
        $mgr = $this->addStaff($o, 'mgr@example.com', 'manager');
        [$s, $j] = $this->call('POST', '/customer-groups', ['name' => 'Staff', 'description' => '10% off', 'discount_percentage' => 10], $mgr['token'], $o['business_id']);
        $this->assertSame([201, '10.00'], [$s, $j['data']['discount_percentage']]);
        $gid = $j['data']['id'];
        [$s, $j] = $this->call('GET', '/customer-groups', null, $cashier['token'], $o['business_id']);
        $this->assertSame([200, 'Staff', '10.00'], [$s, $j['data'][0]['name'], $j['data'][0]['discount_percentage']], 'cashiers need group discounts at checkout');
        foreach ([['POST', '/customer-groups', ['name' => 'X']], ['PATCH', "/customer-groups/$gid", ['name' => 'Y']], ['DELETE', "/customer-groups/$gid", null]] as $x) {
            $this->assertSame(403, $this->call($x[0], $x[1], $x[2], $cashier['token'], $o['business_id'])[0], "cashier {$x[0]} {$x[1]}");
        }
        foreach ([['discount_percentage' => '101'], ['discount_percentage' => '-1'], ['discount_percentage' => '12.345'], ['discount_percentage' => 'ten'], ['name' => '']] as $bad) {
            $this->assertSame(422, $this->call('PATCH', "/customer-groups/$gid", $bad + ['name' => 'Staff'], $mgr['token'], $o['business_id'])[0], json_encode($bad));
        }
        $c = $this->customer($o, ['group_id' => $gid]);
        [$s, $j] = $this->call('GET', '/customer-groups', null, $o['token'], $o['business_id']);
        $this->assertSame(1, $j['data'][0]['customer_count']);
        $this->assertSame(200, $this->call('DELETE', "/customer-groups/$gid", null, $mgr['token'], $o['business_id'])[0]);
        [, $j] = $this->call('GET', '/customers/' . $c['id'], null, $o['token'], $o['business_id']);
        $this->assertSame([null, null], [$j['data']['group_id'], $j['data']['group']]);
    }

    // ------------------------------------------------------------------------------------------------ isolation
    public function testNothingLeaksAcrossBusinesses(): void
    {
        $a = $this->owner('a@example.com');
        $b = $this->owner('b@example.com');
        $ga = $this->call('POST', '/customer-groups', ['name' => 'A-VIP', 'discount_percentage' => '50'], $a['token'], $a['business_id'])[1]['data']['id'];
        $ca = $this->customer($a, ['name' => 'A-Secret', 'phone' => '08000000001', 'group_id' => $ga]);
        $this->tx(fn () => $this->loyalty()->apply($a['business_id'], $ca['id'], null, Money::parse('20000'), 'seed', null));
        foreach ([['GET', "/customers/{$ca['id']}"], ['PATCH', "/customers/{$ca['id']}"], ['DELETE', "/customers/{$ca['id']}"], ['POST', "/customers/{$ca['id']}/restore"], ['GET', "/customers/{$ca['id']}/loyalty-ledger"], ['PATCH', "/customer-groups/$ga"], ['DELETE', "/customer-groups/$ga"]] as $x) {
            [$s] = $this->call($x[0], $x[1], $x[0] === 'PATCH' ? ['name' => 'pwn'] : null, $b['token'], $b['business_id']);
            $this->assertSame(404, $s, "{$x[0]} {$x[1]} as B");
        }
        $this->assertSame(422, $this->call('POST', '/customers', ['name' => 'X', 'group_id' => $ga], $b['token'], $b['business_id'])[0], "A's group cannot be attached");
        [, $j, $res] = $this->call('GET', '/customers?search=A-Secret', null, $b['token'], $b['business_id']);
        $this->assertSame([], $j['data']);
        [, $j, $res] = $this->call('GET', '/customer-groups', null, $b['token'], $b['business_id']);
        $this->assertSame([], $j['data']);
        $this->assertStringNotContainsString('A-VIP', $res->body);
        [$s] = $this->call('GET', '/businesses/' . $a['business_id'] . '/loyalty-settings', null, $b['token']);
        $this->assertSame(404, $s);
        [$s] = $this->call('PUT', '/businesses/' . $a['business_id'] . '/loyalty-settings', ['enabled' => false, 'spend_per_point' => '100', 'point_value' => '1'], $b['token']);
        $this->assertSame(404, $s);
        $this->assertSame(2, (int) $this->pdo->query("SELECT loyalty_points FROM customers WHERE id='{$ca['id']}'")->fetchColumn(), 'A untouched');
    }

    // ------------------------------------------------------------------------------------------------ loyalty settings
    public function testLoyaltySettingsDefaultsOwnerOnlyWriteAndSourceValidationMessages(): void
    {
        $o = $this->owner();
        $mgr = $this->addStaff($o, 'mgr@example.com', 'manager');
        $cash = $this->addStaff($o, 'cash@example.com', 'cashier');
        $path = '/businesses/' . $o['business_id'] . '/loyalty-settings';
        [$s, $j] = $this->call('GET', $path, null, $cash['token']);
        $this->assertSame([200, true, '10000.00', '100.00', true], [$s, $j['data']['enabled'], $j['data']['spend_per_point'], $j['data']['point_value'], $j['data']['is_default']], 'defaults match the source; every member can read');
        $this->assertSame(403, $this->call('PUT', $path, ['enabled' => true, 'spend_per_point' => '5000', 'point_value' => '50'], $mgr['token'])[0], 'owner only');
        $this->assertSame(403, $this->call('PUT', $path, ['enabled' => true, 'spend_per_point' => '5000', 'point_value' => '50'], $cash['token'])[0]);
        [$s, $j] = $this->call('PUT', $path, ['enabled' => true, 'spend_per_point' => '5000', 'point_value' => '50.50'], $o['token']);
        $this->assertSame([200, '5000.00', '50.50', false], [$s, $j['data']['spend_per_point'], $j['data']['point_value'], $j['data']['is_default']]);
        [$s, $j] = $this->call('PUT', $path, ['enabled' => false, 'spend_per_point' => '5000', 'point_value' => '50.50'], $o['token']);
        $this->assertFalse($j['data']['enabled']);
        $msgs = fn (array $b) => json_encode($this->call('PUT', $path, $b + ['enabled' => true], $o['token'])[1]['error']['fields'] ?? []);
        $this->assertStringContainsString('1 or more', $msgs(['spend_per_point' => '0.5', 'point_value' => '0']));
        $this->assertStringContainsString('point_value', $msgs(['spend_per_point' => '100', 'point_value' => '-1']), 'a negative point value is rejected on the field');
        $this->assertStringContainsString('cannot be worth more', $msgs(['spend_per_point' => '100', 'point_value' => '100.01']));
        $this->assertSame(422, $this->call('PUT', $path, ['enabled' => true, 'spend_per_point' => '100000001', 'point_value' => '1'], $o['token'])[0]);
        $this->assertSame(422, $this->call('PUT', $path, ['enabled' => true, 'spend_per_point' => '100', 'point_value' => '1', 'updated_by' => 'x'], $o['token'])[0]);
        $this->assertGreaterThanOrEqual(2, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='loyalty.settings'")->fetchColumn(), 'every settings change is audited');
    }

    // ------------------------------------------------------------------------------------------------ ledger rules
    public function testEarnRedeemLedgerAndInvariants(): void
    {
        $o = $this->owner();
        $c = $this->customer($o);
        $L = $this->loyalty();
        $this->assertSame(2, $this->tx(fn () => $L->apply($o['business_id'], $c['id'], null, Money::parse('25000'), 'Sale A', $o['user_id'])));
        $this->assertSame(1, $this->tx(fn () => $L->apply($o['business_id'], $c['id'], null, Money::parse('5000'), 'Sale B', $o['user_id'])), '5000 progress + 5000 = 1 more point');
        $this->assertSame(-1, $this->tx(fn () => $L->apply($o['business_id'], $c['id'], null, Money::parse('-10000'), 'Refund', $o['user_id'])));
        $after = $this->tx(fn () => $L->redeem($o['business_id'], $c['id'], null, 1, Money::parse('100'), 'Used on sale C', $o['user_id']));
        $this->assertSame(1, $after);
        [, $j] = $this->call('GET', '/customers/' . $c['id'], null, $o['token'], $o['business_id']);
        $this->assertSame(1, $j['data']['loyalty_points']);
        [, $j] = $this->call('GET', '/customers/' . $c['id'] . '/loyalty-ledger', null, $o['token'], $o['business_id']);
        $this->assertSame(['redeem', 'earn', 'earn', 'earn'], array_column($j['data'], 'kind'), 'newest first');
        $this->assertSame([-1, -1, 1, 2], array_map('intval', array_column($j['data'], 'points')));
        $this->assertSame(0, array_sum(array_map('intval', array_column($j['data'], 'points'))) - 1, 'balance equals the sum of ledger points');
        $cash = $this->addStaff($o, 'cash2@example.com', 'cashier');
        $this->assertSame(200, $this->call('GET', '/customers/' . $c['id'] . '/loyalty-ledger', null, $cash['token'], $o['business_id'])[0], 'ledger visible to all members (as in the source)');
    }

    public function testRedeemGuards(): void
    {
        $o = $this->owner();
        $c = $this->customer($o);
        $L = $this->loyalty();
        $this->tx(fn () => $L->apply($o['business_id'], $c['id'], null, Money::parse('30000'), 'seed', null));    // 3 points
        $try = function (callable $f): string {
            try { $this->tx($f); return 'no error'; } catch (\LeenTrack\Core\ApiException $e) { return $e->errorCode; }
        };
        $this->assertSame('insufficient_points', $try(fn () => $L->redeem($o['business_id'], $c['id'], null, 4, Money::parse('1000'), 'x', null)));
        $this->assertSame('discount_mismatch', $try(fn () => $L->redeem($o['business_id'], $c['id'], null, 3, Money::parse('299.99'), 'x', null)), '3 points are worth 300.00');
        $this->assertSame('no error', $try(fn () => $L->redeem($o['business_id'], $c['id'], null, 3, Money::parse('300'), 'x', null)));
        $this->assertSame('customer_not_found', $try(fn () => $L->redeem($o['business_id'], '99999999-9999-4999-8999-999999999999', null, 1, Money::parse('100'), 'x', null)));
        $b = $this->owner('other@example.com');
        $this->assertSame('customer_not_found', $try(fn () => $L->redeem($b['business_id'], $c['id'], null, 1, Money::parse('100'), 'x', null)), "another business cannot spend this customer's points");
        $this->call('PUT', '/businesses/' . $o['business_id'] . '/loyalty-settings', ['enabled' => false, 'spend_per_point' => '10000', 'point_value' => '100'], $o['token']);
        $this->assertSame('loyalty_disabled', $try(fn () => $L->redeem($o['business_id'], $c['id'], null, 1, Money::parse('100'), 'x', null)));
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM loyalty_ledger')->fetchColumn();
        $this->assertSame(0, $this->tx(fn () => $L->apply($o['business_id'], $c['id'], null, Money::parse('50000'), 'off', null)), 'disabled loyalty earns nothing');
        $this->assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM loyalty_ledger')->fetchColumn());
        $this->expectException(\LogicException::class);
        $L->apply($o['business_id'], $c['id'], null, Money::parse('1'), 'outside txn', null);
    }

    public function testRandomisedSequencesKeepBalanceEqualToLedgerAndNeverNegative(): void
    {
        $o = $this->owner();
        $c = $this->customer($o);
        $L = $this->loyalty();
        mt_srand(99);
        for ($i = 0; $i < 120; $i++) {
            $money = Money::ofMinor(mt_rand(-4_000_000, 6_000_000));
            $this->tx(fn () => $L->apply($o['business_id'], $c['id'], null, $money, "op $i", null));
            if ($i % 7 === 0) {
                $bal = (int) $this->pdo->query("SELECT loyalty_points FROM customers WHERE id='{$c['id']}'")->fetchColumn();
                if ($bal > 0) { $this->tx(fn () => $L->redeem($o['business_id'], $c['id'], null, mt_rand(1, $bal), Money::parse('100000000'), 'spend', null)); }
            }
            $row = $this->pdo->query("SELECT c.loyalty_points p, (SELECT COALESCE(SUM(points),0) FROM loyalty_ledger WHERE customer_id=c.id) s, (SELECT balance_after FROM loyalty_ledger WHERE customer_id=c.id ORDER BY id DESC LIMIT 1) last FROM customers c WHERE c.id='{$c['id']}'")->fetch();
            $this->assertGreaterThanOrEqual(0, (int) $row['p']);
            $this->assertSame((int) $row['p'], (int) $row['s'], "iteration $i: balance = sum of ledger");
            $this->assertSame((int) $row['p'], (int) $row['last'], "iteration $i: last balance_after = balance");
        }
    }

    public function testParallelProcessesEarnPointsWithoutLostUpdates(): void
    {
        $o = $this->owner();
        $c = $this->customer($o);
        $env = array_merge($_ENV, getenv());
        $startAt = (string) (microtime(true) + 2.0);
        $procs = [];
        for ($w = 0; $w < 6; $w++) {
            $pipes = [];
            $proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/loyalty_worker.php', $o['business_id'], $c['id'], '20', '10000', $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        $done = 0;
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); proc_close($proc);
            $this->assertSame(1, preg_match('/done:(\d+)/', $out, $m), "worker failed: $out $err");
            $done += (int) $m[1];
        }
        $this->assertSame(120, $done);
        $row = $this->pdo->query("SELECT c.loyalty_points p, (SELECT COUNT(*) FROM loyalty_ledger WHERE customer_id=c.id) n, (SELECT SUM(points) FROM loyalty_ledger WHERE customer_id=c.id) s FROM customers c WHERE c.id='{$c['id']}'")->fetch();
        $this->assertSame([120, 120, 120], [(int) $row['p'], (int) $row['n'], (int) $row['s']], '120 × 10,000 at the default rate = exactly 120 points, one ledger row each');
    }
}
