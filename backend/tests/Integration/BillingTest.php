<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Billing\PriceTable;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Uuid;
use LeenTrack\Mail\FileMailer;
use LeenTrack\Tests\Support\FakeGateway;

final class BillingTest extends ApiTestCase
{
    private FakeGateway $gw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gw = new FakeGateway();
        $this->container->setGateway($this->gw);
        Clock::freeze(new \DateTimeImmutable('2026-03-01 10:00:00', new \DateTimeZone('UTC')));
    }

    private function owner(string $email = 'owner@example.com'): array
    {
        return $this->signup($email);
    }

    private function checkout(array $o, string $plan = 'growth', string $cycle = 'monthly', ?string $currency = null): array
    {
        return $this->call('POST', '/billing/checkout', array_filter(['plan' => $plan, 'cycle' => $cycle, 'currency' => $currency]), $o['token'], $o['business_id']);
    }

    /** checkout + customer pays exactly what was asked; returns the reference */
    private function started(array $o, string $plan = 'growth', string $cycle = 'monthly', ?string $currency = null): string
    {
        [$s, $j] = $this->checkout($o, $plan, $cycle, $currency);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data']['payment']['reference'];
    }

    private function verify(array $o, string $ref): array
    {
        return $this->call('POST', '/billing/verify', ['reference' => $ref], $o['token'], $o['business_id']);
    }

    private function biz(array $o): array
    {
        return $this->pdo->query("SELECT plan, plan_status, plan_expiry FROM businesses WHERE id='{$o['business_id']}'")->fetch();
    }

    private function hook(string $event, array $data, ?string $sig = null, ?string $raw = null): array
    {
        $raw ??= json_encode(['event' => $event, 'data' => $data]);
        $req = \LeenTrack\Core\Request::fromGlobals(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v1/webhooks/paystack', 'REMOTE_ADDR' => '52.31.139.75', 'CONTENT_TYPE' => 'application/json']
            + ($sig === '' ? [] : ['HTTP_X_PAYSTACK_SIGNATURE' => $sig ?? FakeGateway::sign($raw)]), $raw);
        $res = $this->kernel->handle($req);
        return [$res->status, json_decode($res->body, true) ?? []];
    }

    private function chargeSuccess(string $ref, array $over = []): array
    {
        $tx = $this->gw->initialised[$ref];
        return $over + ['status' => 'success', 'reference' => $ref, 'amount' => $tx['amount_minor'], 'currency' => $tx['currency'], 'id' => 4099260516, 'paid_at' => '2026-03-01T10:00:00.000Z', 'customer' => ['email' => 'owner@example.com', 'customer_code' => 'CUS_abc']];
    }

    // ================================================================================================ plans & access
    public function testPlansAreServerPricedAndOwnerOnly(): void
    {
        $o = $this->owner();
        [$s, $j] = $this->call('GET', '/billing/plans?currency=usd', null, $o['token'], $o['business_id']);
        $this->assertSame(200, $s);
        $g = $j['data']['plans'][0];
        $this->assertSame(['USD', '$', 'growth', '2.50'], [$j['data']['currency'], $j['data']['symbol'], $g['plan'], $g['monthly']]);
        $this->assertSame([['monthly', '2.50', 250, '0.00'], ['quarterly', '6.75', 675, '0.75'], ['yearly', '24.00', 2400, '6.00']], array_map(fn ($c) => [$c['cycle'], $c['total'], $c['amount_minor'], $c['saving']], $g['cycles']));
        $this->assertTrue($j['data']['online_payments']);
        $this->assertSame(500, $j['data']['limits']['growth']['products']);
        $this->assertNull($j['data']['limits']['business']['products'], 'unlimited is null');
        $this->assertSame(422, $this->call('GET', '/billing/plans?currency=XXX', null, $o['token'], $o['business_id'])[0]);
        $this->assertSame(422, $this->call('GET', '/billing/plans?bogus=1', null, $o['token'], $o['business_id'])[0]);
        $this->setPlan($o['business_id'], 'growth');                       // staff accounts are a paid feature; the business's plan is irrelevant to the checks below
        $m = $this->addStaff($o, 'mgr@example.com', 'manager');
        $c = $this->addStaff($o, 'cash@example.com', 'cashier');
        foreach ([['GET', '/billing'], ['GET', '/billing/plans'], ['GET', '/billing/history'], ['POST', '/billing/checkout'], ['POST', '/billing/verify']] as [$meth, $path]) {
            foreach ([$m, $c] as $who) { $this->assertSame(403, $this->call($meth, $path, $meth === 'GET' ? null : ['plan' => 'growth'], $who['token'], $o['business_id'])[0], "$meth $path"); }
        }
    }

    // ================================================================================================ checkout
    public function testCheckoutCreatesAServerPricedPaymentAndTheClientCannotInfluenceIt(): void
    {
        $o = $this->owner();
        [$s, $j] = $this->checkout($o, 'business', 'quarterly', 'ghs');
        $this->assertSame(201, $s, json_encode($j));
        $p = $j['data']['payment'];
        $this->assertSame(['business', 'quarterly', 'GHS', '264.60', 26460, 'pending'], [$p['plan'], $p['cycle'], $p['currency'], $p['amount'], $p['amount_minor'], $p['status']], '98 × 3 × 0.9');
        $this->assertMatchesRegularExpression('/^LT-[0-9A-F]{20}$/', $p['reference']);
        $this->assertStringStartsWith('https://checkout.paystack.test/', $j['data']['authorization_url']);
        $tx = $this->gw->initialised[$p['reference']];
        $this->assertSame([26460, 'GHS', 'owner@example.com'], [$tx['amount_minor'], $tx['currency'], $tx['email']]);
        $this->assertSame($o['business_id'], $tx['metadata']['business_id']);
        foreach ([['amount' => 1], ['amount_minor' => 1], ['reference' => 'LT-MINE'], ['business_id' => Uuid::v4()], ['expiry' => '2999-01-01'], ['status' => 'success'], ['user_id' => Uuid::v4()]] as $extra) {
            $this->assertSame(422, $this->call('POST', '/billing/checkout', ['plan' => 'growth', 'cycle' => 'monthly'] + $extra, $o['token'], $o['business_id'])[0], json_encode($extra));
        }
        foreach ([['plan' => 'free', 'cycle' => 'monthly'], ['plan' => 'enterprise', 'cycle' => 'monthly'], ['plan' => 'growth', 'cycle' => 'weekly'], ['plan' => 'growth'], ['cycle' => 'monthly'], ['plan' => 'growth', 'cycle' => 'monthly', 'currency' => 'XXX'], ['plan' => 'growth', 'cycle' => 'monthly', 'currency' => 'NG']] as $bad) {
            $this->assertSame(422, $this->call('POST', '/billing/checkout', $bad, $o['token'], $o['business_id'])[0], json_encode($bad));
        }
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn(), 'refused requests created nothing');
        $this->assertSame('free', $this->biz($o)['plan'], 'starting a checkout changes no plan');
    }

    public function testCheckoutFailureModes(): void
    {
        $o = $this->owner();
        $this->gw->configured = false;
        [$s, $j] = $this->checkout($o);
        $this->assertSame([503, 'billing_not_configured'], [$s, $j['error']['code']]);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
        $this->gw->configured = true;
        $this->gw->failInit = true;
        [$s, $j] = $this->checkout($o);
        $this->assertSame(502, $s);
        $row = $this->pdo->query('SELECT status, failure_reason FROM payments')->fetch();
        $this->assertSame(['failed', 'could_not_start'], [$row['status'], $row['failure_reason']], 'a payment that never started is not left pending');
        $this->gw->failInit = false;
        for ($i = 0; $i < 5; $i++) { $this->assertSame(201, $this->checkout($o)[0]); }
        [$s, $j] = $this->checkout($o);
        $this->assertSame([429, 'too_many_pending'], [$s, $j['error']['code']], 'a client cannot spray pending payments');
        $b = $this->owner('b@example.com');
        $this->assertSame(201, $this->checkout($b)[0], "A's pending payments do not affect B");
    }

    public function testCannotBuyACheaperPlanWhileAHigherOneIsRunning(): void
    {
        $o = $this->owner();
        $ref = $this->started($o, 'business', 'monthly');
        $this->gw->pay($ref);
        $this->assertSame(200, $this->verify($o, $ref)[0]);
        [$s, $j] = $this->checkout($o, 'growth', 'monthly');
        $this->assertSame([409, 'downgrade_not_allowed'], [$s, $j['error']['code']]);
        $this->assertStringContainsString('2026-03-31', $j['error']['message']);
        $this->assertSame(201, $this->checkout($o, 'business', 'yearly')[0], 'renewing the same plan is fine');
    }

    // ================================================================================================ verify
    public function testVerifiedPaymentActivatesExactlyWhatWasBoughtAndOnlyOnce(): void
    {
        $o = $this->owner();
        $this->assertSame('free', $this->call('GET', '/billing', null, $o['token'], $o['business_id'])[1]['data']['effective_plan']);
        $ref = $this->started($o, 'growth', 'quarterly');
        $this->assertSame(['pending'], [$this->verify($o, $ref)[1]['data']['payment']['status']], 'not paid yet: still pending, no plan');
        $this->assertSame('free', $this->biz($o)['plan']);
        $this->gw->pay($ref);
        [$s, $j] = $this->verify($o, $ref);
        $this->assertSame(200, $s, json_encode($j));
        $this->assertSame(['success', 'growth', 'active', 'growth', '2026-06-01T10:00:00.000Z'], [$j['data']['payment']['status'], $j['data']['plan'], $j['data']['plan_status'], $j['data']['effective_plan'], $j['data']['plan_expiry']], '92 days from 1 March');
        $biz = $this->biz($o);
        $this->assertSame(['growth', 'active', '2026-06-01 10:00:00.000'], [$biz['plan'], $biz['plan_status'], $biz['plan_expiry']]);
        $calls = $this->gw->verifyCalls;
        // verifying again changes nothing and does not even ask the provider
        $again = $this->verify($o, $ref)[1]['data'];
        $this->assertSame([$calls, '2026-06-01T10:00:00.000Z'], [$this->gw->verifyCalls, $again['plan_expiry']]);
        $this->assertSame(1, count(array_filter(FileMailer::$outbox, fn ($m) => str_contains($m['subject'] ?? '', 'Growth plan is active'))), 'one confirmation e-mail');
        [, $sum] = $this->call('GET', '/billing', null, $o['token'], $o['business_id']);
        $this->assertSame([92, 'growth'], [$sum['data']['days_left'], $sum['data']['last_payment']['plan']]);
        [, $h] = $this->call('GET', '/billing/history', null, $o['token'], $o['business_id']);
        $this->assertSame([1, 'success', 'NGN', 945000, '9450.00'], [$h['meta']['total'], $h['data'][0]['status'], $h['data'][0]['currency'], $h['data'][0]['amount_minor'], $h['data'][0]['amount']], '3500 × 3 × 0.9 = 9450.00 NGN');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='billing.plan_activated'")->fetchColumn());
        // the unlocked limit really applies: a 2nd outlet needs growth
        $this->assertSame(201, $this->call('POST', '/locations', ['name' => 'Branch'], $o['token'], $o['business_id'])[0]);
    }

    public function testWrongAmountOrCurrencyNeverActivatesAPlan(): void
    {
        $o = $this->owner();
        foreach ([['amount_minor' => 100], ['amount_minor' => 350000 * 2], ['currency' => 'USD'], ['reference' => 'LT-SOMETHINGELSE']] as $i => $override) {
            $ref = $this->started($o, 'growth', 'monthly', 'NGN');
            $this->gw->pay($ref, $override);
            [$s, $j] = $this->verify($o, $ref);
            $this->assertSame(200, $s);
            $this->assertSame('failed', $j['data']['payment']['status'], json_encode($override));
            $this->assertContains($j['data']['payment']['failure_reason'], ['amount_mismatch', 'reference_mismatch']);
            $this->assertSame(['free', null], [$this->biz($o)['plan'], $this->biz($o)['plan_expiry']], "paying something else grants nothing ($i)");
            if ($i === 3) { break; }
        }
        $this->assertGreaterThanOrEqual(4, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='billing.payment_rejected'")->fetchColumn());
    }

    public function testProviderStatesAndOutages(): void
    {
        $o = $this->owner();
        $ref = $this->started($o);
        $this->gw->failVerify = true;
        $this->assertSame(502, $this->verify($o, $ref)[0]);
        $this->assertSame('pending', $this->pdo->query("SELECT status FROM payments WHERE reference='$ref'")->fetchColumn(), 'an outage changes nothing');
        $this->gw->failVerify = false;
        $this->gw->pay($ref, ['status' => 'abandoned']);
        $this->assertSame('abandoned', $this->verify($o, $ref)[1]['data']['payment']['status']);
        $this->assertSame('free', $this->biz($o)['plan']);
        $ref2 = $this->started($o);
        $this->gw->pay($ref2, ['status' => 'failed']);
        $this->assertSame('failed', $this->verify($o, $ref2)[1]['data']['payment']['status']);
        $ref3 = $this->started($o);
        $this->gw->pay($ref3);
        $this->assertSame('success', $this->verify($o, $ref3)[1]['data']['payment']['status'], 'a later attempt can still succeed');
    }

    public function testVerifyIsTenantScopedAndValidated(): void
    {
        $a = $this->owner('a@example.com'); $b = $this->owner('b@example.com');
        $ref = $this->started($a);
        $this->gw->pay($ref);
        $this->assertSame(404, $this->verify($b, $ref)[0], "B cannot settle or even see A's payment");
        $this->assertSame('free', $this->biz($a)['plan']);
        $this->assertSame(404, $this->verify($a, 'LT-DOESNOTEXIST')[0]);
        foreach (['', 'x', 'a b', "LT-1';--", str_repeat('A', 80)] as $bad) { $this->assertSame(422, $this->verify($a, $bad)[0], $bad); }
        $this->assertSame(422, $this->call('POST', '/billing/verify', ['reference' => $ref, 'plan' => 'business', 'amount' => 1], $a['token'], $a['business_id'])[0], 'the request cannot name a plan or amount');
        $this->assertSame([], $this->call('GET', '/billing/history', null, $b['token'], $b['business_id'])[1]['data']);
    }

    public function testRenewalsAddTimeAndPlanChangesStartNow(): void
    {
        $o = $this->owner();
        $r1 = $this->started($o, 'growth', 'monthly'); $this->gw->pay($r1); $this->verify($o, $r1);
        $this->assertSame('2026-03-31 10:00:00.000', $this->biz($o)['plan_expiry']);
        Clock::freeze(new \DateTimeImmutable('2026-03-10 10:00:00', new \DateTimeZone('UTC')));
        $o = $this->login('owner@example.com');                        // the old access token (15 min) has expired on this clock
        $r2 = $this->started($o, 'growth', 'monthly'); $this->gw->pay($r2); $this->verify($o, $r2);
        $this->assertSame('2026-04-30 10:00:00.000', $this->biz($o)['plan_expiry'], 'renewal is added after the current expiry, early payment loses nothing');
        $this->assertSame('2026-03-31 10:00:00.000', $this->pdo->query("SELECT period_start FROM payments WHERE reference='$r2'")->fetchColumn(), 'the second period begins where the first ends');
        $r3 = $this->started($o, 'business', 'monthly'); $this->gw->pay($r3); $this->verify($o, $r3);
        $this->assertSame(['business', '2026-04-09 10:00:00.000'], [$this->biz($o)['plan'], $this->biz($o)['plan_expiry']], 'a different plan starts now (remaining time is not credited — documented)');
        Clock::freeze(new \DateTimeImmutable('2026-06-01 10:00:00', new \DateTimeZone('UTC')));
        $o = $this->login('owner@example.com');
        $r4 = $this->started($o, 'business', 'yearly'); $this->gw->pay($r4); $this->verify($o, $r4);
        $this->assertSame('2027-06-02 10:00:00.000', $this->biz($o)['plan_expiry'], 'after expiry a renewal starts today');
    }

    // ================================================================================================ webhook
    public function testWebhookGrantsOnlyAfterASignatureAndOnlyWhatTheServerCreated(): void
    {
        $o = $this->owner();
        $ref = $this->started($o, 'business', 'yearly');
        $data = $this->chargeSuccess($ref);
        [$s] = $this->hook('charge.success', $data, 'f' . str_repeat('0', 127));
        $this->assertSame(401, $s, 'wrong signature');
        $raw = json_encode(['event' => 'charge.success', 'data' => $data]);
        [$s] = $this->hook('charge.success', $data, '', $raw);
        $this->assertSame(401, $s, 'missing signature');
        [$s] = $this->hook('charge.success', $data, FakeGateway::sign($raw . ' '), $raw);
        $this->assertSame(401, $s, 'signature of different bytes');
        $this->assertSame('free', $this->biz($o)['plan'], 'nothing happened without a valid signature');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn());
        // attacker-style metadata inside a correctly signed body is ignored: the PAYMENT decides
        $data['metadata'] = ['business_id' => Uuid::v4(), 'plan' => 'free', 'cycle' => 'monthly'];
        [$s, $j] = $this->hook('charge.success', $data);
        $this->assertSame([200, 'charge_success'], [$s, $j['outcome']]);
        $biz = $this->biz($o);
        $this->assertSame(['business', 'active', '2027-03-02 10:00:00.000'], [$biz['plan'], $biz['plan_status'], $biz['plan_expiry']], '366 days');
        $this->assertSame('webhook', $this->pdo->query("SELECT settled_via FROM payments WHERE reference='$ref'")->fetchColumn());
        $this->assertSame('CUS_abc', $this->pdo->query("SELECT paystack_customer_id FROM businesses WHERE id='{$o['business_id']}'")->fetchColumn());
        // the event is remembered, the body is not
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn());
        $this->assertStringNotContainsString('owner@example.com', json_encode($this->pdo->query('SELECT * FROM webhook_events')->fetchAll()));
    }

    public function testWebhookReplaysDuplicatesForgedAmountsAndUnknownReferencesAreHarmless(): void
    {
        $o = $this->owner();
        $ref = $this->started($o, 'growth', 'monthly');
        $good = $this->chargeSuccess($ref);
        $this->assertSame('charge_success', $this->hook('charge.success', $good)[1]['outcome']);
        $exp = $this->biz($o)['plan_expiry'];
        [$s, $j] = $this->hook('charge.success', $good);
        $this->assertSame([200, 'duplicate'], [$s, $j['outcome']], 'the identical delivery is acknowledged and ignored');
        Clock::freeze(new \DateTimeImmutable('2026-03-05 10:00:00', new \DateTimeZone('UTC')));
        $this->hook('charge.success', $good + ['extra' => 'different bytes, same payment']);                      // not byte-identical, still the same payment
        $this->assertSame($exp, $this->biz($o)['plan_expiry'], 'a second delivery of an already-settled payment extends nothing');
        // unknown reference: signed but not ours -> nothing
        $o2 = $this->owner('b@example.com');
        [$s, $j] = $this->hook('charge.success', ['status' => 'success', 'reference' => 'LT-NOTMINE', 'amount' => 100, 'currency' => 'NGN']);
        $this->assertSame([200, 'ignored_unknown_reference'], [$s, $j['outcome']]);
        $this->assertSame('free', $this->biz($o2)['plan']);
        // cheaper amount in a signed body for a real reference: rejected
        $ref2 = $this->started($o2, 'business', 'monthly');
        [, $j] = $this->hook('charge.success', $this->chargeSuccess($ref2, ['amount' => 1000]));
        $this->assertSame('charge_success', $j['outcome']);
        $this->assertSame(['failed', 'amount_mismatch', 'free'], [$this->pdo->query("SELECT status FROM payments WHERE reference='$ref2'")->fetchColumn(), $this->pdo->query("SELECT failure_reason FROM payments WHERE reference='$ref2'")->fetchColumn(), $this->biz($o2)['plan']]);
        // a status other than success in a charge.success body does not grant
        $ref3 = $this->started($o2, 'growth', 'monthly');
        $this->hook('charge.success', $this->chargeSuccess($ref3, ['status' => 'failed']));
        $this->assertSame(['failed', 'free'], [$this->pdo->query("SELECT status FROM payments WHERE reference='$ref3'")->fetchColumn(), $this->biz($o2)['plan']]);
    }

    public function testWebhookAndVerifyTogetherSettleOnce(): void
    {
        $o = $this->owner();
        $ref = $this->started($o, 'growth', 'monthly');
        $this->gw->pay($ref);
        $this->hook('charge.success', $this->chargeSuccess($ref));
        $exp = $this->biz($o)['plan_expiry'];
        $this->assertSame('success', $this->verify($o, $ref)[1]['data']['payment']['status']);
        $this->assertSame($exp, $this->biz($o)['plan_expiry'], 'the owner\'s verify after the webhook adds nothing');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='billing.plan_activated'")->fetchColumn());
        $this->assertSame(1, count(array_filter(FileMailer::$outbox, fn ($m) => str_contains($m['subject'] ?? '', 'plan is active'))));
    }

    public function testOtherWebhookEvents(): void
    {
        $o = $this->owner();
        $ref = $this->started($o, 'growth', 'monthly');
        $this->assertSame('marked_failed', $this->hook('charge.failed', ['reference' => $ref])[1]['outcome']);
        $this->assertSame('failed', $this->pdo->query("SELECT status FROM payments WHERE reference='$ref'")->fetchColumn());
        $ref2 = $this->started($o, 'growth', 'monthly');
        $this->hook('charge.success', $this->chargeSuccess($ref2));
        $this->assertSame('growth', $this->biz($o)['plan']);
        $this->assertSame('no_change', $this->hook('charge.failed', ['reference' => $ref2])[1]['outcome'], 'a failure notice cannot undo a settled payment');
        $this->assertSame('success', $this->pdo->query("SELECT status FROM payments WHERE reference='$ref2'")->fetchColumn());
        [, $j] = $this->hook('refund.processed', ['transaction_reference' => $ref2, 'status' => 'processed']);
        $this->assertSame('marked_refunded', $j['outcome']);
        $this->assertSame(['refunded', 'growth'], [$this->pdo->query("SELECT status FROM payments WHERE reference='$ref2'")->fetchColumn(), $this->biz($o)['plan']], 'a refund is recorded; access is a human decision');
        $this->assertSame('ignored_event', $this->hook('subscription.create', ['reference' => $ref2])[1]['outcome']);
        $this->assertSame('ignored_event', $this->hook('transfer.success', ['foo' => 'bar'])[1]['outcome']);
        $raw = 'not json at all';
        $this->assertSame(400, $this->hook('x', [], FakeGateway::sign($raw), $raw)[0]);
        $raw = '{"event":"charge.success"}';
        $this->assertSame(400, $this->hook('x', [], FakeGateway::sign($raw), $raw)[0], 'no data');
        $this->gw->configured = false;
        $this->assertSame(503, $this->hook('charge.success', $this->chargeSuccess($ref2))[0], 'unconfigured: refuses rather than guessing');
    }

    // ================================================================================================ cron, retention
    public function testExpiredPlansAreMovedToFreeAndAccessStopsAtExpiryEvenBeforeTheJobRuns(): void
    {
        $o = $this->owner();
        $ref = $this->started($o, 'growth', 'monthly'); $this->gw->pay($ref); $this->verify($o, $ref);
        $this->assertSame(0, $this->container->billing()->expirePlans()['expired']);
        $this->assertSame('growth', $this->biz($o)['plan'], 'not expired yet -> untouched');
        $this->assertSame(201, $this->call('POST', '/locations', ['name' => 'Second'], $o['token'], $o['business_id'])[0], 'growth allows a second outlet');
        Clock::freeze(new \DateTimeImmutable('2026-03-31 10:00:01', new \DateTimeZone('UTC')));
        $o = $this->login('owner@example.com');
        $this->assertSame('growth', $this->biz($o)['plan'], 'the nightly job has not run yet …');
        $this->assertSame(402, $this->call('POST', '/locations', ['name' => 'Third'], $o['token'], $o['business_id'])[0], '… but the effective plan is already free: access cannot outlive payment');
        $this->assertSame('free', $this->call('GET', '/billing', null, $o['token'], $o['business_id'])[1]['data']['effective_plan']);
        $r = $this->container->billing()->expirePlans();
        $this->assertSame(1, $r['expired']);
        $this->assertSame(['free', 'expired'], [$this->biz($o)['plan'], $this->biz($o)['plan_status']]);
        $this->assertSame(0, $this->container->billing()->expirePlans()['expired'], 'idempotent');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='billing.plan_expired'")->fetchColumn());
        // and the owner can buy again
        $ref2 = $this->started($o, 'growth', 'monthly'); $this->gw->pay($ref2); $this->verify($o, $ref2);
        $this->assertSame(['growth', 'active'], [$this->biz($o)['plan'], $this->biz($o)['plan_status']]);
    }

    public function testPaymentRecordsSurviveAccountDeletionPseudonymisedAndAreDeletedAfterTheRetentionPeriod(): void
    {
        $o = $this->owner();
        $ref = $this->started($o, 'growth', 'monthly'); $this->gw->pay($ref); $this->verify($o, $ref);
        $this->assertSame(202, $this->call('POST', '/me/deletion', ['password' => self::PW, 'confirm' => 'DELETE'], $o['token'])[0]);
        $this->pdo->exec("UPDATE users SET deletion_scheduled_at = '2000-01-01' WHERE id='{$o['user_id']}'");
        $this->assertSame(1, $this->container->account()->purgeDue()['accounts']);
        $row = $this->pdo->query("SELECT business_id, user_id, amount_minor, currency, plan, status, reference FROM payments")->fetch();
        $this->assertSame([null, null, 350000, 'NGN', 'growth', 'success', $ref], [$row['business_id'], $row['user_id'], (int) $row['amount_minor'], $row['currency'], $row['plan'], $row['status'], $row['reference']], 'the accounting record stays, the people are gone');
        $r = $this->container->billing()->expirePlans();
        $this->assertSame(0, $r['retained_payments_deleted'], 'inside the retention period');
        $this->pdo->exec("UPDATE payments SET created_at = '2018-01-01 00:00:00'");
        $this->assertSame(1, $this->container->billing()->expirePlans()['retained_payments_deleted'], 'older than 7 years and detached from any business');
    }

    public function testPaymentsAreIncludedInTheOwnersBusinessExport(): void
    {
        $o = $this->owner();
        $ref = $this->started($o); $this->gw->pay($ref); $this->verify($o, $ref);
        [$s, $j] = $this->call('GET', "/businesses/{$o['business_id']}/export?section=payments", null, $o['token'], $o['business_id']);
        $this->assertSame([200, $ref], [$s, $j['data'][0]['reference']]);
    }

    // ================================================================================================ real parallelism
    public function testConcurrentDeliveriesOfTheSamePaymentActivateOnce(): void
    {
        $o = $this->owner();
        $ref = $this->started($o, 'growth', 'monthly');
        $env = array_merge($_ENV, getenv(), ['TEST_DB_NAME' => getenv('TEST_DB_NAME'), 'TEST_DB_USER' => getenv('TEST_DB_USER'), 'TEST_DB_PASS' => getenv('TEST_DB_PASS'), 'TEST_DB_SOCKET' => getenv('TEST_DB_SOCKET'), 'TEST_PAYSTACK_SECRET' => FakeGateway::SECRET]);
        $startAt = (string) (microtime(true) + 2.0);
        $procs = [];
        for ($w = 0; $w < 8; $w++) {
            $raw = json_encode(['event' => 'charge.success', 'data' => $this->chargeSuccess($ref) + ['n' => $w % 4]]);          // 4 distinct bodies, each delivered twice
            $reqs = [['method' => 'POST', 'path' => '/webhooks/paystack', 'raw' => $raw, 'headers' => ['X-Paystack-Signature' => FakeGateway::sign($raw)]]];
            $pipes = [];
            $procs[] = [proc_open([PHP_BINARY, __DIR__ . '/../bin/request_worker.php', 'unused', $o['business_id'], $startAt, json_encode($reqs)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env), $pipes];
        }
        $codes = [];
        foreach ($procs as [$proc, $pipes]) { $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); proc_close($proc); $this->assertSame('', trim($err), $err); $codes[] = (int) substr(trim($out), 0, 3); }
        $this->assertSame([200], array_values(array_unique($codes)), 'every delivery acknowledged');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='billing.plan_activated'")->fetchColumn(), 'activated exactly once');
        $start = strtotime((string) $this->pdo->query("SELECT period_start FROM payments WHERE reference='$ref'")->fetchColumn() . ' UTC');
        $this->assertSame(30 * 86400, strtotime($this->biz($o)['plan_expiry'] . ' UTC') - $start, 'one 30-day period, not eight');
        $this->assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn(), 'identical bodies were de-duplicated');
    }
}
