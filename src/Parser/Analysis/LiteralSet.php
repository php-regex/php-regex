<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Parser\Analysis;

/**
 * Immutable set of literal strings extracted from regex patterns.
 *
 * Every match starts with one of the prefixes and ends with one of the
 * suffixes; an empty list says nothing about that end. When the set is
 * complete, the prefixes list every string the pattern matches. A list that
 * would pass the size limits is dropped rather than cut: a cut list would
 * leave out strings a match can start or end with.
 */
final readonly class LiteralSet
{
    private const MAX_SET_SIZE = 100; // Prevent memory explosion
    private const MAX_STRING_LENGTH = 1000; // Prevent extremely long literals

    /**
     * @var array<string>
     */
    public array $prefixes;

    /**
     * @var array<string>
     */
    public array $suffixes;

    public bool $complete;

    /**
     * @param array<string> $prefixes
     * @param array<string> $suffixes
     */
    public function __construct(
        array $prefixes = [],
        array $suffixes = [],
        bool $complete = false,
    ) {
        // Enforce size limits to prevent performance degradation
        $this->prefixes = \count($prefixes) > self::MAX_SET_SIZE ? [] : $prefixes;
        $this->suffixes = \count($suffixes) > self::MAX_SET_SIZE ? [] : $suffixes;

        // A complete set lists every string the pattern matches.
        $this->complete = $complete && [] !== $this->prefixes;
    }

    public static function empty(): self
    {
        return new self([], [], false);
    }

    public static function fromString(string $literal): self
    {
        // Limit string length to prevent memory issues
        if (\strlen($literal) > self::MAX_STRING_LENGTH) {
            return self::empty();
        }

        return new self([$literal], [$literal], true);
    }

    public function concat(self $other): self
    {
        $newPrefixes = $this->prefixes;
        $newSuffixes = $other->suffixes;
        $newComplete = $this->complete && $other->complete;

        // Only compute cross products when necessary and possible. When one
        // does not fit, the shorter side is still true of every match.
        if ($this->complete && !empty($other->prefixes)) {
            $product = $this->crossProduct($this->prefixes, $other->prefixes);
            if (null === $product) {
                $newComplete = false;
            } else {
                $newPrefixes = $product;
            }
        }

        if ($other->complete && !empty($this->suffixes)) {
            $product = $this->crossProduct($this->suffixes, $other->suffixes);
            if (null === $product) {
                $newComplete = false;
            } else {
                $newSuffixes = $product;
            }
        }

        $newPrefixes = $this->deduplicate($newPrefixes);

        return new self($newPrefixes, $this->deduplicate($newSuffixes), $newComplete && [] !== $newPrefixes);
    }

    public function unite(self $other): self
    {
        // Fast path for identical sets
        if ($this === $other) {
            return $this;
        }

        // A side about which nothing is known makes the union unknown too.
        $newPrefixes = [] === $this->prefixes || [] === $other->prefixes ? [] : array_merge($this->prefixes, $other->prefixes);
        $newSuffixes = [] === $this->suffixes || [] === $other->suffixes ? [] : array_merge($this->suffixes, $other->suffixes);
        $newPrefixes = $this->deduplicate($newPrefixes);

        return new self($newPrefixes, $this->deduplicate($newSuffixes), $this->complete && $other->complete && [] !== $newPrefixes);
    }

    public function getLongestPrefix(): ?string
    {
        return $this->computeLongestString($this->prefixes);
    }

    public function getLongestSuffix(): ?string
    {
        return $this->computeLongestString($this->suffixes);
    }

    public function isVoid(): bool
    {
        return empty($this->prefixes) && empty($this->suffixes);
    }

    /**
     * Cross product within the size limits, or null when it does not fit:
     * too many combinations, or one too long.
     *
     * @param array<string> $left
     * @param array<string> $right
     *
     * @return array<string>|null
     */
    private function crossProduct(array $left, array $right): ?array
    {
        if (\count($left) * \count($right) > self::MAX_SET_SIZE) {
            return null;
        }

        $result = [];
        foreach ($left as $l) {
            foreach ($right as $r) {
                $combined = $l.$r;
                if (\strlen($combined) > self::MAX_STRING_LENGTH) {
                    return null;
                }

                $result[] = $combined;
            }
        }

        return $result;
    }

    /**
     * Memory-efficient deduplication with size limits.
     *
     * @param array<string> $items
     *
     * @return array<string>
     */
    private function deduplicate(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        // A list past the size limit is dropped, not cut.
        $unique = array_values(array_unique($items));

        return \count($unique) > self::MAX_SET_SIZE ? [] : $unique;
    }

    /**
     * Optimized longest string computation with early termination.
     *
     * @param array<string> $candidates
     */
    /**
     * @param array<string> $candidates
     */
    private function computeLongestString(array $candidates): ?string
    {
        if (empty($candidates)) {
            return null;
        }

        $longest = '';
        $maxLength = 0;

        foreach ($candidates as $candidate) {
            $length = \strlen($candidate);
            if ($length > $maxLength) {
                $longest = $candidate;
                $maxLength = $length;
            }
        }

        // Handle edge case where longest is empty string but empty isn't in candidates
        return 0 === $maxLength && !\in_array('', $candidates, true) ? null : $longest;
    }
}
