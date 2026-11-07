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

namespace PHPRegex\Infection\Mutator;

use Infection\Mutator\Definition;
use Infection\Mutator\MutatorCategory;
use PHPRegex\Infection\Mutation\Family;

/**
 * The repeats of a regex literal: + and * swap, bounds move by one, the repeat goes, laziness turns.
 *
 * @see https://php-regex.com/guides/infection/
 */
final class RegexQuantifier extends RegexMutator
{
    public static function getDefinition(): Definition
    {
        return new Definition(
            <<<'TXT'
                Mutates the repeats of a regex literal passed to a native preg_*() function: "+" and "*" swap, each bound of "{m,n}" moves by one, the repeat goes, and a greedy repeat turns lazy or a lazy or possessive one greedy. Mutants proven to behave as the original for the call are not produced.
                TXT,
            MutatorCategory::ORTHOGONAL_REPLACEMENT,
            <<<'TXT'
                Test the boundaries of each repeat: the fewest and the most repetitions it takes, one fewer and one more. Each mutant carries a subject that kills it, written before the closing delimiter as (?#kill: "…"): a PHP double-quoted literal to pass to the call in a test. A mutant without one is one no subject was found for, or one whose call or spelling leaves the kill string out: the Infection guide lists each case.
                TXT,
            <<<'DIFF'
                - preg_match('/^\d{2,4}$/', $code);
                + preg_match('/^\d{3,4}$(?#kill: "00")/', $code);
                DIFF,
        );
    }

    protected static function family(): Family
    {
        return Family::Quantifier;
    }
}
