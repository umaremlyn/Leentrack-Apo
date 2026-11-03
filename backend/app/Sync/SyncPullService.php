<?php
declare(strict_types=1);

namespace LeenTrack\Sync;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Services\PlanCatalog;
use PDO;

/**
 * Offline pull: what a device needs to keep selling without a connection (catalogue, customers, groups, outlets, per-outlet stock,
 * business settings). Incremental by change time, with one cursor PER RESOURCE so a big table cannot starve a small one.
 *
 * Cursor = "<UTC time>|<id>". Inside one download ("sweep") the cursor is an exact keyset position. When a resource is exhausted the
 * cursor handed back is rewound by OVERLAP seconds (never past "now - overlap"), because a transaction that started earlier can commit after
 * a later one and would otherwise be missed. Re-sent rows are harmless: the device upserts by id.
 */
final class SyncPullService
{
    public const RESOURCES = ['products', 'categories', 'customers', 'customer_groups', 'locations', 'stock_levels', 'settings'];
    private const OVERLAP = 10;
    private const DEFAULT_LIMIT = 500;
    private const MAX_LIMIT = 1000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $body cursors (object), limit, resources (optional list)
     * @return array<string,mixed>
     */
    public function pull(TenantContext $t, array $body): array
    {
        $t->require('sales.create');                 // the dataset exists to run the till
        $v = new Validator($body);
        $v->only(['cursors', 'limit', 'resources']);
        $limit = $v->has('limit') ? (int) $v->int('limit', 1, self::MAX_LIMIT) : self::DEFAULT_LIMIT;
        $want = self::RESOURCES;
        if ($v->has('resources')) {
            $r = $body['resources'];
            if (!is_array($r) || !array_is_list($r) || !$r || array_diff($r, self::RESOURCES)) { $v->addError('resources', 'Choose from: ' . implode(', ', self::RESOURCES) . '.'); } else { $want = array_values(array_unique($r)); }
        }
        $cursors = [];
        if ($v->has('cursors')) {
            $c = $body['cursors'];
            if (!is_array($c) || (array_is_list($c) && $c !== [])) { $v->addError('cursors', 'Must be an object of resource => cursor.'); }
            else { foreach ($c as $k => $val) { if (!in_array($k, self::RESOURCES, true)) { $v->addError("cursors.$k", 'Unknown resource.'); } elseif ($val !== null) { $cursors[$k] = $this->parseCursor($val, $v, "cursors.$k"); } } }
        }
        $v->done();
        $now = Clock::now();
        $changes = []; $next = []; $more = false;
        foreach ($want as $res) {
            [$rows, $cursor, $hasMore] = match ($res) {
                'products' => $this->table($t, 'products', $cursors[$res] ?? null, $limit, $now, $this->productSelect($t), "p.business_id = ?", [$t->businessId], 'p', 'p.id'),
                'categories' => $this->table($t, 'categories', $cursors[$res] ?? null, $limit, $now, 'c.id,c.name,c.description,c.updated_at', 'c.business_id = ?', [$t->businessId], 'c', 'c.id'),
                'customers' => $this->table($t, 'customers', $cursors[$res] ?? null, $limit, $now, 'c.id,c.customer_group_id,c.name,c.email,c.phone,c.address,c.loyalty_points,c.total_spent,c.visit_count,c.last_visit,c.archived_at,c.updated_at', 'c.business_id = ?', [$t->businessId], 'c', 'c.id'),
                'customer_groups' => $this->table($t, 'customer_groups', $cursors[$res] ?? null, $limit, $now, 'g.id,g.name,g.discount_percentage,g.updated_at', 'g.business_id = ?', [$t->businessId], 'g', 'g.id'),
                'locations' => $this->locations($t, $cursors[$res] ?? null, $limit, $now),
                'stock_levels' => $this->stockLevels($t, $cursors[$res] ?? null, $limit, $now),
                'settings' => $this->settings($t, $cursors[$res] ?? null, $now),
            };
            $changes[$res] = $rows; $next[$res] = $cursor; $more = $more || $hasMore;
        }
        $ref = in_array('categories', $want, true) || in_array('customer_groups', $want, true) ? [
            'categories' => $this->ids('categories', $t->businessId), 'customer_groups' => $this->ids('customer_groups', $t->businessId),
        ] : [];
        return ['server_time' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'), 'changes' => $changes, 'cursors' => $next, 'has_more' => $more,
            'reference_ids' => $ref, 'note' => 'Rows can repeat (a short overlap protects against late commits): upsert by id. Remove products/customers that arrive with archived_at set, and categories/groups missing from reference_ids.'];
    }

    // ================================================================================================ resources
    private function productSelect(TenantContext $t): string
    {
        return 'p.id,p.category_id,p.name,p.description,p.sku,p.barcode,p.item_type,p.selling_price,' . ($t->can('products.cost.read') ? 'p.cost_price,' : '') . 'p.min_stock_level,p.reorder_level,p.image_url,p.is_active,p.archived_at,p.updated_at';
    }

    /** @param array{0:string,1:string}|null $cursor @return array{0:list<array<string,mixed>>,1:string,2:bool} */
    private function table(TenantContext $t, string $table, ?array $cursor, int $limit, \DateTimeImmutable $now, string $cols, string $where, array $args, string $alias, string $idCol): array
    {
        [$cw, $ca] = $this->after($cursor, "$alias.updated_at", $idCol);
        $st = $this->pdo->prepare("SELECT $cols FROM `$table` $alias WHERE $where $cw ORDER BY $alias.updated_at, $idCol LIMIT " . ($limit + 1));
        $st->execute(array_merge($args, $ca));
        return $this->page($st->fetchAll(), $limit, $cursor, $now, 'id');
    }

    /** @return array{0:list<array<string,mixed>>,1:string,2:bool} */
    private function locations(TenantContext $t, ?array $cursor, int $limit, \DateTimeImmutable $now): array
    {
        $where = 'l.business_id = ?'; $args = [$t->businessId];
        if ($t->outletIds !== null) {
            if (!$t->outletIds) { return [[], $this->cursorString($cursor, $now, []), false]; }
            $where .= ' AND l.id IN (' . implode(',', array_fill(0, count($t->outletIds), '?')) . ')'; array_push($args, ...$t->outletIds);
        }
        return $this->table($t, 'locations', $cursor, $limit, $now, 'l.id,l.name,l.timezone,l.is_default,l.is_active,l.updated_at', $where, $args, 'l', 'l.id');
    }

    /** @return array{0:list<array<string,mixed>>,1:string,2:bool} */
    private function stockLevels(TenantContext $t, ?array $cursor, int $limit, \DateTimeImmutable $now): array
    {
        $where = 'l.business_id = ?'; $args = [$t->businessId];
        if ($t->outletIds !== null) {
            if (!$t->outletIds) { return [[], $this->cursorString($cursor, $now, []), false]; }
            $where .= ' AND l.location_id IN (' . implode(',', array_fill(0, count($t->outletIds), '?')) . ')'; array_push($args, ...$t->outletIds);
        }
        [$cw, $ca] = $this->after($cursor, 'l.updated_at', "CONCAT(l.product_id,'|',l.location_id)");
        $st = $this->pdo->prepare("SELECT CONCAT(l.product_id,'|',l.location_id) AS id, l.product_id, l.location_id, l.quantity, l.updated_at FROM stock_levels l WHERE $where $cw ORDER BY l.updated_at, id LIMIT " . ($limit + 1));
        $st->execute(array_merge($args, $ca));
        return $this->page($st->fetchAll(), $limit, $cursor, $now, 'id');
    }

    /** Business, loyalty and branding in one object, only when any of them changed. @return array{0:list<array<string,mixed>>,1:string,2:bool} */
    private function settings(TenantContext $t, ?array $cursor, \DateTimeImmutable $now): array
    {
        $since = $cursor === null ? '1000-01-01 00:00:00.000' : $cursor[0];
        $st = $this->pdo->prepare("SELECT b.id,b.name,b.currency,b.tax_rate,b.plan,b.plan_status,b.plan_expiry,b.updated_at AS b_at,
              ls.enabled AS loyalty_enabled, ls.spend_per_point, ls.point_value, ls.updated_at AS l_at,
              br.logo_data, br.phone, br.email, br.address, br.payment_details, br.footer_note, br.updated_at AS br_at
            FROM businesses b LEFT JOIN loyalty_settings ls ON ls.business_id = b.id LEFT JOIN business_branding br ON br.business_id = b.id WHERE b.id = ?");
        $st->execute([$t->businessId]);
        $r = $st->fetch() ?: throw ApiException::notFound();
        $max = max($r['b_at'], $r['l_at'] ?? '0', $r['br_at'] ?? '0');
        $row = null;
        if ($max > $since) {
            $row = ['id' => $r['id'], 'name' => $r['name'], 'currency' => $r['currency'], 'tax_rate' => $r['tax_rate'], 'effective_plan' => PlanCatalog::effective($r),
                'loyalty' => ['enabled' => (bool) ($r['loyalty_enabled'] ?? false), 'spend_per_point' => $r['spend_per_point'] ?? null, 'point_value' => $r['point_value'] ?? null],
                'branding' => ['logo_data' => $r['logo_data'], 'phone' => $r['phone'], 'email' => $r['email'], 'address' => $r['address'], 'payment_details' => $r['payment_details'], 'footer_note' => $r['footer_note']], 'updated_at' => $max];
        }
        $safe = min($max, Clock::sql($now->modify('-' . self::OVERLAP . ' seconds')));
        return [$row === null ? [] : [$row], $safe . '|', false];
    }

    /** @return list<string> */
    private function ids(string $table, string $bid): array
    {
        $st = $this->pdo->prepare("SELECT id FROM `$table` WHERE business_id = ? ORDER BY id");
        $st->execute([$bid]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    // ================================================================================================ cursors
    /** @param array{0:string,1:string}|null $cursor @return array{0:string,1:list<string>} */
    private function after(?array $cursor, string $tsCol, string $idCol): array
    {
        if ($cursor === null) { return ['', []]; }
        return [" AND ($tsCol > ? OR ($tsCol = ? AND $idCol > ?))", [$cursor[0], $cursor[0], $cursor[1]]];
    }

    /**
     * @param list<array<string,mixed>> $rows (limit+1 fetched)
     * @param array{0:string,1:string}|null $cursor
     * @return array{0:list<array<string,mixed>>,1:string,2:bool}
     */
    private function page(array $rows, int $limit, ?array $cursor, \DateTimeImmutable $now, string $idKey): array
    {
        $more = count($rows) > $limit;
        if ($more) { array_pop($rows); }
        foreach ($rows as &$r) { $r['updated_at'] = (new \DateTimeImmutable($r['updated_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'); if (array_key_exists('archived_at', $r) && $r['archived_at'] !== null) { $r['archived_at'] = (new \DateTimeImmutable($r['archived_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'); } }
        unset($r);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        if ($more) {
            return [$rows, $this->fmt(gmdate('Y-m-d H:i:s', strtotime((string) $last['updated_at'])) . '.' . substr((string) $last['updated_at'], 20, 3), (string) $last[$idKey]), true];
        }
        return [$rows, $this->cursorString($cursor, $now, $rows), false];
    }

    /**
     * Where an exhausted resource leaves the device.
     *  - Newest change older than OVERLAP seconds: an exact position right after it (nothing is re-sent while idle).
     *  - Newer than that: rewound to "now - OVERLAP" with no id, so changes committed late by transactions that started earlier are still caught.
     * @param array{0:string,1:string}|null $cursor @param list<array<string,mixed>> $rows (formatted rows of the last page, ascending)
     */
    private function cursorString(?array $cursor, \DateTimeImmutable $now, array $rows): string
    {
        $safe = Clock::sql($now->modify('-' . self::OVERLAP . ' seconds'));
        if ($rows === []) {
            $c = $cursor ?? ['1000-01-01 00:00:00.000', ''];
            return $c[0] <= $safe ? $this->fmt($c[0], $c[1]) : $this->fmt($safe, '');
        }
        $last = $rows[array_key_last($rows)];
        $ts = gmdate('Y-m-d H:i:s', strtotime((string) $last['updated_at'])) . '.' . substr((string) $last['updated_at'], 20, 3);
        return $ts <= $safe ? $this->fmt($ts, (string) $last['id']) : $this->fmt($safe, '');
    }

    private function fmt(string $ts, string $id): string
    {
        return $ts . '|' . $id;
    }

    /** @return array{0:string,1:string} */
    private function parseCursor(mixed $raw, Validator $v, string $field): array
    {
        if (!is_string($raw) || !preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3})\|([A-Za-z0-9_|-]{0,80})$/', $raw, $m)) {
            $v->addError($field, 'Not a cursor. Use exactly what the server returned.');
            return ['1000-01-01 00:00:00.000', ''];
        }
        return [$m[1], $m[2]];
    }
}
