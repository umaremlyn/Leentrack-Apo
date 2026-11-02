<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Unit;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    /** @param array<string,string> $extra */
    private function req(string $body, array $extra = [], array $trusted = []): Request
    {
        return Request::fromGlobals(array_merge([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/v1/x?a=1&b[]=2', 'REMOTE_ADDR' => '203.0.113.9',
            'CONTENT_TYPE' => 'application/json',
        ], $extra), $body, $trusted);
    }

    public function testParsesQueryHeadersAndJson(): void
    {
        $r = $this->req('{"a":1}', ['HTTP_X_BUSINESS_ID' => 'b1']);
        $this->assertSame(['a' => '1', 'b' => ['2']], $r->query);
        $this->assertSame('b1', $r->header('X-Business-Id'));
        $this->assertSame(['a' => 1], $r->json());
    }

    public function testRejectsBadJsonWrongTypeAndListBodies(): void
    {
        foreach ([['{bad', 400], ['[1,2]', 400]] as [$body, $status]) {
            try { $this->req($body)->json(); $this->fail(); } catch (ApiException $e) { $this->assertSame($status, $e->status); }
        }
        try { $this->req('{"a":1}', ['CONTENT_TYPE' => 'text/plain'])->json(); $this->fail(); } catch (ApiException $e) { $this->assertSame(415, $e->status); }
    }

    public function testBodySizeCap(): void
    {
        try { $this->req(str_repeat('a', Request::MAX_BODY + 1))->json(); $this->fail(); } catch (ApiException $e) { $this->assertSame(413, $e->status); }
    }

    public function testForwardedForOnlyFromTrustedProxy(): void
    {
        $h = ['HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.1'];
        $this->assertSame('203.0.113.9', $this->req('', $h)->ip);                       // spoof attempt ignored
        $this->assertSame('198.51.100.7', $this->req('', $h, ['203.0.113.9'])->ip);     // trusted proxy
        $this->assertSame('203.0.113.9', $this->req('', ['HTTP_X_FORWARDED_FOR' => 'not-an-ip'], ['203.0.113.9'])->ip);
    }

    public function testBearerParsing(): void
    {
        $this->assertSame('abcdefghijklmnop1234', $this->req('', ['HTTP_AUTHORIZATION' => 'Bearer abcdefghijklmnop1234'])->bearerToken());
        $this->assertNull($this->req('', ['HTTP_AUTHORIZATION' => 'Basic abc'])->bearerToken());
        $this->assertNull($this->req('', ['HTTP_AUTHORIZATION' => 'Bearer short'])->bearerToken());
    }
}
