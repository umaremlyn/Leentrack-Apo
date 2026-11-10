<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Unit;

use LeenTrack\Core\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    public function testRedactsSecretsRecursively(): void
    {
        $dir = sys_get_temp_dir() . '/lt-log-' . bin2hex(random_bytes(4));
        (new Logger($dir))->info('x', ['password' => 'p@ss', 'nested' => ['Authorization' => 'Bearer abc', 'ok' => 'fine'], 'refresh_token' => 't']);
        $content = (string) file_get_contents($dir . '/app-' . gmdate('Y-m-d') . '.log');
        $this->assertStringNotContainsString('p@ss', $content);
        $this->assertStringNotContainsString('Bearer abc', $content);
        $this->assertStringContainsString('fine', $content);
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}
