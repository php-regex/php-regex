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

use PHPRegex\LanguageServer\Document\RegexOccurrence;
use PHPRegex\Parser\Engine\LibraryPcre;

/**
 * Converts between byte offsets and LSP line/column positions.
 *
 * A column in the protocol counts UTF-16 code units, not bytes: on a line
 * holding "é" or an emoji, an editor and a byte offset stop agreeing, and
 * every diagnostic after that point lands on the wrong characters. Lines
 * that are not valid UTF-8 fall back to bytes, which is the best that can
 * be said about them.
 *
 * @phpstan-import-type Position from RegexOccurrence
 *
 * @internal
 */
final class PositionConverter
{
    private const CHECKPOINT_BYTES = 256;
    /**
     * @var array<int, int> Line number => byte offset of line start
     */
    private array $lineOffsets = [];

    /**
     * Per-line checkpoints: byte column and UTF-16 units, or null once a
     * prefix is invalid UTF-8. Each block starts at a code point boundary.
     *
     * @var array<int, list<array{int, int|null}>>
     */
    private array $columnCheckpoints = [];

    public function __construct(private readonly string $content)
    {
        $this->buildLineOffsets();
    }

    /**
     * Convert a byte offset to LSP position (0-indexed line and character).
     *
     * @return Position
     */
    public function offsetToPosition(int $offset): array
    {
        $line = $this->lineAt($offset);
        $lineStart = $this->lineOffsets[$line] ?? 0;

        return [
            'line' => $line,
            'character' => $this->columnAt($line, $lineStart, max(0, min($offset, \strlen($this->content)) - $lineStart)),
        ];
    }

    /**
     * Extend a line's index only once per block, then measure at most one
     * block per lookup, even when offsets arrive out of order. Keep the
     * prefix-based byte fallback, including offsets inside a code point.
     */
    private function columnAt(int $line, int $lineStart, int $bytes): int
    {
        $this->columnCheckpoints[$line] ??= [[0, 0]];
        $checkpoints = &$this->columnCheckpoints[$line];
        $block = intdiv($bytes, self::CHECKPOINT_BYTES);

        while (\count($checkpoints) <= $block) {
            $end = \count($checkpoints) * self::CHECKPOINT_BYTES;
            // A UTF-8 sequence has at most three continuation bytes. Do not
            // let malformed runs of continuation bytes make this unbounded.
            for ($back = 0; $back < 3 && $end > 0; $back++) {
                $byte = \ord($this->content[$lineStart + $end] ?? "\0");
                if ($byte < 0x80 || $byte > 0xBF) {
                    break;
                }
                $end--;
            }

            [$start, $units] = $checkpoints[\count($checkpoints) - 1];
            if (null !== $units) {
                $text = substr($this->content, $lineStart + $start, $end - $start);
                $units = self::isUtf8($text) ? $units + self::utf16Length($text) : null;
            }
            $checkpoints[] = [$end, $units];
        }

        [$start, $units] = $checkpoints[$block];
        if (null === $units) {
            return $bytes;
        }

        $text = substr($this->content, $lineStart + $start, $bytes - $start);

        return self::isUtf8($text) ? $units + self::utf16Length($text) : $bytes;
    }

    /**
     * The position of a byte offset in the content, as offsetToPosition()
     * gives it, without the table of every line: for a few offsets of a
     * long text.
     *
     * @return Position
     */
    public static function positionIn(string $content, int $offset): array
    {
        $before = substr($content, 0, max(0, $offset));
        $lineStart = 0;
        $line = 0;
        foreach (self::breaksIn($before) as $end) {
            $lineStart = $end;
            $line++;
        }

        return [
            'line' => $line,
            'character' => self::utf16Length(substr($before, $lineStart)),
        ];
    }

    /**
     * Convert LSP position to byte offset.
     */
    public function positionToOffset(int $line, int $character): int
    {
        // Past the last line is the end of the document, as a character past
        // a line's end is that line's end.
        if (!isset($this->lineOffsets[$line])) {
            return $line < 0 ? 0 : \strlen($this->content);
        }

        $lineStart = $this->lineOffsets[$line];

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
     * @param Position $start
     * @param Position $end
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
    private static function utf16Length(string $text): int
    {
        if (!self::isUtf8($text)) {
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

        if (!self::isUtf8($line)) {
            return min($units, \strlen($line));
        }

        $consumed = 0;
        $bytes = 0;

        foreach (LibraryPcre::split('//u', $line, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($consumed >= $units) {
                break;
            }

            $consumed += self::utf16Length($character);
            $bytes += \strlen($character);
        }

        return $bytes;
    }

    private static function isUtf8(string $text): bool
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
        foreach (self::breaksIn($this->content) as $end) {
            $this->lineOffsets[] = $end;
        }
    }

    /**
     * Where each line after the first starts: past "\n", "\r\n" or a lone
     * "\r", the three line breaks the protocol names.
     *
     * @return \Generator<int, int>
     */
    private static function breaksIn(string $text): \Generator
    {
        $length = \strlen($text);
        for ($i = 0; $i < $length; $i++) {
            if ("\n" === $text[$i]) {
                yield $i + 1;
            } elseif ("\r" === $text[$i]) {
                $i += "\n" === ($text[$i + 1] ?? '') ? 1 : 0;

                yield $i + 1;
            }
        }
    }
}
