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
use PHPRegex\Parser\Analysis\GroupIndex;
use PHPRegex\Parser\Hir\AlternationHir;
use PHPRegex\Parser\Hir\AssertionHir;
use PHPRegex\Parser\Hir\AssertionKind;
use PHPRegex\Parser\Hir\AtomicHir;
use PHPRegex\Parser\Hir\CaptureHir;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassHir;
use PHPRegex\Parser\Hir\ConcatHir;
use PHPRegex\Parser\Hir\ConditionalHir;
use PHPRegex\Parser\Hir\EmptyHir;
use PHPRegex\Parser\Hir\Greed;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\LiteralHir;
use PHPRegex\Parser\Hir\LookHir;
use PHPRegex\Parser\Hir\OpaqueHir;
use PHPRegex\Parser\Hir\RepetitionHir;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\SubroutineNode;

/**
 * Builds the priority NFA of a pattern, for the fragment where trying the
 * paths in priority order, all at once, picks the match PCRE's backtracking
 * picks: no backreference, lookaround or atomic group, no possessive
 * quantifier that could have to give anything back, no loop over a body
 * that can match empty, anchors only where they test the edges of the
 * subject.
 *
 * A call of a group runs the body of the group where the call stands: from
 * PCRE2 10.30 the engine backtracks into it, the groups inside it are given
 * back the values they held before it, and the body keeps the options it
 * was written under, as its form already does. A group a DEFINE holds runs
 * nowhere else, yet takes its number and its name.
 *
 * Read through calls, a call that runs its own group again, "(?R)" for the
 * whole pattern, leads back to where that copy of the group starts, and the
 * end of the copy to where each such call returns as well as to what follows
 * the copy: the stack of calls forgotten, the automaton holds every path the
 * engine may take however deep the calls run, and more. Its possessive
 * quantifiers are checked against all it may read after them; it is never
 * run for a match.
 *
 * @internal
 */
final class PriorityNfaBuilder
{
    public const BACKREFERENCE_MESSAGE = 'Backreferences, subroutines, conditionals, callouts and control verbs carry match state the match solver cannot read.';

    public const CALL_MESSAGE = 'A call that may run its own group again, that returns groups, or whose group the match solver does not read is not one it runs where it stands.';

    public const LOOKAROUND_MESSAGE = 'Lookarounds look past the match, which the match solver does not read.';

    public const ATOMIC_MESSAGE = 'Atomic groups and possessive quantifiers cut the paths PCRE would try, which the match solver does not read.';

    public const NULLABLE_LOOP_MESSAGE = 'A repeated body that can match empty is stopped by PCRE after an empty iteration, which the match solver does not read.';

    public const ASSERTION_MESSAGE = 'Word boundaries, multiline anchors, \G and \K are conditions the match solver does not read.';

    public const TRAILING_ANCHOR_MESSAGE = 'An end anchor followed by more of the pattern cannot be read by the match solver.';

    public const ZERO_REPEAT_ASSERTION_MESSAGE = 'An anchor, an assertion or a lookaround in a group repeated no time still moves where PCRE2 tries a match, which the match solver does not read.';

    /**
     * The copy of the whole pattern, beside the groups keyed by object id.
     */
    private const WHOLE = -1;

    private PriorityNfa $nfa;

    /**
     * The possessive quantifiers read as greedy ones, each with the first
     * characters of its body and the state that follows it, checked once
     * the whole automaton is built.
     *
     * @var list<array{first: CharSet, next: int, position: int}>
     */
    private array $possessives = [];

    /**
     * The end anchors, each with the state that follows it, checked again
     * once the whole automaton is built: inside a loop, what follows the
     * body, another iteration or what comes after the loop, is written only
     * once the body is.
     *
     * @var list<array{next: int, position: int}>
     */
    private array $endAnchors = [];

    /**
     * The form being built and the tree it was translated from, which
     * numbers the groups a call runs.
     */
    private Hir $form;

    private NodeInterface $tree;

    /**
     * @var list<Hir> what each DEFINE of the tree holds, which only a call
     *                runs
     */
    private array $defined = [];

    /**
     * The groups of the tree as PCRE numbers them, and the group of the
     * form or of a DEFINE that starts at each offset: read once the build
     * meets a call.
     *
     * @var array{GroupIndex, array<int, CaptureHir>}|null
     */
    private ?array $groups = null;

    /**
     * The groups whose body is being built, by object id: a call of one of
     * them runs it inside itself.
     *
     * @var array<int, true>
     */
    private array $running = [];

    /**
     * How many calls the body being built runs inside: there the groups
     * write nothing.
     */
    private int $calls = 0;

    /**
     * Whether a call that runs its own group again is read through rather
     * than refused.
     */
    private bool $throughCalls = false;

    /**
     * Read through calls, the copies being built, the innermost last: the
     * group each copies, by object id, or the whole pattern, and the state
     * its end leads to. A group stands inside a copy of itself where it
     * calls a group that holds it.
     *
     * @var list<array{int, int}>
     */
    private array $copies = [];

    /**
     * The calls that run a copy again, by the state its end leads to: each
     * leads back to where the copy starts once it is built.
     *
     * @var array<int, list<int>>
     */
    private array $copyCalls = [];

    public function __construct(private readonly int $maxStates, private readonly string $pattern) {}

    /**
     * @param NodeInterface $tree         the tree the form was translated
     *                                    from, the pattern a RegexNode holds
     * @param list<Hir>     $defined      what each DEFINE of the tree holds,
     *                                    which the form leaves out, read
     *                                    under the options in force where it
     *                                    stands
     * @param bool          $throughCalls whether a call that runs its own
     *                                    group again is read through, for an
     *                                    automaton never run for a match
     *
     * @throws ComplexityException
     */
    public function build(Hir $hir, bool $unicode, NodeInterface $tree, array $defined, bool $throughCalls = false): PriorityNfa
    {
        $this->nfa = new PriorityNfa($unicode);
        $this->possessives = [];
        $this->endAnchors = [];
        $this->form = $hir;
        $this->defined = $defined;
        $this->tree = $tree;
        $this->groups = null;
        $this->throughCalls = $throughCalls;
        $this->copies = [];
        $this->copyCalls = [];
        // A group a DEFINE holds runs only where a call runs it, and writes
        // nothing there, yet takes its number and its name.
        foreach ($this->groupsIn($defined) as $group) {
            $this->countGroup($group);
        }
        $match = $this->add(PriorityNfa::MATCH);
        $end = $throughCalls ? $this->enter(self::WHOLE, $match) : $match;
        $this->nfa->start = $this->state($hir, $end);
        if ($throughCalls) {
            $this->leave($end, $this->nfa->start);
        }
        ksort($this->nfa->names);

        $this->assertPossessivesNeverGiveBack();

        foreach ($this->endAnchors as $anchor) {
            if ($this->readsMore($anchor['next'])) {
                throw new ComplexityException(self::TRAILING_ANCHOR_MESSAGE, $anchor['position'], $this->pattern);
            }
        }

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
            $hir instanceof OpaqueHir && $hir->node instanceof SubroutineNode => $this->call($hir->node, $next),
            default => throw new ComplexityException(self::BACKREFERENCE_MESSAGE, $hir->startPosition, $this->pattern),
        };
    }

    /**
     * The body of the group the call runs, built where the call stands.
     *
     * @throws ComplexityException
     */
    private function call(SubroutineNode $call, int $next): int
    {
        $group = $this->calledGroup($call);

        // Read through calls, a call of a copy being built, the innermost,
        // leads back to where it starts, and returns where its end leads.
        $runs = null !== $group ? spl_object_id($group) : (self::callsWhole($call) ? self::WHOLE : null);
        for ($copy = \count($this->copies) - 1; $copy >= 0; $copy--) {
            [$copied, $end] = $this->copies[$copy];
            if ($runs === $copied) {
                $state = $this->split([]);
                $this->copyCalls[$end][] = $state;
                $this->nfa->targets[$end][] = $next;

                return $state;
            }
        }

        if (null === $group || isset($this->running[spl_object_id($group)])) {
            throw new ComplexityException(self::CALL_MESSAGE, $call->getStartPosition(), $this->pattern);
        }

        $this->calls++;
        $state = $this->capture($group, $next);
        $this->calls--;

        return $state;
    }

    /**
     * The group the call runs, or null for a call of the whole pattern, of
     * a group neither the form nor a DEFINE holds, as a condition holds
     * it, or one that returns groups, which writes them.
     */
    private function calledGroup(SubroutineNode $call): ?CaptureHir
    {
        // "(?R)" calls the whole pattern, "(?&R)" a group named R.
        if ([] !== $call->returnedGroups || ('R' === $call->reference && '' === $call->syntax)) {
            return null;
        }

        [$index, $groups] = $this->groups ??= [GroupIndex::of($this->tree), $this->groupsByStart()];
        foreach ($index->groupsCalledBy($call) as $group) {
            if (isset($groups[$group->getStartPosition()])) {
                return $groups[$group->getStartPosition()];
            }
        }

        return null;
    }

    /**
     * Whether the call runs the whole pattern: "(?R)", "(?0)", "\g<0>".
     */
    private static function callsWhole(SubroutineNode $call): bool
    {
        return [] === $call->returnedGroups && ('0' === $call->reference || ('R' === $call->reference && '' === $call->syntax));
    }

    /**
     * Read through calls, the state the end of a copy leads to, the state
     * after it, before it is built: each call that runs the copy again
     * adds the state after the call.
     */
    private function enter(int $group, int $next): int
    {
        $end = $this->split([$next]);
        $this->copies[] = [$group, $end];

        return $end;
    }

    /**
     * The innermost copy built: each call that ran it again leads back to
     * where it starts.
     */
    private function leave(int $end, int $start): void
    {
        array_pop($this->copies);
        foreach ($this->copyCalls[$end] ?? [] as $call) {
            $this->nfa->targets[$call] = [$start];
        }
    }

    /**
     * The groups of the form and of each DEFINE, by the offset each starts
     * at.
     *
     * @return array<int, CaptureHir>
     */
    private function groupsByStart(): array
    {
        $groups = [];
        foreach ($this->groupsIn([$this->form, ...$this->defined]) as $group) {
            $groups[$group->startPosition] = $group;
        }

        return $groups;
    }

    /**
     * @param list<Hir> $forms
     *
     * @return list<CaptureHir>
     */
    private function groupsIn(array $forms): array
    {
        $groups = [];
        $pending = $forms;
        while (null !== $node = array_pop($pending)) {
            if ($node instanceof CaptureHir) {
                $groups[] = $node;
            }

            array_push($pending, ...$node->children());
        }

        return $groups;
    }

    private function add(int $kind): int
    {
        if (\count($this->nfa->kinds) >= $this->maxStates) {
            throw new ComplexityException(\sprintf('The match solver needs more than %d states for this pattern.', $this->maxStates), 0, $this->pattern, diagnostic: ['phase' => 'match automaton', 'limit' => $this->maxStates]);
        }

        return $this->nfa->add($kind);
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
        $state = $this->add(PriorityNfa::CHAR);
        $this->nfa->sets[$state] = $set;
        $this->nfa->next[$state] = $next;

        return $state;
    }

    /**
     * @param list<int> $targets most preferred first
     */
    private function split(array $targets): int
    {
        $state = $this->add(PriorityNfa::SPLIT);
        $this->nfa->targets[$state] = $targets;

        return $state;
    }

    /**
     * @throws ComplexityException
     */
    private function repetition(RepetitionHir $hir, int $next): int
    {
        // A possessive quantifier writes what the greedy one writes when the
        // greedy one never has to give anything back: its body goes one
        // fixed way, and nothing after it can start where an iteration
        // started. That is checked once what follows it is built.
        if (Greed::Possessive === $hir->greed) {
            $first = self::fixedFirst($hir->body);
            if (null === $first) {
                throw new ComplexityException(self::ATOMIC_MESSAGE, $hir->startPosition, $this->pattern);
            }

            $this->possessives[] = ['first' => $first, 'next' => $next, 'position' => $hir->startPosition];
        }

        // A call matches what the body of its group matches, which its own
        // form does not say.
        $body = $hir->body instanceof OpaqueHir && $hir->body->node instanceof SubroutineNode ? $this->calledGroup($hir->body->node) ?? $hir->body : $hir->body;
        if ((null === $hir->max || $hir->max > 1) && 0 === $body->properties->minLength) {
            throw new ComplexityException(self::NULLABLE_LOOP_MESSAGE, $hir->startPosition, $this->pattern);
        }

        // Never tried, the body still numbers and names its groups:
        // preg_match_all() writes an empty group 1 for "(a){0}b".
        if (0 === $hir->max) {
            $this->countGroupsIn($hir->body);

            return $next;
        }

        $greedy = Greed::Lazy !== $hir->greed;
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
        $this->running[spl_object_id($hir)] = true;
        $end = $this->throughCalls ? $this->enter(spl_object_id($hir), $next) : $next;

        // Inside a call the group writes nothing: PCRE2 gives it back the
        // value it held before the call.
        if ($this->calls > 0) {
            $state = $this->state($hir->body, $end);
        } else {
            $this->countGroup($hir);
            $close = $this->tag(2 * $hir->index + 1, $end);
            $state = $this->tag(2 * $hir->index, $this->state($hir->body, $close));
        }

        if ($this->throughCalls) {
            $this->leave($end, $state);
        }

        unset($this->running[spl_object_id($hir)]);

        return $state;
    }

    private function countGroup(CaptureHir $hir): void
    {
        $this->nfa->groupCount = max($this->nfa->groupCount, $hir->index);
        if (null !== $hir->name) {
            $this->nfa->names[$hir->index] ??= $hir->name;
        }
    }

    /**
     * The groups of a body no state is built for, which must hold nothing
     * PCRE2 may read to choose where it tries a match.
     *
     * @throws ComplexityException
     */
    private function countGroupsIn(Hir $hir): void
    {
        $pending = [$hir];
        while (null !== $node = array_pop($pending)) {
            if ($node instanceof CaptureHir) {
                $this->countGroup($node);
            } elseif ($node instanceof AssertionHir || $node instanceof LookHir) {
                // "/(?:x|(?=a)){0}\w/" finds no match in "\tb", where "/\w/"
                // finds "b".
                throw new ComplexityException(self::ZERO_REPEAT_ASSERTION_MESSAGE, $node->startPosition, $this->pattern);
            } elseif ($node instanceof OpaqueHir || $node instanceof ConditionalHir) {
                // A verb, a condition or a group the tree does not show, as
                // in "(*scs:(1)(b))", is read nowhere else either.
                throw new ComplexityException(self::BACKREFERENCE_MESSAGE, $node->startPosition, $this->pattern);
            }

            array_push($pending, ...$node->children());
        }
    }

    private function tag(int $slot, int $next): int
    {
        $state = $this->add(PriorityNfa::TAG);
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
        if (PriorityNfa::START !== $kind) {
            if ($this->readsMore($next)) {
                throw new ComplexityException(self::TRAILING_ANCHOR_MESSAGE, $hir->startPosition, $this->pattern);
            }

            $this->endAnchors[] = ['next' => $next, 'position' => $hir->startPosition];
        }

        $state = $this->add($kind);
        $this->nfa->next[$state] = $next;

        return $state;
    }

    /**
     * The characters a body that matches one fixed way starts with: a
     * class, a literal, or a sequence of them, through captures. Null for
     * anything with a choice inside, which a greedy quantifier may undo.
     */
    private static function fixedFirst(Hir $body): ?CharSet
    {
        while ($body instanceof CaptureHir) {
            $body = $body->body;
        }

        if ($body instanceof ClassHir) {
            return $body->set->isEmpty() ? null : $body->set;
        }

        if ($body instanceof LiteralHir) {
            return [] === $body->codePoints ? null : CharSet::single($body->codePoints[0]);
        }

        if (!$body instanceof ConcatHir || [] === $body->parts) {
            return null;
        }

        $first = null;
        foreach ($body->parts as $part) {
            $partFirst = self::fixedFirst($part);
            if (null === $partFirst) {
                return null;
            }

            $first ??= $partFirst;
        }

        return $first;
    }

    /**
     * Each possessive quantifier read as a greedy one, checked against what
     * follows it now that the whole automaton is built.
     *
     * @throws ComplexityException
     */
    private function assertPossessivesNeverGiveBack(): void
    {
        foreach ($this->possessives as $possessive) {
            if (!$this->neverTakes($possessive['first'], $possessive['next'])) {
                throw new ComplexityException(self::ATOMIC_MESSAGE, $possessive['position'], $this->pattern);
            }
        }
    }

    /**
     * Whether nothing reached from the state, before it reads a character,
     * can hold where a possessive quantifier's iteration started: the
     * characters it reads first share none with the body's, and no anchor
     * on the way could hold there and not after the last iteration. A
     * greedy quantifier given back to such a place finds no match there, so
     * it never gives back, and the possessive one writes what it writes.
     */
    private function neverTakes(CharSet $first, int $state): bool
    {
        $pending = [$state];
        $seen = [];
        while (null !== $state = array_pop($pending)) {
            if (isset($seen[$state])) {
                continue;
            }
            $seen[$state] = true;

            switch ($this->nfa->kinds[$state]) {
                case PriorityNfa::MATCH:
                    break;
                case PriorityNfa::SPLIT:
                    array_push($pending, ...$this->nfa->targets[$state]);

                    break;
                case PriorityNfa::CHAR:
                    if (!$this->nfa->sets[$state]->intersect($first)->isEmpty()) {
                        return false;
                    }

                    break;
                case PriorityNfa::START:
                    // "\A" holds before the first iteration as well as before none.
                    return false;
                case PriorityNfa::END_OR_FINAL_NEWLINE:
                    // "$" holds before a final newline the body may have read.
                    if ($first->contains(0x0A)) {
                        return false;
                    }
                    $pending[] = $this->nfa->next[$state];

                    break;
                default:
                    $pending[] = $this->nfa->next[$state];
            }
        }

        return true;
    }

    private function readsMore(int $state): bool
    {
        $pending = [$state];
        $seen = [];
        while (null !== $state = array_pop($pending)) {
            if (isset($seen[$state])) {
                continue;
            }
            // Share visitation across all branches: empty alternatives can
            // reach the same continuation through exponentially many paths.
            $seen[$state] = true;

            switch ($this->nfa->kinds[$state]) {
                case PriorityNfa::CHAR:
                case PriorityNfa::START:
                    return true;
                case PriorityNfa::MATCH:
                    break;
                case PriorityNfa::SPLIT:
                    array_push($pending, ...$this->nfa->targets[$state]);

                    break;
                default:
                    $pending[] = $this->nfa->next[$state];
            }
        }

        return false;
    }
}
