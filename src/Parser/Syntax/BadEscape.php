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
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Text\Ascii;

/**
 * The escapes PCRE2 refuses as it reads them, which PHP 7.3 and 7.4 read as
 * the character after the backslash: both compile with
 * PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL unless the pattern has the "X" modifier
 * (PhpVersionGates::badEscapeIsLiteral()). PCRE2 then reads the backslash
 * and that character as the character, and what follows as pattern text
 * again: "\o{8}" is "o" repeated eight times, "\p{Bogus}" the text
 * "p{Bogus}".
 *
 * The rules are those of check_escape() in pcre2_compile.c of PCRE2 10.32
 * to 10.35, the releases PHP 7.3 and 7.4 bundle, with what parse_regex()
 * reads right after it: the name of "\p", "\k" and "\g". Only an error met
 * there is a literal: a reference to no group, which PCRE2 finds once the
 * pattern is read, a property in a class and a class escape before a "-"
 * stay refused. 10.32 also reads as a literal an escape it refuses in a
 * class, an assertion or "\N" there, and a class escape ending a range;
 * 10.33 refuses them.
 *
 * @internal
 */
final class BadEscape
{
    /**
     * The letters of an escape that names no character, which a class holds
     * as a set or refuses: an assertion, a type, "\N", "\k".
     */
    public const CLASS_ESCAPE_LETTERS = 'ABCDGHKNPRSVWXZdhkpsvwz';
    /**
     * The largest number the digits after a backslash may make.
     */
    private const MAX_BACKSLASH_NUMBER = 214_748_363;

    /**
     * The characters a group name may hold, past which PCRE2 refuses it.
     */
    private const MAX_NAME_LENGTH = 32;

    /**
     * The characters "\p{...}" reads, its "}" included, past which the
     * sequence is malformed.
     */
    private const MAX_PROPERTY_READ = 31;

    /**
     * The escaped letters with no meaning, and the Perl case escapes.
     */
    private const UNKNOWN_LETTERS = 'FIJLMOTUYijlmquy';

    /**
     * The letters of the class escapes a class holds as a set.
     */
    private const CLASS_SET_LETTERS = 'DHSVWdhsvw';

    /**
     * Whether the escape whose backslash stands at $at, ending a range in a
     * class, is refused as an invalid range past its letter before PCRE2
     * 10.45: an assertion, "\C", "\k", or "\p" and "\P", whose name is not
     * read then. From 10.33 check_escape() refuses "\B", "\R" and "\X" in a
     * class first, on their letter; 10.32 does not. A type and "\N" are
     * judged where they are read.
     */
    public static function endsRangeAsInvalidRange(string $pattern, int $at, bool $pcre2Ten32): bool
    {
        $letter = $pattern[$at + 1] ?? '';

        return '\\' === ($pattern[$at] ?? '') && '' !== $letter && str_contains($pcre2Ten32 ? 'ABCGKRXZkzpP' : 'ACGKZkzpP', $letter);
    }

    /**
     * Whether the escape whose backslash stands at $at is one PCRE2 refuses
     * as it reads it, so that PHP 7.3 and 7.4 read the backslash and the
     * character after it as that character. A decision that hangs on the
     * groups opened before it, "\g{-1}" or "\400", is false while $captures
     * is null.
     *
     * @param bool       $inClass      whether the escape is in a class
     * @param bool       $utf          whether the pattern is read as UTF-8
     * @param PcreTarget $target       the PHP and PCRE2 judged
     * @param int|null   $captures     the groups PCRE2 numbered before it, null when unknown
     * @param bool       $rangeStarted whether a character and a "-" before it start a range in the class
     */
    public static function at(string $pattern, int $at, bool $inClass, bool $utf, PcreTarget $target, ?int $captures = null, bool $rangeStarted = false): bool
    {
        $letter = $pattern[$at + 1] ?? '';
        if (!Ascii::isAlnum($letter)) {
            return false;
        }

        $pcre2Ten32 = !$target->supports(PcreFeature::BadEscapeInClassStaysRefused);
        $after = $at + 2;
        if (str_contains(self::UNKNOWN_LETTERS, $letter)) {
            return true;
        }

        if ($inClass && str_contains(self::CLASS_ESCAPE_LETTERS, $letter)) {
            return self::refusedInClass($pattern, $letter, $after, $utf, $pcre2Ten32, $rangeStarted);
        }

        return match ($letter) {
            'N' => self::namedCharacterFails($pattern, $after, $utf),
            'p', 'P' => !self::readsProperty($pattern, $after, $target),
            'k' => self::namedReferenceFails($pattern, $after, $utf && !$pcre2Ten32 ? $target : null),
            'g' => !$inClass && self::gReferenceFails($pattern, $after, $utf && !$pcre2Ten32 ? $target : null, $captures),
            'o' => self::octalBracesFail($pattern, $after, $utf),
            'x' => '{' === ($pattern[$after] ?? '') && self::hexBracesFail($pattern, $after + 1, $utf),
            'c' => self::controlFails($pattern, $after),
            '0' => false,
            default => Ascii::isDigit($letter) && self::digitsFail($pattern, $at + 1, $inClass, $utf, $captures),
        };
    }

    /**
     * An escape of a letter that names no character, in a class: a class
     * escape that is a set stays one but for 10.32 ending a range; any other
     * is refused there, which 10.32 reads as its letter. A property is read
     * first, and PCRE2 refuses one it cannot read in a class.
     */
    private static function refusedInClass(string $pattern, string $letter, int $after, bool $utf, bool $pcre2Ten32, bool $rangeStarted): bool
    {
        // "\N{" is refused by check_escape() but for a count and, under UTF,
        // a code point, which is a character there.
        if ('N' === $letter && '{' === ($pattern[$after] ?? '')) {
            if (self::namedCharacterFails($pattern, $after, $utf)) {
                return true;
            }

            if ('U+' === substr($pattern, $after + 1, 2)) {
                return false;
            }
        }

        if (!$pcre2Ten32) {
            return false;
        }

        if ($rangeStarted) {
            return true;
        }

        return !str_contains(self::CLASS_SET_LETTERS.'pP', $letter);
    }

    /**
     * "\N{": a code point under UTF as "\N{U+41}", or a count, as "\N{2}";
     * anything else is refused, "U+" outside UTF included.
     */
    private static function namedCharacterFails(string $pattern, int $after, bool $utf): bool
    {
        if ('{' !== ($pattern[$after] ?? '')) {
            return false;
        }

        $inside = $after + 1;
        if (\strlen($pattern) - $inside > 1 && 'U+' === substr($pattern, $inside, 2)) {
            return !$utf || self::hexBracesFail($pattern, $inside + 2, $utf);
        }

        return !self::readsCount($pattern, $inside);
    }

    /**
     * Whether "{" then the text at $at is a count PCRE2 reads, "{2}", "{2,}"
     * or "{2,3}", in order and at most 65535.
     */
    private static function readsCount(string $pattern, int $at): bool
    {
        $min = self::readNumber($pattern, $at, PcreNumber::MAX);
        if (null === $min || false === $min[0]) {
            return false;
        }

        $at = $min[1];
        if ('}' === ($pattern[$at] ?? '')) {
            return true;
        }

        if (',' !== ($pattern[$at] ?? '')) {
            return false;
        }

        $at++;
        if ('}' === ($pattern[$at] ?? '')) {
            return true;
        }

        $max = self::readNumber($pattern, $at, PcreNumber::MAX);

        return null !== $max && false !== $max[0] && '}' === ($pattern[$max[1]] ?? '') && $max[0] >= $min[0];
    }

    /**
     * The digits at $at as a number, and where they end: null when no digit
     * stands there, false for the number when it goes past $max.
     *
     * @return array{0: int|false, 1: int}|null
     */
    private static function readNumber(string $pattern, int $at, int $max): ?array
    {
        if (!Ascii::isDigit($pattern[$at] ?? '')) {
            return null;
        }

        $value = 0;
        while (Ascii::isDigit($pattern[$at] ?? '')) {
            $value = $value * 10 + (int) $pattern[$at];
            $at++;
            if ($value > $max) {
                return [false, $at];
            }
        }

        return [$value, $at];
    }

    /**
     * Whether "\p" or "\P" reads a property: a letter, or a braced name of
     * at most 30 characters, a "^" before it, that the PCRE2 judged knows:
     * "\p{Vithkuqi}" is the text "p{Vithkuqi}" before 10.39.
     */
    private static function readsProperty(string $pattern, int $after, PcreTarget $target): bool
    {
        $first = $pattern[$after] ?? '';
        if ('{' !== $first) {
            return Ascii::isAlpha($first) && PropertyNames::knownBefore1040($first, $target);
        }

        $at = $after + 1;
        if ('^' === ($pattern[$at] ?? '')) {
            $at++;
        }

        $close = strpos($pattern, '}', $at);
        if (false === $close || $close - $at >= self::MAX_PROPERTY_READ || str_contains(substr($pattern, $at, $close - $at), "\0")) {
            return false;
        }

        return PropertyNames::knownBefore1040(substr($pattern, $at, $close - $at), $target);
    }

    /**
     * "\k" and a name between "<>", "''" or "{}".
     */
    private static function namedReferenceFails(string $pattern, int $after, ?PcreTarget $unicodeTables): bool
    {
        $closer = ['<' => '>', "'" => "'", '{' => '}'][$pattern[$after] ?? ''] ?? null;

        return null === $closer || !self::readsName($pattern, $after + 1, $closer, $unicodeTables);
    }

    /**
     * Whether a group name and its closer stand at $at. From 10.33 a name
     * under UTF takes letters and digits of any script, those of the Unicode
     * tables of $unicodeTables; ASCII ones when it is null.
     */
    private static function readsName(string $pattern, int $at, string $closer, ?PcreTarget $unicodeTables): bool
    {
        if (null !== $unicodeTables) {
            if (NameCharacters::digitAt($pattern, $at, $unicodeTables) > 0) {
                return false;
            }

            $end = NameCharacters::end($pattern, $at, $unicodeTables);
        } else {
            if (Ascii::isDigit($pattern[$at] ?? '')) {
                return false;
            }

            LibraryPcre::match('/\G[A-Za-z0-9_]*+/', $pattern, $name, 0, $at);
            $end = $at + \strlen($name[0] ?? '');
        }

        // The length is counted in bytes, as PCRE2 counts code units.
        $length = $end - $at;

        return $length > 0 && $length <= self::MAX_NAME_LENGTH && $closer === ($pattern[$at + $length] ?? '');
    }

    /**
     * "\g" outside a class: a number, braced or not, a name in braces, or a
     * name or a number between "<>" or "''". A number counted back from the
     * groups opened so far needs them.
     */
    private static function gReferenceFails(string $pattern, int $after, ?PcreTarget $unicodeTables, ?int $captures): bool
    {
        $opener = $pattern[$after] ?? '';
        if ('' === $opener) {
            return true;
        }

        if ('<' === $opener || "'" === $opener) {
            $closer = '<' === $opener ? '>' : "'";
            // "\g<0>" calls the whole pattern.
            $number = self::signedNumber($pattern, $after + 1, $captures, false);
            if (null === $number) {
                return !self::readsName($pattern, $after + 1, $closer, $unicodeTables);
            }

            [$fails, $end] = $number;

            return true === $fails || $closer !== ($pattern[$end] ?? '');
        }

        if ('{' === $opener) {
            $number = self::signedNumber($pattern, $after + 1, $captures, true);
            if (null === $number) {
                return !self::readsName($pattern, $after + 1, '}', $unicodeTables);
            }

            [$fails, $end] = $number;

            return true === $fails || '}' !== ($pattern[$end] ?? '');
        }

        $number = self::signedNumber($pattern, $after, $captures, true);

        return null === $number || true === $number[0];
    }

    /**
     * A group number after "\g", a sign before it: null when no digit
     * follows, else whether PCRE2 refuses it (null when that hangs on the
     * groups opened so far, unknown) and where it ends. A number past 65535
     * is refused, so is a signed zero, a zero when it names a reference,
     * a number counted back past the groups opened, and one counted forward
     * past 65535 less them.
     *
     * @return array{0: bool|null, 1: int}|null
     */
    private static function signedNumber(string $pattern, int $at, ?int $captures, bool $reference): ?array
    {
        $sign = $pattern[$at] ?? '';
        $digits = '+' === $sign || '-' === $sign ? $at + 1 : $at;
        $number = self::readNumber($pattern, $digits, PcreNumber::MAX);
        if (null === $number) {
            return null;
        }

        [$value, $end] = $number;
        if (false === $value) {
            return [true, $end];
        }

        if (0 === $value) {
            return [$reference || $digits > $at, $end];
        }

        // read_number() adds the groups opened so far to "+n", and refuses
        // the sum past 65535.
        if ('+' === $sign) {
            return [null === $captures ? null : $value > PcreNumber::MAX - $captures, $end];
        }

        if ('-' !== $sign) {
            return [false, $end];
        }

        return [null === $captures ? null : $value > $captures, $end];
    }

    /**
     * "\o{...}": octal digits, at most a byte without UTF, no surrogate under
     * it, then "}".
     */
    private static function octalBracesFail(string $pattern, int $after, bool $utf): bool
    {
        if ('{' !== ($pattern[$after] ?? '') || '}' === ($pattern[$after + 1] ?? '}')) {
            return true;
        }

        $at = $after + 1;
        $value = 0;
        while (1 === \strlen($digit = $pattern[$at] ?? '') && $digit >= '0' && $digit <= '7') {
            $value = $value * 8 + (int) $digit;
            if ($value > ($utf ? 0x10FFFF : 0xFF)) {
                return true;
            }
            $at++;
        }

        return '}' !== ($pattern[$at] ?? '') || ($utf && $value >= 0xD800 && $value <= 0xDFFF);
    }

    /**
     * The hex digits of "\x{" or "\N{U+" from $at: at least one, at most a
     * byte without UTF, no surrogate under it, then "}".
     */
    private static function hexBracesFail(string $pattern, int $at, bool $utf): bool
    {
        if ('}' === ($pattern[$at] ?? '}')) {
            return true;
        }

        $value = 0;
        while (Ascii::isHexDigit($pattern[$at] ?? '')) {
            $value = $value * 16 + (int) hexdec($pattern[$at]);
            if ($value > ($utf ? 0x10FFFF : 0xFF)) {
                return true;
            }
            $at++;
        }

        return '}' !== ($pattern[$at] ?? '') || ($utf && $value >= 0xD800 && $value <= 0xDFFF);
    }

    /**
     * "\c" and the character it XORs with 0x40.
     */
    private static function controlFails(string $pattern, int $after): bool
    {
        $next = $pattern[$after] ?? '';

        return '' === $next || \ord($next) < 0x20 || \ord($next) > 0x7E;
    }

    /**
     * A backslash and digits from 1 to 9. Outside a class, a number past
     * 214748363 is refused; under 10, from "\8", or naming a group opened
     * so far, a reference, refused past 65535; otherwise, as in a class, up
     * to three octal digits, refused past a byte without UTF. A reference
     * or an octal escape hangs on the groups opened so far.
     */
    private static function digitsFail(string $pattern, int $digit, bool $inClass, bool $utf, ?int $captures): bool
    {
        if (!$inClass) {
            $number = self::readNumber($pattern, $digit, self::MAX_BACKSLASH_NUMBER);
            \assert(null !== $number);
            [$value] = $number;
            if (false === $value) {
                return true;
            }

            if ($value < 10 || $pattern[$digit] >= '8') {
                return $value > PcreNumber::MAX;
            }

            // A group opened so far: a reference. Not knowing them, the
            // escape is no literal until they are known.
            if ($value <= PcreNumber::MAX && (null === $captures || $value <= $captures)) {
                return false;
            }
        }

        if ($pattern[$digit] >= '8') {
            return false;
        }

        LibraryPcre::match('/\G[0-7]{1,3}/', $pattern, $octal, 0, $digit);

        return !$utf && (int) octdec($octal[0] ?? '0') > 0xFF;
    }
}
