<?php
declare(strict_types=1);

namespace LeenTrack\Core;

use PDO;

/** Fixed-window limiter stored in MySQL (works on shared hosting: no Redis/APCu needed). */
final class RateLimiter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{allowed:bool,remaining:int,retry_after:int} */
    public function hit(string $key, int $maxHits, int $windowSeconds, ?int $now = null): array
    {
        $now ??= time();
        $window = intdiv($now, $windowSeconds) * $windowSeconds;
        $hash = hash('sha256', $key);
        $st = $this->pdo->prepare('INSERT INTO rate_limits (key_hash, window_start, hits) VALUES (?,?,1)
            ON DUPLICATE KEY UPDATE hits = hits + 1');
        $st->execute([$hash, $window]);
        $q = $this->pdo->prepare('SELECT hits FROM rate_limits WHERE key_hash = ? AND window_start = ?');
        $q->execute([$hash, $window]);
        $hits = (int) $q->fetchColumn();
        return [
            'allowed' => $hits <= $maxHits,
            'remaining' => max(0, $maxHits - $hits),
            'retry_after' => $window + $windowSeconds - $now,
        ];
    }

    /** Housekeeping, called from cron. */
    public function purge(int $olderThanSeconds = 86400): int
    {
        $st = $this->pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?');
        $st->execute([time() - $olderThanSeconds]);
        return $st->rowCount();
    }
}
