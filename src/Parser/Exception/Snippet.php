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
use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Parser\Text\Bytes;
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
     * The characters of East Asian Width Wide or Fullwidth, the emoji drawn
     * as such among them, two columns each in a terminal: the ranges
     * mb_strwidth() counts two on PHP 8.4, written out so that every PHP
     * counts them alike.
     */
    private const WIDE = [
        [0x1100, 0x115F], [0x231A, 0x231B], [0x2329, 0x232A], [0x23E9, 0x23EC],
        [0x23F0, 0x23F0], [0x23F3, 0x23F3], [0x25FD, 0x25FE], [0x2614, 0x2615],
        [0x2630, 0x2637], [0x2648, 0x2653], [0x267F, 0x267F], [0x268A, 0x268F],
        [0x2693, 0x2693], [0x26A1, 0x26A1], [0x26AA, 0x26AB], [0x26BD, 0x26BE],
        [0x26C4, 0x26C5], [0x26CE, 0x26CE], [0x26D4, 0x26D4], [0x26EA, 0x26EA],
        [0x26F2, 0x26F3], [0x26F5, 0x26F5], [0x26FA, 0x26FA], [0x26FD, 0x26FD],
        [0x2705, 0x2705], [0x270A, 0x270B], [0x2728, 0x2728], [0x274C, 0x274C],
        [0x274E, 0x274E], [0x2753, 0x2755], [0x2757, 0x2757], [0x2795, 0x2797],
        [0x27B0, 0x27B0], [0x27BF, 0x27BF], [0x2B1B, 0x2B1C], [0x2B50, 0x2B50],
        [0x2B55, 0x2B55], [0x2E80, 0x2E99], [0x2E9B, 0x2EF3], [0x2F00, 0x2FD5],
        [0x2FF0, 0x303E], [0x3041, 0x3096], [0x3099, 0x30FF], [0x3105, 0x312F],
        [0x3131, 0x318E], [0x3190, 0x31E5], [0x31EF, 0x321E], [0x3220, 0x3247],
        [0x3250, 0xA48C], [0xA490, 0xA4C6], [0xA960, 0xA97C], [0xAC00, 0xD7A3],
        [0xF900, 0xFAFF], [0xFE10, 0xFE19], [0xFE30, 0xFE52], [0xFE54, 0xFE66],
        [0xFE68, 0xFE6B], [0xFF01, 0xFF60], [0xFFE0, 0xFFE6], [0x16FE0, 0x16FE4],
        [0x16FF0, 0x16FF1], [0x17000, 0x187F7], [0x18800, 0x18CD5], [0x18CFF, 0x18D08],
        [0x1AFF0, 0x1AFF3], [0x1AFF5, 0x1AFFB], [0x1AFFD, 0x1AFFE], [0x1B000, 0x1B122],
        [0x1B132, 0x1B132], [0x1B150, 0x1B152], [0x1B155, 0x1B155], [0x1B164, 0x1B167],
        [0x1B170, 0x1B2FB], [0x1D300, 0x1D356], [0x1D360, 0x1D376], [0x1F004, 0x1F004],
        [0x1F0CF, 0x1F0CF], [0x1F18E, 0x1F18E], [0x1F191, 0x1F19A], [0x1F200, 0x1F202],
        [0x1F210, 0x1F23B], [0x1F240, 0x1F248], [0x1F250, 0x1F251], [0x1F260, 0x1F265],
        [0x1F300, 0x1F320], [0x1F32D, 0x1F335], [0x1F337, 0x1F37C], [0x1F37E, 0x1F393],
        [0x1F3A0, 0x1F3CA], [0x1F3CF, 0x1F3D3], [0x1F3E0, 0x1F3F0], [0x1F3F4, 0x1F3F4],
        [0x1F3F8, 0x1F43E], [0x1F440, 0x1F440], [0x1F442, 0x1F4FC], [0x1F4FF, 0x1F53D],
        [0x1F54B, 0x1F54E], [0x1F550, 0x1F567], [0x1F57A, 0x1F57A], [0x1F595, 0x1F596],
        [0x1F5A4, 0x1F5A4], [0x1F5FB, 0x1F64F], [0x1F680, 0x1F6C5], [0x1F6CC, 0x1F6CC],
        [0x1F6D0, 0x1F6D2], [0x1F6D5, 0x1F6D7], [0x1F6DC, 0x1F6DF], [0x1F6EB, 0x1F6EC],
        [0x1F6F4, 0x1F6FC], [0x1F7E0, 0x1F7EB], [0x1F7F0, 0x1F7F0], [0x1F90C, 0x1F93A],
        [0x1F93C, 0x1F945], [0x1F947, 0x1F9FF], [0x1FA70, 0x1FA7C], [0x1FA80, 0x1FA89],
        [0x1FA8F, 0x1FAC6], [0x1FACE, 0x1FADC], [0x1FADF, 0x1FAE9], [0x1FAF0, 0x1FAF8],
        [0x20000, 0x2FFFD], [0x30000, 0x3FFFD],
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
     * Escape an existing diagnostic for a terminal, preserving its caret's
     * column after control characters have been expanded to visible text.
     */
    public static function escapeForTerminal(string $snippet): string
    {
        if (1 !== LibraryPcre::match('/\\A(Line \\d+: )([^\\n]*)\\n( *)\\^\\z/', $snippet, $match)) {
            return DisplayEscaper::escapeText($snippet);
        }

        [, $label, $excerpt, $indent] = $match;
        $offset = max(0, \strlen($indent) - \strlen($label));
        $before = '';
        $columns = 0;
        $characters = mb_check_encoding($excerpt, 'UTF-8') ? mb_str_split($excerpt, 1, 'UTF-8') : Bytes::split($excerpt);
        foreach ($characters as $character) {
            if ($columns >= $offset) {
                break;
            }
            $before .= $character;
            $columns += mb_check_encoding($character, 'UTF-8') ? self::columns($character) : 1;
        }
        $width = self::columns(DisplayEscaper::escapeText($before)) + max(0, $offset - $columns);

        return $label.DisplayEscaper::escapeText($excerpt)."\n".str_repeat(' ', \strlen($label) + $width).'^';
    }

    /**
     * The columns a terminal draws a UTF-8 text in: two for a wide East
     * Asian character or an emoji, one for any other. A table of its own,
     * not mb_strwidth(), whose tables change with the PHP release: the same
     * pattern gets the same caret on every PHP.
     */
    public static function columns(string $text): int
    {
        $columns = 0;
        foreach (mb_str_split($text, 1, 'UTF-8') as $character) {
            $codePoint = (int) mb_ord($character, 'UTF-8');
            $columns += self::isWide($codePoint) ? 2 : 1;
        }

        return $columns;
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

    private static function isWide(int $codePoint): bool
    {
        // The ranges run in order: past the code point, none holds it.
        foreach (self::WIDE as [$first, $last]) {
            if ($codePoint <= $last) {
                return $codePoint >= $first;
            }
        }

        return false;
    }
}
