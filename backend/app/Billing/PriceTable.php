<?php
declare(strict_types=1);

namespace LeenTrack\Billing;

/**
 * What a subscription costs. Port of billing-client.tsx (MONTHLY_PRICE, CYCLES, calcTotal, toSmallestUnit) in exact integer arithmetic;
 * BillingGoldenTest compares all 48 plan/currency/cycle amounts with the original source's own numbers.
 * The server is the only place a price is decided: the client can ask for a plan, never for an amount.
 */
final class PriceTable
{
    /** monthly base price in MINOR units (kobo, pesewas, cents …) */
    private const MONTHLY_MINOR = [
        'growth' => ['NGN' => 350000, 'GHS' => 3800, 'KES' => 33000, 'ZAR' => 4600, 'EGP' => 12000, 'USD' => 250, 'GBP' => 200, 'EUR' => 250],
        'business' => ['NGN' => 900000, 'GHS' => 9800, 'KES' => 85000, 'ZAR' => 11800, 'EGP' => 31000, 'USD' => 650, 'GBP' => 500, 'EUR' => 600],
    ];
    /** months, discount (percent), days of access granted */
    public const CYCLES = ['monthly' => [1, 0, 30], 'quarterly' => [3, 10, 92], 'yearly' => [12, 20, 366]];
    public const PLANS = ['growth', 'business'];

    /** @return list<string> */
    public static function currencies(): array
    {
        return array_keys(self::MONTHLY_MINOR['growth']);
    }

    public static function monthly(string $plan, string $currency): int
    {
        return self::MONTHLY_MINOR[$plan][$currency] ?? throw new \InvalidArgumentException('Unknown plan or currency.');
    }

    /** base × months × (1 − discount), rounded half-up to a whole minor unit */
    public static function amountMinor(string $plan, string $cycle, string $currency): int
    {
        [$months, $disc] = self::CYCLES[$cycle] ?? throw new \InvalidArgumentException('Unknown cycle.');
        return intdiv(self::monthly($plan, $currency) * $months * (100 - $disc) + 50, 100);
    }

    public static function days(string $cycle): int
    {
        return (self::CYCLES[$cycle] ?? throw new \InvalidArgumentException('Unknown cycle.'))[2];
    }
}
