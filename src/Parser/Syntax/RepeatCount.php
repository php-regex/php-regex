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
 * How PCRE2 before 10.37 reads the count after a "{": the digits of the
 * first number, then a comma and the digits of the second, each refused as
 * it reaches past 65535, before anything checks that a "}" closes the
 * count. "a{65536 }", "a{1,65536" and "\N{65536x}" are refused there,
 * where a later release reads text or a character name.
 *
 * @internal
 */
final class RepeatCount
{
    private const MAX_COUNT = 65535;

    /**
     * Past the digit that takes the first number after the "{" at $brace,
     * or the one after its comma, over 65535. Null when neither does.
     */
    public static function overflowBeforeTen37(string $pattern, int $brace): ?int
    {
        $at = $brace + 1;
        for ($number = 0; $number < 2; $number++) {
            $start = $at;
            $value = 0;
            while (Ascii::isDigit($pattern[$at] ?? '')) {
                $value = $value * 10 + (int) $pattern[$at];
                $at++;
                if ($value > self::MAX_COUNT) {
                    return $at;
                }
            }

            if ($at === $start || ',' !== ($pattern[$at] ?? '')) {
                return null;
            }
            $at++;
        }

        return null;
    }
}
