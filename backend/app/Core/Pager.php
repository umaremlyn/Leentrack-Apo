<?php
declare(strict_types=1);

namespace LeenTrack\Core;

/** ?page=1&per_page=50 (max 200). Unknown/invalid values are rejected rather than silently clamped. */
final class Pager
{
    public function __construct(public readonly int $page, public readonly int $perPage)
    {
    }

    /** @param array<string,mixed> $q */
    public static function fromQuery(array $q, int $defaultPer = 50): self
    {
        $page = self::int($q, 'page', 1, 1, 1_000_000);
        $per = self::int($q, 'per_page', $defaultPer, 1, 200);
        return new self($page, $per);
    }

    public function offset(): int { return ($this->page - 1) * $this->perPage; }

    /** @return array{page:int,per_page:int,total:int} */
    public function meta(int $total): array { return ['page' => $this->page, 'per_page' => $this->perPage, 'total' => $total]; }

    /** @param array<string,mixed> $q */
    private static function int(array $q, string $k, int $default, int $min, int $max): int
    {
        if (!isset($q[$k])) { return $default; }
        if (!is_string($q[$k]) || !preg_match('/^\d{1,9}$/', $q[$k]) || (int) $q[$k] < $min || (int) $q[$k] > $max) {
            throw ApiException::validation([$k => ["Must be a whole number between $min and $max."]]);
        }
        return (int) $q[$k];
    }
}
