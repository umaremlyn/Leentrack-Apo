<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

final class CatalogTest extends ApiTestCase
{
    /** @return array<string,mixed> owner on a GROWTH plan (limits 500 products / 50 categories / 3 outlets) */
    private function owner(string $email = 'owner@example.com', string $plan = 'growth'): array
    {
        $o = $this->signup($email);
        $this->setPlan($o['business_id'], $plan);
        return $o;
    }

    private function mainLoc(array $o): string
    {
        return (string) $this->pdo->query("SELECT id FROM locations WHERE business_id='{$o['business_id']}' AND is_default=1")->fetchColumn();
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> product view */
    private function product(array $o, array $extra = []): array
    {
        [$s, $j] = $this->call('POST', '/products', $extra + ['name' => 'Widget ' . bin2hex(random_bytes(3)), 'selling_price' => '100.00', 'cost_price' => '60.00', 'stock_quantity' => 10], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    private function levelsSumEqualsTotal(string $bid): void
    {
        $bad = $this->pdo->query("SELECT p.id, p.stock_quantity, COALESCE(SUM(l.quantity),0) s FROM products p LEFT JOIN stock_levels l ON l.product_id=p.id WHERE p.business_id='$bid' GROUP BY p.id HAVING p.stock_quantity <> s")->fetchAll();
        $this->assertSame([], $bad, 'invariant products.stock_quantity = SUM(levels) violated');
        $badMv = $this->pdo->query("SELECT l.product_id FROM stock_levels l LEFT JOIN (SELECT product_id, location_id, SUM(delta) d FROM stock_movements GROUP BY product_id, location_id) m ON m.product_id=l.product_id AND m.location_id=l.location_id WHERE l.business_id='$bid' AND l.quantity <> COALESCE(m.d,0)")->fetchAll();
        $this->assertSame([], $badMv, 'every level must equal the sum of its movements');
    }

    // ---------------------------------------------------------------------------------------------- categories
    public function testCategoryCrudUniquenessAndCashierCannotWrite(): void
    {
        $o = $this->owner();
        [$s, $j] = $this->call('POST', '/categories', ['name' => 'Beverages'], $o['token'], $o['business_id']);
        $this->assertSame(201, $s);
        $id = $j['data']['id'];
        [$s, $j] = $this->call('POST', '/categories', ['name' => 'beverages'], $o['token'], $o['business_id']);
        $this->assertSame([409, 'duplicate_name'], [$s, $j['error']['code']], 'case-insensitive uniqueness (matches the importer)');
        [$s] = $this->call('PATCH', "/categories/$id", ['name' => 'Drinks', 'description' => 'cold'], $o['token'], $o['business_id']);
        $this->assertSame(200, $s);
        $cashier = $this->addStaff($o, 'c@example.com', 'cashier');
        [$s, $j] = $this->call('GET', '/categories', null, $cashier['token'], $o['business_id']);
        $this->assertSame([200, 'Drinks'], [$s, $j['data'][0]['name']]);
        foreach ([['POST', '/categories', ['name' => 'X']], ['PATCH', "/categories/$id", ['name' => 'Y']], ['DELETE', "/categories/$id", null]] as $c) {
            [$s] = $this->call($c[0], $c[1], $c[2], $cashier['token'], $o['business_id']);
            $this->assertSame(403, $s, "cashier {$c[0]} {$c[1]}");
        }
        $p = $this->product($o, ['category_id' => $id]);
        [$s] = $this->call('DELETE', "/categories/$id", null, $o['token'], $o['business_id']);
        $this->assertSame(200, $s);
        [$s, $j] = $this->call('GET', '/products/' . $p['id'], null, $o['token'], $o['business_id']);
        $this->assertNull($j['data']['category_id'], 'products survive category deletion, uncategorised (as in the source)');
    }

    public function testCategoryPlanLimitEnforced(): void
    {
        $o = $this->owner('free@example.com', 'free');
        for ($i = 1; $i <= 5; $i++) { [$s] = $this->call('POST', '/categories', ['name' => "C$i"], $o['token'], $o['business_id']); $this->assertSame(201, $s); }
        [$s, $j] = $this->call('POST', '/categories', ['name' => 'C6'], $o['token'], $o['business_id']);
        $this->assertSame([402, 'plan_limit_reached'], [$s, $j['error']['code']]);
    }

    // ---------------------------------------------------------------------------------------------- products
    public function testCreateWithOpeningStockCreatesLevelMovementAndKeepsInvariant(): void
    {
        $o = $this->owner();
        $p = $this->product($o, ['name' => 'Rice 5kg', 'stock_quantity' => 25, 'barcode' => '12345', 'min_stock_level' => 4]);
        $this->assertSame(25, $p['stock_quantity']);
        $this->assertSame('ok', $p['stock_status']);
        $this->assertSame(4, $p['reorder_level'], 'reorder defaults to min when omitted (source rule)');
        $this->assertSame('60.00', $p['cost_price']);
        $m = $this->pdo->query("SELECT delta, quantity_after, reason, location_id FROM stock_movements")->fetchAll();
        $this->assertCount(1, $m);
        $this->assertSame([25, 25, 'opening', $this->mainLoc($o)], [(int) $m[0]['delta'], (int) $m[0]['quantity_after'], $m[0]['reason'], $m[0]['location_id']]);
        $this->levelsSumEqualsTotal($o['business_id']);
        $q = $this->product($o, ['stock_quantity' => 3, 'min_stock_level' => 0]);
        $this->assertSame(5, $q['reorder_level'], 'min 0 -> reorder 5');
        $this->assertSame('low', $q['stock_status']);
    }

    public function testServicesHaveNoStockBarcodeOrThresholds(): void
    {
        $o = $this->owner();
        $s = $this->product($o, ['item_type' => 'service', 'name' => 'Haircut', 'stock_quantity' => 99, 'barcode' => 'ABC', 'min_stock_level' => 7]);
        $this->assertSame([0, null, null, 0], [$s['stock_quantity'], $s['barcode'], $s['stock_status'], $s['reorder_level']]);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn());
        [$st, $j] = $this->call('POST', '/stock/adjustments', ['product_id' => $s['id'], 'location_id' => $this->mainLoc($o), 'delta' => 5, 'reason' => 'found'], $o['token'], $o['business_id']);
        $this->assertSame([409, 'not_stocked'], [$st, $j['error']['code']]);
        // a service is excluded from low-stock lists and valuation
        [$st, $j] = $this->call('GET', '/products?stock_status=out', null, $o['token'], $o['business_id']);
        $this->assertSame([], $j['data']);
    }

    public function testDuplicateBarcodeRulesAndArchiveFreesBarcode(): void
    {
        $o = $this->owner();
        $a = $this->product($o, ['barcode' => '999']);
        [$s, $j] = $this->call('POST', '/products', ['name' => 'Other', 'selling_price' => '1', 'barcode' => '999'], $o['token'], $o['business_id']);
        $this->assertSame([409, 'duplicate_barcode'], [$s, $j['error']['code']]);
        $b = $this->product($o, ['barcode' => '1000']);
        [$s, $j] = $this->call('PATCH', '/products/' . $b['id'], ['barcode' => '999'], $o['token'], $o['business_id']);
        $this->assertSame([409, 'duplicate_barcode'], [$s, $j['error']['code']]);
        [$s] = $this->call('PATCH', '/products/' . $a['id'], ['barcode' => '999'], $o['token'], $o['business_id']);
        $this->assertSame(200, $s, 'keeping its own barcode is fine');
        [$s] = $this->call('DELETE', '/products/' . $a['id'], null, $o['token'], $o['business_id']);
        $this->assertSame(200, $s);
        $c = $this->product($o, ['barcode' => '999']);   // freed by archiving
        [$s, $j] = $this->call('POST', '/products/' . $a['id'] . '/restore', null, $o['token'], $o['business_id']);
        $this->assertSame([409, 'duplicate_barcode'], [$s, $j['error']['code']], 'cannot restore into a clash');
        $this->assertNotSame($a['id'], $c['id']);
        [$s] = $this->call('POST', '/products', ['name' => 'Bad', 'selling_price' => '1', 'barcode' => 'has space'], $o['token'], $o['business_id']);
        $this->assertSame(422, $s);
    }

    public function testArchiveKeepsHistoryAndHidesFromListsRestoreWorks(): void
    {
        $o = $this->owner();
        $p = $this->product($o);
        [$s] = $this->call('DELETE', '/products/' . $p['id'], null, $o['token'], $o['business_id']);
        $this->assertSame(200, $s);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM products WHERE id='{$p['id']}'")->fetchColumn(), 'row is kept');
        $this->assertGreaterThan(0, (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE product_id='{$p['id']}'")->fetchColumn(), 'history is kept');
        [$s, $j] = $this->call('GET', '/products', null, $o['token'], $o['business_id']);
        $this->assertSame([], $j['data']);
        [$s] = $this->call('GET', '/products/' . $p['id'], null, $o['token'], $o['business_id']);
        $this->assertSame(404, $s);
        [$s, $j] = $this->call('GET', '/products?archived=1', null, $o['token'], $o['business_id']);
        $this->assertSame([$p['id']], array_column($j['data'], 'id'));
        [$s, $j] = $this->call('POST', '/products/' . $p['id'] . '/restore', null, $o['token'], $o['business_id']);
        $this->assertSame([200, false], [$s, $j['data']['archived']]);
    }

    public function testUpdateCannotChangeStockAndValidatesInput(): void
    {
        $o = $this->owner();
        $p = $this->product($o);
        [$s, $j] = $this->call('PATCH', '/products/' . $p['id'], ['stock_quantity' => 999], $o['token'], $o['business_id']);
        $this->assertSame(422, $s);
        $this->assertArrayHasKey('stock_quantity', $j['error']['fields']);
        foreach ([['selling_price' => '-1'], ['selling_price' => '1.005'], ['selling_price' => 'abc'], ['name' => ''], ['reorder_level' => -1], ['image_url' => 'javascript:alert(1)'], ['item_type' => 'gadget'], ['category_id' => 'not-a-uuid'], ['business_id' => 'x'], ['archived_at' => 'now'], ['is_admin' => true]] as $bad) {
            [$s] = $this->call('PATCH', '/products/' . $p['id'], $bad, $o['token'], $o['business_id']);
            $this->assertSame(422, $s, json_encode($bad));
        }
        [$s, $j] = $this->call('PATCH', '/products/' . $p['id'], ['selling_price' => 150.5, 'name' => 'Renamed', 'image_url' => 'https://cdn.example.com/a.png'], $o['token'], $o['business_id']);
        $this->assertSame([200, '150.50', 'Renamed'], [$s, $j['data']['selling_price'], $j['data']['name']]);
        $this->assertSame(10, $j['data']['stock_quantity']);
    }

    /** Regression: PATCH {is_active:false} used to return 500 (PDO sent the boolean as an empty string). */
    public function testSwitchingAProductOffAndOnWorksAndHidesItFromActiveFilter(): void
    {
        $o = $this->owner();
        $p = $this->product($o);
        [$s, $j] = $this->call('PATCH', '/products/' . $p['id'], ['is_active' => false], $o['token'], $o['business_id']);
        $this->assertSame([200, false], [$s, $j['data']['is_active']]);
        [, $l] = $this->call('GET', '/products?active=1', null, $o['token'], $o['business_id']);
        $this->assertSame([], $l['data']);
        [$s, $j] = $this->call('PATCH', '/products/' . $p['id'], ['is_active' => true], $o['token'], $o['business_id']);
        $this->assertSame([200, true], [$s, $j['data']['is_active']]);
        $loc = $this->call('POST', '/locations', ['name' => 'Annex'], $o['token'], $o['business_id'])[1]['data']['id'];
        [$s, $j] = $this->call('PATCH', "/locations/$loc", ['is_active' => false], $o['token'], $o['business_id']);
        $this->assertSame([200, false], [$s, $j['data']['is_active']], 'locations had the same hazard');
    }

    public function testConvertingProductWithStockToServiceIsRefused(): void
    {
        $o = $this->owner();
        $p = $this->product($o, ['stock_quantity' => 4]);
        [$s, $j] = $this->call('PATCH', '/products/' . $p['id'], ['item_type' => 'service'], $o['token'], $o['business_id']);
        $this->assertSame([409, 'has_stock'], [$s, $j['error']['code']]);
        $empty = $this->product($o, ['stock_quantity' => 0, 'barcode' => '777']);
        [$s, $j] = $this->call('PATCH', '/products/' . $empty['id'], ['item_type' => 'service'], $o['token'], $o['business_id']);
        $this->assertSame([200, null], [$s, $j['data']['barcode']]);
    }

    public function testCashierSeesNoCostPriceAndCannotWrite(): void
    {
        $o = $this->owner();
        $p = $this->product($o);
        $c = $this->addStaff($o, 'cashier@example.com', 'cashier');
        [$s, $j, $res] = $this->call('GET', '/products', null, $c['token'], $o['business_id']);
        $this->assertSame(200, $s);
        $this->assertArrayNotHasKey('cost_price', $j['data'][0]);
        $this->assertStringNotContainsString('60.00', $res->body);
        [$s, $j] = $this->call('GET', '/products/' . $p['id'], null, $c['token'], $o['business_id']);
        $this->assertArrayNotHasKey('cost_price', $j['data']);
        foreach ([['POST', '/products', ['name' => 'X', 'selling_price' => '1']], ['PATCH', '/products/' . $p['id'], ['name' => 'Y']], ['DELETE', '/products/' . $p['id'], null], ['POST', '/products/' . $p['id'] . '/restore', null], ['GET', '/products?archived=1', null], ['GET', '/stock/movements', null], ['GET', '/stock/valuation', null]] as $x) {
            [$s] = $this->call($x[0], $x[1], $x[2], $c['token'], $o['business_id']);
            $this->assertSame(403, $s, "cashier {$x[0]} {$x[1]}");
        }
        $m = $this->addStaff($o, 'mgr@example.com', 'manager');
        [$s, $j] = $this->call('GET', '/products/' . $p['id'], null, $m['token'], $o['business_id']);
        $this->assertSame('60.00', $j['data']['cost_price'], 'managers see costs');
    }

    public function testProductPlanLimit(): void
    {
        $o = $this->owner('lim@example.com', 'free');
        $ins = $this->pdo->prepare("INSERT INTO products (id,business_id,name) VALUES (UUID(),?,?)");
        for ($i = 0; $i < 50; $i++) { $ins->execute([$o['business_id'], "P$i"]); }
        [$s, $j] = $this->call('POST', '/products', ['name' => 'P51', 'selling_price' => '1'], $o['token'], $o['business_id']);
        $this->assertSame([402, 'plan_limit_reached'], [$s, $j['error']['code']]);
        $id = $this->pdo->query("SELECT id FROM products LIMIT 1")->fetchColumn();
        $this->call('DELETE', "/products/$id", null, $o['token'], $o['business_id']);
        [$s] = $this->call('POST', '/products', ['name' => 'P51', 'selling_price' => '1'], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, 'archiving frees a slot');
        [$s, $j] = $this->call('POST', "/products/$id/restore", null, $o['token'], $o['business_id']);
        $this->assertSame([402, 'plan_limit_reached'], [$s, $j['error']['code']], 'restore respects the limit');
    }

    public function testListFiltersSortPaginationAndSearchEscaping(): void
    {
        $o = $this->owner();
        $cat = $this->call('POST', '/categories', ['name' => 'Food'], $o['token'], $o['business_id'])[1]['data']['id'];
        $this->product($o, ['name' => 'Apple', 'selling_price' => '30', 'stock_quantity' => 0, 'category_id' => $cat, 'sku' => 'AP-1']);
        $this->product($o, ['name' => 'Banana', 'selling_price' => '10', 'stock_quantity' => 2, 'barcode' => '5551']);
        $this->product($o, ['name' => '100% Juice', 'selling_price' => '20', 'stock_quantity' => 50]);
        $this->product($o, ['name' => 'Repair', 'item_type' => 'service', 'selling_price' => '5']);
        $g = fn (string $qs) => $this->call('GET', '/products' . $qs, null, $o['token'], $o['business_id']);
        $names = fn (array $j) => array_column($j['data'], 'name');
        $this->assertSame(['100% Juice', 'Apple', 'Banana', 'Repair'], $names($g('')[1]));
        $this->assertSame(['Banana', '100% Juice', 'Apple'], $names($g('?type=product&sort=selling_price&dir=asc')[1]));
        $this->assertSame(['100% Juice'], $names($g('?search=' . rawurlencode('%'))[1]), 'LIKE wildcards are escaped: % matches only a literal %');
        $this->assertSame(['Apple'], $names($g('?search=AP-1')[1]));
        $this->assertSame(['Banana'], $names($g('?barcode=5551')[1]));
        $this->assertSame(['Apple'], $names($g("?category_id=$cat")[1]));
        $this->assertSame(['Apple'], $names($g('?stock_status=out')[1]));
        $this->assertSame(['Banana'], $names($g('?stock_status=low')[1]));
        $this->assertSame(['100% Juice'], $names($g('?stock_status=ok')[1]));
        $this->assertSame(['Repair'], $names($g('?type=service')[1]));
        [, $j] = $g('?per_page=2&page=2&sort=name');
        $this->assertSame(['Banana', 'Repair'], $names($j));
        $this->assertSame(['page' => 2, 'per_page' => 2, 'total' => 4], array_intersect_key($j['meta'], ['page' => 1, 'per_page' => 1, 'total' => 1]));
        foreach (['?sort=cost_price', '?sort=name;DROP TABLE products', '?dir=sideways', '?per_page=0', '?per_page=201', '?page=-1', '?bogus=1', '?type=gadget', '?stock_status=x', '?category_id=zzz'] as $bad) {
            $this->assertSame(422, $g($bad)[0], $bad);
        }
    }

    // ---------------------------------------------------------------------------------------------- tenant isolation
    public function testNothingIsAddressableAcrossBusinesses(): void
    {
        $a = $this->owner('a@example.com');
        $b = $this->owner('b@example.com');
        $pa = $this->product($a, ['name' => 'A-Secret', 'barcode' => 'AAA']);
        $ca = $this->call('POST', '/categories', ['name' => 'A-Cat'], $a['token'], $a['business_id'])[1]['data']['id'];
        $la = $this->mainLoc($a);
        $lb = $this->mainLoc($b);
        $pb = $this->product($b, ['name' => 'B-Item']);
        foreach ([['GET', "/products/{$pa['id']}"], ['PATCH', "/products/{$pa['id']}"], ['DELETE', "/products/{$pa['id']}"], ['POST', "/products/{$pa['id']}/restore"], ['PATCH', "/categories/$ca"], ['DELETE', "/categories/$ca"]] as $c) {
            [$s] = $this->call($c[0], $c[1], $c[0] === 'PATCH' ? ['name' => 'pwn'] : null, $b['token'], $b['business_id']);
            $this->assertSame(404, $s, "{$c[0]} {$c[1]} as B");
        }
        [$s, $j] = $this->call('POST', '/products', ['name' => 'X', 'selling_price' => '1', 'category_id' => $ca], $b['token'], $b['business_id']);
        $this->assertSame(422, $s, "A's category cannot be attached to B's product");
        [$s] = $this->call('POST', '/products', ['name' => 'X', 'selling_price' => '1', 'stock_quantity' => 5, 'location_id' => $la], $b['token'], $b['business_id']);
        $this->assertSame(422, $s, "A's outlet cannot receive B's stock");
        [$s] = $this->call('POST', '/products', ['name' => 'Same barcode ok', 'selling_price' => '1', 'barcode' => 'AAA'], $b['token'], $b['business_id']);
        $this->assertSame(201, $s, 'barcodes are unique per business, not globally (and a clash must not reveal A has it)');
        // stock endpoints
        $adj = fn ($pid, $loc) => $this->call('POST', '/stock/adjustments', ['product_id' => $pid, 'location_id' => $loc, 'delta' => 5, 'reason' => 'found'], $b['token'], $b['business_id']);
        $this->assertSame(404, $adj($pa['id'], $lb)[0], "A's product");
        $this->assertSame(404, $adj($pb['id'], $la)[0], "A's outlet");
        foreach (['/stock/levels', '/stock/movements'] as $path) {
            [$s, $j, $res] = $this->call('GET', $path . "?product_id={$pa['id']}", null, $b['token'], $b['business_id']);
            $this->assertSame([], $j['data'] ?? [], $path);
            $this->assertStringNotContainsString('A-Secret', $res->body);
        }
        [$s, $j] = $this->call('GET', "/stock/levels?location_id=$la", null, $b['token'], $b['business_id']);
        $this->assertSame([], $j['data']);
        [$s, $j] = $this->call('GET', '/products', null, $b['token'], $b['business_id']);
        $this->assertNotContains('A-Secret', array_column($j['data'], 'name'));
        $this->assertSame(10, (int) $this->pdo->query("SELECT stock_quantity FROM products WHERE id='{$pa['id']}'")->fetchColumn(), 'A untouched');
        $this->levelsSumEqualsTotal($a['business_id']);
        $this->levelsSumEqualsTotal($b['business_id']);
    }

    // ---------------------------------------------------------------------------------------------- stock
    public function testAdjustmentsRecordMovementsAndRespectSignsAndFloor(): void
    {
        $o = $this->owner();
        $p = $this->product($o, ['stock_quantity' => 10]);
        $loc = $this->mainLoc($o);
        $adj = fn (array $b) => $this->call('POST', '/stock/adjustments', $b + ['product_id' => $p['id'], 'location_id' => $loc], $o['token'], $o['business_id']);
        [$s, $j] = $adj(['delta' => -3, 'reason' => 'damaged', 'note' => 'dropped']);
        $this->assertSame([201, 7, -3], [$s, $j['data']['quantity_after'], $j['data']['delta']]);
        [$s, $j] = $adj(['new_quantity' => 20, 'reason' => 'count']);
        $this->assertSame([201, 13, 20], [$s, $j['data']['delta'], $j['data']['quantity_after']]);
        $this->assertSame(422, $adj(['delta' => 5, 'reason' => 'damaged'])[0], 'damaged can only remove');
        $this->assertSame(422, $adj(['delta' => -5, 'reason' => 'found'])[0], 'found can only add');
        $this->assertSame(422, $adj(['delta' => 0, 'reason' => 'correction'])[0]);
        $this->assertSame(422, $adj(['new_quantity' => 20, 'reason' => 'count'])[0], 'no change is rejected');
        $this->assertSame(422, $adj(['delta' => 1, 'new_quantity' => 3, 'reason' => 'count'])[0], 'exactly one of delta/new_quantity');
        $this->assertSame(422, $adj(['reason' => 'count'])[0]);
        $this->assertSame(422, $adj(['delta' => 1, 'reason' => 'because'])[0]);
        $this->assertSame(422, $adj(['delta' => 1.5, 'reason' => 'found'])[0]);
        [$s, $j] = $adj(['delta' => -21, 'reason' => 'write_off']);
        $this->assertSame([409, 'insufficient_stock'], [$s, $j['error']['code']], 'online code never drives stock negative');
        $this->assertSame(20, (int) $this->pdo->query("SELECT stock_quantity FROM products WHERE id='{$p['id']}'")->fetchColumn());
        $this->levelsSumEqualsTotal($o['business_id']);
        [$s, $j] = $this->call('GET', "/stock/movements?product_id={$p['id']}", null, $o['token'], $o['business_id']);
        $this->assertSame(['count', 'damaged', 'opening'], array_column($j['data'], 'reason'), 'newest first, append-only history');
        $this->assertSame($o['user_id'], $j['data'][0]['actor_user_id']);
        [$s, $j] = $this->call('GET', '/stock/movements?reason=damaged&from=2020-01-01&to=2099-01-01', null, $o['token'], $o['business_id']);
        $this->assertSame(1, $j['meta']['total']);
        $this->assertSame(422, $this->call('GET', '/stock/movements?from=yesterday', null, $o['token'], $o['business_id'])[0]);
    }

    public function testCashierCannotAdjustStock(): void
    {
        $o = $this->owner();
        $p = $this->product($o);
        $c = $this->addStaff($o, 'cash@example.com', 'cashier');
        [$s] = $this->call('POST', '/stock/adjustments', ['product_id' => $p['id'], 'location_id' => $this->mainLoc($o), 'delta' => 100, 'reason' => 'found'], $c['token'], $o['business_id']);
        $this->assertSame(403, $s);
        $this->assertSame(10, (int) $this->pdo->query("SELECT stock_quantity FROM products WHERE id='{$p['id']}'")->fetchColumn());
    }

    public function testStockIsPerOutletAndRestrictedMembersSeeOnlyTheirOutlets(): void
    {
        $o = $this->owner();
        [, $j] = $this->call('POST', '/locations', ['name' => 'Branch'], $o['token'], $o['business_id']);
        $branch = $j['data']['id'];
        $main = $this->mainLoc($o);
        $p = $this->product($o, ['stock_quantity' => 10]);
        $this->call('POST', '/stock/adjustments', ['product_id' => $p['id'], 'location_id' => $branch, 'delta' => 5, 'reason' => 'found'], $o['token'], $o['business_id']);
        [, $j] = $this->call('GET', '/products/' . $p['id'], null, $o['token'], $o['business_id']);
        $this->assertSame(15, $j['data']['stock_quantity'], 'owner sees the business total');
        [, $j] = $this->call('GET', '/stock/levels?product_id=' . $p['id'], null, $o['token'], $o['business_id']);
        $this->assertEqualsCanonicalizing([10, 5], array_map('intval', array_column($j['data'], 'quantity')));

        $m = $this->addStaff($o, 'branchmgr@example.com', 'manager');
        $this->pdo->prepare('INSERT INTO member_outlets (member_id, location_id) VALUES (?,?)')->execute([$m['member_id'], $branch]);
        [, $j] = $this->call('GET', '/products/' . $p['id'], null, $m['token'], $o['business_id']);
        $this->assertSame(5, $j['data']['stock_quantity'], 'restricted member sees only their outlet stock, not the business total');
        [, $j] = $this->call('GET', '/products?stock_status=out&sort=stock_quantity', null, $m['token'], $o['business_id']);
        $this->assertSame([], $j['data']);
        [, $j, $res] = $this->call('GET', '/stock/levels', null, $m['token'], $o['business_id']);
        $this->assertSame([$branch], array_unique(array_column($j['data'], 'location_id')));
        [$s] = $this->call('POST', '/stock/adjustments', ['product_id' => $p['id'], 'location_id' => $main, 'delta' => -1, 'reason' => 'damaged'], $m['token'], $o['business_id']);
        $this->assertSame(404, $s, 'cannot touch an outlet outside the restriction');
        [$s] = $this->call('POST', '/stock/adjustments', ['product_id' => $p['id'], 'location_id' => $branch, 'delta' => -1, 'reason' => 'damaged'], $m['token'], $o['business_id']);
        $this->assertSame(201, $s);
        [$s, $j] = $this->call('GET', "/stock/movements?location_id=$main", null, $m['token'], $o['business_id']);
        $this->assertSame([], $j['data'], 'movement history of other outlets is hidden');
        [$s] = $this->call('GET', "/locations/$main", null, $m['token'], $o['business_id']);
        $this->assertSame(404, $s);
        [$s, $j] = $this->call('POST', '/products', ['name' => 'Made by branch', 'selling_price' => '1', 'stock_quantity' => 2], $m['token'], $o['business_id']);
        $this->assertSame(422, $s, 'default outlet is outside the restriction -> must pick one of theirs');
        [$s] = $this->call('POST', '/products', ['name' => 'Made by branch', 'selling_price' => '1', 'stock_quantity' => 2, 'location_id' => $branch], $m['token'], $o['business_id']);
        $this->assertSame(201, $s);
        $this->levelsSumEqualsTotal($o['business_id']);
    }

    public function testValuationCostAndRetailExcludeServicesArchivedAndInactive(): void
    {
        $o = $this->owner();
        $this->product($o, ['name' => 'A', 'selling_price' => '100.50', 'cost_price' => '60.25', 'stock_quantity' => 4]);   // cost 241.00 retail 402.00
        $this->product($o, ['name' => 'B', 'selling_price' => '10', 'cost_price' => '5', 'stock_quantity' => 10]);          // cost 50 retail 100
        $this->product($o, ['name' => 'Svc', 'item_type' => 'service', 'selling_price' => '999', 'cost_price' => '1']);
        $arch = $this->product($o, ['name' => 'Gone', 'selling_price' => '1000', 'cost_price' => '900', 'stock_quantity' => 5]);
        $this->call('DELETE', '/products/' . $arch['id'], null, $o['token'], $o['business_id']);
        $off = $this->product($o, ['name' => 'Off', 'selling_price' => '1000', 'cost_price' => '900', 'stock_quantity' => 5, 'is_active' => false]);
        [$s, $j] = $this->call('GET', '/stock/valuation', null, $o['token'], $o['business_id']);
        $this->assertSame([200, '291.00', '502.00', 14, 2], [$s, $j['data']['cost_value'], $j['data']['retail_value'], $j['data']['units'], $j['data']['products']]);
        $this->assertSame(404, $this->call('GET', '/stock/valuation?location_id=99999999-9999-4999-8999-999999999999', null, $this->signup('x@example.com')['token'], $o['business_id'])[0]);
    }

    /** Real parallel processes hammering one product row: locks must serialise them; the floor must hold; invariants must hold. */
    public function testConcurrentDecrementsFromParallelProcessesNeverOversell(): void
    {
        $o = $this->owner();
        $p = $this->product($o, ['stock_quantity' => 120]);
        $loc = $this->mainLoc($o);
        $env = array_merge($_ENV, getenv(), ['TEST_DB_NAME' => getenv('TEST_DB_NAME'), 'TEST_DB_USER' => getenv('TEST_DB_USER'), 'TEST_DB_PASS' => getenv('TEST_DB_PASS'), 'TEST_DB_SOCKET' => getenv('TEST_DB_SOCKET')]);
        $procs = [];
        $startAt = (string) (microtime(true) + 2.0);     // all workers released together
        for ($w = 0; $w < 8; $w++) {
            $cmd = [PHP_BINARY, __DIR__ . '/../bin/adjust_worker.php', $o['business_id'], $p['id'], $loc, '30', $startAt];
            $pipes = [];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        $ok = 0; $refused = 0;
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            proc_close($proc);
            $this->assertSame(1, preg_match('/ok:(\d+) refused:(\d+)/', $out, $m), "worker failed: $out $err");
            $ok += (int) $m[1]; $refused += (int) $m[2];
        }
        $this->assertSame(240, $ok + $refused, 'every attempt accounted for');
        $this->assertSame(120, $ok, 'exactly the 120 units in stock were sold, no more');
        $this->assertSame(120, $refused);
        $this->assertSame(0, (int) $this->pdo->query("SELECT quantity FROM stock_levels WHERE product_id='{$p['id']}'")->fetchColumn());
        $this->levelsSumEqualsTotal($o['business_id']);
    }

    // ---------------------------------------------------------------------------------------------- import
    private function csv(array $o, string $csv, string $mode = 'validate', string $query = ''): array
    {
        return $this->rawCall('POST', "/products/import?mode=$mode$query", $csv, $o['token'], $o['business_id'], 'text/csv');
    }

    /** @return array{0:int,1:array<string,mixed>,2:\LeenTrack\Core\Response} */
    private function rawCall(string $method, string $path, string $body, string $token, string $bid, string $ct): array
    {
        $req = \LeenTrack\Core\Request::fromGlobals(['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/api/v1' . $path, 'REMOTE_ADDR' => $this->ip, 'CONTENT_TYPE' => $ct, 'HTTP_AUTHORIZATION' => "Bearer $token", 'HTTP_X_BUSINESS_ID' => $bid], $body);
        $res = $this->kernel->handle($req);
        return [$res->status, json_decode($res->body, true) ?? [], $res];
    }

    public function testImportValidateReportsRowErrorsAndWritesNothing(): void
    {
        $o = $this->owner();
        $this->product($o, ['name' => 'Existing', 'barcode' => '4444']);
        $csv = "Product_Name,Price,Cost,Qty,Barcode,Category,Type\n"
             . "Good One,\"1,200.50\",800,10,111,Food,\n"        // ok
             . ",50,10,1,,,\n"                                    // no name
             . "No Price,,10,1,,,\n"                              // price required
             . "Neg Stock,50,10,-5,,,\n"                          // negative stock
             . "Dupe In File,5,1,1,111,,\n"                       // barcode repeated
             . "Dupe Existing,5,1,1,4444,,\n"                     // barcode of existing item
             . "Haircut,\$300,,99,SHOULD-BE-IGNORED,Services,service\n"; // service: stock/barcode ignored
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        [$s, $j] = $this->csv($o, $csv);
        $this->assertSame(200, $s, json_encode($j));
        $d = $j['data'];
        $this->assertSame([false, 7, 2, 5], [$d['committed'], $d['total_rows'], $d['valid_count'], $d['invalid_count']]);
        $this->assertSame(['Food', 'Services'], $d['new_categories']);
        $byRow = array_column($d['rows'], null, 'row');
        $this->assertTrue($byRow[2]['valid']);
        $this->assertContains('Item name is required', $byRow[3]['errors']);
        $this->assertContains('Valid selling price is required', $byRow[4]['errors']);
        $this->assertContains('Valid stock quantity (>= 0) is required for physical products', $byRow[5]['errors']);
        $this->assertStringContainsString('repeated (first used on row 2)', $byRow[6]['errors'][0]);
        $this->assertContains('Barcode already belongs to another item', $byRow[7]['errors']);
        $this->assertTrue($byRow[8]['valid']);
        $this->assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(), 'validate mode never writes');
    }

    public function testImportCommitCreatesProductsCategoriesStockAndHistory(): void
    {
        $o = $this->owner();
        $csv = "\xEF\xBB\xBFname,sku,barcode,selling_price,cost_price,stock_quantity,min_stock_level,reorder_level,category,description\r\n"
             . "\"Rice, long grain\",R1,901,6500,4800,40,8,12,Groceries,\"5kg \"\"premium\"\" bag\"\r\n"
             . "Beans,B1,902,\"₦1,200\",900,20,0,,groceries,\r\n"
             . "Oil,O1,,4200,3100,25,6,,Groceries,\r\n";
        [$s, $j] = $this->csv($o, $csv, 'commit');
        $this->assertSame(200, $s, json_encode($j));
        $this->assertSame([true, 3], [$j['data']['committed'], $j['data']['created']]);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(), 'categories matched case-insensitively');
        [, $l] = $this->call('GET', '/products?sort=name', null, $o['token'], $o['business_id']);
        $by = array_column($l['data'], null, 'name');
        $this->assertSame(['6500.00', 40, 12], [$by['Rice, long grain']['selling_price'], $by['Rice, long grain']['stock_quantity'], $by['Rice, long grain']['reorder_level']]);
        $this->assertSame('5kg "premium" bag', $by['Rice, long grain']['description']);
        $this->assertSame(['1200.00', 5], [$by['Beans']['selling_price'], $by['Beans']['reorder_level']], 'reorder = given || min || 5');
        $this->assertSame(6, $by['Oil']['reorder_level'], 'reorder falls back to min');
        $this->assertSame(3, (int) $this->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reason='import'")->fetchColumn());
        $this->levelsSumEqualsTotal($o['business_id']);
        // importing the same file again: every barcoded row now clashes
        [, $j2] = $this->csv($o, $csv, 'commit');
        $this->assertSame(1, $j2['data']['created'], 'only the barcode-less row can repeat');
    }

    public function testImportRespectsPlanLimitsAtomically(): void
    {
        $o = $this->owner('imp@example.com', 'free');
        $ins = $this->pdo->prepare("INSERT INTO products (id,business_id,name) VALUES (UUID(),?,?)");
        for ($i = 0; $i < 48; $i++) { $ins->execute([$o['business_id'], "P$i"]); }
        $csv = "name,price,qty\nA,1,1\nB,1,1\nC,1,1\n";
        [$s, $j] = $this->csv($o, $csv, 'commit');
        $this->assertSame([402, 'plan_limit_reached'], [$s, $j['error']['code']]);
        $this->assertSame(48, (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(), 'nothing imported (all-or-nothing)');
        [$s] = $this->csv($o, "name,price,qty\nA,1,1\nB,1,1\n", 'commit');
        $this->assertSame(200, $s);
        $o2 = $this->owner('imp2@example.com', 'free');
        [$s, $j] = $this->csv($o2, "name,price,qty,category\nA,1,1,c1\nB,1,1,c2\nC,1,1,c3\nD,1,1,c4\nE,1,1,c5\nF,1,1,c6\n", 'commit');
        $this->assertSame([402, 'plan_limit_reached'], [$s, $j['error']['code']], 'category limit (free = 5)');
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM categories WHERE business_id='{$o2['business_id']}'")->fetchColumn());
    }

    public function testImportInputValidationAndPermissions(): void
    {
        $o = $this->owner();
        $this->assertSame(422, $this->csv($o, "   ")[0]);
        $this->assertSame(422, $this->csv($o, "name\n")[0], 'header only');
        [$s, $j] = $this->csv($o, "sku,price\nA,1\n");
        $this->assertSame(422, $s);
        $this->assertSame(422, $this->csv($o, "name,price\nA,1\n", 'explode')[0]);
        [$s] = $this->rawCall('POST', '/products/import?mode=validate', "\xFF\xFE bad", $o['token'], $o['business_id'], 'text/csv');
        $this->assertSame(422, $s, 'non-UTF-8 rejected');
        [$s] = $this->rawCall('POST', '/products/import?mode=validate', 'name,price', $o['token'], $o['business_id'], 'application/xml');
        $this->assertSame(415, $s);
        [$s] = $this->rawCall('POST', '/products/import?mode=validate', str_repeat('a', 2_097_153), $o['token'], $o['business_id'], 'text/csv');
        $this->assertSame(413, $s);
        $rows = "name,price\n" . implode("\n", array_map(fn ($i) => "P$i,1", range(1, 2001)));
        $this->assertSame(413, $this->csv($o, $rows)[0], 'row cap');
        [$s, $j] = $this->call('POST', '/products/import?mode=validate', ['csv' => "name,price,qty\nViaJson,5,1\n"], $o['token'], $o['business_id']);
        $this->assertSame([200, 1], [$s, $j['data']['valid_count']], 'JSON {csv} body also accepted');
        $c = $this->addStaff($o, 'cash@example.com', 'cashier');
        $this->assertSame(403, $this->csv(['token' => $c['token'], 'business_id' => $o['business_id']], "name,price\nA,1\n", 'commit')[0]);
        [$s, $j] = $this->csv($o, "name,price,qty\nA,1,1\n", 'commit', '&location_id=99999999-9999-4999-8999-999999999999');
        $this->assertSame([422, true], [$s, isset($j['error']['fields']['location_id'])], "another business's / unknown outlet is refused");
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
        [$s, $j] = $this->csv($o, "name,price\nNoStockColumn,5\n");
        $this->assertSame([200, 0], [$s, $j['data']['valid_count']], 'physical items need a stock quantity (same rule as the source dialog)');
    }

    // ---------------------------------------------------------------------------------------------- export
    public function testExportIsPlanGatedQuotedAndInjectionSafe(): void
    {
        $o = $this->owner('free-exp@example.com', 'free');
        [$s, $j] = $this->call('GET', '/products/export', null, $o['token'], $o['business_id']);
        $this->assertSame([402, 'feature_not_in_plan'], [$s, $j['error']['code']]);
        $this->setPlan($o['business_id'], 'growth');
        $this->product($o, ['name' => '=HYPERLINK("http://evil","click")', 'sku' => '@SUM(1)', 'description' => "+cmd|' /C calc'!A0", 'selling_price' => '10', 'stock_quantity' => 3]);
        $this->product($o, ['name' => 'Rice, "premium"', 'selling_price' => '5.50', 'stock_quantity' => 0, 'barcode' => '-123']);
        $this->product($o, ['name' => 'Cut', 'item_type' => 'service', 'selling_price' => '7']);
        [$s, , $res] = $this->call('GET', '/products/export', null, $o['token'], $o['business_id']);
        $this->assertSame(200, $s);
        $this->assertStringStartsWith('text/csv', $res->headers['Content-Type']);
        $this->assertStringContainsString('attachment; filename="products-', $res->headers['Content-Disposition']);
        $body = $res->body;
        $this->assertStringStartsWith("\xEF\xBB\xBFname,sku,barcode", $body);
        $rows = \LeenTrack\Core\Csv::parse($body);
        $byName = [];
        foreach (array_slice($rows, 1) as $r) { $byName[$r[0]] = $r; }
        $this->assertArrayHasKey("'=HYPERLINK(\"http://evil\",\"click\")", $byName, 'formula neutralised, quotes escaped');
        $evil = $byName["'=HYPERLINK(\"http://evil\",\"click\")"];
        $this->assertSame("'@SUM(1)", $evil[1]);
        $this->assertSame("'+cmd|' /C calc'!A0", $evil[10]);
        $this->assertSame(['5.50', '0'], [$byName['Rice, "premium"'][5], $byName['Rice, "premium"'][7]], 'numbers are not mangled');
        $this->assertSame('-123', $byName['Rice, "premium"'][2], 'our own barcode column is not prefixed (digits only data, not user formula text)');
        $this->assertSame(['service', '0'], [$byName['Cut'][4], $byName['Cut'][7]]);
        $c = $this->addStaff($o, 'cashier@example.com', 'cashier');
        $this->assertSame(403, $this->call('GET', '/products/export', null, $c['token'], $o['business_id'])[0]);
    }

    public function testHousekeepingRemovesOnlyExpiredSecurityArtefacts(): void
    {
        $o = $this->signup('hk@example.com');
        $this->pdo->exec("INSERT INTO rate_limits (key_hash, window_start, hits) VALUES (REPEAT('a',64), 1000, 1)");
        $this->pdo->exec("INSERT INTO user_tokens (user_id,purpose,token_hash,expires_at,used_at) VALUES ('{$o['user_id']}','reset_password',REPEAT('b',64),'2020-01-01',NULL)");
        $this->pdo->exec("INSERT INTO auth_sessions (id,user_id,access_hash,access_expires_at,refresh_hash,refresh_expires_at) VALUES (UUID(),'{$o['user_id']}',REPEAT('c',64),'2020-01-01',REPEAT('d',64),'2020-01-02')");
        $r = (new \LeenTrack\Services\HousekeepingService($this->pdo))->run();
        $this->assertGreaterThanOrEqual(1, $r['rate_limits']);
        $this->assertGreaterThanOrEqual(1, $r['user_tokens']);
        $this->assertSame(1, $r['auth_sessions']);
        [$s] = $this->call('GET', '/me', null, $o['token']);
        $this->assertSame(200, $s, 'live session untouched');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM businesses')->fetchColumn());
    }
}
