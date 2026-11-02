<?php
declare(strict_types=1);

namespace LeenTrack\Repositories;

use PDO;

/** Read-only queries behind profit, dashboard and (3h) reports. Every query is scoped by business id and, where relevant, outlets. */
final class ReportRepository
{
    public const MAX_ITEM_ROWS = 200_000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<string>|null $outletIds @return array{0:string,1:list<mixed>}|null null = nothing visible */
    private function saleScope(string $bid, ?string $outletId, ?array $outletIds): ?array
    {
        $w = "s.business_id = ? AND s.payment_status IN ('completed','pending')";     // sales that count (a credit sale is a sale before it is paid)
        $a = [$bid];
        if ($outletId !== null) { $w .= ' AND s.outlet_id = ?'; $a[] = $outletId; }
        if ($outletIds !== null) {
            if (!$outletIds) { return null; }
            $w .= ' AND s.outlet_id IN (' . implode(',', array_fill(0, count($outletIds), '?')) . ')';
            array_push($a, ...$outletIds);
        }
        return [$w, $a];
    }

    /** @param list<string>|null $outletIds @return list<array<string,mixed>> */
    public function salesInRange(string $bid, string $fromUtc, string $toUtc, ?string $outletId, ?array $outletIds): array
    {
        $sc = $this->saleScope($bid, $outletId, $outletIds);
        if ($sc === null) { return []; }
        $st = $this->pdo->prepare("SELECT s.id,s.receipt_number,s.created_at,s.subtotal,s.discount_amount,s.tax_amount,s.total_amount,s.amount_paid,c.name AS customer_name
            FROM sales s LEFT JOIN customers c ON c.id = s.customer_id AND c.business_id = s.business_id WHERE {$sc[0]} AND s.created_at >= ? AND s.created_at < ? ORDER BY s.created_at DESC, s.id");
        $st->execute(array_merge($sc[1], [$fromUtc, $toUtc]));
        return $st->fetchAll();
    }

    /** @param list<string>|null $outletIds @return list<array<string,mixed>> */
    public function itemsInRange(string $bid, string $fromUtc, string $toUtc, ?string $outletId, ?array $outletIds): array
    {
        $sc = $this->saleScope($bid, $outletId, $outletIds);
        if ($sc === null) { return []; }
        $cnt = $this->pdo->prepare("SELECT COUNT(*) FROM sale_items i JOIN sales s ON s.id = i.sale_id WHERE {$sc[0]} AND s.created_at >= ? AND s.created_at < ?");
        $cnt->execute(array_merge($sc[1], [$fromUtc, $toUtc]));
        if ((int) $cnt->fetchColumn() > self::MAX_ITEM_ROWS) { throw new \LeenTrack\Core\ApiException('range_too_large', 'That period has too many sold items to analyse at once. Choose a shorter period.', 413); }
        $st = $this->pdo->prepare("SELECT i.sale_id,i.product_id,i.product_name,i.item_type,i.quantity,i.total_price,i.unit_cost,p.cost_price AS product_cost
            FROM sale_items i JOIN sales s ON s.id = i.sale_id LEFT JOIN products p ON p.id = i.product_id AND p.business_id = i.business_id
            WHERE {$sc[0]} AND s.created_at >= ? AND s.created_at < ? ORDER BY i.id");
        $st->execute(array_merge($sc[1], [$fromUtc, $toUtc]));
        return $st->fetchAll();
    }

    /**
     * @param list<string>|null $outletIds
     * @return array{count:int,billed:string,tax:string,net_sales:string,paid_at_till:string}
     */
    public function salesTotals(string $bid, string $fromUtc, string $toUtc, ?string $outletId, ?array $outletIds): array
    {
        $zero = ['count' => 0, 'billed' => '0.00', 'tax' => '0.00', 'net_sales' => '0.00', 'paid_at_till' => '0.00'];
        $sc = $this->saleScope($bid, $outletId, $outletIds);
        if ($sc === null) { return $zero; }
        $st = $this->pdo->prepare("SELECT COUNT(*), COALESCE(SUM(s.total_amount),0), COALESCE(SUM(s.tax_amount),0), COALESCE(SUM(s.subtotal - s.discount_amount),0), COALESCE(SUM(LEAST(s.amount_paid, s.total_amount)),0)
            FROM sales s WHERE {$sc[0]} AND s.created_at >= ? AND s.created_at < ?");
        $st->execute(array_merge($sc[1], [$fromUtc, $toUtc]));
        $r = $st->fetch(PDO::FETCH_NUM);
        return ['count' => (int) $r[0], 'billed' => (string) $r[1], 'tax' => (string) $r[2], 'net_sales' => (string) $r[3], 'paid_at_till' => (string) $r[4]];
    }

    /** Repayments received in the window on sales that still count. @param list<string>|null $outletIds */
    public function repaymentsTotal(string $bid, string $fromUtc, string $toUtc, ?string $outletId, ?array $outletIds): string
    {
        $sc = $this->saleScope($bid, $outletId, $outletIds);
        if ($sc === null) { return '0.00'; }
        $st = $this->pdo->prepare("SELECT COALESCE(SUM(d.amount),0) FROM debt_repayments d JOIN sales s ON s.id = d.sale_id WHERE {$sc[0]} AND d.created_at >= ? AND d.created_at < ?");
        $st->execute(array_merge($sc[1], [$fromUtc, $toUtc]));
        return (string) $st->fetchColumn();
    }

    /** @param list<string>|null $outletIds */
    public function expensesTotal(string $bid, string $fromDate, string $toDate, ?string $outletId, ?array $outletIds): string
    {
        $w = 'e.business_id = ? AND e.deleted_at IS NULL AND e.expense_date >= ? AND e.expense_date < ?';
        $a = [$bid, $fromDate, $toDate];
        if ($outletId !== null) { $w .= ' AND e.outlet_id = ?'; $a[] = $outletId; }
        if ($outletIds !== null) {
            if (!$outletIds) { return '0.00'; }
            $w .= ' AND e.outlet_id IN (' . implode(',', array_fill(0, count($outletIds), '?')) . ')';
            array_push($a, ...$outletIds);
        }
        $st = $this->pdo->prepare("SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE $w");
        $st->execute($a);
        return (string) $st->fetchColumn();
    }

    /** @param list<string>|null $outletIds @return array<string,string> date => total */
    public function expensesByDay(string $bid, string $fromDate, string $toDate, ?string $outletId, ?array $outletIds): array
    {
        $w = 'e.business_id = ? AND e.deleted_at IS NULL AND e.expense_date >= ? AND e.expense_date < ?';
        $a = [$bid, $fromDate, $toDate];
        if ($outletId !== null) { $w .= ' AND e.outlet_id = ?'; $a[] = $outletId; }
        if ($outletIds !== null) {
            if (!$outletIds) { return []; }
            $w .= ' AND e.outlet_id IN (' . implode(',', array_fill(0, count($outletIds), '?')) . ')';
            array_push($a, ...$outletIds);
        }
        $st = $this->pdo->prepare("SELECT e.expense_date, SUM(e.amount) FROM expenses e WHERE $w GROUP BY e.expense_date");
        $st->execute($a);
        return array_column($st->fetchAll(PDO::FETCH_NUM), 1, 0);
    }

    /** @param list<string>|null $outletIds @return list<array{0:string,1:string}> (created_at, total) */
    public function saleTimes(string $bid, string $fromUtc, string $toUtc, ?string $outletId, ?array $outletIds): array
    {
        $sc = $this->saleScope($bid, $outletId, $outletIds);
        if ($sc === null) { return []; }
        $st = $this->pdo->prepare("SELECT s.created_at, s.total_amount FROM sales s WHERE {$sc[0]} AND s.created_at >= ? AND s.created_at < ?");
        $st->execute(array_merge($sc[1], [$fromUtc, $toUtc]));
        return $st->fetchAll(PDO::FETCH_NUM);
    }

    /** Top sellers by revenue (line totals), grouped by PRODUCT id — the source grouped by name and merged different products. @param list<string>|null $outletIds @return list<array<string,mixed>> */
    public function topProducts(string $bid, string $fromUtc, string $toUtc, ?string $outletId, ?array $outletIds, int $limit): array
    {
        $sc = $this->saleScope($bid, $outletId, $outletIds);
        if ($sc === null) { return []; }
        $st = $this->pdo->prepare("SELECT i.product_id, MAX(i.product_name) AS name, SUM(i.quantity) AS qty, SUM(i.total_price) AS revenue
            FROM sale_items i JOIN sales s ON s.id = i.sale_id WHERE {$sc[0]} AND s.created_at >= ? AND s.created_at < ? GROUP BY i.product_id ORDER BY revenue DESC, name LIMIT $limit");
        $st->execute(array_merge($sc[1], [$fromUtc, $toUtc]));
        return $st->fetchAll();
    }

    /** @param list<string>|null $outletIds @return list<array<string,mixed>> */
    public function recentSales(string $bid, ?string $outletId, ?array $outletIds, int $limit): array
    {
        $sc = $this->saleScope($bid, $outletId, $outletIds);
        if ($sc === null) { return []; }
        $st = $this->pdo->prepare("SELECT s.id,s.receipt_number,s.total_amount,s.payment_method,s.payment_status,s.created_at,c.name AS customer_name FROM sales s LEFT JOIN customers c ON c.id = s.customer_id AND c.business_id = s.business_id
            WHERE {$sc[0]} ORDER BY s.created_at DESC, s.id DESC LIMIT $limit");
        $st->execute($sc[1]);
        return $st->fetchAll();
    }

    /** @param list<string>|null $outletIds @return array{products:int,low:int,out:int} active catalogue counts as the dashboard defines them */
    public function stockCounts(string $bid, ?string $outletId, ?array $outletIds): array
    {
        $ids = $outletId !== null ? [$outletId] : $outletIds;
        if ($ids !== null && !$ids) { return ['products' => 0, 'low' => 0, 'out' => 0]; }
        $sub = $ids === null ? 'p.stock_quantity' : 'COALESCE((SELECT SUM(l.quantity) FROM stock_levels l WHERE l.product_id = p.id AND l.location_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')),0)';
        $st = $this->pdo->prepare("SELECT COUNT(*), COALESCE(SUM(p.item_type = 'product' AND s > 0 AND s <= p.reorder_level),0), COALESCE(SUM(p.item_type = 'product' AND s <= 0),0)
            FROM (SELECT p.item_type, p.reorder_level, $sub AS s FROM products p WHERE p.business_id = ? AND p.archived_at IS NULL AND p.is_active = 1) p");
        $st->execute(array_merge($ids ?? [], [$bid]));
        $r = $st->fetch(PDO::FETCH_NUM);
        return ['products' => (int) $r[0], 'low' => (int) $r[1], 'out' => (int) $r[2]];
    }
}
