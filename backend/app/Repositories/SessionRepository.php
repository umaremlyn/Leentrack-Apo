<?php
declare(strict_types=1);

namespace LeenTrack\Repositories;

use LeenTrack\Core\Clock;
use PDO;

final class SessionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $s */
    public function insert(array $s): void
    {
        $st = $this->pdo->prepare('INSERT INTO auth_sessions (id,user_id,device_id,device_name,platform,access_hash,access_expires_at,refresh_hash,refresh_expires_at,ip,user_agent)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$s['id'], $s['user_id'], $s['device_id'], $s['device_name'], $s['platform'], $s['access_hash'], $s['access_expires_at'], $s['refresh_hash'], $s['refresh_expires_at'], $s['ip'], $s['user_agent']]);
    }

    /** @return array<string,mixed>|null live (unrevoked, unexpired) session by access-token hash */
    public function findLiveByAccess(string $hash): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM auth_sessions WHERE access_hash = ? AND revoked_at IS NULL AND access_expires_at > ?');
        $st->execute([$hash, Clock::sql()]);
        return $st->fetch() ?: null;
    }

    public function touch(string $id): void
    {
        $this->pdo->prepare('UPDATE auth_sessions SET last_used_at = ? WHERE id = ? AND last_used_at < ?')
            ->execute([Clock::sql(), $id, Clock::sql(Clock::now()->modify('-60 seconds'))]);
    }

    /** @return array<string,mixed>|null row locked FOR UPDATE; must be called inside a transaction */
    public function lockByRefresh(string $hash): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM auth_sessions WHERE refresh_hash = ? FOR UPDATE');
        $st->execute([$hash]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByPrevRefresh(string $hash): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM auth_sessions WHERE prev_refresh_hash = ?');
        $st->execute([$hash]);
        return $st->fetch() ?: null;
    }

    public function rotate(string $id, string $oldRefreshHash, string $newAccessHash, string $accessExp, string $newRefreshHash, string $refreshExp): void
    {
        $this->pdo->prepare('UPDATE auth_sessions SET prev_refresh_hash = ?, access_hash = ?, access_expires_at = ?, refresh_hash = ?, refresh_expires_at = ?, last_used_at = ? WHERE id = ?')
            ->execute([$oldRefreshHash, $newAccessHash, $accessExp, $newRefreshHash, $refreshExp, Clock::sql(), $id]);
    }

    public function revoke(string $id, string $reason): void
    {
        $this->pdo->prepare('UPDATE auth_sessions SET revoked_at = COALESCE(revoked_at, ?), revoked_reason = COALESCE(revoked_reason, ?) WHERE id = ?')->execute([Clock::sql(), $reason, $id]);
    }

    public function revokeAllForUser(string $userId, string $reason, ?string $exceptId = null): int
    {
        $st = $this->pdo->prepare('UPDATE auth_sessions SET revoked_at = ?, revoked_reason = ? WHERE user_id = ? AND revoked_at IS NULL AND (? IS NULL OR id <> ?)');
        $st->execute([Clock::sql(), $reason, $userId, $exceptId, $exceptId]);
        return $st->rowCount();
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(string $userId): array
    {
        $st = $this->pdo->prepare('SELECT id,device_name,platform,ip,created_at,last_used_at FROM auth_sessions WHERE user_id = ? AND revoked_at IS NULL AND refresh_expires_at > ? ORDER BY last_used_at DESC');
        $st->execute([$userId, Clock::sql()]);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findOwned(string $id, string $userId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM auth_sessions WHERE id = ? AND user_id = ?');
        $st->execute([$id, $userId]);
        return $st->fetch() ?: null;
    }
}
