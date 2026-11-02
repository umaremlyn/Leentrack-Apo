<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Unit;

use LeenTrack\Core\App;
use LeenTrack\Core\Request;
use LeenTrack\Http\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    private function app(array $over = []): App
    {
        return new App(array_merge([
            'env' => 'testing', 'debug' => false, 'url' => '', 'version' => 't', 'api_prefix' => '/api/v1',
            'log_dir' => sys_get_temp_dir() . '/lt-test-logs', 'log_level' => 'error',
            'cors_origins' => ['https://track.example.com'], 'trusted_proxies' => [], 'rate_limit_enabled' => false,
            'db' => ['host' => '', 'port' => 0, 'name' => '', 'user' => '', 'pass' => '', 'socket' => ''],
        ], $over));
    }

    private function call(string $method, string $uri, array $server = []): array
    {
        $req = Request::fromGlobals(array_merge(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1'], $server), '');
        $res = (new Kernel($this->app()))->handle($req);
        return [$res, json_decode($res->body, true)];
    }

    public function testHealthEnvelopeAndSecurityHeaders(): void
    {
        [$res, $j] = $this->call('GET', '/api/v1/health');
        $this->assertSame(200, $res->status);
        $this->assertTrue($j['ok']);
        $this->assertSame('not_configured', $j['data']['db']);
        $this->assertNotEmpty($j['meta']['request_id']);
        $this->assertSame('nosniff', $res->headers['X-Content-Type-Options']);
        $this->assertSame('no-store', $res->headers['Cache-Control']);
        $this->assertArrayHasKey('X-Request-Id', $res->headers);
    }

    public function testUnknownRouteAndOutsidePrefixAre404Envelope(): void
    {
        foreach (['/api/v1/nope', '/admin', '/'] as $p) {
            [$res, $j] = $this->call('GET', $p);
            $this->assertSame(404, $res->status);
            $this->assertFalse($j['ok']);
            $this->assertSame('not_found', $j['error']['code']);
        }
    }

    public function testMethodNotAllowed(): void
    {
        [$res, $j] = $this->call('DELETE', '/api/v1/health');
        $this->assertSame(405, $res->status);
        $this->assertStringContainsString('GET', $res->headers['Allow']);
    }

    public function testCorsAllowListOnlyAndPreflight(): void
    {
        [$ok] = $this->call('OPTIONS', '/api/v1/health', ['HTTP_ORIGIN' => 'https://track.example.com']);
        $this->assertSame(204, $ok->status);
        $this->assertSame('https://track.example.com', $ok->headers['Access-Control-Allow-Origin']);
        [$bad] = $this->call('GET', '/api/v1/health', ['HTTP_ORIGIN' => 'https://evil.example']);
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $bad->headers);
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $ok->headers);
    }

    public function testProductionRefusesDebug(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->app(['env' => 'production', 'debug' => true]);
    }

    public function testUnhandledErrorIsGenericAndLogged(): void
    {
        $kernel = new Kernel($this->app());
        $kernel->router()->get('/boom', function () { throw new \PDOException("SQLSTATE[42S02]: secret_table missing"); });
        $req = Request::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/boom', 'REMOTE_ADDR' => '127.0.0.1'], '');
        $res = $kernel->handle($req);
        $this->assertSame(500, $res->status);
        $this->assertStringNotContainsString('secret_table', $res->body);
        $this->assertStringNotContainsString('SQLSTATE', $res->body);
        $this->assertSame('server_error', json_decode($res->body, true)['error']['code']);
    }

    /** Route-table security guard (grows as routes are added): every public route must be explicitly marked. */
    public function testOnlyExplicitlyPublicRoutesExist(): void
    {
        $public = array_filter((new Kernel($this->app()))->router()->table(), fn ($r) => $r['public']);
        $this->assertSame(['/health'], array_values(array_column($public, 'pattern')));
    }
}
