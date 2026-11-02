<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Db;
use LeenTrack\Core\Pager;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\CatalogRepository;
use LeenTrack\Repositories\TransferRepository;
use PDO;

/**
 * Stock transfers between outlets. Unlike the source (a status label that moved nothing), stock really moves:
 * dispatch debits the source, receive credits the destination by what arrived, and any shortfall stays recorded as a variance.
 * Lock order: transfer row -> product rows (ascending id) -> level rows. Stock changes only via StockService::applyDelta.
 */
final class TransferService
{
    private const STATUSES = ['pending', 'in_transit', 'completed', 'cancelled'];
    private const MAX_LINES = 200;
    private const MAX_QTY = 1_000_000;

    public function __construct(private readonly PDO $pdo, private readonly TransferRepository $repo, private readonly CatalogRepository $catalog, private readonly StockService $stock, private readonly AuditService $audit)
    {
    }

    // ================================================================================================ read
    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,mixed>} */
    public function list(TenantContext $t, array $q): array
    {
        $t->require('transfers.manage');
        if ($extra = array_diff(array_keys($q), ['status', 'location_id', 'search', 'from', 'to', 'page', 'per_page'])) { throw ApiException::validation([(string) reset($extra) => ['Unknown filter.']]); }
        $status = null;
        if (isset($q['status'])) { $status = is_string($q['status']) && in_array($q['status'], self::STATUSES, true) ? $q['status'] : throw ApiException::validation(['status' => ['Not a valid status.']]); }
        $loc = null;
        if (isset($q['location_id'])) {
            if (!is_string($q['location_id']) || !Uuid::isValid($q['location_id'])) { throw ApiException::validation(['location_id' => ['Must be a valid id.']]); }
            if (!$t->canAccessOutlet($q['location_id'])) { throw ApiException::notFound('Outlet not found.'); }
            $loc = $q['location_id'];
        }
        $f = ['status' => $status, 'location_id' => $loc, 'from' => $this->qDate($q, 'from', 0), 'to' => $this->qDate($q, 'to', 1),
            'search' => isset($q['search']) ? (is_string($q['search']) && $q['search'] !== '' && mb_strlen($q['search']) <= 100 ? $q['search'] : throw ApiException::validation(['search' => ['Must be text up to 100 characters.']])) : null];
        $pg = Pager::fromQuery($q, 50);
        $r = $this->repo->list($t->businessId, $f, $t->outletIds, $pg->perPage, $pg->offset());
        return ['rows' => array_map(fn ($x) => $this->base($x) + ['item_count' => (int) $x['item_count']], $r['rows']), 'meta' => $pg->meta($r['total']) + ['summary' => ['by_status' => $r['byStatus']]]];
    }

    /** @return array<string,mixed> */
    public function get(TenantContext $t, string $id): array
    {
        $t->require('transfers.manage');
        return $this->view($t, $this->find($t, $id));
    }

    // ================================================================================================ write
    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function create(TenantContext $t, array $body, Request $req): array
    {
        $t->require('transfers.manage');
        $v = new Validator($body);
        $v->only(['from_location_id', 'to_location_id', 'notes', 'items']);
        $from = $v->uuid('from_location_id');
        $to = $v->uuid('to_location_id');
        $notes = $v->string('notes', 1, 1000, false);
        if ($from !== null && $from === $to) { $v->addError('to_location_id', 'Choose a different outlet from the one sending.'); }
        $v->done();
        $items = $this->parseItems($t, $body['items'] ?? null);
        $this->checkLocation($t, (string) $from, 'from_location_id', true);
        $this->checkLocation($t, (string) $to, 'to_location_id', false);
        $id = Uuid::v4();
        Db::transaction($this->pdo, function () use ($t, $id, $from, $to, $notes, $items) {
            $number = sprintf('TR-%06d', $this->repo->nextSequence($t->businessId));
            $this->repo->insert(['id' => $id, 'business_id' => $t->businessId, 'transfer_number' => $number, 'from' => $from, 'to' => $to, 'notes' => $notes, 'created_by' => $t->userId]);
            $this->repo->replaceItems($t->businessId, $id, $items);
        });
        $this->audit->log('transfer.create', $req, $t->userId, $t->businessId, 'transfer', $id, null, ['from' => $from, 'to' => $to, 'lines' => count($items)]);
        return $this->view($t, $this->find($t, $id));
    }

    /** Pending only. The sending outlet is fixed; destination, notes and lines can change. @param array<string,mixed> $body @return array<string,mixed> */
    public function update(TenantContext $t, string $id, array $body, Request $req): array
    {
        $t->require('transfers.manage');
        $cur = $this->find($t, $id);
        $this->requireAccess($t, (string) $cur['from_location_id']);
        $v = new Validator($body);
        $v->only(['to_location_id', 'notes', 'items']);
        $fields = [];
        if ($v->has('to_location_id')) { $fields['to_location_id'] = $v->uuid('to_location_id'); }
        if ($v->has('notes')) { $fields['notes'] = $v->string('notes', 1, 1000, false); }
        if (isset($fields['to_location_id']) && $fields['to_location_id'] === $cur['from_location_id']) { $v->addError('to_location_id', 'Choose a different outlet from the one sending.'); }
        $v->done();
        $items = array_key_exists('items', $body) ? $this->parseItems($t, $body['items']) : null;
        if (isset($fields['to_location_id'])) { $this->checkLocation($t, $fields['to_location_id'], 'to_location_id', false); }
        Db::transaction($this->pdo, function () use ($t, $id, $fields, $items) {
            $l = $this->repo->lock($t->businessId, $id) ?? throw ApiException::notFound();
            if ($l['status'] !== 'pending') { throw new ApiException('not_pending', 'Only a transfer that has not been sent can be changed.', 409); }
            $this->repo->update($t->businessId, $id, $fields);
            if ($items !== null) { $this->repo->replaceItems($t->businessId, $id, $items); }
        });
        $this->audit->log('transfer.update', $req, $t->userId, $t->businessId, 'transfer', $id, null, array_keys($fields) + ($items !== null ? ['items' => count($items)] : []));
        return $this->view($t, $this->find($t, $id));
    }

    /**
     * pending -> in_transit: the source is debited by what is actually shipped.
     * @param array<string,mixed> $body client_operation_id, items?:[{item_id, quantity}] (omitted lines ship in full)
     * @return array{result:array<string,mixed>,replayed:bool}
     */
    public function dispatch(TenantContext $t, string $id, array $body, ?string $headerKey, Request $req): array
    {
        return $this->move($t, $id, $body, $headerKey, $req, 'dispatch');
    }

    /**
     * in_transit -> completed: the destination is credited by what arrived; the difference is the recorded variance.
     * @param array<string,mixed> $body client_operation_id, items?:[{item_id, quantity}] (omitted lines arrived in full)
     * @return array{result:array<string,mixed>,replayed:bool}
     */
    public function receive(TenantContext $t, string $id, array $body, ?string $headerKey, Request $req): array
    {
        return $this->move($t, $id, $body, $headerKey, $req, 'receive');
    }

    /** pending -> cancelled; in_transit -> cancelled returns the shipped stock to the sender. @return array<string,mixed> */
    public function cancel(TenantContext $t, string $id, Request $req): array
    {
        $t->require('transfers.manage');
        $cur = $this->find($t, $id);
        Db::transaction($this->pdo, function () use ($t, $id, $req, $cur) {
            $l = $this->repo->lock($t->businessId, $id) ?? throw ApiException::notFound();
            if (!in_array($l['status'], ['pending', 'in_transit'], true)) { throw new ApiException('invalid_status', 'This transfer is ' . $l['status'] . ' and cannot be cancelled.', 409); }
            $returned = 0;
            if ($l['status'] === 'in_transit') {
                $items = $this->byProduct($this->repo->items($t->businessId, $id, true));
                foreach ($items as $pid => $it) {
                    $q = (int) $it['quantity_shipped'];
                    if ($q > 0) { $this->stock->applyDelta($t->businessId, (string) $pid, (string) $l['from_location_id'], $q, 'transfer_return', 'transfer', $id, $l['transfer_number'], $t->userId); $returned += $q; }
                }
            }
            $this->repo->update($t->businessId, $id, ['status' => 'cancelled', 'cancelled_at' => $this->now()]);
            $this->audit->log('transfer.cancel', $req, $t->userId, $t->businessId, 'transfer', $id, ['status' => $l['status']], ['returned_units' => $returned]);
        });
        return $this->view($t, $this->find($t, $id));
    }

    // ================================================================================================ dispatch / receive engine
    /** @param array<string,mixed> $body @return array{result:array<string,mixed>,replayed:bool} */
    private function move(TenantContext $t, string $id, array $body, ?string $headerKey, Request $req, string $kind): array
    {
        $t->require('transfers.manage');
        if (!Uuid::isValid($id)) { throw ApiException::notFound(); }
        $v = new Validator($body);
        $v->only(['client_operation_id', 'items']);
        $op = $v->has('client_operation_id') ? $v->uuid('client_operation_id') : null;
        if ($op === null && $headerKey !== null) {
            if (Uuid::isValid($headerKey)) { $op = $headerKey; } else { $v->addError('client_operation_id', 'Idempotency-Key must be a UUID.'); }
        }
        if ($op === null && !$v->has('client_operation_id')) { $v->addError('client_operation_id', 'This field is required (a UUID generated by the device, reused on retry).'); }
        $lines = [];
        if (array_key_exists('items', $body) && $body['items'] !== null) {
            $raw = $body['items'];
            if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::MAX_LINES) {
                $v->addError('items', 'Provide a list of items, or leave it out to use the full quantities.');
            } else {
                $seen = [];
                foreach ($raw as $i => $row) {
                    if (!is_array($row)) { $v->addError("items.$i", 'Must be an object.'); continue; }
                    $iv = new Validator($row);
                    $iv->only(['item_id', 'quantity']);
                    $itemId = $iv->uuid('item_id');
                    $qty = $iv->int('quantity', 0, self::MAX_QTY);
                    try { $iv->done(); } catch (ApiException $e) { foreach ($e->fields as $k => $m) { foreach ($m as $msg) { $v->addError("items.$i.$k", $msg); } } continue; }
                    if (isset($seen[$itemId])) { $v->addError("items.$i.item_id", 'Each item can appear only once.'); continue; }
                    $seen[$itemId] = true;
                    $lines[(string) $itemId] = (int) $qty;
                }
            }
        }
        $v->done();
        ksort($lines);
        $hash = hash('sha256', json_encode([$kind, $id, $lines], JSON_THROW_ON_ERROR));
        $cur = $this->find($t, $id);
        $this->requireAccess($t, (string) $cur[$kind === 'dispatch' ? 'from_location_id' : 'to_location_id']);
        try {
            return Db::transaction($this->pdo, fn () => $this->moveTx($t, $id, $kind, $lines, (string) $op, $hash, $req));
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains((string) $e->getMessage(), "uq_transfer_{$kind}_op")) {
                throw new ApiException('idempotency_conflict', 'This operation id was already used for a different transfer.', 409);
            }
            throw $e;
        }
    }

    /** @param array<string,int> $lines item_id => quantity @return array{result:array<string,mixed>,replayed:bool} */
    private function moveTx(TenantContext $t, string $id, string $kind, array $lines, string $op, string $hash, Request $req): array
    {
        $bid = $t->businessId;
        $tr = $this->repo->lock($bid, $id) ?? throw ApiException::notFound();                          // 1. the transfer
        $need = $kind === 'dispatch' ? 'pending' : 'in_transit';
        $opCol = "{$kind}_operation_id"; $hashCol = "{$kind}_hash";
        if ($tr[$opCol] !== null && $tr[$opCol] === $op) {                                              // replay, decided under the lock
            if (!hash_equals((string) $tr[$hashCol], $hash)) { throw new ApiException('idempotency_conflict', 'This operation id was already used for a different request.', 409); }
            return ['result' => $this->view($t, $this->find($t, $id) ?? []), 'replayed' => true];
        }
        if ($tr['status'] !== $need) {
            throw new ApiException('invalid_status', $kind === 'dispatch' ? ($tr['status'] === 'cancelled' ? 'This transfer was cancelled.' : 'This transfer has already been sent.') : ($tr['status'] === 'pending' ? 'Send the transfer before receiving it.' : 'This transfer is ' . $tr['status'] . ' and cannot be received.'), 409);
        }
        $items = [];
        foreach ($this->repo->items($bid, $id, true) as $it) { $items[$it['id']] = $it; }
        $errors = [];
        foreach ($lines as $itemId => $q) {
            $it = $items[$itemId] ?? null;
            if ($it === null) { $errors["items.$itemId"] = ['This item is not on this transfer.']; continue; }
            $max = $kind === 'dispatch' ? (int) $it['quantity_requested'] : (int) $it['quantity_shipped'];
            if ($q > $max) { $errors["items.$itemId.quantity"] = ["At most $max of \"{$it['product_name']}\" can be " . ($kind === 'dispatch' ? 'sent.' : 'received (that is what was sent).')]; }
        }
        if ($errors) { throw new ApiException('over_quantity', 'A quantity is higher than allowed.', 409, $errors); }
        $qty = [];
        foreach ($items as $itemId => $it) { $qty[$itemId] = $lines[$itemId] ?? ($kind === 'dispatch' ? (int) $it['quantity_requested'] : (int) $it['quantity_shipped']); }
        if ($kind === 'dispatch' && array_sum($qty) === 0) { throw ApiException::validation(['items' => ['Send at least one unit.']]); }

        $order = [];
        foreach ($items as $itemId => $it) { $order[(string) $it['product_id']] = $itemId; }
        ksort($order, SORT_STRING);                                                                      // 2. products ascending
        $units = 0; $variance = 0;
        foreach ($order as $pid => $itemId) {
            $q = $qty[$itemId];
            if ($kind === 'dispatch') {
                if ($q > 0) { $this->stock->applyDelta($bid, $pid, (string) $tr['from_location_id'], -$q, 'transfer_out', 'transfer', $id, $tr['transfer_number'], $t->userId); }
                $this->repo->setShipped($itemId, $q);
            } else {
                if ($q > 0) { $this->stock->applyDelta($bid, $pid, (string) $tr['to_location_id'], $q, 'transfer_in', 'transfer', $id, $tr['transfer_number'], $t->userId); }
                $this->repo->setReceived($itemId, $q);
                $variance += (int) $items[$itemId]['quantity_shipped'] - $q;
            }
            $units += $q;
        }
        $this->repo->update($bid, $id, $kind === 'dispatch'
            ? ['status' => 'in_transit', 'shipped_at' => $this->now(), 'dispatch_operation_id' => $op, 'dispatch_hash' => $hash]
            : ['status' => 'completed', 'received_at' => $this->now(), 'receive_operation_id' => $op, 'receive_hash' => $hash]);
        $this->audit->log("transfer.$kind", $req, $t->userId, $bid, 'transfer', $id, null, ['units' => $units] + ($kind === 'receive' ? ['variance_units' => $variance] : []));
        return ['result' => $this->view($t, $this->find($t, $id) ?? []), 'replayed' => false];
    }

    // ================================================================================================ helpers
    /** @param list<array<string,mixed>> $items @return array<string,array<string,mixed>> keyed by product id, ascending */
    private function byProduct(array $items): array
    {
        $o = [];
        foreach ($items as $it) { $o[(string) $it['product_id']] = $it; }
        ksort($o, SORT_STRING);
        return $o;
    }

    /** Visible when either end is the user's. @return array<string,mixed> */
    private function find(TenantContext $t, string $id): array
    {
        if (!Uuid::isValid($id)) { throw ApiException::notFound(); }
        $x = $this->repo->find($t->businessId, $id) ?? throw ApiException::notFound();
        if ($t->outletIds !== null && !$t->canAccessOutlet((string) $x['from_location_id']) && !$t->canAccessOutlet((string) $x['to_location_id'])) { throw ApiException::notFound(); }
        return $x;
    }

    private function requireAccess(TenantContext $t, string $locationId): void
    {
        if (!$t->canAccessOutlet($locationId)) { throw ApiException::forbidden('You do not manage that outlet.'); }
    }

    private function checkLocation(TenantContext $t, string $id, string $field, bool $mustManage): void
    {
        if (!$this->catalog->locationBelongs($t->businessId, $id)) { throw ApiException::validation([$field => ['Outlet not found.']]); }
        if ($mustManage && !$t->canAccessOutlet($id)) { throw ApiException::validation([$field => ['You can only send stock from your own outlets.']]); }
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
            $v->only(['product_id', 'quantity']);
            $pid = $v->uuid('product_id');
            $qty = $v->int('quantity', 1, self::MAX_QTY);
            try { $v->done(); } catch (ApiException $e) { foreach ($e->fields as $k => $m) { $errors["items.$i.$k"] = $m; } continue; }
            if (isset($seen[$pid])) { $errors["items.$i.product_id"] = ['Each product can appear only once.']; continue; }
            $seen[$pid] = true;
            $p = $this->catalog->product($t->businessId, (string) $pid);
            if ($p === null) { $errors["items.$i.product_id"] = ['Product not found.']; continue; }
            if ($p['item_type'] !== 'product') { $errors["items.$i.product_id"] = ['Services are not stocked, so they cannot be transferred.']; continue; }
            $out[] = ['id' => Uuid::v4(), 'product_id' => $pid, 'product_name' => (string) $p['name'], 'quantity' => (int) $qty];
        }
        if ($errors) { throw ApiException::validation($errors); }
        return $out;
    }

    /** @param array<string,mixed> $x @return array<string,mixed> */
    private function base(array $x): array
    {
        return ['id' => $x['id'], 'transfer_number' => $x['transfer_number'], 'status' => $x['status'],
            'from_location_id' => $x['from_location_id'], 'from_name' => $x['from_name'], 'to_location_id' => $x['to_location_id'], 'to_name' => $x['to_name'],
            'notes' => $x['notes'], 'shipped_at' => $x['shipped_at'], 'received_at' => $x['received_at'], 'cancelled_at' => $x['cancelled_at'], 'created_at' => $x['created_at']];
    }

    /** @param array<string,mixed> $x @return array<string,mixed> */
    private function view(TenantContext $t, array $x): array
    {
        $items = $this->repo->items($t->businessId, (string) $x['id']);
        $variance = 0;
        $rows = array_map(function ($i) use (&$variance) {
            $var = $i['quantity_shipped'] !== null && $i['quantity_received'] !== null ? (int) $i['quantity_shipped'] - (int) $i['quantity_received'] : null;
            $variance += $var ?? 0;
            return ['id' => $i['id'], 'product_id' => $i['product_id'], 'product_name' => $i['product_name'], 'quantity_requested' => (int) $i['quantity_requested'],
                'quantity_shipped' => $i['quantity_shipped'] === null ? null : (int) $i['quantity_shipped'], 'quantity_received' => $i['quantity_received'] === null ? null : (int) $i['quantity_received'], 'variance' => $var];
        }, $items);
        return $this->base($x) + ['can_edit' => $x['status'] === 'pending', 'variance_units' => $variance, 'items' => $rows];
    }

    private function now(): string
    {
        return Clock::now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    /** @param array<string,mixed> $q */
    private function qDate(array $q, string $k, int $plusDays): ?string
    {
        if (!isset($q[$k])) { return null; }
        $d = is_string($q[$k]) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $q[$k], new \DateTimeZone('UTC')) : false;
        if (!$d || $d->format('Y-m-d') !== $q[$k]) { throw ApiException::validation([$k => ['Must be a date as YYYY-MM-DD.']]); }
        return $d->modify("+$plusDays day")->format('Y-m-d H:i:s');
    }
}
