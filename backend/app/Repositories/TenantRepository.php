<?php
declare(strict_types=1);

namespace LeenTrack\Repositories;

use PDO;

/** Businesses, memberships, locations. Every query that returns tenant data takes a business_id. */
final class TenantRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $b */
    public function insertBusiness(array $b): void
    {
        $this->pdo->prepare('INSERT INTO businesses (id,name,business_type,currency,tax_rate,owner_user_id) VALUES (?,?,?,?,?,?)')
            ->execute([$b['id'], $b['name'], $b['business_type'], $b['currency'], $b['tax_rate'] ?? '0.00', $b['owner_user_id']]);
    }

    /** @return array<string,mixed>|null */
    public function business(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM businesses WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,mixed> $fields name|business_type|currency|tax_rate only */
    public function updateBusiness(string $id, array $fields): void
    {
        $allowed = ['name', 'business_type', 'currency', 'tax_rate'];
        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) { throw new \LogicException("Column $k is not updatable here."); }
            $sets[] = "`$k` = ?";
            $vals[] = is_bool($v) ? (int) $v : $v;   // PDO would send false as '' and MySQL strict mode rejects it
        }
        if (!$sets) { return; }
        $vals[] = $id;
        $this->pdo->prepare('UPDATE businesses SET ' . implode(',', $sets) . ' WHERE id = ?')->execute($vals);
    }

    /** @param array<string,mixed> $m */
    public function insertMember(array $m): void
    {
        $this->pdo->prepare('INSERT INTO business_members (id,business_id,user_id,email,name,role,status,invited_by,invite_token_hash,invite_expires_at,joined_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$m['id'], $m['business_id'], $m['user_id'] ?? null, $m['email'], $m['name'] ?? null, $m['role'], $m['status'], $m['invited_by'] ?? null, $m['invite_token_hash'] ?? null, $m['invite_expires_at'] ?? null, $m['joined_at'] ?? null]);
    }

    /** @return array<string,mixed>|null ACTIVE membership of a user in a business (the only thing that grants access) */
    public function activeMembership(string $userId, string $businessId): ?array
    {
        $st = $this->pdo->prepare("SELECT m.id, m.role, m.business_id, b.owner_user_id, b.plan, b.plan_status, b.plan_expiry, b.name, b.currency
            FROM business_members m JOIN businesses b ON b.id = m.business_id AND b.deleted_at IS NULL
            WHERE m.user_id = ? AND m.business_id = ? AND m.status = 'active'");
        $st->execute([$userId, $businessId]);
        return $st->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function membershipsOf(string $userId): array
    {
        $st = $this->pdo->prepare("SELECT m.id AS member_id, m.role, b.id AS business_id, b.name, b.currency, b.plan, b.plan_status, b.plan_expiry
            FROM business_members m JOIN businesses b ON b.id = m.business_id AND b.deleted_at IS NULL
            WHERE m.user_id = ? AND m.status = 'active' ORDER BY b.created_at");
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /** @return list<string> location ids the member is restricted to (empty = unrestricted) */
    public function memberOutlets(string $memberId): array
    {
        $st = $this->pdo->prepare('SELECT location_id FROM member_outlets WHERE member_id = ?');
        $st->execute([$memberId]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array<string,mixed>> */
    public function members(string $businessId): array
    {
        $st = $this->pdo->prepare('SELECT id,user_id,email,name,role,status,joined_at,created_at FROM business_members WHERE business_id = ? ORDER BY created_at');
        $st->execute([$businessId]);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function member(string $businessId, string $memberId): ?array
    {
        $st = $this->pdo->prepare('SELECT id,business_id,user_id,email,name,role,status,joined_at,created_at FROM business_members WHERE business_id = ? AND id = ?');
        $st->execute([$businessId, $memberId]);
        return $st->fetch() ?: null;
    }

    public function countStaff(string $businessId): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM business_members WHERE business_id = ? AND role <> 'owner' AND status IN ('invited','active')");
        $st->execute([$businessId]);
        return (int) $st->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function memberByInviteHash(string $hash): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM business_members WHERE invite_token_hash = ? AND status = 'invited'");
        $st->execute([$hash]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,mixed> $fields name|role|status */
    public function updateMember(string $businessId, string $memberId, array $fields): void
    {
        $allowed = ['name', 'role', 'status'];
        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) { throw new \LogicException("Column $k is not updatable here."); }
            $sets[] = "`$k` = ?";
            $vals[] = is_bool($v) ? (int) $v : $v;   // PDO would send false as '' and MySQL strict mode rejects it
        }
        if (!$sets) { return; }
        array_push($vals, $businessId, $memberId);
        $this->pdo->prepare('UPDATE business_members SET ' . implode(',', $sets) . ' WHERE business_id = ? AND id = ?')->execute($vals);
    }

    public function setInvite(string $businessId, string $memberId, string $hash, string $expiresSql): void
    {
        $this->pdo->prepare("UPDATE business_members SET invite_token_hash = ?, invite_expires_at = ? WHERE business_id = ? AND id = ? AND status = 'invited'")
            ->execute([$hash, $expiresSql, $businessId, $memberId]);
    }

    /** Atomically activate an invited member. @return bool true if this call performed the activation */
    public function activateMember(string $memberId, string $userId, string $nowSql): bool
    {
        $st = $this->pdo->prepare("UPDATE business_members SET user_id = ?, status = 'active', joined_at = ?, invite_token_hash = NULL, invite_expires_at = NULL
            WHERE id = ? AND status = 'invited' AND invite_expires_at > ?");
        $st->execute([$userId, $nowSql, $memberId, $nowSql]);
        return $st->rowCount() === 1;
    }

    public function deleteMember(string $businessId, string $memberId): void
    {
        $this->pdo->prepare('DELETE FROM business_members WHERE business_id = ? AND id = ?')->execute([$businessId, $memberId]);
    }

    // ---- locations ----
    /** @param array<string,mixed> $l */
    public function insertLocation(array $l): void
    {
        $this->pdo->prepare('INSERT INTO locations (id,business_id,name,location_type,address,city,state,postal_code,country,phone,email,manager_name,is_active,is_default,timezone)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$l['id'], $l['business_id'], $l['name'], $l['location_type'] ?? 'store', $l['address'] ?? null, $l['city'] ?? null, $l['state'] ?? null, $l['postal_code'] ?? null, $l['country'] ?? 'Nigeria', $l['phone'] ?? null, $l['email'] ?? null, $l['manager_name'] ?? null, (int) ($l['is_active'] ?? 1), (int) ($l['is_default'] ?? 0), $l['timezone'] ?? 'Africa/Lagos']);
    }

    /** @return list<array<string,mixed>> */
    public function locations(string $businessId, ?array $onlyIds = null): array
    {
        $sql = 'SELECT * FROM locations WHERE business_id = ?';
        $args = [$businessId];
        if ($onlyIds !== null) {
            if (!$onlyIds) { return []; }
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
            array_push($args, ...$onlyIds);
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY is_default DESC, name');
        $st->execute($args);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function location(string $businessId, string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM locations WHERE business_id = ? AND id = ?');
        $st->execute([$businessId, $id]);
        return $st->fetch() ?: null;
    }

    public function countActiveLocations(string $businessId): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM locations WHERE business_id = ? AND is_active = 1');
        $st->execute([$businessId]);
        return (int) $st->fetchColumn();
    }

    /** @param array<string,mixed> $fields */
    public function updateLocation(string $businessId, string $id, array $fields): void
    {
        $allowed = ['name', 'location_type', 'address', 'city', 'state', 'postal_code', 'country', 'phone', 'email', 'manager_name', 'is_active', 'timezone'];
        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) { throw new \LogicException("Column $k is not updatable here."); }
            $sets[] = "`$k` = ?";
            $vals[] = is_bool($v) ? (int) $v : $v;   // PDO would send false as '' and MySQL strict mode rejects it
        }
        if (!$sets) { return; }
        array_push($vals, $businessId, $id);
        $this->pdo->prepare('UPDATE locations SET ' . implode(',', $sets) . ' WHERE business_id = ? AND id = ?')->execute($vals);
    }
}
