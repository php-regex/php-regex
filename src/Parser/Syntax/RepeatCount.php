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

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;
use PHPRegex\Parser\Token\Token;

/**
 * How PCRE reads the count of a quantifier, and where it refuses one.
 *
 * PCRE reads the numbers of a braced count before it asks whether the item
 * before it can repeat, and refuses each number as it reaches past 65535:
 * "{2,1}" and "{65536}" fail wherever they stand, and where the error is
 * reported moved across releases. Before PCRE2 10.37 it even reads the
 * digits after a "{" that turns out to be text: "a{65536 }", "a{1,65536"
 * and "\N{65536x}" are refused there, where a later release reads text or
 * a character name. The parser asks here where each of these is refused.
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

    /**
     * Where PCRE refuses the numbers of the count $quantifier spells: past
     * the digit that takes one over 65535, or past the maximum when they are
     * out of order. Null for a count PCRE reads, or no count at all. From
     * PCRE2 10.43, a count may be padded with spaces and tabs, and "{,n}" is
     * one.
     */
    public static function faultOffset(Token $quantifier, PcreTarget $target): ?int
    {
        $blank = $target->supports(PcreFeature::OpenAndPaddedRepeatCounts) ? '[ \t]*+' : '';
        if (1 !== LibraryPcre::match('/^\{'.$blank.'(\d*+)'.$blank.'(?:(,)'.$blank.'(\d*+)'.$blank.')?\}/', $quantifier->value, $matches, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        [$minimum, $minimumAt] = $matches[1];
        [$maximum, $maximumAt] = $matches[3];
        $minimum ??= '';
        $maximum ??= '';
        if ('' === $minimum && ('' === $blank || '' === $maximum)) {
            return null;
        }

        foreach ([[$minimum, $minimumAt], [$maximum, $maximumAt]] as [$digits, $at]) {
            $tooBig = self::tooBigOffset($digits, $quantifier->position + $at, $target);
            if (null !== $tooBig) {
                return $tooBig;
            }
        }

        return '' !== $maximum && '' !== $minimum && (int) $maximum < (int) $minimum ? $quantifier->position + $maximumAt + \strlen($maximum) : null;
    }

    /**
     * What is wrong with a count faultOffset() refuses: a number past 65535,
     * or else numbers out of order.
     */
    public static function faultCode(string $quantifier): ErrorCode
    {
        LibraryPcre::matchAll('/\d++/', $quantifier, $numbers);

        foreach ($numbers[0] as $digits) {
            if (\strlen(ltrim($digits, '0')) > 5 || (int) $digits > self::MAX_COUNT) {
                return ErrorCode::QuantifierTooBig;
            }
        }

        return ErrorCode::QuantifierInvalidRange;
    }

    /**
     * Where PCRE refuses a quantifier with nothing to repeat: once it has
     * read it, before any "?" or "+" that would make it lazy or possessive.
     * Numbers it cannot take are refused first, while it reads them.
     */
    public static function nothingToRepeatOffset(Token $quantifier, PcreTarget $target): int
    {
        $countError = self::faultOffset($quantifier, $target);
        if (null !== $countError) {
            return $countError;
        }

        $suffixed = \strlen($quantifier->value) > 1 && \in_array(substr($quantifier->value, -1), ['?', '+'], true);

        return $target->faultOffset($quantifier->end() - ($suffixed ? 1 : 0));
    }

    /**
     * Where PCRE refuses a number past 65535, a count or a group number,
     * whose digits start at $at: past the whole number from PCRE2 10.45,
     * past the digit that takes it over before. Null for one within.
     */
    public static function tooBigOffset(string $digits, int $at, PcreTarget $target): ?int
    {
        $value = 0;
        foreach (Bytes::split($digits) as $read => $digit) {
            $value = $value * 10 + (int) $digit;
            if ($value > self::MAX_COUNT) {
                return $at + ($target->supports(PcreFeature::NumberTooBigPastWholeNumber) ? \strlen($digits) : $read + 1);
            }
        }

        return null;
    }
}
