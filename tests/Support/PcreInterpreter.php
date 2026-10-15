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

namespace PHPRegex\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * A pattern run by PCRE2's interpreter whatever pcre.jit says: "(*NO_JIT)"
 * right after the opening delimiter.
 *
 * ini_set('pcre.jit', '0') is not enough for an engine fact the JIT would
 * answer differently (a backtrack or recursion limit hit, a step count). PHP
 * caches a compiled pattern with its JIT code: once an earlier call compiled
 * the same pattern with the JIT on, the cached entry runs the JIT whatever
 * the ini says now. A pattern that asks for the interpreter itself is a key
 * of its own in that cache and is never run by the JIT.
 *
 * Start-of-pattern items stack in any order: "(*NO_JIT)" may lead
 * "(*UTF)", "(*NO_START_OPT)" or "(*LIMIT_MATCH=n)".
 */
final class PcreInterpreter
{
    public const VERB = '(*NO_JIT)';

    /**
     * Delimiters the verb holds: the pattern would end inside it.
     */
    private const HELD_BY_THE_VERB = ['*', '_', ')'];

    /**
     * The pattern with "(*NO_JIT)" after its opening delimiter, white space
     * PHP skips before the delimiter kept as it is.
     */
    public static function pattern(string $pattern): string
    {
        $offset = \strlen($pattern) - \strlen(ltrim($pattern, " \t\n\r\v\f"));
        Assert::assertLessThan(\strlen($pattern), $offset, 'No delimiter in '.$pattern);

        $delimiter = $pattern[$offset];
        Assert::assertNotContains($delimiter, self::HELD_BY_THE_VERB, \sprintf('The delimiter of %s ends the pattern inside %s.', $pattern, self::VERB));

        return substr($pattern, 0, $offset + 1).self::VERB.substr($pattern, $offset + 1);
    }
}
