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
 * The modifiers of a regex literal: each goes, u aside.
 *
 * @see https://php-regex.com/guides/infection/
 */
final class RegexFlag extends RegexMutator
{
    public static function getDefinition(): Definition
    {
        return new Definition(
            <<<'TXT'
                Removes each trailing modifier of a regex literal passed to a native preg_*() function, "u" aside. Mutants proven to behave as the original for the call are not produced: a modifier the pattern does not use is no gap in the tests.
                TXT,
            MutatorCategory::SEMANTIC_REDUCTION,
            <<<'TXT'
                Test what each modifier changes: a letter in the other case for "i", a newline for "s", "m" and "D". Each mutant carries a subject that kills it, written before the closing delimiter as (?#kill: "…"): a PHP double-quoted literal to pass to the call in a test. A mutant without one is one no subject was found for, or one whose call or spelling leaves the kill string out: the Infection guide lists each case.
                TXT,
            <<<'DIFF'
                - preg_match('/^yes$/i', $answer);
                + preg_match('/^yes$(?#kill: "YES")/', $answer);
                DIFF,
        );
    }

    protected static function family(): Family
    {
        return Family::Flag;
    }
}
