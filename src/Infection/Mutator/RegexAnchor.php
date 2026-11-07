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
 * The anchors of a regex literal: ^, $, \A, \z, \Z, \b and \B go; $ and \z swap.
 *
 * @see https://php-regex.com/guides/infection/
 */
final class RegexAnchor extends RegexMutator
{
    public static function getDefinition(): Definition
    {
        return new Definition(
            <<<'TXT'
                Removes the anchors and word boundaries of a regex literal passed to a native preg_*() function ("^", "$", "\A", "\z", "\Z", "\b", "\B"), and swaps "$" and "\z", which differ on a subject ending with a newline. Mutants proven to behave as the original for the call are not produced.
                TXT,
            MutatorCategory::SEMANTIC_REDUCTION,
            <<<'TXT'
                Test a subject with text before or after what the pattern must match whole, and one ending with a newline. Each mutant carries a subject that kills it, written before the closing delimiter as (?#kill: "…"): a PHP double-quoted literal to pass to the call in a test. A mutant without one is one no subject was found for, or one whose call or spelling leaves the kill string out: the Infection guide lists each case.
                TXT,
            <<<'DIFF'
                - preg_match('/^\d+$/', $id);
                + preg_match('/^\d+\z(?#kill: "0\x0a")/', $id);
                DIFF,
        );
    }

    protected static function family(): Family
    {
        return Family::Anchor;
    }
}
