<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Unit;

use LeenTrack\Billing\PaystackGateway;
use LeenTrack\Core\ApiException;
use LeenTrack\Core\Logger;
use PHPUnit\Framework\TestCase;

final class PaystackGatewayTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/lt-pg-logs-' . bin2hex(random_bytes(4));
        mkdir($this->logDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->logDir);
    }

    /** @return array{0:string,1:resource,2:string,3:string} baseUrl, process, requestFile, portFile */
    private function server(int $status, string $body): array
    {
        $pf = tempnam(sys_get_temp_dir(), 'hp'); $rf = tempnam(sys_get_temp_dir(), 'hr');
        unlink($pf);
        $proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/fake_http.php', $pf, $rf, (string) $status, $body], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        for ($i = 0; $i < 100 && !is_file($pf); $i++) { usleep(50_000); }
        usleep(100_000);
        return ['http://127.0.0.1:' . (int) file_get_contents($pf), $proc, $rf, $pf];
    }

    private function gw(string $base, string $secret = 'sk_test_FAKEFORTESTS'): PaystackGateway
    {
        return new PaystackGateway($secret, $base, new Logger($this->logDir, 'debug'), 5);
    }

    private function logs(): string
    {
        return implode("\n", array_map(fn ($f) => (string) file_get_contents($f), glob($this->logDir . '/*') ?: []));
    }

    public function testInitializeSendsTheServerChosenAmountWithTheSecretInAHeaderOnly(): void
    {
        [$base, $proc, $rf, $pf] = $this->server(200, json_encode(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'access_code' => 'abc', 'reference' => 'LT-REF1']]));
        $r = $this->gw($base)->initialize(['email' => 'o@example.com', 'amount_minor' => 675, 'currency' => 'USD', 'reference' => 'LT-REF1', 'callback_url' => 'https://app.test/cb', 'metadata' => ['business_id' => 'b1']]);
        proc_close($proc);
        $req = json_decode((string) file_get_contents($rf), true);
        $this->assertSame('POST /transaction/initialize', $req['line']);
        $this->assertStringContainsString('Authorization: Bearer sk_test_FAKEFORTESTS', $req['head']);
        $body = json_decode($req['body'], true);
        $this->assertSame([675, 'USD', 'LT-REF1', 'o@example.com'], [$body['amount'], $body['currency'], $body['reference'], $body['email']], 'amount is an integer in minor units');
        $this->assertStringNotContainsString('TOPSECRET', $req['body']);
        $this->assertSame(['https://checkout.paystack.com/abc', 'abc', 'LT-REF1'], [$r['authorization_url'], $r['access_code'], $r['reference']]);
        @unlink($rf); @unlink($pf);
    }

    public function testVerifyParsesTheProvidersAnswer(): void
    {
        [$base, $proc, $rf, $pf] = $this->server(200, json_encode(['status' => true, 'data' => ['status' => 'success', 'reference' => 'LT-REF1', 'amount' => 675, 'currency' => 'usd', 'id' => 4099260516, 'paid_at' => '2026-03-01T10:00:00.000Z']]));
        $r = $this->gw($base)->verify('LT-REF1');
        proc_close($proc);
        $this->assertSame('GET /transaction/verify/LT-REF1', json_decode((string) file_get_contents($rf), true)['line']);
        $this->assertSame(['success', 'LT-REF1', 675, 'USD', '4099260516'], [$r['status'], $r['reference'], $r['amount_minor'], $r['currency'], $r['transaction_id']], 'currency is upper-cased, ids are strings');
        @unlink($rf); @unlink($pf);
    }

    public function testProviderErrorsBecomeA502WithoutLeakingTheKeyOrTheirBody(): void
    {
        foreach ([[401, '{"status":false,"message":"Invalid key sk_test_FAKEFORTESTS"}'], [200, '{"status":false,"message":"nope"}'], [200, 'not json'], [500, '<html>boom</html>']] as [$st, $body]) {
            [$base, $proc, $rf, $pf] = $this->server($st, $body);
            try { $this->gw($base)->verify('LT-REF1'); $this->fail("accepted $st $body"); } catch (ApiException $e) {
                $this->assertSame(502, $e->status);
                $this->assertStringNotContainsString('TOPSECRET', $e->getMessage() . json_encode($e->fields));
                $this->assertStringNotContainsString('boom', $e->getMessage());
            }
            proc_close($proc); @unlink($rf); @unlink($pf);
        }
        $this->assertStringNotContainsString('TOPSECRET', $this->logs(), 'the key is never logged');
        try { $this->gw('http://127.0.0.1:1')->verify('LT-REF1'); $this->fail('unreachable accepted'); } catch (ApiException $e) { $this->assertSame(['payment_provider_unreachable', 502], [$e->errorCode, $e->status]); }
    }

    public function testMalformedSuccessAnswersAreRefused(): void
    {
        foreach ([['data' => ['authorization_url' => 'https://x.test/a', 'access_code' => 'a', 'reference' => 'OTHER']], ['data' => ['authorization_url' => 'http://insecure.test/a', 'access_code' => 'a', 'reference' => 'LT-REF1']], ['data' => []]] as $data) {
            [$base, $proc, $rf, $pf] = $this->server(200, json_encode(['status' => true] + $data));
            try { $this->gw($base)->initialize(['email' => 'o@example.com', 'amount_minor' => 1, 'currency' => 'NGN', 'reference' => 'LT-REF1', 'callback_url' => null, 'metadata' => []]); $this->fail('accepted ' . json_encode($data)); } catch (ApiException $e) { $this->assertSame(502, $e->status); }
            proc_close($proc); @unlink($rf); @unlink($pf);
        }
    }

    public function testWebhookSignatureIsCheckedOverTheRawBodyInConstantTime(): void
    {
        $g = $this->gw('http://127.0.0.1:1', 'whsec');
        $raw = '{"event":"charge.success","data":{"reference":"X"}}';
        $sig = hash_hmac('sha512', $raw, 'whsec');
        $this->assertTrue($g->signatureValid($raw, $sig));
        $this->assertTrue($g->signatureValid($raw, strtoupper($sig)));
        $this->assertFalse($g->signatureValid($raw . ' ', $sig), 'a single extra byte invalidates it');
        $this->assertFalse($g->signatureValid($raw, null));
        $this->assertFalse($g->signatureValid($raw, ''));
        $this->assertFalse($g->signatureValid($raw, hash_hmac('sha512', $raw, 'other')));
        $this->assertFalse($this->gw('http://127.0.0.1:1', '')->signatureValid($raw, hash_hmac('sha512', $raw, '')), 'with no secret configured nothing is ever valid');
    }

    public function testPlainHttpIsRefusedExceptForLocalhostAndNotConfiguredMeansNoCalls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PaystackGateway('sk', 'http://api.paystack.co', new Logger($this->logDir, 'debug'));
    }

    public function testWithoutASecretNothingIsSentAnywhere(): void
    {
        $g = $this->gw('http://127.0.0.1:1', '');
        $this->assertFalse($g->isConfigured());
        try { $g->verify('LT-REF1'); $this->fail(); } catch (ApiException $e) { $this->assertSame(['billing_not_configured', 503], [$e->errorCode, $e->status]); }
    }
}
