<?php
declare(strict_types=1);

namespace LeenTrack\Sync;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Pager;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\TenantRepository;
use LeenTrack\Services\AuditService;
use LeenTrack\Services\CreditService;
use LeenTrack\Services\CustomerService;
use LeenTrack\Services\ExpenseService;
use LeenTrack\Services\PlanCatalog;
use LeenTrack\Services\SaleService;
use LeenTrack\Services\StockService;
use PDO;

/**
 * Offline push. A device queues what the cashier did; this applies each operation THROUGH THE SAME SERVICES the online API uses, so every
 * rule (prices, stock, permissions, plan limits, loyalty, credit) stays in one place. See docs/14_SYNC_PROTOCOL.md for the contract.
 *
 * Outcome per operation: applied | conflict (state-dependent, may succeed later or after a person decides) | rejected (will not succeed as sent)
 * | discarded (a manager gave up on it) | error (server trouble: nothing recorded, send again). Applied/rejected/discarded outcomes are remembered,
 * so re-sending an operation is always safe and returns the same answer (`duplicate: true`).
 */
final class SyncService
{
    public const MAX_OPERATIONS = 100;
    public const MAX_AGE_DAYS = 60;
    public const MAX_FUTURE_SECONDS = 120;
    public const TYPES = ['sale.checkout', 'credit.repay', 'customer.create', 'expense.create', 'stock.adjust'];
    /** codes that mean "the world is not ready for this yet / disagrees", as opposed to "this operation is wrong" */
    private const CONFLICT_CODES = ['insufficient_stock', 'price_changed', 'not_found', 'dependency_missing'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly TenantRepository $tenants,
        private readonly SaleService $sales,
        private readonly CreditService $credit,
        private readonly CustomerService $customers,
        private readonly ExpenseService $expenses,
        private readonly StockService $stock,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function info(): array
    {
        return ['server_time' => Clock::now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'), 'protocol' => 1, 'max_operations_per_push' => self::MAX_OPERATIONS,
            'max_operation_age_days' => self::MAX_AGE_DAYS, 'operation_types' => self::TYPES, 'pull_resources' => SyncPullService::RESOURCES,
            'online_only' => ['sale.edit', 'sale.void', 'purchase.*', 'transfer.*', 'billing.*', 'staff.*', 'product edits', 'reports']];
    }

    // ================================================================================================ push
    /**
     * @param array<string,mixed> $body device_id, device_time, operations[]
     * @return array<string,mixed>
     */
    public function push(TenantContext $t, array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['device_id', 'device_time', 'operations']);
        $device = $v->string('device_id', 8, 64);
        $deviceTime = $v->has('device_time') ? $v->string('device_time', 10, 40) : null;
        $ops = $body['operations'] ?? null;
        if (!is_array($ops) || $ops === [] || !array_is_list($ops) || count($ops) > self::MAX_OPERATIONS) {
            $v->addError('operations', 'Send between 1 and ' . self::MAX_OPERATIONS . ' operations per request.');
        }
        $skew = 0;
        if ($deviceTime !== null) {
            try { $skew = Clock::now()->getTimestamp() - (new \DateTimeImmutable($deviceTime))->getTimestamp(); } catch (\Exception) { $v->addError('device_time', 'Must be an ISO-8601 timestamp.'); }
            if (abs($skew) > self::MAX_AGE_DAYS * 86400) { $v->addError('device_time', 'The device clock is wildly wrong. Set the date and time on the device.'); }
        }
        $v->done();
        $now = Clock::now();
        $results = []; $seen = [];
        foreach ($ops as $i => $op) {
            $results[] = $this->one($t, $device ?? '', is_array($op) ? $op : [], $skew, $now, $req, $seen);
        }
        $count = array_count_values(array_column($results, 'status'));
        return ['server_time' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'), 'clock_skew_seconds' => $skew, 'results' => $results,
            'summary' => ['applied' => $count['applied'] ?? 0, 'conflict' => $count['conflict'] ?? 0, 'rejected' => $count['rejected'] ?? 0, 'discarded' => $count['discarded'] ?? 0, 'error' => $count['error'] ?? 0]];
    }

    /** @param array<string,mixed> $op @param array<string,array<string,mixed>> $seen @return array<string,mixed> */
    private function one(TenantContext $t, string $device, array $op, int $skew, \DateTimeImmutable $now, Request $req, array &$seen): array
    {
        $id = is_string($op['id'] ?? null) && Uuid::isValid($op['id']) ? $op['id'] : null;
        if ($id === null) { return $this->out(null, 'rejected', 'invalid_operation', 'Every operation needs an id (a UUID made on the device).'); }
        $type = $op['type'] ?? null;
        $payload = $op['payload'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) { return $this->out($id, 'rejected', 'unsupported_type', 'Unknown or online-only operation type.'); }
        if (!is_array($payload) || array_is_list($payload) && $payload !== []) { return $this->out($id, 'rejected', 'invalid_operation', 'The payload must be an object.'); }
        $occurred = $this->occurredAt($op['client_created_at'] ?? null, $skew, $now);
        if ($occurred === false || $occurred === null) { return $this->out($id, 'rejected', 'invalid_operation', 'client_created_at (when the cashier did it, ISO-8601) is required.'); }
        $hash = $this->hash($type, $payload);
        if (isset($seen[$id])) {                                                      // the same id twice in one batch
            return $seen[$id]['hash'] === $hash ? ['duplicate' => true] + $seen[$id]['out'] : $this->out($id, 'rejected', 'idempotency_conflict', 'This operation id was used twice with different content.');
        }
        $row = $this->row($t->businessId, $id);
        if ($row !== null) {
            if (!hash_equals((string) $row['request_hash'], $hash)) { return $this->out($id, 'rejected', 'idempotency_conflict', 'This operation id was already used for a different operation.'); }
            if (in_array($row['status'], ['applied', 'rejected', 'discarded'], true)) { return $this->remember($seen, $id, $hash, ['duplicate' => true] + $this->fromRow($row)); }
            // a conflict being re-sent: try again, the world may have changed
        }
        if ($occurred < $now->modify('-' . self::MAX_AGE_DAYS . ' days')) {
            $out = $this->out($id, 'rejected', 'too_old', 'Operations older than ' . self::MAX_AGE_DAYS . ' days are not accepted. Ask a manager to record it online.');
            $this->save($t, $device, $id, $type, $hash, $payload, $out, $op['client_created_at'] ?? null, null, $row);
            return $this->remember($seen, $id, $hash, $out);
        }
        $occSql = Clock::sql($occurred);
        $out = $this->run($t, $type, $payload, $id, $occSql, $req);
        if ($out['status'] !== 'error') { $this->save($t, $device, $id, $type, $hash, $payload, $out, $op['client_created_at'] ?? null, $occSql, $row); }
        return $this->remember($seen, $id, $hash, $out);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function run(TenantContext $t, string $type, array $payload, string $opId, string $occSql, Request $req): array
    {
        try {
            return $this->out($opId, 'applied', null, null, null, $this->execute($t, $type, $payload, $opId, $occSql, $req));
        } catch (ApiException $e) {
            $fields = $e->fields;
            $code = $e->errorCode;
            if ($code === 'validation_failed' && isset($fields['customer_id'])) { $code = 'dependency_missing'; }          // the customer it refers to has not arrived (yet)
            $status = ($e->status >= 500) ? 'error' : (in_array($code, self::CONFLICT_CODES, true) ? 'conflict' : 'rejected');
            return $this->out($opId, $status, $code, $e->getMessage(), $fields);
        } catch (\Throwable $e) {
            error_log('sync operation failed: ' . get_class($e));
            return $this->out($opId, 'error', 'server_error', 'The server could not process this operation. Send it again.');
        }
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private function execute(TenantContext $t, string $type, array $p, string $opId, string $occSql, Request $req): array
    {
        switch ($type) {
            case 'sale.checkout':
                $body = $p; unset($body['client_operation_id']);
                $body['client_operation_id'] = $opId;
                $body['client_created_at'] = (new \DateTimeImmutable($occSql . ' UTC'))->format('Y-m-d\TH:i:s.v\Z');
                $x = $this->sales->checkout($t, $body, null, $req, $occSql, true);
                $s = $x['sale'];
                return ['sale_id' => $s['id'], 'receipt_number' => $s['receipt_number'], 'total_amount' => $s['total_amount'], 'payment_status' => $s['status'], 'replayed' => $x['replayed']];
            case 'credit.repay':
                $body = $p; $body['client_operation_id'] = $opId;
                $sale = $body['sale_id'] ?? null; unset($body['sale_id']);
                if (!is_string($sale) || !Uuid::isValid($sale)) { throw ApiException::validation(['sale_id' => ['A valid sale id is required.']]); }
                $x = $this->credit->repay($t, $sale, $body, null, $req);
                return ['repayment_id' => $x['result']['repayment']['id'] ?? null, 'sale_id' => $sale, 'replayed' => $x['replayed']];
            case 'customer.create':
                $id = $p['id'] ?? null; $body = $p; unset($body['id']);
                if (!is_string($id) || !Uuid::isValid($id)) { throw ApiException::validation(['id' => ['A new customer needs an id made on the device.']]); }
                return $this->createOnce($t, $id, 'customers', fn () => $this->customers->create($t, $body, $req, $id), 'customer_id');
            case 'expense.create':
                $id = $p['id'] ?? null; $body = $p; unset($body['id']);
                if (!is_string($id) || !Uuid::isValid($id)) { throw ApiException::validation(['id' => ['A new expense needs an id made on the device.']]); }
                return $this->createOnce($t, $id, 'expenses', fn () => $this->expenses->create($t, $body, $req, $id), 'expense_id');
            case 'stock.adjust':
                if (array_key_exists('new_quantity', $p)) { throw ApiException::validation(['new_quantity' => ['Stock counts cannot be queued offline: a count depends on stock the device may not see. Send a change (delta) instead, or count online.']]); }
                $x = $this->stock->adjust($t, $p, $req, $opId);
                return ['product_id' => $x['product_id'], 'location_id' => $x['location_id'], 'delta' => $x['delta'], 'quantity_after' => $x['quantity_after'], 'replayed' => $x['replayed'] ?? false];
        }
        throw new \LogicException('unreachable');
    }

    /** New records carry the id the device chose: if it already exists in THIS business it is the same record arriving again. @param callable():mixed $create @return array<string,mixed> */
    private function createOnce(TenantContext $t, string $id, string $table, callable $create, string $key): array
    {
        $exists = fn () => (function () use ($table, $id, $t) { $s = $this->pdo->prepare("SELECT 1 FROM `$table` WHERE business_id = ? AND id = ?"); $s->execute([$t->businessId, $id]); return (bool) $s->fetchColumn(); })();
        if ($exists()) { return [$key => $id, 'replayed' => true]; }
        try {
            $create();
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 && $exists()) { return [$key => $id, 'replayed' => true]; }        // lost a race with an identical duplicate
            throw $e;
        }
        return [$key => $id, 'replayed' => false];
    }

    // ================================================================================================ the inbox (conflicts etc.)
    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,mixed>} */
    public function operations(TenantContext $t, array $q): array
    {
        if ($extra = array_diff(array_keys($q), ['status', 'page', 'per_page'])) { throw ApiException::validation([(string) reset($extra) => ['Unknown filter.']]); }
        $status = $q['status'] ?? 'attention';
        if (!is_string($status) || !in_array($status, ['attention', 'conflict', 'rejected', 'applied', 'discarded'], true)) { throw ApiException::validation(['status' => ['Must be attention, conflict, rejected, applied or discarded.']]); }
        $w = ['business_id = ?']; $a = [$t->businessId];
        if ($status === 'attention') { $w[] = "status IN ('conflict','rejected')"; } else { $w[] = 'status = ?'; $a[] = $status; }
        if (!$t->can('sales.edit')) { $w[] = 'user_id = ?'; $a[] = $t->userId; }                 // cashiers see only their own
        $where = implode(' AND ', $w);
        $pg = Pager::fromQuery($q, 50);
        $c = $this->pdo->prepare("SELECT COUNT(*) FROM sync_operations WHERE $where");
        $c->execute($a);
        $st = $this->pdo->prepare("SELECT * FROM sync_operations WHERE $where ORDER BY id DESC LIMIT {$pg->perPage} OFFSET {$pg->offset()}");
        $st->execute($a);
        return ['rows' => array_map($this->opView(...), $st->fetchAll()), 'meta' => $pg->meta((int) $c->fetchColumn())];
    }

    /** Manager decision: try the stored operation again, now. @return array<string,mixed> */
    public function retry(TenantContext $t, string $opId, Request $req, bool $acceptPrices = false): array
    {
        $t->require('sales.edit');
        $row = $this->attentionRow($t, $opId);
        $payload = json_decode((string) $row['payload'], true) ?: [];
        if ($acceptPrices) {
            if ($row['code'] !== 'price_changed') { throw ApiException::conflict('not_price_conflict', 'Only an operation refused because prices changed can be accepted at the new prices.'); }
            unset($payload['expected_total']);
        }
        $as = $this->contextOf((string) $row['user_id'], $t);                                  // it is recorded as the original cashier's sale
        $occ = $row['occurred_at'] ?? Clock::sql();
        $out = $this->run($as, (string) $row['type'], $payload, $opId, (string) $occ, $req);
        if ($out['status'] === 'error') { throw new ApiException('server_error', 'The server could not process this operation. Try again.', 503); }
        // Found by the Flutter end-to-end test (G-84): at a HIGHER price the cash the customer already handed over may no longer cover the sale.
        // Do not destroy the held operation (it would turn into a terminal "rejected"); leave it as it was and tell the manager exactly why.
        if ($acceptPrices && $out['status'] === 'rejected' && isset($out['fields']['payment.amount'])) {
            throw ApiException::conflict('payment_short', 'At the new prices the sale costs more than the customer paid. The operation is unchanged: record the difference with the customer, then record this sale online, or discard it.');
        }
        // request_hash stays what the DEVICE sent (its identity for duplicate detection), even after a manager changed the stored payload (accept-prices)
        $this->pdo->prepare('UPDATE sync_operations SET status = ?, code = ?, message = ?, result = ?, payload = ?, processed_at = ?, resolved_by = ?, resolved_at = ? WHERE id = ?')
            ->execute([$out['status'], $out['code'] ?? null, isset($out['message']) ? mb_substr((string) $out['message'], 0, 300) : null, isset($out['result']) ? json_encode($out['result']) : null,
                $out['status'] === 'applied' ? null : json_encode($payload), Clock::sql(), $t->userId, Clock::sql(), $row['id']]);
        $this->audit->log($acceptPrices ? 'sync.accept_prices' : 'sync.retry', $req, $t->userId, $t->businessId, 'sync_operation', $opId, ['status' => $row['status'], 'code' => $row['code']], ['status' => $out['status'], 'code' => $out['code'] ?? null]);
        return $this->opView($this->row($t->businessId, $opId) ?? []);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function discard(TenantContext $t, string $opId, array $body, Request $req): array
    {
        $t->require('sales.edit');
        $v = new Validator($body);
        $v->only(['reason']);
        $reason = $v->string('reason', 5, 300);
        $v->done();
        $row = $this->attentionRow($t, $opId);
        $this->pdo->prepare("UPDATE sync_operations SET status = 'discarded', message = ?, resolved_by = ?, resolved_at = ? WHERE id = ?")->execute([$reason, $t->userId, Clock::sql(), $row['id']]);
        $this->audit->log('sync.discard', $req, $t->userId, $t->businessId, 'sync_operation', $opId, ['status' => $row['status'], 'code' => $row['code'], 'type' => $row['type']], ['reason' => $reason]);
        return $this->opView($this->row($t->businessId, $opId) ?? []);
    }

    // ================================================================================================ helpers
    /** @return array<string,mixed> */
    private function attentionRow(TenantContext $t, string $opId): array
    {
        if (!Uuid::isValid($opId)) { throw ApiException::notFound(); }
        $row = $this->row($t->businessId, $opId) ?? throw ApiException::notFound();
        if (!in_array($row['status'], ['conflict', 'rejected'], true)) { throw ApiException::conflict('not_pending', 'This operation is ' . $row['status'] . ' and needs no decision.'); }
        return $row;
    }

    /** The original cashier's context (so permissions and the sale's cashier are theirs); falls back to refusing when they have left. */
    private function contextOf(string $userId, TenantContext $manager): TenantContext
    {
        if ($userId === $manager->userId) { return $manager; }
        $m = $this->tenants->activeMembership($userId, $manager->businessId);
        if ($m === null) { throw ApiException::conflict('user_inactive', 'The person who made this sale is no longer on the team. Record it manually and discard this entry.'); }
        $outlets = $this->tenants->memberOutlets((string) $m['id']);
        return new TenantContext($manager->businessId, $userId, (string) $m['id'], (string) $m['role'], PlanCatalog::effective($m), $outlets === [] ? null : $outlets);
    }

    /** @return \DateTimeImmutable|null|false null = missing, false = malformed */
    private function occurredAt(mixed $raw, int $skew, \DateTimeImmutable $now): \DateTimeImmutable|null|false
    {
        if ($raw === null) { return null; }
        if (!is_string($raw)) { return false; }
        try { $t = (new \DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('UTC')); } catch (\Exception) { return false; }
        $adj = $t->modify(($skew >= 0 ? '+' : '') . $skew . ' seconds');
        $max = $now->modify('+' . self::MAX_FUTURE_SECONDS . ' seconds');
        return $adj > $max ? $now : $adj;                                                    // never in the future, whatever the device clock said
    }

    /** Identity of what the device sent, independent of the order of keys in its JSON. @param array<string,mixed> $payload */
    private function hash(string $type, array $payload): string
    {
        $canon = function ($v) use (&$canon) {
            if (!is_array($v)) { return $v; }
            $v = array_map($canon, $v);
            if (!array_is_list($v)) { ksort($v); }
            return $v;
        };
        return hash('sha256', json_encode([$type, $canon($payload)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $seen */
    private function remember(array &$seen, string $id, string $hash, array $out): array
    {
        $seen[$id] = ['hash' => $hash, 'out' => $out];
        return $out;
    }

    /** @param array<string,mixed>|null $fields @param array<string,mixed>|null $result @return array<string,mixed> */
    private function out(?string $id, string $status, ?string $code = null, ?string $message = null, ?array $fields = null, ?array $result = null): array
    {
        return array_filter(['id' => $id, 'status' => $status, 'duplicate' => false, 'code' => $code, 'message' => $message, 'fields' => $fields ?: null, 'result' => $result], fn ($v) => $v !== null) + ['duplicate' => false];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function fromRow(array $r): array
    {
        return $this->out((string) $r['client_operation_id'], (string) $r['status'], $r['code'], $r['message'], null, $r['result'] === null ? null : json_decode((string) $r['result'], true));
    }

    /** @return array<string,mixed>|null */
    private function row(string $bid, string $opId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM sync_operations WHERE business_id = ? AND client_operation_id = ?');
        $st->execute([$bid, $opId]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $out @param array<string,mixed>|null $existing */
    private function save(TenantContext $t, string $device, string $id, string $type, string $hash, array $payload, array $out, mixed $clientAt, ?string $occSql, ?array $existing): void
    {
        $keep = $out['status'] === 'applied' ? null : json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ca = null;
        if (is_string($clientAt)) { try { $ca = Clock::sql((new \DateTimeImmutable($clientAt))->setTimezone(new \DateTimeZone('UTC'))); } catch (\Exception) { $ca = null; } }
        $msg = isset($out['message']) ? mb_substr((string) $out['message'], 0, 300) : null;
        $res = isset($out['result']) ? json_encode($out['result']) : null;
        if ($existing !== null) {
            $this->pdo->prepare('UPDATE sync_operations SET status = ?, code = ?, message = ?, payload = ?, result = ?, processed_at = ?, occurred_at = COALESCE(?, occurred_at) WHERE id = ?')
                ->execute([$out['status'], $out['code'] ?? null, $msg, $keep, $res, Clock::sql(), $occSql, $existing['id']]);
            return;
        }
        $this->pdo->prepare('INSERT IGNORE INTO sync_operations (business_id,client_operation_id,device_id,user_id,type,status,code,message,request_hash,payload,result,client_created_at,occurred_at,received_at,processed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$t->businessId, $id, $device !== '' ? $device : null, $t->userId, $type, $out['status'], $out['code'] ?? null, $msg, $hash, $keep, $res, $ca, $occSql, Clock::sql(), Clock::sql()]);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function opView(array $r): array
    {
        $iso = fn (?string $s) => $s === null ? null : (new \DateTimeImmutable($s, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        return ['id' => $r['client_operation_id'], 'type' => $r['type'], 'status' => $r['status'], 'code' => $r['code'], 'message' => $r['message'], 'device_id' => $r['device_id'], 'user_id' => $r['user_id'],
            'client_created_at' => $iso($r['client_created_at']), 'occurred_at' => $iso($r['occurred_at']), 'received_at' => $iso($r['received_at']), 'resolved_at' => $iso($r['resolved_at']),
            'payload' => $r['payload'] === null ? null : json_decode((string) $r['payload'], true), 'result' => $r['result'] === null ? null : json_decode((string) $r['result'], true)];
    }
}
