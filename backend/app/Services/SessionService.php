<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Db;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Http\AuthContext;
use LeenTrack\Repositories\SessionRepository;
use LeenTrack\Repositories\UserRepository;
use LeenTrack\Security\Tokens;
use PDO;

/** Opaque access (15 min) + rotating refresh (30 d) tokens. Reuse of a rotated refresh token revokes the session. */
final class SessionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SessionRepository $sessions,
        private readonly UserRepository $users,
        private readonly AuditService $audit,
        private readonly int $accessTtl,
        private readonly int $refreshTtl,
    ) {
    }

    /**
     * @param array{id?:?string,name?:?string,platform?:?string} $device
     * @return array{access_token:string,refresh_token:string,expires_in:int,session_id:string}
     */
    public function issue(string $userId, array $device, ?Request $req): array
    {
        $access = Tokens::generate('lta_');
        $refresh = Tokens::generate('ltr_');
        $id = Uuid::v4();
        $this->sessions->insert([
            'id' => $id, 'user_id' => $userId,
            'device_id' => $device['id'] ?? null, 'device_name' => $device['name'] ?? null, 'platform' => $device['platform'] ?? null,
            'access_hash' => Tokens::hash($access), 'access_expires_at' => Clock::sql(Clock::plus("+{$this->accessTtl} seconds")),
            'refresh_hash' => Tokens::hash($refresh), 'refresh_expires_at' => Clock::sql(Clock::plus("+{$this->refreshTtl} seconds")),
            'ip' => $req?->ip, 'user_agent' => $req ? substr((string) $req->header('user-agent', ''), 0, 255) : null,
        ]);
        return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => $this->accessTtl, 'session_id' => $id];
    }

    /** Resolves a bearer token to an AuthContext, or null. Disabled / deleted users are rejected here, on every request. */
    public function authenticate(string $accessToken): ?AuthContext
    {
        if (!str_starts_with($accessToken, 'lta_')) {
            return null;
        }
        $s = $this->sessions->findLiveByAccess(Tokens::hash($accessToken));
        if ($s === null) {
            return null;
        }
        $u = $this->users->findById((string) $s['user_id']);
        if ($u === null || $u['status'] !== 'active') {
            return null;
        }
        $this->sessions->touch((string) $s['id']);
        return new AuthContext((string) $u['id'], (string) $s['id'], (string) $u['email']);
    }

    /** @return array{access_token:string,refresh_token:string,expires_in:int,session_id:string} */
    public function refresh(string $refreshToken, ?Request $req): array
    {
        if (!str_starts_with($refreshToken, 'ltr_')) {
            throw ApiException::unauthenticated('Invalid refresh token.');
        }
        $hash = Tokens::hash($refreshToken);
        $theft = null;
        $result = Db::transaction($this->pdo, function () use ($hash, &$theft, $req) {
            $s = $this->sessions->lockByRefresh($hash);
            if ($s === null) {
                $old = $this->sessions->findByPrevRefresh($hash);
                if ($old !== null) {
                    $this->sessions->revoke((string) $old['id'], 'refresh_reuse');
                    $theft = $old;
                }
                return null;
            }
            $now = Clock::sql();
            if ($s['revoked_at'] !== null || $s['refresh_expires_at'] <= $now) {
                return null;
            }
            $u = $this->users->findById((string) $s['user_id']);
            if ($u === null || $u['status'] !== 'active') {
                return null;
            }
            $access = Tokens::generate('lta_');
            $refresh = Tokens::generate('ltr_');
            $this->sessions->rotate((string) $s['id'], $hash, Tokens::hash($access), Clock::sql(Clock::plus("+{$this->accessTtl} seconds")), Tokens::hash($refresh), Clock::sql(Clock::plus("+{$this->refreshTtl} seconds")));
            return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => $this->accessTtl, 'session_id' => (string) $s['id']];
        });
        if ($theft !== null) {
            // Outside the transaction so the revocation is committed even though we reject the request.
            $this->audit->log('auth.refresh_reuse_detected', $req, (string) $theft['user_id'], null, 'session', (string) $theft['id']);
        }
        if ($result === null) {
            throw ApiException::unauthenticated('Invalid or expired refresh token.');
        }
        return $result;
    }

    public function logout(string $sessionId): void
    {
        $this->sessions->revoke($sessionId, 'logout');
    }

    public function logoutAll(string $userId, string $reason = 'logout_all', ?string $exceptSessionId = null): int
    {
        return $this->sessions->revokeAllForUser($userId, $reason, $exceptSessionId);
    }
}
