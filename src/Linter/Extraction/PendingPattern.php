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
 * A pattern argument held until the whole file is read: the names it goes
 * through may be proved anywhere in the file. Each strategy places it its
 * own way, the token strategy by its token range, the php-parser strategy
 * by its node. One the call site settles on its own, a literal or an
 * argument nothing can be read of, is held with its state, to be told
 * among the arguments of the file.
 *
 * @template TPlace
 *
 * @internal
 */
final class PendingPattern
{
    /**
     * @param TPlace      $place where the argument is written
     * @param string|null $state the state the call site gives the argument, one of the values of ArgumentState;
     *                           null when the file is to say
     */
    public function __construct(
        public readonly mixed $place,
        public readonly PatternFunction $function,
        public readonly ?string $state = null
    ) {}

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
     * reads as: each where its literal is written. One of another file
     * carries where the argument stands in $content, when $argument says.
     * One no file writes, a value PHP gives one of its constants, stands
     * where the argument starts.
     *
     * @param array{int, int}|null $argument the argument's byte offset and length in $content
     * @param int|null             $start    where the argument starts in $content, the parentheses around the whole
     *                                       of it left out
     *
     * @return list<PatternOccurrence>
     */
    public function occurrences(InFileValueResolver $resolver, ValueExpr $value, string $file, string $content, ?array $argument = null, ?int $start = null): array
    {
        $occurrences = [];
        foreach ($resolver->patterns($value, $this->function) as $pattern) {
            $offset = $pattern['offset'];
            $line = $pattern['line'];
            if ($offset < 0) {
                if (null === $start) {
                    continue;
                }

                $offset = $start;
                $line = substr_count($content, "\n", 0, $start) + 1;
            }

            // A literal of another file, read through the project's
            // constants, is reported there, at the column read with it,
            // for the versions the guards of that file keep around it.
            $occurrences[] = (new PatternOccurrence(
                $pattern['pattern'],
                $pattern['file'] ?? $file,
                $line,
                $this->function->source(),
                location: $pattern['location'],
                column: null === $pattern['file'] ? self::column($content, $offset) : $pattern['column'],
                fileOffset: $offset,
                argumentOffset: null === $pattern['file'] ? null : $argument[0] ?? null,
                argumentLength: null === $pattern['file'] ? null : $argument[1] ?? null,
                variant: $pattern['variant'],
            ))->guardedBy($pattern['guard']);
        }

        return $occurrences;
    }

    /**
     * The arguments the call reads here, each with what is known of it:
     * one, or one for each item of an array read through a name, all where
     * the argument is written.
     *
     * @param list<string> $states the state of each, as the values of ArgumentState
     * @param int          $line   the line the argument starts on
     * @param int          $offset where it starts in $content, the parentheses around the whole of it left out
     * @param int          $length its length, up to the end of its last token
     *
     * @return list<PatternOccurrence>
     */
    public function arguments(array $states, string $file, string $content, int $line, int $offset, int $length): array
    {
        $arguments = [];
        foreach ($states as $state) {
            $arguments[] = PatternOccurrence::argument($state, $file, $line, self::column($content, $offset), $offset, $this->function->source(), substr($content, $offset, $length));
        }

        return $arguments;
    }
}
