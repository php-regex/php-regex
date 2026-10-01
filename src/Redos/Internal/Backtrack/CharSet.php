<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Redos\Internal\Backtrack;

/**
 * A set of code points (or bytes without /u), as sorted, disjoint and
 * non-adjacent ranges.
 *
 * @internal
 */
final readonly class CharSet
{
    private const FIRST_PRINTABLE = 0x21;

    private const LAST_PRINTABLE = 0x7E;

    private const SPACE = 0x20;

    /**
     * @param list<array{int, int}> $ranges
     */
    private function __construct(public array $ranges) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public static function single(int $codePoint): self
    {
        return new self([[$codePoint, $codePoint]]);
    }

    public static function range(int $from, int $to): self
    {
        return $from > $to ? new self([]) : new self([[$from, $to]]);
    }

    /**
     * Every character the alphabet has: the bytes without /u, the code
     * points but the surrogates with it.
     */
    public static function universe(bool $unicode): self
    {
        return $unicode ? new self([[0, 0xD7FF], [0xE000, 0x10FFFF]]) : new self([[0, 0xFF]]);
    }

    /**
     * @param array<array{int, int}> $ranges in any order, overlapping or not
     */
    public static function fromRanges(array $ranges): self
    {
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];
        $currentFrom = null;
        $currentTo = 0;
        foreach ($ranges as [$from, $to]) {
            if ($from > $to) {
                continue;
            }

            if (null !== $currentFrom && $from <= $currentTo + 1) {
                $currentTo = max($currentTo, $to);

                continue;
            }

            if (null !== $currentFrom) {
                $merged[] = [$currentFrom, $currentTo];
            }

            $currentFrom = $from;
            $currentTo = $to;
        }

        if (null !== $currentFrom) {
            $merged[] = [$currentFrom, $currentTo];
        }

        return new self($merged);
    }

    /**
     * @param list<array{int, int}> $ranges ascending and disjoint, maybe adjacent
     */
    public static function fromSorted(array $ranges): self
    {
        $merged = [];
        $last = -1;
        foreach ($ranges as [$from, $to]) {
            if ($last >= 0 && $from <= $merged[$last][1] + 1) {
                $merged[$last] = [$merged[$last][0], max($merged[$last][1], $to)];

                continue;
            }

            $merged[] = [$from, $to];
            $last++;
        }

        return new self($merged);
    }

    public function union(self $other): self
    {
        if ([] === $other->ranges) {
            return $this;
        }

        if ([] === $this->ranges) {
            return $other;
        }

        return self::fromRanges([...$this->ranges, ...$other->ranges]);
    }

    public function intersect(self $other): self
    {
        $result = [];
        $i = 0;
        $j = 0;
        $left = $this->ranges;
        $right = $other->ranges;
        $leftCount = \count($left);
        $rightCount = \count($right);

        while ($i < $leftCount && $j < $rightCount) {
            $from = max($left[$i][0], $right[$j][0]);
            $to = min($left[$i][1], $right[$j][1]);
            if ($from <= $to) {
                $result[] = [$from, $to];
            }

            if ($left[$i][1] < $right[$j][1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return new self($result);
    }

    public function subtract(self $other): self
    {
        $result = [];
        $j = 0;
        $right = $other->ranges;
        $rightCount = \count($right);

        foreach ($this->ranges as [$from, $to]) {
            while ($j < $rightCount && $right[$j][1] < $from) {
                $j++;
            }

            $current = $from;
            $k = $j;
            while ($k < $rightCount && $right[$k][0] <= $to) {
                if ($right[$k][0] > $current) {
                    $result[] = [$current, $right[$k][0] - 1];
                }

                $current = max($current, $right[$k][1] + 1);
                $k++;
            }

            if ($current <= $to) {
                $result[] = [$current, $to];
            }
        }

        return new self($result);
    }

    public function isEmpty(): bool
    {
        return [] === $this->ranges;
    }

    public function contains(int $codePoint): bool
    {
        $low = 0;
        $high = \count($this->ranges) - 1;
        while ($low <= $high) {
            $middle = ($low + $high) >> 1;
            [$from, $to] = $this->ranges[$middle];
            if ($codePoint < $from) {
                $high = $middle - 1;
            } elseif ($codePoint > $to) {
                $low = $middle + 1;
            } else {
                return true;
            }
        }

        return false;
    }

    public function key(): string
    {
        $parts = [];
        foreach ($this->ranges as [$from, $to]) {
            $parts[] = $from === $to ? (string) $from : $from.'-'.$to;
        }

        return implode(',', $parts);
    }

    /**
     * The character a witness uses for the set: its smallest printable ASCII
     * character other than space, else space, else its smallest character.
     */
    public function representative(): ?int
    {
        if ([] === $this->ranges) {
            return null;
        }

        foreach ($this->ranges as [$from, $to]) {
            $candidate = max($from, self::FIRST_PRINTABLE);
            if ($candidate <= $to && $candidate <= self::LAST_PRINTABLE) {
                return $candidate;
            }

            if ($from > self::LAST_PRINTABLE) {
                break;
            }
        }

        if ($this->contains(self::SPACE)) {
            return self::SPACE;
        }

        return $this->ranges[0][0];
    }

    /**
     * The order representatives are tried in: printable ASCII first, then
     * space, then every other character by code point.
     */
    public static function preference(int $codePoint): int
    {
        if ($codePoint >= self::FIRST_PRINTABLE && $codePoint <= self::LAST_PRINTABLE) {
            return $codePoint - self::FIRST_PRINTABLE;
        }

        if (self::SPACE === $codePoint) {
            return self::LAST_PRINTABLE - self::FIRST_PRINTABLE + 1;
        }

        return self::LAST_PRINTABLE + 2 + $codePoint;
    }
}
