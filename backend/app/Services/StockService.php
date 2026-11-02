<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Db;
use LeenTrack\Core\Pager;
use LeenTrack\Core\Request;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\CatalogRepository;
use PDO;

/**
 * The ONLY code allowed to change stock. Invariant maintained inside one transaction:
 *   products.stock_quantity = SUM(stock_levels.quantity), and every change has a stock_movements row.
 * Lock order is always product row -> level row, so concurrent adjustments serialise instead of deadlocking.
 */
final class StockService
{
    /** reason => required sign (+1 adds stock, -1 removes, 0 either) */
    public const ADJUST_REASONS = ['correction' => 0, 'count' => 0, 'found' => 1, 'return' => 1, 'damaged' => -1, 'write_off' => -1, 'expired' => -1, 'theft' => -1];
    private const MAX_QTY = 10_000_000;

    public function __construct(private readonly PDO $pdo, private readonly CatalogRepository $repo, private readonly AuditService $audit)
    {
    }

    /**
     * Low-level change used by adjustments, imports, opening stock and (later) sales/purchases/transfers.
     * MUST run inside a transaction opened by the caller. @return int resulting quantity at the location
     */
    public function applyDelta(string $bid, string $productId, string $locationId, int $delta, string $reason, ?string $refType, ?string $refId, ?string $note, ?string $actor, bool $allowNegative = false): int
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('applyDelta requires an open transaction.'); }
        if ($delta === 0) { throw new \InvalidArgumentException('Zero delta.'); }
        $p = $this->repo->lockProduct($bid, $productId) ?? throw ApiException::notFound();
        if ($p['item_type'] !== 'product') { throw ApiException::conflict('not_stocked', 'Services do not hold stock.'); }
        $current = $this->repo->lockLevel($bid, $productId, $locationId);
        $after = $current + $delta;
        if ($after < 0 && !$allowNegative) {
            throw new ApiException('insufficient_stock', "Not enough stock for \"{$p['name']}\". Only $current left.", 409);
        }
        $this->repo->setLevel($bid, $productId, $locationId, $after);
        $this->repo->addToTotal($bid, $productId, $delta);
        $this->repo->insertMovement($bid, $productId, $locationId, $delta, $after, $reason, $refType, $refId, $note, $actor);
        return $after;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function adjust(TenantContext $t, array $body, Request $req): array
    {
        $t->require('stock.adjust');
        $v = new Validator($body);
        $v->only(['product_id', 'location_id', 'delta', 'new_quantity', 'reason', 'note']);
        $productId = $v->uuid('product_id');
        $locationId = $v->uuid('location_id');
        $reason = $v->enum('reason', array_keys(self::ADJUST_REASONS));
        $note = $v->string('note', 1, 300, false);
        $hasDelta = $v->has('delta') && $body['delta'] !== null;
        $hasNew = $v->has('new_quantity') && $body['new_quantity'] !== null;
        if ($hasDelta === $hasNew) { $v->addError('delta', 'Provide exactly one of delta or new_quantity.'); }
        $delta = $hasDelta ? $v->int('delta', -self::MAX_QTY, self::MAX_QTY) : null;
        $newQty = $hasNew ? $v->int('new_quantity', 0, self::MAX_QTY) : null;
        $v->done();

        if (!$t->canAccessOutlet((string) $locationId) || !$this->repo->locationBelongs($t->businessId, (string) $locationId)) {
            throw ApiException::notFound('Outlet not found.');
        }
        $result = Db::transaction($this->pdo, function () use ($t, $productId, $locationId, $reason, $note, $delta, $newQty) {
            $p = $this->repo->lockProduct($t->businessId, (string) $productId) ?? throw ApiException::notFound('Product not found.');
            if ($p['archived_at'] !== null) { throw ApiException::notFound('Product not found.'); }
            $current = $this->repo->lockLevel($t->businessId, (string) $productId, (string) $locationId);
            $d = $delta ?? ((int) $newQty - $current);
            if ($d === 0) { throw ApiException::validation(['delta' => ['This would not change the stock.']]); }
            $sign = self::ADJUST_REASONS[(string) $reason];
            if (($sign === 1 && $d < 0) || ($sign === -1 && $d > 0)) {
                throw ApiException::validation(['reason' => [$sign === 1 ? 'This reason can only add stock.' : 'This reason can only remove stock.']]);
            }
            $after = $this->applyDelta($t->businessId, (string) $productId, (string) $locationId, $d, (string) $reason, 'adjustment', null, $note, $t->userId);
            return ['product_id' => $productId, 'location_id' => $locationId, 'delta' => $d, 'quantity_after' => $after, 'before' => $current];
        });
        $this->audit->log('stock.adjust', $req, $t->userId, $t->businessId, 'product', (string) $productId, ['quantity' => $result['before']], ['quantity' => $result['quantity_after'], 'reason' => $reason, 'location_id' => $locationId]);
        unset($result['before']);
        return $result;
    }

    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,int>} */
    public function levels(TenantContext $t, array $q): array
    {
        $t->require('products.read');
        $loc = $this->optUuid($q, 'location_id');
        $prod = $this->optUuid($q, 'product_id');
        $pg = Pager::fromQuery($q, 100);
        $r = $this->repo->levels($t->businessId, $loc, $prod, $t->outletIds, $pg->perPage, $pg->offset());
        return ['rows' => $r['rows'], 'meta' => $pg->meta($r['total'])];
    }

    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,int>} */
    public function movements(TenantContext $t, array $q): array
    {
        $t->require('products.write');     // movement history shows who/why: managers and owners only
        $f = [
            'product_id' => $this->optUuid($q, 'product_id'),
            'location_id' => $this->optUuid($q, 'location_id'),
            'reason' => isset($q['reason']) && is_string($q['reason']) && preg_match('/^[a-z_]{2,30}$/', $q['reason']) ? $q['reason'] : null,
            'from' => $this->optDate($q, 'from'),
            'to' => $this->optDate($q, 'to'),
        ];
        $pg = Pager::fromQuery($q, 50);
        $r = $this->repo->movements($t->businessId, $f, $t->outletIds, $pg->perPage, $pg->offset());
        return ['rows' => $r['rows'], 'meta' => $pg->meta($r['total'])];
    }

    /** @param array<string,mixed> $q @return array<string,mixed> */
    public function valuation(TenantContext $t, array $q): array
    {
        $t->require('reports.read');
        $loc = $this->optUuid($q, 'location_id');
        if ($loc !== null && !$t->canAccessOutlet($loc)) { throw ApiException::notFound('Outlet not found.'); }
        $v = $this->repo->valuation($t->businessId, $loc, $t->outletIds);
        return ['cost_value' => $v['cost'], 'retail_value' => $v['retail'], 'units' => $v['units'], 'products' => $v['products'], 'location_id' => $loc, 'as_of' => Clock::sql()];
    }

    /** @param array<string,mixed> $q */
    private function optUuid(array $q, string $k): ?string
    {
        if (!isset($q[$k])) { return null; }
        if (!is_string($q[$k]) || !\LeenTrack\Core\Uuid::isValid($q[$k])) { throw ApiException::validation([$k => ['Must be a valid id.']]); }
        return $q[$k];
    }

    /** @param array<string,mixed> $q */
    private function optDate(array $q, string $k): ?string
    {
        if (!isset($q[$k])) { return null; }
        $d = is_string($q[$k]) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $q[$k], new \DateTimeZone('UTC')) : false;
        if (!$d || $d->format('Y-m-d') !== $q[$k]) { throw ApiException::validation([$k => ['Must be a date as YYYY-MM-DD.']]); }
        return Clock::sql($d);
    }
}
