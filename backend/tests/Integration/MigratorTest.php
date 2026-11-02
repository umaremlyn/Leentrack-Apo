<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Migrator;

final class MigratorTest extends DbTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/lt-mig-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            @rmdir($this->dir);
        }
    }

    private function write(string $name, string $sql): void
    {
        file_put_contents($this->dir . '/' . $name, $sql);
    }

    public function testRealMigrationsApplyRollbackAndReapply(): void
    {
        $m = new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations');
        $this->assertNotEmpty($m->available());
        $applied = $m->up();
        $this->assertContains('0001', $applied);
        $this->assertSame([], $m->up(), 'second run is a no-op');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='rate_limits'")->fetchColumn());
        $m->down();
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='rate_limits'")->fetchColumn());
        $this->assertContains('0001', $m->up());
    }

    public function testModifiedAppliedMigrationIsRefused(): void
    {
        $this->write('0001_a.up.sql', 'CREATE TABLE a (id INT PRIMARY KEY);');
        $this->write('0001_a.down.sql', 'DROP TABLE a;');
        $m = new Migrator($this->pdo, $this->dir);
        $m->up();
        $this->write('0001_a.up.sql', 'CREATE TABLE a (id INT PRIMARY KEY, x INT);');
        $this->expectExceptionMessage('modified after being applied');
        $m->up();
    }

    public function testFailedMigrationIsNotRecorded(): void
    {
        $this->write('0001_ok.up.sql', 'CREATE TABLE ok (id INT PRIMARY KEY);');
        $this->write('0002_bad.up.sql', 'CREATE TABLE bad (id INT PRIMARY KEY); THIS IS NOT SQL;');
        $m = new Migrator($this->pdo, $this->dir);
        try {
            $m->up();
            $this->fail('should have thrown');
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(['0001'], array_keys($m->applied()), 'only the successful migration is recorded');
    }

    public function testRollbackWithoutDownFileRefused(): void
    {
        $this->write('0001_nodown.up.sql', 'CREATE TABLE nd (id INT PRIMARY KEY);');
        $m = new Migrator($this->pdo, $this->dir);
        $m->up();
        $this->expectExceptionMessage('no .down.sql');
        $m->down();
    }
}
