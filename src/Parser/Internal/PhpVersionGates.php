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
     * PHP 7.3 is the first PHP on PCRE2 (10.32): 7.2 and earlier link
     * PCRE1, another engine, which the library does not judge.
     */
    public const PCRE2 = 70300;

    /**
     * The oldest PHP the library judges: the first on PCRE2. The whole 7.3
     * series bundles PCRE2 10.32.
     */
    public const FLOOR = self::PCRE2;

    /**
     * PHP 7.4 bundles PCRE2 10.33 from 7.4.0, 10.34 from 7.4.6 and 10.35
     * from 7.4.12: a series is judged with the release of its x.y.0.
     */
    public const BUNDLES_PCRE2_10_33 = 70400;

    /**
     * PHP 8.0 bundles PCRE2 10.35.
     */
    public const BUNDLES_PCRE2_10_35 = 80000;

    /**
     * PHP 8.0 compiles without PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, and reads
     * the "X" modifier as nothing. PHP 7.3 and 7.4 compile with it unless
     * the pattern has the "X" modifier (php_pcre.c): an escape PCRE2 refuses
     * as it reads it, "\y", "\x{zz}" or "\p{Bogus}", is then the character
     * after the backslash, and what follows it is read again.
     */
    public const BAD_ESCAPE_IS_REFUSED = 80000;

    /**
     * PHP 8.1.1 and later bundle PCRE2 10.39; 8.1.0 bundled 10.37, judged
     * as 10.39.
     */
    public const BUNDLES_PCRE2_10_39 = 80100;

    /**
     * PHP 8.2 bundles PCRE2 10.40.
     */
    public const BUNDLES_PCRE2_10_40 = 80200;

    /**
     * PHP reads the "n" modifier (PCRE2_NO_AUTO_CAPTURE) from 8.2.
     */
    public const NO_AUTO_CAPTURE_MODIFIER = 80200;

    /**
     * PHP reads a NUL byte in a pattern from 8.2: before, it stops at the
     * first one and refuses the pattern ("Null byte in regex").
     */
    public const NUL_IN_PATTERN = 80200;

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
        self::BUNDLES_PCRE2_10_33,
        self::BUNDLES_PCRE2_10_35,
        self::BUNDLES_PCRE2_10_39,
        self::BUNDLES_PCRE2_10_40,
        self::BUNDLES_PCRE2_10_42,
        self::BUNDLES_PCRE2_10_44,
        self::NEVER_BACKSLASH_C_UNDER_UTF_8_4,
        self::NO_KEEP_IN_LOOKAROUND,
        self::NEVER_BACKSLASH_C_UNDER_UTF_8_5,
    ];

    /**
     * Whether PHP compiles a pattern with these modifiers with
     * PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL: 7.3 and 7.4, without "X".
     */
    public static function badEscapeIsLiteral(int $phpVersionId, string $flags): bool
    {
        return $phpVersionId < self::BAD_ESCAPE_IS_REFUSED && !str_contains($flags, 'X');
    }

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
