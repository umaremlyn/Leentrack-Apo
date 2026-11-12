<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The phpMyAdmin installer for hosts without a terminal must always match the migrations. Regenerate with:
 *  php bin/build-install-sql.php > database/install/leentrack-install.sql */
final class InstallSqlBundleTest extends TestCase
{
    public function testTheCommittedInstallerMatchesTheMigrations(): void
    {
        $root = dirname(__DIR__, 2);
        $fresh = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/build-install-sql.php') . ' 2>&1');
        $this->assertNotNull($fresh);
        $this->assertSame($fresh, (string) file_get_contents($root . '/database/install/leentrack-install.sql'), 'database/install/leentrack-install.sql is out of date: regenerate it');
        $this->assertSame(count(glob($root . '/database/migrations/*.up.sql') ?: []), substr_count($fresh, 'INSERT INTO schema_migrations'));
    }
}
