<?php
declare(strict_types=1);

use LeenTrack\Core\App;
use LeenTrack\Core\Env;

$root = dirname(__DIR__);
// Composer's autoloader when it exists (development, tests). The app has NO runtime dependencies, so on shared hosting `vendor/` is not needed:
// a built-in PSR-4 loader for LeenTrack\ -> app/ takes over. (LEENTRACK_NO_VENDOR=1 forces it; used by the tests.)
if (is_file($root . '/vendor/autoload.php') && getenv('LEENTRACK_NO_VENDOR') !== '1') {
    require $root . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'LeenTrack\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) { return; }
        $file = $root . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require $file; }
    });
}

// .env lives OUTSIDE the web root in production: LEENTRACK_ENV_FILE overrides, then ../.env above project, then ./.env.
$candidates = array_filter([
    getenv('LEENTRACK_ENV_FILE') ?: null,
    dirname($root) . '/leentrack.env',
    $root . '/.env',
]);
$file = null;
foreach ($candidates as $c) {
    if (is_file($c)) { $file = $c; break; }
}
Env::load($file);

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
ini_set('expose_php', '0');
ini_set('display_errors', '0');        // errors go to the log, never to the client
ini_set('log_errors', '1');

return new App(require $root . '/config/app.php');
