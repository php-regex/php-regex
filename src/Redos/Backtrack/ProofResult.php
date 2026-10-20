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

namespace PHPRegex\Redos\Backtrack;

use PHPRegex\Redos\RedosComplexity;

/**
 * What the proof found: the class, the degree of a polynomial, and the
 * witness as raw bytes, with the other suffixes that also reject (one with a
 * character to read, one ending with a literal every match needs) for the
 * replay, and whether the witness needs the call without $matches (PHP
 * retries an empty match there, refusing it).
 *
 * @internal
 */
final class ProofResult
{
    /**
     * @param list<string> $otherSuffixes
     */
    public function __construct(
        public readonly RedosComplexity $complexity,
        public readonly ?int $degree,
        public readonly ?string $prefix,
        public readonly ?string $pump,
        public readonly ?string $suffix,
        public readonly array $otherSuffixes,
        public readonly bool $unicode,
        public readonly bool $withoutMatches = false,
    ) {}
}
