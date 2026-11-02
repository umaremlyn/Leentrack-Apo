<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Db;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests need a real MySQL 8.x. Configure with TEST_DB_* env vars; if not set the tests are SKIPPED
 * (never silently passed) so a green run clearly states how many DB tests did not execute.
 */
abstract class DbTestCase extends TestCase
{
    protected PDO $pdo;

    /** @return array{host:string,port:int,name:string,user:string,pass:string,socket:string}|null */
    protected static function cfg(): ?array
    {
        $name = getenv('TEST_DB_NAME');
        if (!$name) {
            return null;
        }
        return [
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => $name,
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'pass' => getenv('TEST_DB_PASS') ?: '',
            'socket' => getenv('TEST_DB_SOCKET') ?: '',
        ];
    }

    protected function setUp(): void
    {
        $c = self::cfg();
        if ($c === null) {
            $this->markTestSkipped('TEST_DB_NAME not set: MySQL integration tests not run.');
        }
        $this->pdo = Db::connect($c, multiStatement: true);
        $this->dropAll();
    }

    protected function dropAll(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM) as $t) {
            $this->pdo->exec('DROP TABLE `' . $t[0] . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
