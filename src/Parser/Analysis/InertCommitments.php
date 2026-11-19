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

namespace PHPRegex\Parser\Analysis;

use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Syntax\StartOptions;

/**
 * The possessive quantifiers and the atomic groups of a pattern that read
 * as their backtracking spelling, by the offset each starts at in the
 * pattern: "\R" is an atomic group that starts where it is written.
 *
 * A printer for an engine without possessive quantifiers or atomic groups
 * asks it of a pattern searched, and prints the ones it names plain.
 */
final class InertCommitments
{
    /**
     * @internal built by AtomicInertiaAnalyzer
     *
     * @param array<int, true> $possessives
     * @param array<int, true> $atomics
     */
    public function __construct(private readonly array $possessives = [], private readonly array $atomics = []) {}

    /**
     * The commitments of a pattern searched, as preg_match() searches it,
     * that commit to nothing: none when the pattern cannot be read. Its
     * line ends are read under the newline convention its start options
     * name.
     */
    public static function of(RegexNode $regex): self
    {
        try {
            $lineFeedNewline = 'LF' === StartOptions::newline($regex->source ?? '');

            return (new AtomicInertiaAnalyzer(wholeSubject: false, lineFeedNewline: $lineFeedNewline))->inertCommitments((new HirTranslator())->translate($regex));
        } catch (\Throwable) {
            return new self();
        }
    }

    public function isInertPossessive(int $startPosition): bool
    {
        return isset($this->possessives[$startPosition]);
    }

    public function isInertAtomic(int $startPosition): bool
    {
        return isset($this->atomics[$startPosition]);
    }

    public function isEmpty(): bool
    {
        return [] === $this->possessives && [] === $this->atomics;
    }
}
