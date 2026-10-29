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

namespace PHPRegex\Linter\Formatter;

/**
 * Folds a line of the console report into the room it has, on its spaces,
 * counted in display columns. A double-quoted span is one word, and a word
 * wider than the room stands alone on its line and overflows: a pattern or
 * an attack string is never broken.
 *
 * @internal
 */
final class TextWrap
{
    /**
     * @return list<string>
     */
    public static function lines(string $text, int $room): array
    {
        $lines = [];
        $line = '';
        $width = 0;
        $started = false;

        foreach (self::words($text) as $word) {
            // An empty word is an extra space of a run: kept, never a fold.
            if ('' === $word) {
                if ($started) {
                    $line .= ' ';
                    $width++;
                }
                $started = true;

                continue;
            }

            $wordWidth = mb_strwidth($word, 'UTF-8');
            if (!$started) {
                $line = $word;
                $width = $wordWidth;
                $started = true;

                continue;
            }

            if ($width + 1 + $wordWidth <= $room) {
                $line .= ' '.$word;
                $width += 1 + $wordWidth;

                continue;
            }

            $lines[] = $line;
            $line = $word;
            $width = $wordWidth;
        }

        $lines[] = $line;

        return $lines;
    }

    /**
     * The words of the text: what its single spaces separate, so a run of
     * spaces yields empty words and is kept; a span between a quote and the
     * quote that closes it is one word, a quote nothing closes or a
     * backslash escapes read as any other character.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = [];
        $word = '';
        $quoted = false;
        $length = \strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ('"' === $char && !self::escaped($text, $i)) {
                if ($quoted) {
                    $quoted = false;
                } elseif (null !== self::closingQuote($text, $i + 1)) {
                    $quoted = true;
                }
                $word .= $char;

                continue;
            }

            if (' ' === $char && !$quoted) {
                $words[] = $word;
                $word = '';

                continue;
            }

            $word .= $char;
        }

        // The last word, empty when the text ends on a space.
        if ('' !== $text) {
            $words[] = $word;
        }

        return $words;
    }

    /**
     * Whether a backslash escapes the character at $at, as one does a
     * quote inside an attack string: an odd run of backslashes before it.
     */
    private static function escaped(string $text, int $at): bool
    {
        $backslashes = 0;
        while ($at - $backslashes > 0 && '\\' === $text[$at - $backslashes - 1]) {
            $backslashes++;
        }

        return 1 === $backslashes % 2;
    }

    /**
     * The offset of the first quote from $from that no backslash escapes,
     * or null.
     */
    private static function closingQuote(string $text, int $from): ?int
    {
        for ($at = strpos($text, '"', $from); false !== $at; $at = strpos($text, '"', $at + 1)) {
            if (!self::escaped($text, $at)) {
                return $at;
            }
        }

        return null;
    }
}
