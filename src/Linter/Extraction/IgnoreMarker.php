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

/**
 * One "@regex-ignore" or "@regex-ignore-next-line" of a PHP source: the
 * line it is written on, the line it silences, and the issues it names,
 * none for every issue of that line.
 *
 * @internal
 */
final class IgnoreMarker
{
    /**
     * @param string       $marker      "@regex-ignore" or "@regex-ignore-next-line"
     * @param int          $line        the line the marker is written on, one-based
     * @param int          $coveredLine the line it silences, one-based
     * @param list<string> $identifiers the issues it silences, every issue of the line when empty
     * @param int          $offset      where the marker starts in the source, in bytes
     * @param int          $column      where it starts on its line, one-based, in bytes
     */
    public function __construct(
        public readonly string $marker,
        public readonly int $line,
        public readonly int $coveredLine,
        public readonly array $identifiers,
        public readonly int $offset,
        public readonly int $column = 1,
    ) {}

    /**
     * What a report says of the marker, given what it silenced: one message
     * when it names no issue and silenced none, one for each identifier it
     * names that silenced none; nothing when all it holds silenced one.
     *
     * @param array<string, true> $silenced the identifiers it silenced issues under, "" for a marker
     *                                      that names none
     *
     * @return list<string>
     */
    public function unmatched(array $silenced): array
    {
        if ([] === $this->identifiers) {
            return [] === $silenced ? [\sprintf('The %s marker silences nothing: no issue is reported on line %d. Remove it.', $this->marker, $this->coveredLine)] : [];
        }

        $messages = [];
        foreach (array_unique($this->identifiers) as $identifier) {
            if (!isset($silenced[$identifier])) {
                $messages[] = \sprintf('The %s marker names %s, which no issue reports on line %d. Remove the identifier.', $this->marker, $identifier, $this->coveredLine);
            }
        }

        return $messages;
    }

    /**
     * Whether the marker silences an issue of this identifier on its line.
     */
    public function silences(string $identifier): bool
    {
        return [] === $this->identifiers || \in_array($identifier, $this->identifiers, true);
    }
}
