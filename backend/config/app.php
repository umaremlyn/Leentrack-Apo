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
    'lock_dir' => Env::get('LOCK_DIR', dirname(__DIR__) . '/storage/locks'),
    'avatar_dir' => Env::get('AVATAR_DIR', dirname(__DIR__) . '/storage/avatars'),
    'log_level' => Env::get('LOG_LEVEL', 'info'),
    'cors_origins' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('CORS_ALLOWED_ORIGINS', ''))))),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', ''))))),
    'rate_limit_enabled' => Env::bool('RATE_LIMIT_ENABLED', true),
    'enforce_sales_limit' => Env::bool('ENFORCE_SALES_LIMIT', false),   // the source only showed a banner; blocking the till is an owner decision (G-29)
    'web_url' => rtrim((string) Env::get('WEB_URL', ''), '/'),
    'paystack' => [
        'secret' => Env::get('PAYSTACK_SECRET_KEY', ''),                      // server-side secret (sk_…); the browser never sees it
        'base_url' => Env::get('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'callback_url' => Env::get('PAYSTACK_CALLBACK_URL', ''),              // where Paystack sends the customer back, e.g. https://app.example.com/billing/callback
    ],
    'payment_retention_years' => Env::int('PAYMENT_RETENTION_YEARS', 7),
    'report_max_rows' => Env::int('REPORT_MAX_ROWS', 50000),             // largest report (rows) built in one request; protects shared-hosting memory
    'mail_driver' => Env::get('MAIL_DRIVER', ''),                       // smtp | native | file ('' = native in production, file otherwise)
    'smtp' => [
        'host' => Env::get('SMTP_HOST', ''), 'port' => Env::int('SMTP_PORT', 587), 'secure' => Env::get('SMTP_SECURE', 'tls'),   // tls = STARTTLS, ssl = implicit TLS, none
        'user' => Env::get('SMTP_USER', ''), 'pass' => Env::get('SMTP_PASS', ''),
    ],
    'mail_from' => Env::get('MAIL_FROM', 'LeenTrack <no-reply@localhost>'),
    'auth' => [
        'access_ttl' => Env::int('ACCESS_TOKEN_TTL', 900),            // 15 min
        'refresh_ttl' => Env::int('REFRESH_TOKEN_TTL', 2592000),      // 30 days
        'require_email_verification' => Env::bool('REQUIRE_EMAIL_VERIFICATION', true),
        'invite_ttl' => Env::int('INVITE_TTL', 604800),               // 7 days
        'reset_ttl' => 3600,
        'verify_ttl' => 172800,
    ],
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::int('DB_PORT', 3306),
        'name' => Env::get('DB_NAME', ''),
        'user' => Env::get('DB_USER', ''),
        'pass' => Env::get('DB_PASS', ''),
        'socket' => Env::get('DB_SOCKET', ''),
    ],
];
