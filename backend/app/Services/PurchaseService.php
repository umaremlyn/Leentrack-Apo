<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Db;
use LeenTrack\Core\Money;
use LeenTrack\Core\Pager;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\CatalogRepository;
use LeenTrack\Repositories\PurchaseRepository;
use PDO;

/**
 * Suppliers and purchase orders. Receiving is the security-relevant part (source defect S-08):
 * additive, bounded by what is still outstanding, row-locked, idempotent, and the only stock writer is StockService.
 * Lock order: purchase order row -> product rows (ascending id) -> stock level rows.
 */
final class PurchaseService
{
    private const STATUSES = ['draft', 'ordered', 'partially_received', 'received', 'closed', 'cancelled'];
    private const MAX_LINES = 200;
    private const MAX_QTY = 1_000_000;

    public function __construct(private readonly PDO $pdo, private readonly PurchaseRepository $repo, private readonly CatalogRepository $catalog, private readonly StockService $stock, private readonly AuditService $audit)
    {
    }

    // ================================================================================================ suppliers
    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,mixed>} */
    public function suppliers(TenantContext $t, array $q): array
    {
        $t->require('suppliers.manage');
        if ($extra = array_diff(array_keys($q), ['search', 'archived', 'page', 'per_page'])) { throw ApiException::validation([(string) reset($extra) => ['Unknown filter.']]); }
        $search = isset($q['search']) ? (is_string($q['search']) && $q['search'] !== '' && mb_strlen($q['search']) <= 100 ? $q['search'] : throw ApiException::validation(['search' => ['Must be text up to 100 characters.']])) : null;
        $arch = isset($q['archived']) ? (in_array($q['archived'], ['0', '1'], true) ? $q['archived'] === '1' : throw ApiException::validation(['archived' => ['Must be 0 or 1.']])) : false;
        $pg = Pager::fromQuery($q, 50);
        $r = $this->repo->suppliers($t->businessId, $arch, $search, $pg->perPage, $pg->offset());
        return ['rows' => array_map($this->supplierView(...), $r['rows']), 'meta' => $pg->meta($r['total'])];
    }

    /** @return array<string,mixed> */
    public function supplier(TenantContext $t, string $id): array
    {
        $t->require('suppliers.manage');
        return $this->supplierView($this->findSupplier($t, $id));
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function createSupplier(TenantContext $t, array $body, Request $req): array
    {
        $t->require('suppliers.manage');
        $d = $this->parseSupplier($body, true);
        $id = Uuid::v4();
        $this->repo->insertSupplier($d + ['id' => $id, 'business_id' => $t->businessId]);
        $this->audit->log('supplier.create', $req, $t->userId, $t->businessId, 'supplier', $id, null, ['name' => $d['name']]);
        return $this->supplierView($this->findSupplier($t, $id));
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function updateSupplier(TenantContext $t, string $id, array $body, Request $req): array
    {
        $t->require('suppliers.manage');
        $before = $this->findSupplier($t, $id);
        $d = $this->parseSupplier($body, false);
        $this->repo->updateSupplier($t->businessId, $id, $d);
        $this->audit->log('supplier.update', $req, $t->userId, $t->businessId, 'supplier', $id, array_intersect_key($before, $d), $d);
        return $this->supplierView($this->findSupplier($t, $id));
    }

    /** "Delete" archives: orders that already reference the supplier must keep their history. */
    public function archiveSupplier(TenantContext $t, string $id, Request $req): void
    {
        $t->require('suppliers.manage');
        $s = $this->findSupplier($t, $id);
        $this->repo->archiveSupplier($t->businessId, $id);
        $this->audit->log('supplier.archive', $req, $t->userId, $t->businessId, 'supplier', $id, ['name' => $s['name']]);
    }

    /** @return array<string,mixed> */
    public function restoreSupplier(TenantContext $t, string $id, Request $req): array
    {
        $t->require('suppliers.manage');
        $s = $this->findSupplier($t, $id);
        $this->repo->restoreSupplier($t->businessId, $id);
        $this->audit->log('supplier.restore', $req, $t->userId, $t->businessId, 'supplier', $id, null, ['name' => $s['name']]);
        return $this->supplierView($this->findSupplier($t, $id));
    }

    // ================================================================================================ orders: read
    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,mixed>} */
    public function orders(TenantContext $t, array $q): array
    {
        $t->require('purchases.manage');
        if ($extra = array_diff(array_keys($q), ['status', 'supplier_id', 'outlet_id', 'search', 'from', 'to', 'page', 'per_page'])) { throw ApiException::validation([(string) reset($extra) => ['Unknown filter.']]); }
        $status = null;
        if (isset($q['status'])) { $status = is_string($q['status']) && in_array($q['status'], self::STATUSES, true) ? $q['status'] : throw ApiException::validation(['status' => ['Not a valid status.']]); }
        $f = ['status' => $status, 'supplier_id' => $this->qUuid($q, 'supplier_id'), 'outlet_id' => $this->qUuid($q, 'outlet_id'),
            'from' => $this->qDate($q, 'from', 0), 'to' => $this->qDate($q, 'to', 1),
            'search' => isset($q['search']) ? (is_string($q['search']) && $q['search'] !== '' && mb_strlen($q['search']) <= 100 ? $q['search'] : throw ApiException::validation(['search' => ['Must be text up to 100 characters.']])) : null];
        if ($f['outlet_id'] !== null && !$t->canAccessOutlet($f['outlet_id'])) { throw ApiException::notFound('Outlet not found.'); }
        $pg = Pager::fromQuery($q, 50);
        $r = $this->repo->orders($t->businessId, $f, $t->outletIds, $pg->perPage, $pg->offset());
        $rows = array_map(fn ($o) => $this->orderBase($o) + ['item_count' => (int) $o['item_count']], $r['rows']);
        return ['rows' => $rows, 'meta' => $pg->meta($r['total']) + ['summary' => ['by_status' => $r['byStatus']]]];
    }

    /** @return array<string,mixed> */
    public function order(TenantContext $t, string $id): array
    {
        $t->require('purchases.manage');
        return $this->orderView($t, $this->findOrder($t, $id));
    }

    // ================================================================================================ orders: write
    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function createOrder(TenantContext $t, array $body, Request $req): array
    {
        $t->require('purchases.manage');
        $v = new Validator($body);
        $v->only(['supplier_id', 'outlet_id', 'notes', 'items']);
        $supplier = $v->uuid('supplier_id', false);
        $outlet = $v->uuid('outlet_id', false);
        $notes = $v->string('notes', 1, 1000, false);
        $v->done();
        $items = $this->parseItems($t, $body['items'] ?? null);
        $this->checkSupplier($t, $supplier);
        $outletId = $this->resolveOutlet($t, $outlet);
        $id = Uuid::v4();
        $total = $this->total($items);
        Db::transaction($this->pdo, function () use ($t, $id, $supplier, $outletId, $notes, $items, $total) {
            $number = sprintf('PO-%06d', $this->repo->nextSequence($t->businessId));
            $this->repo->insertOrder(['id' => $id, 'business_id' => $t->businessId, 'supplier_id' => $supplier, 'outlet_id' => $outletId, 'order_number' => $number, 'total_cost' => $total, 'notes' => $notes, 'created_by' => $t->userId]);
            $this->repo->replaceItems($t->businessId, $id, $items);
        });
        $this->audit->log('purchase.create', $req, $t->userId, $t->businessId, 'purchase_order', $id, null, ['total' => $total, 'lines' => count($items)]);
        return $this->orderView($t, $this->findOrder($t, $id));
    }

    /** Only a draft can be changed; sending `items` replaces all lines. @param array<string,mixed> $body @return array<string,mixed> */
    public function updateOrder(TenantContext $t, string $id, array $body, Request $req): array
    {
        $t->require('purchases.manage');
        $this->findOrder($t, $id);
        $v = new Validator($body);
        $v->only(['supplier_id', 'outlet_id', 'notes', 'items']);
        $fields = [];
        if ($v->has('supplier_id')) { $fields['supplier_id'] = $v->uuid('supplier_id', false); }
        $outlet = $v->has('outlet_id') ? $v->uuid('outlet_id') : null;
        if ($v->has('notes')) { $fields['notes'] = $v->string('notes', 1, 1000, false); }
        $v->done();
        $items = array_key_exists('items', $body) ? $this->parseItems($t, $body['items']) : null;
        if (isset($fields['supplier_id'])) { $this->checkSupplier($t, $fields['supplier_id']); }
        if ($outlet !== null) { $fields['outlet_id'] = $this->resolveOutlet($t, $outlet); }
        if ($items !== null) { $fields['total_cost'] = $this->total($items); }
        Db::transaction($this->pdo, function () use ($t, $id, $fields, $items) {
            $o = $this->repo->lockOrder($t->businessId, $id) ?? throw ApiException::notFound();
            if ($o['status'] !== 'draft') { throw new ApiException('not_draft', 'Only a draft order can be changed.', 409); }
            $this->repo->updateOrder($t->businessId, $id, $fields);
            if ($items !== null) { $this->repo->replaceItems($t->businessId, $id, $items); }
        });
        $this->audit->log('purchase.update', $req, $t->userId, $t->businessId, 'purchase_order', $id, null, array_keys($fields) + ($items !== null ? ['items' => count($items)] : []));
        return $this->orderView($t, $this->findOrder($t, $id));
    }

    public function deleteOrder(TenantContext $t, string $id, Request $req): void
    {
        $t->require('purchases.manage');
        $o = $this->findOrder($t, $id);
        Db::transaction($this->pdo, function () use ($t, $id) {
            $l = $this->repo->lockOrder($t->businessId, $id) ?? throw ApiException::notFound();
            if ($l['status'] !== 'draft') { throw new ApiException('not_draft', 'Only a draft order can be deleted. Cancel it instead.', 409); }
            $this->repo->deleteOrder($t->businessId, $id);
        });
        $this->audit->log('purchase.delete', $req, $t->userId, $t->businessId, 'purchase_order', $id, ['order_number' => $o['order_number']]);
    }

    /** draft -> ordered. @return array<string,mixed> */
    public function place(TenantContext $t, string $id, Request $req): array
    {
        return $this->transition($t, $id, $req, 'purchase.order', ['draft'], 'ordered', 'Only a draft can be placed.', function (array $o) use ($t, $id) {
            if (!$this->repo->items($t->businessId, $id)) { throw ApiException::validation(['items' => ['Add at least one item first.']]); }
            return ['ordered_at' => $this->now()];
        });
    }

    /** draft/ordered -> cancelled (nothing may have been received). @return array<string,mixed> */
    public function cancel(TenantContext $t, string $id, Request $req): array
    {
        return $this->transition($t, $id, $req, 'purchase.cancel', ['draft', 'ordered'], 'cancelled', 'Only a draft or an order with nothing received can be cancelled. Close it instead if part has arrived.', fn () => []);
    }

    /** partially_received -> closed: the rest is not coming. @return array<string,mixed> */
    public function close(TenantContext $t, string $id, Request $req): array
    {
        return $this->transition($t, $id, $req, 'purchase.close', ['partially_received'], 'closed', 'Only a partly received order can be closed.', fn () => ['received_at' => $this->now()]);
    }

    /**
     * @param list<string> $from
     * @param callable(array<string,mixed>):array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function transition(TenantContext $t, string $id, Request $req, string $action, array $from, string $to, string $message, callable $extra): array
    {
        $t->require('purchases.manage');
        $this->findOrder($t, $id);
        Db::transaction($this->pdo, function () use ($t, $id, $from, $to, $message, $extra) {
            $o = $this->repo->lockOrder($t->businessId, $id) ?? throw ApiException::notFound();
            if (!in_array($o['status'], $from, true)) { throw new ApiException('invalid_status', $message, 409); }
            $this->repo->updateOrder($t->businessId, $id, ['status' => $to] + $extra($o));
        });
        $this->audit->log($action, $req, $t->userId, $t->businessId, 'purchase_order', $id, null, ['status' => $to]);
        return $this->orderView($t, $this->findOrder($t, $id));
    }

    // ================================================================================================ receiving
    /**
     * @param array<string,mixed> $body items:[{item_id,quantity}], client_operation_id, update_cost
     * @return array{result:array<string,mixed>,replayed:bool}
     */
    public function receive(TenantContext $t, string $id, array $body, ?string $headerKey, Request $req): array
    {
        $t->require('purchases.manage');
        if (!Uuid::isValid($id)) { throw ApiException::notFound(); }
        $v = new Validator($body);
        $v->only(['items', 'client_operation_id', 'update_cost']);
        $op = $v->has('client_operation_id') ? $v->uuid('client_operation_id') : null;
        if ($op === null && $headerKey !== null) {
            if (Uuid::isValid($headerKey)) { $op = $headerKey; } else { $v->addError('client_operation_id', 'Idempotency-Key must be a UUID.'); }
        }
        if ($op === null && !$v->has('client_operation_id')) { $v->addError('client_operation_id', 'This field is required (a UUID generated by the device, reused on retry).'); }
        $updateCost = $v->has('update_cost') ? (bool) $v->bool('update_cost') : false;
        $lines = [];
        $raw = $body['items'] ?? null;
        if (!is_array($raw) || $raw === [] || !array_is_list($raw) || count($raw) > self::MAX_LINES) {
            $v->addError('items', 'Provide between 1 and ' . self::MAX_LINES . ' items to receive.');
        } else {
            $seen = [];
            foreach ($raw as $i => $row) {
                $iv = new Validator(is_array($row) ? $row : []);
                if (!is_array($row)) { $v->addError("items.$i", 'Must be an object.'); continue; }
                $iv->only(['item_id', 'quantity']);
                $itemId = $iv->uuid('item_id');
                $qty = $iv->int('quantity', 1, self::MAX_QTY);
                try { $iv->done(); } catch (ApiException $e) { foreach ($e->fields as $k => $m) { foreach ($m as $msg) { $v->addError("items.$i.$k", $msg); } } continue; }
                if (isset($seen[$itemId])) { $v->addError("items.$i.item_id", 'Each item can appear only once.'); continue; }
                $seen[$itemId] = true;
                $lines[] = ['item_id' => (string) $itemId, 'quantity' => (int) $qty];
            }
        }
        $v->done();
        usort($lines, fn ($a, $b) => strcmp($a['item_id'], $b['item_id']));
        $hash = hash('sha256', json_encode([$id, $lines, $updateCost], JSON_THROW_ON_ERROR));
        $bid = $t->businessId;
        $this->findOrder($t, $id);                                  // 404 before anything else, incl. outlet restriction

        try {
            return Db::transaction($this->pdo, fn () => $this->receiveTx($t, $id, $lines, $updateCost, (string) $op, $hash, $req));
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains((string) $e->getMessage(), 'uq_receipt_operation')) {
                $existing = $this->repo->receiptByOperation($bid, (string) $op);
                if ($existing !== null) { return $this->replay($t, $id, $existing, $hash); }
            }
            throw $e;
        }
    }

    /**
     * @param list<array{item_id:string,quantity:int}> $lines (sorted by item id)
     * @return array{result:array<string,mixed>,replayed:bool}
     */
    private function receiveTx(TenantContext $t, string $id, array $lines, bool $updateCost, string $op, string $hash, Request $req): array
    {
        $bid = $t->businessId;
        $o = $this->repo->lockOrder($bid, $id) ?? throw ApiException::notFound();                       // 1. the order
        if (!$t->canAccessOutlet((string) $o['outlet_id'])) { throw ApiException::notFound(); }
        $existing = $this->repo->receiptByOperation($bid, $op);                                         // decided under the lock
        if ($existing !== null) { return $this->replay($t, $id, $existing, $hash); }
        if (!in_array($o['status'], ['ordered', 'partially_received'], true)) {
            throw new ApiException('invalid_status', $o['status'] === 'draft' ? 'Place the order before receiving goods.' : 'This order is ' . str_replace('_', ' ', (string) $o['status']) . ' and cannot receive more goods.', 409);
        }
        $items = [];
        foreach ($this->repo->items($bid, $id, true) as $it) { $items[$it['id']] = $it; }
        $errors = [];
        foreach ($lines as $i => $l) {
            $it = $items[$l['item_id']] ?? null;
            if ($it === null) { $errors["items.$i.item_id"] = ['This item is not on this order.']; continue; }
            $left = (int) $it['quantity_ordered'] - (int) $it['quantity_received'];
            if ($l['quantity'] > $left) { $errors["items.$i.quantity"] = [$left === 0 ? "\"{$it['product_name']}\" has already been fully received." : "Only $left of \"{$it['product_name']}\" is still outstanding."]; }
        }
        if ($errors) { throw new ApiException('over_receive', 'You cannot receive more than was ordered.', 409, $errors); }

        $rid = Uuid::v4();
        $this->repo->insertReceipt($rid, $bid, $id, $op, $hash, $t->userId, $lines);                    // before any stock moves
        $byProduct = [];
        foreach ($lines as $l) { $byProduct[(string) ($items[$l['item_id']]['product_id'] ?? '')][] = $l; }
        ksort($byProduct, SORT_STRING);                                                                 // 2. products ascending
        foreach ($byProduct as $pid => $ls) {
            foreach ($ls as $l) {
                $it = $items[$l['item_id']];
                if ($pid !== '') {
                    $p = $this->catalog->lockProduct($bid, $pid);
                    if ($p === null || $p['archived_at'] !== null) { throw new ApiException('product_unavailable', "\"{$it['product_name']}\" is archived or removed. Restore it before receiving this item.", 409); }
                    $this->stock->applyDelta($bid, $pid, (string) $o['outlet_id'], $l['quantity'], 'purchase', 'purchase_order', $id, $o['order_number'], $t->userId);
                    if ($updateCost) { $this->catalog->updateProduct($bid, $pid, ['cost_price' => $it['unit_cost']]); }
                }
                $this->repo->setReceived($it['id'], (int) $it['quantity_received'] + $l['quantity']);
                $items[$l['item_id']]['quantity_received'] = (int) $it['quantity_received'] + $l['quantity'];
            }
        }
        $complete = true;
        foreach ($items as $it) { if ((int) $it['quantity_received'] < (int) $it['quantity_ordered']) { $complete = false; break; } }
        $this->repo->updateOrder($bid, $id, $complete ? ['status' => 'received', 'received_at' => $this->now()] : ['status' => 'partially_received']);
        $this->audit->log('purchase.receive', $req, $t->userId, $bid, 'purchase_order', $id, null, ['receipt_id' => $rid, 'lines' => count($lines), 'units' => array_sum(array_column($lines, 'quantity')), 'complete' => $complete, 'cost_updated' => $updateCost]);
        return ['result' => ['receipt_id' => $rid, 'order' => $this->orderView($t, $this->findOrder($t, $id))], 'replayed' => false];
    }

    /** @param array<string,mixed> $existing @return array{result:array<string,mixed>,replayed:bool} */
    private function replay(TenantContext $t, string $id, array $existing, string $hash): array
    {
        if ($existing['purchase_order_id'] !== $id || !hash_equals((string) $existing['request_hash'], $hash)) {
            throw new ApiException('idempotency_conflict', 'This operation id was already used for a different delivery.', 409);
        }
        return ['result' => ['receipt_id' => $existing['id'], 'order' => $this->orderView($t, $this->findOrder($t, $id))], 'replayed' => true];
    }

    // ================================================================================================ helpers
    /** @return array<string,mixed> */
    private function findSupplier(TenantContext $t, string $id): array
    {
        if (!Uuid::isValid($id)) { throw ApiException::notFound(); }
        return $this->repo->supplier($t->businessId, $id) ?? throw ApiException::notFound();
    }

    /** @return array<string,mixed> */
    private function findOrder(TenantContext $t, string $id): array
    {
        if (!Uuid::isValid($id)) { throw ApiException::notFound(); }
        $o = $this->repo->order($t->businessId, $id) ?? throw ApiException::notFound();
        if (!$t->canAccessOutlet((string) $o['outlet_id'])) { throw ApiException::notFound(); }
        return $o;
    }

    private function checkSupplier(TenantContext $t, ?string $supplierId): void
    {
        if ($supplierId === null) { return; }
        if ($this->repo->supplier($t->businessId, $supplierId, false) === null) { throw ApiException::validation(['supplier_id' => ['Supplier not found.']]); }
    }

    private function resolveOutlet(TenantContext $t, ?string $outlet): string
    {
        if ($outlet === null) {
            if ($t->outletIds !== null) {
                if (count($t->outletIds) !== 1) { throw ApiException::validation(['outlet_id' => ['Choose the outlet that will receive the goods.']]); }
                $outlet = $t->outletIds[0];
            } else {
                $outlet = $this->catalog->defaultLocationId($t->businessId) ?? throw new ApiException('no_outlet', 'This business has no default outlet.', 409);
            }
        }
        if (!$t->canAccessOutlet($outlet) || !$this->catalog->locationBelongs($t->businessId, $outlet)) { throw ApiException::validation(['outlet_id' => ['Outlet not found.']]); }
        return $outlet;
    }

    /** @return list<array<string,mixed>> */
    private function parseItems(TenantContext $t, mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::MAX_LINES) {
            throw ApiException::validation(['items' => ['Provide between 1 and ' . self::MAX_LINES . ' items.']]);
        }
        $errors = []; $out = []; $seen = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) { $errors["items.$i"] = ['Must be an object.']; continue; }
            $v = new Validator($row);
            $v->only(['product_id', 'product_name', 'quantity', 'unit_cost']);
            $pid = $v->uuid('product_id', false);
            $name = $v->string('product_name', 1, 200, $pid === null);
            $qty = $v->int('quantity', 1, self::MAX_QTY);
            $cost = $v->money('unit_cost', false);
            try { $v->done(); } catch (ApiException $e) { foreach ($e->fields as $k => $m) { $errors["items.$i.$k"] = $m; } continue; }
            if ($pid !== null) {
                if (isset($seen[$pid])) { $errors["items.$i.product_id"] = ['Each product can appear only once.']; continue; }
                $seen[$pid] = true;
                $p = $this->catalog->product($t->businessId, $pid);
                if ($p === null) { $errors["items.$i.product_id"] = ['Product not found.']; continue; }
                if ($p['item_type'] !== 'product') { $errors["items.$i.product_id"] = ['Services are not stocked, so they cannot be ordered.']; continue; }
                $name = (string) $p['name'];
                $cost ??= Money::parse((string) $p['cost_price']);
            }
            $cost ??= Money::zero();
            try { $line = $cost->mulInt((int) $qty); } catch (\OverflowException | \InvalidArgumentException) { $errors["items.$i.unit_cost"] = ['This amount is too large.']; continue; }
            $out[] = ['id' => Uuid::v4(), 'product_id' => $pid, 'product_name' => (string) $name, 'quantity' => (int) $qty, 'unit_cost' => $cost->toString(), 'line_total' => $line->toString()];
        }
        if ($errors) { throw ApiException::validation($errors); }
        return $out;
    }

    /** @param list<array<string,mixed>> $items */
    private function total(array $items): string
    {
        $sum = Money::zero();
        try { foreach ($items as $it) { $sum = $sum->add(Money::parse($it['line_total'])); } } catch (\OverflowException | \InvalidArgumentException) {
            throw ApiException::validation(['items' => ['The order total is too large.']]);
        }
        return $sum->toString();
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function parseSupplier(array $body, bool $create): array
    {
        $v = new Validator($body);
        $v->only(['name', 'contact_name', 'phone', 'email', 'address', 'notes']);
        $d = [];
        $set = fn (string $k) => $create || $v->has($k);
        if ($set('name')) { $d['name'] = $v->string('name', 1, 150); }
        if ($set('contact_name')) { $d['contact_name'] = $v->string('contact_name', 1, 150, false); }
        if ($set('phone')) { $d['phone'] = $v->string('phone', 3, 40, false); }
        if ($set('email')) { $d['email'] = $v->email('email', false); }
        if ($set('address')) { $d['address'] = $v->string('address', 1, 300, false); }
        if ($set('notes')) { $d['notes'] = $v->string('notes', 1, 1000, false); }
        $v->done();
        return $d;
    }

    /** @param array<string,mixed> $s @return array<string,mixed> */
    private function supplierView(array $s): array
    {
        return ['id' => $s['id'], 'name' => $s['name'], 'contact_name' => $s['contact_name'], 'phone' => $s['phone'], 'email' => $s['email'], 'address' => $s['address'], 'notes' => $s['notes'],
            'archived' => $s['archived_at'] !== null, 'order_count' => (int) $s['order_count'], 'open_orders' => (int) $s['open_orders'], 'created_at' => $s['created_at']];
    }

    /** @param array<string,mixed> $o @return array<string,mixed> */
    private function orderBase(array $o): array
    {
        return ['id' => $o['id'], 'order_number' => $o['order_number'], 'status' => $o['status'], 'supplier_id' => $o['supplier_id'], 'supplier_name' => $o['supplier_name'],
            'outlet_id' => $o['outlet_id'], 'outlet_name' => $o['outlet_name'], 'total_cost' => $o['total_cost'], 'notes' => $o['notes'],
            'ordered_at' => $o['ordered_at'], 'received_at' => $o['received_at'], 'created_at' => $o['created_at']];
    }

    /** @param array<string,mixed> $o @return array<string,mixed> */
    private function orderView(TenantContext $t, array $o): array
    {
        $items = $this->repo->items($t->businessId, (string) $o['id']);
        $received = Money::zero();
        foreach ($items as $i) { $received = $received->add(Money::parse($i['unit_cost'])->mulInt((int) $i['quantity_received'])); }
        return $this->orderBase($o) + [
            'received_value' => $received->toString(),
            'can_edit' => $o['status'] === 'draft',
            'items' => array_map(fn ($i) => ['id' => $i['id'], 'product_id' => $i['product_id'], 'product_name' => $i['product_name'], 'quantity_ordered' => (int) $i['quantity_ordered'],
                'quantity_received' => (int) $i['quantity_received'], 'outstanding' => (int) $i['quantity_ordered'] - (int) $i['quantity_received'], 'unit_cost' => $i['unit_cost'], 'line_total' => $i['line_total'],
                'stocked' => $i['product_id'] !== null], $items),
            'receipts' => $this->repo->receipts($t->businessId, (string) $o['id']),
        ];
    }

    private function now(): string
    {
        return \LeenTrack\Core\Clock::now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    /** @param array<string,mixed> $q */
    private function qUuid(array $q, string $k): ?string
    {
        if (!isset($q[$k])) { return null; }
        if (!is_string($q[$k]) || !Uuid::isValid($q[$k])) { throw ApiException::validation([$k => ['Must be a valid id.']]); }
        return $q[$k];
    }

    /** UTC instant of midnight (UTC) of the date + $plusDays. @param array<string,mixed> $q */
    private function qDate(array $q, string $k, int $plusDays): ?string
    {
        if (!isset($q[$k])) { return null; }
        $d = is_string($q[$k]) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $q[$k], new \DateTimeZone('UTC')) : false;
        if (!$d || $d->format('Y-m-d') !== $q[$k]) { throw ApiException::validation([$k => ['Must be a date as YYYY-MM-DD.']]); }
        return $d->modify("+$plusDays day")->format('Y-m-d H:i:s');
    }
}
