<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Db;
use LeenTrack\Core\Uuid;

final class TransferTest extends ApiTestCase
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

    private function branch(array $o, string $name = 'Branch'): string
    {
        [$s, $j] = $this->call('POST', '/locations', ['name' => $name], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data']['id'];
    }

    /** product with $stock units at the default outlet */
    private function product(array $o, string $name, int $stock = 0, string $type = 'product'): array
    {
        [$s, $j] = $this->call('POST', '/products', ['name' => $name, 'selling_price' => '50.00', 'cost_price' => '10.00', 'stock_quantity' => $stock, 'item_type' => $type], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    private function at(string $pid, string $loc): int
    {
        return (int) $this->pdo->query("SELECT COALESCE(SUM(quantity),0) FROM stock_levels WHERE product_id='$pid' AND location_id='$loc'")->fetchColumn();
    }

    private function total(string $pid): int
    {
        return (int) $this->pdo->query("SELECT stock_quantity FROM products WHERE id='$pid'")->fetchColumn();
    }

    private function create(array $o, string $from, string $to, array $items, array $extra = []): array
    {
        [$s, $j] = $this->call('POST', '/transfers', $extra + ['from_location_id' => $from, 'to_location_id' => $to, 'items' => $items], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    private function act(array $o, string $id, string $what, array $body = []): array
    {
        return $this->call('POST', "/transfers/$id/$what", $body + ['client_operation_id' => Uuid::v4()], $o['token'], $o['business_id']);
    }

    private function invariant(string $bid): void
    {
        $this->assertSame([], $this->pdo->query("SELECT p.id FROM products p LEFT JOIN stock_levels l ON l.product_id=p.id WHERE p.business_id='$bid' GROUP BY p.id, p.stock_quantity HAVING p.stock_quantity <> COALESCE(SUM(l.quantity),0)")->fetchAll(), 'total = Σ levels');
        $this->assertSame([], $this->pdo->query("SELECT l.product_id FROM stock_levels l LEFT JOIN (SELECT product_id, location_id, SUM(delta) d FROM stock_movements GROUP BY product_id, location_id) m ON m.product_id=l.product_id AND m.location_id=l.location_id WHERE l.business_id='$bid' AND l.quantity <> COALESCE(m.d,0)")->fetchAll(), 'level = Σ movements');
        $this->assertSame([], $this->pdo->query("SELECT quantity FROM stock_levels WHERE business_id='$bid' AND quantity < 0")->fetchAll(), 'no negative stock anywhere');
    }

    public function testCompleteTransferMovesStockAndConservesIt(): void
    {
        $o = $this->shop();
        $main = $this->main($o); $br = $this->branch($o);
        $a = $this->product($o, 'A', 20); $b = $this->product($o, 'B', 5);
        $t = $this->create($o, $main, $br, [['product_id' => $a['id'], 'quantity' => 8], ['product_id' => $b['id'], 'quantity' => 5]], ['notes' => 'weekly restock']);
        $this->assertSame(['TR-000001', 'pending', 'weekly restock', 'Main', 'Branch', true, null], [$t['transfer_number'], $t['status'], $t['notes'], $t['from_name'], $t['to_name'], $t['can_edit'], $t['items'][0]['quantity_shipped']]);
        $this->assertSame([20, 0], [$this->at($a['id'], $main), $this->at($a['id'], $br)], 'creating moves nothing');
        [$s, $j] = $this->act($o, $t['id'], 'dispatch');
        $this->assertSame([201, 'in_transit', false, false], [$s, $j['data']['status'], $j['data']['can_edit'], $j['meta']['replayed']], json_encode($j));
        $this->assertSame([12, 0, 12], [$this->at($a['id'], $main), $this->at($a['id'], $br), $this->total($a['id'])], 'source debited at dispatch; goods in transit belong to no outlet yet');
        [$s, $j] = $this->act($o, $t['id'], 'receive');
        $this->assertSame([201, 'completed', 0], [$s, $j['data']['status'], $j['data']['variance_units']]);
        $this->assertSame([12, 8, 20], [$this->at($a['id'], $main), $this->at($a['id'], $br), $this->total($a['id'])], 'conserved: 12 + 8 = 20');
        $this->assertSame([0, 5, 5], [$this->at($b['id'], $main), $this->at($b['id'], $br), $this->total($b['id'])]);
        $m = $this->pdo->query("SELECT delta,reason,ref_type,ref_id FROM stock_movements WHERE product_id='{$a['id']}' AND reason LIKE 'transfer%' ORDER BY id")->fetchAll();
        $this->assertSame([[-8, 'transfer_out', 'transfer', $t['id']], [8, 'transfer_in', 'transfer', $t['id']]], array_map(fn ($r) => [(int) $r['delta'], $r['reason'], $r['ref_type'], $r['ref_id']], $m));
        $this->assertNotNull($j['data']['shipped_at']); $this->assertNotNull($j['data']['received_at']);
        $this->assertSame('TR-000002', $this->create($o, $main, $br, [['product_id' => $a['id'], 'quantity' => 1]])['transfer_number']);
        $this->invariant($o['business_id']);
    }

    public function testPartialShipmentAndShortfallAreRecordedAsVariance(): void
    {
        $o = $this->shop();
        $main = $this->main($o); $br = $this->branch($o);
        $a = $this->product($o, 'A', 20); $b = $this->product($o, 'B', 10);
        $t = $this->create($o, $main, $br, [['product_id' => $a['id'], 'quantity' => 10], ['product_id' => $b['id'], 'quantity' => 4]]);
        [$ia, $ib] = array_column($t['items'], 'id');
        // ship less than requested; the omitted line ships in full
        [$s, $j] = $this->act($o, $t['id'], 'dispatch', ['items' => [['item_id' => $ia, 'quantity' => 6]]]);
        $this->assertSame(201, $s, json_encode($j));
        $this->assertSame([6, 4, 14, 6], [$j['data']['items'][0]['quantity_shipped'], $j['data']['items'][1]['quantity_shipped'], $this->at($a['id'], $main), $this->at($b['id'], $main)]);
        // only 5 of the 6 arrive, one box of B is lost
        [$s, $j] = $this->act($o, $t['id'], 'receive', ['items' => [['item_id' => $ia, 'quantity' => 5], ['item_id' => $ib, 'quantity' => 3]]]);
        $this->assertSame([201, 2, [1, 1], [5, 3]], [$s, $j['data']['variance_units'], array_column($j['data']['items'], 'variance'), array_column($j['data']['items'], 'quantity_received')]);
        $this->assertSame([5, 3], [$this->at($a['id'], $br), $this->at($b['id'], $br)]);
        $this->assertSame(30 - 2, $this->total($a['id']) + $this->total($b['id']), 'started with 30; the 2 lost units are reported as variance, not hidden');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='transfer.receive' AND JSON_EXTRACT(after_json, '$.variance_units') = 2")->fetchColumn(), 'the shortfall is in the audit log');
        $this->invariant($o['business_id']);
    }

    public function testDispatchIsAllOrNothingAndLimitedByRealStock(): void
    {
        $o = $this->shop();
        $main = $this->main($o); $br = $this->branch($o);
        $a = $this->product($o, 'A', 10); $b = $this->product($o, 'B', 2);
        $t = $this->create($o, $main, $br, [['product_id' => $a['id'], 'quantity' => 5], ['product_id' => $b['id'], 'quantity' => 3]]);
        [$s, $j] = $this->act($o, $t['id'], 'dispatch');
        $this->assertSame([409, 'insufficient_stock'], [$s, $j['error']['code']]);
        $this->assertSame([10, 2], [$this->at($a['id'], $main), $this->at($b['id'], $main)], 'nothing left the shelf');
        $this->assertSame('pending', $this->call('GET', "/transfers/{$t['id']}", null, $o['token'], $o['business_id'])[1]['data']['status']);
        $this->assertNull($this->pdo->query("SELECT dispatch_operation_id FROM transfers")->fetchColumn(), 'a failed attempt is not remembered as done');
        // stock sold meanwhile: the draft can still be sent at a smaller quantity
        [$s] = $this->act($o, $t['id'], 'dispatch', ['items' => [['item_id' => $t['items'][1]['id'], 'quantity' => 2]]]);
        $this->assertSame(201, $s);
        $this->assertSame([5, 0], [$this->at($a['id'], $main), $this->at($b['id'], $main)]);
        $this->invariant($o['business_id']);
    }

    public function testBoundsStatusMachineAndCancel(): void
    {
        $o = $this->shop();
        $main = $this->main($o); $br = $this->branch($o);
        $a = $this->product($o, 'A', 10);
        $t = $this->create($o, $main, $br, [['product_id' => $a['id'], 'quantity' => 6]]);
        $iid = $t['items'][0]['id'];
        $this->assertSame('invalid_status', $this->act($o, $t['id'], 'receive')[1]['error']['code'], 'cannot receive what was not sent');
        foreach ([['items' => []], ['items' => [['item_id' => $iid, 'quantity' => 7]]], ['items' => [['item_id' => $iid, 'quantity' => -1]]], ['items' => [['item_id' => Uuid::v4(), 'quantity' => 1]]], ['items' => [['item_id' => $iid, 'quantity' => 1], ['item_id' => $iid, 'quantity' => 1]]], ['items' => [['item_id' => $iid, 'quantity' => 1, 'x' => 1]]], ['status' => 'completed'], ['items' => 'all']] as $bad) {
            $this->assertContains($this->act($o, $t['id'], 'dispatch', $bad)[0], [409, 422], json_encode($bad));
        }
        $this->assertSame(422, $this->act($o, $t['id'], 'dispatch', ['items' => [['item_id' => $iid, 'quantity' => 0]]])[0], 'sending nothing is refused');
        $this->assertSame(422, $this->call('POST', "/transfers/{$t['id']}/dispatch", [], $o['token'], $o['business_id'])[0], 'operation id required');
        $this->assertSame(10, $this->at($a['id'], $main));
        $this->act($o, $t['id'], 'dispatch');
        $this->assertSame('invalid_status', $this->act($o, $t['id'], 'dispatch')[1]['error']['code'], 'cannot send twice');
        $this->assertSame('over_quantity', $this->act($o, $t['id'], 'receive', ['items' => [['item_id' => $iid, 'quantity' => 7]]])[1]['error']['code'], 'cannot receive more than was sent');
        // cancelling while in transit returns the stock to the sender
        [$s, $j] = $this->call('POST', "/transfers/{$t['id']}/cancel", null, $o['token'], $o['business_id']);
        $this->assertSame([200, 'cancelled', 10, 0], [$s, $j['data']['status'], $this->at($a['id'], $main), $this->at($a['id'], $br)]);
        $this->assertSame(['transfer_out', 'transfer_return'], $this->pdo->query("SELECT reason FROM stock_movements WHERE reason LIKE 'transfer%' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
        foreach (['dispatch', 'receive'] as $w) { $this->assertSame('invalid_status', $this->act($o, $t['id'], $w)[1]['error']['code'], "cancelled is final: $w"); }
        $this->assertSame('invalid_status', $this->call('POST', "/transfers/{$t['id']}/cancel", null, $o['token'], $o['business_id'])[1]['error']['code']);
        // cancelling a pending one moves nothing; a completed one cannot be cancelled
        $p = $this->create($o, $main, $br, [['product_id' => $a['id'], 'quantity' => 3]]);
        $this->assertSame([200, 10], [$this->call('POST', "/transfers/{$p['id']}/cancel", null, $o['token'], $o['business_id'])[0], $this->at($a['id'], $main)]);
        $d = $this->create($o, $main, $br, [['product_id' => $a['id'], 'quantity' => 3]]);
        $this->act($o, $d['id'], 'dispatch'); $this->act($o, $d['id'], 'receive');
        $this->assertSame('invalid_status', $this->call('POST', "/transfers/{$d['id']}/cancel", null, $o['token'], $o['business_id'])[1]['error']['code'], 'completed transfers are history');
        $this->assertSame([7, 3, 10], [$this->at($a['id'], $main), $this->at($a['id'], $br), $this->total($a['id'])]);
        $this->invariant($o['business_id']);
    }

    public function testCreateAndEditValidation(): void
    {
        $o = $this->shop();
        $main = $this->main($o); $br = $this->branch($o);
        $a = $this->product($o, 'A', 10); $svc = $this->product($o, 'Svc', 0, 'service');
        $ok = [['product_id' => $a['id'], 'quantity' => 1]];
        $bad = [
            'same outlet' => ['from_location_id' => $main, 'to_location_id' => $main, 'items' => $ok], 'no items' => ['from_location_id' => $main, 'to_location_id' => $br, 'items' => []],
            'unknown outlet' => ['from_location_id' => $main, 'to_location_id' => Uuid::v4(), 'items' => $ok], 'missing from' => ['to_location_id' => $br, 'items' => $ok],
            'qty 0' => ['from_location_id' => $main, 'to_location_id' => $br, 'items' => [['product_id' => $a['id'], 'quantity' => 0]]],
            'duplicate' => ['from_location_id' => $main, 'to_location_id' => $br, 'items' => [['product_id' => $a['id'], 'quantity' => 1], ['product_id' => $a['id'], 'quantity' => 1]]],
            'service' => ['from_location_id' => $main, 'to_location_id' => $br, 'items' => [['product_id' => $svc['id'], 'quantity' => 1]]],
            'unknown product' => ['from_location_id' => $main, 'to_location_id' => $br, 'items' => [['product_id' => Uuid::v4(), 'quantity' => 1]]],
            'forged status' => ['from_location_id' => $main, 'to_location_id' => $br, 'items' => $ok, 'status' => 'completed'], 'forged number' => ['from_location_id' => $main, 'to_location_id' => $br, 'items' => $ok, 'transfer_number' => 'X'],
            'item shipped' => ['from_location_id' => $main, 'to_location_id' => $br, 'items' => [['product_id' => $a['id'], 'quantity' => 1, 'quantity_shipped' => 1]]],
        ];
        foreach ($bad as $why => $body) { $this->assertSame(422, $this->call('POST', '/transfers', $body, $o['token'], $o['business_id'])[0], $why); }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM transfers')->fetchColumn());
        $t = $this->create($o, $main, $br, $ok);
        $br2 = $this->branch($o, 'Branch 2');
        [$s, $j] = $this->call('PATCH', "/transfers/{$t['id']}", ['to_location_id' => $br2, 'notes' => 'changed', 'items' => [['product_id' => $a['id'], 'quantity' => 4]]], $o['token'], $o['business_id']);
        $this->assertSame([200, 'Branch 2', 'changed', 4], [$s, $j['data']['to_name'], $j['data']['notes'], $j['data']['items'][0]['quantity_requested']]);
        foreach ([['from_location_id' => $br], ['status' => 'completed'], ['to_location_id' => $main]] as $badPatch) { $this->assertSame(422, $this->call('PATCH', "/transfers/{$t['id']}", $badPatch, $o['token'], $o['business_id'])[0], json_encode($badPatch)); }
        $this->act($o, $t['id'], 'dispatch');
        [$s, $j] = $this->call('PATCH', "/transfers/{$t['id']}", ['notes' => 'late'], $o['token'], $o['business_id']);
        $this->assertSame([409, 'not_pending'], [$s, $j['error']['code']]);
    }

    public function testRetriesAreIdempotent(): void
    {
        $o = $this->shop();
        $main = $this->main($o); $br = $this->branch($o);
        $a = $this->product($o, 'A', 10);
        $t = $this->create($o, $main, $br, [['product_id' => $a['id'], 'quantity' => 6]]);
        $other = $this->create($o, $main, $br, [['product_id' => $a['id'], 'quantity' => 1]]);
        $op = Uuid::v4();
        $call = fn (string $id, string $w, array $body) => $this->call('POST', "/transfers/$id/$w", ['client_operation_id' => $op] + $body, $o['token'], $o['business_id']);
        [$s1, $j1] = $call($t['id'], 'dispatch', []);
        [$s2, $j2] = $call($t['id'], 'dispatch', []);
        $this->assertSame([201, false, 200, true], [$s1, $j1['meta']['replayed'], $s2, $j2['meta']['replayed']]);
        $this->assertSame(4, $this->at($a['id'], $main), 'debited once');
        $this->assertSame([409, 'idempotency_conflict'], (fn ($r) => [$r[0], $r[1]['error']['code']])($call($t['id'], 'dispatch', ['items' => [['item_id' => $t['items'][0]['id'], 'quantity' => 2]]])), 'same id, different content');
        $this->assertSame([409, 'idempotency_conflict'], (fn ($r) => [$r[0], $r[1]['error']['code']])($call($other['id'], 'dispatch', [])), 'same id on another transfer');
        $this->assertSame(4, $this->at($a['id'], $main));
        $rop = Uuid::v4();
        $rc = fn () => $this->call('POST', "/transfers/{$t['id']}/receive", ['client_operation_id' => $rop], $o['token'], $o['business_id']);
        $this->assertSame([201, 200], [$rc()[0], $rc()[0]]);
        $this->assertSame([4, 6], [$this->at($a['id'], $main), $this->at($a['id'], $br)], 'credited once');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reason='transfer_in'")->fetchColumn());
        $this->invariant($o['business_id']);
    }

    public function testRolesTenantsAndOutletRestrictedManager(): void
    {
        $a = $this->shop('a@example.com'); $b = $this->shop('b@example.com');
        $main = $this->main($a); $br = $this->branch($a); $br2 = $this->branch($a, 'Far branch');
        $p = $this->product($a, 'A', 30);
        $t = $this->create($a, $main, $br, [['product_id' => $p['id'], 'quantity' => 5]]);
        $cashier = $this->addStaff($a, 'cash@example.com', 'cashier');
        foreach ([['GET', '/transfers'], ['POST', '/transfers'], ['GET', "/transfers/{$t['id']}"], ['PATCH', "/transfers/{$t['id']}"], ['POST', "/transfers/{$t['id']}/dispatch"], ['POST', "/transfers/{$t['id']}/receive"], ['POST', "/transfers/{$t['id']}/cancel"]] as [$m, $path]) {
            $this->assertSame(403, $this->call($m, $path, $m === 'GET' ? null : ['x' => 1], $cashier['token'], $a['business_id'])[0], "cashier $m $path");
            if (str_contains($path, $t['id'])) { $this->assertSame(404, $this->call($m, $path, $m === 'GET' ? null : ['client_operation_id' => Uuid::v4()], $b['token'], $b['business_id'])[0], "B $m $path"); }
        }
        $this->assertSame([], $this->call('GET', '/transfers', null, $b['token'], $b['business_id'])[1]['data']);
        $this->assertSame(422, $this->call('POST', '/transfers', ['from_location_id' => $main, 'to_location_id' => $br, 'items' => [['product_id' => $p['id'], 'quantity' => 1]]], $b['token'], $b['business_id'])[0], "A's outlets/products are not B's");
        // a manager assigned only to Branch
        $m = $this->addStaff($a, 'bm@example.com', 'manager');
        $this->pdo->prepare('INSERT INTO member_outlets (member_id, location_id) VALUES (?,?)')->execute([$m['member_id'], $br]);
        $far = $this->create($a, $main, $br2, [['product_id' => $p['id'], 'quantity' => 1]]);           // touches neither end of theirs? Main -> Far branch
        $this->assertSame([$t['id']], array_column($this->call('GET', '/transfers', null, $m['token'], $a['business_id'])[1]['data'], 'id'), 'only transfers touching their outlet');
        $this->assertSame(404, $this->call('GET', "/transfers/{$far['id']}", null, $m['token'], $a['business_id'])[0]);
        $this->assertSame(404, $this->call('GET', "/transfers?location_id=$main", null, $m['token'], $a['business_id'])[0]);
        [$s] = $this->call('POST', "/transfers/{$t['id']}/dispatch", ['client_operation_id' => Uuid::v4()], $m['token'], $a['business_id']);
        $this->assertSame(403, $s, 'the receiving manager cannot send from Main');
        $this->assertSame(201, $this->act($a, $t['id'], 'dispatch')[0]);
        $this->assertSame(201, $this->call('POST', "/transfers/{$t['id']}/receive", ['client_operation_id' => Uuid::v4()], $m['token'], $a['business_id'])[0], 'the destination manager receives');
        $this->assertSame(422, $this->call('POST', '/transfers', ['from_location_id' => $main, 'to_location_id' => $br, 'items' => [['product_id' => $p['id'], 'quantity' => 1]]], $m['token'], $a['business_id'])[0], 'cannot send from an outlet they do not manage');
        $this->assertSame(201, $this->call('POST', '/transfers', ['from_location_id' => $br, 'to_location_id' => $main, 'items' => [['product_id' => $p['id'], 'quantity' => 1]]], $m['token'], $a['business_id'])[0], 'can send from their own');
        $this->invariant($a['business_id']);
    }

    public function testListFilters(): void
    {
        $o = $this->shop();
        $main = $this->main($o); $br = $this->branch($o); $br2 = $this->branch($o, 'Two');
        $a = $this->product($o, 'A', 50);
        $t1 = $this->create($o, $main, $br, [['product_id' => $a['id'], 'quantity' => 1]]);
        $t2 = $this->create($o, $main, $br2, [['product_id' => $a['id'], 'quantity' => 1]]);
        $this->act($o, $t2['id'], 'dispatch');
        $q = fn (string $qs) => $this->call('GET', '/transfers' . $qs, null, $o['token'], $o['business_id']);
        $this->assertSame([$t2['id']], array_column($q('?status=in_transit')[1]['data'], 'id'));
        $this->assertSame([$t1['id']], array_column($q("?location_id=$br")[1]['data'], 'id'));
        $this->assertSame([$t2['id']], array_column($q('?search=' . $t2['transfer_number'])[1]['data'], 'id'));
        $by = $q('')[1]['meta']['summary']['by_status']; ksort($by);
        $this->assertSame(['in_transit' => 1, 'pending' => 1], $by);
        foreach (['?status=x', '?location_id=zz', '?from=today', '?bogus=1', '?per_page=0'] as $bad) { $this->assertSame(422, $q($bad)[0], $bad); }
    }

    // ================================================================================================ parallel
    /** @return array{0:resource,1:array} */
    private function spawn(array $o, array $reqs, string $startAt): array
    {
        $env = array_merge($_ENV, getenv(), ['TEST_DB_NAME' => getenv('TEST_DB_NAME'), 'TEST_DB_USER' => getenv('TEST_DB_USER'), 'TEST_DB_PASS' => getenv('TEST_DB_PASS'), 'TEST_DB_SOCKET' => getenv('TEST_DB_SOCKET')]);
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/request_worker.php', $o['token'], $o['business_id'], $startAt, json_encode($reqs)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc);
        return [$proc, $pipes];
    }

    /** @return list<string> */
    private function collect(array $procs): array
    {
        $lines = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            proc_close($proc);
            $this->assertSame('', trim($err), "worker stderr: $err");
            foreach (array_filter(explode("\n", trim($out))) as $l) { $lines[] = $l; }
        }
        return $lines;
    }

    public function testParallelTransfersCompetingForTheSameStockNeverOversend(): void
    {
        $o = $this->shop();
        $main = $this->main($o); $br = $this->branch($o);
        $a = $this->product($o, 'A', 10);
        $ids = [];
        for ($i = 0; $i < 8; $i++) { $ids[] = $this->create($o, $main, $br, [['product_id' => $a['id'], 'quantity' => 4]])['id']; }
        $startAt = (string) (microtime(true) + 2.0);
        $procs = [];
        foreach ($ids as $id) { $procs[] = $this->spawn($o, [['method' => 'POST', 'path' => "/transfers/$id/dispatch", 'body' => ['client_operation_id' => Uuid::v4()]]], $startAt); }
        $codes = array_count_values(array_map(fn ($l) => explode(' ', $l)[0], $this->collect($procs)));
        ksort($codes);
        $this->assertSame([201 => 2, 409 => 6], $codes, '10 units, 4 each: exactly two can leave: ' . json_encode($codes));
        $this->assertSame(2, $this->at($a['id'], $main), 'two units left behind');
        $this->assertSame(2, $this->total($a['id']));
        $this->invariant($o['business_id']);
    }

    public function testParallelDuplicateDispatchAndReceiveApplyOnce(): void
    {
        $o = $this->shop();
        $main = $this->main($o); $br = $this->branch($o);
        $a = $this->product($o, 'A', 10);
        $t = $this->create($o, $main, $br, [['product_id' => $a['id'], 'quantity' => 6]]);
        $startAt = (string) (microtime(true) + 2.0);
        $procs = [];
        for ($w = 0; $w < 8; $w++) { $procs[] = $this->spawn($o, [['method' => 'POST', 'path' => "/transfers/{$t['id']}/dispatch", 'body' => ['client_operation_id' => Uuid::v4()]]], $startAt); }
        $codes = array_count_values(array_map(fn ($l) => explode(' ', $l)[0], $this->collect($procs)));
        ksort($codes);
        $this->assertSame([201 => 1, 409 => 7], $codes, 'different op ids: one wins, the rest see it already sent');
        $this->assertSame(4, $this->at($a['id'], $main), 'debited once');
        $startAt = (string) (microtime(true) + 2.0);
        $op = Uuid::v4(); $procs = [];
        for ($w = 0; $w < 8; $w++) { $procs[] = $this->spawn($o, [['method' => 'POST', 'path' => "/transfers/{$t['id']}/receive", 'body' => ['client_operation_id' => $op]]], $startAt); }
        $codes = array_count_values(array_map(fn ($l) => substr($l, 0, 5), $this->collect($procs)));
        ksort($codes);
        $this->assertSame(['200 1' => 7, '201 0' => 1], $codes, 'same op id: one applied, seven replays');
        $this->assertSame([4, 6, 10], [$this->at($a['id'], $main), $this->at($a['id'], $br), $this->total($a['id'])]);
        $this->invariant($o['business_id']);
    }

    public function testParallelOppositeTransfersDoNotDeadlockOrLoseStock(): void
    {
        $o = $this->shop();
        $main = $this->main($o); $br = $this->branch($o);
        $a = $this->product($o, 'A', 50); $b = $this->product($o, 'B', 50);
        $this->call('POST', '/stock/adjustments', ['product_id' => $a['id'], 'location_id' => $br, 'delta' => 50, 'reason' => 'found'], $o['token'], $o['business_id']);
        $this->call('POST', '/stock/adjustments', ['product_id' => $b['id'], 'location_id' => $br, 'delta' => 50, 'reason' => 'found'], $o['token'], $o['business_id']);
        $before = $this->total($a['id']) + $this->total($b['id']);
        $ids = [];
        for ($i = 0; $i < 4; $i++) {      // main->branch lists A,B ; branch->main lists B,A  (opposite order on purpose)
            $ids[] = $this->create($o, $main, $br, [['product_id' => $a['id'], 'quantity' => 3], ['product_id' => $b['id'], 'quantity' => 2]])['id'];
            $ids[] = $this->create($o, $br, $main, [['product_id' => $b['id'], 'quantity' => 2], ['product_id' => $a['id'], 'quantity' => 3]])['id'];
        }
        $startAt = (string) (microtime(true) + 2.0);
        $procs = [];
        foreach ($ids as $id) { $procs[] = $this->spawn($o, [['method' => 'POST', 'path' => "/transfers/$id/dispatch", 'body' => ['client_operation_id' => Uuid::v4()]], ['method' => 'POST', 'path' => "/transfers/$id/receive", 'body' => ['client_operation_id' => Uuid::v4()]]], $startAt); }
        $codes = array_count_values(array_map(fn ($l) => explode(' ', $l)[0], $this->collect($procs)));
        $this->assertSame([201 => 16], $codes, 'every dispatch and receive succeeded, no deadlock error: ' . json_encode($codes));
        $this->assertSame($before, $this->total($a['id']) + $this->total($b['id']), 'nothing created or destroyed');
        $this->assertSame([50, 50, 50, 50], [$this->at($a['id'], $main), $this->at($a['id'], $br), $this->at($b['id'], $main), $this->at($b['id'], $br)], 'balanced opposite flows net to zero');
        $this->invariant($o['business_id']);
    }

    /** Same technique as the checkout lock-order test: hold the HIGHER product lock, dispatch a transfer listing [high, low]; the worker must already hold `low`. */
    public function testProductsAreLockedInAscendingIdOrderRegardlessOfLineOrder(): void
    {
        $o = $this->shop();
        $main = $this->main($o); $br = $this->branch($o);
        $p1 = $this->product($o, 'One', 50); $p2 = $this->product($o, 'Two', 50);
        [$low, $high] = $p1['id'] < $p2['id'] ? [$p1['id'], $p2['id']] : [$p2['id'], $p1['id']];
        $t = $this->create($o, $main, $br, [['product_id' => $high, 'quantity' => 1], ['product_id' => $low, 'quantity' => 1]]);
        $holder = Db::connect(self::cfg());
        $holder->beginTransaction();
        $holder->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE')->execute([$high]);
        $proc = $this->spawn($o, [['method' => 'POST', 'path' => "/transfers/{$t['id']}/dispatch", 'body' => ['client_operation_id' => Uuid::v4()]]], (string) microtime(true));
        $heldLow = false;
        for ($i = 0; $i < 40 && !$heldLow; $i++) {
            usleep(200_000);
            $probe = Db::connect(self::cfg());
            $probe->beginTransaction();
            try { $probe->query("SELECT id FROM products WHERE id = '$low' FOR UPDATE NOWAIT"); $probe->rollBack(); } catch (\PDOException $e) {
                $heldLow = (int) ($e->errorInfo[1] ?? 0) === 3572;
                $probe->rollBack();
            }
        }
        $holder->commit();
        $lines = $this->collect([$proc]);
        $this->assertStringStartsWith('201 ', $lines[0] ?? '');
        $this->assertTrue($heldLow, 'the dispatch must lock the lower product id first even though the transfer lists the higher one first');
    }
}
