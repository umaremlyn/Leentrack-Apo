<?php
declare(strict_types=1);

namespace LeenTrack\Repositories;

use PDO;

final class BillingRepository
{
    private const COLS = 'id,business_id,user_id,provider,reference,plan,cycle,currency,amount_minor,status,provider_transaction_id,failure_reason,period_start,period_end,paid_at,settled_via,created_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $p */
    public function insertPayment(array $p): void
    {
        $this->pdo->prepare('INSERT INTO payments (id,business_id,user_id,reference,plan,cycle,currency,amount_minor) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$p['id'], $p['business_id'], $p['user_id'], $p['reference'], $p['plan'], $p['cycle'], $p['currency'], $p['amount_minor']]);
    }

    /** @return array<string,mixed>|null */
    public function payment(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::COLS . ' FROM payments WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function byReference(string $reference): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::COLS . " FROM payments WHERE provider = 'paystack' AND reference = ?");
        $st->execute([$reference]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function lockByReference(string $reference): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::COLS . " FROM payments WHERE provider = 'paystack' AND reference = ? FOR UPDATE");
        $st->execute([$reference]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,mixed> $f */
    public function updatePayment(string $id, array $f): void
    {
        $allowed = ['status', 'provider_transaction_id', 'failure_reason', 'period_start', 'period_end', 'paid_at', 'settled_via'];
        $sets = []; $vals = [];
        foreach ($f as $k => $v) { if (in_array($k, $allowed, true)) { $sets[] = "$k = ?"; $vals[] = $v; } }
        if (!$sets) { return; }
        $vals[] = $id;
        $this->pdo->prepare('UPDATE payments SET ' . implode(',', $sets) . ' WHERE id = ?')->execute($vals);
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function history(string $bid, int $limit, int $offset): array
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM payments WHERE business_id = ?');
        $st->execute([$bid]);
        $total = (int) $st->fetchColumn();
        $st = $this->pdo->prepare('SELECT ' . self::COLS . " FROM payments WHERE business_id = ? ORDER BY created_at DESC, id LIMIT $limit OFFSET $offset");
        $st->execute([$bid]);
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function lastPayment(string $bid): ?array
    {
        $st = $this->pdo->prepare('SELECT ' . self::COLS . " FROM payments WHERE business_id = ? AND status = 'success' ORDER BY paid_at DESC, id LIMIT 1");
        $st->execute([$bid]);
        return $st->fetch() ?: null;
    }

    public function pendingCountSince(string $bid, string $sinceSql): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM payments WHERE business_id = ? AND status = 'pending' AND created_at >= ?");
        $st->execute([$bid, $sinceSql]);
        return (int) $st->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function lockBusiness(string $bid): ?array
    {
        $st = $this->pdo->prepare('SELECT id,name,owner_user_id,plan,plan_status,plan_expiry,currency FROM businesses WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
        $st->execute([$bid]);
        return $st->fetch() ?: null;
    }

    public function activatePlan(string $bid, string $plan, string $expirySql, ?string $customerCode): void
    {
        $this->pdo->prepare("UPDATE businesses SET plan = ?, plan_status = 'active', plan_expiry = ?, paystack_customer_id = COALESCE(?, paystack_customer_id) WHERE id = ?")->execute([$plan, $expirySql, $customerCode, $bid]);
    }

    /** @return list<array{id:string,name:string,plan:string}> businesses past expiry (to be moved to free) */
    public function expired(string $nowSql): array
    {
        $st = $this->pdo->prepare("SELECT id,name,plan FROM businesses WHERE deleted_at IS NULL AND plan <> 'free' AND plan_expiry IS NOT NULL AND plan_expiry < ? ORDER BY plan_expiry LIMIT 1000");
        $st->execute([$nowSql]);
        return $st->fetchAll();
    }

    public function markExpired(string $bid, string $nowSql): bool
    {
        $st = $this->pdo->prepare("UPDATE businesses SET plan = 'free', plan_status = 'expired' WHERE id = ? AND plan <> 'free' AND plan_expiry IS NOT NULL AND plan_expiry < ?");
        $st->execute([$bid, $nowSql]);
        return $st->rowCount() === 1;
    }

    /** @return bool true when this delivery has NOT been seen before */
    public function recordEvent(string $hash, string $type, ?string $reference, string $outcome): bool
    {
        $st = $this->pdo->prepare("INSERT IGNORE INTO webhook_events (provider, payload_hash, event_type, reference, outcome) VALUES ('paystack',?,?,?,?)");
        $st->execute([$hash, substr($type, 0, 60), $reference, $outcome]);
        return $st->rowCount() === 1;
    }

    public function setEventOutcome(string $hash, string $outcome): void
    {
        $this->pdo->prepare("UPDATE webhook_events SET outcome = ? WHERE provider = 'paystack' AND payload_hash = ?")->execute([$outcome, $hash]);
    }

    public function userEmail(string $userId): ?string
    {
        $st = $this->pdo->prepare('SELECT email FROM users WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$userId]);
        return $st->fetchColumn() ?: null;
    }

    public function deleteOldRetained(string $olderThanSql): int
    {
        $st = $this->pdo->prepare('DELETE FROM payments WHERE business_id IS NULL AND created_at < ?');
        $st->execute([$olderThanSql]);
        return $st->rowCount();
    }
}
