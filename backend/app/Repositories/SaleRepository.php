<?php
declare(strict_types=1);

namespace LeenTrack\Repositories;

use PDO;

/** Sales, items, repayments (read), edit history. Every query is scoped by business id. */
final class SaleRepository
{
    private const COLS = 's.id,s.outlet_id,s.user_id,s.customer_id,s.receipt_number,s.subtotal,s.discount_amount,s.group_discount,s.manual_discount,s.points_discount,s.points_redeemed,s.tax_rate,s.tax_amount,s.total_amount,s.amount_paid,s.change_amount,s.payment_method,s.payment_status,s.is_credit,s.notes,s.client_operation_id,s.request_hash,s.voided_at,s.void_reason,s.created_at,s.updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Next number for (business, kind); runs inside the sale transaction so a rollback returns the number. */
    public function nextSequence(string $bid, string $kind): int
    {
        $this->pdo->prepare('INSERT IGNORE INTO document_sequences (business_id, kind, next_value) VALUES (?,?,1)')->execute([$bid, $kind]);
        $this->pdo->prepare('UPDATE document_sequences SET next_value = LAST_INSERT_ID(next_value) + 1 WHERE business_id = ? AND kind = ?')->execute([$bid, $kind]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $s */
    public function insertSale(array $s): void
    {
        $this->pdo->prepare('INSERT INTO sales (id,business_id,outlet_id,user_id,customer_id,receipt_number,subtotal,discount_amount,group_discount,manual_discount,points_discount,points_redeemed,tax_rate,tax_amount,total_amount,amount_paid,change_amount,payment_method,payment_status,is_credit,notes,client_operation_id,request_hash,client_created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$s['id'], $s['business_id'], $s['outlet_id'], $s['user_id'], $s['customer_id'], $s['receipt_number'], $s['subtotal'], $s['discount_amount'], $s['group_discount'], $s['manual_discount'], $s['points_discount'], $s['points_redeemed'], $s['tax_rate'], $s['tax_amount'], $s['total_amount'], $s['amount_paid'], $s['change_amount'], $s['payment_method'], $s['payment_status'], (int) $s['is_credit'], $s['notes'], $s['client_operation_id'], $s['request_hash'], $s['client_created_at']]);
    }

    public function setReceiptNumber(string $bid, string $id, string $number): void
    {
        $this->pdo->prepare('UPDATE sales SET receipt_number = ? WHERE business_id = ? AND id = ?')->execute([$number, $bid, $id]);
    }

    /** @param array<string,mixed> $i */
    public function insertItem(array $i): void
    {
        $this->pdo->prepare('INSERT INTO sale_items (id,sale_id,business_id,product_id,product_name,item_type,quantity,unit_price,total_price,unit_cost,position) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$i['id'], $i['sale_id'], $i['business_id'], $i['product_id'], $i['product_name'], $i['item_type'], $i['quantity'], $i['unit_price'], $i['total_price'], $i['unit_cost'], $i['position']]);
    }

    public function deleteItems(string $bid, string $saleId): void
    {
        $this->pdo->prepare('DELETE FROM sale_items WHERE business_id = ? AND sale_id = ?')->execute([$bid, $saleId]);
    }

    /** @return list<array<string,mixed>> */
    public function items(string $bid, string $saleId): array
    {
        $st = $this->pdo->prepare('SELECT id,product_id,product_name,item_type,quantity,unit_price,total_price,unit_cost FROM sale_items WHERE business_id = ? AND sale_id = ? ORDER BY position, id');
        $st->execute([$bid, $saleId]);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findByOperation(string $bid, string $opId): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::COLS . ' FROM sales s WHERE s.business_id = ? AND s.client_operation_id = ?');
        $st->execute([$bid, $opId]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function sale(string $bid, string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::COLS . ',c.name AS customer_name,c.phone AS customer_phone,c.email AS customer_email,c.address AS customer_address,l.name AS outlet_name,p.full_name AS cashier_name
            FROM sales s LEFT JOIN customers c ON c.id = s.customer_id AND c.business_id = s.business_id
            LEFT JOIN locations l ON l.id = s.outlet_id LEFT JOIN user_profiles p ON p.user_id = s.user_id
            WHERE s.business_id = ? AND s.id = ?');
        $st->execute([$bid, $id]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null row-locked */
    public function lockSale(string $bid, string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::COLS . ' FROM sales s WHERE s.business_id = ? AND s.id = ? FOR UPDATE');
        $st->execute([$bid, $id]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function saleCustomerId(string $bid, string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT customer_id, outlet_id FROM sales WHERE business_id = ? AND id = ?');
        $st->execute([$bid, $id]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,mixed> $f fields of sales that edit/void may change */
    public function updateSale(string $bid, string $id, array $f): void
    {
        $allowed = ['subtotal', 'discount_amount', 'group_discount', 'manual_discount', 'points_discount', 'tax_amount', 'total_amount', 'amount_paid', 'payment_status', 'is_credit', 'voided_at', 'voided_by', 'void_reason'];
        $sets = [];
        $vals = [];
        foreach ($f as $k => $v) {
            if (!in_array($k, $allowed, true)) { throw new \LogicException("Column $k is not updatable here."); }
            $sets[] = "`$k` = ?";
            $vals[] = $v;
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP(3)';
        array_push($vals, $bid, $id);
        $this->pdo->prepare('UPDATE sales SET ' . implode(',', $sets) . ' WHERE business_id = ? AND id = ?')->execute($vals);
    }

    public function repaidTotal(string $bid, string $saleId): string
    {
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM debt_repayments WHERE business_id = ? AND sale_id = ?');
        $st->execute([$bid, $saleId]);
        return (string) $st->fetchColumn();
    }

    /** @return array{count:int,last:?string} */
    public function editInfo(string $bid, string $saleId): array
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) c, MAX(edited_at) l FROM sale_edits WHERE business_id = ? AND sale_id = ?');
        $st->execute([$bid, $saleId]);
        $r = $st->fetch();
        return ['count' => (int) $r['c'], 'last' => $r['l']];
    }

    /** @param array<string,mixed> $e */
    public function insertEdit(array $e): void
    {
        $this->pdo->prepare('INSERT INTO sale_edits (id,business_id,sale_id,edited_by,reason,old_total,new_total,paid_so_far,balance_due,refund_due,extra_paid,extra_method,before_discount,after_discount,before_items,after_items)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$e['id'], $e['business_id'], $e['sale_id'], $e['edited_by'], $e['reason'], $e['old_total'], $e['new_total'], $e['paid_so_far'], $e['balance_due'], $e['refund_due'], $e['extra_paid'], $e['extra_method'], $e['before_discount'], $e['after_discount'], $e['before_items'], $e['after_items']]);
    }

    public function countSales(string $bid): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM sales WHERE business_id = ? AND payment_status <> 'voided'");
        $st->execute([$bid]);
        return (int) $st->fetchColumn();
    }

    /** @return ?string business time zone = the default outlet's zone (Africa/Lagos unless changed) */
    public function businessTimezone(string $bid): string
    {
        $st = $this->pdo->prepare('SELECT timezone FROM locations WHERE business_id = ? AND is_default = 1 LIMIT 1');
        $st->execute([$bid]);
        return (string) ($st->fetchColumn() ?: 'Africa/Lagos');
    }

    /**
     * @param array{from?:?string,to?:?string,status?:?string,payment?:?string,customer_id?:?string,outlet_id?:?string,search?:?string} $f UTC datetimes for from/to
     * @param list<string>|null $outletIds
     * @return array{rows:list<array<string,mixed>>,total:int,summary:array{count:int,total:string}}
     */
    public function list(string $bid, array $f, ?array $outletIds, int $limit, int $offset): array
    {
        $where = ['s.business_id = ?'];
        $args = [$bid];
        if (($f['from'] ?? null) !== null) { $where[] = 's.created_at >= ?'; $args[] = $f['from']; }
        if (($f['to'] ?? null) !== null) { $where[] = 's.created_at < ?'; $args[] = $f['to']; }
        if (($f['status'] ?? null) !== null) { $where[] = 's.payment_status = ?'; $args[] = $f['status']; }
        if (($f['payment'] ?? null) !== null) {
            if ($f['payment'] === 'credit') { $where[] = "(s.is_credit = 1 OR s.payment_status = 'pending')"; }
            else { $where[] = 's.payment_method = ?'; $args[] = $f['payment']; }
        }
        if (($f['customer_id'] ?? null) !== null) { $where[] = 's.customer_id = ?'; $args[] = $f['customer_id']; }
        if (($f['outlet_id'] ?? null) !== null) { $where[] = 's.outlet_id = ?'; $args[] = $f['outlet_id']; }
        if (($f['search'] ?? null) !== null) {
            $like = '%' . addcslashes($f['search'], '\\%_') . '%';
            $where[] = '(s.receipt_number LIKE ? OR c.name LIKE ?)';
            array_push($args, $like, $like);
        }
        if ($outletIds !== null) {
            if (!$outletIds) { return ['rows' => [], 'total' => 0, 'summary' => ['count' => 0, 'total' => '0.00']]; }
            $where[] = 's.outlet_id IN (' . implode(',', array_fill(0, count($outletIds), '?')) . ')';
            array_push($args, ...$outletIds);
        }
        $from = 'FROM sales s LEFT JOIN customers c ON c.id = s.customer_id AND c.business_id = s.business_id WHERE ' . implode(' AND ', $where);
        $cnt = $this->pdo->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN s.payment_status IN ('completed','pending') THEN s.total_amount ELSE 0 END),0), SUM(s.payment_status IN ('completed','pending')) $from");
        $cnt->execute($args);
        [$total, $sum, $counted] = $cnt->fetch(PDO::FETCH_NUM);
        $st = $this->pdo->prepare('SELECT ' . self::COLS . ",c.name AS customer_name $from ORDER BY s.created_at DESC, s.id DESC LIMIT $limit OFFSET $offset");
        $st->execute($args);
        return ['rows' => $st->fetchAll(), 'total' => (int) $total, 'summary' => ['count' => (int) $counted, 'total' => (string) $sum]];
    }

    /** @return array<string,mixed>|null */
    public function branding(string $bid): ?array
    {
        $st = $this->pdo->prepare('SELECT logo_data,phone,email,address,payment_details,footer_note,updated_at FROM business_branding WHERE business_id = ?');
        $st->execute([$bid]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,?string> $b */
    public function saveBranding(string $bid, array $b, string $actor): void
    {
        $this->pdo->prepare('INSERT INTO business_branding (business_id,logo_data,phone,email,address,payment_details,footer_note,updated_by) VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE logo_data = VALUES(logo_data), phone = VALUES(phone), email = VALUES(email), address = VALUES(address), payment_details = VALUES(payment_details), footer_note = VALUES(footer_note), updated_by = VALUES(updated_by)')
            ->execute([$bid, $b['logo_data'], $b['phone'], $b['email'], $b['address'], $b['payment_details'], $b['footer_note'], $actor]);
    }

    /** @return array<string,mixed>|null */
    public function businessInfo(string $bid): ?array
    {
        $st = $this->pdo->prepare('SELECT name,currency,tax_rate FROM businesses WHERE id = ?');
        $st->execute([$bid]);
        return $st->fetch() ?: null;
    }
}
