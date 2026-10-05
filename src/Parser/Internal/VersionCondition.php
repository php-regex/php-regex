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
 * The condition "(?(VERSION>=10.4)yes|no)" asks about.
 *
 * PCRE lets a pattern branch on the version of the library reading it. The
 * text between the parentheses is all there is to it, so reading it needs no
 * tokens and no parser.
 *
 * @internal
 */
final readonly class VersionCondition
{
    /**
     * The comparisons that may follow "VERSION", longest first so that ">="
     * is not read as ">".
     *
     * PCRE itself only takes ">=" and "="; the others are read anyway, and
     * left to the validator to judge.
     */
    private const OPERATORS = ['>=', '<=', '==', '!=', '>', '<', '='];

    private function __construct(public string $operator, public string $version) {}

    /**
     * Read "VERSION>=10.4", or null when the text says something else.
     */
    public static function read(string $text): ?self
    {
        $text = trim($text);
        if (!str_starts_with($text, 'VERSION')) {
            return null;
        }

        $rest = ltrim(substr($text, \strlen('VERSION')));

        foreach (self::OPERATORS as $operator) {
            if (!str_starts_with($rest, $operator)) {
                continue;
            }

            $version = ltrim(substr($rest, \strlen($operator)));

            return self::isVersionNumber($version) ? new self($operator, $version) : null;
        }

        return null;
    }

    /**
     * Where PCRE refuses the version condition whose "VERSION" starts at
     * $position in $pattern, or null when it takes it or does not read one
     * there.
     *
     * PCRE reads one when "VERSION" is not followed by ")" and the pattern
     * holds at least ten more characters. It stops on a character it cannot
     * take where it expects a digit, a "." or the ")"; from PCRE2 10.47,
     * which $pastTheFault stands for, past it, unless the comparison began
     * with ">". Under $utf, past it is past the whole character.
     */
    public static function errorOffset(string $pattern, int $position, bool $twoDigitMinor = false, bool $pastTheFault = true, bool $utf = false): ?int
    {
        $length = \strlen($pattern);
        if ($length - $position < 10 || 'VERSION' !== substr($pattern, $position, 7)) {
            return null;
        }

        if (')' === $pattern[$position + 7]) {
            return null;
        }

        $at = $position + 7;
        $atLeast = '>' === $pattern[$at];
        if ($atLeast) {
            $at++;
        }

        if ('=' !== ($pattern[$at] ?? '')) {
            return $atLeast ? $at : $at + self::stepPast($pattern, $at, $pastTheFault, $utf);
        }

        $at++;
        if (!Ascii::isDigit($pattern[$at] ?? '')) {
            return $atLeast ? $at : $at + self::stepPast($pattern, $at, $pastTheFault, $utf);
        }

        [$at, $tooBig] = $twoDigitMinor ? self::readVersionPartDigitByDigit($pattern, $at) : self::readVersionPart($pattern, $at);
        if ($tooBig) {
            return $at;
        }

        if ('.' === ($pattern[$at] ?? '')) {
            $at++;
            if (!Ascii::isDigit($pattern[$at] ?? '')) {
                return $at < $length ? $at + self::stepPast($pattern, $at, $pastTheFault, $utf) : $at;
            }

            // Up to PCRE2 10.45 the minor is two digits, and a third one is
            // where PCRE stops.
            if ($twoDigitMinor) {
                $at += strspn($pattern, '0123456789', $at, 2);
                if (Ascii::isDigit($pattern[$at] ?? '')) {
                    return $at;
                }
            } else {
                [$at, $tooBig] = self::readVersionPart($pattern, $at);
                if ($tooBig) {
                    return $at;
                }
            }
        }

        if (')' !== ($pattern[$at] ?? '')) {
            return $at < $length ? $at + self::stepPast($pattern, $at, $pastTheFault, $utf) : $at;
        }

        return null;
    }

    /**
     * Whether $offset, where PCRE refuses the version condition whose
     * "VERSION" starts at $position, is the character right after a major
     * number PCRE takes, where only "." or ")" may follow.
     */
    public static function isMajorLeftOpenAt(string $pattern, int $position, int $offset): bool
    {
        if (1 !== LibraryPcre::match('/\GVERSION>?=(\d++)/', $pattern, $matches, 0, $position)) {
            return false;
        }

        $end = $position + \strlen($matches[0]);

        return $offset === $end && !\in_array($pattern[$end] ?? ')', ['.', ')'], true) && (int) $matches[1] <= 1000;
    }

    /**
     * How far past the character at $position PCRE reports an error it
     * reports past the fault: one byte, or the whole character under $utf.
     */
    private static function stepPast(string $pattern, int $position, bool $pastTheFault, bool $utf): int
    {
        if (!$pastTheFault) {
            return 0;
        }

        if ($utf && 1 === LibraryPcre::match('/\G./su', $pattern, $matches, 0, $position)) {
            return \strlen($matches[0]);
        }

        return 1;
    }

    /**
     * Where the digits of a major or a minor number end, and whether the
     * number goes over 1000, the most PCRE reads.
     *
     * @return array{0: int, 1: bool}
     */
    private static function readVersionPart(string $pattern, int $position): array
    {
        $digits = strspn($pattern, '0123456789', $position);

        return [$position + $digits, (int) substr($pattern, $position, $digits) > 1000];
    }

    /**
     * As readVersionPart(), the way PCRE2 up to 10.45 reads a number: it
     * stops right past the digit that takes it over 1000.
     *
     * @return array{0: int, 1: bool}
     */
    private static function readVersionPartDigitByDigit(string $pattern, int $position): array
    {
        $value = 0;
        while (Ascii::isDigit($pattern[$position] ?? '')) {
            $value = $value * 10 + (int) $pattern[$position++];
            if ($value > 1000) {
                return [$position, true];
            }
        }

        return [$position, false];
    }

    private static function isVersionNumber(string $version): bool
    {
        if ('' === $version) {
            return false;
        }

        foreach (explode('.', $version) as $part) {
            if ('' === $part || !Ascii::isDigit($part)) {
                return false;
            }
        }

        return true;
    }
}
