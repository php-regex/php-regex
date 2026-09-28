<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Internal;

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
     * take where it expects a digit, a "." or the ")"; past it, unless the
     * comparison began with ">".
     */
    public static function errorOffset(string $pattern, int $position): ?int
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
            return $atLeast ? $at : $at + 1;
        }

        $at++;
        if (!ctype_digit($pattern[$at] ?? '')) {
            return $atLeast ? $at : $at + 1;
        }

        [$at, $tooBig] = self::readVersionPart($pattern, $at);
        if ($tooBig) {
            return $at;
        }

        if ('.' === ($pattern[$at] ?? '')) {
            $at++;
            if (!ctype_digit($pattern[$at] ?? '')) {
                return $at < $length ? $at + 1 : $at;
            }

            [$at, $tooBig] = self::readVersionPart($pattern, $at);
            if ($tooBig) {
                return $at;
            }
        }

        if (')' !== ($pattern[$at] ?? '')) {
            return $at < $length ? $at + 1 : $at;
        }

        return null;
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

    private static function isVersionNumber(string $version): bool
    {
        if ('' === $version) {
            return false;
        }

        foreach (explode('.', $version) as $part) {
            if ('' === $part || !ctype_digit($part)) {
                return false;
            }
        }

        return true;
    }
}
