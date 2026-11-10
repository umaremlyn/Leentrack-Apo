<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Uuid;

final class PurchaseTest extends ApiTestCase
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

    private function product(array $o, string $name, string $cost = '10.00', int $stock = 0, string $type = 'product'): array
    {
        [$s, $j] = $this->call('POST', '/products', ['name' => $name, 'selling_price' => '50.00', 'cost_price' => $cost, 'stock_quantity' => $stock, 'item_type' => $type], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    private function supplier(array $o, string $name = 'Acme Wholesale'): array
    {
        [$s, $j] = $this->call('POST', '/suppliers', ['name' => $name], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    /** @param list<array<string,mixed>> $items */
    private function order(array $o, array $items, array $extra = []): array
    {
        [$s, $j] = $this->call('POST', '/purchase-orders', $extra + ['items' => $items], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    private function placed(array $o, array $items, array $extra = []): array
    {
        $p = $this->order($o, $items, $extra);
        [$s, $j] = $this->call('POST', "/purchase-orders/{$p['id']}/order", null, $o['token'], $o['business_id']);
        $this->assertSame(200, $s, json_encode($j));
        return $j['data'];
    }

    private function receive(array $o, string $id, mixed $lines, array $extra = []): array
    {
        return $this->call('POST', "/purchase-orders/$id/receive", $extra + ['client_operation_id' => Uuid::v4(), 'items' => $lines], $o['token'], $o['business_id']);
    }

    private function stockAt(string $pid, string $loc): int
    {
        return (int) $this->pdo->query("SELECT COALESCE(SUM(quantity),0) FROM stock_levels WHERE product_id='$pid' AND location_id='$loc'")->fetchColumn();
    }

    private function invariant(string $bid): void
    {
        $this->assertSame([], $this->pdo->query("SELECT p.id FROM products p LEFT JOIN stock_levels l ON l.product_id=p.id WHERE p.business_id='$bid' GROUP BY p.id, p.stock_quantity HAVING p.stock_quantity <> COALESCE(SUM(l.quantity),0)")->fetchAll(), 'total = Σ levels');
        $this->assertSame([], $this->pdo->query("SELECT l.product_id FROM stock_levels l LEFT JOIN (SELECT product_id, location_id, SUM(delta) d FROM stock_movements GROUP BY product_id, location_id) m ON m.product_id=l.product_id AND m.location_id=l.location_id WHERE l.business_id='$bid' AND l.quantity <> COALESCE(m.d,0)")->fetchAll(), 'level = Σ movements');
        $this->assertSame([], $this->pdo->query("SELECT i.id FROM purchase_order_items i LEFT JOIN (SELECT item_id, SUM(quantity) q FROM purchase_receipt_items GROUP BY item_id) r ON r.item_id=i.id WHERE i.business_id='$bid' AND i.quantity_received <> COALESCE(r.q,0)")->fetchAll(), 'received = Σ receipts');
    }

    // ================================================================================================ suppliers
    public function testSupplierCrudArchiveRestoreSearchValidation(): void
    {
        $o = $this->shop();
        [$s, $j] = $this->call('POST', '/suppliers', ['name' => '  Acme Wholesale ', 'contact_name' => 'Bola', 'phone' => '0803 000 0000', 'email' => 'SALES@ACME.test', 'address' => 'Lagos', 'notes' => 'Net 30'], $o['token'], $o['business_id']);
        $this->assertSame([201, 'Acme Wholesale', 'sales@acme.test', 'Net 30', 0, false], [$s, $j['data']['name'], $j['data']['email'], $j['data']['notes'], $j['data']['order_count'], $j['data']['archived']]);
        $id = $j['data']['id'];
        $this->supplier($o, 'Zed Traders');
        [, $l] = $this->call('GET', '/suppliers', null, $o['token'], $o['business_id']);
        $this->assertSame(['Acme Wholesale', 'Zed Traders'], array_column($l['data'], 'name'));
        $this->assertSame(['Zed Traders'], array_column($this->call('GET', '/suppliers?search=' . rawurlencode('zed'), null, $o['token'], $o['business_id'])[1]['data'], 'name'));
        $this->assertSame([], $this->call('GET', '/suppliers?search=' . rawurlencode('%'), null, $o['token'], $o['business_id'])[1]['data'], 'wildcards are literal');
        [$s, $j] = $this->call('PATCH', "/suppliers/$id", ['phone' => null, 'name' => 'Acme Ltd'], $o['token'], $o['business_id']);
        $this->assertSame([200, 'Acme Ltd', null, 'Bola'], [$s, $j['data']['name'], $j['data']['phone'], $j['data']['contact_name']]);
        foreach ([['name' => ''], ['name' => str_repeat('x', 151)], ['email' => 'nope'], ['id' => 'x'], ['business_id' => 'x'], ['archived_at' => '2020-01-01'], ['order_count' => 5]] as $bad) {
            $this->assertSame(422, $this->call('PATCH', "/suppliers/$id", $bad, $o['token'], $o['business_id'])[0], json_encode($bad));
        }
        $this->assertSame(422, $this->call('POST', '/suppliers', ['contact_name' => 'x'], $o['token'], $o['business_id'])[0]);
        // an archived supplier disappears from lists and cannot take new orders, but old orders keep it
        $p = $this->product($o, 'Rice');
        $po = $this->order($o, [['product_id' => $p['id'], 'quantity' => 2]], ['supplier_id' => $id]);
        $this->assertSame(200, $this->call('DELETE', "/suppliers/$id", null, $o['token'], $o['business_id'])[0]);
        $this->assertSame(['Zed Traders'], array_column($this->call('GET', '/suppliers', null, $o['token'], $o['business_id'])[1]['data'], 'name'));
        $this->assertSame(['Acme Ltd', 'Zed Traders'], array_column($this->call('GET', '/suppliers?archived=1', null, $o['token'], $o['business_id'])[1]['data'], 'name'));
        $this->assertSame(422, $this->call('POST', '/purchase-orders', ['supplier_id' => $id, 'items' => [['product_id' => $p['id'], 'quantity' => 1]]], $o['token'], $o['business_id'])[0]);
        $this->assertSame('Acme Ltd', $this->call('GET', "/purchase-orders/{$po['id']}", null, $o['token'], $o['business_id'])[1]['data']['supplier_name'], 'history keeps the supplier');
        [$s, $j] = $this->call('POST', "/suppliers/$id/restore", null, $o['token'], $o['business_id']);
        $this->assertSame([200, false, 1], [$s, $j['data']['archived'], $j['data']['order_count']]);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='supplier.archive'")->fetchColumn());
        foreach (['?bogus=1', '?archived=2', '?per_page=0'] as $bad) { $this->assertSame(422, $this->call('GET', "/suppliers$bad", null, $o['token'], $o['business_id'])[0], $bad); }
    }

    // ================================================================================================ roles & tenancy
    public function testRolesAndTenantIsolationOnEveryRoute(): void
    {
        $a = $this->shop('a@example.com');
        $b = $this->shop('b@example.com');
        $sup = $this->supplier($a);
        $p = $this->product($a, 'Rice');
        $po = $this->placed($a, [['product_id' => $p['id'], 'quantity' => 5]]);
        $draft = $this->order($a, [['product_id' => $p['id'], 'quantity' => 1]]);
        $cashier = $this->addStaff($a, 'cash@example.com', 'cashier');
        $mgr = $this->addStaff($a, 'mgr@example.com', 'manager');
        $routes = [['GET', '/suppliers'], ['POST', '/suppliers'], ['GET', "/suppliers/{$sup['id']}"], ['PATCH', "/suppliers/{$sup['id']}"], ['DELETE', "/suppliers/{$sup['id']}"], ['POST', "/suppliers/{$sup['id']}/restore"],
            ['GET', '/purchase-orders'], ['POST', '/purchase-orders'], ['GET', "/purchase-orders/{$po['id']}"], ['PATCH', "/purchase-orders/{$draft['id']}"], ['DELETE', "/purchase-orders/{$draft['id']}"],
            ['POST', "/purchase-orders/{$draft['id']}/order"], ['POST', "/purchase-orders/{$po['id']}/cancel"], ['POST', "/purchase-orders/{$po['id']}/close"], ['POST', "/purchase-orders/{$po['id']}/receive"]];
        foreach ($routes as [$m, $path]) {
            $this->assertSame(403, $this->call($m, $path, in_array($m, ['GET', 'DELETE'], true) ? null : ['name' => 'x'], $cashier['token'], $a['business_id'])[0], "cashier $m $path");
        }
        $this->assertSame(200, $this->call('GET', '/purchase-orders', null, $mgr['token'], $a['business_id'])[0]);
        foreach ($routes as [$m, $path]) {
            if (!preg_match('~/(suppliers|purchase-orders)/~', $path)) { continue; }
            $body = str_ends_with($path, '/receive') ? ['client_operation_id' => Uuid::v4(), 'items' => [['item_id' => Uuid::v4(), 'quantity' => 1]]] : ['name' => 'x'];
            [$s] = $this->call($m, $path, in_array($m, ['GET', 'DELETE'], true) ? null : $body, $b['token'], $b['business_id']);
            $this->assertSame(404, $s, "B $m $path");
        }
        $this->assertSame(422, $this->call('POST', '/purchase-orders', ['supplier_id' => $sup['id'], 'items' => [['product_id' => $p['id'], 'quantity' => 1]]], $b['token'], $b['business_id'])[0], "A's supplier/product are not B's");
        $this->assertSame([[], []], [$this->call('GET', '/suppliers', null, $b['token'], $b['business_id'])[1]['data'], $this->call('GET', '/purchase-orders', null, $b['token'], $b['business_id'])[1]['data']]);
        $this->assertSame(0, $this->stockOf($p['id']), 'B could not touch A\'s stock');
    }

    private function stockOf(string $pid): int
    {
        return (int) $this->pdo->query("SELECT stock_quantity FROM products WHERE id='$pid'")->fetchColumn();
    }

    // ================================================================================================ order creation / editing
    public function testCreateComputesEverythingOnTheServerAndValidates(): void
    {
        $o = $this->shop();
        $rice = $this->product($o, 'Rice', '60.50');
        $svc = $this->product($o, 'Delivery', '0', 0, 'service');
        $sup = $this->supplier($o);
        [$s, $j] = $this->call('POST', '/purchase-orders', ['supplier_id' => $sup['id'], 'notes' => 'urgent', 'items' => [
            ['product_id' => $rice['id'], 'quantity' => 3],                                  // cost defaults to the product's
            ['product_name' => 'Packaging (not tracked)', 'quantity' => 10, 'unit_cost' => '1.25'],
        ]], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        $d = $j['data'];
        $this->assertSame(['PO-000001', 'draft', '194.00', $this->main($o), 'Acme Wholesale', true], [$d['order_number'], $d['status'], $d['total_cost'], $d['outlet_id'], $d['supplier_name'], $d['can_edit']], '3×60.50 + 10×1.25');
        $this->assertSame(['Rice', '60.50', '181.50', 3, true, 'Packaging (not tracked)', false], [$d['items'][0]['product_name'], $d['items'][0]['unit_cost'], $d['items'][0]['line_total'], $d['items'][0]['outstanding'], $d['items'][0]['stocked'], $d['items'][1]['product_name'], $d['items'][1]['stocked']]);
        $this->assertSame('PO-000002', $this->order($o, [['product_id' => $rice['id'], 'quantity' => 1]])['order_number'], 'server-issued, gap-free');
        $this->assertSame(0, $this->stockOf($rice['id']), 'ordering moves no stock');
        $bad = [
            'no items' => ['items' => []], 'items not a list' => ['items' => ['a' => 1]], 'qty 0' => ['items' => [['product_id' => $rice['id'], 'quantity' => 0]]], 'qty negative' => ['items' => [['product_id' => $rice['id'], 'quantity' => -2]]],
            'qty text' => ['items' => [['product_id' => $rice['id'], 'quantity' => 'x']]], 'qty huge' => ['items' => [['product_id' => $rice['id'], 'quantity' => 1000001]]],
            'cost 3dp' => ['items' => [['product_id' => $rice['id'], 'quantity' => 1, 'unit_cost' => '1.001']]], 'cost negative' => ['items' => [['product_id' => $rice['id'], 'quantity' => 1, 'unit_cost' => '-1']]],
            'duplicate product' => ['items' => [['product_id' => $rice['id'], 'quantity' => 1], ['product_id' => $rice['id'], 'quantity' => 2]]],
            'service' => ['items' => [['product_id' => $svc['id'], 'quantity' => 1]]], 'unknown product' => ['items' => [['product_id' => Uuid::v4(), 'quantity' => 1]]],
            'free line without a name' => ['items' => [['quantity' => 1, 'unit_cost' => '1']]], 'item extra key' => ['items' => [['product_id' => $rice['id'], 'quantity' => 1, 'line_total' => '1']]],
            'unknown supplier' => ['supplier_id' => Uuid::v4(), 'items' => [['product_id' => $rice['id'], 'quantity' => 1]]],
            'unknown outlet' => ['outlet_id' => Uuid::v4(), 'items' => [['product_id' => $rice['id'], 'quantity' => 1]]],
            'client total' => ['total_cost' => '1', 'items' => [['product_id' => $rice['id'], 'quantity' => 1]]], 'client status' => ['status' => 'received', 'items' => [['product_id' => $rice['id'], 'quantity' => 1]]],
            'client number' => ['order_number' => 'PO-1', 'items' => [['product_id' => $rice['id'], 'quantity' => 1]]], 'client business' => ['business_id' => Uuid::v4(), 'items' => [['product_id' => $rice['id'], 'quantity' => 1]]],
            'overflow' => ['items' => [['product_name' => 'x', 'quantity' => 1000000, 'unit_cost' => '99999999999.99']]],
        ];
        foreach ($bad as $why => $body) { $this->assertSame(422, $this->call('POST', '/purchase-orders', $body, $o['token'], $o['business_id'])[0], $why); }
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn(), 'nothing half-created by the failures');
        // an archived product cannot be ordered
        $this->call('DELETE', "/products/{$rice['id']}", null, $o['token'], $o['business_id']);
        $this->assertSame(422, $this->call('POST', '/purchase-orders', ['items' => [['product_id' => $rice['id'], 'quantity' => 1]]], $o['token'], $o['business_id'])[0]);
    }

    public function testDraftEditDeleteAndPlacing(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'A', '5.00'); $b = $this->product($o, 'B', '7.00');
        $d = $this->order($o, [['product_id' => $a['id'], 'quantity' => 2]]);
        [$s, $j] = $this->call('PATCH', "/purchase-orders/{$d['id']}", ['notes' => 'edited', 'items' => [['product_id' => $b['id'], 'quantity' => 4], ['product_id' => $a['id'], 'quantity' => 1, 'unit_cost' => '6.00']]], $o['token'], $o['business_id']);
        $this->assertSame([200, 'edited', '34.00', ['B', 'A']], [$s, $j['data']['notes'], $j['data']['total_cost'], array_column($j['data']['items'], 'product_name')], 'items replaced, total recomputed');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM purchase_orders")->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM purchase_order_items")->fetchColumn(), 'old lines gone');
        $this->assertSame(422, $this->call('PATCH', "/purchase-orders/{$d['id']}", ['total_cost' => '1'], $o['token'], $o['business_id'])[0]);
        // placing
        [$s, $j] = $this->call('POST', "/purchase-orders/{$d['id']}/order", null, $o['token'], $o['business_id']);
        $this->assertSame([200, 'ordered', false], [$s, $j['data']['status'], $j['data']['can_edit']]);
        $this->assertNotNull($j['data']['ordered_at']);
        foreach ([['PATCH', ['notes' => 'x']], ['DELETE', null]] as [$m, $body]) {
            [$s, $j] = $this->call($m, "/purchase-orders/{$d['id']}", $body, $o['token'], $o['business_id']);
            $this->assertSame([409, 'not_draft'], [$s, $j['error']['code']], $m);
        }
        $this->assertSame('invalid_status', $this->call('POST', "/purchase-orders/{$d['id']}/order", null, $o['token'], $o['business_id'])[1]['error']['code'], 'cannot place twice');
        $draft = $this->order($o, [['product_id' => $a['id'], 'quantity' => 1]]);
        $this->assertSame(200, $this->call('DELETE', "/purchase-orders/{$draft['id']}", null, $o['token'], $o['business_id'])[0]);
        $this->assertSame(404, $this->call('GET', "/purchase-orders/{$draft['id']}", null, $o['token'], $o['business_id'])[0]);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id='{$draft['id']}'")->fetchColumn());
    }

    // ================================================================================================ receiving
    public function testPartialThenCompleteReceivingMovesExactlyTheDeliveredStock(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'A', '5.00', 7); $b = $this->product($o, 'B', '7.00');
        $loc = $this->main($o);
        $po = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 10], ['product_id' => $b['id'], 'quantity' => 4], ['product_name' => 'Bags', 'quantity' => 5, 'unit_cost' => '2.00']]);
        [$ia, $ib, $ic] = array_column($po['items'], 'id');
        [$s, $j] = $this->receive($o, $po['id'], [['item_id' => $ia, 'quantity' => 6], ['item_id' => $ib, 'quantity' => 4]]);
        $this->assertSame(201, $s, json_encode($j));
        $this->assertSame(['partially_received', 6, 4, 4, 0, '58.00'], [$j['data']['order']['status'], $j['data']['order']['items'][0]['quantity_received'], $j['data']['order']['items'][1]['quantity_received'], $j['data']['order']['items'][0]['outstanding'], $j['data']['order']['items'][1]['outstanding'], $j['data']['order']['received_value']]);
        $this->assertSame([13, 4], [$this->stockAt($a['id'], $loc), $this->stockAt($b['id'], $loc)], '7+6 and 0+4');
        $m = $this->pdo->query("SELECT delta,reason,ref_type,ref_id,quantity_after FROM stock_movements WHERE product_id='{$a['id']}' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertSame([6, 'purchase', 'purchase_order', $po['id'], 13], [(int) $m['delta'], $m['reason'], $m['ref_type'], $m['ref_id'], (int) $m['quantity_after']]);
        $this->assertNull($j['data']['order']['received_at'], 'not finished yet');
        // the rest arrives: the status completes only when EVERYTHING has
        [$s, $j] = $this->receive($o, $po['id'], [['item_id' => $ia, 'quantity' => 4], ['item_id' => $ic, 'quantity' => 5]]);
        $this->assertSame([201, 'received', 2], [$s, $j['data']['order']['status'], count($j['data']['order']['receipts'])]);
        $this->assertNotNull($j['data']['order']['received_at']);
        $this->assertSame([17, 4], [$this->stockAt($a['id'], $loc), $this->stockAt($b['id'], $loc)]);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reason='purchase' AND product_id IS NULL")->fetchColumn());
        $this->assertSame(3, (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reason='purchase'")->fetchColumn(), 'free-text "Bags" never touches stock');
        $this->invariant($o['business_id']);
        $this->assertSame(['received' => 1], $this->call('GET', '/purchase-orders', null, $o['token'], $o['business_id'])[1]['meta']['summary']['by_status']);
    }

    public function testS08SecondDeliveryCannotDoubleStockOrExceedWhatWasOrdered(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'A', '5.00'); $b = $this->product($o, 'B', '1.00');
        $loc = $this->main($o);
        $po = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 10], ['product_id' => $b['id'], 'quantity' => 2]]);
        [$ia, $ib] = array_column($po['items'], 'id');
        $this->assertSame(201, $this->receive($o, $po['id'], [['item_id' => $ia, 'quantity' => 10]])[0]);
        // the source: calling receive again re-added the stock. Here: nothing outstanding -> refused, stock unchanged.
        [$s, $j] = $this->receive($o, $po['id'], [['item_id' => $ia, 'quantity' => 10]]);
        $this->assertSame([409, 'over_receive'], [$s, $j['error']['code']]);
        $this->assertStringContainsString('already been fully received', json_encode($j['error']['fields']));
        $this->assertSame(10, $this->stockAt($a['id'], $loc));
        [$s, $j] = $this->receive($o, $po['id'], [['item_id' => $ib, 'quantity' => 3]]);
        $this->assertSame([409, 'over_receive'], [$s, $j['error']['code']]);
        $this->assertStringContainsString('Only 2', json_encode($j['error']['fields']));
        // one bad line rejects the whole delivery: nothing from the good line is applied
        [$s] = $this->receive($o, $po['id'], [['item_id' => $ib, 'quantity' => 1], ['item_id' => $ia, 'quantity' => 1]]);
        $this->assertSame(409, $s);
        $this->assertSame([10, 0], [$this->stockAt($a['id'], $loc), $this->stockAt($b['id'], $loc)], 'all-or-nothing');
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM purchase_receipts WHERE client_operation_id IS NOT NULL AND id NOT IN (SELECT receipt_id FROM purchase_receipt_items)")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM purchase_receipts')->fetchColumn(), 'rejected deliveries leave no receipt');
        // item from another order
        $other = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 5]]);
        $this->assertSame('over_receive', $this->receive($o, $po['id'], [['item_id' => $other['items'][0]['id'], 'quantity' => 1]])[1]['error']['code'], 'item ids of other orders are not accepted');
        foreach ([[], [['item_id' => $ib]], [['item_id' => $ib, 'quantity' => 0]], [['item_id' => $ib, 'quantity' => -1]], [['item_id' => 'x', 'quantity' => 1]], [['item_id' => $ib, 'quantity' => 1], ['item_id' => $ib, 'quantity' => 1]], [['item_id' => $ib, 'quantity' => 1, 'unit_cost' => '0']], 'nope'] as $bad) {
            $this->assertSame(422, $this->receive($o, $po['id'], $bad)[0], json_encode($bad));
        }
        $this->assertSame(422, $this->call('POST', "/purchase-orders/{$po['id']}/receive", ['items' => [['item_id' => $ib, 'quantity' => 1]]], $o['token'], $o['business_id'])[0], 'operation id required');
        $this->assertSame(422, $this->receive($o, $po['id'], [['item_id' => $ib, 'quantity' => 1]], ['status' => 'received'])[0]);
        $this->invariant($o['business_id']);
    }

    public function testStatusRulesForReceivingCancellingClosing(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'A', '5.00');
        $draft = $this->order($o, [['product_id' => $a['id'], 'quantity' => 5]]);
        [$s, $j] = $this->receive($o, $draft['id'], [['item_id' => $draft['items'][0]['id'], 'quantity' => 1]]);
        $this->assertSame([409, 'invalid_status'], [$s, $j['error']['code']], 'a draft has not been placed');
        $this->assertSame('invalid_status', $this->call('POST', "/purchase-orders/{$draft['id']}/close", null, $o['token'], $o['business_id'])[1]['error']['code']);
        $this->assertSame(200, $this->call('POST', "/purchase-orders/{$draft['id']}/cancel", null, $o['token'], $o['business_id'])[0], 'a draft can be cancelled');
        $this->assertSame('invalid_status', $this->receive($o, $draft['id'], [['item_id' => $draft['items'][0]['id'], 'quantity' => 1]])[1]['error']['code'], 'cancelled orders receive nothing');
        $this->assertSame('invalid_status', $this->call('POST', "/purchase-orders/{$draft['id']}/order", null, $o['token'], $o['business_id'])[1]['error']['code'], 'cancelled is final');
        $this->assertSame('not_draft', $this->call('DELETE', "/purchase-orders/{$draft['id']}", null, $o['token'], $o['business_id'])[1]['error']['code'], 'history is kept');

        $po = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 5]]);
        $this->assertSame('invalid_status', $this->call('POST', "/purchase-orders/{$po['id']}/close", null, $o['token'], $o['business_id'])[1]['error']['code'], 'nothing received yet: cancel instead');
        $this->assertSame(201, $this->receive($o, $po['id'], [['item_id' => $po['items'][0]['id'], 'quantity' => 2]])[0]);
        $this->assertSame('invalid_status', $this->call('POST', "/purchase-orders/{$po['id']}/cancel", null, $o['token'], $o['business_id'])[1]['error']['code'], 'goods already arrived: cannot cancel');
        [$s, $j] = $this->call('POST', "/purchase-orders/{$po['id']}/close", null, $o['token'], $o['business_id']);
        $this->assertSame([200, 'closed'], [$s, $j['data']['status']]);
        $this->assertNotNull($j['data']['received_at']);
        $this->assertSame('invalid_status', $this->receive($o, $po['id'], [['item_id' => $po['items'][0]['id'], 'quantity' => 1]])[1]['error']['code'], 'closed orders take no more');
        $this->assertSame(2, $this->stockOf($a['id']));
        $placed = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 1]]);
        $this->assertSame('cancelled', $this->call('POST', "/purchase-orders/{$placed['id']}/cancel", null, $o['token'], $o['business_id'])[1]['data']['status'], 'placed with nothing received can be cancelled');
        $this->invariant($o['business_id']);
    }

    public function testReceivingGoesToTheOrdersOutletAndCanUpdateCostPrice(): void
    {
        $o = $this->shop();
        [, $loc] = $this->call('POST', '/locations', ['name' => 'Branch'], $o['token'], $o['business_id']);
        $branch = $loc['data']['id'];
        $a = $this->product($o, 'A', '5.00', 3);
        $po = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 6, 'unit_cost' => '8.00']], ['outlet_id' => $branch]);
        $this->assertSame($branch, $po['outlet_id']);
        $this->assertSame(201, $this->receive($o, $po['id'], [['item_id' => $po['items'][0]['id'], 'quantity' => 2]])[0]);
        $this->assertSame([3, 2], [$this->stockAt($a['id'], $this->main($o)), $this->stockAt($a['id'], $branch)], 'lands at the order\'s outlet');
        $this->assertSame('5.00', $this->call('GET', "/products/{$a['id']}", null, $o['token'], $o['business_id'])[1]['data']['cost_price'], 'cost is not touched unless asked');
        $this->assertSame(201, $this->receive($o, $po['id'], [['item_id' => $po['items'][0]['id'], 'quantity' => 4]], ['update_cost' => true])[0]);
        $this->assertSame('8.00', $this->call('GET', "/products/{$a['id']}", null, $o['token'], $o['business_id'])[1]['data']['cost_price'], 'opt-in cost update');
        $this->assertSame(422, $this->receive($o, $po['id'], [['item_id' => $po['items'][0]['id'], 'quantity' => 1]], ['update_cost' => 'maybe'])[0]);
        $this->invariant($o['business_id']);
    }

    public function testArchivedProductBlocksOnlyItsOwnDeliveryAndRollsBack(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'A'); $b = $this->product($o, 'B');
        $po = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 2], ['product_id' => $b['id'], 'quantity' => 2]]);
        $this->call('DELETE', "/products/{$b['id']}", null, $o['token'], $o['business_id']);
        [$s, $j] = $this->receive($o, $po['id'], [['item_id' => $po['items'][0]['id'], 'quantity' => 1], ['item_id' => $po['items'][1]['id'], 'quantity' => 1]]);
        $this->assertSame([409, 'product_unavailable'], [$s, $j['error']['code']]);
        $this->assertSame([0, 0], [$this->stockOf($a['id']), $this->stockOf($b['id'])], 'rolled back, including the good line');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM purchase_receipts')->fetchColumn());
        $this->assertSame(201, $this->receive($o, $po['id'], [['item_id' => $po['items'][0]['id'], 'quantity' => 2]])[0], 'the available line can still be received alone');
        $this->invariant($o['business_id']);
    }

    // ================================================================================================ idempotency
    public function testRetriesAreIdempotentAndConflictsAreDetected(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'A', '5.00'); $b = $this->product($o, 'B', '5.00');
        $po = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 10]]);
        $po2 = $this->placed($o, [['product_id' => $b['id'], 'quantity' => 10]]);
        $iid = $po['items'][0]['id'];
        $op = Uuid::v4();
        [$s1, $j1, ] = $this->call('POST', "/purchase-orders/{$po['id']}/receive", ['client_operation_id' => $op, 'items' => [['item_id' => $iid, 'quantity' => 4]]], $o['token'], $o['business_id']);
        [$s2, $j2] = $this->call('POST', "/purchase-orders/{$po['id']}/receive", ['client_operation_id' => $op, 'items' => [['item_id' => $iid, 'quantity' => 4]]], $o['token'], $o['business_id']);
        $this->assertSame([201, false, 200, true], [$s1, $j1['meta']['replayed'], $s2, $j2['meta']['replayed']]);
        $this->assertSame($j1['data']['receipt_id'], $j2['data']['receipt_id']);
        $this->assertSame(4, $this->stockOf($a['id']), 'the retry did not add stock again');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM purchase_receipts')->fetchColumn());
        // Idempotency-Key header works the same
        $key = Uuid::v4();
        $r = fn () => $this->callWithHeaders('POST', "/purchase-orders/{$po['id']}/receive", ['items' => [['item_id' => $iid, 'quantity' => 1]]], $o['token'], $o['business_id'], ['HTTP_IDEMPOTENCY_KEY' => $key]);
        $this->assertSame([201, 200], [$r()[0], $r()[0]]);
        $this->assertSame(5, $this->stockOf($a['id']));
        // same id, different content / different order -> refused, never silently applied
        foreach ([[$po['id'], 3], [$po['id'], 5]] as [$oid, $q]) {
            [$s, $j] = $this->call('POST', "/purchase-orders/$oid/receive", ['client_operation_id' => $op, 'items' => [['item_id' => $iid, 'quantity' => $q]]], $o['token'], $o['business_id']);
            $this->assertSame([409, 'idempotency_conflict'], [$s, $j['error']['code']]);
        }
        [$s, $j] = $this->call('POST', "/purchase-orders/{$po2['id']}/receive", ['client_operation_id' => $op, 'items' => [['item_id' => $po2['items'][0]['id'], 'quantity' => 4]]], $o['token'], $o['business_id']);
        $this->assertSame([409, 'idempotency_conflict'], [$s, $j['error']['code']], 'same id on another order');
        $this->assertSame(0, $this->stockOf($b['id']));
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='purchase.receive'")->fetchColumn(), 'one audit entry per accepted delivery, none for replays or refusals');
        $this->invariant($o['business_id']);
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function callWithHeaders(string $method, string $path, ?array $body, string $token, string $bid, array $server): array
    {
        $req = \LeenTrack\Core\Request::fromGlobals($server + ['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/api/v1' . $path, 'REMOTE_ADDR' => $this->ip, 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer $token", 'HTTP_X_BUSINESS_ID' => $bid], $body === null ? '' : json_encode($body));
        $res = $this->kernel->handle($req);
        return [$res->status, json_decode($res->body, true) ?? []];
    }

    // ================================================================================================ outlets
    public function testOutletRestrictedManagerOnlySeesAndReceivesTheirOutlet(): void
    {
        $o = $this->shop();
        [, $loc] = $this->call('POST', '/locations', ['name' => 'Branch'], $o['token'], $o['business_id']);
        $branch = $loc['data']['id'];
        $main = $this->main($o);
        $a = $this->product($o, 'A', '5.00');
        $hq = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 5]], ['outlet_id' => $main]);
        $br = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 5]], ['outlet_id' => $branch]);
        $m = $this->addStaff($o, 'bm@example.com', 'manager');
        $this->pdo->prepare('INSERT INTO member_outlets (member_id, location_id) VALUES (?,?)')->execute([$m['member_id'], $branch]);
        [, $l] = $this->call('GET', '/purchase-orders', null, $m['token'], $o['business_id']);
        $this->assertSame([$br['id']], array_column($l['data'], 'id'));
        $this->assertSame(404, $this->call('GET', "/purchase-orders?outlet_id=$main", null, $m['token'], $o['business_id'])[0]);
        foreach ([['GET', ''], ['POST', '/cancel'], ['POST', '/close']] as [$mth, $suffix]) {
            $this->assertSame(404, $this->call($mth, "/purchase-orders/{$hq['id']}$suffix", null, $m['token'], $o['business_id'])[0], "HQ order $mth $suffix");
        }
        [$s] = $this->call('POST', "/purchase-orders/{$hq['id']}/receive", ['client_operation_id' => Uuid::v4(), 'items' => [['item_id' => $hq['items'][0]['id'], 'quantity' => 1]]], $m['token'], $o['business_id']);
        $this->assertSame(404, $s);
        $this->assertSame(0, $this->stockAt($a['id'], $main));
        $this->assertSame(201, $this->call('POST', "/purchase-orders/{$br['id']}/receive", ['client_operation_id' => Uuid::v4(), 'items' => [['item_id' => $br['items'][0]['id'], 'quantity' => 5]]], $m['token'], $o['business_id'])[0]);
        $this->assertSame(5, $this->stockAt($a['id'], $branch));
        [$s, $j] = $this->call('POST', '/purchase-orders', ['items' => [['product_id' => $a['id'], 'quantity' => 1]]], $m['token'], $o['business_id']);
        $this->assertSame([201, $branch], [$s, $j['data']['outlet_id']], 'defaults to their only outlet');
        $this->assertSame(422, $this->call('POST', '/purchase-orders', ['outlet_id' => $main, 'items' => [['product_id' => $a['id'], 'quantity' => 1]]], $m['token'], $o['business_id'])[0]);
        $this->invariant($o['business_id']);
    }

    // ================================================================================================ list & dashboard
    public function testListFiltersAndDashboardPendingTile(): void
    {
        $o = $this->shop();
        $s1 = $this->supplier($o, 'Alpha'); $s2 = $this->supplier($o, 'Beta');
        $a = $this->product($o, 'A', '5.00');
        $d = $this->order($o, [['product_id' => $a['id'], 'quantity' => 1]], ['supplier_id' => $s1['id']]);
        $p1 = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 4]], ['supplier_id' => $s1['id']]);
        $p2 = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 4]], ['supplier_id' => $s2['id']]);
        $this->receive($o, $p2['id'], [['item_id' => $p2['items'][0]['id'], 'quantity' => 4]]);
        $p3 = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 4]]);
        $this->receive($o, $p3['id'], [['item_id' => $p3['items'][0]['id'], 'quantity' => 1]]);
        $q = fn (string $qs) => $this->call('GET', '/purchase-orders' . $qs, null, $o['token'], $o['business_id']);
        [, $all] = $q('');
        $this->assertEqualsCanonicalizing([$p3['id'], $p2['id'], $p1['id'], $d['id']], array_column($all['data'], 'id'));
        $by = $all['meta']['summary']['by_status']; ksort($by);
        $this->assertSame(['draft' => 1, 'ordered' => 1, 'partially_received' => 1, 'received' => 1], $by);
        $this->assertSame([$p1['id']], array_column($q('?status=ordered')[1]['data'], 'id'));
        $this->assertEqualsCanonicalizing([$d['id'], $p1['id']], array_column($q("?supplier_id={$s1['id']}")[1]['data'], 'id'));
        $this->assertSame([$p2['id']], array_column($q('?search=beta')[1]['data'], 'id'));
        $this->assertSame([$p3['id']], array_column($q('?search=' . $p3['order_number'])[1]['data'], 'id'));
        $this->assertSame(4, $q('?from=2000-01-01&to=2999-01-01')[1]['meta']['total']);
        $this->assertSame(0, $q('?to=2000-01-01')[1]['meta']['total']);
        foreach (['?status=bogus', '?bogus=1', '?supplier_id=zz', '?from=today', '?per_page=0'] as $bad) { $this->assertSame(422, $q($bad)[0], $bad); }
        $this->assertSame(2, $this->call('GET', "/suppliers/{$s1['id']}", null, $o['token'], $o['business_id'])[1]['data']['order_count']);
        $this->assertSame(1, $this->call('GET', "/suppliers/{$s1['id']}", null, $o['token'], $o['business_id'])[1]['data']['open_orders']);
        // dashboard: ordered + partially_received count as pending (the source counted a status that does not exist)
        $this->assertSame(2, $this->call('GET', '/dashboard', null, $o['token'], $o['business_id'])[1]['data']['inventory']['pending_purchase_orders']);
        $this->call('POST', "/purchase-orders/{$p1['id']}/cancel", null, $o['token'], $o['business_id']);
        $this->assertSame(1, $this->call('GET', '/dashboard', null, $o['token'], $o['business_id'])[1]['data']['inventory']['pending_purchase_orders']);
    }

    // ================================================================================================ real parallelism
    /** @param list<array<string,mixed>> $reqs @return list<string> output lines of one worker process */
    private function spawn(array $o, array $reqs, string $startAt): array
    {
        $env = array_merge($_ENV, getenv(), ['TEST_DB_NAME' => getenv('TEST_DB_NAME'), 'TEST_DB_USER' => getenv('TEST_DB_USER'), 'TEST_DB_PASS' => getenv('TEST_DB_PASS'), 'TEST_DB_SOCKET' => getenv('TEST_DB_SOCKET')]);
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/request_worker.php', $o['token'], $o['business_id'], $startAt, json_encode($reqs)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc);
        return [$proc, $pipes];
    }

    /** @param list<array{0:resource,1:array}> $procs @return list<string> */
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

    public function testParallelDeliveriesNeverReceiveMoreThanOrdered(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'A', '5.00');
        $po = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 20]]);
        $iid = $po['items'][0]['id'];
        $startAt = (string) (microtime(true) + 2.0);
        $procs = [];
        for ($w = 0; $w < 8; $w++) {        // 8 devices x 3 deliveries of 5 = 120 units offered against 20 ordered
            $reqs = [];
            for ($k = 0; $k < 3; $k++) { $reqs[] = ['method' => 'POST', 'path' => "/purchase-orders/{$po['id']}/receive", 'body' => ['client_operation_id' => Uuid::v4(), 'items' => [['item_id' => $iid, 'quantity' => 5]]]]; }
            $procs[] = $this->spawn($o, $reqs, $startAt);
        }
        $lines = $this->collect($procs);
        $this->assertCount(24, $lines);
        $codes = array_count_values(array_map(fn ($l) => explode(' ', $l)[0], $lines));
        ksort($codes);
        $this->assertSame([201 => 4, 409 => 20], $codes, 'exactly 20/5 = 4 deliveries accepted, the rest refused: ' . json_encode($codes));
        $this->assertSame(20, $this->stockOf($a['id']));
        $this->assertSame('received', $this->call('GET', "/purchase-orders/{$po['id']}", null, $o['token'], $o['business_id'])[1]['data']['status']);
        $this->invariant($o['business_id']);
    }

    public function testParallelRetriesOfTheSameDeliveryApplyOnce(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'A', '5.00');
        $po = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 100]]);
        $req = [['method' => 'POST', 'path' => "/purchase-orders/{$po['id']}/receive", 'body' => ['client_operation_id' => Uuid::v4(), 'items' => [['item_id' => $po['items'][0]['id'], 'quantity' => 7]]]]];
        $startAt = (string) (microtime(true) + 2.0);
        $procs = [];
        for ($w = 0; $w < 8; $w++) { $procs[] = $this->spawn($o, $req, $startAt); }
        $lines = $this->collect($procs);
        $codes = array_count_values(array_map(fn ($l) => substr($l, 0, 5), $lines));
        ksort($codes);
        $this->assertSame(['200 1' => 7, '201 0' => 1], $codes, 'one accepted, seven told it was already done: ' . json_encode($codes));
        $this->assertSame(7, $this->stockOf($a['id']));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM purchase_receipts')->fetchColumn());
        $this->invariant($o['business_id']);
    }

    public function testParallelReceivingAndSellingTheSameProductNeverOversells(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'A', '5.00', 0);
        $po = $this->placed($o, [['product_id' => $a['id'], 'quantity' => 30]]);
        $startAt = (string) (microtime(true) + 2.0);
        $procs = [];
        for ($w = 0; $w < 3; $w++) {
            $procs[] = $this->spawn($o, [['method' => 'POST', 'path' => "/purchase-orders/{$po['id']}/receive", 'body' => ['client_operation_id' => Uuid::v4(), 'items' => [['item_id' => $po['items'][0]['id'], 'quantity' => 10]]]]], $startAt);
        }
        for ($w = 0; $w < 4; $w++) {
            $sales = [];
            for ($k = 0; $k < 5; $k++) { $sales[] = ['method' => 'POST', 'path' => '/sales', 'body' => ['client_operation_id' => Uuid::v4(), 'items' => [['product_id' => $a['id'], 'quantity' => 1]], 'payment' => ['mode' => 'full', 'method' => 'cash', 'amount' => '1000']]]; }
            $procs[] = $this->spawn($o, $sales, $startAt);
        }
        $lines = $this->collect($procs);
        $this->assertCount(23, $lines);
        $sold = (int) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE business_id='{$o['business_id']}'")->fetchColumn();
        $this->assertSame(30, $this->stockOf($a['id']) + $sold, 'received 30 = on hand + sold');
        $this->assertGreaterThanOrEqual(0, $this->stockOf($a['id']));
        $this->invariant($o['business_id']);
    }
}
