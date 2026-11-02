<?php
declare(strict_types=1);

namespace LeenTrack\Repositories;

use LeenTrack\Core\Clock;
use PDO;

/** Customers, groups, loyalty. Every method is scoped by business id. */
final class CustomerRepository
{
    private const CUST = 'c.id,c.customer_group_id,c.name,c.email,c.phone,c.address,c.date_of_birth,c.notes,c.loyalty_points,c.total_spent,c.visit_count,c.last_visit,c.archived_at,c.created_at,c.updated_at,g.name AS group_name,g.discount_percentage AS group_discount';

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ---------------------------------------------------------------- customers
    /**
     * @param array{search?:?string,group_id?:?string,archived?:bool} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function customers(string $bid, array $f, string $sort, string $dir, int $limit, int $offset): array
    {
        $where = ['c.business_id = ?', ($f['archived'] ?? false) ? 'c.archived_at IS NOT NULL' : 'c.archived_at IS NULL'];
        $args = [$bid];
        if (($f['search'] ?? null) !== null) {
            $like = '%' . addcslashes($f['search'], '\\%_') . '%';
            $where[] = '(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        if (($f['group_id'] ?? null) !== null) { $where[] = 'c.customer_group_id = ?'; $args[] = $f['group_id']; }
        $from = 'FROM customers c LEFT JOIN customer_groups g ON g.id = c.customer_group_id AND g.business_id = c.business_id WHERE ' . implode(' AND ', $where);
        $col = ['name' => 'c.name', 'created_at' => 'c.created_at', 'total_spent' => 'c.total_spent', 'last_visit' => 'c.last_visit', 'visit_count' => 'c.visit_count', 'loyalty_points' => 'c.loyalty_points'][$sort];
        $d = $dir === 'desc' ? 'DESC' : 'ASC';
        $cnt = $this->pdo->prepare("SELECT COUNT(*) $from");
        $cnt->execute($args);
        $st = $this->pdo->prepare('SELECT ' . self::CUST . " $from ORDER BY $col $d, c.id LIMIT $limit OFFSET $offset");
        $st->execute($args);
        return ['rows' => $st->fetchAll(), 'total' => (int) $cnt->fetchColumn()];
    }

    /** @return array<string,mixed>|null */
    public function customer(string $bid, string $id, bool $withArchived = false): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::CUST . ' FROM customers c LEFT JOIN customer_groups g ON g.id = c.customer_group_id AND g.business_id = c.business_id WHERE c.business_id = ? AND c.id = ?' . ($withArchived ? '' : ' AND c.archived_at IS NULL'));
        $st->execute([$bid, $id]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null row-locked. Sale code locks the customer FIRST so loyalty updates cannot deadlock on the FK's shared lock. */
    public function lockCustomer(string $bid, string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id,loyalty_points,archived_at FROM customers WHERE business_id = ? AND id = ? FOR UPDATE');
        $st->execute([$bid, $id]);
        return $st->fetch() ?: null;
    }

    public function countLive(string $bid): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM customers WHERE business_id = ? AND archived_at IS NULL');
        $st->execute([$bid]);
        return (int) $st->fetchColumn();
    }

    /** @param array<string,mixed> $c */
    public function insert(array $c): void
    {
        $this->pdo->prepare('INSERT INTO customers (id,business_id,customer_group_id,name,email,phone,address,date_of_birth,notes) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$c['id'], $c['business_id'], $c['customer_group_id'], $c['name'], $c['email'], $c['phone'], $c['address'], $c['date_of_birth'], $c['notes']]);
    }

    /** @param array<string,mixed> $fields */
    public function update(string $bid, string $id, array $fields): void
    {
        $allowed = ['customer_group_id', 'name', 'email', 'phone', 'address', 'date_of_birth', 'notes'];
        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) { throw new \LogicException("Column $k is not updatable here."); }
            $sets[] = "`$k` = ?";
            $vals[] = is_bool($v) ? (int) $v : $v;   // PDO would send false as '' and MySQL strict mode rejects it
        }
        if (!$sets) { return; }
        array_push($vals, $bid, $id);
        $this->pdo->prepare('UPDATE customers SET ' . implode(',', $sets) . ' WHERE business_id = ? AND id = ?')->execute($vals);
    }

    public function setArchived(string $bid, string $id, bool $archived): void
    {
        $this->pdo->prepare('UPDATE customers SET archived_at = ' . ($archived ? 'CURRENT_TIMESTAMP(3)' : 'NULL') . ' WHERE business_id = ? AND id = ?')->execute([$bid, $id]);
    }

    public function setPoints(string $bid, string $id, int $points): void
    {
        $this->pdo->prepare('UPDATE customers SET loyalty_points = ? WHERE business_id = ? AND id = ?')->execute([$points, $bid, $id]);
    }

    /** Called only by sale/repayment code, inside its transaction, after lockCustomer(). total_spent = money RECEIVED (docs G-12). */
    public function adjustStats(string $bid, string $id, string $spentDelta, int $visitDelta, ?string $lastVisit): void
    {
        $this->pdo->prepare('UPDATE customers SET total_spent = GREATEST(0, total_spent + ?), visit_count = GREATEST(0, visit_count + ?), last_visit = COALESCE(?, last_visit) WHERE business_id = ? AND id = ?')
            ->execute([$spentDelta, $visitDelta, $lastVisit, $bid, $id]);
    }

    // ---------------------------------------------------------------- groups
    /** @return list<array<string,mixed>> */
    public function groups(string $bid): array
    {
        $st = $this->pdo->prepare('SELECT g.id,g.name,g.description,g.discount_percentage,g.created_at,
            (SELECT COUNT(*) FROM customers c WHERE c.customer_group_id = g.id AND c.archived_at IS NULL) AS customer_count
            FROM customer_groups g WHERE g.business_id = ? ORDER BY g.name');
        $st->execute([$bid]);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function group(string $bid, string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id,name,description,discount_percentage,created_at FROM customer_groups WHERE business_id = ? AND id = ?');
        $st->execute([$bid, $id]);
        return $st->fetch() ?: null;
    }

    public function insertGroup(string $id, string $bid, string $name, ?string $desc, string $pct): void
    {
        $this->pdo->prepare('INSERT INTO customer_groups (id,business_id,name,description,discount_percentage) VALUES (?,?,?,?,?)')->execute([$id, $bid, $name, $desc, $pct]);
    }

    public function updateGroup(string $bid, string $id, string $name, ?string $desc, string $pct): void
    {
        $this->pdo->prepare('UPDATE customer_groups SET name = ?, description = ?, discount_percentage = ? WHERE business_id = ? AND id = ?')->execute([$name, $desc, $pct, $bid, $id]);
    }

    public function deleteGroup(string $bid, string $id): void
    {
        $this->pdo->prepare('DELETE FROM customer_groups WHERE business_id = ? AND id = ?')->execute([$bid, $id]);
    }

    // ---------------------------------------------------------------- loyalty
    /** @return array{enabled:int,spend_per_point:string,point_value:string}|null */
    public function loyaltySettings(string $bid): ?array
    {
        $st = $this->pdo->prepare('SELECT enabled,spend_per_point,point_value FROM loyalty_settings WHERE business_id = ?');
        $st->execute([$bid]);
        return $st->fetch() ?: null;
    }

    public function saveLoyaltySettings(string $bid, bool $enabled, string $spend, string $value, string $actor): void
    {
        $this->pdo->prepare('INSERT INTO loyalty_settings (business_id,enabled,spend_per_point,point_value,updated_by) VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), spend_per_point = VALUES(spend_per_point), point_value = VALUES(point_value), updated_by = VALUES(updated_by)')
            ->execute([$bid, (int) $enabled, $spend, $value, $actor]);
    }

    /** Progress carried by the most recent EARN row (redeem rows copy it forward, as in the source). */
    public function lastProgress(string $customerId): string
    {
        $st = $this->pdo->prepare("SELECT progress_after FROM loyalty_ledger WHERE customer_id = ? AND kind = 'earn' ORDER BY id DESC LIMIT 1");
        $st->execute([$customerId]);
        $v = $st->fetchColumn();
        return $v === false ? '0.00' : (string) $v;
    }

    public function insertLedger(string $bid, string $customerId, ?string $saleId, string $kind, string $money, int $points, string $progress, int $balance, ?string $rate, ?string $value, ?string $note, ?string $actor): void
    {
        $this->pdo->prepare('INSERT INTO loyalty_ledger (business_id,customer_id,sale_id,kind,money,points,progress_after,balance_after,spend_per_point,point_value,note,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$bid, $customerId, $saleId, $kind, $money, $points, $progress, $balance, $rate, $value, $note === null ? null : mb_substr($note, 0, 200), $actor, Clock::sql()]);
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function ledger(string $bid, string $customerId, int $limit, int $offset): array
    {
        $c = $this->pdo->prepare('SELECT COUNT(*) FROM loyalty_ledger WHERE business_id = ? AND customer_id = ?');
        $c->execute([$bid, $customerId]);
        $st = $this->pdo->prepare("SELECT id,kind,money,points,balance_after,note,sale_id,created_at FROM loyalty_ledger WHERE business_id = ? AND customer_id = ? ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $st->execute([$bid, $customerId]);
        return ['rows' => $st->fetchAll(), 'total' => (int) $c->fetchColumn()];
    }
}
