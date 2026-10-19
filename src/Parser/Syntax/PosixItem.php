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

/**
 * A POSIX item as check_posix_syntax() reads it where a class item starts:
 * a "[", then ":", "." or "=", up to the same character before a "]". A
 * "]" or a "\" inside stands only after a backslash, and a "[" followed by
 * the opening character ends the reading. PCRE reads the item whole, then
 * refuses any name it does not know, whatever the name holds.
 *
 * @internal
 */
final class PosixItem
{
    /**
     * The offset of the ":", "." or "=" that ends the item whose "[" is at
     * $bracket, before its "]"; null when no item starts there.
     */
    public static function terminator(string $pattern, int $bracket): ?int
    {
        $opener = $pattern[$bracket + 1] ?? '';
        if (':' !== $opener && '.' !== $opener && '=' !== $opener) {
            return null;
        }

        $length = \strlen($pattern);
        for ($position = $bracket + 2; $length - $position >= 2; $position++) {
            $char = $pattern[$position];
            $next = $pattern[$position + 1];

            if ('\\' === $char && (']' === $next || '\\' === $next)) {
                $position++;

                continue;
            }

            if (('[' === $char && $opener === $next) || ']' === $char) {
                return null;
            }

            if ($opener === $char && ']' === $next) {
                return $position;
            }
        }

        return null;
    }

    /**
     * The offset of the terminator of the item whose "[" is at $bracket,
     * when what it holds has a backslash, which the lexer would otherwise
     * read as escapes; null for any other item, or none.
     */
    public static function terminatorPastABackslash(string $pattern, int $bracket): ?int
    {
        $terminator = self::terminator($pattern, $bracket);

        return null !== $terminator && str_contains(substr($pattern, $bracket + 2, $terminator - $bracket - 2), '\\') ? $terminator : null;
    }
}
