<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Csv;
use LeenTrack\Core\Db;
use LeenTrack\Core\Pager;
use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Core\Uuid;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\CatalogRepository;
use PDO;

final class ProductService
{
    private const MAX_QTY = 10_000_000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly CatalogRepository $repo,
        private readonly StockService $stock,
        private readonly AuditService $audit,
    ) {
    }

    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,int>} */
    public function list(TenantContext $t, array $q): array
    {
        $t->require('products.read');
        $allowed = ['search', 'category_id', 'type', 'active', 'barcode', 'stock_status', 'archived', 'sort', 'dir', 'page', 'per_page'];
        if ($extra = array_diff(array_keys($q), $allowed)) { throw ApiException::validation([(string) reset($extra) => ['Unknown filter.']]); }
        $f = [
            'search' => $this->str($q, 'search', 100),
            'category_id' => $this->uuid($q, 'category_id'),
            'type' => $this->oneOf($q, 'type', ['product', 'service']),
            'active' => isset($q['active']) ? $this->bool($q, 'active') : null,
            'barcode' => $this->str($q, 'barcode', 80),
            'stock_status' => $this->oneOf($q, 'stock_status', ['low', 'out', 'ok']),
            'archived' => isset($q['archived']) && $this->bool($q, 'archived'),
        ];
        if ($f['archived']) { $t->require('products.write'); }
        $sort = $this->oneOf($q, 'sort', ['name', 'created_at', 'selling_price', 'stock_quantity']) ?? 'name';
        $dir = $this->oneOf($q, 'dir', ['asc', 'desc']) ?? 'asc';
        $pg = Pager::fromQuery($q, 50);
        $r = $this->repo->products($t->businessId, $f, $t->outletIds, $sort, $dir, $pg->perPage, $pg->offset());
        return ['rows' => array_map(fn ($p) => $this->view($t, $p), $r['rows']), 'meta' => $pg->meta($r['total'])];
    }

    /** @return array<string,mixed> */
    public function get(TenantContext $t, string $id): array
    {
        $t->require('products.read');
        return $this->view($t, $this->find($t, $id));
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function create(TenantContext $t, array $body, Request $req): array
    {
        $t->require('products.write');
        $d = $this->parse($t, $body, true);
        $t->requireWithinLimit('products', $this->repo->countLiveProducts($t->businessId));
        $locationId = $this->openingLocation($t, $d['location_id']);
        $id = Uuid::v4();
        Db::transaction($this->pdo, function () use ($t, $d, $id, $locationId, $req) {
            $this->assertCategory($t, $d['category_id']);
            $this->assertBarcodeFree($t, $d['barcode'], null);
            try {
                $this->repo->insertProduct($d + ['id' => $id, 'business_id' => $t->businessId]);
            } catch (\PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) { throw ApiException::conflict('duplicate_barcode', 'Another item already uses this barcode.'); }
                throw $e;
            }
            if ($d['item_type'] === 'product' && $d['opening'] > 0) {
                $this->stock->applyDelta($t->businessId, $id, $locationId, $d['opening'], 'opening', 'product', $id, 'Opening stock', $t->userId);
            }
        });
        $this->audit->log('product.create', $req, $t->userId, $t->businessId, 'product', $id, null, ['name' => $d['name'], 'item_type' => $d['item_type'], 'opening' => $d['opening']]);
        return $this->view($t, $this->find($t, $id));
    }

    /**
     * Stock is NOT editable here (use POST /stock/adjustments, which records who/why). The source's edit form overwrote stock silently.
     * @param array<string,mixed> $body @return array<string,mixed>
     */
    public function update(TenantContext $t, string $id, array $body, Request $req): array
    {
        $t->require('products.write');
        $before = $this->find($t, $id);
        if (array_key_exists('stock_quantity', $body)) {
            throw ApiException::validation(['stock_quantity' => ['Use stock adjustments to change stock.']]);
        }
        $d = $this->parse($t, $body, false, (string) $before['item_type']);
        unset($d['opening'], $d['location_id']);
        $type = $d['item_type'] ?? $before['item_type'];
        if ($type !== $before['item_type']) {
            if ($type === 'service' && (int) $this->lockedStock($t, $id) !== 0) {
                throw ApiException::conflict('has_stock', 'Remove the remaining stock before converting this item to a service.');
            }
        }
        if ($type === 'service') { $d['barcode'] = null; $d['min_stock_level'] = 0; $d['reorder_level'] = 0; }
        if ($type === 'product' && $before['item_type'] === 'service' && !isset($d['reorder_level'])) { $d['reorder_level'] = ($d['min_stock_level'] ?? 0) ?: 5; }
        Db::transaction($this->pdo, function () use ($t, $id, $d) {
            if (array_key_exists('category_id', $d)) { $this->assertCategory($t, $d['category_id']); }
            if (array_key_exists('barcode', $d)) { $this->assertBarcodeFree($t, $d['barcode'], $id); }
            try {
                $this->repo->updateProduct($t->businessId, $id, $d);
            } catch (\PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) { throw ApiException::conflict('duplicate_barcode', 'Another item already uses this barcode.'); }
                throw $e;
            }
        });
        $changed = array_intersect_key($before, $d);
        $this->audit->log('product.update', $req, $t->userId, $t->businessId, 'product', $id, $changed, array_map(fn ($v) => $v instanceof \LeenTrack\Core\Money ? (string) $v : $v, $d));
        return $this->view($t, $this->find($t, $id));
    }

    /**
     * Products are archived, never destroyed: stock history and (later) sales reference them. Archiving frees the barcode.
     */
    public function archive(TenantContext $t, string $id, Request $req): void
    {
        $t->require('products.write');
        $this->find($t, $id);
        $this->repo->setArchived($t->businessId, $id, true);
        $this->audit->log('product.archive', $req, $t->userId, $t->businessId, 'product', $id);
    }

    /** @return array<string,mixed> */
    public function restore(TenantContext $t, string $id, Request $req): array
    {
        $t->require('products.write');
        $p = $this->repo->product($t->businessId, $id, null, true) ?? throw ApiException::notFound();
        if ($p['archived_at'] === null) { throw ApiException::conflict('not_archived', 'This item is not archived.'); }
        $t->requireWithinLimit('products', $this->repo->countLiveProducts($t->businessId));
        if ($p['barcode'] !== null && $this->repo->productByBarcode($t->businessId, (string) $p['barcode'], $id)) {
            throw ApiException::conflict('duplicate_barcode', 'Another item now uses this barcode. Change it first.');
        }
        $this->repo->setArchived($t->businessId, $id, false);
        $this->audit->log('product.restore', $req, $t->userId, $t->businessId, 'product', $id);
        return $this->view($t, $this->find($t, $id));
    }

    /** CSV of live products (plan-gated like the source's reports export). Text cells are formula-injection-safe. */
    public function export(TenantContext $t): Response
    {
        $t->require('reports.read');
        $t->requireFeature('exportReports');
        $out = Csv::line(['name', 'sku', 'barcode', 'category', 'type', 'selling_price', 'cost_price', 'stock_quantity', 'min_stock_level', 'reorder_level', 'description', 'is_active']);
        foreach ($this->repo->exportRows($t->businessId, $t->outletIds) as $r) {
            $out .= Csv::line([
                Csv::safeText((string) $r['name']), Csv::safeText((string) ($r['sku'] ?? '')), (string) ($r['barcode'] ?? ''), Csv::safeText((string) ($r['category'] ?? '')),
                $r['item_type'], $r['selling_price'], $r['cost_price'], $r['item_type'] === 'service' ? 0 : (int) $r['stock'], (int) $r['min_stock_level'], (int) $r['reorder_level'],
                Csv::safeText((string) ($r['description'] ?? '')), $r['is_active'] ? 'true' : 'false',
            ]);
        }
        return Response::csv("\xEF\xBB\xBF" . $out, 'products-' . gmdate('Ymd') . '.csv');
    }

    // ------------------------------------------------------------------------------------------------------------
    /** @return array<string,mixed> */
    private function find(TenantContext $t, string $id): array
    {
        if (!Uuid::isValid($id)) { throw ApiException::notFound(); }
        return $this->repo->product($t->businessId, $id, $t->outletIds) ?? throw ApiException::notFound();
    }

    private function lockedStock(TenantContext $t, string $id): int
    {
        $st = $this->pdo->prepare('SELECT stock_quantity FROM products WHERE business_id = ? AND id = ?');
        $st->execute([$t->businessId, $id]);
        return (int) $st->fetchColumn();
    }

    private function assertCategory(TenantContext $t, ?string $categoryId): void
    {
        if ($categoryId !== null && $this->repo->category($t->businessId, $categoryId) === null) {
            throw ApiException::validation(['category_id' => ['Category not found.']]);
        }
    }

    private function assertBarcodeFree(TenantContext $t, ?string $barcode, ?string $exceptId): void
    {
        if ($barcode !== null && $this->repo->productByBarcode($t->businessId, $barcode, $exceptId) !== null) {
            throw ApiException::conflict('duplicate_barcode', 'Another item already uses this barcode.');
        }
    }

    /** Opening stock goes to the requested outlet, or the default outlet; a restricted member may only use their own outlets. */
    private function openingLocation(TenantContext $t, ?string $requested): string
    {
        $loc = $requested ?? $this->repo->defaultLocationId($t->businessId);
        if ($loc === null || !$this->repo->locationBelongs($t->businessId, $loc) || !$t->canAccessOutlet($loc)) {
            throw ApiException::validation(['location_id' => ['Choose an outlet you can use.']]);
        }
        return $loc;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed> for create: all fields; for update: only the fields present
     */
    private function parse(TenantContext $t, array $body, bool $create, string $currentType = 'product'): array
    {
        $v = new Validator($body);
        $v->only(['name', 'description', 'sku', 'barcode', 'category_id', 'item_type', 'selling_price', 'cost_price', 'stock_quantity', 'location_id', 'min_stock_level', 'reorder_level', 'image_url', 'is_active']);
        $d = [];
        $set = fn (string $k) => $create || $v->has($k);
        if ($set('name')) { $d['name'] = $v->string('name', 1, 200); }
        if ($set('description')) { $d['description'] = $v->string('description', 1, 1000, false); }
        if ($set('sku')) { $d['sku'] = $v->string('sku', 1, 80, false); }
        if ($set('barcode')) {
            $b = $v->string('barcode', 1, 80, false);
            if ($b !== null && !preg_match('/^[\x21-\x7E]{1,80}$/', $b)) { $v->addError('barcode', 'Use letters, digits and symbols without spaces.'); }
            $d['barcode'] = $b;
        }
        if ($set('category_id')) { $d['category_id'] = $v->uuid('category_id', false); }
        if ($set('item_type')) { $d['item_type'] = $v->enum('item_type', ['product', 'service'], false, 'product'); }
        if ($set('selling_price')) { $d['selling_price'] = $v->money('selling_price', true)?->toString(); }
        if ($set('cost_price')) { $d['cost_price'] = $v->money('cost_price', false, \LeenTrack\Core\Money::zero())?->toString(); }
        if ($set('min_stock_level')) { $d['min_stock_level'] = $v->int('min_stock_level', 0, self::MAX_QTY, false, 0); }
        if ($set('reorder_level')) { $d['reorder_level'] = $v->int('reorder_level', 0, self::MAX_QTY, false); }
        if ($set('image_url')) { $d['image_url'] = $v->url('image_url'); }
        if ($set('is_active')) { $d['is_active'] = $v->bool('is_active') ?? true; }
        $opening = 0;
        $locationId = null;
        if ($create) {
            $opening = $v->int('stock_quantity', 0, self::MAX_QTY, false, 0) ?? 0;
            $locationId = $v->uuid('location_id', false);
        } elseif ($v->has('location_id')) {
            $v->addError('location_id', 'Not applicable when editing.');
        }
        $v->done();

        $type = $d['item_type'] ?? ($create ? 'product' : $currentType);
        if ($create) {
            // same defaulting as the source form/CSV: reorder = given || min || 5; services carry no stock settings
            $d['reorder_level'] ??= null;
            if ($d['reorder_level'] === null || $d['reorder_level'] === 0) { $d['reorder_level'] = ($d['min_stock_level'] ?? 0) ?: 5; }
            $d['is_active'] ??= true;
        }
        if ($type === 'service') {
            if (array_key_exists('barcode', $d)) { $d['barcode'] = null; }
            if ($create) { $d['barcode'] = null; $d['min_stock_level'] = 0; $d['reorder_level'] = 0; $opening = 0; }
        }
        if ($create) {
            $d['opening'] = $opening;
            $d['location_id'] = $locationId;
            $d += ['description' => null, 'sku' => null, 'barcode' => null, 'category_id' => null, 'image_url' => null];
        }
        return $d;
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private function view(TenantContext $t, array $p): array
    {
        $service = $p['item_type'] === 'service';
        $stock = (int) $p['visible_stock'];
        $status = null;
        if (!$service) { $status = $stock <= 0 ? 'out' : ($stock <= (int) $p['reorder_level'] ? 'low' : 'ok'); }
        $out = [
            'id' => $p['id'], 'name' => $p['name'], 'description' => $p['description'], 'sku' => $p['sku'], 'barcode' => $p['barcode'],
            'category_id' => $p['category_id'], 'item_type' => $p['item_type'], 'selling_price' => $p['selling_price'],
            'stock_quantity' => $service ? 0 : $stock, 'stock_status' => $status, 'min_stock_level' => (int) $p['min_stock_level'], 'reorder_level' => (int) $p['reorder_level'],
            'image_url' => $p['image_url'], 'is_active' => (bool) $p['is_active'], 'archived' => $p['archived_at'] !== null,
            'created_at' => $p['created_at'], 'updated_at' => $p['updated_at'],
        ];
        if ($t->can('products.cost.read')) { $out['cost_price'] = $p['cost_price']; }     // cashiers never see what goods cost
        return $out;
    }

    /** @param array<string,mixed> $q */
    private function str(array $q, string $k, int $max): ?string
    {
        if (!isset($q[$k])) { return null; }
        if (!is_string($q[$k]) || mb_strlen($q[$k]) > $max || $q[$k] === '') { throw ApiException::validation([$k => ["Must be text up to $max characters."]]); }
        return $q[$k];
    }

    /** @param array<string,mixed> $q */
    private function uuid(array $q, string $k): ?string
    {
        if (!isset($q[$k])) { return null; }
        if (!is_string($q[$k]) || !Uuid::isValid($q[$k])) { throw ApiException::validation([$k => ['Must be a valid id.']]); }
        return $q[$k];
    }

    /** @param array<string,mixed> $q @param list<string> $allowed */
    private function oneOf(array $q, string $k, array $allowed): ?string
    {
        if (!isset($q[$k])) { return null; }
        if (!is_string($q[$k]) || !in_array($q[$k], $allowed, true)) { throw ApiException::validation([$k => ['Must be one of: ' . implode(', ', $allowed) . '.']]); }
        return $q[$k];
    }

    /** @param array<string,mixed> $q */
    private function bool(array $q, string $k): bool
    {
        if (!in_array($q[$k] ?? null, ['0', '1', 'true', 'false'], true)) { throw ApiException::validation([$k => ['Must be true or false.']]); }
        return in_array($q[$k], ['1', 'true'], true);
    }
}
