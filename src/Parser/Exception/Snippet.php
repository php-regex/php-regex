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

    /**
     * The East Asian Wide and Fullwidth blocks and the emoji blocks, two
     * columns each in a terminal.
     */
    private const WIDE = [
        [0x1100, 0x115F], [0x2E80, 0x303E], [0x3041, 0x33FF], [0x3400, 0x4DBF],
        [0x4E00, 0x9FFF], [0xA000, 0xA4CF], [0xAC00, 0xD7A3], [0xF900, 0xFAFF],
        [0xFE30, 0xFE4F], [0xFF00, 0xFF60], [0xFFE0, 0xFFE6], [0x1F300, 0x1F64F],
        [0x1F900, 0x1F9FF], [0x20000, 0x2FFFD], [0x30000, 0x3FFFD],
    ];

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
        $width = mb_check_encoding($before, 'UTF-8') ? self::columns($before) : \strlen($before);
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

    /**
     * The columns a terminal draws a UTF-8 text in: two for a wide East
     * Asian character or an emoji, one for any other. A table of its own,
     * not mb_strwidth(), whose tables change with the PHP release: the same
     * pattern gets the same caret on every PHP.
     */
    private static function columns(string $text): int
    {
        $columns = 0;
        foreach (mb_str_split($text, 1, 'UTF-8') as $character) {
            $codePoint = (int) mb_ord($character, 'UTF-8');
            $columns += self::isWide($codePoint) ? 2 : 1;
        }

        return $columns;
    }

    private static function isWide(int $codePoint): bool
    {
        foreach (self::WIDE as [$first, $last]) {
            if ($codePoint >= $first && $codePoint <= $last) {
                return true;
            }
        }

        return false;
    }
}
