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
 * The classes of a regex literal: negated, a member removed, a range narrowed, \d \w \s turned.
 *
 * @see https://php-regex.com/guides/infection/
 */
final class RegexCharClass extends RegexMutator
{
    public static function getDefinition(): Definition
    {
        return new Definition(
            <<<'TXT'
                Mutates the character classes of a regex literal passed to a native preg_*() function: a class is negated, loses one member, or has a range narrowed by one character at either end; "\d", "\w" and "\s" turn into their complement. Mutants proven to behave as the original for the call are not produced.
                TXT,
            MutatorCategory::ORTHOGONAL_REPLACEMENT,
            <<<'TXT'
                Test each member of a class, and the characters at both ends of each range. Each mutant carries a subject that kills it, written before the closing delimiter as (?#kill: "…"): a PHP double-quoted literal to pass to the call in a test. A mutant without one is one no subject was found for, or one whose call or spelling leaves the kill string out: the Infection guide lists each case.
                TXT,
            <<<'DIFF'
                - preg_match('/^[a-z]+$/', $slug);
                + preg_match('/^[b-z]+$(?#kill: "a")/', $slug);
                DIFF,
        );
    }

    protected static function family(): Family
    {
        return Family::CharClass;
    }
}
