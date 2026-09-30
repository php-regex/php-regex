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

namespace RegexParser\Automata\Unicode;

use RegexParser\Automata\Alphabet\CharSet;

/**
 * UTF-8 code point helpers for automata output and decoding.
 *
 * @internal
 */
final class CodePointHelper
{
    public static function toString(int $codePoint): ?string
    {
        if ($codePoint < CharSet::MIN_CODEPOINT || $codePoint > CharSet::UNICODE_MAX_CODEPOINT) {
            return null;
        }

        // A surrogate is no character: mb_chr() refuses it.
        $char = mb_chr($codePoint, 'UTF-8');

        return false === $char || '' === $char ? null : $char;
    }

    public static function toCodePoint(string $char): ?int
    {
        if ('' === $char) {
            return null;
        }

        $value = mb_ord($char, 'UTF-8');

        return false === $value ? null : $value;
    }

    /**
     * @return array<int>
     */
    public static function toCodePoints(string $text): array
    {
        if ('' === $text) {
            return [];
        }

        if (!self::isValidUtf8($text)) {
            return [];
        }

        $chars = \preg_split('//u', $text, -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $chars) {
            return [];
        }

        $codePoints = [];
        foreach ($chars as $char) {
            $value = self::toCodePoint($char);
            if (null !== $value) {
                $codePoints[] = $value;
            }
        }

        return $codePoints;
    }

    public static function isValidUtf8(string $text): bool
    {
        if (\function_exists('mb_check_encoding')) {
            return \mb_check_encoding($text, 'UTF-8');
        }

        return 1 === \preg_match('//u', $text);
    }

    public static function singleCodePoint(string $text): ?int
    {
        if ('' === $text) {
            return null;
        }

        $chars = \preg_split('//u', $text, -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $chars || 1 !== \count($chars)) {
            return null;
        }

        return self::toCodePoint($chars[0]);
    }
}
