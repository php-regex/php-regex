<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser\Analysis;

/**
 * Lightweight character set representation with basic set operations.
 */
final readonly class ByteCharSet
{
    private const ASCII_MAX = 0x7F;

    /**
     * @param array<array{0:int, 1:int}> $ranges inclusive ranges of byte values
     */
    private function __construct(private array $ranges = [], private bool $unknown = false) {}

    public static function empty(): self
    {
        return new self();
    }

    public static function unknown(): self
    {
        return new self([], true);
    }

    public static function full(): self
    {
        return new self([[0, self::ASCII_MAX]]);
    }

    public static function fromRange(int $start, int $end): self
    {
        if ($start > self::ASCII_MAX || $end < 0 || $end > self::ASCII_MAX) {
            return self::unknown();
        }

        $clampedStart = max(0, $start);
        $clampedEnd = min(self::ASCII_MAX, $end);

        if ($clampedStart > $clampedEnd) {
            return self::empty();
        }

        return new self([[$clampedStart, $clampedEnd]]);
    }

    public static function fromChar(string $char): self
    {
        return '' === $char ? self::empty() : new self([[\ord($char[0]), \ord($char[0])]]);
    }

    public function isEmpty(): bool
    {
        return !$this->unknown && [] === $this->ranges;
    }

    public function isUnknown(): bool
    {
        return $this->unknown;
    }

    public function union(self $other): self
    {
        if ($this->unknown || $other->unknown) {
            return self::unknown();
        }

        $merged = array_merge($this->ranges, $other->ranges);
        if ([] === $merged) {
            return self::empty();
        }

        usort($merged, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $result = [];
        foreach ($merged as [$start, $end]) {
            if ([] === $result) {
                $result[] = [$start, $end];

                continue;
            }

            [$lastStart, $lastEnd] = $result[\count($result) - 1];
            if ($start <= $lastEnd + 1) {
                $result[\count($result) - 1] = [$lastStart, max($lastEnd, $end)];

                continue;
            }

            $result[] = [$start, $end];
        }

        return new self($result);
    }

    public function intersects(self $other): bool
    {
        if ($this->unknown || $other->unknown) {
            return true;
        }

        foreach ($this->ranges as [$start, $end]) {
            foreach ($other->ranges as [$oStart, $oEnd]) {
                if ($start <= $oEnd && $oStart <= $end) {
                    return true;
                }
            }
        }

        return false;
    }

    public function intersect(self $other): self
    {
        if ($this->unknown || $other->unknown) {
            return self::unknown();
        }

        $common = self::empty();
        foreach ($this->ranges as [$start, $end]) {
            foreach ($other->ranges as [$oStart, $oEnd]) {
                if ($start <= $oEnd && $oStart <= $end) {
                    $common = $common->union(new self([[max($start, $oStart), min($end, $oEnd)]]));
                }
            }
        }

        return $common;
    }

    public function complement(): self
    {
        if ($this->unknown) {
            return self::unknown();
        }

        if ([] === $this->ranges) {
            return self::full();
        }

        $complement = [];
        $cursor = 0;

        foreach ($this->ranges as [$start, $end]) {
            if ($cursor < $start) {
                $complement[] = [$cursor, $start - 1];
            }
            $cursor = $end + 1;
        }

        if ($cursor <= self::ASCII_MAX) {
            $complement[] = [$cursor, self::ASCII_MAX];
        }

        return new self($complement);
    }

    public function sampleChar(): ?string
    {
        if ($this->unknown || [] === $this->ranges) {
            return null;
        }

        $value = $this->ranges[0][0] ?? null;
        if (null === $value) {
            return null;
        }

        return \chr($value & 0xFF);
    }
}
