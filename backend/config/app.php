<?php
declare(strict_types=1);

use LeenTrack\Core\Env;

return [
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => Env::get('APP_URL', ''),
    'version' => '0.1.0',
    'api_prefix' => '/api/v1',
    'log_dir' => dirname(__DIR__) . '/storage/logs',
    'log_level' => Env::get('LOG_LEVEL', 'info'),
    'cors_origins' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('CORS_ALLOWED_ORIGINS', ''))))),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', ''))))),
    'rate_limit_enabled' => Env::bool('RATE_LIMIT_ENABLED', true),
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::int('DB_PORT', 3306),
        'name' => Env::get('DB_NAME', ''),
        'user' => Env::get('DB_USER', ''),
        'pass' => Env::get('DB_PASS', ''),
        'socket' => Env::get('DB_SOCKET', ''),
    ],
];
