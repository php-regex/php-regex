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
 * The options a pattern opens with, such as "(*UTF)(*LIMIT_MATCH=10)": PCRE
 * reads them there only, one after the other, nothing between.
 *
 * @internal
 */
final class StartOptions
{
    /**
     * Every option PCRE2 reads in the opening run; the same list as the
     * validator's start-of-pattern settings, kept in step with it.
     */
    private const NAMES = [
        'UTF' => true, 'UTF8' => true, 'UCP' => true,
        'CR' => true, 'LF' => true, 'CRLF' => true, 'ANYCRLF' => true, 'ANY' => true, 'NUL' => true,
        'BSR_ANYCRLF' => true, 'BSR_UNICODE' => true,
        'NOTEMPTY' => true, 'NOTEMPTY_ATSTART' => true,
        'NO_AUTO_POSSESS' => true, 'NO_DOTSTAR_ANCHOR' => true, 'NO_JIT' => true, 'NO_START_OPT' => true,
        'LIMIT_MATCH' => true, 'LIMIT_DEPTH' => true, 'LIMIT_HEAP' => true, 'LIMIT_RECURSION' => true,
        'CASELESS_RESTRICT' => true, 'TURKISH_CASING' => true,
    ];

    private const NEWLINES = ['CR' => true, 'LF' => true, 'CRLF' => true, 'ANYCRLF' => true, 'ANY' => true, 'NUL' => true];

    /**
     * The run of options the source opens with, as written.
     */
    public static function of(string $source): string
    {
        $end = 0;
        while ('(*' === substr($source, $end, 2)) {
            $close = strpos($source, ')', $end);
            if (false === $close) {
                break;
            }

            $item = substr($source, $end + 2, $close - $end - 2);
            $equals = strpos($item, '=');
            $name = false === $equals ? $item : substr($item, 0, $equals);
            $value = false === $equals ? null : substr($item, $equals + 1);
            if (!isset(self::NAMES[$name]) || (str_starts_with($name, 'LIMIT_') ? null === $value || !Ascii::isDigit($value) : null !== $value)) {
                break;
            }

            $end = $close + 1;
        }

        return substr($source, 0, $end);
    }

    /**
     * The newline convention the options set, "LF" when they set none: the
     * last of "(*CR)", "(*LF)", "(*CRLF)", "(*ANYCRLF)", "(*ANY)", "(*NUL)".
     */
    public static function newline(string $source): string
    {
        $newline = 'LF';
        foreach (explode(')(*', substr(self::of($source), 2, -1)) as $option) {
            if (isset(self::NEWLINES[$option])) {
                $newline = $option;
            }
        }

        return $newline;
    }

    /**
     * Whether the options turn UTF mode on: "(*UTF)", or "(*UTF8)", which the
     * 8-bit library takes as the same option.
     */
    public static function turnUtfOn(string $source): bool
    {
        $options = self::of($source);

        return str_contains($options, '(*UTF)') || str_contains($options, '(*UTF8)');
    }
}
