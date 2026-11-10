<?php
declare(strict_types=1);

namespace LeenTrack\Repositories;

use PDO;

final class PurchaseRepository
{
    private const SUP = 's.id,s.name,s.contact_name,s.phone,s.email,s.address,s.notes,s.archived_at,s.created_at,s.updated_at';
    private const PO = 'o.id,o.supplier_id,o.outlet_id,o.order_number,o.status,o.total_cost,o.notes,o.ordered_at,o.received_at,o.created_by,o.created_at,o.updated_at,s.name AS supplier_name,l.name AS outlet_name';

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ================================================================================== suppliers
    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function suppliers(string $bid, bool $archived, ?string $search, int $limit, int $offset): array
    {
        $w = 's.business_id = ?';
        $a = [$bid];
        if (!$archived) { $w .= ' AND s.archived_at IS NULL'; }
        if ($search !== null) {
            $w .= ' AND (s.name LIKE ? OR s.contact_name LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)';
            $like = '%' . addcslashes($search, '\\%_') . '%';
            array_push($a, $like, $like, $like, $like);
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM suppliers s WHERE $w");
        $st->execute($a);
        $total = (int) $st->fetchColumn();
        $st = $this->pdo->prepare('SELECT ' . self::SUP . ",
            (SELECT COUNT(*) FROM purchase_orders o WHERE o.supplier_id = s.id) AS order_count,
            (SELECT COUNT(*) FROM purchase_orders o WHERE o.supplier_id = s.id AND o.status IN ('ordered','partially_received')) AS open_orders,
            COALESCE((SELECT SUM(i.quantity_received * i.unit_cost) FROM purchase_order_items i JOIN purchase_orders o ON o.id = i.purchase_order_id WHERE o.supplier_id = s.id),0)
              - COALESCE((SELECT SUM(p.amount) FROM supplier_payments p WHERE p.supplier_id = s.id),0) AS balance_owed
            FROM suppliers s WHERE $w ORDER BY s.name, s.id LIMIT $limit OFFSET $offset");
        $st->execute($a);
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function supplier(string $bid, string $id, bool $withArchived = true): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::SUP . ",
            (SELECT COUNT(*) FROM purchase_orders o WHERE o.supplier_id = s.id) AS order_count,
            (SELECT COUNT(*) FROM purchase_orders o WHERE o.supplier_id = s.id AND o.status IN ('ordered','partially_received')) AS open_orders,
            COALESCE((SELECT SUM(i.quantity_received * i.unit_cost) FROM purchase_order_items i JOIN purchase_orders o ON o.id = i.purchase_order_id WHERE o.supplier_id = s.id),0)
              - COALESCE((SELECT SUM(p.amount) FROM supplier_payments p WHERE p.supplier_id = s.id),0) AS balance_owed
            FROM suppliers s WHERE s.business_id = ? AND s.id = ?" . ($withArchived ? '' : ' AND s.archived_at IS NULL'));
        $st->execute([$bid, $id]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,mixed> $s */
    public function insertSupplier(array $s): void
    {
        $this->pdo->prepare('INSERT INTO suppliers (id,business_id,name,contact_name,phone,email,address,notes) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$s['id'], $s['business_id'], $s['name'], $s['contact_name'], $s['phone'], $s['email'], $s['address'], $s['notes']]);
    }

    /** @param array<string,mixed> $f */
    public function updateSupplier(string $bid, string $id, array $f): void
    {
        $allowed = ['name', 'contact_name', 'phone', 'email', 'address', 'notes'];
        $sets = []; $vals = [];
        foreach ($f as $k => $v) { if (in_array($k, $allowed, true)) { $sets[] = "$k = ?"; $vals[] = $v; } }
        if (!$sets) { return; }
        $vals[] = $bid; $vals[] = $id;
        $this->pdo->prepare('UPDATE suppliers SET ' . implode(',', $sets) . ' WHERE business_id = ? AND id = ?')->execute($vals);
    }

    public function archiveSupplier(string $bid, string $id): void
    {
        $this->pdo->prepare('UPDATE suppliers SET archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP(3)) WHERE business_id = ? AND id = ?')->execute([$bid, $id]);
    }

    public function restoreSupplier(string $bid, string $id): void
    {
        $this->pdo->prepare('UPDATE suppliers SET archived_at = NULL WHERE business_id = ? AND id = ?')->execute([$bid, $id]);
    }

    // ================================================================================== orders
    /**
     * @param array<string,mixed> $f status, supplier_id, search, from, to (UTC bounds), outlet_id
     * @param list<string>|null $outletIds
     * @return array{rows:list<array<string,mixed>>,total:int,byStatus:array<string,int>}
     */
    public function orders(string $bid, array $f, ?array $outletIds, int $limit, int $offset): array
    {
        $w = 'o.business_id = ?'; $a = [$bid];
        if ($outletIds !== null) {
            if (!$outletIds) { return ['rows' => [], 'total' => 0, 'byStatus' => []]; }
            $w .= ' AND o.outlet_id IN (' . implode(',', array_fill(0, count($outletIds), '?')) . ')';
            array_push($a, ...$outletIds);
        }
        foreach (['supplier_id', 'outlet_id'] as $k) { if ($f[$k] !== null) { $w .= " AND o.$k = ?"; $a[] = $f[$k]; } }
        if ($f['from'] !== null) { $w .= ' AND o.created_at >= ?'; $a[] = $f['from']; }
        if ($f['to'] !== null) { $w .= ' AND o.created_at < ?'; $a[] = $f['to']; }
        if ($f['search'] !== null) {
            $w .= ' AND (o.order_number LIKE ? OR s.name LIKE ?)';
            $like = '%' . addcslashes($f['search'], '\\%_') . '%';
            array_push($a, $like, $like);
        }
        $join = 'FROM purchase_orders o LEFT JOIN suppliers s ON s.id = o.supplier_id LEFT JOIN locations l ON l.id = o.outlet_id';
        $st = $this->pdo->prepare("SELECT o.status, COUNT(*) FROM purchase_orders o LEFT JOIN suppliers s ON s.id = o.supplier_id WHERE $w GROUP BY o.status");
        $st->execute($a);
        $by = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) { $by[(string) $r[0]] = (int) $r[1]; }
        $wf = $w; $af = $a;
        if ($f['status'] !== null) { $wf .= ' AND o.status = ?'; $af[] = $f['status']; }
        $total = $f['status'] !== null ? ($by[$f['status']] ?? 0) : array_sum($by);
        $st = $this->pdo->prepare('SELECT ' . self::PO . ",(SELECT COUNT(*) FROM purchase_order_items i WHERE i.purchase_order_id = o.id) AS item_count $join WHERE $wf ORDER BY o.created_at DESC, o.id LIMIT $limit OFFSET $offset");
        $st->execute($af);
        return ['rows' => $st->fetchAll(), 'total' => $total, 'byStatus' => $by];
    }

    /** @return array<string,mixed>|null */
    public function order(string $bid, string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::PO . ' FROM purchase_orders o LEFT JOIN suppliers s ON s.id = o.supplier_id LEFT JOIN locations l ON l.id = o.outlet_id WHERE o.business_id = ? AND o.id = ?');
        $st->execute([$bid, $id]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function lockOrder(string $bid, string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id,supplier_id,outlet_id,order_number,status,total_cost FROM purchase_orders WHERE business_id = ? AND id = ? FOR UPDATE');
        $st->execute([$bid, $id]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,mixed> $o */
    public function insertOrder(array $o): void
    {
        $this->pdo->prepare('INSERT INTO purchase_orders (id,business_id,supplier_id,outlet_id,order_number,status,total_cost,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$o['id'], $o['business_id'], $o['supplier_id'], $o['outlet_id'], $o['order_number'], 'draft', $o['total_cost'], $o['notes'], $o['created_by']]);
    }

    /** @param array<string,mixed> $f */
    public function updateOrder(string $bid, string $id, array $f): void
    {
        $allowed = ['supplier_id', 'outlet_id', 'status', 'total_cost', 'notes', 'ordered_at', 'received_at'];
        $sets = []; $vals = [];
        foreach ($f as $k => $v) { if (in_array($k, $allowed, true)) { $sets[] = "$k = ?"; $vals[] = $v; } }
        if (!$sets) { return; }
        $vals[] = $bid; $vals[] = $id;
        $this->pdo->prepare('UPDATE purchase_orders SET ' . implode(',', $sets) . ' WHERE business_id = ? AND id = ?')->execute($vals);
    }

    public function deleteOrder(string $bid, string $id): void
    {
        $this->pdo->prepare('DELETE FROM purchase_order_items WHERE business_id = ? AND purchase_order_id = ?')->execute([$bid, $id]);
        $this->pdo->prepare('DELETE FROM purchase_orders WHERE business_id = ? AND id = ?')->execute([$bid, $id]);
    }

    public function nextSequence(string $bid): int
    {
        $this->pdo->prepare("INSERT IGNORE INTO document_sequences (business_id, kind, next_value) VALUES (?, 'purchase', 1)")->execute([$bid]);
        $this->pdo->prepare("UPDATE document_sequences SET next_value = LAST_INSERT_ID(next_value) + 1 WHERE business_id = ? AND kind = 'purchase'")->execute([$bid]);
        return (int) $this->pdo->lastInsertId();
    }

    public function orderNumberTaken(string $bid, string $number): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM purchase_orders WHERE business_id = ? AND order_number = ?');
        $st->execute([$bid, $number]);
        return (bool) $st->fetchColumn();
    }

    // ================================================================================== items
    /** @return list<array<string,mixed>> */
    public function items(string $bid, string $orderId, bool $lock = false): array
    {
        $st = $this->pdo->prepare('SELECT id,product_id,product_name,quantity_ordered,quantity_received,unit_cost,line_total FROM purchase_order_items WHERE business_id = ? AND purchase_order_id = ? ORDER BY position, id' . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$bid, $orderId]);
        return $st->fetchAll();
    }

    /** @param list<array<string,mixed>> $items */
    public function replaceItems(string $bid, string $orderId, array $items): void
    {
        $this->pdo->prepare('DELETE FROM purchase_order_items WHERE business_id = ? AND purchase_order_id = ?')->execute([$bid, $orderId]);
        $st = $this->pdo->prepare('INSERT INTO purchase_order_items (id,purchase_order_id,business_id,product_id,product_name,quantity_ordered,unit_cost,line_total,position) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($items as $i => $it) {
            $st->execute([$it['id'], $orderId, $bid, $it['product_id'], $it['product_name'], $it['quantity'], $it['unit_cost'], $it['line_total'], $i]);
        }
    }

    public function setReceived(string $itemId, int $newTotal): void
    {
        $this->pdo->prepare('UPDATE purchase_order_items SET quantity_received = ? WHERE id = ?')->execute([$newTotal, $itemId]);
    }

    // ================================================================================== receipts
    /** @return array<string,mixed>|null */
    public function receiptByOperation(string $bid, string $op): ?array
    {
        $st = $this->pdo->prepare('SELECT id,purchase_order_id,request_hash,created_at FROM purchase_receipts WHERE business_id = ? AND client_operation_id = ?');
        $st->execute([$bid, $op]);
        return $st->fetch() ?: null;
    }

    /** @param list<array{item_id:string,quantity:int}> $lines */
    public function insertReceipt(string $id, string $bid, string $orderId, string $op, string $hash, ?string $user, array $lines): void
    {
        $this->pdo->prepare('INSERT INTO purchase_receipts (id,business_id,purchase_order_id,client_operation_id,request_hash,user_id) VALUES (?,?,?,?,?,?)')->execute([$id, $bid, $orderId, $op, $hash, $user]);
        $st = $this->pdo->prepare('INSERT INTO purchase_receipt_items (receipt_id,item_id,quantity) VALUES (?,?,?)');
        foreach ($lines as $l) { $st->execute([$id, $l['item_id'], $l['quantity']]); }
    }

    /** @return list<array<string,mixed>> receipts with their lines, oldest first */
    public function receipts(string $bid, string $orderId): array
    {
        $st = $this->pdo->prepare('SELECT r.id,r.user_id,r.created_at,ri.item_id,ri.quantity FROM purchase_receipts r JOIN purchase_receipt_items ri ON ri.receipt_id = r.id WHERE r.business_id = ? AND r.purchase_order_id = ? ORDER BY r.created_at, r.id, ri.item_id');
        $st->execute([$bid, $orderId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[$r['id']] ??= ['id' => $r['id'], 'user_id' => $r['user_id'], 'created_at' => $r['created_at'], 'items' => []];
            $out[$r['id']]['items'][] = ['item_id' => $r['item_id'], 'quantity' => (int) $r['quantity']];
        }
        return array_values($out);
    }

    // ================================================================================== misc
    /** @param list<string>|null $outletIds */
    public function pendingCount(string $bid, ?string $outletId, ?array $outletIds): int
    {
        $w = "business_id = ? AND status IN ('ordered','partially_received')"; $a = [$bid];
        if ($outletId !== null) { $w .= ' AND outlet_id = ?'; $a[] = $outletId; }
        if ($outletIds !== null) {
            if (!$outletIds) { return 0; }
            $w .= ' AND outlet_id IN (' . implode(',', array_fill(0, count($outletIds), '?')) . ')';
            array_push($a, ...$outletIds);
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE $w");
        $st->execute($a);
        return (int) $st->fetchColumn();
    }

    // ================================================================================== supplier payments
    /** Value of goods received on one order. */
    public function receivedValue(string $bid, string $orderId): string
    {
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(quantity_received * unit_cost),0) FROM purchase_order_items WHERE business_id = ? AND purchase_order_id = ?');
        $st->execute([$bid, $orderId]);
        return (string) $st->fetchColumn();
    }

    public function paidTotal(string $bid, string $orderId): string
    {
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE business_id = ? AND purchase_order_id = ?');
        $st->execute([$bid, $orderId]);
        return (string) $st->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function paymentByOperation(string $bid, string $op): ?array
    {
        $st = $this->pdo->prepare('SELECT id,purchase_order_id,request_hash FROM supplier_payments WHERE business_id = ? AND client_operation_id = ?');
        $st->execute([$bid, $op]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,mixed> $p */
    public function insertPayment(array $p): void
    {
        $this->pdo->prepare('INSERT INTO supplier_payments (id,business_id,supplier_id,purchase_order_id,amount,payment_method,reference,notes,client_operation_id,request_hash,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$p['id'], $p['business_id'], $p['supplier_id'], $p['purchase_order_id'], $p['amount'], $p['payment_method'], $p['reference'], $p['notes'], $p['client_operation_id'], $p['request_hash'], $p['created_by']]);
    }

    /** @return list<array<string,mixed>> oldest first */
    public function payments(string $bid, ?string $orderId, ?string $supplierId): array
    {
        $w = 'p.business_id = ?'; $a = [$bid];
        if ($orderId !== null) { $w .= ' AND p.purchase_order_id = ?'; $a[] = $orderId; }
        if ($supplierId !== null) { $w .= ' AND p.supplier_id = ?'; $a[] = $supplierId; }
        $st = $this->pdo->prepare("SELECT p.id,p.purchase_order_id,o.order_number,p.amount,p.payment_method,p.reference,p.notes,p.created_by,p.created_at FROM supplier_payments p JOIN purchase_orders o ON o.id = p.purchase_order_id WHERE $w ORDER BY p.created_at, p.id");
        $st->execute($a);
        return $st->fetchAll();
    }

    /** Orders that have received something, with what was received and paid. @return list<array<string,mixed>> */
    public function supplierLedger(string $bid, string $supplierId): array
    {
        $st = $this->pdo->prepare("SELECT o.id,o.order_number,o.status,o.created_at,
              COALESCE((SELECT SUM(i.quantity_received * i.unit_cost) FROM purchase_order_items i WHERE i.purchase_order_id = o.id),0) AS received_value,
              COALESCE((SELECT SUM(p.amount) FROM supplier_payments p WHERE p.purchase_order_id = o.id),0) AS paid
            FROM purchase_orders o WHERE o.business_id = ? AND o.supplier_id = ? AND o.status NOT IN ('draft','cancelled') ORDER BY o.created_at, o.id");
        $st->execute([$bid, $supplierId]);
        return $st->fetchAll();
    }
}
