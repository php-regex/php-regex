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

namespace PHPRegex\LanguageServer\Document;

/**
 * Represents a regex pattern occurrence in a document.
 *
 * @phpstan-type Position array{line: int, character: int}
 * @phpstan-type Range array{start: Position, end: Position}
 *
 * @internal
 */
final class RegexOccurrence
{
    /**
     * @param string   $pattern    the pattern, as PHP reads the literal
     * @param Position $start
     * @param Position $end
     * @param int      $byteOffset where the literal starts in the document
     * @param string   $literal    the literal as the document spells it, quotes included
     * @param bool     $ignored    whether "@regex-ignore" or "@regex-ignore-next-line" silences
     *                             its line: it gets no diagnostic
     */
    public function __construct(
        public readonly string $pattern,
        public readonly array $start,
        public readonly array $end,
        public readonly int $byteOffset,
        public readonly string $literal,
        public readonly bool $ignored = false,
    ) {}

    /**
     * Check if a position falls within this occurrence.
     */
    public function containsPosition(int $line, int $character): bool
    {
        if ($line < $this->start['line'] || $line > $this->end['line']) {
            return false;
        }

        if ($line === $this->start['line'] && $character < $this->start['character']) {
            return false;
        }

        if ($line === $this->end['line'] && $character > $this->end['character']) {
            return false;
        }

        return true;
    }
}
