<?php
declare(strict_types=1);

namespace LeenTrack\Core;

use PDO;

/** Service holder (deliberately not a DI container: fewer moving parts on shared hosting). */
final class App
{
    private ?PDO $pdo = null;
    private ?Logger $logger = null;

    /** @param array<string,mixed> $config */
    public function __construct(public readonly array $config)
    {
        // Safety net: debug must never be on in production.
        if (($config['env'] ?? 'production') === 'production' && ($config['debug'] ?? false)) {
            throw new \RuntimeException('APP_DEBUG must be false when APP_ENV=production.');
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= Db::connect($this->config['db']);
    }

    public function setPdo(PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    public function dbConfigured(): bool
    {
        return $this->pdo !== null || ($this->config['db']['name'] ?? '') !== '';
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger($this->config['log_dir'], $this->config['log_level']);
    }

    public function isProduction(): bool
    {
        return $this->config['env'] === 'production';
    }
}
