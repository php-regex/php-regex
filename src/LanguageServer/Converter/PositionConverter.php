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

namespace PHPRegex\LanguageServer\Converter;

use PHPRegex\Parser\Internal\LibraryPcre;

/**
 * Converts between byte offsets and LSP line/column positions.
 *
 * A column in the protocol counts UTF-16 code units, not bytes: on a line
 * holding "é" or an emoji, an editor and a byte offset stop agreeing, and
 * every diagnostic after that point lands on the wrong characters. Lines
 * that are not valid UTF-8 fall back to bytes, which is the best that can
 * be said about them.
 *
 * @internal
 */
final class PositionConverter
{
    /**
     * @var array<int, int> Line number => byte offset of line start
     */
    private array $lineOffsets = [];

    public function __construct(private readonly string $content)
    {
        $this->buildLineOffsets();
    }

    /**
     * Convert a byte offset to LSP position (0-indexed line and character).
     *
     * @return array{line: int, character: int}
     */
    public function offsetToPosition(int $offset): array
    {
        $line = $this->lineAt($offset);
        $lineStart = $this->lineOffsets[$line] ?? 0;

        return [
            'line' => $line,
            'character' => $this->utf16Length(substr($this->content, $lineStart, max(0, $offset - $lineStart))),
        ];
    }

    /**
     * Convert LSP position to byte offset.
     */
    public function positionToOffset(int $line, int $character): int
    {
        $lineStart = $this->lineOffsets[$line] ?? 0;

        return $lineStart + $this->byteLengthOfUtf16Units($this->getLineContent($line), $character);
    }

    /**
     * Get the line content at the given line number.
     */
    public function getLineContent(int $line): string
    {
        $start = $this->lineOffsets[$line] ?? 0;
        $end = $this->lineOffsets[$line + 1] ?? \strlen($this->content);

        return substr($this->content, $start, $end - $start);
    }

    /**
     * Check if an offset falls within a range.
     *
     * @param array{line: int, character: int} $start
     * @param array{line: int, character: int} $end
     */
    public function isOffsetInRange(int $offset, array $start, array $end): bool
    {
        $startOffset = $this->positionToOffset($start['line'], $start['character']);
        $endOffset = $this->positionToOffset($end['line'], $end['character']);

        return $offset >= $startOffset && $offset <= $endOffset;
    }

    /**
     * Check if a position falls within a byte range.
     */
    public function isPositionInByteRange(int $line, int $character, int $byteStart, int $byteEnd): bool
    {
        $offset = $this->positionToOffset($line, $character);

        return $offset >= $byteStart && $offset <= $byteEnd;
    }

    /**
     * The number of UTF-16 code units a piece of text takes: one per code
     * point, two for anything outside the basic multilingual plane.
     */
    private function utf16Length(string $text): int
    {
        if (!$this->isUtf8($text)) {
            return \strlen($text);
        }

        return (int) mb_strlen($text, 'UTF-8') + (int) LibraryPcre::matchAll('/[\x{10000}-\x{10FFFF}]/u', $text);
    }

    /**
     * How many bytes of a line the first $units UTF-16 code units cover.
     */
    private function byteLengthOfUtf16Units(string $line, int $units): int
    {
        if ($units <= 0) {
            return 0;
        }

        if (!$this->isUtf8($line)) {
            return min($units, \strlen($line));
        }

        $consumed = 0;
        $bytes = 0;

        foreach (LibraryPcre::split('//u', $line, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($consumed >= $units) {
                break;
            }

            $consumed += $this->utf16Length($character);
            $bytes += \strlen($character);
        }

        return $bytes;
    }

    private function isUtf8(string $text): bool
    {
        return 1 === LibraryPcre::match('//u', $text);
    }

    /**
     * The line a byte offset falls on, found by bisection so that a document
     * with many diagnostics does not walk every line for each of them.
     */
    private function lineAt(int $offset): int
    {
        $low = 0;
        $high = \count($this->lineOffsets) - 1;

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);

            if ($this->lineOffsets[$middle] <= $offset) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low;
    }

    private function buildLineOffsets(): void
    {
        $this->lineOffsets[0] = 0;
        $line = 1;

        $length = \strlen($this->content);
        for ($i = 0; $i < $length; $i++) {
            if ("\n" === $this->content[$i]) {
                $this->lineOffsets[$line] = $i + 1;
                $line++;
            }
        }
    }
}
