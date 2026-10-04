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

namespace PHPRegex\Automata\Match;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Parser\Hir\AlternationHir;
use PHPRegex\Parser\Hir\AssertionHir;
use PHPRegex\Parser\Hir\AssertionKind;
use PHPRegex\Parser\Hir\AtomicHir;
use PHPRegex\Parser\Hir\CaptureHir;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassHir;
use PHPRegex\Parser\Hir\ConcatHir;
use PHPRegex\Parser\Hir\EmptyHir;
use PHPRegex\Parser\Hir\Greed;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\LiteralHir;
use PHPRegex\Parser\Hir\LookHir;
use PHPRegex\Parser\Hir\RepetitionHir;

/**
 * Builds the priority NFA of a pattern, for the fragment where trying the
 * paths in priority order, all at once, picks the match PCRE's backtracking
 * picks: no backreference, lookaround, atomic group or possessive
 * quantifier, no loop over a body that can match empty, anchors only where
 * they test the edges of the subject.
 *
 * @internal
 */
final class PriorityNfaBuilder
{
    public const BACKREFERENCE_MESSAGE = 'Backreferences, subroutines, conditionals, callouts and control verbs carry match state the match solver cannot read.';

    public const LOOKAROUND_MESSAGE = 'Lookarounds look past the match, which the match solver does not read.';

    public const ATOMIC_MESSAGE = 'Atomic groups and possessive quantifiers cut the paths PCRE would try, which the match solver does not read.';

    public const NULLABLE_LOOP_MESSAGE = 'A repeated body that can match empty is stopped by PCRE after an empty iteration, which the match solver does not read.';

    public const ASSERTION_MESSAGE = 'Word boundaries, multiline anchors, \G and \K are conditions the match solver does not read.';

    public const TRAILING_ANCHOR_MESSAGE = 'An end anchor followed by more of the pattern cannot be read by the match solver.';

    private PriorityNfa $nfa;

    public function __construct(private readonly int $maxStates, private readonly string $pattern) {}

    /**
     * @throws ComplexityException
     */
    public function build(Hir $hir, bool $unicode): PriorityNfa
    {
        $this->nfa = new PriorityNfa($unicode);
        $match = $this->nfa->add(PriorityNfa::MATCH);
        $this->nfa->start = $this->state($hir, $match);
        ksort($this->nfa->names);

        return $this->nfa;
    }

    /**
     * The state that matches the node, then goes on to the next one: the
     * automaton is built from its end.
     *
     * @throws ComplexityException
     */
    private function state(Hir $hir, int $next): int
    {
        if (\count($this->nfa->kinds) > $this->maxStates) {
            throw new ComplexityException(\sprintf('The match solver needs more than %d states for this pattern.', $this->maxStates), 0, $this->pattern);
        }

        return match (true) {
            $hir instanceof EmptyHir => $next,
            $hir instanceof LiteralHir => $this->literal($hir->codePoints, $next),
            $hir instanceof ClassHir => $this->char($hir->set, $next),
            $hir instanceof ConcatHir => array_reduce(array_reverse($hir->parts), fn (int $after, Hir $part): int => $this->state($part, $after), $next),
            $hir instanceof AlternationHir => $this->split(array_map(fn (Hir $branch): int => $this->state($branch, $next), $hir->branches)),
            $hir instanceof RepetitionHir => $this->repetition($hir, $next),
            $hir instanceof CaptureHir => $this->capture($hir, $next),
            $hir instanceof AssertionHir => $this->assertion($hir, $next),
            $hir instanceof LookHir => throw new ComplexityException(self::LOOKAROUND_MESSAGE, $hir->startPosition, $this->pattern),
            $hir instanceof AtomicHir => throw new ComplexityException(self::ATOMIC_MESSAGE, $hir->startPosition, $this->pattern),
            default => throw new ComplexityException(self::BACKREFERENCE_MESSAGE, $hir->startPosition, $this->pattern),
        };
    }

    /**
     * @param list<int> $codePoints
     */
    private function literal(array $codePoints, int $next): int
    {
        foreach (array_reverse($codePoints) as $codePoint) {
            $next = $this->char(CharSet::single($codePoint), $next);
        }

        return $next;
    }

    private function char(CharSet $set, int $next): int
    {
        $state = $this->nfa->add(PriorityNfa::CHAR);
        $this->nfa->sets[$state] = $set;
        $this->nfa->next[$state] = $next;

        return $state;
    }

    /**
     * @param list<int> $targets most preferred first
     */
    private function split(array $targets): int
    {
        $state = $this->nfa->add(PriorityNfa::SPLIT);
        $this->nfa->targets[$state] = $targets;

        return $state;
    }

    /**
     * @throws ComplexityException
     */
    private function repetition(RepetitionHir $hir, int $next): int
    {
        if (Greed::Possessive === $hir->greed) {
            throw new ComplexityException(self::ATOMIC_MESSAGE, $hir->startPosition, $this->pattern);
        }

        if ((null === $hir->max || $hir->max > 1) && 0 === $hir->body->properties->minLength) {
            throw new ComplexityException(self::NULLABLE_LOOP_MESSAGE, $hir->startPosition, $this->pattern);
        }

        $greedy = Greed::Greedy === $hir->greed;
        if (null === $hir->max) {
            // The loop: one more iteration or out, in the order the greed says.
            $loop = $this->split([]);
            $body = $this->state($hir->body, $loop);
            $this->nfa->targets[$loop] = $greedy ? [$body, $next] : [$next, $body];
            $state = $loop;
        } else {
            // Each optional copy is tried only once the one before matched.
            $state = $next;
            for ($copy = $hir->min; $copy < $hir->max; $copy++) {
                $body = $this->state($hir->body, $state);
                $state = $this->split($greedy ? [$body, $next] : [$next, $body]);
            }
        }

        for ($copy = 0; $copy < $hir->min; $copy++) {
            $state = $this->state($hir->body, $state);
        }

        return $state;
    }

    /**
     * @throws ComplexityException
     */
    private function capture(CaptureHir $hir, int $next): int
    {
        $this->nfa->groupCount = max($this->nfa->groupCount, $hir->index);
        if (null !== $hir->name) {
            $this->nfa->names[$hir->index] ??= $hir->name;
        }

        $close = $this->tag(2 * $hir->index + 1, $next);

        return $this->tag(2 * $hir->index, $this->state($hir->body, $close));
    }

    private function tag(int $slot, int $next): int
    {
        $state = $this->nfa->add(PriorityNfa::TAG);
        $this->nfa->slots[$state] = $slot;
        $this->nfa->next[$state] = $next;

        return $state;
    }

    /**
     * @throws ComplexityException
     */
    private function assertion(AssertionHir $hir, int $next): int
    {
        $kind = match ($hir->kind) {
            AssertionKind::SubjectStart => PriorityNfa::START,
            AssertionKind::SubjectEnd => PriorityNfa::END,
            AssertionKind::EndOrFinalNewline => PriorityNfa::END_OR_FINAL_NEWLINE,
            default => throw new ComplexityException(self::ASSERTION_MESSAGE, $hir->startPosition, $this->pattern),
        };

        // What follows an end anchor must read nothing: it is checked when
        // the subject ends, or a final newline is all that is left.
        if (PriorityNfa::START !== $kind && $this->readsMore($next, [])) {
            throw new ComplexityException(self::TRAILING_ANCHOR_MESSAGE, $hir->startPosition, $this->pattern);
        }

        $state = $this->nfa->add($kind);
        $this->nfa->next[$state] = $next;

        return $state;
    }

    /**
     * @param array<int, true> $seen
     */
    private function readsMore(int $state, array $seen): bool
    {
        if (isset($seen[$state])) {
            return false; // a cycle after an end anchor loops over nothing, refused before it is built
        }
        $seen[$state] = true;

        $kind = $this->nfa->kinds[$state];
        if (PriorityNfa::SPLIT === $kind) {
            foreach ($this->nfa->targets[$state] as $target) {
                if ($this->readsMore($target, $seen)) {
                    return true;
                }
            }

            return false;
        }

        return match ($kind) {
            PriorityNfa::CHAR, PriorityNfa::START => true,
            PriorityNfa::MATCH => false,
            default => $this->readsMore($this->nfa->next[$state], $seen),
        };
    }
}
