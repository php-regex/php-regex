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
use PHPRegex\Linter\Extraction\PhpStringLiteral;

/**
 * Where the text of a pattern lies in its document. The library counts in
 * bytes of the pattern body, as PHP reads the literal; the editor counts
 * UTF-16 code units of the source, where the literal may spell a byte with
 * an escape ("\t", "\x5b", "\u{e9}"). Every diagnostic places its range
 * through here.
 *
 * @phpstan-import-type Position from RegexOccurrence
 *
 * @internal
 */
final readonly class PatternSpan
{
    private const CLOSING = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    /**
     * @param list<int> $spelling where the literal spells each byte of the pattern, then its closing quote
     */
    private function __construct(
        private PositionConverter $positions,
        private int $literalOffset,
        private array $spelling,
        private string $pattern,
        private int $bodyLength,
    ) {}

    public static function of(RegexOccurrence $occurrence, PositionConverter $positions): self
    {
        $pattern = $occurrence->pattern;
        $length = \strlen($pattern);
        $spelling = PhpStringLiteral::spellingOffsets($occurrence->literal) ?? range(1, $length + 1);

        // The body ends at the last closing delimiter: the modifiers follow it.
        $close = strrpos($pattern, self::CLOSING[$pattern[0] ?? ''] ?? ($pattern[0] ?? ''), 1);

        return new self($positions, $occurrence->byteOffset, $spelling, $pattern, false === $close ? $length - 1 : $close - 1);
    }

    /**
     * The bytes between the delimiters.
     */
    public function bodyLength(): int
    {
        return $this->bodyLength;
    }

    /**
     * The range of the body bytes $from to $to, $to excluded; one character
     * when no end is given. An offset at the end of the body, where an error
     * of the input ending is reported, lands on the closing delimiter. A
     * character is never cut: a range covers each one it touches whole.
     *
     * @return array{start: Position, end: Position}
     */
    public function range(int $from, ?int $to = null): array
    {
        $from = max(0, min($from, $this->bodyLength));
        $to = null === $to || $to <= $from ? $from + 1 : min($to, $this->bodyLength + 1);

        // Pattern offsets: past the opening delimiter.
        $length = \strlen($this->pattern);
        $start = min($from + 1, $length);
        $end = min($to + 1, $length);
        while ($start > 1 && self::continuesACharacter($this->pattern, $start)) {
            $start--;
        }
        while ($end < $length && self::continuesACharacter($this->pattern, $end)) {
            $end++;
        }

        return [
            'start' => $this->positions->offsetToPosition($this->literalOffset + $this->spelling[$start]),
            'end' => $this->positions->offsetToPosition($this->literalOffset + $this->spelling[$end]),
        ];
    }

    private static function continuesACharacter(string $text, int $offset): bool
    {
        return 0x80 === (\ord($text[$offset]) & 0xC0);
    }
}
