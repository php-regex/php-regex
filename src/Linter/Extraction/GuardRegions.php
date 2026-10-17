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

namespace PHPRegex\Linter\Extraction;

use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Linter\PatternOccurrence;

/**
 * The stretches of a PHP source a guard on the PHP version holds over, by
 * byte offset: the body of an "if", an "elseif" or an "else", a branch of a
 * ternary, an arm of "match (true)", the rest of a block after an early
 * exit. A pattern is judged for the versions every stretch around its
 * literal keeps.
 *
 * @internal
 */
final class GuardRegions
{
    /**
     * @var list<array{int, int, TargetConstraint}>
     */
    private array $regions = [];

    /**
     * The versions kept from $from to $to, both included.
     */
    public function add(int $from, int $to, TargetConstraint $constraint): void
    {
        if ($from <= $to) {
            $this->regions[] = [$from, $to, $constraint];
        }
    }

    /**
     * The versions the code at an offset runs on, as the guards around it
     * say them; null when no guard holds there, or when the offset is not
     * known.
     */
    public function at(?int $offset): ?TargetConstraint
    {
        if (null === $offset) {
            return null;
        }

        $constraint = null;
        foreach ($this->regions as [$from, $to, $kept]) {
            if ($offset >= $from && $offset <= $to) {
                $constraint = $constraint?->intersect($kept) ?? $kept;
            }
        }

        return $constraint;
    }

    /**
     * The occurrences, each judged for the versions the guards around its
     * literal keep.
     *
     * @param list<PatternOccurrence> $occurrences
     *
     * @return list<PatternOccurrence>
     */
    public function apply(array $occurrences): array
    {
        if ([] === $this->regions) {
            return $occurrences;
        }

        return array_map(fn (PatternOccurrence $occurrence): PatternOccurrence => $occurrence->guardedBy($this->at($occurrence->fileOffset)), $occurrences);
    }
}
