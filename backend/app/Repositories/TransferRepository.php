<?php
declare(strict_types=1);

namespace LeenTrack\Repositories;

use PDO;

final class TransferRepository
{
    private const COLS = 't.id,t.transfer_number,t.from_location_id,t.to_location_id,t.status,t.notes,t.created_by,t.shipped_at,t.received_at,t.cancelled_at,t.dispatch_operation_id,t.dispatch_hash,t.receive_operation_id,t.receive_hash,t.created_at,t.updated_at,fl.name AS from_name,tl.name AS to_name';
    private const JOIN = 'FROM transfers t LEFT JOIN locations fl ON fl.id = t.from_location_id LEFT JOIN locations tl ON tl.id = t.to_location_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $f status, location_id (either end), from, to (UTC), search
     * @param list<string>|null $outletIds visible when EITHER end is theirs
     * @return array{rows:list<array<string,mixed>>,total:int,byStatus:array<string,int>}
     */
    public function list(string $bid, array $f, ?array $outletIds, int $limit, int $offset): array
    {
        $w = 't.business_id = ?'; $a = [$bid];
        if ($outletIds !== null) {
            if (!$outletIds) { return ['rows' => [], 'total' => 0, 'byStatus' => []]; }
            $in = implode(',', array_fill(0, count($outletIds), '?'));
            $w .= " AND (t.from_location_id IN ($in) OR t.to_location_id IN ($in))";
            array_push($a, ...$outletIds, ...$outletIds);
        }
        if ($f['location_id'] !== null) { $w .= ' AND (t.from_location_id = ? OR t.to_location_id = ?)'; array_push($a, $f['location_id'], $f['location_id']); }
        if ($f['from'] !== null) { $w .= ' AND t.created_at >= ?'; $a[] = $f['from']; }
        if ($f['to'] !== null) { $w .= ' AND t.created_at < ?'; $a[] = $f['to']; }
        if ($f['search'] !== null) { $w .= ' AND t.transfer_number LIKE ?'; $a[] = '%' . addcslashes($f['search'], '\\%_') . '%'; }
        $st = $this->pdo->prepare("SELECT t.status, COUNT(*) FROM transfers t WHERE $w GROUP BY t.status");
        $st->execute($a);
        $by = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) { $by[(string) $r[0]] = (int) $r[1]; }
        $wf = $w; $af = $a;
        if ($f['status'] !== null) { $wf .= ' AND t.status = ?'; $af[] = $f['status']; }
        $total = $f['status'] !== null ? ($by[$f['status']] ?? 0) : array_sum($by);
        $st = $this->pdo->prepare('SELECT ' . self::COLS . ',(SELECT COUNT(*) FROM transfer_items i WHERE i.transfer_id = t.id) AS item_count ' . self::JOIN . " WHERE $wf ORDER BY t.created_at DESC, t.id LIMIT $limit OFFSET $offset");
        $st->execute($af);
        return ['rows' => $st->fetchAll(), 'total' => $total, 'byStatus' => $by];
    }

    /** @return array<string,mixed>|null */
    public function find(string $bid, string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::COLS . ' ' . self::JOIN . ' WHERE t.business_id = ? AND t.id = ?');
        $st->execute([$bid, $id]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function lock(string $bid, string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT t.id,t.transfer_number,t.from_location_id,t.to_location_id,t.status,t.dispatch_operation_id,t.dispatch_hash,t.receive_operation_id,t.receive_hash FROM transfers t WHERE t.business_id = ? AND t.id = ? FOR UPDATE');
        $st->execute([$bid, $id]);
        return $st->fetch() ?: null;
    }

    public function nextSequence(string $bid): int
    {
        $this->pdo->prepare("INSERT IGNORE INTO document_sequences (business_id, kind, next_value) VALUES (?, 'transfer', 1)")->execute([$bid]);
        $this->pdo->prepare("UPDATE document_sequences SET next_value = LAST_INSERT_ID(next_value) + 1 WHERE business_id = ? AND kind = 'transfer'")->execute([$bid]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $t */
    public function insert(array $t): void
    {
        $this->pdo->prepare('INSERT INTO transfers (id,business_id,transfer_number,from_location_id,to_location_id,notes,created_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$t['id'], $t['business_id'], $t['transfer_number'], $t['from'], $t['to'], $t['notes'], $t['created_by']]);
    }

    /** @param array<string,mixed> $f */
    public function update(string $bid, string $id, array $f): void
    {
        $allowed = ['to_location_id', 'from_location_id', 'status', 'notes', 'shipped_at', 'received_at', 'cancelled_at', 'dispatch_operation_id', 'dispatch_hash', 'receive_operation_id', 'receive_hash'];
        $sets = []; $vals = [];
        foreach ($f as $k => $v) { if (in_array($k, $allowed, true)) { $sets[] = "$k = ?"; $vals[] = $v; } }
        if (!$sets) { return; }
        $vals[] = $bid; $vals[] = $id;
        $this->pdo->prepare('UPDATE transfers SET ' . implode(',', $sets) . ' WHERE business_id = ? AND id = ?')->execute($vals);
    }

    /** @return list<array<string,mixed>> */
    public function items(string $bid, string $id, bool $lock = false): array
    {
        $st = $this->pdo->prepare('SELECT id,product_id,product_name,quantity_requested,quantity_shipped,quantity_received FROM transfer_items WHERE business_id = ? AND transfer_id = ? ORDER BY position, id' . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$bid, $id]);
        return $st->fetchAll();
    }

    /** @param list<array<string,mixed>> $items */
    public function replaceItems(string $bid, string $id, array $items): void
    {
        $this->pdo->prepare('DELETE FROM transfer_items WHERE business_id = ? AND transfer_id = ?')->execute([$bid, $id]);
        $st = $this->pdo->prepare('INSERT INTO transfer_items (id,transfer_id,business_id,product_id,product_name,quantity_requested,position) VALUES (?,?,?,?,?,?,?)');
        foreach ($items as $i => $it) { $st->execute([$it['id'], $id, $bid, $it['product_id'], $it['product_name'], $it['quantity'], $i]); }
    }

    public function setShipped(string $itemId, int $q): void
    {
        $this->pdo->prepare('UPDATE transfer_items SET quantity_shipped = ? WHERE id = ?')->execute([$q, $itemId]);
    }

    public function setReceived(string $itemId, int $q): void
    {
        $this->pdo->prepare('UPDATE transfer_items SET quantity_received = ? WHERE id = ?')->execute([$q, $itemId]);
    }
}
