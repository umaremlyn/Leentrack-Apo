<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Clock;
use LeenTrack\Core\Uuid;
use LeenTrack\Mail\FileMailer;
use LeenTrack\Services\JobService;

final class OpsTest extends ApiTestCase
{
    private string $lockDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockDir = sys_get_temp_dir() . '/lt-locks-' . bin2hex(random_bytes(4));
        $this->boot(['lock_dir' => $this->lockDir, 'avatar_dir' => $this->lockDir . '-av', 'web_url' => 'https://app.test']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->lockDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->lockDir);
        parent::tearDown();
    }

    private function shop(string $email = 'owner@example.com'): array
    {
        $o = $this->signup($email);
        $this->setPlan($o['business_id'], 'growth', '2999-01-01 00:00:00');
        return $o;
    }

    private function product(array $o, string $name, int $stock, ?int $reorder = null, string $type = 'product'): array
    {
        [$s, $j] = $this->call('POST', '/products', ['name' => $name, 'selling_price' => '10', 'cost_price' => '5', 'stock_quantity' => $stock, 'item_type' => $type] + ($reorder !== null ? ['reorder_level' => $reorder] : []), $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    private function mails(string $needle = ''): array
    {
        return array_values(array_filter(FileMailer::$outbox, fn ($m) => $needle === '' || str_contains(json_encode($m), $needle)));
    }

    // ================================================================================================ alert settings
    public function testAlertSettingsAreOwnerOnlyValidatedAndAudited(): void
    {
        $o = $this->shop();
        $p = "/businesses/{$o['business_id']}/alert-settings";
        [$s, $j] = $this->call('GET', $p, null, $o['token']);
        $this->assertSame([200, false, null, 'owner@example.com', 'daily'], [$s, $j['data']['enabled'], $j['data']['email'], $j['data']['default_email'], $j['data']['frequency']]);
        $this->assertSame(422, $this->call('PUT', $p, ['enabled' => true], $o['token'])[0], 'enabling needs an address (the default is not assumed silently)');
        foreach ([['enabled' => 'yes'], ['email' => 'nope'], ['frequency' => 'weekly'], ['last_sent_at' => '2020-01-01'], ['business_id' => Uuid::v4()]] as $bad) {
            $this->assertSame(422, $this->call('PUT', $p, $bad + ['enabled' => false], $o['token'])[0], json_encode($bad));
        }
        [$s, $j] = $this->call('PUT', $p, ['enabled' => true, 'email' => 'Alerts@Shop.test', 'frequency' => 'hourly'], $o['token']);
        $this->assertSame([200, true, 'alerts@shop.test', 'hourly'], [$s, $j['data']['enabled'], $j['data']['email'], $j['data']['frequency']]);
        [, $j] = $this->call('PUT', $p, ['frequency' => 'daily'], $o['token']);
        $this->assertSame([true, 'alerts@shop.test'], [$j['data']['enabled'], $j['data']['email']], 'a partial update keeps the rest');
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='alerts.settings'")->fetchColumn());
        $m = $this->addStaff($o, 'mgr@example.com', 'manager');
        foreach ([['GET', $p], ['PUT', $p], ['POST', "$p/test"]] as [$meth, $path]) { $this->assertSame(403, $this->call($meth, $path, $meth === 'GET' ? null : ['enabled' => false], $m['token'])[0], "$meth by a manager"); }
        $b = $this->shop('b@example.com');
        $this->assertSame(404, $this->call('GET', $p, null, $b['token'])[0], "B cannot read A's settings");
        $this->assertSame(404, $this->call('PUT', $p, ['enabled' => false], $b['token'])[0]);
    }

    public function testTestAlertSendsOnlyWhenSomethingIsLowAndIsRateLimited(): void
    {
        $o = $this->shop();
        $p = "/businesses/{$o['business_id']}/alert-settings/test";
        [$s, $j] = $this->call('POST', $p, [], $o['token']);
        $this->assertSame([200, false, 0], [$s, $j['data']['sent'], $j['data']['count']]);
        $this->assertSame([], $this->mails('Stock alert'));
        $this->product($o, 'Rice', 0); $this->product($o, 'Soap', 3); $this->product($o, 'Plenty', 500); $this->product($o, 'Consulting', 0, null, 'service');
        $old = $this->product($o, 'Discontinued', 0);
        $this->assertSame(200, $this->call('DELETE', "/products/{$old['id']}", null, $o['token'], $o['business_id'])[0]);              // archived
        $this->pdo->exec("UPDATE products SET is_active = 1 WHERE id='{$old['id']}'");                                        // even if something left it switched on
        $this->call('PUT', "/businesses/{$o['business_id']}/alert-settings", ['enabled' => false, 'email' => 'boss@shop.test'], $o['token']);
        [, $j] = $this->call('POST', $p, [], $o['token']);
        $this->assertSame([true, 2, 'boss@shop.test'], [$j['data']['sent'], $j['data']['count'], $j['data']['to']], 'a test works even when alerts are switched off');
        $mail = $this->mails('boss@shop.test')[0];
        $body = json_encode($mail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('1 product(s) OUT OF STOCK', $body);
        $this->assertStringContainsString('Rice — OUT OF STOCK', $body);
        $this->assertStringContainsString('Soap — 3 left (reorder at 5)', $body);
        $this->assertStringNotContainsString('Plenty', $body);
        $this->assertStringNotContainsString('Consulting', $body, 'services have no stock');
        $this->assertStringNotContainsString('Discontinued', $body, 'archived products are not reported');
        $this->assertStringContainsString('https://app.test/purchases', $body);
        $this->assertNotNull($this->call('GET', "/businesses/{$o['business_id']}/alert-settings", null, $o['token'])[1]['data']['last_sent_at']);
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='alerts.test'")->fetchColumn(), 'each test is audited');
    }

    public function testTestAlertRateLimit(): void
    {
        $this->boot(['rate_limit_enabled' => true, 'lock_dir' => $this->lockDir, 'web_url' => 'https://app.test']);
        $o = $this->shop();
        $this->product($o, 'Rice', 0);
        $codes = [];
        for ($i = 0; $i < 7; $i++) { $codes[] = $this->call('POST', "/businesses/{$o['business_id']}/alert-settings/test", [], $o['token'])[0]; }
        $this->assertSame([200, 200, 200, 200, 200, 429, 429], $codes, 'five test mails an hour per business: it cannot be used to spam an address');
    }

    // ================================================================================================ the job
    public function testLowStockJobHonoursSwitchCooldownAndOwnerStateAndIsolatesBusinesses(): void
    {
        $a = $this->shop('a@example.com'); $b = $this->shop('b@example.com'); $c = $this->shop('c@example.com'); $d = $this->shop('d@example.com');
        foreach ([$a, $b, $c, $d] as $o) { $this->product($o, 'Rice', 1); }
        $set = fn (array $o, array $x) => $this->assertSame(200, $this->call('PUT', "/businesses/{$o['business_id']}/alert-settings", $x, $o['token'])[0]);
        $set($a, ['enabled' => true, 'email' => 'a-alerts@shop.test']);                // on
        $set($b, ['enabled' => false, 'email' => 'b-alerts@shop.test']);               // off
        $set($c, ['enabled' => true, 'email' => 'c-alerts@shop.test', 'frequency' => 'hourly']);
        $set($d, ['enabled' => true, 'email' => 'd-alerts@shop.test']);
        $this->pdo->exec("UPDATE users SET status = 'disabled' WHERE id='{$d['user_id']}'");                     // owner disabled: no mail
        $svc = $this->container->stockAlerts();
        $r = $svc->runDue();
        $this->assertSame(['checked' => 2, 'sent' => 2, 'skipped' => 0, 'failed' => 0], $r);
        $this->assertCount(1, $this->mails('a-alerts@shop.test')); $this->assertCount(1, $this->mails('c-alerts@shop.test'));
        $this->assertSame([], $this->mails('b-alerts@shop.test')); $this->assertSame([], $this->mails('d-alerts@shop.test'));
        // cool-down: daily 20 h, hourly 1 h
        Clock::freeze(new \DateTimeImmutable('+30 minutes', new \DateTimeZone('UTC')));
        $this->assertSame(0, $svc->runDue()['sent']);
        Clock::freeze(new \DateTimeImmutable('+90 minutes', new \DateTimeZone('UTC')));
        $this->assertSame(1, $svc->runDue()['sent'], 'only the hourly business is due again');
        Clock::freeze(new \DateTimeImmutable('+21 hours', new \DateTimeZone('UTC')));
        $r = $svc->runDue();
        $this->assertSame(2, $r['sent']);
        // nothing low -> nothing sent, and the "last sent" time does not move
        $before = $this->pdo->query("SELECT last_sent_at FROM stock_alert_settings WHERE business_id='{$a['business_id']}'")->fetchColumn();
        $this->pdo->exec("UPDATE products SET stock_quantity = 100 WHERE business_id='{$a['business_id']}'");
        $this->pdo->exec("UPDATE stock_levels SET quantity = 100 WHERE business_id='{$a['business_id']}'");
        Clock::freeze(new \DateTimeImmutable('+48 hours', new \DateTimeZone('UTC')));
        $svc->runDue();
        $this->assertSame($before, $this->pdo->query("SELECT last_sent_at FROM stock_alert_settings WHERE business_id='{$a['business_id']}'")->fetchColumn());
        // a deleted business with settings is ignored, not an error
        $this->pdo->exec("UPDATE businesses SET deleted_at = NOW() WHERE id='{$c['business_id']}'");
        $this->assertSame(0, $svc->runDue()['failed']);
    }

    public function testLongLowStockListsAreCappedAndNamesCannotInjectLines(): void
    {
        $o = $this->shop();
        $this->call('PUT', "/businesses/{$o['business_id']}/alert-settings", ['enabled' => true, 'email' => 'x@shop.test'], $o['token']);
        $ins = $this->pdo->prepare("INSERT INTO products (id,business_id,name,item_type,selling_price,cost_price,stock_quantity,min_stock_level,reorder_level,is_active) VALUES (?,?,?,'product',1,1,0,5,5,1)");
        for ($i = 0; $i < 130; $i++) { $ins->execute([Uuid::v4(), $o['business_id'], "Item $i"]); }
        $ins->execute([Uuid::v4(), $o['business_id'], "Evil\r\nBcc: attacker@x.test\nName"]);
        $this->container->stockAlerts()->runDue();
        $m = json_encode($this->mails('x@shop.test')[0]);
        $this->assertStringContainsString('and 31 more', $m);
        $this->assertSame(100, substr_count($m, '\n- '), 'exactly 100 product lines are listed');
        $this->assertSame(1, substr_count($m, 'Evil'), 'the odd name appears once, flattened onto one line');
        $this->assertStringNotContainsString('\r\nBcc', $m);
    }

    // ================================================================================================ preferences
    public function testPreferencesAreStoredServerSideAndStrictlyValidated(): void
    {
        $o = $this->shop();
        $me = fn () => $this->call('GET', '/me', null, $o['token'])[1]['data']['user']['preferences'];
        $this->assertSame(['notifications' => ['email' => true, 'sms' => false, 'marketing' => false], 'language' => 'en', 'privacy' => ['show_profile' => true, 'share_data' => false]], $me());
        [$s, $j] = $this->call('PATCH', '/me/preferences', ['notifications' => ['marketing' => true, 'email' => false], 'language' => 'fr-CA'], $o['token']);
        $this->assertSame([200, false, true, 'fr-CA', false], [$s, $j['data']['notifications']['email'], $j['data']['notifications']['marketing'], $j['data']['language'], $j['data']['notifications']['sms']], 'merged over what was there');
        $this->call('PATCH', '/me/preferences', ['privacy' => ['share_data' => true]], $o['token']);
        $this->assertSame([false, true, 'fr-CA'], [$me()['notifications']['email'], $me()['privacy']['share_data'], $me()['language']], 'earlier values survive later updates');
        foreach ([['notifications' => ['email' => 'yes']], ['notifications' => ['push' => true]], ['notifications' => 'all'], ['language' => 'english'], ['language' => 'EN'], ['privacy' => ['show_profile' => 1]], ['theme' => 'dark'], ['notifications' => [true, false]]] as $bad) {
            $this->assertSame(422, $this->call('PATCH', '/me/preferences', $bad, $o['token'])[0], json_encode($bad));
        }
        $this->assertSame('fr-CA', $me()['language'], 'refused updates changed nothing');
        $this->pdo->prepare('UPDATE user_profiles SET notification_prefs = ? WHERE user_id = ?')->execute(['{"language":"<script>","notifications":{"email":"x"}}', $o['user_id']]);
        $this->assertSame('en', $me()['language'], 'garbage already in the database never reaches a client');
        $other = $this->shop('other@example.com');
        $this->assertSame('en', $this->call('GET', '/me', null, $other['token'])[1]['data']['user']['preferences']['language'], 'preferences are per person');
        $this->assertSame(401, $this->call('PATCH', '/me/preferences', ['language' => 'en'])[0]);
    }

    // ================================================================================================ reconciliation
    private function populate(array $o): void
    {
        $t = $o['token']; $b = $o['business_id'];
        $call = function (string $m, string $p, ?array $body = null) use ($t, $b) { $r = $this->call($m, $p, $body, $t, $b); $this->assertLessThan(300, $r[0], "$m $p " . json_encode($r[1])); return $r[1]['data'] ?? []; };
        $main = (string) $this->pdo->query("SELECT id FROM locations WHERE business_id='$b' AND is_default=1")->fetchColumn();
        $br = $call('POST', '/locations', ['name' => 'Branch'])['id'];
        $p1 = $call('POST', '/products', ['name' => 'Rice', 'selling_price' => '100', 'cost_price' => '60', 'stock_quantity' => 50]);
        $p2 = $call('POST', '/products', ['name' => 'Beans', 'selling_price' => '50', 'cost_price' => '30', 'stock_quantity' => 50]);
        $c = $call('POST', '/customers', ['name' => 'Ada']);
        $call('PUT', "/businesses/$b/loyalty-settings", ['enabled' => true, 'spend_per_point' => '100', 'point_value' => '1']);
        $s1 = $call('POST', '/sales', ['client_operation_id' => Uuid::v4(), 'customer_id' => $c['id'], 'items' => [['product_id' => $p1['id'], 'quantity' => 3]], 'payment' => ['mode' => 'credit', 'amount' => '50']]);
        $call('POST', "/sales/{$s1['id']}/repayments", ['client_operation_id' => Uuid::v4(), 'amount' => '20']);
        $call('POST', '/sales', ['client_operation_id' => Uuid::v4(), 'customer_id' => $c['id'], 'items' => [['product_id' => $p2['id'], 'quantity' => 2]], 'payment' => ['mode' => 'full', 'method' => 'cash', 'amount' => '100']]);
        $s3 = $call('POST', '/sales', ['client_operation_id' => Uuid::v4(), 'items' => [['product_id' => $p2['id'], 'quantity' => 1]], 'payment' => ['mode' => 'full', 'method' => 'cash', 'amount' => '50']]);
        $call('POST', "/sales/{$s3['id']}/void", ['reason' => 'test']);
        $sup = $call('POST', '/suppliers', ['name' => 'Acme']);
        $po = $call('POST', '/purchase-orders', ['supplier_id' => $sup['id'], 'items' => [['product_id' => $p1['id'], 'quantity' => 5, 'unit_cost' => '55']]]);
        $call('POST', "/purchase-orders/{$po['id']}/order");
        $call('POST', "/purchase-orders/{$po['id']}/receive", ['client_operation_id' => Uuid::v4(), 'items' => [['item_id' => $po['items'][0]['id'], 'quantity' => 2]]]);
        $call('POST', "/purchase-orders/{$po['id']}/payments", ['client_operation_id' => Uuid::v4(), 'amount' => '50', 'method' => 'cash']);
        $tr = $call('POST', '/transfers', ['from_location_id' => $main, 'to_location_id' => $br, 'items' => [['product_id' => $p2['id'], 'quantity' => 4]]]);
        $call('POST', "/transfers/{$tr['id']}/dispatch", ['client_operation_id' => Uuid::v4()]);
        $call('POST', "/transfers/{$tr['id']}/receive", ['client_operation_id' => Uuid::v4()]);
        $call('POST', '/expenses', ['description' => 'Rent', 'amount' => '10', 'expense_date' => '2026-03-01']);
    }

    /** @return array<string,int> */
    private function violations(): array
    {
        $r = $this->container->reconciliation()->run();
        $this->assertGreaterThanOrEqual(16, count($r['checks']));
        return array_column($r['checks'], 'violations', 'name');
    }

    public function testReconciliationIsCleanOnRealActivityAndFindsEachKindOfDrift(): void
    {
        $o = $this->shop();
        $this->populate($o);
        $v = $this->violations();
        $this->assertSame([], array_filter($v, fn ($n, $k) => $n > 0 && $k !== 'stale_pending_payments', ARRAY_FILTER_USE_BOTH), 'every invariant holds after a realistic mix of activity');
        $bid = $o['business_id'];
        $pid = (string) $this->pdo->query("SELECT id FROM products WHERE business_id='$bid' AND name='Rice'")->fetchColumn();
        $cust = (string) $this->pdo->query("SELECT id FROM customers WHERE business_id='$bid'")->fetchColumn();
        $credit = (string) $this->pdo->query("SELECT id FROM sales WHERE business_id='$bid' AND is_credit=1")->fetchColumn();
        $po = (string) $this->pdo->query("SELECT id FROM purchase_orders WHERE business_id='$bid'")->fetchColumn();
        $drift = [
            'stock_total_equals_levels' => "UPDATE products SET stock_quantity = stock_quantity + 1 WHERE id='$pid'",
            'stock_levels_equal_movements' => "UPDATE stock_levels SET quantity = quantity + 1 WHERE product_id='$pid' LIMIT 1",
            'stock_never_negative' => "UPDATE stock_levels SET quantity = -1 WHERE product_id='$pid' LIMIT 1",
            'customer_spend_equals_money_received' => "UPDATE customers SET total_spent = total_spent + 5 WHERE id='$cust'",
            'loyalty_points_equal_ledger' => "UPDATE customers SET loyalty_points = loyalty_points + 7 WHERE id='$cust'",
            'completed_credit_sales_are_settled' => "UPDATE sales SET payment_status = 'completed' WHERE id='$credit'",
            'repayments_never_exceed_debt' => "UPDATE debt_repayments SET amount = amount + 1000 WHERE sale_id='$credit' LIMIT 1",
            'sale_totals_add_up' => "UPDATE sales SET total_amount = total_amount + 1 WHERE id='$credit'",
            'purchase_received_equals_receipts' => "UPDATE purchase_order_items SET quantity_received = quantity_received + 1 WHERE purchase_order_id='$po'",
            'purchase_status_matches_lines' => "UPDATE purchase_orders SET status = 'received' WHERE id='$po'",
            'supplier_payments_within_received' => "UPDATE supplier_payments SET amount = amount + 9999 WHERE purchase_order_id='$po'",
            'transfer_quantities_consistent' => "UPDATE transfer_items SET quantity_received = NULL LIMIT 1",
            'paid_plans_have_an_expiry' => "UPDATE businesses SET plan = 'growth', plan_expiry = NULL WHERE id='$bid'",
        ];
        foreach ($drift as $check => $sql) {
            $this->pdo->exec('START TRANSACTION');
            $this->pdo->exec($sql);
            $now = $this->violations();
            $this->pdo->exec('ROLLBACK');
            $this->assertGreaterThan(0, $now[$check], "$check must notice: $sql");
        }
        // after all rollbacks the books are clean again, and the report names ids, not people or amounts
        $this->assertSame([], array_filter($this->violations(), fn ($n, $k) => $n > 0 && $k !== 'stale_pending_payments', ARRAY_FILTER_USE_BOTH));
        $this->pdo->exec("UPDATE products SET stock_quantity = stock_quantity + 1 WHERE id='$pid'");
        $r = $this->container->reconciliation()->run();
        $chk = array_values(array_filter($r['checks'], fn ($c) => $c['name'] === 'stock_total_equals_levels'))[0];
        $this->assertSame([1, [$pid]], [$chk['violations'], $chk['samples']]);
        $this->assertStringNotContainsString('Rice', json_encode($r));
    }

    public function testReconciliationFlagsStalePendingPaymentsAndOverdueAccountDeletions(): void
    {
        $o = $this->shop();
        $this->pdo->prepare("INSERT INTO payments (id,business_id,user_id,reference,plan,cycle,currency,amount_minor,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([Uuid::v4(), $o['business_id'], $o['user_id'], 'LT-OLD', 'growth', 'monthly', 'NGN', 100, 'pending', gmdate('Y-m-d H:i:s', time() - 5 * 86400)]);
        $this->assertSame(1, $this->violations()['stale_pending_payments']);
        // more than the 1000-row sample cap: the exact number is still reported
        $ins = $this->pdo->prepare("INSERT INTO products (id,business_id,name,item_type,selling_price,cost_price,stock_quantity,min_stock_level,reorder_level,is_active) VALUES (?,?,?,'product',1,1,1,5,5,1)");
        $this->pdo->beginTransaction();
        for ($i = 0; $i < 1003; $i++) { $ins->execute([Uuid::v4(), $o['business_id'], "Ghost $i"]); }
        $this->pdo->commit();
        $this->assertSame(1003, $this->violations()['stock_total_equals_levels'], 'ghost stock with no outlet levels is counted exactly, beyond the sample limit');
        $u = $this->shop('late@example.com');
        $this->pdo->exec("UPDATE users SET status='pending_deletion', deletion_scheduled_at = '2000-01-01' WHERE id='{$u['user_id']}'");
        $this->assertSame(1, $this->violations()['accounts_pending_deletion_overdue'], 'tells the operator the purge job is not running');
    }

    // ================================================================================================ job runner
    public function testJobRunnerRecordsRunsLocksAndSurvivesFailures(): void
    {
        $jobs = $this->container->jobs();
        $r = $jobs->run('reconcile', fn () => ['findings' => 0]);
        $this->assertSame(['ok', ['findings' => 0]], [$r['status'], $r['summary']]);
        $r = $jobs->run('housekeeping', function () { throw new \RuntimeException('boom with details'); });
        $this->assertSame(['failed', 'RuntimeException'], [$r['status'], $r['summary']['error']]);
        $h = $jobs->history('housekeeping');
        $this->assertSame(['failed', 'RuntimeException'], [$h[0]['status'], $h[0]['summary']['error']]);
        $this->assertNotNull($h[0]['finished_at']);
        // an overlapping run is refused quietly while the first still holds the lock
        $inner = null;
        $jobs->run('expire-plans', function () use ($jobs, &$inner) { $inner = $jobs->run('expire-plans', fn () => ['ran' => true]); return ['outer' => true]; });
        $this->assertSame(['skipped', ['reason' => 'already_running']], [$inner['status'], $inner['summary']]);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM job_runs WHERE job='expire-plans'")->fetchColumn(), 'the skipped attempt left no row');
        $jobs->run('expire-plans', fn () => ['again' => true]);
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM job_runs WHERE job='expire-plans'")->fetchColumn(), 'the lock is released afterwards');
        // overview: never-run jobs need attention, fresh ok runs do not, failed ones do
        $ov = array_column($jobs->overview(), null, 'job');
        $this->assertSame([false, true, true, 'never_run'], [$ov['reconcile']['attention'], $ov['housekeeping']['attention'], $ov['purge-accounts']['attention'], $ov['purge-accounts']['last_status']]);
        $this->pdo->exec("UPDATE job_runs SET started_at = '2020-01-01', finished_at = '2020-01-01' WHERE job='reconcile'");
        $this->assertTrue(array_column($jobs->overview(), null, 'job')['reconcile']['attention'], 'a job that stopped running is flagged');
        $this->pdo->exec("INSERT INTO job_runs (job,status,started_at) VALUES ('low-stock-alert','running','" . gmdate('Y-m-d H:i:s', time() - 7200) . "')");
        $this->assertSame('crashed', array_column($jobs->overview(), null, 'job')['low-stock-alert']['last_status'], 'a run stuck "running" for over an hour is reported as crashed');
    }

    public function testPruneKeepsTheLatestRunOfEveryJob(): void
    {
        $jobs = $this->container->jobs();
        $jobs->run('reconcile', fn () => ['n' => 1]);
        $this->pdo->exec("UPDATE job_runs SET started_at = '2019-01-01', finished_at = '2019-01-01'");
        $jobs->run('housekeeping', fn () => ['n' => 2]);
        $this->pdo->exec("INSERT INTO job_runs (job,status,started_at,finished_at) VALUES ('reconcile','ok','2019-01-02','2019-01-02')");
        $this->assertSame(1, $jobs->prune(), 'old history goes, but the newest run of reconcile stays even though it is old');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM job_runs WHERE job='reconcile'")->fetchColumn());
    }

    public function testTheCronCommandRunsJobsRecordsThemAndUsesExitCodes(): void
    {
        $env = ['DB_NAME' => getenv('TEST_DB_NAME'), 'DB_USER' => getenv('TEST_DB_USER'), 'DB_PASS' => getenv('TEST_DB_PASS'), 'DB_SOCKET' => getenv('TEST_DB_SOCKET'), 'DB_HOST' => '', 'APP_ENV' => 'testing', 'LOCK_DIR' => $this->lockDir, 'MAIL_DRIVER' => 'file', 'PATH' => getenv('PATH')];
        $run = function (array $args) use ($env): array {
            $p = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../../bin/cron.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/../..', $env);
            $o = stream_get_contents($pipes[1]); $e = stream_get_contents($pipes[2]);
            return [proc_close($p), $o, $e];
        };
        $this->assertSame(0, $run(['list'])[0]);
        [$c, , $err] = $run(['nonsense']);
        $this->assertSame(2, $c);
        $this->assertStringContainsString('Usage', $err);
        [$c, $out] = $run(['housekeeping']);
        $this->assertSame([0, 'ok'], [$c, json_decode($out, true)['status']]);
        [$c, $out] = $run(['reconcile']);
        $this->assertSame([0, 0], [$c, json_decode($out, true)['summary']['findings']]);
        $o = $this->shop('two@example.com');
        $this->product($o, 'Rice', 5);
        $this->pdo->exec("UPDATE products SET stock_quantity = stock_quantity + 3");                 // drift
        [$c, $out] = $run(['reconcile']);
        $this->assertSame(4, $c, 'findings -> exit code 4 so cron mails the operator');
        $this->assertGreaterThan(0, json_decode($out, true)['summary']['findings']);
        $runs = $this->container->jobs()->history('reconcile');
        $this->assertSame(['ok', 'ok'], array_column($runs, 'status'), 'finding drift is a result, not a failure');
        $this->assertGreaterThan(0, $runs[0]['summary']['findings']);
        $this->assertSame(['housekeeping', 'reconcile'], array_values(array_unique(array_column($this->pdo->query('SELECT job FROM job_runs ORDER BY id')->fetchAll(), 'job'))));
    }

    public function testHealthReportsMissingExtensionsAndBadConfigurationHonestly(): void
    {
        $dir = dirname(__DIR__, 2) . '/database/migrations';
        $cfg = ['env' => 'production', 'debug' => true, 'log_dir' => '/nonexistent-root/logs', 'avatar_dir' => '/nonexistent-root/avatars', 'web_url' => '', 'mail_driver' => 'file', 'paystack' => ['secret' => ''], 'cors_origins' => [], 'smtp' => ['host' => '']];
        $h = $this->container->jobs()->health($cfg, $dir, fn (string $e) => !in_array($e, ['mbstring', 'sodium'], true));
        $by = array_column($h['checks'], null, 'name');
        $this->assertFalse($h['ok'], 'a required extension is missing');
        $this->assertSame(['fail', 'warn', 'fail', 'fail', 'fail', 'fail', 'warn', 'fail'], [$by['ext_mbstring']['status'], $by['ext_sodium']['status'], $by['debug_off']['status'], $by['writable_log_dir']['status'], $by['writable_avatar_dir']['status'], $by['mail']['status'], $by['web_url']['status'], $by['mail']['status']]);
        $this->assertStringContainsString('MISSING', $by['ext_mbstring']['detail']);
        $good = $this->container->jobs()->health(['env' => 'testing', 'debug' => false, 'log_dir' => sys_get_temp_dir(), 'avatar_dir' => sys_get_temp_dir(), 'web_url' => 'https://x.test', 'mail_driver' => 'smtp', 'smtp' => ['host' => 'smtp.x.test'], 'paystack' => ['secret' => 'x'], 'cors_origins' => ['https://x.test']], $dir);
        $this->assertSame([], array_column(array_filter($good['checks'], fn ($c) => $c['status'] === 'fail'), 'name'), 'a healthy configuration has no failures');
        $this->assertSame('ok', array_column($good['checks'], null, 'name')['mail']['status']);
    }

    // ================================================================================================ admin ops endpoints
    public function testAdminSeesJobsAndTheHostHealthCheck(): void
    {
        $a = $this->shop('root@example.com');
        $this->pdo->prepare('INSERT INTO platform_admins (user_id) VALUES (?)')->execute([$a['user_id']]);
        $o = $this->shop('owner@example.com');
        foreach ([['GET', '/admin/jobs'], ['GET', '/admin/jobs/reconcile'], ['GET', '/admin/health']] as [$m, $p]) { $this->assertSame(404, $this->call($m, $p, null, $o['token'])[0], "owner $p"); }
        $this->container->jobs()->run('reconcile', fn () => ['findings' => 3]);
        [$s, $j] = $this->call('GET', '/admin/jobs', null, $a['token']);
        $row = array_column($j['data'], null, 'job')['reconcile'];
        $this->assertSame([200, 'ok', ['findings' => 3], 5], [$s, $row['last_status'], $row['last_summary'], count($j['data'])]);
        $this->assertSame(['findings' => 3], $this->call('GET', '/admin/jobs/reconcile', null, $a['token'])[1]['data'][0]['summary'], 'history is readable');
        $this->assertSame(404, $this->call('GET', '/admin/jobs/nope', null, $a['token'])[0]);
        [$s, $j] = $this->call('GET', '/admin/health', null, $a['token']);
        $this->assertSame(200, $s);
        $by = array_column($j['data']['checks'], null, 'name');
        $this->assertSame('ok', $by['database']['status']);
        $this->assertSame('ok', $by['migrations']['status']);
        $this->assertSame('ok', $by['ext_pdo_mysql']['status']);
        foreach (['mbstring' => 'fail', 'sodium' => 'warn', 'gd' => 'warn', 'intl' => 'warn'] as $ext => $missing) {
            $this->assertSame(extension_loaded($ext) ? 'ok' : $missing, $by["ext_$ext"]['status'], "extension $ext is reported as it really is");
        }
        $this->assertContains($by['payments']['status'], ['warn', 'ok']);
        $this->assertArrayHasKey('job_housekeeping', $by);
        $blob = json_encode($j['data']);
        foreach (['sk_', 'password', getenv('TEST_DB_PASS') ?: 'lt_test_pw'] as $secret) { $this->assertStringNotContainsString($secret, $blob, 'the health report never contains secrets'); }
    }
}
