<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Csv;
use LeenTrack\Core\Db;
use LeenTrack\Core\Money;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\CatalogRepository;
use PDO;

/**
 * Bulk CSV import with the SAME column aliases and row rules as the web app's dialog (bulk-csv-import-dialog.tsx), plus the
 * things the source skipped: plan limits, duplicate-barcode checks, size caps, and one all-or-nothing transaction.
 * Valid rows are imported; invalid rows are reported with row numbers (as in the source's preview).
 */
final class ProductImportService
{
    public const MAX_ROWS = 2000;
    private const MAX_QTY = 10_000_000;
    private const ALIASES = [
        'name' => ['name', 'product_name', 'product', 'title', 'item'],
        'sku' => ['sku', 'product_code', 'code', 'item_code'],
        'barcode' => ['barcode', 'upc', 'ean', 'bar_code'],
        'selling_price' => ['selling_price', 'price', 'retail_price', 'unit_price', 'sale_price', 'fee'],
        'cost_price' => ['cost_price', 'cost', 'buy_price', 'purchase_price', 'buying_price'],
        'stock_quantity' => ['stock_quantity', 'stock', 'quantity', 'qty', 'initial_stock', 'count'],
        'min_stock_level' => ['min_stock_level', 'min_stock', 'min_level', 'minimum_stock', 'min'],
        'reorder_level' => ['reorder_level', 'reorder_point', 'reorder', 'reorder_at'],
        'category' => ['category', 'category_name', 'category_id', 'department'],
        'description' => ['description', 'desc', 'details', 'notes', 'summary'],
        'item_type' => ['type', 'item_type', 'classification', 'kind'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly CatalogRepository $repo,
        private readonly StockService $stock,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param 'validate'|'commit' $mode
     * @return array<string,mixed>
     */
    public function run(TenantContext $t, string $csv, string $mode, ?string $locationId, Request $req): array
    {
        $t->require('products.write');
        if (trim($csv) === '') { throw ApiException::validation(['csv' => ['The file is empty.']]); }
        $rows = Csv::parse($csv);
        if (count($rows) < 2) { throw ApiException::validation(['csv' => ['The CSV file must have a header row and at least one product row.']]); }
        if (count($rows) - 1 > self::MAX_ROWS) { throw new ApiException('too_many_rows', 'At most ' . self::MAX_ROWS . ' rows can be imported at once.', 413); }

        $map = $this->columnMap($rows[0]);
        if ($map['name'] === -1) { throw ApiException::validation(['csv' => ["Could not find a 'name' or 'product_name' column header in your CSV."]]); }

        $parsed = [];
        $seenBarcodes = [];
        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            $parsed[] = $this->parseRow($t, $rows[$i], $map, $i + 1, $seenBarcodes);
        }
        $valid = array_values(array_filter($parsed, fn ($r) => $r['valid']));

        // categories this import would create (case-insensitive match against existing, like the source)
        $newCats = [];
        foreach ($valid as $r) {
            $c = $r['data']['category'];
            if ($c !== '' && !isset($newCats[mb_strtolower($c)]) && $this->repo->categoryByName($t->businessId, $c) === null) { $newCats[mb_strtolower($c)] = $c; }
        }
        $summary = [
            'total_rows' => count($parsed), 'valid_count' => count($valid), 'invalid_count' => count($parsed) - count($valid),
            'new_categories' => array_values($newCats),
            'rows' => array_map(fn ($r) => ['row' => $r['row'], 'valid' => $r['valid'], 'errors' => $r['errors'], 'name' => $r['data']['name'] ?? ''], $parsed),
        ];
        if ($mode === 'validate') { return $summary + ['committed' => false]; }

        // ---- commit ----
        if (!$valid) { throw ApiException::validation(['csv' => ['There are no valid rows to import.']]); }
        $locId = $locationId ?? $this->repo->defaultLocationId($t->businessId);
        if ($locId === null || !$this->repo->locationBelongs($t->businessId, $locId) || !$t->canAccessOutlet($locId)) {
            throw ApiException::validation(['location_id' => ['Choose an outlet you can use.']]);
        }
        $room = \LeenTrack\Services\PlanCatalog::limit($t->plan, 'products') - $this->repo->countLiveProducts($t->businessId);
        if (count($valid) > max(0, $room)) {
            throw new ApiException('plan_limit_reached', 'Your plan allows ' . max(0, $room) . ' more item(s) but this file has ' . count($valid) . ' valid rows.', 402);
        }
        $catRoom = \LeenTrack\Services\PlanCatalog::limit($t->plan, 'categories') - $this->repo->countCategories($t->businessId);
        if (count($newCats) > max(0, $catRoom)) {
            throw new ApiException('plan_limit_reached', 'This file would create ' . count($newCats) . ' new categories but your plan allows ' . max(0, $catRoom) . ' more.', 402);
        }

        $batch = Uuid::v4();
        $created = Db::transaction($this->pdo, function () use ($t, $valid, $locId, $batch) {
            $catIds = [];
            $n = 0;
            foreach ($valid as $r) {
                $d = $r['data'];
                $catId = null;
                if ($d['category'] !== '') {
                    $key = mb_strtolower($d['category']);
                    if (!isset($catIds[$key])) {
                        $existing = $this->repo->categoryByName($t->businessId, $d['category']);
                        if ($existing === null) { $cid = Uuid::v4(); $this->repo->insertCategory($cid, $t->businessId, $d['category'], null); $catIds[$key] = $cid; }
                        else { $catIds[$key] = (string) $existing['id']; }
                    }
                    $catId = $catIds[$key];
                }
                $id = Uuid::v4();
                $this->repo->insertProduct([
                    'id' => $id, 'business_id' => $t->businessId, 'category_id' => $catId, 'name' => $d['name'], 'description' => $d['description'] ?: null,
                    'sku' => $d['sku'] ?: null, 'barcode' => $d['barcode'] ?: null, 'item_type' => $d['item_type'], 'selling_price' => $d['selling_price'],
                    'cost_price' => $d['cost_price'], 'min_stock_level' => $d['min_stock_level'], 'reorder_level' => $d['reorder_level'], 'image_url' => null, 'is_active' => true,
                ]);
                if ($d['item_type'] === 'product' && $d['stock_quantity'] > 0) {
                    $this->stock->applyDelta($t->businessId, $id, $locId, $d['stock_quantity'], 'import', 'import', $batch, 'CSV import', $t->userId);
                }
                $n++;
            }
            return $n;
        });
        $this->audit->log('product.import', $req, $t->userId, $t->businessId, 'import', $batch, null, ['created' => $created, 'skipped' => $summary['invalid_count'], 'new_categories' => count($newCats)]);
        return $summary + ['committed' => true, 'created' => $created, 'batch_id' => $batch];
    }

    /** @param list<string> $header @return array<string,int> */
    private function columnMap(array $header): array
    {
        $norm = array_map(fn ($h) => preg_replace('/[^a-z0-9_]/', '_', strtolower($h)), $header);
        $map = [];
        foreach (self::ALIASES as $field => $aliases) {
            $map[$field] = -1;
            foreach ($norm as $idx => $h) { if (in_array($h, $aliases, true)) { $map[$field] = $idx; break; } }
        }
        return $map;
    }

    /** Port of cleanNumber(): strip everything except digits . - then parse; default when empty/unparseable. */
    private function cleanNumber(?string $raw, string $default): string
    {
        if ($raw === null || $raw === '') { return $default; }
        $s = preg_replace('/[^0-9.\-]+/', '', $raw) ?? '';
        if (!preg_match('/^-?\d*\.?\d+$|^-?\d+\.?$/', $s)) { return $default; }
        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '-');
        if (str_starts_with($s, '.')) { $s = '0' . $s; }
        return ($neg ? '-' : '') . rtrim($s, '.');
    }

    /**
     * @param list<string> $row @param array<string,int> $map @param array<string,int> $seenBarcodes
     * @return array{row:int,valid:bool,errors:list<string>,data:array<string,mixed>}
     */
    private function parseRow(TenantContext $t, array $row, array $map, int $line, array &$seenBarcodes): array
    {
        $cell = fn (string $f) => $map[$f] !== -1 ? trim($row[$map[$f]] ?? '') : '';
        $errors = [];
        $name = $cell('name');
        $type = in_array(strtolower($cell('item_type')), ['service', 'labor', 'service/labor'], true) ? 'service' : 'product';
        $isService = $type === 'service';

        $sell = $this->cleanNumber($map['selling_price'] !== -1 ? ($row[$map['selling_price']] ?? '') : '', '-1');
        $cost = $this->cleanNumber($map['cost_price'] !== -1 ? ($row[$map['cost_price']] ?? '') : '', '0');
        $stockRaw = $this->cleanNumber($map['stock_quantity'] !== -1 ? ($row[$map['stock_quantity']] ?? '') : '', '-1');
        $minRaw = $this->cleanNumber($map['min_stock_level'] !== -1 ? ($row[$map['min_stock_level']] ?? '') : '', '0');

        $money = function (string $s, string $label) use (&$errors): ?string {
            try { $m = Money::parse($s, true); } catch (\InvalidArgumentException | \OverflowException) { $errors[] = "$label is not a valid amount"; return null; }
            return $m->isNegative() ? '0.00' : $m->toString();
        };

        if ($name === '') { $errors[] = 'Item name is required'; }
        if (mb_strlen($name) > 200) { $errors[] = 'Item name is too long (200 characters maximum)'; }
        if ((float) $sell < 0) { $errors[] = 'Valid selling price is required'; $sellP = '0.00'; } else { $sellP = $money($sell, 'Selling price') ?? '0.00'; }
        $costP = (float) $cost < 0 ? '0.00' : ($money($cost, 'Cost price') ?? '0.00');

        $stock = $isService ? 0 : (int) floor((float) $stockRaw);
        if (!$isService && (float) $stockRaw < 0) { $errors[] = 'Valid stock quantity (>= 0) is required for physical products'; $stock = 0; }
        $min = $isService ? 0 : max(0, (int) floor((float) $minRaw));
        $reorderRaw = $map['reorder_level'] !== -1 ? ($row[$map['reorder_level']] ?? '') : '';
        $reorderParsed = max(0, (int) floor((float) $this->cleanNumber($reorderRaw, (string) ($min ?: 5))));
        $reorder = $isService ? 0 : ($reorderParsed ?: $min ?: 5);   // source: reorder || min || 5
        if ($stock > self::MAX_QTY || $min > self::MAX_QTY || $reorder > self::MAX_QTY) { $errors[] = 'Quantity is too large'; }

        $barcode = $isService ? '' : $cell('barcode');
        if ($barcode !== '') {
            if (!preg_match('/^[\x21-\x7E]{1,80}$/', $barcode)) { $errors[] = 'Barcode may only contain letters, digits and symbols (no spaces)'; }
            elseif (isset($seenBarcodes[$barcode])) { $errors[] = "Barcode is repeated (first used on row {$seenBarcodes[$barcode]})"; }
            elseif ($this->repo->productByBarcode($t->businessId, $barcode) !== null) { $errors[] = 'Barcode already belongs to another item'; }
            else { $seenBarcodes[$barcode] = $line; }
        }
        $sku = $cell('sku');
        if (mb_strlen($sku) > 80) { $errors[] = 'SKU is too long (80 characters maximum)'; }
        $category = $cell('category');
        if (mb_strlen($category) > 100) { $errors[] = 'Category name is too long (100 characters maximum)'; }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $category)) { $category = ''; $errors[] = 'Category must be a name, not an id'; }
        $desc = $cell('description');
        if (mb_strlen($desc) > 1000) { $errors[] = 'Description is too long (1000 characters maximum)'; }

        return ['row' => $line, 'valid' => $errors === [], 'errors' => $errors, 'data' => [
            'name' => $name, 'sku' => $sku, 'barcode' => $barcode, 'selling_price' => $sellP, 'cost_price' => $costP, 'stock_quantity' => $stock,
            'min_stock_level' => $min, 'reorder_level' => $reorder, 'category' => $category, 'description' => $desc, 'item_type' => $type,
        ]];
    }
}
