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

namespace PHPRegex\Parser\Exception;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Text\Utf8;

/**
 * The line of a pattern with a caret under an offset, as the exceptions
 * that show the pattern and the runtime compile check draw it.
 *
 * @internal
 */
final class Snippet
{
    /**
     * The widest excerpt shown, in bytes: a longer line is cut around the
     * caret.
     */
    private const MAX_WIDTH = 80;

    private const ELLIPSIS = '...';

    private function __construct() {}

    /**
     * "Line N: " and the line holding $position, then a caret under it; the
     * empty string without a pattern or an offset. A line past MAX_WIDTH is
     * cut around the caret, and in a pattern that is UTF-8 never inside a
     * character: a cut moves to the edge of the character it falls in,
     * inward.
     */
    public static function render(?string $pattern, ?int $position): string
    {
        if (null === $pattern || null === $position || $position < 0) {
            return '';
        }

        $length = \strlen($pattern);
        $caretIndex = min($position, $length);

        $lineStart = strrpos($pattern, "\n", $caretIndex - $length);
        $lineStart = false === $lineStart ? 0 : $lineStart + 1;
        $lineEnd = strpos($pattern, "\n", $caretIndex);
        $lineEnd = false === $lineEnd ? $length : $lineEnd;

        $lineNumber = substr_count($pattern, "\n", 0, $lineStart) + 1;

        $displayStart = $lineStart;
        $displayEnd = $lineEnd;

        if ($displayEnd - $displayStart > self::MAX_WIDTH) {
            $displayStart = max($lineStart, $caretIndex - intdiv(self::MAX_WIDTH, 2));
            $displayEnd = min($lineEnd, $displayStart + self::MAX_WIDTH);

            if (1 === LibraryPcre::match('//u', $pattern)) {
                [$displayStart, $displayEnd] = self::onCharacters($pattern, $displayStart, $displayEnd, $caretIndex, $lineEnd);
            }
        }

        $prefixEllipsis = $displayStart > $lineStart ? self::ELLIPSIS : '';
        $suffixEllipsis = $displayEnd < $lineEnd ? self::ELLIPSIS : '';

        $excerpt = $prefixEllipsis.substr($pattern, $displayStart, $displayEnd - $displayStart).$suffixEllipsis;

        // A caret on the line break that ends the line before stands at the
        // start of the line shown. It counts the columns the characters
        // before it take, as a terminal draws them, a wide one two; a line
        // that is not UTF-8 is counted in bytes.
        $before = substr($pattern, $displayStart, max(0, $caretIndex - $displayStart));
        $width = mb_check_encoding($before, 'UTF-8') ? mb_strwidth($before, 'UTF-8') : \strlen($before);
        $caretOffset = \strlen($prefixEllipsis) + $width;
        $lineLabel = 'Line '.$lineNumber.': ';

        return $lineLabel.$excerpt."\n".str_repeat(' ', \strlen($lineLabel) + $caretOffset).'^';
    }

    /**
     * The window moved inward to whole characters: its start past the
     * character it falls inside, never past the caret, its end back to the
     * start of the character it cuts.
     *
     * @return array{int, int}
     */
    private static function onCharacters(string $pattern, int $start, int $end, int $caret, int $lineEnd): array
    {
        while ($start < $caret && Utf8::continues($pattern[$start])) {
            $start++;
        }

        while ($end > $caret && $end < $lineEnd && Utf8::continues($pattern[$end])) {
            $end--;
        }

        return [$start, $end];
    }
}
