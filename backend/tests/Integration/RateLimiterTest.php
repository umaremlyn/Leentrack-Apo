<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Migrator;
use LeenTrack\Core\RateLimiter;

final class RateLimiterTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->up();
    }

    public function testBlocksAfterMaxWithinWindowAndResetsNextWindow(): void
    {
        $rl = new RateLimiter($this->pdo);
        $t = 1_700_000_000 - (1_700_000_000 % 60);          // aligned window start
        foreach ([1, 2, 3] as $i) {
            $this->assertTrue($rl->hit('login|1.2.3.4', 3, 60, $t + $i)['allowed']);
        }
        $r = $rl->hit('login|1.2.3.4', 3, 60, $t + 10);
        $this->assertFalse($r['allowed']);
        $this->assertSame(50, $r['retry_after']);
        $this->assertTrue($rl->hit('login|1.2.3.4', 3, 60, $t + 61)['allowed'], 'new window');
        $this->assertTrue($rl->hit('login|9.9.9.9', 3, 60, $t + 10)['allowed'], 'other key independent');
    }

    public function testTwoConnectionsShareOneCounter(): void
    {
        // Two connections incrementing the same key must not lose updates.
        $rl2 = new RateLimiter(\LeenTrack\Core\Db::connect(self::cfg()));
        $rl1 = new RateLimiter($this->pdo);
        $t = 1_700_000_040;
        for ($i = 0; $i < 10; $i++) {
            $rl1->hit('k', 100, 60, $t);
            $rl2->hit('k', 100, 60, $t);
        }
        $this->assertSame(79, $rl1->hit('k', 100, 60, $t)['remaining'], '20 hits from two connections + this one = 21 counted');
    }

    public function testPurge(): void
    {
        $rl = new RateLimiter($this->pdo);
        $rl->hit('old', 5, 60, time() - 200_000);
        $rl->hit('new', 5, 60);
        $this->assertSame(1, $rl->purge(86400));
    }
}
