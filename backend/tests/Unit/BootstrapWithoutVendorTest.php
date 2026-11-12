<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Shared hosting has no Composer: the zip from Git has no vendor/ folder, and the app must still start. */
final class BootstrapWithoutVendorTest extends TestCase
{
    public function testTheAppBootsAndLoadsItsClassesWithoutComposerAutoloader(): void
    {
        $root = dirname(__DIR__, 2);
        $code = '$app = require ' . var_export($root . '/app/bootstrap.php', true) . ';'
            . 'echo get_class($app), "|", class_exists("Composer\\\\Autoload\\\\ClassLoader", false) ? "composer" : "builtin", "|", class_exists("LeenTrack\\\\Http\\\\Kernel") ? "kernel" : "nokernel";';
        $out = [];
        $rc = 0;
        exec('LEENTRACK_NO_VENDOR=1 ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertSame('LeenTrack\\Core\\App|builtin|kernel', trim(implode("\n", $out)));
    }
}
