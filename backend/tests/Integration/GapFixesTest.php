<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Clock;
use LeenTrack\Core\Uuid;
use LeenTrack\Mail\SmtpMailer;

/** Behaviour that used to be a documented gap (docs/09) and is now fixed. */
final class GapFixesTest extends ApiTestCase
{
    private function shop(string $email = 'owner@example.com'): array
    {
        $o = $this->signup($email);
        $this->setPlan($o['business_id'], 'growth');
        return $o;
    }

    private function main(array $o): string
    {
        return (string) $this->pdo->query("SELECT id FROM locations WHERE business_id='{$o['business_id']}' AND is_default=1")->fetchColumn();
    }

    private function product(array $o, string $name, string $cost = '10.00', int $stock = 50): array
    {
        [$s, $j] = $this->call('POST', '/products', ['name' => $name, 'selling_price' => '50.00', 'cost_price' => $cost, 'stock_quantity' => $stock], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        return $j['data'];
    }

    // ================================================================================================ G-38
    public function testAnotherBusinessesOutletIdIsNotFoundOnEveryFilter(): void
    {
        $a = $this->shop('a@example.com'); $b = $this->shop('b@example.com');
        $foreign = $this->main($a);
        $own = $this->main($b);
        foreach (['/sales?outlet_id=', '/expenses?outlet_id=', '/profit?outlet_id=', '/dashboard?outlet_id=', '/reports/sales?outlet_id=', '/reports/inventory?outlet_id=', '/purchase-orders?outlet_id=', '/transfers?location_id=', '/stock/valuation?location_id='] as $path) {
            $this->assertSame(404, $this->call('GET', $path . $foreign, null, $b['token'], $b['business_id'])[0], "foreign outlet on $path");
            $this->assertSame(200, $this->call('GET', $path . $own, null, $b['token'], $b['business_id'])[0], "own outlet on $path");
        }
        $this->assertSame(404, $this->call('GET', '/profit?outlet_id=' . Uuid::v4(), null, $b['token'], $b['business_id'])[0], 'an id that exists nowhere is the same answer');
    }

    // ================================================================================================ G-41 / G-48
    public function testPurchaseAndTransferDateFiltersUseTheBusinessTimeZone(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'A');
        $br = $this->call('POST', '/locations', ['name' => 'Branch'], $o['token'], $o['business_id'])[1]['data']['id'];
        $po = $this->call('POST', '/purchase-orders', ['items' => [['product_id' => $a['id'], 'quantity' => 1]]], $o['token'], $o['business_id'])[1]['data'];
        $tr = $this->call('POST', '/transfers', ['from_location_id' => $this->main($o), 'to_location_id' => $br, 'items' => [['product_id' => $a['id'], 'quantity' => 1]]], $o['token'], $o['business_id'])[1]['data'];
        // 23:30 UTC on 1 March is 00:30 on 2 March in Lagos (UTC+1)
        $this->pdo->exec("UPDATE purchase_orders SET created_at='2026-03-01 23:30:00' WHERE id='{$po['id']}'");
        $this->pdo->exec("UPDATE transfers SET created_at='2026-03-01 23:30:00' WHERE id='{$tr['id']}'");
        foreach (['/purchase-orders', '/transfers'] as $path) {
            $n = fn (string $qs) => $this->call('GET', $path . $qs, null, $o['token'], $o['business_id'])[1]['meta']['total'];
            $this->assertSame([0, 1, 1, 0], [$n('?from=2026-03-01&to=2026-03-01'), $n('?from=2026-03-02&to=2026-03-02'), $n('?from=2026-03-01&to=2026-03-02'), $n('?from=2026-03-03')], $path);
        }
    }

    // ================================================================================================ G-49
    public function testCustomersReportIsTheSourcesCustomersExport(): void
    {
        $o = $this->shop();
        $grp = $this->call('POST', '/customer-groups', ['name' => 'VIP', 'discount_percentage' => 5], $o['token'], $o['business_id'])[1]['data'];
        $r1 = $this->call('POST', '/customers', ['name' => '=cmd|calc', 'email' => 'x@y.test', 'phone' => '08030000000', 'group_id' => $grp['id']], $o['token'], $o['business_id']);
        $this->assertSame(201, $r1[0], json_encode($r1[1]));
        $c1 = $r1[1]['data'];
        $c2 = $this->call('POST', '/customers', ['name' => 'Bola, "B"'], $o['token'], $o['business_id'])[1]['data'];
        $c3 = $this->call('POST', '/customers', ['name' => 'Gone'], $o['token'], $o['business_id'])[1]['data'];
        $this->call('DELETE', "/customers/{$c3['id']}", null, $o['token'], $o['business_id']);
        $p = $this->product($o, 'A');
        $this->call('POST', '/sales', ['client_operation_id' => Uuid::v4(), 'customer_id' => $c1['id'], 'items' => [['product_id' => $p['id'], 'quantity' => 2]], 'payment' => ['mode' => 'full', 'method' => 'cash', 'amount' => '1000']], $o['token'], $o['business_id']);
        [$s, $j] = $this->call('GET', '/reports/customers', null, $o['token'], $o['business_id']);
        $this->assertSame(200, $s, json_encode($j));
        $this->assertSame(['=cmd|calc', 'Bola, "B"'], array_column($j['data'], 'name'), 'archived customers are not exported');
        $row = array_column($j['data'], null, 'name')['=cmd|calc'];
        $this->assertSame(['VIP', 1, 'x@y.test'], [$row['group'], $row['visits'], $row['email']]);
        $this->assertSame($row['total_spent'], $j['meta']['summary']['total_spent']);
        $this->assertSame(2, $j['meta']['summary']['customers']);
        $req = \LeenTrack\Core\Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/reports/customers?format=csv', 'REMOTE_ADDR' => $this->ip, 'HTTP_AUTHORIZATION' => "Bearer {$o['token']}", 'HTTP_X_BUSINESS_ID' => $o['business_id']], '');
        $res = $this->kernel->handle($req);
        $rows = \LeenTrack\Core\Csv::parse(substr($res->body, 3));
        $this->assertSame(['name', 'email', 'phone', 'group', 'total_spent', 'visits', 'last_visit', 'loyalty_points', 'joined'], $rows[0]);
        $this->assertSame(["'=cmd|calc", 'Bola, "B"'], [$rows[1][0], $rows[2][0]], 'formula neutralised, commas and quotes kept');
        $this->assertSame(422, $this->call('GET', '/reports/customers?period=month', null, $o['token'], $o['business_id'])[0], 'a customer list has no period');
        $cashier = $this->addStaff($o, 'cash@example.com', 'cashier');
        $this->assertSame(403, $this->call('GET', '/reports/customers', null, $cashier['token'], $o['business_id'])[0]);
        $b = $this->shop('b@example.com');
        $this->assertSame([], $this->call('GET', '/reports/customers', null, $b['token'], $b['business_id'])[1]['data']);
    }

    // ================================================================================================ G-40 supplier payments
    private function receivedOrder(array $o, array $sup, int $qty, string $cost, int $receive): array
    {
        $a = $this->product($o, 'P' . bin2hex(random_bytes(2)));
        $po = $this->call('POST', '/purchase-orders', ['supplier_id' => $sup['id'], 'items' => [['product_id' => $a['id'], 'quantity' => $qty, 'unit_cost' => $cost]]], $o['token'], $o['business_id'])[1]['data'];
        $this->call('POST', "/purchase-orders/{$po['id']}/order", null, $o['token'], $o['business_id']);
        if ($receive > 0) {
            $this->assertSame(201, $this->call('POST', "/purchase-orders/{$po['id']}/receive", ['client_operation_id' => Uuid::v4(), 'items' => [['item_id' => $po['items'][0]['id'], 'quantity' => $receive]]], $o['token'], $o['business_id'])[0]);
        }
        return $po;
    }

    private function pay(array $o, string $poId, string $amount, array $extra = []): array
    {
        return $this->call('POST', "/purchase-orders/$poId/payments", $extra + ['client_operation_id' => Uuid::v4(), 'amount' => $amount, 'method' => 'transfer', 'reference' => 'TRX-1'], $o['token'], $o['business_id']);
    }

    public function testSupplierPaymentsAreBoundedByGoodsReceivedAndTrackBalances(): void
    {
        $o = $this->shop();
        $sup = $this->call('POST', '/suppliers', ['name' => 'Acme'], $o['token'], $o['business_id'])[1]['data'];
        $this->assertSame('0.00', $sup['balance_owed']);
        $po = $this->receivedOrder($o, $sup, 10, '50.00', 0);                                    // ordered 500, nothing received
        [$s, $j] = $this->pay($o, $po['id'], '100');
        $this->assertSame([409, 'nothing_due'], [$s, $j['error']['code']], 'no prepayment: you pay for goods that arrived');
        $this->call('POST', "/purchase-orders/{$po['id']}/receive", ['client_operation_id' => Uuid::v4(), 'items' => [['item_id' => $po['items'][0]['id'], 'quantity' => 4]]], $o['token'], $o['business_id']);   // 4 x 50 = 200 received
        $this->assertSame('200.00', $this->call('GET', "/suppliers/{$sup['id']}", null, $o['token'], $o['business_id'])[1]['data']['balance_owed']);
        [$s, $j] = $this->pay($o, $po['id'], '75.50', ['reference' => 'TRX-9', 'notes' => 'part']);
        $this->assertSame([201, '75.50', '124.50', 1, 'TRX-9'], [$s, $j['data']['order']['paid'], $j['data']['order']['balance_due'], count($j['data']['order']['payments']), $j['data']['order']['payments'][0]['reference']], json_encode($j));
        [$s, $j] = $this->pay($o, $po['id'], '124.51');
        $this->assertSame([422, true], [$s, isset($j['error']['fields']['amount'])], 'cannot pay more than is owed');
        foreach (['0', '-5', '1.005', 'x', ''] as $bad) { $this->assertSame(422, $this->pay($o, $po['id'], $bad)[0], "amount '$bad'"); }
        $this->assertSame(422, $this->pay($o, $po['id'], '1', ['method' => 'bitcoin'])[0]);
        $this->assertSame(422, $this->pay($o, $po['id'], '1', ['supplier_id' => Uuid::v4()])[0], 'the supplier is taken from the order');
        $this->assertSame(201, $this->pay($o, $po['id'], '124.50')[0]);
        $this->assertSame('nothing_due', $this->pay($o, $po['id'], '1')[1]['error']['code'], 'settled');
        $this->assertSame('0.00', $this->call('GET', "/suppliers/{$sup['id']}", null, $o['token'], $o['business_id'])[1]['data']['balance_owed']);
        // a second delivery re-opens the balance
        $this->call('POST', "/purchase-orders/{$po['id']}/receive", ['client_operation_id' => Uuid::v4(), 'items' => [['item_id' => $po['items'][0]['id'], 'quantity' => 6]]], $o['token'], $o['business_id']);
        $po2 = $this->receivedOrder($o, $sup, 2, '10.00', 2);                                    // another 20 owed
        [, $st] = $this->call('GET', "/suppliers/{$sup['id']}/statement", null, $o['token'], $o['business_id']);
        $t = $st['data']['totals'];
        $this->assertSame(['520.00', '200.00', '320.00'], [$t['received'], $t['paid'], $t['balance_owed']], '500 + 20 received, 200 paid');
        $this->assertSame(2, count($st['data']['orders']));
        $this->assertSame(2, count($st['data']['payments']));
        $this->assertSame('320.00', $this->call('GET', "/suppliers/{$sup['id']}", null, $o['token'], $o['business_id'])[1]['data']['balance_owed']);
        // payments do not touch profit (buying stock is not an expense)
        $this->assertSame('0.00', $this->call('GET', '/profit?period=month', null, $o['token'], $o['business_id'])[1]['data']['expenses']);
        // reports: paid and payable
        $r = $this->call('GET', '/reports/purchases?period=month', null, $o['token'], $o['business_id'])[1]['meta']['summary'];
        $this->assertSame(['200.00', '320.00'], [$r['paid_to_suppliers'], $r['payable_to_suppliers']]);
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='purchase.payment'")->fetchColumn());
        // an order without a supplier has nobody to pay
        $a = $this->product($o, 'NoSup');
        $po3 = $this->call('POST', '/purchase-orders', ['items' => [['product_id' => $a['id'], 'quantity' => 1]]], $o['token'], $o['business_id'])[1]['data'];
        $this->call('POST', "/purchase-orders/{$po3['id']}/order", null, $o['token'], $o['business_id']);
        $this->call('POST', "/purchase-orders/{$po3['id']}/receive", ['client_operation_id' => Uuid::v4(), 'items' => [['item_id' => $po3['items'][0]['id'], 'quantity' => 1]]], $o['token'], $o['business_id']);
        $this->assertSame('no_supplier', $this->pay($o, $po3['id'], '1')[1]['error']['code']);
        unset($po2);
    }

    public function testSupplierPaymentIdempotencyRolesAndIsolation(): void
    {
        $a = $this->shop('a@example.com'); $b = $this->shop('b@example.com');
        $sup = $this->call('POST', '/suppliers', ['name' => 'Acme'], $a['token'], $a['business_id'])[1]['data'];
        $po = $this->receivedOrder($a, $sup, 10, '10.00', 10);                                   // 100 owed
        $op = Uuid::v4();
        $go = fn (string $amt, array $x = []) => $this->call('POST', "/purchase-orders/{$po['id']}/payments", array_replace(['client_operation_id' => $op, 'amount' => $amt, 'method' => 'cash'], $x), $a['token'], $a['business_id']);
        [$s1, $j1] = $go('40'); [$s2, $j2] = $go('40');
        $this->assertSame([201, 200, true, $j1['data']['payment_id']], [$s1, $s2, $j2['meta']['replayed'], $j2['data']['payment_id']]);
        $this->assertSame('40.00', $j2['data']['order']['paid'], 'the retry did not pay twice');
        foreach (['amount' => $go('41'), 'method' => $go('40', ['method' => 'card']), 'reference' => $go('40', ['reference' => 'other'])] as $what => [$s, $j]) { $this->assertSame([409, 'idempotency_conflict'], [$s, $j['error']['code'] ?? null], "changed $what"); }
        $po2 = $this->receivedOrder($a, $sup, 1, '10.00', 1);
        $this->assertSame(409, $this->call('POST', "/purchase-orders/{$po2['id']}/payments", ['client_operation_id' => $op, 'amount' => '40', 'method' => 'cash'], $a['token'], $a['business_id'])[0], 'same id on another order');
        $key = Uuid::v4();
        $h = fn () => $this->call('POST', "/purchase-orders/{$po['id']}/payments", ['amount' => '10', 'method' => 'cash'], $a['token'], $a['business_id'], ['Idempotency-Key' => $key]);
        $this->assertSame([201, 200], [$h()[0], $h()[0]]);
        $this->assertSame('50.00', $this->call('GET', "/purchase-orders/{$po['id']}", null, $a['token'], $a['business_id'])[1]['data']['paid']);
        $cashier = $this->addStaff($a, 'cash@example.com', 'cashier');
        $this->assertSame(403, $this->call('POST', "/purchase-orders/{$po['id']}/payments", ['client_operation_id' => Uuid::v4(), 'amount' => '1'], $cashier['token'], $a['business_id'])[0]);
        $this->assertSame(403, $this->call('GET', "/suppliers/{$sup['id']}/statement", null, $cashier['token'], $a['business_id'])[0]);
        $this->assertSame(404, $this->call('POST', "/purchase-orders/{$po['id']}/payments", ['client_operation_id' => Uuid::v4(), 'amount' => '1'], $b['token'], $b['business_id'])[0]);
        $this->assertSame(404, $this->call('GET', "/suppliers/{$sup['id']}/statement", null, $b['token'], $b['business_id'])[0]);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM supplier_payments WHERE client_operation_id='$op'")->fetchColumn());
    }

    public function testParallelSupplierPaymentsNeverExceedWhatIsOwed(): void
    {
        $o = $this->shop();
        $sup = $this->call('POST', '/suppliers', ['name' => 'Acme'], $o['token'], $o['business_id'])[1]['data'];
        $po = $this->receivedOrder($o, $sup, 10, '10.00', 10);                                   // 100 owed
        $env = array_merge($_ENV, getenv(), ['TEST_DB_NAME' => getenv('TEST_DB_NAME'), 'TEST_DB_USER' => getenv('TEST_DB_USER'), 'TEST_DB_PASS' => getenv('TEST_DB_PASS'), 'TEST_DB_SOCKET' => getenv('TEST_DB_SOCKET')]);
        $startAt = (string) (microtime(true) + 2.0);
        $procs = [];
        for ($w = 0; $w < 8; $w++) {
            $reqs = [];
            for ($k = 0; $k < 5; $k++) { $reqs[] = ['method' => 'POST', 'path' => "/purchase-orders/{$po['id']}/payments", 'body' => ['client_operation_id' => Uuid::v4(), 'amount' => '5', 'method' => 'cash']]; }
            $pipes = [];
            $procs[] = [proc_open([PHP_BINARY, __DIR__ . '/../bin/request_worker.php', $o['token'], $o['business_id'], $startAt, json_encode($reqs)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env), $pipes];
        }
        $codes = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); proc_close($proc);
            $this->assertSame('', trim($err), $err);
            foreach (array_filter(explode("\n", trim($out))) as $line) { $codes[] = (int) substr($line, 0, 3); }
        }
        $c = array_count_values($codes); ksort($c);
        $this->assertSame([201 => 20, 409 => 20], $c, '100 owed, 40 attempts of 5: exactly twenty fit, the rest find nothing left to pay: ' . json_encode($c));
        $this->assertSame('100.00', number_format((float) $this->pdo->query('SELECT SUM(amount) FROM supplier_payments')->fetchColumn(), 2, '.', ''), 'never more than what was owed');
    }

    // ================================================================================================ G-22 SMTP
    /** @return array{0:int,1:resource,2:string,3:string} */
    private function fakeServer(string $mode = 'plain'): array
    {
        $dir = sys_get_temp_dir();
        $portFile = tempnam($dir, 'smtpport'); $out = tempnam($dir, 'smtpout');
        unlink($portFile);
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/fake_smtp.php', $portFile, $out, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        for ($i = 0; $i < 100 && !is_file($portFile); $i++) { usleep(50_000); }
        $this->assertFileExists($portFile);
        usleep(100_000);
        return [(int) file_get_contents($portFile), $proc, $out, $portFile];
    }

    private function mailer(int $port): SmtpMailer
    {
        return new SmtpMailer('127.0.0.1', $port, 'none', 'user@x.test', 's3cret', 'LeenTrack <no-reply@example.com>', $this->app->logger(), 5);
    }

    public function testSmtpMailerSpeaksSmtpAuthenticatesAndEscapesTheMessage(): void
    {
        [$port, $proc, $out, $pf] = $this->fakeServer();
        $this->mailer($port)->send('ada@example.com', 'Verify — your e-mail', "Hello\n.\nA line that starts with a dot above.\nhttps://x.test/verify?token=abc");
        proc_close($proc);
        $log = json_decode((string) file_get_contents($out), true);
        $cmds = array_values(array_filter($log, 'is_string'));
        $this->assertSame('EHLO leentrack', $cmds[0]);
        $this->assertStringStartsWith('AUTH PLAIN ', $cmds[1]);
        $this->assertSame("\0user@x.test\0s3cret", base64_decode(substr($cmds[1], 11)));
        $this->assertSame(['MAIL FROM:<no-reply@example.com>', 'RCPT TO:<ada@example.com>', 'DATA', 'QUIT'], array_slice($cmds, 2));
        $msg = array_values(array_filter($log, 'is_array'))[0]['DATA_BODY'];
        $this->assertStringContainsString("From: LeenTrack <no-reply@example.com>\r\n", $msg);
        $this->assertStringContainsString("To: <ada@example.com>\r\n", $msg);
        $this->assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Verify — your e-mail') . '?=', $msg);
        $body = base64_decode(str_replace("\r\n", '', substr($msg, strpos($msg, "\r\n\r\n") + 4)));
        $this->assertStringContainsString("Hello\r\n..\r\nA line that starts with a dot above.", $body, 'a lone "." line is dot-stuffed so it cannot end the message early');
        @unlink($out); @unlink($pf);
    }

    public function testSmtpMailerUsesAuthLoginWhenPlainIsNotOffered(): void
    {
        [$port, $proc, $out, $pf] = $this->fakeServer('login');
        $this->mailer($port)->send('ada@example.com', 'Hi', 'Body');
        proc_close($proc);
        $cmds = array_values(array_filter(json_decode((string) file_get_contents($out), true), 'is_string'));
        $this->assertContains('AUTH LOGIN', $cmds);
        $this->assertContains(base64_encode('user@x.test'), $cmds);
        $this->assertContains(base64_encode('s3cret'), $cmds);
        @unlink($out); @unlink($pf);
    }

    public function testSmtpFailuresAreLoggedWithoutSecretsAndNeverBreakTheCaller(): void
    {
        $logFile = $this->app->config['log_dir'] . '/' . gmdate('Y-m-d') . '.log';
        // rejected recipient
        [$port, $proc, $out, $pf] = $this->fakeServer('reject-rcpt');
        $this->mailer($port)->send('nobody@example.com', 'Hi', 'Body');
        proc_close($proc);
        // nothing listening
        $this->mailer(1)->send('ada@example.com', 'Hi', 'Body');
        $this->addToAssertionCount(1);          // reaching here = no exception escaped
        foreach (["bad\r\nBcc: evil@x.test", "x\ny"] as $inj) {
            try { $this->mailer($port)->send('ada@example.com', $inj, 'Body'); $this->fail('header injection accepted'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        try { $this->mailer($port)->send("a@x.test\r\nRCPT TO:<evil@x.test>", 'Hi', 'Body'); $this->fail('recipient injection accepted'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        try { $this->mailer($port)->send('not-an-address', 'Hi', 'Body'); $this->fail('invalid address accepted'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        foreach (glob($this->app->config['log_dir'] . '/*') ?: [] as $f) {
            $c = (string) file_get_contents($f);
            $this->assertStringNotContainsString('s3cret', $c, 'the SMTP password is never logged');
        }
        @unlink($out); @unlink($pf);
    }

    public function testContainerPicksTheConfiguredMailDriver(): void
    {
        $this->boot(['mail_driver' => 'smtp', 'smtp' => ['host' => 'smtp.test', 'port' => 587, 'secure' => 'tls', 'user' => 'u', 'pass' => 'p']]);
        $c = new \LeenTrack\Services\Container($this->app);
        $this->assertInstanceOf(SmtpMailer::class, $c->mailer());
        $this->boot(['mail_driver' => 'native']);
        $this->assertInstanceOf(\LeenTrack\Mail\NativeMailer::class, (new \LeenTrack\Services\Container($this->app))->mailer());
    }

    // ================================================================================================ G-50
    public function testReportSizeLimitIsEnforcedAndConfigurable(): void
    {
        $this->boot(['report_max_rows' => 3]);
        $o = $this->shop();
        $p = $this->product($o, 'A');
        $sell = fn () => $this->call('POST', '/sales', ['client_operation_id' => Uuid::v4(), 'items' => [['product_id' => $p['id'], 'quantity' => 1]], 'payment' => ['mode' => 'full', 'method' => 'cash', 'amount' => '100']], $o['token'], $o['business_id']);
        for ($i = 0; $i < 3; $i++) { $sell(); }
        $this->assertSame(200, $this->call('GET', '/reports/sales?period=month', null, $o['token'], $o['business_id'])[0], 'exactly at the limit is fine');
        $sell();
        [$s, $j] = $this->call('GET', '/reports/sales?period=month', null, $o['token'], $o['business_id']);
        $this->assertSame([413, 'range_too_large'], [$s, $j['error']['code']]);
        $this->assertSame(413, $this->call('GET', '/reports/sales?period=month&format=csv', null, $o['token'], $o['business_id'])[0]);
        $this->assertSame(200, $this->call('GET', '/reports/sales?from=2000-01-01&to=2000-01-02', null, $o['token'], $o['business_id'])[0], 'a smaller period still works');
        for ($i = 0; $i < 4; $i++) { $this->call('POST', '/customers', ['name' => "C$i"], $o['token'], $o['business_id']); }
        $this->assertSame(413, $this->call('GET', '/reports/customers', null, $o['token'], $o['business_id'])[0]);
    }

    // ================================================================================================ G-43
    public function testGoodsReturnedToASupplierLeaveStockWithAnAuditedReason(): void
    {
        $o = $this->shop();
        $a = $this->product($o, 'A', '10.00', 10);
        $loc = $this->main($o);
        [$s, $j] = $this->call('POST', '/stock/adjustments', ['product_id' => $a['id'], 'location_id' => $loc, 'delta' => -4, 'reason' => 'supplier_return', 'note' => 'damaged on arrival, PO-000001'], $o['token'], $o['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        $this->assertSame(6, (int) $this->pdo->query("SELECT stock_quantity FROM products WHERE id='{$a['id']}'")->fetchColumn());
        $this->assertSame('supplier_return', $this->pdo->query("SELECT reason FROM stock_movements WHERE product_id='{$a['id']}' ORDER BY id DESC LIMIT 1")->fetchColumn());
        $this->assertSame(422, $this->call('POST', '/stock/adjustments', ['product_id' => $a['id'], 'location_id' => $loc, 'delta' => 4, 'reason' => 'supplier_return'], $o['token'], $o['business_id'])[0], 'a return can only remove stock');
    }
}
