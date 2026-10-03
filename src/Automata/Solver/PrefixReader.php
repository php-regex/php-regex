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

namespace PHPRegex\Automata\Solver;

use PHPRegex\Automata\Model\Dfa;

/**
 * Reads an input on a DFA and tells whether an accepting state can still be
 * reached from where it stops: whether the input begins some string of the
 * language.
 *
 * @internal
 */
final class PrefixReader
{
    /**
     * @var \WeakMap<Dfa, array<int, true>>|null
     */
    private static ?\WeakMap $live = null;

    /**
     * @param bool $unicode whether the DFA reads code points, the input being UTF-8, rather than bytes
     */
    public static function begins(Dfa $dfa, string $input, bool $unicode): bool
    {
        $live = self::liveStates($dfa);
        $state = $dfa->startState;
        $length = \strlen($input);
        $offset = 0;

        // A state without a way on is dead, as the sink of a complete DFA is.
        while ($offset < $length && null !== $state) {
            if (!$unicode) {
                $state = $dfa->getState($state)->transitionFor(\ord($input[$offset++]));

                continue;
            }

            $size = self::sequenceLength(\ord($input[$offset]));
            $ranges = 0 === $size ? [] : self::completions(substr($input, $offset, $size), $size);
            if ([] === $ranges) {
                return false;
            }

            // A code point cut short by the end of the input: viable when one
            // way to finish it leads somewhere that can still accept.
            if ($offset + $size > $length) {
                return self::anyLiveTarget($dfa, $state, $ranges, $live);
            }

            $state = $dfa->getState($state)->transitionFor($ranges[0][0]);
            $offset += $size;
        }

        return null !== $state && isset($live[$state]);
    }

    /**
     * How many bytes the UTF-8 sequence this byte leads holds, 0 for a byte
     * no sequence starts with.
     */
    private static function sequenceLength(int $lead): int
    {
        return match (true) {
            $lead < 0x80 => 1,
            $lead >= 0xC0 && $lead < 0xE0 => 2,
            $lead >= 0xE0 && $lead < 0xF0 => 3,
            $lead >= 0xF0 && $lead < 0xF8 => 4,
            default => 0,
        };
    }

    /**
     * The code points whose UTF-8 encoding starts with the given bytes, as
     * ranges: one code point for a whole sequence, none for invalid bytes.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function completions(string $bytes, int $size): array
    {
        if (1 === $size) {
            return [[\ord($bytes), \ord($bytes)]];
        }

        $codePoint = \ord($bytes[0]) & (0xFF >> ($size + 1));
        for ($index = 1, $count = \strlen($bytes); $index < $count; $index++) {
            $byte = \ord($bytes[$index]);
            if (0x80 !== ($byte & 0xC0)) {
                return [];
            }
            $codePoint = ($codePoint << 6) | ($byte & 0x3F);
        }

        $missing = 6 * ($size - \strlen($bytes));
        // The shortest encoding is the only valid one: "\xC0\x80" is no NUL.
        $shortest = match ($size) {
            2 => 0x80,
            3 => 0x800,
            default => 0x10000,
        };
        $low = max($codePoint << $missing, $shortest);
        $high = min(($codePoint << $missing) | ((1 << $missing) - 1), 0x10FFFF);

        $ranges = [];
        foreach ([[$low, min($high, 0xD7FF)], [max($low, 0xE000), $high]] as [$from, $to]) {
            if ($from <= $to) {
                $ranges[] = [$from, $to];
            }
        }

        return $ranges;
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     * @param array<int, true>            $live
     */
    private static function anyLiveTarget(Dfa $dfa, int $state, array $ranges, array $live): bool
    {
        $current = $dfa->getState($state);
        foreach ($ranges as [$from, $to]) {
            foreach ($current->transitions as $codePoint => $target) {
                if ($codePoint >= $from && $codePoint <= $to && isset($live[$target])) {
                    return true;
                }
            }

            foreach ($current->ranges as [$start, $end, $target]) {
                if ($start <= $to && $end >= $from && isset($live[$target])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The states an accepting state can be reached from, the accepting ones
     * included, computed once per DFA.
     *
     * @return array<int, true>
     */
    private static function liveStates(Dfa $dfa): array
    {
        self::$live ??= new \WeakMap();
        if (isset(self::$live[$dfa])) {
            return self::$live[$dfa];
        }

        $predecessors = [];
        $live = [];
        $queue = [];
        foreach ($dfa->states as $id => $state) {
            foreach ([...array_values($state->transitions), ...array_column($state->ranges, 2)] as $target) {
                $predecessors[$target][$id] = true;
            }

            if ($state->isAccepting) {
                $live[$id] = true;
                $queue[] = $id;
            }
        }

        while ([] !== $queue) {
            $target = array_pop($queue);
            foreach (array_keys($predecessors[$target] ?? []) as $source) {
                if (!isset($live[$source])) {
                    $live[$source] = true;
                    $queue[] = $source;
                }
            }
        }

        return self::$live[$dfa] = $live;
    }
}
