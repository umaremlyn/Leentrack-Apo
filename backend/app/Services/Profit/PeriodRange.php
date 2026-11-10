<?php
declare(strict_types=1);

namespace LeenTrack\Services\Profit;

use LeenTrack\Core\ApiException;

/** Reporting periods in the BUSINESS time zone as [from, to) — port of periodRange()/localDate() in lib/profit/compute.ts. */
final class PeriodRange
{
    public const KEYS = ['today', 'week', 'month', 'lastMonth'];

    /** @return array{from:\DateTimeImmutable,to:\DateTimeImmutable,fromDate:string,toDate:string} from/to in UTC; dates are the local calendar dates used for expenses */
    public static function of(string $key, \DateTimeImmutable $nowUtc, \DateTimeZone $tz): array
    {
        $now = $nowUtc->setTimezone($tz);
        $today = $now->setTime(0, 0, 0);
        $tomorrow = $today->modify('+1 day');
        [$from, $to] = match ($key) {
            'today' => [$today, $tomorrow],
            'week' => [$today->modify('-6 days'), $tomorrow],
            'month' => [$today->modify('first day of this month'), $tomorrow],
            'lastMonth' => [$today->modify('first day of last month'), $today->modify('first day of this month')],
            default => throw ApiException::validation(['period' => ['Must be one of: ' . implode(', ', self::KEYS) . ', custom.']]),
        };
        return self::wrap($from, $to);
    }

    /** a custom range of whole local days: first day .. last day INCLUSIVE */
    public static function custom(string $fromDate, string $toDate, \DateTimeZone $tz): array
    {
        $f = \DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate, $tz);
        $t = \DateTimeImmutable::createFromFormat('!Y-m-d', $toDate, $tz);
        if (!$f || $f->format('Y-m-d') !== $fromDate) { throw ApiException::validation(['from' => ['Must be a date as YYYY-MM-DD.']]); }
        if (!$t || $t->format('Y-m-d') !== $toDate) { throw ApiException::validation(['to' => ['Must be a date as YYYY-MM-DD.']]); }
        if ($t < $f) { throw ApiException::validation(['to' => ['Must not be before the start date.']]); }
        if ($t->diff($f)->days > 400) { throw ApiException::validation(['to' => ['The range may not be longer than 400 days.']]); }
        return self::wrap($f, $t->modify('+1 day'));
    }

    /** @return array{from:\DateTimeImmutable,to:\DateTimeImmutable,fromDate:string,toDate:string} */
    private static function wrap(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $utc = new \DateTimeZone('UTC');
        return ['from' => $from->setTimezone($utc), 'to' => $to->setTimezone($utc), 'fromDate' => $from->format('Y-m-d'), 'toDate' => $to->format('Y-m-d')];
    }
}
