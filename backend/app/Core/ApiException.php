<?php
declare(strict_types=1);

namespace LeenTrack\Core;

/** An error that is safe to show to the client. Anything else becomes a generic 500. */
class ApiException extends \RuntimeException
{
    /** @param array<string,list<string>> $fields */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $fields = [],
        /** @var array<string,string> */
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string,list<string>> $fields */
    public static function validation(array $fields, string $message = 'The given data was invalid.'): self
    {
        return new self('validation_failed', $message, 422, $fields);
    }
    public static function unauthenticated(string $m = 'Authentication required.'): self { return new self('unauthenticated', $m, 401); }
    public static function forbidden(string $m = 'You do not have permission to do this.'): self { return new self('forbidden', $m, 403); }
    public static function notFound(string $m = 'Not found.'): self { return new self('not_found', $m, 404); }
    public static function conflict(string $code, string $m): self { return new self($code, $m, 409); }
    public static function methodNotAllowed(array $allowed): self
    {
        return new self('method_not_allowed', 'Method not allowed.', 405, [], ['Allow' => implode(', ', $allowed)]);
    }
    public static function tooMany(int $retryAfter): self
    {
        return new self('rate_limited', 'Too many requests. Please try again later.', 429, [], ['Retry-After' => (string) max(1, $retryAfter)]);
    }
}
