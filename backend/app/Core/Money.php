<?php
declare(strict_types=1);

namespace LeenTrack\Core;

/**
 * Exact money arithmetic in integer minor units (2 dp), no floats, no bcmath/gmp needed (Truehost-safe).
 * Rounding: half away from zero (equals the source's Math.round for non-negative amounts).
 * Hard ceiling 1e11 minor units (= 1,000,000,000.00, the source's own "total too large" limit) so that
 * ratio multiplications cannot overflow a 64-bit int.
 */
final class Money
{
    public const MAX_MINOR = 100_000_000_000;

    private function __construct(public readonly int $minor)
    {
    }

    public static function zero(): self { return new self(0); }

    public static function ofMinor(int $minor): self
    {
        if (abs($minor) > self::MAX_MINOR) {
            throw new \OverflowException('Amount too large.');
        }
        return new self($minor);
    }

    /** Parse "1500", "1,500.5", "1500.50". At most 2 decimals unless $round is true (then half-up to 2 dp). */
    public static function parse(string|int $v, bool $round = false): self
    {
        $s = trim(str_replace([',', ' '], '', (string) $v));
        if (!preg_match('/^(-)?(\d{1,11})(?:\.(\d+))?$/', $s, $m)) {
            throw new \InvalidArgumentException('Not a valid amount.');
        }
        $neg = $m[1] === '-';
        $frac = $m[3] ?? '';
        if (strlen($frac) > 2 && !$round) {
            throw new \InvalidArgumentException('Too many decimal places.');
        }
        $minor = ((int) $m[2]) * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0');
        if (strlen($frac) > 2 && (int) $frac[2] >= 5) {
            $minor++;
        }
        return self::ofMinor($neg ? -$minor : $minor);
    }

    public function add(self $o): self { return self::ofMinor($this->minor + $o->minor); }
    public function sub(self $o): self { return self::ofMinor($this->minor - $o->minor); }
    public function mulInt(int $qty): self { return self::ofMinor($this->minor * $qty); }
    public function isZero(): bool { return $this->minor === 0; }
    public function isNegative(): bool { return $this->minor < 0; }
    public function cmp(self $o): int { return $this->minor <=> $o->minor; }
    public function gt(self $o): bool { return $this->minor > $o->minor; }
    public function gte(self $o): bool { return $this->minor >= $o->minor; }
    public function lt(self $o): bool { return $this->minor < $o->minor; }
    public function max(self $o): self { return $this->minor >= $o->minor ? $this : $o; }
    public function min(self $o): self { return $this->minor <= $o->minor ? $this : $o; }

    /** this × (num/den), rounded half away from zero. e.g. percent: mulRatio(750, 10000) = 7.5 %. */
    public function mulRatio(int $num, int $den): self
    {
        if ($den <= 0) {
            throw new \DivisionByZeroError();
        }
        $n = $this->minor * $num;
        $neg = $n < 0;
        $q = intdiv(abs($n) * 2 + $den, $den * 2);   // round half up on magnitude
        return self::ofMinor($neg ? -$q : $q);
    }

    /** Percent given as a decimal string with ≤2 dp ("7.5", "10", "0.25"). */
    public function percent(string $pct): self
    {
        if (!preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $pct, $m)) {
            throw new \InvalidArgumentException('Bad percentage.');
        }
        $bp = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');   // basis points of a percent: 7.5 -> 750
        return $this->mulRatio($bp, 10_000);
    }

    /** "1500.50" */
    public function toString(): string
    {
        $a = abs($this->minor);
        return ($this->minor < 0 ? '-' : '') . intdiv($a, 100) . '.' . str_pad((string) ($a % 100), 2, '0', STR_PAD_LEFT);
    }

    public function __toString(): string { return $this->toString(); }
}
