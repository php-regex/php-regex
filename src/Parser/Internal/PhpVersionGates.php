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

namespace PHPRegex\Parser\Internal;

/**
 * Every PHP version a rule of the library changes at, by name: the parser
 * and the validator compare the PHP judged with these, never with a raw
 * number, so POINTS names every version where a verdict may change.
 *
 * Comparisons with PHP_VERSION_ID ask about the PHP running the library,
 * not about the one judged, and are not gates.
 *
 * @internal
 */
final class PhpVersionGates
{
    /**
     * The oldest PHP the library judges.
     */
    public const FLOOR = 80200;

    /**
     * PHP reads the "n" modifier (PCRE2_NO_AUTO_CAPTURE) from 8.2.
     */
    public const NO_AUTO_CAPTURE_MODIFIER = 80200;

    /**
     * PHP dropped the "e" modifier in 7.0.
     */
    public const EVAL_MODIFIER_REMOVED = 70000;

    /**
     * PHP 8.3 bundles PCRE2 10.42.
     */
    public const BUNDLES_PCRE2_10_42 = 80300;

    /**
     * PHP 8.4 bundles PCRE2 10.44, as 8.5 does.
     */
    public const BUNDLES_PCRE2_10_44 = 80400;

    /**
     * PHP 8.4 reads the "r" modifier (PCRE2_EXTRA_CASELESS_RESTRICT) when
     * built against PCRE2 10.43 or later.
     */
    public const CASELESS_RESTRICT_MODIFIER = 80400;

    /**
     * PHP 8.4.25 compiles "u" patterns with PCRE2_NEVER_BACKSLASH_C
     * (GH-21134).
     */
    public const NEVER_BACKSLASH_C_UNDER_UTF_8_4 = 80425;

    /**
     * PHP 8.5 compiles without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK: "\K" in a
     * lookaround is refused. 8.5.0 to 8.5.9 compile "\C" under "u" again.
     */
    public const PHP_8_5 = 80500;

    public const NO_KEEP_IN_LOOKAROUND = self::PHP_8_5;

    /**
     * PHP 8.5.10 compiles "u" patterns with PCRE2_NEVER_BACKSLASH_C again.
     */
    public const NEVER_BACKSLASH_C_UNDER_UTF_8_5 = 80510;

    /**
     * The PHP versions a verdict may change at, ascending: the floor, then
     * every gate above it.
     */
    public const POINTS = [
        self::FLOOR,
        self::BUNDLES_PCRE2_10_42,
        self::BUNDLES_PCRE2_10_44,
        self::NEVER_BACKSLASH_C_UNDER_UTF_8_4,
        self::NO_KEEP_IN_LOOKAROUND,
        self::NEVER_BACKSLASH_C_UNDER_UTF_8_5,
    ];

    /**
     * Whether PHP compiles a "u" pattern with PCRE2_NEVER_BACKSLASH_C: 8.4
     * from 8.4.25, 8.5 from 8.5.10.
     */
    public static function neverBackslashCUnderUtf(int $phpVersionId): bool
    {
        return $phpVersionId >= self::NEVER_BACKSLASH_C_UNDER_UTF_8_5
            || ($phpVersionId >= self::NEVER_BACKSLASH_C_UNDER_UTF_8_4 && $phpVersionId < self::PHP_8_5);
    }
}
