<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\Clock;
use PDO;

/** Cron-driven cleanup (bin/cron.php housekeeping). Deletes only expired/consumed security artefacts — never business data. */
final class HousekeepingService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,int> rows removed per table */
    public function run(): array
    {
        $now = Clock::now();
        $day = Clock::sql($now->modify('-1 day'));
        $week = Clock::sql($now->modify('-7 days'));
        $month = Clock::sql($now->modify('-30 days'));
        $out = [];
        $del = function (string $key, string $sql, array $args) use (&$out) {
            $st = $this->pdo->prepare($sql);
            $st->execute($args);
            $out[$key] = $st->rowCount();
        };
        $del('rate_limits', 'DELETE FROM rate_limits WHERE window_start < ?', [$now->getTimestamp() - 86400]);
        $del('user_tokens', 'DELETE FROM user_tokens WHERE expires_at < ? OR used_at < ?', [$week, $week]);
        $del('auth_sessions', 'DELETE FROM auth_sessions WHERE (revoked_at IS NOT NULL AND revoked_at < ?) OR refresh_expires_at < ?', [$month, $month]);
        $del('sync_operations', "DELETE FROM sync_operations WHERE status IN ('applied','discarded') AND processed_at < ?", [Clock::sql($now->modify('-90 days'))]);       // beyond the 60-day acceptance window nothing can replay these
        return $out;
    }
}
