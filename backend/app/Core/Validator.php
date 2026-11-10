<?php
declare(strict_types=1);

namespace LeenTrack\Core;

/**
 * Whitelist-first input validation. Usage:
 *   $v = new Validator($body); $v->only(['email','password']); $email = $v->email('email'); $v->done();
 * Any key not in only() is rejected (mass-assignment defence); `done()` throws one 422 with all field errors.
 */
final class Validator
{
    /** @var array<string,list<string>> */
    private array $errors = [];

    /** Keys no endpoint may ever accept from a client unless it explicitly allows them via only(). */
    private const FORBIDDEN = ['role', 'plan', 'plan_status', 'plan_expiry', 'is_admin', 'is_platform_admin', 'user_id', 'owner_user_id', 'status', 'email_verified_at', 'legacy_bcrypt_hash', 'password_hash', 'unit_cost', 'loyalty_points'];

    /** @param array<string,mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    /** @param list<string> $allowed */
    public function only(array $allowed): self
    {
        foreach (array_keys($this->data) as $k) {
            if (!in_array($k, $allowed, true)) {
                $code = in_array($k, self::FORBIDDEN, true) ? 'This field cannot be set.' : 'Unknown field.';
                $this->errors[(string) $k][] = $code;
            }
        }
        return $this;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function string(string $key, int $min = 1, int $max = 255, bool $required = true): ?string
    {
        if (!$this->has($key) || $this->data[$key] === null || $this->data[$key] === '') {
            if ($required) { $this->errors[$key][] = 'This field is required.'; }
            return null;
        }
        $v = $this->data[$key];
        if (!is_string($v)) { $this->errors[$key][] = 'Must be text.'; return null; }
        $v = trim($v);
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) { $this->errors[$key][] = "Must be between $min and $max characters."; return null; }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v)) { $this->errors[$key][] = 'Contains invalid characters.'; return null; }
        return $v;
    }

    public function email(string $key, bool $required = true): ?string
    {
        $v = $this->string($key, 3, 254, $required);
        if ($v === null) { return null; }
        $v = strtolower($v);
        if (!filter_var($v, FILTER_VALIDATE_EMAIL) || str_contains($v, '..')) { $this->errors[$key][] = 'Must be a valid e-mail address.'; return null; }
        return $v;
    }

    /** @param list<string> $allowed */
    public function enum(string $key, array $allowed, bool $required = true, ?string $default = null): ?string
    {
        if (!$this->has($key) || $this->data[$key] === null) {
            if ($required && $default === null) { $this->errors[$key][] = 'This field is required.'; }
            return $default;
        }
        $v = $this->data[$key];
        if (!is_string($v) || !in_array($v, $allowed, true)) { $this->errors[$key][] = 'Must be one of: ' . implode(', ', $allowed) . '.'; return null; }
        return $v;
    }

    /** Percentage with ≤2 decimals as string. */
    public function percent(string $key, bool $required = true): ?string
    {
        if (!$this->has($key) || $this->data[$key] === null) {
            if ($required) { $this->errors[$key][] = 'This field is required.'; }
            return null;
        }
        $s = (string) $this->data[$key];
        if (!is_scalar($this->data[$key]) || !preg_match('/^\d{1,3}(\.\d{1,2})?$/', $s) || (float) $s > 100) {
            $this->errors[$key][] = 'Must be a number between 0 and 100 with at most 2 decimals.';
            return null;
        }
        return $s;
    }

    public function bool(string $key, bool $required = false): ?bool
    {
        if (!$this->has($key) || $this->data[$key] === null) {
            if ($required) { $this->errors[$key][] = 'This field is required.'; }
            return null;
        }
        if (!is_bool($this->data[$key])) { $this->errors[$key][] = 'Must be true or false.'; return null; }
        return $this->data[$key];
    }

    public function uuid(string $key, bool $required = true): ?string
    {
        $v = $this->string($key, 36, 36, $required);
        if ($v !== null && !Uuid::isValid($v)) { $this->errors[$key][] = 'Must be a valid id.'; return null; }
        return $v;
    }

    /** Whole number within [min,max]. Accepts JSON ints or digit strings, never floats like 1.5. */
    public function int(string $key, int $min, int $max, bool $required = true, ?int $default = null): ?int
    {
        if (!$this->has($key) || $this->data[$key] === null || $this->data[$key] === '') {
            if ($required && $default === null) { $this->errors[$key][] = 'This field is required.'; }
            return $default;
        }
        $v = $this->data[$key];
        if (is_string($v) && preg_match('/^-?\d{1,12}$/', $v)) { $v = (int) $v; }
        if (!is_int($v) || $v < $min || $v > $max) { $this->errors[$key][] = "Must be a whole number between $min and $max."; return null; }
        return $v;
    }

    /** Money amount (≥0 unless $allowNegative) with at most 2 decimals. Returns a Money object. */
    public function money(string $key, bool $required = true, ?Money $default = null): ?Money
    {
        if (!$this->has($key) || $this->data[$key] === null || $this->data[$key] === '') {
            if ($required && $default === null) { $this->errors[$key][] = 'This field is required.'; }
            return $default;
        }
        $v = $this->data[$key];
        if (!is_int($v) && !is_string($v) && !is_float($v)) { $this->errors[$key][] = 'Must be an amount.'; return null; }
        try {
            $m = Money::parse(is_float($v) ? rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') : $v);
        } catch (\InvalidArgumentException | \OverflowException) {
            $this->errors[$key][] = 'Must be an amount with at most 2 decimals.';
            return null;
        }
        if ($m->isNegative()) { $this->errors[$key][] = 'Must not be negative.'; return null; }
        return $m;
    }

    public function url(string $key, bool $required = false): ?string
    {
        $v = $this->string($key, 8, 500, $required);
        if ($v === null) { return null; }
        if (!filter_var($v, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($v), 'https://')) {
            $this->errors[$key][] = 'Must be a valid https:// URL.';
            return null;
        }
        return $v;
    }

    public function addError(string $key, string $message): void
    {
        $this->errors[$key][] = $message;
    }

    public function done(): void
    {
        if ($this->errors) {
            throw ApiException::validation($this->errors);
        }
    }
}
