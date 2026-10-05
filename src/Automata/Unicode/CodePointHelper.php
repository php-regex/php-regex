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

namespace PHPRegex\Automata\Unicode;

/**
 * UTF-8 code point helpers for automata output and decoding.
 *
 * @internal
 */
final class CodePointHelper
{
    public static function toString(int $codePoint): ?string
    {
        if ($codePoint < 0 || $codePoint > 0x10FFFF) {
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
}
