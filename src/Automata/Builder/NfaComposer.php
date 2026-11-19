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

namespace PHPRegex\Automata\Builder;

use PHPRegex\Automata\Exception\IncompatibleArgumentException;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Model\NfaState;
use PHPRegex\Automata\Model\NfaTransition;
use PHPRegex\Parser\Hir\CharSet;

/**
 * Composes NFAs into one, never through the text of their patterns: the
 * union of several, and the concatenation of two around a separator, a
 * code point past Unicode that no subject holds, so the parts of a tuple,
 * a method, a scheme, a host and a path, never run into one another.
 *
 * Each NFA keeps its own states, renumbered after the ones before it; the
 * composed one reads the widest alphabet of its parts.
 *
 * @internal
 */
final class NfaComposer
{
    /**
     * The first code point past Unicode: a separator no pattern matches.
     */
    public const SEPARATOR = 0x110000;

    /**
     * The NFA matching what any of them matches.
     */
    public function union(Nfa $first, Nfa ...$others): Nfa
    {
        $states = [];
        $starts = [];
        $min = $first->minCodePoint;
        $max = $first->maxCodePoint;
        $offset = 1;
        foreach ([$first, ...$others] as $nfa) {
            [$renumbered, $start] = self::renumber($nfa, $offset);
            $states += $renumbered;
            $starts[] = $start;
            $offset += \count($renumbered);
            $min = min($min, $nfa->minCodePoint);
            $max = max($max, $nfa->maxCodePoint);
        }

        $states[0] = new NfaState(0, [], $starts, false);
        ksort($states);

        return new Nfa(0, $states, $min, $max);
    }

    /**
     * The NFA matching what the left one matches, the separator, then what
     * the right one matches.
     *
     * @throws IncompatibleArgumentException when the separator is a code point a pattern may match
     */
    public function concat(Nfa $left, Nfa $right, int $separator = self::SEPARATOR): Nfa
    {
        if ($separator < self::SEPARATOR) {
            throw new IncompatibleArgumentException(\sprintf('The separator must lie past Unicode, at 0x%X or above: 0x%X given.', self::SEPARATOR, $separator));
        }

        [$leftStates, $leftStart] = self::renumber($left, 0);
        $bridge = \count($leftStates);
        [$rightStates, $rightStart] = self::renumber($right, $bridge + 1);

        $states = [];
        foreach ($leftStates as $id => $state) {
            $states[$id] = $state->isAccepting
                ? new NfaState($id, $state->transitions, [...$state->epsilonTransitions, $bridge], false)
                : $state;
        }

        $states[$bridge] = new NfaState($bridge, [new NfaTransition(CharSet::single($separator), $rightStart)], [], false);
        $states += $rightStates;

        return new Nfa($leftStart, $states, min($left->minCodePoint, $right->minCodePoint), max($left->maxCodePoint, $right->maxCodePoint, $separator));
    }

    /**
     * The NFA's states numbered from the offset, and its start.
     *
     * @return array{array<int, NfaState>, int}
     */
    private static function renumber(Nfa $nfa, int $offset): array
    {
        $numbers = [];
        foreach ($nfa->states as $id => $_) {
            $numbers[$id] = $offset + \count($numbers);
        }

        $states = [];
        foreach ($nfa->states as $id => $state) {
            $transitions = [];
            foreach ($state->transitions as $transition) {
                $transitions[] = new NfaTransition($transition->charSet, $numbers[$transition->target]);
            }

            $epsilon = [];
            foreach ($state->epsilonTransitions as $target) {
                $epsilon[] = $numbers[$target];
            }

            $states[$numbers[$id]] = new NfaState($numbers[$id], $transitions, $epsilon, $state->isAccepting);
        }

        return [$states, $numbers[$nfa->startState]];
    }
}
