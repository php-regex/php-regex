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
 * The alternations of a regex literal: a branch goes, neighbouring branches swap.
 *
 * @see https://php-regex.com/guides/infection/
 */
final class RegexAlternation extends RegexMutator
{
    public static function getDefinition(): Definition
    {
        return new Definition(
            <<<'TXT'
                Removes each branch of an alternation in a regex literal passed to a native preg_*() function, and swaps neighbouring branches, which PCRE tries in order. Mutants proven to behave as the original for the call are not produced.
                TXT,
            MutatorCategory::SEMANTIC_REDUCTION,
            <<<'TXT'
                Test a subject for each branch, and one where two branches both match from the same place. Each mutant carries a subject that kills it, written before the closing delimiter as (?#kill: "…"): a PHP double-quoted literal to pass to the call in a test. A mutant without one is one no subject was found for, or one whose call or spelling leaves the kill string out: the Infection guide lists each case.
                TXT,
            <<<'DIFF'
                - preg_replace('/cat|dog/', 'pet', $text);
                + preg_replace('/dog(?#kill: "cat")/', 'pet', $text);
                DIFF,
        );
    }

    protected static function family(): Family
    {
        return Family::Alternation;
    }
}
