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

/**
 * The patterns and the range the tests of a single-pattern command over a
 * range of PHP versions judge, read first on the running engine with
 * RangeFixtureReadings.
 */
final class RangePatterns
{
    /**
     * Compiles up to PHP 8.4 and is refused from 8.5, which compiles
     * without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK.
     */
    public const KEEP_IN_LOOKBEHIND = '/(?<=a\Kb)c/';

    /**
     * Text before PCRE2 10.43, a quantifier ("{0,3}") from it.
     */
    public const QUANTIFIER_WITHOUT_MIN = '/a{,3}/';

    /**
     * The versions ">=8.1" is judged at: its floor, then every PHP version
     * a rule of the library changes at above it, up to the last it knows.
     */
    public const FROM_8_1 = [80100, 80200, 80300, 80400, 80425, 80500, 80510];
}
