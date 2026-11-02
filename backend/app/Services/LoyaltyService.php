<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Money;
use LeenTrack\Core\Request;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\CustomerRepository;
use LeenTrack\Services\Loyalty\LoyaltyMath;
use PDO;

/**
 * Points live ONLY here. No endpoint accepts a points value; they change as a side effect of money received (apply) or of a
 * checkout that spends them (redeem), each with an append-only ledger row. Runs inside the caller's transaction.
 */
final class LoyaltyService
{
    public const DEFAULT_RATE = '10000.00';
    public const DEFAULT_VALUE = '100.00';

    public function __construct(private readonly PDO $pdo, private readonly CustomerRepository $repo, private readonly AuditService $audit)
    {
    }

    /** @return array{enabled:bool,spend_per_point:Money,point_value:Money,is_default:bool} */
    public function settings(string $bid): array
    {
        $s = $this->repo->loyaltySettings($bid);
        return [
            'enabled' => $s === null ? true : (bool) $s['enabled'],
            'spend_per_point' => Money::parse($s['spend_per_point'] ?? self::DEFAULT_RATE),
            'point_value' => Money::parse($s['point_value'] ?? self::DEFAULT_VALUE),
            'is_default' => $s === null,
        ];
    }

    /** @return array<string,mixed> */
    public function showSettings(TenantContext $t): array
    {
        $t->require('loyalty.read');
        return $this->settingsView($this->settings($t->businessId));
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function saveSettings(TenantContext $t, array $body, Request $req): array
    {
        $t->require('loyalty.write');
        $v = new Validator($body);
        $v->only(['enabled', 'spend_per_point', 'point_value']);
        $enabled = $v->bool('enabled', true);
        $spend = $v->money('spend_per_point', true);
        $value = $v->money('point_value', true);
        if ($spend !== null && ($spend->lt(Money::parse('1')) || $spend->gt(Money::parse('100000000')))) {
            $v->addError('spend_per_point', 'Enter how much a customer must spend to earn 1 point (1 or more).');
        }
        if ($value !== null && $value->gt(Money::parse('1000000'))) { $v->addError('point_value', 'Enter what one point is worth (0 or more).'); }
        if ($spend !== null && $value !== null && $value->gt($spend)) { $v->addError('point_value', 'A point cannot be worth more than the amount spent to earn it.'); }
        $v->done();
        $before = $this->settings($t->businessId);
        $this->repo->saveLoyaltySettings($t->businessId, (bool) $enabled, $spend->toString(), $value->toString(), $t->userId);
        $this->audit->log('loyalty.settings', $req, $t->userId, $t->businessId, 'business', $t->businessId, ['spend_per_point' => $before['spend_per_point']->toString(), 'point_value' => $before['point_value']->toString(), 'enabled' => $before['enabled']], ['spend_per_point' => $spend->toString(), 'point_value' => $value->toString(), 'enabled' => $enabled]);
        return $this->settingsView($this->settings($t->businessId));
    }

    /**
     * Credit (or, with negative $money, take back) points for money RECEIVED. Mirrors loyalty_apply().
     * @return int points added (negative when corrected downwards); 0 when loyalty is off or nothing changed
     */
    public function apply(string $bid, string $customerId, ?string $saleId, Money $money, string $note, ?string $actor): int
    {
        $this->assertInTransaction();
        if ($money->isZero()) { return 0; }
        $s = $this->settings($bid);
        if (!$s['enabled']) { return 0; }
        $c = $this->repo->lockCustomer($bid, $customerId);
        if ($c === null) { return 0; }
        $r = LoyaltyMath::earn(Money::parse($this->repo->lastProgress($customerId)), $money, $s['spend_per_point'], (int) $c['loyalty_points']);
        $this->repo->setPoints($bid, $customerId, $r['balance']);
        $this->repo->insertLedger($bid, $customerId, $saleId, 'earn', $money->toString(), $r['points'], $r['progress']->toString(), $r['balance'], $s['spend_per_point']->toString(), $s['point_value']->toString(), $note, $actor);
        return $r['points'];
    }

    /**
     * Spend points at checkout. Validates exactly like complete_sale_with_points(): enabled, enough points (under lock),
     * and the sale's discount must already include the points' value. Returns the balance after spending.
     */
    public function redeem(string $bid, string $customerId, ?string $saleId, int $points, Money $saleDiscount, string $note, ?string $actor): int
    {
        $this->assertInTransaction();
        if ($points <= 0) { throw new \InvalidArgumentException('Points must be positive.'); }
        $s = $this->settings($bid);
        if (!$s['enabled']) { throw new ApiException('loyalty_disabled', 'Loyalty points are switched off for this business.', 422); }
        $c = $this->repo->lockCustomer($bid, $customerId) ?? throw new ApiException('customer_not_found', 'Customer does not belong to this business.', 422);
        if ((int) $c['loyalty_points'] < $points) { throw new ApiException('insufficient_points', "This customer only has {$c['loyalty_points']} point(s).", 422); }
        if ($s['point_value']->mulInt($points)->gt($saleDiscount)) { throw new ApiException('discount_mismatch', 'The discount on this sale does not include the points being used.', 422); }
        $after = (int) $c['loyalty_points'] - $points;
        $this->repo->setPoints($bid, $customerId, $after);
        $this->repo->insertLedger($bid, $customerId, $saleId, 'redeem', '0.00', -$points, $this->repo->lastProgress($customerId), $after, null, $s['point_value']->toString(), $note, $actor);
        return $after;
    }

    private function assertInTransaction(): void
    {
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Loyalty changes must run inside the sale transaction.'); }
    }

    /** @param array{enabled:bool,spend_per_point:Money,point_value:Money,is_default:bool} $s @return array<string,mixed> */
    private function settingsView(array $s): array
    {
        return ['enabled' => $s['enabled'], 'spend_per_point' => $s['spend_per_point']->toString(), 'point_value' => $s['point_value']->toString(), 'is_default' => $s['is_default']];
    }
}
