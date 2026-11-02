<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Unit;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesParamsAndMethod(): void
    {
        $r = new Router();
        $r->get('/products/{id}', fn () => 1);
        $m = $r->match('GET', '/products/abc-123');
        $this->assertSame(['id' => 'abc-123'], $m['params']);
    }

    public function testParamCannotContainSlashOrTraversal(): void
    {
        $r = new Router();
        $r->get('/products/{id}', fn () => 1);
        foreach (['/products/a/b', '/products/../x', '/products/'] as $p) {
            try {
                $r->match('GET', $p);
                $this->fail("matched $p");
            } catch (ApiException $e) {
                $this->assertSame(404, $e->status);
            }
        }
    }

    public function testWrongMethodIs405WithAllow(): void
    {
        $r = new Router();
        $r->get('/x', fn () => 1);
        try {
            $r->match('POST', '/x');
            $this->fail();
        } catch (ApiException $e) {
            $this->assertSame(405, $e->status);
            $this->assertStringContainsString('GET', $e->headers['Allow']);
        }
    }

    public function testRouteTableExposesPublicFlag(): void
    {
        $r = new Router();
        $r->get('/a', fn () => 1, [], true);
        $r->post('/b', fn () => 1);
        $this->assertSame([true, false], array_column($r->table(), 'public'));
    }
}
