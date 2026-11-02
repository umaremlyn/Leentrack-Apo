<?php
declare(strict_types=1);

use LeenTrack\Core\App;
use LeenTrack\Core\Env;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

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
