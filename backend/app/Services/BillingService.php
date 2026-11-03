<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Billing\PaymentGateway;
use LeenTrack\Billing\PriceTable;
use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Db;
use LeenTrack\Core\Money;
use LeenTrack\Core\Pager;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Mail\Mailer;
use LeenTrack\Repositories\BillingRepository;
use PDO;

/**
 * Subscriptions. The rule that closes audit S-04/S-06: a plan is granted ONLY when a payment row that the SERVER created (with the server's
 * price) is confirmed by the provider for exactly that amount and currency. Neither the browser nor the webhook body can name a plan,
 * an amount, a business or an expiry. Confirmation can arrive by the owner's `verify` call (we ask the provider) or by a signed webhook;
 * both go through the same row-locked, idempotent `settle`.
 */
final class BillingService
{
    private const SYMBOLS = ['NGN' => '₦', 'GHS' => 'GH₵', 'KES' => 'KSh', 'ZAR' => 'R', 'EGP' => 'EGP ', 'USD' => '$', 'GBP' => '£', 'EUR' => '€'];
    private const RANK = ['free' => 0, 'growth' => 1, 'business' => 2];

    public function __construct(
        private readonly PDO $pdo,
        private readonly BillingRepository $repo,
        private readonly PaymentGateway $gateway,
        private readonly Mailer $mailer,
        private readonly AuditService $audit,
        private readonly string $callbackUrl = '',
        private readonly int $retentionYears = 7,
    ) {
    }

    // ================================================================================================ read
    /** @return array<string,mixed> */
    public function plans(TenantContext $t, array $q): array
    {
        $t->require('billing.manage');
        if ($extra = array_diff(array_keys($q), ['currency'])) { throw ApiException::validation([(string) reset($extra) => ['Unknown filter.']]); }
        $cur = isset($q['currency']) ? (is_string($q['currency']) && in_array(strtoupper($q['currency']), PriceTable::currencies(), true) ? strtoupper($q['currency']) : throw ApiException::validation(['currency' => ['Supported: ' . implode(', ', PriceTable::currencies()) . '.']])) : 'NGN';
        $plans = [];
        foreach (PriceTable::PLANS as $plan) {
            $cycles = [];
            foreach (PriceTable::CYCLES as $cycle => [$months, $disc, $days]) {
                $total = PriceTable::amountMinor($plan, $cycle, $cur);
                $full = PriceTable::monthly($plan, $cur) * $months;
                $cycles[] = ['cycle' => $cycle, 'months' => $months, 'discount_percent' => $disc, 'access_days' => $days, 'total' => Money::ofMinor($total)->toString(), 'amount_minor' => $total, 'saving' => Money::ofMinor($full - $total)->toString()];
            }
            $plans[] = ['plan' => $plan, 'monthly' => Money::ofMinor(PriceTable::monthly($plan, $cur))->toString(), 'cycles' => $cycles];
        }
        $limits = [];
        foreach (['free', 'growth', 'business'] as $p) {
            foreach (['products', 'categories', 'outlets', 'customers', 'sales', 'staff'] as $res) { $l = PlanCatalog::limit($p, $res); $limits[$p][$res] = $l >= PlanCatalog::INF ? null : $l; }
        }
        return ['currency' => $cur, 'symbol' => self::SYMBOLS[$cur], 'currencies' => PriceTable::currencies(), 'plans' => $plans, 'limits' => $limits, 'online_payments' => $this->gateway->isConfigured()];
    }

    /** @return array<string,mixed> */
    public function summary(TenantContext $t): array
    {
        $t->require('billing.manage');
        $b = $this->business($t->businessId);
        $eff = PlanCatalog::effective($b);
        $left = ($eff !== 'free' && $b['plan_expiry'] !== null) ? max(0, (int) ceil((strtotime($b['plan_expiry'] . ' UTC') - Clock::now()->getTimestamp()) / 86400)) : null;
        $last = $this->repo->lastPayment($t->businessId);
        return ['plan' => $b['plan'], 'plan_status' => $b['plan_status'], 'effective_plan' => $eff, 'plan_expiry' => $b['plan_expiry'] === null ? null : $this->iso($b['plan_expiry']), 'days_left' => $left,
            'online_payments' => $this->gateway->isConfigured(), 'last_payment' => $last === null ? null : $this->view($last)];
    }

    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,mixed>} */
    public function history(TenantContext $t, array $q): array
    {
        $t->require('billing.manage');
        if ($extra = array_diff(array_keys($q), ['page', 'per_page'])) { throw ApiException::validation([(string) reset($extra) => ['Unknown filter.']]); }
        $pg = Pager::fromQuery($q, 25);
        $r = $this->repo->history($t->businessId, $pg->perPage, $pg->offset());
        return ['rows' => array_map($this->view(...), $r['rows']), 'meta' => $pg->meta($r['total'])];
    }

    // ================================================================================================ checkout
    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function checkout(TenantContext $t, array $body, Request $req): array
    {
        $t->require('billing.manage');
        $v = new Validator($body);
        $v->only(['plan', 'cycle', 'currency']);
        $plan = $v->enum('plan', PriceTable::PLANS);
        $cycle = $v->enum('cycle', array_keys(PriceTable::CYCLES));
        $currency = $v->has('currency') ? strtoupper((string) $v->string('currency', 3, 3)) : null;
        $v->done();
        $b = $this->business($t->businessId);
        $currency ??= in_array($b['currency'], PriceTable::currencies(), true) ? $b['currency'] : 'NGN';
        if (!in_array($currency, PriceTable::currencies(), true)) { throw ApiException::validation(['currency' => ['Supported: ' . implode(', ', PriceTable::currencies()) . '.']]); }
        if (!$this->gateway->isConfigured()) { throw new ApiException('billing_not_configured', 'Online payments are not set up yet.', 503); }
        $eff = PlanCatalog::effective($b);
        if (self::RANK[$plan] < self::RANK[$eff]) {
            throw new ApiException('downgrade_not_allowed', 'Your ' . ucfirst($eff) . ' plan is active until ' . substr((string) $b['plan_expiry'], 0, 10) . '. You can choose ' . ucfirst((string) $plan) . ' after it ends.', 409);
        }
        if ($this->repo->pendingCountSince($t->businessId, Clock::sql(Clock::now()->modify('-1 hour'))) >= 5) {
            throw new ApiException('too_many_pending', 'You already have several unfinished payments. Finish or wait for them before starting another.', 429);
        }
        $email = $this->repo->userEmail($t->userId) ?? throw ApiException::unauthenticated();
        $amount = PriceTable::amountMinor((string) $plan, (string) $cycle, $currency);
        $id = Uuid::v4();
        $reference = 'LT-' . strtoupper(bin2hex(random_bytes(10)));
        $this->repo->insertPayment(['id' => $id, 'business_id' => $t->businessId, 'user_id' => $t->userId, 'reference' => $reference, 'plan' => $plan, 'cycle' => $cycle, 'currency' => $currency, 'amount_minor' => $amount]);
        try {
            $init = $this->gateway->initialize(['email' => $email, 'amount_minor' => $amount, 'currency' => $currency, 'reference' => $reference,
                'callback_url' => $this->callbackUrl !== '' ? $this->callbackUrl . '?reference=' . $reference : null, 'metadata' => ['business_id' => $t->businessId, 'payment_id' => $id, 'plan' => (string) $plan, 'cycle' => (string) $cycle]]);
        } catch (ApiException $e) {
            $this->repo->updatePayment($id, ['status' => 'failed', 'failure_reason' => 'could_not_start']);
            throw $e;
        }
        $this->audit->log('billing.checkout', $req, $t->userId, $t->businessId, 'payment', $id, null, ['plan' => $plan, 'cycle' => $cycle, 'amount_minor' => $amount, 'currency' => $currency]);
        return ['payment' => $this->view($this->repo->payment($id) ?? []), 'authorization_url' => $init['authorization_url'], 'access_code' => $init['access_code'],
            'note' => $plan === $eff ? 'This renews your current plan: the new period is added after your current expiry.' : 'The new plan starts when the payment is confirmed.'];
    }

    /**
     * The owner (or the app after the payment screen closes) asks us to check a reference. WE ask the provider; the request can say nothing about amount or plan.
     * @param array<string,mixed> $body @return array<string,mixed>
     */
    public function verify(TenantContext $t, array $body, Request $req): array
    {
        $t->require('billing.manage');
        $v = new Validator($body);
        $v->only(['reference']);
        $ref = $v->string('reference', 8, 64);
        if ($ref !== null && !preg_match('/^[A-Za-z0-9_-]+$/', $ref)) { $v->addError('reference', 'Not a valid reference.'); }
        $v->done();
        $p = $this->repo->byReference((string) $ref);
        if ($p === null || $p['business_id'] !== $t->businessId) { throw ApiException::notFound('Payment not found.'); }
        if (in_array($p['status'], ['success', 'refunded'], true)) { return $this->verifyResult($p, $t->businessId); }
        $remote = $this->gateway->verify((string) $ref);                                           // 502 if the provider cannot be reached: nothing changes
        $after = [];
        Db::transaction($this->pdo, function () use ($p, $remote, &$after, $req) { $after = $this->settle((string) $p['reference'], $remote, 'verify', $req); });
        foreach ($after as $f) { $f(); }
        return $this->verifyResult($this->repo->byReference((string) $ref) ?? $p, $t->businessId);
    }

    // ================================================================================================ webhook
    /** @return array{status:int,body:array<string,mixed>} */
    public function webhook(string $raw, ?string $signature): array
    {
        if (!$this->gateway->isConfigured()) { return ['status' => 503, 'body' => ['error' => 'not_configured']]; }
        if (!$this->gateway->signatureValid($raw, $signature)) { return ['status' => 401, 'body' => ['error' => 'invalid_signature']]; }
        $evt = json_decode($raw, true);
        if (!is_array($evt) || !is_string($evt['event'] ?? null) || !is_array($evt['data'] ?? null)) { return ['status' => 400, 'body' => ['error' => 'invalid_payload']]; }
        $hash = hash('sha256', $raw);
        $type = (string) $evt['event'];
        $d = $evt['data'];
        $ref = is_string($d['reference'] ?? null) ? $d['reference'] : (is_string($d['transaction_reference'] ?? null) ? $d['transaction_reference'] : null);
        $after = [];
        $outcome = Db::transaction($this->pdo, function () use ($hash, $type, $d, $ref, &$after) {
            if (!$this->repo->recordEvent($hash, $type, $ref, 'received')) { return 'duplicate'; }       // same delivery again: acknowledge, do nothing
            $out = $this->applyEvent($type, $d, $ref, $after);
            $this->repo->setEventOutcome($hash, $out);
            return $out;
        });
        foreach ($after as $f) { $f(); }
        return ['status' => 200, 'body' => ['ok' => true, 'outcome' => $outcome]];
    }

    /** @param array<string,mixed> $d @param list<callable> $after */
    private function applyEvent(string $type, array $d, ?string $ref, array &$after): string
    {
        if ($ref === null || !in_array($type, ['charge.success', 'charge.failed', 'refund.processed'], true)) { return 'ignored_event'; }
        $p = $this->repo->byReference($ref);
        if ($p === null) { return 'ignored_unknown_reference'; }                                       // a reference WE did not create can never grant anything
        if ($type === 'charge.success') {
            $remote = ['status' => (string) ($d['status'] ?? ''), 'reference' => $ref, 'amount_minor' => is_int($d['amount'] ?? null) ? $d['amount'] : -1, 'currency' => strtoupper((string) ($d['currency'] ?? '')),
                'transaction_id' => isset($d['id']) ? (string) $d['id'] : null, 'paid_at' => is_string($d['paid_at'] ?? null) ? $d['paid_at'] : null, 'customer_code' => is_string($d['customer']['customer_code'] ?? null) ? $d['customer']['customer_code'] : null];
            $after = array_merge($after, $this->settle($ref, $remote, 'webhook', null));
            return 'charge_success';
        }
        if ($type === 'charge.failed') {
            $l = $this->repo->lockByReference($ref);
            if ($l !== null && $l['status'] === 'pending') { $this->repo->updatePayment((string) $l['id'], ['status' => 'failed', 'failure_reason' => 'declined']); return 'marked_failed'; }
            return 'no_change';
        }
        $l = $this->repo->lockByReference($ref);                                                         // refund.processed: record it; the owner/admin decides about access
        if ($l !== null && $l['status'] === 'success') {
            $this->repo->updatePayment((string) $l['id'], ['status' => 'refunded']);
            $this->audit->log('billing.refunded', null, null, $l['business_id'], 'payment', (string) $l['id'], null, ['reference' => $ref]);
            return 'marked_refunded';
        }
        return 'no_change';
    }

    // ================================================================================================ the one place a plan is granted
    /**
     * Idempotent and row-locked. Must run inside a transaction. Returns callbacks to run AFTER commit (e-mail).
     * @param array{status:string,reference:string,amount_minor:int,currency:string,transaction_id:?string,paid_at:?string,customer_code?:?string} $remote
     * @return list<callable>
     */
    private function settle(string $reference, array $remote, string $via, ?Request $req): array
    {
        $p = $this->repo->lockByReference($reference) ?? throw ApiException::notFound('Payment not found.');
        if (in_array($p['status'], ['success', 'refunded'], true)) { return []; }                        // already settled: nothing to do, nothing to repeat
        $id = (string) $p['id'];
        if (($remote['reference'] ?? null) !== $reference) { return $this->fail($p, 'reference_mismatch', $req); }
        if ($remote['status'] === 'abandoned') { $this->repo->updatePayment($id, ['status' => 'abandoned']); return []; }
        if ($remote['status'] !== 'success') {
            if ($remote['status'] === 'failed') { $this->repo->updatePayment($id, ['status' => 'failed', 'failure_reason' => 'declined']); }
            return [];                                                                                  // still pending at the provider: leave it
        }
        if ((int) $remote['amount_minor'] !== (int) $p['amount_minor'] || strtoupper($remote['currency']) !== $p['currency']) {
            return $this->fail($p, 'amount_mismatch', $req);                                              // paid something else than we asked for: NO plan
        }
        if ($p['business_id'] === null) { return $this->fail($p, 'business_missing', $req); }
        $b = $this->repo->lockBusiness((string) $p['business_id']);
        if ($b === null) { return $this->fail($p, 'business_missing', $req); }
        $now = Clock::now();
        $cur = $b['plan_expiry'] === null ? null : new \DateTimeImmutable($b['plan_expiry'], new \DateTimeZone('UTC'));
        $renewal = PlanCatalog::effective($b, $now) === $p['plan'] && $cur !== null && $cur > $now;      // same plan still running: add time after it; otherwise start now
        $start = $renewal ? $cur : $now;
        $end = $start->modify('+' . PriceTable::days((string) $p['cycle']) . ' days');
        $this->repo->activatePlan((string) $p['business_id'], (string) $p['plan'], Clock::sql($end), $remote['customer_code'] ?? null);
        $this->repo->updatePayment($id, ['status' => 'success', 'provider_transaction_id' => $remote['transaction_id'], 'period_start' => Clock::sql($start), 'period_end' => Clock::sql($end), 'paid_at' => Clock::sql($now), 'settled_via' => $via, 'failure_reason' => null]);
        $this->audit->log('billing.plan_activated', $req, $p['user_id'], (string) $p['business_id'], 'payment', $id, ['plan' => $b['plan'], 'plan_expiry' => $b['plan_expiry']], ['plan' => $p['plan'], 'plan_expiry' => Clock::sql($end), 'via' => $via, 'renewal' => $renewal]);
        $email = $p['user_id'] === null ? null : $this->repo->userEmail((string) $p['user_id']);
        $text = "Thank you. Your " . ucfirst((string) $p['plan']) . " plan for {$b['name']} is active until " . $end->format('j F Y') . ".\n\nAmount: {$p['currency']} " . Money::ofMinor((int) $p['amount_minor'])->toString() . "\nReference: {$p['reference']}\n";
        return $email === null ? [] : [fn () => $this->mailer->send($email, 'Your LeenTrack ' . ucfirst((string) $p['plan']) . ' plan is active', $text)];
    }

    /** @param array<string,mixed> $p @return list<callable> */
    private function fail(array $p, string $reason, ?Request $req): array
    {
        $this->repo->updatePayment((string) $p['id'], ['status' => 'failed', 'failure_reason' => $reason]);
        $this->audit->log('billing.payment_rejected', $req, $p['user_id'], $p['business_id'], 'payment', (string) $p['id'], null, ['reason' => $reason]);
        return [];
    }

    // ================================================================================================ cron
    /** Moves businesses whose paid period has ended to the free plan (access already stops at expiry; this makes the data agree). @return array{expired:int} */
    public function expirePlans(): array
    {
        $now = Clock::sql();
        $n = 0;
        foreach ($this->repo->expired($now) as $b) {
            if ($this->repo->markExpired($b['id'], $now)) { $n++; $this->audit->log('billing.plan_expired', null, null, $b['id'], 'business', $b['id'], ['plan' => $b['plan']], ['plan' => 'free', 'plan_status' => 'expired']); }
        }
        return ['expired' => $n, 'retained_payments_deleted' => $this->repo->deleteOldRetained(Clock::sql(Clock::now()->modify('-' . $this->retentionYears . ' years')))];
    }

    // ================================================================================================ helpers
    /** @return array<string,mixed> */
    private function business(string $bid): array
    {
        $st = $this->pdo->prepare('SELECT id,name,currency,plan,plan_status,plan_expiry FROM businesses WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$bid]);
        return $st->fetch() ?: throw ApiException::notFound();
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private function verifyResult(array $p, string $bid): array
    {
        $b = $this->business($bid);
        return ['payment' => $this->view($p), 'plan' => $b['plan'], 'plan_status' => $b['plan_status'], 'effective_plan' => PlanCatalog::effective($b), 'plan_expiry' => $b['plan_expiry'] === null ? null : $this->iso($b['plan_expiry'])];
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private function view(array $p): array
    {
        return ['id' => $p['id'], 'reference' => $p['reference'], 'plan' => $p['plan'], 'cycle' => $p['cycle'], 'currency' => $p['currency'], 'amount' => Money::ofMinor((int) $p['amount_minor'])->toString(), 'amount_minor' => (int) $p['amount_minor'],
            'status' => $p['status'], 'failure_reason' => $p['failure_reason'], 'paid_at' => $p['paid_at'] === null ? null : $this->iso($p['paid_at']),
            'period_start' => $p['period_start'] === null ? null : $this->iso($p['period_start']), 'period_end' => $p['period_end'] === null ? null : $this->iso($p['period_end']), 'created_at' => $this->iso($p['created_at'])];
    }

    private function iso(string $sql): string
    {
        return (new \DateTimeImmutable($sql, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
