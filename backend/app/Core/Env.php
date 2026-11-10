<?php
declare(strict_types=1);

namespace LeenTrack\Core;

/** Minimal .env loader. Real environment variables win over the file. */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];

    public static function load(?string $file): void
    {
        self::$vars = [];
        if ($file !== null && is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);
                if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
                    $q = $v[0];
                    $end = strrpos($v, $q);
                    $v = $end > 0 ? substr($v, 1, $end - 1) : substr($v, 1);
                } else {
                    $hash = strpos($v, ' #');           // strip trailing comment
                    if ($hash !== false) {
                        $v = rtrim(substr($v, 0, $hash));
                    }
                }
                self::$vars[$k] = $v;
            }
        }
    }

    public static function set(string $key, string $value): void
    {
        self::$vars[$key] = $value;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $real = getenv($key);
        if ($real !== false) {
            return $real;
        }
        return self::$vars[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v !== null && is_numeric($v) ? (int) $v : $default;
    }
}
