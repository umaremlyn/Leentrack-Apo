<?php
declare(strict_types=1);

namespace LeenTrack\Services\Sales;

use LeenTrack\Core\Money;

/**
 * Money rules for editing a sale — port of lib/sales/edit-math.ts (which mirrors SQL edit_sale, migration 017).
 * Exact integer cents. The server is the authority; clients use their own copy only for live preview.
 */
final class EditMath
{
    /**
     * Tax basis recovered from a stored sale.
     * @param array{subtotal:Money,discount:Money,tax:Money} $old
     * @return array{kind:'none'}|array{kind:'rate',pctHundredths:int}|array{kind:'ratio',num:int,den:int}
     */
    public static function taxBasis(array $old): array
    {
        $base = $old['subtotal']->sub($old['discount']);
        if ($old['tax']->minor <= 0 || $base->minor <= 0) {
            return ['kind' => 'none'];
        }
        // ratePct = r2(tax / base * 100)  ==> in hundredths of a percent: round(tax * 10000 / base)
        $pctH = (int) intdiv($old['tax']->minor * 20000 + $base->minor, $base->minor * 2);
        // clean percentage if base * rate / 100 reproduces the stored tax to within 1.1 cents
        $recomputed = $base->mulRatio($pctH, 10000);
        if (abs($recomputed->minor - $old['tax']->minor) <= 1) {
            return ['kind' => 'rate', 'pctHundredths' => $pctH];
        }
        return ['kind' => 'ratio', 'num' => $old['tax']->minor, 'den' => $base->minor];
    }

    /**
     * @param list<array{quantity:int,unit_price:Money}> $lines
     * @param array{subtotal:Money,discount:Money,tax:Money,total:Money} $old
     * @return array{subtotal:Money,discount:Money,tax:Money,total:Money,discountError:string,diff:Money,extraDue:Money,refundDue:Money}
     */
    public static function compute(array $lines, Money $discountInput, array $old, Money $paidSoFar): array
    {
        $subtotal = Money::zero();
        foreach ($lines as $l) {
            $subtotal = $subtotal->add($l['unit_price']->mulInt($l['quantity']));
        }
        $wanted = $discountInput->isNegative() ? Money::zero() : $discountInput;
        $discountError = ($wanted->gt(Money::zero()) && ($subtotal->minor <= 0 || $wanted->gte($subtotal)))
            ? 'The discount must be less than the total of the items.' : '';
        $discount = $discountError !== '' ? Money::zero() : $wanted;

        $basis = self::taxBasis($old);
        $taxable = $subtotal->sub($discount);
        $tax = match ($basis['kind']) {
            'none' => Money::zero(),
            'rate' => $taxable->mulRatio($basis['pctHundredths'], 10000),
            'ratio' => $taxable->mulRatio($basis['num'], $basis['den']),
        };
        $total = $taxable->add($tax);
        $diff = $total->sub($paidSoFar);
        return [
            'subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $total, 'discountError' => $discountError,
            'diff' => $diff,
            'extraDue' => $diff->minor > 0 ? $diff : Money::zero(),          // source: diff > 0.005 (i.e. at least one cent)
            'refundDue' => $diff->minor < 0 ? Money::ofMinor(-$diff->minor) : Money::zero(),
        ];
    }
}
