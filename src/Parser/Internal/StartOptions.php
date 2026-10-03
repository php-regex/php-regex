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
    private const NAMES = [
        'UTF' => true, 'UTF8' => true, 'UCP' => true,
        'CR' => true, 'LF' => true, 'CRLF' => true, 'ANYCRLF' => true, 'ANY' => true, 'NUL' => true,
        'BSR_ANYCRLF' => true, 'BSR_UNICODE' => true,
        'NOTEMPTY' => true, 'NOTEMPTY_ATSTART' => true,
        'NO_AUTO_POSSESS' => true, 'NO_DOTSTAR_ANCHOR' => true, 'NO_JIT' => true, 'NO_START_OPT' => true,
        'LIMIT_MATCH' => true, 'LIMIT_DEPTH' => true, 'LIMIT_HEAP' => true, 'LIMIT_RECURSION' => true,
    ];

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
     * Whether the options turn UTF mode on: "(*UTF)", or "(*UTF8)", which the
     * 8-bit library takes as the same option.
     */
    public static function turnUtfOn(string $source): bool
    {
        $options = self::of($source);

        return str_contains($options, '(*UTF)') || str_contains($options, '(*UTF8)');
    }
}
