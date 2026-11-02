<?php
declare(strict_types=1);

namespace LeenTrack\Repositories;

use PDO;

/** Categories, products and stock. EVERY method takes a business id and scopes by it — there is no unscoped lookup. */
final class CatalogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    // ------------------------------------------------------------------ categories
    /** @return list<array<string,mixed>> */
    public function categories(string $bid): array
    {
        $st = $this->pdo->prepare('SELECT c.id,c.name,c.description,c.created_at,
                (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.business_id = c.business_id AND p.archived_at IS NULL) AS product_count
            FROM categories c WHERE c.business_id = ? ORDER BY c.name');
        $st->execute([$bid]);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function category(string $bid, string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id,name,description,created_at FROM categories WHERE business_id = ? AND id = ?');
        $st->execute([$bid, $id]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null case/accent-insensitive by column collation */
    public function categoryByName(string $bid, string $name): ?array
    {
        $st = $this->pdo->prepare('SELECT id,name FROM categories WHERE business_id = ? AND name = ?');
        $st->execute([$bid, $name]);
        return $st->fetch() ?: null;
    }

    public function countCategories(string $bid): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM categories WHERE business_id = ?');
        $st->execute([$bid]);
        return (int) $st->fetchColumn();
    }

    public function insertCategory(string $id, string $bid, string $name, ?string $desc): void
    {
        $this->pdo->prepare('INSERT INTO categories (id,business_id,name,description) VALUES (?,?,?,?)')->execute([$id, $bid, $name, $desc]);
    }

    public function updateCategory(string $bid, string $id, string $name, ?string $desc): void
    {
        $this->pdo->prepare('UPDATE categories SET name = ?, description = ? WHERE business_id = ? AND id = ?')->execute([$name, $desc, $bid, $id]);
    }

    public function deleteCategory(string $bid, string $id): void
    {
        $this->pdo->prepare('DELETE FROM categories WHERE business_id = ? AND id = ?')->execute([$bid, $id]);
    }

    // ------------------------------------------------------------------ products
    private const COLS = 'p.id,p.category_id,p.name,p.description,p.sku,p.barcode,p.item_type,p.selling_price,p.cost_price,p.min_stock_level,p.reorder_level,p.image_url,p.is_active,p.archived_at,p.created_at,p.updated_at';

    /**
     * Stock figure the caller is allowed to see: the business total, or — for outlet-restricted members — only the sum over
     * their own outlets. Placeholders are bound by the caller in the order returned.
     * @param list<string>|null $outletIds
     * @return array{0:string,1:list<string>} SQL expression + params
     */
    private function visibleStock(?array $outletIds): array
    {
        if ($outletIds === null) { return ['p.stock_quantity', []]; }
        if (!$outletIds) { return ['0', []]; }
        return ['COALESCE((SELECT SUM(l.quantity) FROM stock_levels l WHERE l.product_id = p.id AND l.location_id IN (' . implode(',', array_fill(0, count($outletIds), '?')) . ')),0)', $outletIds];
    }

    /**
     * @param array{search?:?string,category_id?:?string,type?:?string,active?:?bool,archived?:bool,stock_status?:?string,barcode?:?string} $f
     * @param list<string>|null $outletIds
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function products(string $bid, array $f, ?array $outletIds, string $sort, string $dir, int $limit, int $offset): array
    {
        [$stockExpr, $stockParams] = $this->visibleStock($outletIds);
        $where = ['p.business_id = ?'];
        $args = [$bid];
        $where[] = ($f['archived'] ?? false) ? 'p.archived_at IS NOT NULL' : 'p.archived_at IS NULL';
        if (($f['search'] ?? null) !== null) {
            $like = '%' . addcslashes($f['search'], '\\%_') . '%';
            $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        if (($f['category_id'] ?? null) !== null) { $where[] = 'p.category_id = ?'; $args[] = $f['category_id']; }
        if (($f['type'] ?? null) !== null) { $where[] = 'p.item_type = ?'; $args[] = $f['type']; }
        if (($f['active'] ?? null) !== null) { $where[] = 'p.is_active = ?'; $args[] = (int) $f['active']; }
        if (($f['barcode'] ?? null) !== null) { $where[] = 'p.barcode = ?'; $args[] = $f['barcode']; }
        $having = '';
        $havingArgs = [];
        if (($f['stock_status'] ?? null) !== null) {
            $where[] = "p.item_type = 'product'";
            $having = match ($f['stock_status']) {
                'out' => ' HAVING visible_stock <= 0',
                'low' => ' HAVING visible_stock > 0 AND visible_stock <= reorder_level',
                'ok' => ' HAVING visible_stock > reorder_level',
            };
        }
        $sortCol = ['name' => 'p.name', 'created_at' => 'p.created_at', 'selling_price' => 'p.selling_price', 'stock_quantity' => 'visible_stock'][$sort];
        $dirSql = $dir === 'desc' ? 'DESC' : 'ASC';
        $base = "SELECT " . self::COLS . ", $stockExpr AS visible_stock FROM products p WHERE " . implode(' AND ', $where) . $having;
        $params = array_merge($stockParams, $args, $havingArgs);

        $cnt = $this->pdo->prepare("SELECT COUNT(*) FROM ($base) t");
        $cnt->execute($params);
        $total = (int) $cnt->fetchColumn();

        $st = $this->pdo->prepare("$base ORDER BY $sortCol $dirSql, p.id LIMIT $limit OFFSET $offset");   // $sortCol/$dirSql from allow-lists; limit/offset are ints
        $st->execute($params);
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** @param list<string>|null $outletIds @return array<string,mixed>|null */
    public function product(string $bid, string $id, ?array $outletIds = null, bool $withArchived = false): ?array
    {
        [$stockExpr, $stockParams] = $this->visibleStock($outletIds);
        $st = $this->pdo->prepare("SELECT " . self::COLS . ", $stockExpr AS visible_stock FROM products p WHERE p.business_id = ? AND p.id = ?" . ($withArchived ? '' : ' AND p.archived_at IS NULL'));
        $st->execute(array_merge($stockParams, [$bid, $id]));
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null row-locked for the rest of the transaction */
    public function lockProduct(string $bid, string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id,item_type,stock_quantity,archived_at,is_active,name FROM products WHERE business_id = ? AND id = ? FOR UPDATE');
        $st->execute([$bid, $id]);
        return $st->fetch() ?: null;
    }

    public function countLiveProducts(string $bid): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE business_id = ? AND archived_at IS NULL');
        $st->execute([$bid]);
        return (int) $st->fetchColumn();
    }

    /** @return array<string,mixed>|null a LIVE product holding this barcode */
    public function productByBarcode(string $bid, string $barcode, ?string $exceptId = null): ?array
    {
        $st = $this->pdo->prepare('SELECT id FROM products WHERE business_id = ? AND barcode_live = ? AND (? IS NULL OR id <> ?)');
        $st->execute([$bid, $barcode, $exceptId, $exceptId]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,mixed> $p */
    public function insertProduct(array $p): void
    {
        $this->pdo->prepare('INSERT INTO products (id,business_id,category_id,name,description,sku,barcode,item_type,selling_price,cost_price,stock_quantity,min_stock_level,reorder_level,image_url,is_active)
            VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?,?,?)')
            ->execute([$p['id'], $p['business_id'], $p['category_id'], $p['name'], $p['description'], $p['sku'], $p['barcode'], $p['item_type'], $p['selling_price'], $p['cost_price'], $p['min_stock_level'], $p['reorder_level'], $p['image_url'], (int) $p['is_active']]);
    }

    /** @param array<string,mixed> $fields */
    public function updateProduct(string $bid, string $id, array $fields): void
    {
        $allowed = ['category_id', 'name', 'description', 'sku', 'barcode', 'item_type', 'selling_price', 'cost_price', 'min_stock_level', 'reorder_level', 'image_url', 'is_active'];
        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) { throw new \LogicException("Column $k is not updatable here."); }
            $sets[] = "`$k` = ?";
            $vals[] = $v;
        }
        if (!$sets) { return; }
        array_push($vals, $bid, $id);
        $this->pdo->prepare('UPDATE products SET ' . implode(',', $sets) . ' WHERE business_id = ? AND id = ?')->execute($vals);
    }

    public function setArchived(string $bid, string $id, bool $archived): void
    {
        $this->pdo->prepare('UPDATE products SET archived_at = ' . ($archived ? 'CURRENT_TIMESTAMP(3), is_active = 0' : 'NULL') . ' WHERE business_id = ? AND id = ?')->execute([$bid, $id]);
    }

    public function locationBelongs(string $bid, string $locationId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM locations WHERE business_id = ? AND id = ? AND is_active = 1');
        $st->execute([$bid, $locationId]);
        return (bool) $st->fetchColumn();
    }

    public function defaultLocationId(string $bid): ?string
    {
        $st = $this->pdo->prepare('SELECT id FROM locations WHERE business_id = ? AND is_default = 1 AND is_active = 1');
        $st->execute([$bid]);
        return $st->fetchColumn() ?: null;
    }

    // ------------------------------------------------------------------ stock
    /** @return int|null current level, locked FOR UPDATE (row created at 0 if missing) */
    public function lockLevel(string $bid, string $productId, string $locationId): int
    {
        $this->pdo->prepare('INSERT IGNORE INTO stock_levels (product_id,location_id,business_id,quantity) VALUES (?,?,?,0)')->execute([$productId, $locationId, $bid]);
        $st = $this->pdo->prepare('SELECT quantity FROM stock_levels WHERE business_id = ? AND product_id = ? AND location_id = ? FOR UPDATE');
        $st->execute([$bid, $productId, $locationId]);
        return (int) $st->fetchColumn();
    }

    public function setLevel(string $bid, string $productId, string $locationId, int $qty): void
    {
        $this->pdo->prepare('UPDATE stock_levels SET quantity = ? WHERE business_id = ? AND product_id = ? AND location_id = ?')->execute([$qty, $bid, $productId, $locationId]);
    }

    public function addToTotal(string $bid, string $productId, int $delta): void
    {
        $this->pdo->prepare('UPDATE products SET stock_quantity = stock_quantity + ? WHERE business_id = ? AND id = ?')->execute([$delta, $bid, $productId]);
    }

    public function insertMovement(string $bid, string $productId, string $locationId, int $delta, int $after, string $reason, ?string $refType, ?string $refId, ?string $note, ?string $actor): void
    {
        $this->pdo->prepare('INSERT INTO stock_movements (business_id,product_id,location_id,delta,quantity_after,reason,ref_type,ref_id,note,actor_user_id) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$bid, $productId, $locationId, $delta, $after, $reason, $refType, $refId, $note, $actor]);
    }

    /**
     * @param list<string>|null $outletIds
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function levels(string $bid, ?string $locationId, ?string $productId, ?array $outletIds, int $limit, int $offset): array
    {
        $where = ["l.business_id = ?", "p.archived_at IS NULL"];
        $args = [$bid];
        if ($locationId !== null) { $where[] = 'l.location_id = ?'; $args[] = $locationId; }
        if ($productId !== null) { $where[] = 'l.product_id = ?'; $args[] = $productId; }
        if ($outletIds !== null) {
            if (!$outletIds) { return ['rows' => [], 'total' => 0]; }
            $where[] = 'l.location_id IN (' . implode(',', array_fill(0, count($outletIds), '?')) . ')';
            array_push($args, ...$outletIds);
        }
        $from = 'FROM stock_levels l JOIN products p ON p.id = l.product_id AND p.business_id = l.business_id JOIN locations loc ON loc.id = l.location_id WHERE ' . implode(' AND ', $where);
        $c = $this->pdo->prepare("SELECT COUNT(*) $from");
        $c->execute($args);
        $st = $this->pdo->prepare("SELECT l.product_id, p.name AS product_name, p.sku, l.location_id, loc.name AS location_name, l.quantity, p.reorder_level $from ORDER BY p.name, loc.name LIMIT $limit OFFSET $offset");
        $st->execute($args);
        return ['rows' => $st->fetchAll(), 'total' => (int) $c->fetchColumn()];
    }

    /**
     * @param array{product_id?:?string,location_id?:?string,reason?:?string,from?:?string,to?:?string} $f
     * @param list<string>|null $outletIds
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function movements(string $bid, array $f, ?array $outletIds, int $limit, int $offset): array
    {
        $where = ['m.business_id = ?'];
        $args = [$bid];
        foreach (['product_id' => 'm.product_id', 'location_id' => 'm.location_id', 'reason' => 'm.reason'] as $k => $col) {
            if (($f[$k] ?? null) !== null) { $where[] = "$col = ?"; $args[] = $f[$k]; }
        }
        if (($f['from'] ?? null) !== null) { $where[] = 'm.created_at >= ?'; $args[] = $f['from']; }
        if (($f['to'] ?? null) !== null) { $where[] = 'm.created_at < ?'; $args[] = $f['to']; }
        if ($outletIds !== null) {
            if (!$outletIds) { return ['rows' => [], 'total' => 0]; }
            $where[] = 'm.location_id IN (' . implode(',', array_fill(0, count($outletIds), '?')) . ')';
            array_push($args, ...$outletIds);
        }
        $from = 'FROM stock_movements m JOIN products p ON p.id = m.product_id WHERE ' . implode(' AND ', $where);
        $c = $this->pdo->prepare("SELECT COUNT(*) $from");
        $c->execute($args);
        $st = $this->pdo->prepare("SELECT m.id,m.product_id,p.name AS product_name,m.location_id,m.delta,m.quantity_after,m.reason,m.ref_type,m.ref_id,m.note,m.actor_user_id,m.created_at $from ORDER BY m.id DESC LIMIT $limit OFFSET $offset");
        $st->execute($args);
        return ['rows' => $st->fetchAll(), 'total' => (int) $c->fetchColumn()];
    }

    /**
     * @param list<string>|null $outletIds
     * @return array{cost:string,retail:string,units:int,products:int}
     */
    public function valuation(string $bid, ?string $locationId, ?array $outletIds): array
    {
        $where = ["l.business_id = ?", "p.archived_at IS NULL", "p.is_active = 1", "p.item_type = 'product'", "l.quantity > 0"];
        $args = [$bid];
        if ($locationId !== null) { $where[] = 'l.location_id = ?'; $args[] = $locationId; }
        if ($outletIds !== null) {
            if (!$outletIds) { return ['cost' => '0.00', 'retail' => '0.00', 'units' => 0, 'products' => 0]; }
            $where[] = 'l.location_id IN (' . implode(',', array_fill(0, count($outletIds), '?')) . ')';
            array_push($args, ...$outletIds);
        }
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(l.quantity * p.cost_price),0) AS cost, COALESCE(SUM(l.quantity * p.selling_price),0) AS retail,
                COALESCE(SUM(l.quantity),0) AS units, COUNT(DISTINCT l.product_id) AS products
            FROM stock_levels l JOIN products p ON p.id = l.product_id AND p.business_id = l.business_id WHERE ' . implode(' AND ', $where));
        $st->execute($args);
        $r = $st->fetch();
        return ['cost' => $this->fmt((string) $r['cost']), 'retail' => $this->fmt((string) $r['retail']), 'units' => (int) $r['units'], 'products' => (int) $r['products']];
    }

    /** SQL DECIMAL(…,2) sums are exact already; normalise to a plain "123.45" string without going through capped Money. */
    private function fmt(string $decimal): string
    {
        if (!preg_match('/^(-?\d+)(?:\.(\d+))?$/', $decimal, $m)) { throw new \UnexpectedValueException('Unexpected decimal from database.'); }
        return $m[1] . '.' . str_pad(substr($m[2] ?? '', 0, 2), 2, '0');
    }

    /** @return list<array<string,mixed>> */
    public function exportRows(string $bid, ?array $outletIds): array
    {
        [$stockExpr, $stockParams] = $this->visibleStock($outletIds);
        $st = $this->pdo->prepare("SELECT p.name,p.sku,p.barcode,c.name AS category,p.item_type,p.selling_price,p.cost_price,$stockExpr AS stock,p.min_stock_level,p.reorder_level,p.description,p.is_active
            FROM products p LEFT JOIN categories c ON c.id = p.category_id AND c.business_id = p.business_id
            WHERE p.business_id = ? AND p.archived_at IS NULL ORDER BY p.name, p.id");
        $st->execute(array_merge($stockParams, [$bid]));
        return $st->fetchAll();
    }
}
