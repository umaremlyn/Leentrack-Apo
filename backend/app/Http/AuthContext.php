<?php
declare(strict_types=1);

namespace LeenTrack\Http;

final class AuthContext
{
    public function __construct(
        public readonly string $userId,
        public readonly string $sessionId,
        public readonly string $email,
    ) {
    }
}
