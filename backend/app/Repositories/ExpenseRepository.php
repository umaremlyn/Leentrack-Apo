<?php
declare(strict_types=1);

namespace LeenTrack\Repositories;

use PDO;

final class ExpenseRepository
{
    private const COLS = 'e.id,e.outlet_id,e.category_id,e.user_id,e.amount,e.description,e.notes,e.payment_method,e.payment_status,e.payment_reference,e.receipt_image_url,e.expense_date,e.created_at,e.updated_at,c.name AS category_name';

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ---- categories
    /** @return list<array<string,mixed>> */
    public function categories(string $bid): array
    {
        $st = $this->pdo->prepare('SELECT c.id,c.name,c.description,c.created_at,(SELECT COUNT(*) FROM expenses e WHERE e.category_id = c.id AND e.deleted_at IS NULL) AS expense_count FROM expense_categories c WHERE c.business_id = ? ORDER BY c.name');
        $st->execute([$bid]);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function category(string $bid, string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id,name,description FROM expense_categories WHERE business_id = ? AND id = ?');
        $st->execute([$bid, $id]);
        return $st->fetch() ?: null;
    }

    public function insertCategory(string $id, string $bid, string $name, ?string $desc): void
    {
        $this->pdo->prepare('INSERT INTO expense_categories (id,business_id,name,description) VALUES (?,?,?,?)')->execute([$id, $bid, $name, $desc]);
    }

    public function updateCategory(string $bid, string $id, string $name, ?string $desc): void
    {
        $this->pdo->prepare('UPDATE expense_categories SET name = ?, description = ? WHERE business_id = ? AND id = ?')->execute([$name, $desc, $bid, $id]);
    }

    public function countCategories(string $bid): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM expense_categories WHERE business_id = ?');
        $st->execute([$bid]);
        return (int) $st->fetchColumn();
    }

    public function liveExpensesIn(string $bid, string $categoryId): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM expenses WHERE business_id = ? AND category_id = ? AND deleted_at IS NULL');
        $st->execute([$bid, $categoryId]);
        return (int) $st->fetchColumn();
    }

    public function deleteCategory(string $bid, string $id): void
    {
        $this->pdo->prepare('UPDATE expenses SET category_id = NULL WHERE business_id = ? AND category_id = ? AND deleted_at IS NOT NULL')->execute([$bid, $id]);   // history rows keep existing
        $this->pdo->prepare('DELETE FROM expense_categories WHERE business_id = ? AND id = ?')->execute([$bid, $id]);
    }

    // ---- expenses
    /**
     * @param array{from?:?string,to?:?string,category_id?:?string,search?:?string,payment_method?:?string,outlet_id?:?string} $f dates are YYYY-MM-DD, `to` exclusive
     * @param list<string>|null $outletIds
     * @return array{rows:list<array<string,mixed>>,total:int,sum:string,byCategory:list<array<string,mixed>>}
     */
    public function list(string $bid, array $f, ?array $outletIds, int $limit, int $offset): array
    {
        [$where, $args] = $this->where($bid, $f, $outletIds);
        if ($where === null) { return ['rows' => [], 'total' => 0, 'sum' => '0.00', 'byCategory' => []]; }
        $from = 'FROM expenses e LEFT JOIN expense_categories c ON c.id = e.category_id AND c.business_id = e.business_id WHERE ' . $where;
        $agg = $this->pdo->prepare("SELECT COUNT(*), COALESCE(SUM(e.amount),0) $from");
        $agg->execute($args);
        [$count, $sum] = $agg->fetch(PDO::FETCH_NUM);
        $cat = $this->pdo->prepare("SELECT e.category_id, c.name, COUNT(*) n, SUM(e.amount) total $from GROUP BY e.category_id, c.name ORDER BY total DESC");
        $cat->execute($args);
        $st = $this->pdo->prepare('SELECT ' . self::COLS . " $from ORDER BY e.expense_date DESC, e.created_at DESC, e.id DESC LIMIT $limit OFFSET $offset");
        $st->execute($args);
        return ['rows' => $st->fetchAll(), 'total' => (int) $count, 'sum' => (string) $sum, 'byCategory' => $cat->fetchAll()];
    }

    /** @param array<string,mixed> $f @param list<string>|null $outletIds @return array{0:?string,1:list<mixed>} */
    private function where(string $bid, array $f, ?array $outletIds): array
    {
        $w = ['e.business_id = ?', 'e.deleted_at IS NULL'];
        $a = [$bid];
        if (($f['from'] ?? null) !== null) { $w[] = 'e.expense_date >= ?'; $a[] = $f['from']; }
        if (($f['to'] ?? null) !== null) { $w[] = 'e.expense_date < ?'; $a[] = $f['to']; }
        if (($f['category_id'] ?? null) !== null) { $w[] = 'e.category_id = ?'; $a[] = $f['category_id']; }
        if (($f['payment_method'] ?? null) !== null) { $w[] = 'e.payment_method = ?'; $a[] = $f['payment_method']; }
        if (($f['outlet_id'] ?? null) !== null) { $w[] = 'e.outlet_id = ?'; $a[] = $f['outlet_id']; }
        if (($f['search'] ?? null) !== null) { $w[] = 'e.description LIKE ?'; $a[] = '%' . addcslashes($f['search'], '\\%_') . '%'; }
        if ($outletIds !== null) {
            if (!$outletIds) { return [null, []]; }
            $w[] = 'e.outlet_id IN (' . implode(',', array_fill(0, count($outletIds), '?')) . ')';   // business-wide (outlet-less) expenses are hidden from outlet-restricted members
            array_push($a, ...$outletIds);
        }
        return [implode(' AND ', $w), $a];
    }

    /** @return array<string,mixed>|null */
    public function expense(string $bid, string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::COLS . ' FROM expenses e LEFT JOIN expense_categories c ON c.id = e.category_id AND c.business_id = e.business_id WHERE e.business_id = ? AND e.id = ? AND e.deleted_at IS NULL');
        $st->execute([$bid, $id]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,mixed> $e */
    public function insert(array $e): void
    {
        $this->pdo->prepare('INSERT INTO expenses (id,business_id,outlet_id,category_id,user_id,amount,description,notes,payment_method,payment_status,payment_reference,receipt_image_url,expense_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$e['id'], $e['business_id'], $e['outlet_id'], $e['category_id'], $e['user_id'], $e['amount'], $e['description'], $e['notes'], $e['payment_method'], $e['payment_status'], $e['payment_reference'], $e['receipt_image_url'], $e['expense_date']]);
    }

    /** @param array<string,mixed> $fields */
    public function update(string $bid, string $id, array $fields): void
    {
        $allowed = ['outlet_id', 'category_id', 'amount', 'description', 'notes', 'payment_method', 'payment_status', 'payment_reference', 'receipt_image_url', 'expense_date'];
        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) { throw new \LogicException("Column $k is not updatable here."); }
            $sets[] = "`$k` = ?";
            $vals[] = is_bool($v) ? (int) $v : $v;
        }
        if (!$sets) { return; }
        array_push($vals, $bid, $id);
        $this->pdo->prepare('UPDATE expenses SET ' . implode(',', $sets) . ' WHERE business_id = ? AND id = ? AND deleted_at IS NULL')->execute($vals);
    }

    public function softDelete(string $bid, string $id, string $actor): void
    {
        $this->pdo->prepare('UPDATE expenses SET deleted_at = CURRENT_TIMESTAMP(3), deleted_by = ? WHERE business_id = ? AND id = ? AND deleted_at IS NULL')->execute([$actor, $bid, $id]);
    }
}
