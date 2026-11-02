<?php
declare(strict_types=1);

namespace LeenTrack\Core;

/** JSON-lines logger. Sensitive keys are redacted; never log request bodies wholesale. */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
    private const REDACT = ['password', 'pass', 'token', 'access_token', 'refresh_token', 'authorization', 'secret', 'api_key', 'hash', 'password_hash', 'legacy_bcrypt_hash', 'card', 'cvv'];

    public function __construct(private readonly string $dir, private readonly string $minLevel = 'info')
    {
    }

    /** @param array<string,mixed> $ctx */
    public function log(string $level, string $message, array $ctx = []): void
    {
        if ((self::LEVELS[$level] ?? 1) < (self::LEVELS[$this->minLevel] ?? 1)) {
            return;
        }
        $line = json_encode([
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'msg' => $message,
            'ctx' => $this->redact($ctx),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
        @file_put_contents($this->dir . '/app-' . gmdate('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $ctx */
    public function info(string $m, array $ctx = []): void { $this->log('info', $m, $ctx); }
    /** @param array<string,mixed> $ctx */
    public function warning(string $m, array $ctx = []): void { $this->log('warning', $m, $ctx); }
    /** @param array<string,mixed> $ctx */
    public function error(string $m, array $ctx = []): void { $this->log('error', $m, $ctx); }

    /** @param array<mixed> $v @return array<mixed> */
    private function redact(array $v): array
    {
        $out = [];
        foreach ($v as $k => $x) {
            if (is_string($k) && in_array(strtolower($k), self::REDACT, true)) {
                $out[$k] = '[redacted]';
            } elseif (is_array($x)) {
                $out[$k] = $this->redact($x);
            } elseif (is_string($x) && strlen($x) > 500) {
                $out[$k] = substr($x, 0, 500) . '…';
            } else {
                $out[$k] = $x;
            }
        }
        return $out;
    }
}
