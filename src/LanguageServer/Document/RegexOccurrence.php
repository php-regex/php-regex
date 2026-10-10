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
final readonly class RegexOccurrence
{
    /**
     * @param Position $start
     * @param Position $end
     */
    public function __construct(
        public string $pattern,
        public array $start,
        public array $end,
        public int $byteOffset,
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
