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

/**
 * What check_escape() makes of "\N{" in a class, before the class reads
 * the escape or a range ending on it.
 *
 * Up to PCRE2 10.44 it reads a count after "\N", as outside a class: a
 * brace that is no count is a character name, which PCRE2 does not
 * support; a count whose numbers it refuses is refused; a count it reads
 * leaves "\N", which the class refuses. From 10.45 the brace is a
 * character name whatever follows it. "U+" after the brace, past spaces and
 * tabs from 10.43, is a code point.
 *
 * @internal
 */
final class NamedCharacterBrace
{
    private const MAX_COUNT = 65535;

    /**
     * The error check_escape() gives "\N" followed by the "{" at $brace in a
     * class, null when it reads a count there. A code point, which
     * namesCodePoint() tells, is judged apart, before.
     */
    public static function errorInClass(string $pattern, int $brace, PcreTarget $target): ?ErrorCode
    {
        if ($target->supports(PcreFeature::NamedCharacterBraceRefusedInClass)) {
            return ErrorCode::EscapeUnsupported;
        }

        $blank = $target->supports(PcreFeature::OpenAndPaddedRepeatCounts) ? '[ \t]*+' : '';

        if (1 !== LibraryPcre::match('/\G\{'.$blank.'(\d*+)'.$blank.'(?:(,)'.$blank.'(\d*+)'.$blank.')?\}/', $pattern, $count, \PREG_UNMATCHED_AS_NULL, $brace)) {
            return ErrorCode::EscapeUnsupported;
        }

        $minimum = $count[1] ?? '';
        $maximum = $count[3] ?? '';
        if ('' === $minimum && ('' === $blank || '' === $maximum)) {
            return ErrorCode::EscapeUnsupported;
        }

        if (self::tooBig($minimum) || self::tooBig($maximum)) {
            return ErrorCode::QuantifierTooBig;
        }

        return '' !== $minimum && '' !== $maximum && (int) $maximum < (int) $minimum ? ErrorCode::QuantifierInvalidRange : null;
    }

    /**
     * Whether "\N" followed by the "{" at $brace names a code point, "U+"
     * after the brace, past spaces and tabs from PCRE2 10.43: one PCRE2
     * reads under UTF, refused otherwise.
     */
    public static function namesCodePoint(string $pattern, int $brace, PcreTarget $target): bool
    {
        $blank = $target->supports(PcreFeature::OpenAndPaddedRepeatCounts) ? '[ \t]*+' : '';

        return 1 === LibraryPcre::match('/\G\{'.$blank.'U\+/', $pattern, $codePoint, 0, $brace);
    }

    private static function tooBig(string $digits): bool
    {
        $digits = ltrim($digits, '0');

        return \strlen($digits) > 5 || (int) $digits > self::MAX_COUNT;
    }
}
