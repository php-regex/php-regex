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

namespace PHPRegex\Linter\Extraction;

use PHPRegex\Linter\PatternOccurrence;

/**
 * A pattern argument no literal gives, held until the whole file is read:
 * the names it goes through may be proved anywhere in the file. Each
 * strategy places it its own way, the token strategy by its token range,
 * the php-parser strategy by its expression.
 *
 * @template TPlace
 *
 * @internal
 */
final readonly class PendingPattern
{
    /**
     * @param TPlace $place where the argument is written
     */
    public function __construct(public mixed $place, public PatternFunction $function) {}

    /**
     * The 1-based column of a byte offset in the content; null for an
     * offset the parser does not know, -1.
     */
    public static function column(string $content, int $offset): ?int
    {
        if ($offset < 0) {
            return null;
        }

        // The last newline before the offset, found without copying what
        // precedes it: a file with many patterns is not read once per pattern.
        $length = \strlen($content);
        $lastNewline = 0 === $offset || 0 === $length ? false : strrpos($content, "\n", min($offset, $length) - $length - 1);

        return false === $lastNewline ? $offset + 1 : $offset - $lastNewline;
    }

    /**
     * The occurrences the file proves the argument holds, given what it
     * reads as: each where its literal is written.
     *
     * @return list<PatternOccurrence>
     */
    public function occurrences(InFileValueResolver $resolver, ValueExpr $value, string $file, string $content): array
    {
        $occurrences = [];
        foreach ($resolver->patterns($value, $this->function) as $pattern) {
            $occurrences[] = new PatternOccurrence(
                $pattern['pattern'],
                $file,
                $pattern['line'],
                $this->function->source(),
                location: $pattern['location'],
                column: self::column($content, $pattern['offset']),
                fileOffset: $pattern['offset'],
            );
        }

        return $occurrences;
    }
}
