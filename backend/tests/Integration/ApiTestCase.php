<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\App;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Migrator;
use LeenTrack\Core\Request;
use LeenTrack\Http\Kernel;
use LeenTrack\Mail\FileMailer;
use LeenTrack\Services\Container;

/** Drives the real Kernel (routing, middleware, services, MySQL) in-process — no mocks of our own code. */
abstract class ApiTestCase extends DbTestCase
{
    protected Kernel $kernel;
    protected Container $container;
    protected App $app;
    protected string $ip = '203.0.113.10';
    public const PW = 'Correct-Horse-9';

    /** @param array<string,mixed> $overrides */
    protected function boot(array $overrides = []): void
    {
        $cfg = array_replace_recursive([
            'env' => 'testing', 'debug' => false, 'url' => '', 'version' => 't', 'api_prefix' => '/api/v1',
            'log_dir' => sys_get_temp_dir() . '/lt-api-test-logs', 'log_level' => 'error',
            'web_url' => 'https://web.test', 'mail_from' => 'x@test', 'mail_driver' => 'file',
            'cors_origins' => [], 'trusted_proxies' => [], 'rate_limit_enabled' => false,
            'auth' => ['access_ttl' => 900, 'refresh_ttl' => 2592000, 'require_email_verification' => true, 'invite_ttl' => 604800, 'reset_ttl' => 3600, 'verify_ttl' => 172800],
            'db' => self::cfg(),
        ], $overrides);
        $this->app = new App($cfg);
        $this->app->setPdo($this->pdo);
        $this->container = new Container($this->app);
        $this->container->setMailer(new FileMailer());
        $this->kernel = new Kernel($this->app, $this->container);
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->up();
        FileMailer::$outbox = [];
        Clock::freeze(null);
        $this->boot();
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,string> $headers
     * @return array{0:int,1:array<string,mixed>,2:\LeenTrack\Core\Response}
     */
    protected function call(string $method, string $path, ?array $body = null, ?string $token = null, ?string $businessId = null, array $headers = []): array
    {
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/api/v1' . $path, 'REMOTE_ADDR' => $this->ip, 'CONTENT_TYPE' => 'application/json'];
        if ($token !== null) { $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token; }
        if ($businessId !== null) { $server['HTTP_X_BUSINESS_ID'] = $businessId; }
        foreach ($headers as $k => $v) { $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v; }
        $req = Request::fromGlobals($server, $body === null ? '' : json_encode($body), []);
        $res = $this->kernel->handle($req);
        return [$res->status, json_decode($res->body, true) ?? [], $res];
    }

    protected function tokenFromMail(string $to, string $param = 'token'): string
    {
        $m = FileMailer::last($to);
        $this->assertNotNull($m, "no mail sent to $to");
        $this->assertSame(1, preg_match('/[?&]' . $param . '=([A-Za-z0-9_-]+)/', $m['text'], $x), 'no token in mail: ' . $m['text']);
        return $x[1];
    }

    /** Registers + verifies + logs in. @return array{token:string,refresh:string,user_id:string,business_id:string,email:string,session_id:string} */
    protected function signup(string $email, string $business = 'Acme Stores', string $pw = self::PW): array
    {
        [$s] = $this->call('POST', '/auth/register', ['email' => $email, 'password' => $pw, 'full_name' => 'Test Owner', 'business_name' => $business, 'business_type' => 'retail', 'currency' => 'NGN']);
        $this->assertSame(202, $s);
        [$s] = $this->call('POST', '/auth/verify-email', ['token' => $this->tokenFromMail($email)]);
        $this->assertSame(200, $s);
        return $this->login($email, $pw);
    }

    /** @return array{token:string,refresh:string,user_id:string,business_id:string,email:string,session_id:string} */
    protected function login(string $email, string $pw = self::PW): array
    {
        [$s, $j] = $this->call('POST', '/auth/login', ['email' => $email, 'password' => $pw, 'device' => ['id' => 'device-0001', 'name' => 'Test', 'platform' => 'linux']]);
        $this->assertSame(200, $s, json_encode($j));
        $d = $j['data'];
        return ['token' => $d['access_token'], 'refresh' => $d['refresh_token'], 'user_id' => $d['user']['id'], 'business_id' => $d['memberships'][0]['business_id'] ?? '', 'email' => $email, 'session_id' => $d['session_id']];
    }

    protected function setPlan(string $businessId, string $plan, ?string $expiry = null, string $status = 'active'): void
    {
        $this->pdo->prepare('UPDATE businesses SET plan = ?, plan_status = ?, plan_expiry = ? WHERE id = ?')->execute([$plan, $status, $expiry, $businessId]);
    }

    /** Owner invites + invitee accepts as a brand-new user. @return array{token:string,user_id:string,member_id:string} */
    protected function addStaff(array $owner, string $email, string $role): array
    {
        [$s, $j] = $this->call('POST', '/staff', ['name' => 'Staff Person', 'email' => $email, 'role' => $role], $owner['token'], $owner['business_id']);
        $this->assertSame(201, $s, json_encode($j));
        $memberId = $j['data']['id'];
        [$s, $j] = $this->call('POST', '/invites/accept', ['token' => $this->tokenFromMail($email), 'password' => self::PW, 'full_name' => 'Staff Person']);
        $this->assertSame(200, $s, json_encode($j));
        $l = $this->login($email);
        return ['token' => $l['token'], 'user_id' => $l['user_id'], 'member_id' => $memberId];
    }
}
