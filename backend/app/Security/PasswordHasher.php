<?php
declare(strict_types=1);

namespace LeenTrack\Security;

use LeenTrack\Core\ApiException;

final class PasswordHasher
{
    private const COMMON = ['password', 'password1', 'password12', 'password123', '12345678', '123456789', '1234567890', 'qwertyui', 'qwerty123', 'iloveyou', 'admin123', 'welcome1', 'letmein1', 'abc12345', 'leentrack', 'leentrack1', '11111111', '00000000', 'passw0rd', 'changeme'];
    private ?string $dummy = null;

    private function algo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** @return array<string,mixed> */
    private function opts(): array
    {
        return $this->algo() === PASSWORD_BCRYPT ? ['cost' => 12] : ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];
    }

    /** Enforce policy; returns list of problems (empty = ok). @return list<string> */
    public function problems(string $pw, ?string $email = null): array
    {
        $p = [];
        if (mb_strlen($pw) < 8) { $p[] = 'Must be at least 8 characters.'; }
        if (strlen($pw) > 128) { $p[] = 'Must be at most 128 bytes.'; }
        if (in_array(strtolower($pw), self::COMMON, true)) { $p[] = 'This password is too common.'; }
        if ($email !== null && strtolower($pw) === strtolower($email)) { $p[] = 'Must not be your e-mail address.'; }
        if (preg_match('/^(.)\1+$/', $pw)) { $p[] = 'Must not be a single repeated character.'; }
        return $p;
    }

    public function assertAcceptable(string $pw, ?string $email = null, string $field = 'password'): void
    {
        $p = $this->problems($pw, $email);
        if ($p) { throw ApiException::validation([$field => $p]); }
    }

    public function hash(string $pw): string
    {
        return password_hash($pw, $this->algo(), $this->opts());
    }

    public function verify(string $pw, string $hash): bool
    {
        return strlen($pw) <= 1024 && password_verify($pw, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algo(), $this->opts());
    }

    /** Burn comparable CPU time for unknown accounts so response time does not reveal existence. */
    public function dummyVerify(string $pw): void
    {
        $this->dummy ??= $this->hash('dummy-password-for-timing');
        password_verify($pw, $this->dummy);
    }

    /** Only bcrypt ($2a$/$2b$/$2y$) legacy hashes are accepted for the one-time transition. */
    public function verifyLegacyBcrypt(string $pw, string $hash): bool
    {
        return preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hash) === 1 && strlen($pw) <= 72 && password_verify($pw, $hash);
    }
}
