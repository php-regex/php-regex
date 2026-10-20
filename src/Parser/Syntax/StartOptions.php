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

namespace PHPRegex\Parser\Syntax;

use PHPRegex\Parser\Text\Ascii;

/**
 * The options a pattern opens with, such as "(*UTF)(*LIMIT_MATCH=10)": PCRE
 * reads them there only, one after the other, nothing between.
 *
 * @internal
 */
final class StartOptions
{
    /**
     * Every option PCRE2 reads in the opening run, and only there: anywhere
     * else they are not verbs at all.
     */
    public const NAMES = [
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
     * What an option is written with: the capitals, digits and "_" of its
     * name, then "=" and the digits of a limit.
     */
    private const OPTION_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_=';

    /**
     * The run of options the source opens with, as written.
     */
    public static function of(string $source): string
    {
        $end = 0;
        // An option is read up to its ")" over the characters it is written
        // with alone: anything else ends the run, and the rest of the source
        // is not searched for a ")", nor copied, at every "(*" it opens with.
        while ('(' === ($source[$end] ?? '') && '*' === ($source[$end + 1] ?? '')) {
            $length = strspn($source, self::OPTION_CHARACTERS, $end + 2);
            $close = $end + 2 + $length;
            if (')' !== ($source[$close] ?? '')) {
                break;
            }

            $item = substr($source, $end + 2, $length);
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
     * Each option of the opening run, in order, as written between "(*" and
     * ")": "UTF", "LIMIT_MATCH=10".
     *
     * @return list<string>
     */
    public static function items(string $source): array
    {
        $options = self::of($source);

        return '' === $options ? [] : explode(')(*', substr($options, 2, -1));
    }

    /**
     * The newline convention the options set, "LF" when they set none: the
     * last of "(*CR)", "(*LF)", "(*CRLF)", "(*ANYCRLF)", "(*ANY)", "(*NUL)".
     */
    public static function newline(string $source): string
    {
        $newline = 'LF';
        foreach (self::items($source) as $option) {
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
