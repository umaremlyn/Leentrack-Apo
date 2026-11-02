<?php
declare(strict_types=1);

namespace LeenTrack\Core;

/** All "now" in the app goes through here so expiry logic is testable. Always UTC. */
final class Clock
{
    private static ?\DateTimeImmutable $fixed = null;

    public static function now(): \DateTimeImmutable
    {
        return self::$fixed ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function freeze(?\DateTimeImmutable $at): void
    {
        self::$fixed = $at;
    }

    /** MySQL DATETIME(3) string. */
    public static function sql(?\DateTimeImmutable $t = null): string
    {
        return ($t ?? self::now())->format('Y-m-d H:i:s.v');
    }

    public static function plus(string $interval): \DateTimeImmutable
    {
        return self::now()->modify($interval);
    }
}
