<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\Request;
use PDO;

final class AuditService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     * Never put passwords, tokens or hashes in $before/$after.
     */
    public function log(string $action, ?Request $req, ?string $actorUserId, ?string $businessId = null, ?string $entityType = null, ?string $entityId = null, ?array $before = null, ?array $after = null): void
    {
        $st = $this->pdo->prepare('INSERT INTO audit_logs (business_id, actor_user_id, action, entity_type, entity_id, before_json, after_json, ip, user_agent, request_id)
            VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute([
            $businessId, $actorUserId, $action, $entityType, $entityId,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            $req?->ip, $req ? substr((string) $req->header('user-agent', ''), 0, 255) : null, $req?->id,
        ]);
    }
}
