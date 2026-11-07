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
 * The escaped metacharacters of a regex literal: the backslash goes.
 *
 * @see https://php-regex.com/guides/infection/
 */
final class RegexEscape extends RegexMutator
{
    public static function getDefinition(): Definition
    {
        return new Definition(
            <<<'TXT'
                Takes the backslash from an escaped metacharacter outside a class in a regex literal passed to a native preg_*() function ("\.", "\$", "\^", "\|", "\?", "\*", "\+", "\{"), as when a dot meant literally is written unescaped. Mutants the engine refuses, or proven to behave as the original for the call, are not produced.
                TXT,
            MutatorCategory::SEMANTIC_ADDITION,
            <<<'TXT'
                Test a subject with another character where the escaped one stands. Each mutant carries a subject that kills it, written before the closing delimiter as (?#kill: "…"): a PHP double-quoted literal to pass to the call in a test. A mutant without one is one no subject was found for, or one whose call or spelling leaves the kill string out: the Infection guide lists each case.
                TXT,
            <<<'DIFF'
                - preg_match('/^v\d+\.\d+$/', $version);
                + preg_match('/^v\d+.\d+$(?#kill: "v0!0")/', $version);
                DIFF,
        );
    }

    protected static function family(): Family
    {
        return Family::Escape;
    }
}
