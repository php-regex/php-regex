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
use PHPRegex\Automata\Model\DfaState;

/**
 * An automaton over bytes read on a UTF-8 subject, a character at a time:
 * each code point moves it as the bytes of its UTF-8 form do. Compared with
 * an automaton over code points, a pattern read as bytes and one read as
 * UTF-8, it tells them apart on the subjects both read, the valid UTF-8
 * strings. No code point stands for a surrogate, an overlong form or a
 * byte that begins no character: a pattern read as UTF-8 matches no such
 * subject, PCRE refuses it.
 *
 * @phpstan-import-type AlphabetRange from Dfa
 *
 * @internal
 */
final class Utf8Reading
{
    private const LAST_CODE_POINT = 0x10FFFF;

    /**
     * Each state's moves on n continuation bytes, as ranges of the value
     * they carry: three ints a range, its first value, its last and its
     * target, by state and n.
     *
     * @var array<int, array<int, list<int>>>
     */
    private array $tails = [];

    private function __construct(private readonly Dfa $bytes) {}

    public static function of(Dfa $bytes): Dfa
    {
        $reading = new self($bytes);
        $states = [];
        $boundaries = [0 => true, self::LAST_CODE_POINT + 1 => true];
        foreach ($bytes->states as $id => $state) {
            $ranges = $reading->characters($id);
            for ($at = 0, $count = \count($ranges); $at < $count; $at += 3) {
                $boundaries[$ranges[$at]] = true;
                $boundaries[$ranges[$at + 1] + 1] = true;
            }

            $states[$id] = new DfaState($id, [], $state->isAccepting, $ranges);
        }

        $points = array_keys($boundaries);
        sort($points);
        $alphabet = [];
        for ($i = 0, $last = \count($points) - 1; $i < $last; $i++) {
            $alphabet[] = [$points[$i], $points[$i + 1] - 1];
        }

        return new Dfa($bytes->startState, $states, $alphabet, 0, self::LAST_CODE_POINT);
    }

    /**
     * The state's moves on each code point, its UTF-8 form read a byte at a
     * time: one byte below 0x80, a lead byte and one to three continuation
     * bytes above.
     *
     * @return list<int>
     */
    private function characters(int $state): array
    {
        $ranges = [];
        for ($byte = 0; $byte < 0x80; $byte++) {
            $target = $this->bytes->getState($state)->transitionFor($byte);
            if (null !== $target) {
                self::add($ranges, $byte, $byte, $target);
            }
        }

        // The lead bytes, the continuation bytes after them, and the code
        // points their forms may stand for: no overlong form, no surrogate.
        foreach ([[0xC2, 0xDF, 1, 0x1F], [0xE0, 0xEF, 2, 0x0F], [0xF0, 0xF4, 3, 0x07]] as [$first, $last, $continuations, $mask]) {
            for ($lead = $first; $lead <= $last; $lead++) {
                $next = $this->bytes->getState($state)->transitionFor($lead);
                if (null === $next) {
                    continue;
                }

                $base = ($lead & $mask) << (6 * $continuations);
                $tail = $this->tail($next, $continuations);
                for ($at = 0, $count = \count($tail); $at < $count; $at += 3) {
                    $from = max($base + $tail[$at], [1 => 0x80, 2 => 0x800, 3 => 0x10000][$continuations]);
                    $to = min($base + $tail[$at + 1], self::LAST_CODE_POINT);
                    // The surrogates have no UTF-8 form.
                    foreach ([[$from, min($to, 0xD7FF)], [max($from, 0xE000), $to]] as [$low, $high]) {
                        if ($low <= $high) {
                            self::add($ranges, $low, $high, $tail[$at + 2]);
                        }
                    }
                }
            }
        }

        return $ranges;
    }

    /**
     * The state's moves on n continuation bytes, 0x80 to 0xBF each, by the
     * value of their low six bits read in order.
     *
     * @return list<int>
     */
    private function tail(int $state, int $continuations): array
    {
        if (isset($this->tails[$state][$continuations])) {
            return $this->tails[$state][$continuations];
        }

        $ranges = [];
        $shift = 6 * ($continuations - 1);
        for ($byte = 0x80; $byte <= 0xBF; $byte++) {
            $next = $this->bytes->getState($state)->transitionFor($byte);
            if (null === $next) {
                continue;
            }

            $value = ($byte & 0x3F) << $shift;
            if (1 === $continuations) {
                self::add($ranges, $value, $value, $next);

                continue;
            }

            $tail = $this->tail($next, $continuations - 1);
            for ($at = 0, $count = \count($tail); $at < $count; $at += 3) {
                self::add($ranges, $value + $tail[$at], $value + $tail[$at + 1], $tail[$at + 2]);
            }
        }

        return $this->tails[$state][$continuations] = $ranges;
    }

    /**
     * Appends a range after the last one, joined to it when it follows it
     * and goes to the same state.
     *
     * @param list<int> $ranges
     */
    private static function add(array &$ranges, int $first, int $last, int $target): void
    {
        $count = \count($ranges);
        if ($count >= 3 && $ranges[$count - 1] === $target && $ranges[$count - 2] + 1 === $first) {
            array_splice($ranges, -2, 1, [$last]);

            return;
        }

        array_push($ranges, $first, $last, $target);
    }
}
