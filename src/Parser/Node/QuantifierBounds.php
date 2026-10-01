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

namespace PhpRegex\Parser\Node;

/**
 * Canonical parsed form of a quantifier token.
 *
 * This is the single source of truth for quantifier bounds; every visitor
 * must use it instead of re-implementing the parsing, so that inputs like
 * "{,5}" or "{ 2 }" (extended mode) mean the same thing everywhere.
 */
final readonly class QuantifierBounds
{
    private function __construct(
        public int $min,
        /**
         * Maximum repetitions, or null when unbounded ("*", "+", "{n,}").
         */
        public ?int $max,
    ) {}

    /**
     * Parse a quantifier token ("*", "+", "?", "{n}", "{n,}", "{n,m}", "{,m}",
     * with optional lazy/possessive suffix and optional whitespace inside the
     * braces). Returns null when the token is not a valid quantifier.
     */
    public static function parse(string $quantifier): ?self
    {
        // Strip a lazy ("a+?") or possessive ("a++") suffix.
        $base = $quantifier;
        if ('' !== $base && \in_array($base[\strlen($base) - 1], ['?', '+'], true) && \strlen($base) > 1) {
            $candidate = substr($base, 0, -1);
            if (\in_array($candidate, ['*', '+', '?'], true) || str_ends_with($candidate, '}')) {
                $base = $candidate;
            }
        }

        switch ($base) {
            case '*':
                return new self(0, null);
            case '+':
                return new self(1, null);
            case '?':
                return new self(0, 1);
        }

        if (1 !== preg_match('/^\{\s*(\d*)\s*(?:(,)\s*(\d*)\s*)?\}$/', $base, $m)) {
            return null;
        }

        $minDigits = $m[1];
        $hasComma = ($m[2] ?? '') === ',';
        $maxDigits = $m[3] ?? '';

        if ('' === $minDigits && !$hasComma) {
            return null; // "{}" is not a quantifier
        }
        if ('' === $minDigits && '' === $maxDigits) {
            return null; // "{,}" is not a quantifier
        }

        $min = '' === $minDigits ? 0 : (int) $minDigits;

        if (!$hasComma) {
            return new self($min, $min);
        }

        return new self($min, '' === $maxDigits ? null : (int) $maxDigits);
    }

    public function isUnbounded(): bool
    {
        return null === $this->max;
    }
}
