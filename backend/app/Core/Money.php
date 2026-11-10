<?php
declare(strict_types=1);

namespace LeenTrack\Core;

/**
 * Exact money arithmetic in integer minor units (2 dp), no floats, no bcmath/gmp needed (Truehost-safe).
 * Rounding: half away from zero (equals the source's Math.round for non-negative amounts).
 * Hard ceiling 1e12 minor units (= 10,000,000,000.00; the source rejected sale subtotals above 1,000,000,000 and a total can
 * be a little higher after tax). Products of two amounts can exceed 64 bits, so mulRatio() falls back to exact big-number
 * arithmetic instead of letting PHP silently turn the product into a float.
 */
final class Money
{
    public const MAX_MINOR = 1_000_000_000_000;      // 10,000,000,000.00

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

    /** this × (num/den), rounded half away from zero. e.g. percent: mulRatio(750, 10000) = 7.5 %. Exact for any inputs. */
    public function mulRatio(int $num, int $den): self
    {
        if ($den <= 0) {
            throw new \DivisionByZeroError();
        }
        $neg = ($this->minor < 0) !== ($num < 0) && $this->minor !== 0 && $num !== 0;
        $q = self::mulDivRound(abs($this->minor), abs($num), $den);
        return self::ofMinor($neg ? -$q : $q);
    }

    /** round-half-up( a * b / d ) for non-negative ints, never overflowing: fast path in int64, schoolbook big-number otherwise. */
    public static function mulDivRound(int $a, int $b, int $d): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        // fast path only when a*b*2 + d provably fits in a signed 64-bit int
        if ($b <= intdiv(PHP_INT_MAX, 4) && $a <= intdiv(PHP_INT_MAX - $d, 2 * $b)) {
            return intdiv($a * $b * 2 + $d, $d * 2);
        }
        // slow path: decimal-string product, then long division by $d (remainder stays < $d <= ~1e12, so r*10+digit fits in int64)
        $x = strrev((string) $a);
        $y = strrev((string) $b);
        $prod = array_fill(0, strlen($x) + strlen($y), 0);
        for ($i = 0, $n = strlen($x); $i < $n; $i++) {
            for ($j = 0, $m = strlen($y); $j < $m; $j++) {
                $prod[$i + $j] += ((int) $x[$i]) * ((int) $y[$j]);
            }
        }
        $carry = 0;
        foreach ($prod as $k => $v) {
            $v += $carry;
            $prod[$k] = $v % 10;
            $carry = intdiv($v, 10);
        }
        $digits = rtrim(implode('', array_reverse($prod)), '');
        $digits = ltrim($digits, '0') ?: '0';
        $quot = 0;
        $rem = 0;
        for ($i = 0, $n = strlen($digits); $i < $n; $i++) {
            $rem = $rem * 10 + (int) $digits[$i];
            $qd = intdiv($rem, $d);
            $rem -= $qd * $d;
            $quot = $quot * 10 + $qd;
            if ($quot > self::MAX_MINOR * 10) {
                throw new \OverflowException('Amount too large.');
            }
        }
        return $rem * 2 >= $d ? $quot + 1 : $quot;
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

    /** floor(this / other) as a whole number, exact (no floats). Works for negative dividends (rounds toward −∞, like SQL FLOOR). */
    public function floorDiv(self $o): int
    {
        if ($o->minor <= 0) {
            throw new \DivisionByZeroError();
        }
        $q = intdiv($this->minor, $o->minor);
        return ($this->minor % $o->minor !== 0 && $this->minor < 0) ? $q - 1 : $q;
    }

    /** "1500.50" */
    public function toString(): string
    {
        $a = abs($this->minor);
        return ($this->minor < 0 ? '-' : '') . intdiv($a, 100) . '.' . str_pad((string) ($a % 100), 2, '0', STR_PAD_LEFT);
    }

    public function __toString(): string { return $this->toString(); }
}
