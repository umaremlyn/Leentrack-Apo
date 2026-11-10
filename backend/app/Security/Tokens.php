<?php
declare(strict_types=1);

namespace LeenTrack\Security;

final class Tokens
{
    /** 256-bit URL-safe random token. The raw value is shown once; only hash() is stored. */
    public static function generate(string $prefix = ''): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
