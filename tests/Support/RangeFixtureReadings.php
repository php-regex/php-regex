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
 * What the running engine makes of the patterns the range tests judge, read
 * before the library's verdict on them: a test that asserts a later PHP
 * refuses or reads a pattern otherwise first checks the reading it can
 * check here.
 */
trait RangeFixtureReadings
{
    /**
     * "a{,3}" is a quantifier from PCRE2 10.43 ("{0,3}"), text before.
     */
    private static function assertEngineReadsQuantifierWithoutMin(): void
    {
        $quantifier = version_compare(explode(' ', \PCRE_VERSION)[0], '10.43', '>=');

        Assert::assertSame($quantifier ? 1 : 0, preg_match('/^a{,3}$/', 'aa'), 'PCRE2 '.\PCRE_VERSION);
        Assert::assertSame($quantifier ? 0 : 1, preg_match('/^a{,3}$/', 'a{,3}'), 'PCRE2 '.\PCRE_VERSION);
    }

    /**
     * The first point after the floor whose PCRE2 reads "a{,3}" otherwise
     * than the floor's (a quantifier from 10.43, text before), in either
     * direction, null when none does: 8.4 with the bundled releases, 8.2
     * where the floor's PHP links 10.43 or later and 8.2 bundles 10.40.
     * The class reads each point's PCRE2 with PcreRule::judgedPcre().
     *
     * @param list<int> $points the floor first
     */
    private static function readsQuantifierOtherwiseFrom(array $points): ?int
    {
        $reads = static fn (int $point): bool => version_compare(self::judgedPcre($point), '10.43', '>=');
        foreach (\array_slice($points, 1) as $point) {
            if ($reads($point) !== $reads($points[0])) {
                return $point;
            }
        }

        return null;
    }

    /**
     * "\K" in a lookbehind compiles up to PHP 8.4 and is refused from PHP
     * 8.5, which no longer sets PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK.
     */
    private static function assertEngineKeepInLookbehind(): void
    {
        $compiles = \PHP_VERSION_ID < 80500;

        Assert::assertSame($compiles ? 1 : false, @preg_match('/(?<=a\Kb)c/', 'abc'), 'PHP '.\PHP_VERSION.', PCRE2 '.\PCRE_VERSION);
    }

    /**
     * A group that holds a reference to itself is backtracked into from
     * PCRE2 10.35, which every PHP this suite runs on links or bundles:
     * "abb" is found in "abb".
     */
    private static function assertEngineBacktracksIntoSelfReference(): void
    {
        Assert::assertSame(1, preg_match('/^(a|ab|\1x)+b$/', 'abb', $match), 'PCRE2 '.\PCRE_VERSION);
        Assert::assertSame('abb', $match[0]);
    }
}
