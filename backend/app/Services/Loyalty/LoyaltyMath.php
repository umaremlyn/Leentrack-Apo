<?php
declare(strict_types=1);

namespace LeenTrack\Services\Loyalty;

use LeenTrack\Core\Money;

/**
 * Pure loyalty / checkout-discount maths. Ported from lib/loyalty/math.ts (discounts) and the SQL function loyalty_apply
 * (earning, migration 018). Exact integer-cent arithmetic; verified against golden vectors produced by running the original TS.
 */
final class LoyaltyMath
{
    /** A customer group's discount can never take the whole sale. */
    public const MAX_GROUP_PERCENT = '99';

    /**
     * @param array{subtotal:Money,manual:Money,groupPct:string,groupOn:bool,loyaltyOn:bool,customerPoints:int,pointValue:Money,pointsWanted:int} $i
     * @return array{groupPercent:string,groupDiscount:Money,manual:Money,manualError:string,maxPoints:int,pointsUsed:int,pointsValue:Money,discount:Money}
     */
    public static function checkoutDiscounts(array $i): array
    {
        $zero = Money::zero();
        $subtotal = $i['subtotal']->isNegative() ? $zero : $i['subtotal'];

        $pct = self::clampPercent($i['groupPct']);
        $groupDiscount = ($i['groupOn'] && $pct !== '0' && $subtotal->gt($zero)) ? $subtotal->percent($pct) : $zero;

        $manualWanted = $i['manual']->gt($zero) ? $i['manual'] : $zero;
        $tooMuch = $manualWanted->gt($zero) && $subtotal->gt($zero) && $manualWanted->add($groupDiscount)->gte($subtotal);
        $manualError = $tooMuch ? ($groupDiscount->gt($zero) ? 'The discounts together must be less than the cart total.' : 'Discount must be less than the cart total.') : '';
        $manual = $manualError !== '' ? $zero : $manualWanted;

        // there must always be something left to pay (at least 0.01)
        $room = $subtotal->sub(Money::ofMinor(1))->sub($groupDiscount)->sub($manual);
        $room = $room->isNegative() ? $zero : $room;

        $value = $i['pointValue']->gt($zero) ? $i['pointValue'] : $zero;
        $maxPoints = ($i['loyaltyOn'] && $value->gt($zero))
            ? max(0, min(max(0, $i['customerPoints']), $room->floorDiv($value)))
            : 0;
        $pointsUsed = max(0, min(max(0, $i['pointsWanted']), $maxPoints));
        $pointsValue = $value->mulInt($pointsUsed);

        return [
            'groupPercent' => $pct, 'groupDiscount' => $groupDiscount, 'manual' => $manual, 'manualError' => $manualError,
            'maxPoints' => $maxPoints, 'pointsUsed' => $pointsUsed, 'pointsValue' => $pointsValue,
            'discount' => $groupDiscount->add($manual)->add($pointsValue),
        ];
    }

    /** "7.5" -> "7.5"; below 0 -> "0"; above 99 -> "99". Percent strings have ≤2 decimals. */
    private static function clampPercent(string $p): string
    {
        $neg = str_starts_with($p, '-');
        if ($neg) { return '0'; }
        $cents = (int) round((float) $p * 100);          // ≤ 2 dp by construction (DECIMAL(5,2) / validated input)
        if ($cents <= 0) { return '0'; }
        if ($cents >= 9900) { return self::MAX_GROUP_PERCENT; }
        return rtrim(rtrim(sprintf('%d.%02d', intdiv($cents, 100), $cents % 100), '0'), '.');
    }

    /**
     * Earning rule of loyalty_apply(): add `money` (negative = a correction taking points back) to the carried-over progress,
     * convert whole multiples of $rate into points, never let the balance go below zero.
     * @return array{points:int,progress:Money,balance:int}
     */
    public static function earn(Money $progress, Money $money, Money $rate, int $balance): array
    {
        $total = $progress->add($money);
        $pts = $total->floorDiv($rate);
        if ($balance + $pts < 0) {
            $pts = -$balance;                       // points never go below zero
        }
        $newProgress = $total->sub($rate->mulInt($pts));
        if ($newProgress->isNegative()) {
            $newProgress = Money::zero();           // greatest(0, ...)
        }
        return ['points' => $pts, 'progress' => $newProgress, 'balance' => $balance + $pts];
    }
}
