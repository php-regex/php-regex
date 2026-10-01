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
final readonly class ProofResult
{
    /**
     * @param list<string> $otherSuffixes
     * @param list<string> $abstractions
     */
    public function __construct(
        public RedosComplexity $complexity,
        public ?int $degree,
        public ?string $prefix,
        public ?string $pump,
        public ?string $suffix,
        public array $otherSuffixes,
        public bool $unicode,
        public array $abstractions,
        public bool $withoutMatches = false,
    ) {}
}
