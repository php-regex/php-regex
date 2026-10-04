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

namespace PHPRegex\Automata\Transform;

use PHPRegex\Automata\Builder\NfaBuilder;
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Model\NfaFragment;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Parser\Hir\AlternationHir;
use PHPRegex\Parser\Hir\AssertionHir;
use PHPRegex\Parser\Hir\AssertionKind;
use PHPRegex\Parser\Hir\AtomicHir;
use PHPRegex\Parser\Hir\CaptureHir;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassHir;
use PHPRegex\Parser\Hir\ConcatHir;
use PHPRegex\Parser\Hir\ConditionalHir;
use PHPRegex\Parser\Hir\Greed;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\LiteralHir;
use PHPRegex\Parser\Hir\LookHir;
use PHPRegex\Parser\Hir\LookKind;
use PHPRegex\Parser\Hir\OpaqueHir;
use PHPRegex\Parser\Hir\RepetitionHir;

/**
 * Builds an NFA from the normalized form of a pattern, using Thompson
 * construction.
 *
 * Every character set comes from the tree: the engine oracle has already
 * read each class, escape, dot and caseless letter, so the walk only
 * assembles. What the form still carries that a pure language does not say
 * is refused with one message per reason, before any state is built.
 *
 * @internal
 */
final class HirToNfaTransformer
{
    public const OPAQUE_MESSAGE = 'Backreferences, subroutines, callouts and control verbs carry match state the automata solver cannot read as a pure language.';

    public const CONDITIONAL_MESSAGE = 'Conditional groups branch on match state the automata solver cannot read as a pure language.';

    public const NESTED_LOOKAROUND_MESSAGE = 'A lookaround inside a lookaround is beyond what the automata solver reads.';

    public const LOOKAROUND_ANCHOR_MESSAGE = 'An anchor inside a lookaround is beyond what the automata solver reads.';

    public const NON_ATOMIC_LOOKAROUND_MESSAGE = 'A non-atomic lookaround, (*napla:...) or its kind, backtracks into its body, which the automata solver does not read.';

    public const ATOMIC_MESSAGE = 'Atomic groups commit to their first match and never retry, which is ordered behaviour the solver cannot read as a pure language.';

    public const ASSERTION_MESSAGE = '\K, \G and anchors away from the edges of an alternative are zero-width conditions the automata solver cannot read as a pure language.';

    public const POSSESSIVE_MESSAGE = 'Possessive quantifiers never give back what they matched, which is ordered behaviour the solver cannot read as a pure language.';

    public const SURROGATE_MESSAGE = 'PCRE refuses any pattern that names a surrogate code point, which the automata solver cannot read as a pure language.';

    private const MIN_CODEPOINT = 0;

    private const MAX_CODEPOINT = 255;

    private const UNICODE_MAX_CODEPOINT = 0x10FFFF;

    private const SURROGATE_FIRST = 0xD800;

    private const SURROGATE_LAST = 0xDFFF;

    private NfaBuilder $builder;

    /**
     * The possessive repetitions an enclosing sequence has proven safe,
     * by object id, for the refusal walk being run.
     *
     * @var array<int, true>
     */
    private array $vouchedPossessives = [];

    private bool $insideLookaround = false;

    /**
     * @var array<int, LookHir> the mark state of each lookaround
     */
    private array $marks = [];

    public function __construct(private readonly string $pattern, private readonly bool $unicode) {}

    /**
     * @throws ComplexityException When the tree leaves the regular subset or a limit is reached
     */
    public function transform(Hir $hir, SolverOptions $options): Nfa
    {
        $this->assertTranslatable($hir, $options);

        $alphabetMax = $this->unicode ? self::UNICODE_MAX_CODEPOINT : self::MAX_CODEPOINT;
        $this->builder = new NfaBuilder($options->maxNfaStates, self::MIN_CODEPOINT, $alphabetMax);

        $this->marks = [];
        $fragment = $this->buildNode($hir);
        if (MatchMode::Partial === $options->matchMode) {
            [$startAnchored, $end] = $this->partialAnchorsOf($hir);
            $fragment = $this->wrapPartialMatch($fragment, $startAnchored, $end);
        }

        $nfa = $this->builder->build($fragment);
        $marks = $this->marks();
        if ([] === $marks) {
            return $nfa;
        }

        return (new LookaroundProduct($this->pattern, $this->unicode, $options))->build($nfa, $marks);
    }

    /**
     * The automaton of a lookaround's body: what it matches from where it
     * stands, or, for a lookbehind, any subject ending with what it matches.
     *
     * @throws ComplexityException
     */
    public function lookaroundBody(LookHir $look, SolverOptions $options): Nfa
    {
        $alphabetMax = $this->unicode ? self::UNICODE_MAX_CODEPOINT : self::MAX_CODEPOINT;
        $this->builder = new NfaBuilder($options->maxNfaStates, self::MIN_CODEPOINT, $alphabetMax);
        $fragment = $this->buildNode($look->body);
        $behind = LookKind::Behind === $look->kind || LookKind::NegativeBehind === $look->kind;

        return $this->builder->build($behind ? $this->wrapPartialMatch($fragment, false, AssertionKind::SubjectEnd) : $fragment);
    }

    /**
     * Whether the tree reads as a pure language, refused with one message
     * per reason when it does not: nothing is built.
     *
     * @throws ComplexityException
     */
    public function assertTranslatable(Hir $hir, SolverOptions $options): void
    {
        $this->vouchedPossessives = [];
        $this->assertNode($hir, $options, MatchMode::Full === $options->matchMode ? CharSet::empty() : null);
    }

    /**
     * The lookaround marks the last build left.
     *
     * @return array<int, LookHir>
     */
    private function marks(): array
    {
        return $this->marks;
    }

    /**
     * @param CharSet|null $follow the characters that may come right after the
     *                             node, null when unknown; under PARTIAL anything
     *                             may still be matched around the pattern
     *
     * @throws ComplexityException
     */
    private function assertNode(Hir $node, SolverOptions $options, ?CharSet $follow): void
    {
        if ($node instanceof OpaqueHir) {
            throw new ComplexityException(self::OPAQUE_MESSAGE, $node->startPosition, $this->pattern);
        }

        if ($node instanceof ConditionalHir) {
            throw new ComplexityException(self::CONDITIONAL_MESSAGE, $node->startPosition, $this->pattern);
        }

        if ($node instanceof LookHir) {
            if (!$node->atomic) {
                throw new ComplexityException(self::NON_ATOMIC_LOOKAROUND_MESSAGE, $node->startPosition, $this->pattern);
            }

            if ($this->insideLookaround) {
                throw new ComplexityException(self::NESTED_LOOKAROUND_MESSAGE, $node->startPosition, $this->pattern);
            }

            // The body is read as a language of its own, in full: what it
            // matches from the lookaround's position, or up to it.
            $this->insideLookaround = true;

            try {
                $this->assertNode($node->body, new SolverOptions(matchMode: MatchMode::Full, maxNfaStates: $options->maxNfaStates, maxDfaStates: $options->maxDfaStates), CharSet::empty());
            } finally {
                $this->insideLookaround = false;
            }

            return;
        }

        if ($node instanceof AtomicHir) {
            throw new ComplexityException(self::ATOMIC_MESSAGE, $node->startPosition, $this->pattern);
        }

        if ($node instanceof AssertionHir) {
            if ($this->insideLookaround) {
                throw new ComplexityException(self::LOOKAROUND_ANCHOR_MESSAGE, $node->startPosition, $this->pattern);
            }

            // A whole-tree assertion sits at the edge of its alternative by
            // definition: only the kinds carry a meaning there.
            $this->assertAssertionKind($node);

            return;
        }

        if ($node instanceof ClassHir && !$node->set->intersect(self::surrogates())->isEmpty()) {
            // The engine refuses to compile the pattern that names a
            // surrogate code point: no automaton may be read from it.
            throw new ComplexityException(self::SURROGATE_MESSAGE, $node->startPosition, $this->pattern);
        }

        if ($node instanceof LiteralHir) {
            foreach ($node->codePoints as $codePoint) {
                if ($codePoint >= self::SURROGATE_FIRST && $codePoint <= self::SURROGATE_LAST) {
                    throw new ComplexityException(self::SURROGATE_MESSAGE, $node->startPosition, $this->pattern);
                }
            }

            return;
        }

        if ($node instanceof RepetitionHir) {
            if (Greed::Possessive === $node->greed && !isset($this->vouchedPossessives[spl_object_id($node)])) {
                throw new ComplexityException(self::POSSESSIVE_MESSAGE, $node->startPosition, $this->pattern);
            }

            // Another iteration of the loop may follow the body, so the
            // loop's own first characters follow it too.
            $bodyFollow = null === $follow ? null
                : (null === $node->max || $node->max > 1 ? $node->body->properties->first?->union($follow) : $follow);

            $this->assertNode($node->body, $options, $bodyFollow);

            return;
        }

        if ($node instanceof ConcatHir) {
            $this->assertConcat($node, $options, $follow);

            return;
        }

        foreach ($node->children() as $child) {
            $this->assertNode($child, $options, $follow);
        }
    }

    /**
     * A sequence: its assertions may sit at its edges, and each part that
     * ends in a possessive quantifier is judged against what follows it —
     * the rest of the sequence and, at its end, whatever follows the
     * sequence itself.
     *
     * @throws ComplexityException
     */
    private function assertConcat(ConcatHir $node, SolverOptions $options, ?CharSet $follow): void
    {
        $parts = $node->parts;
        $lastIndex = \count($parts) - 1;

        foreach ($parts as $index => $part) {
            if ($part instanceof AssertionHir) {
                $this->assertAssertionPosition($part, 0 === $index, $lastIndex === $index);
            }
        }

        foreach ($parts as $index => $part) {
            $partFollow = $this->followSetOf($parts, $index, $follow);
            $possessive = $this->possessiveAtEnd($part);
            if (null !== $possessive) {
                $this->assertPossessiveIsSafe($possessive, $partFollow, $parts, $index);
            }

            $this->assertNode($part, $options, $partFollow);
        }
    }

    /**
     * The kinds that carry no meaning at the edge of an alternative: the
     * start of the subject at the start, its end (or a final newline) at
     * the end. Every other condition reads where the match stands.
     *
     * @throws ComplexityException
     */
    private function assertAssertionKind(AssertionHir $node): void
    {
        if (AssertionKind::SubjectStart === $node->kind
            || AssertionKind::SubjectEnd === $node->kind
            || AssertionKind::EndOrFinalNewline === $node->kind
            || self::isWordBoundary($node)
        ) {
            return;
        }

        throw new ComplexityException(self::ASSERTION_MESSAGE, $node->startPosition, $this->pattern);
    }

    /**
     * @throws ComplexityException
     */
    private function assertAssertionPosition(AssertionHir $node, bool $atStart, bool $atEnd): void
    {
        $this->assertAssertionKind($node);

        // A word boundary reads where it stands, as its lookarounds do.
        if (self::isWordBoundary($node)) {
            return;
        }

        $accepted = match ($node->kind) {
            AssertionKind::SubjectStart => $atStart,
            AssertionKind::SubjectEnd, AssertionKind::EndOrFinalNewline => $atEnd,
            default => false,
        };

        if (!$accepted) {
            throw new ComplexityException(self::ASSERTION_MESSAGE, $node->startPosition, $this->pattern);
        }
    }

    /**
     * A possessive quantifier reads the same language as its greedy
     * spelling when nothing that follows it can consume what it would have
     * to give back: the follower consumes no character of its own (its
     * first set is empty), or its first characters and the atom's share
     * none. What follows it is everything: the rest of its sequence and,
     * past the sequence's end — a capture, an alternation branch, a
     * repetition body all end where the engine keeps reading — whatever
     * follows the sequence itself. A follow that cannot be worked out,
     * or one a PARTIAL match may extend, refuses, and so does a possessive
     * with another one further along the sequence: each proof reads what
     * follows its own quantifier, and the two do not compose.
     *
     * @param list<Hir> $parts the parts of the sequence the possessive ends
     *
     * @throws ComplexityException
     */
    private function assertPossessiveIsSafe(RepetitionHir $possessive, ?CharSet $follow, array $parts, int $index): void
    {
        $atom = $this->atomFirstSet($possessive->body);
        $safe = null !== $follow
            && null !== $atom
            && $atom->intersect($follow)->isEmpty()
            && !$this->containsPossessiveBeyond($parts, $index + 1);

        if (!$safe) {
            throw new ComplexityException(self::POSSESSIVE_MESSAGE, $possessive->startPosition, $this->pattern);
        }

        $this->vouchedPossessives[spl_object_id($possessive)] = true;
    }

    /**
     * The first characters of everything after the position: the rest of
     * the sequence, through the followers that may be skipped, and what
     * follows the sequence itself. All followers skippable and nothing
     * beyond the sequence, an empty set; a follower or continuation whose
     * first characters cannot be worked out, null, which the rule above
     * reads as a refusal.
     *
     * @param list<Hir> $parts
     */
    private function followSetOf(array $parts, int $index, ?CharSet $beyond): ?CharSet
    {
        if (null === $beyond) {
            return null;
        }

        $rest = $beyond;
        for ($i = \count($parts) - 1; $i > $index; $i--) {
            $properties = $parts[$i]->properties;
            if (null === $properties->first) {
                return null;
            }

            $rest = true === $properties->nullable
                ? $properties->first->union($rest)
                : $properties->first;
        }

        return $rest;
    }

    private static function surrogates(): CharSet
    {
        return CharSet::range(self::SURROGATE_FIRST, self::SURROGATE_LAST);
    }

    /**
     * The characters the atom of a possessive quantifier consumes first,
     * when it always consumes from one set before anything else: a class,
     * a literal, a concatenation or a repetition that cannot be skipped
     * and starts the same way. An alternation, an empty set, or anything
     * that may match nothing first, stays refused.
     */
    private function atomFirstSet(Hir $atom): ?CharSet
    {
        while ($atom instanceof CaptureHir) {
            $atom = $atom->body;
        }

        if ($atom instanceof ClassHir || $atom instanceof LiteralHir) {
            $first = $atom->properties->first;

            return null === $first || $first->isEmpty() ? null : $first;
        }

        if ($atom instanceof ConcatHir) {
            $head = $atom->parts[0] ?? null;
            if (null === $head || false !== $head->properties->nullable) {
                return null;
            }

            return $this->atomFirstSet($head);
        }

        if ($atom instanceof RepetitionHir) {
            if ($atom->min < 1 || false !== $atom->body->properties->nullable) {
                return null;
            }

            return $this->atomFirstSet($atom->body);
        }

        return null;
    }

    /**
     * Whether a possessive quantifier occurs beyond the part that follows
     * the position, anywhere in the parts after it.
     *
     * @param list<Hir> $parts
     */
    private function containsPossessiveBeyond(array $parts, int $after): bool
    {
        for ($i = $after + 1, $count = \count($parts); $i < $count; $i++) {
            if ($this->containsPossessive($parts[$i])) {
                return true;
            }
        }

        return false;
    }

    private function containsPossessive(Hir $node): bool
    {
        if ($node instanceof RepetitionHir) {
            return Greed::Possessive === $node->greed || $this->containsPossessive($node->body);
        }

        foreach ($node->children() as $child) {
            if ($this->containsPossessive($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The possessive quantifier a subtree ends with, through captures and
     * sequences, or null. An assertion, an alternation or anything else in
     * the tail is a barrier: what follows it is not what follows the
     * quantifier.
     */
    private function possessiveAtEnd(Hir $node): ?RepetitionHir
    {
        while (true) {
            if ($node instanceof RepetitionHir) {
                return Greed::Possessive === $node->greed ? $node : null;
            }

            if ($node instanceof ConcatHir) {
                $node = $node->parts[\count($node->parts) - 1] ?? null;
                if (null === $node) {
                    return null;
                }

                continue;
            }

            if ($node instanceof CaptureHir) {
                $node = $node->body;

                continue;
            }

            return null;
        }
    }

    /**
     * @throws ComplexityException
     */
    private function buildNode(Hir $node): NfaFragment
    {
        if ($node instanceof ConcatHir) {
            return $this->buildConcat($node);
        }

        if ($node instanceof AlternationHir) {
            return $this->buildAlternation($node);
        }

        if ($node instanceof RepetitionHir) {
            // A possessive quantifier reaching the build was vouched by the
            // refusal walk; lazy and greedy read the same language.
            return $this->buildRepetition($node);
        }

        if ($node instanceof ClassHir) {
            return $this->buildClass($node->set);
        }

        if ($node instanceof LiteralHir) {
            return $this->buildLiteral($node);
        }

        if ($node instanceof CaptureHir) {
            return $this->buildNode($node->body);
        }

        if ($node instanceof AssertionHir && null !== $node->wordSet && self::isWordBoundary($node)) {
            return $this->buildWordBoundary($node, $node->wordSet);
        }

        if ($node instanceof LookHir) {
            // A mark the lookaround product reads: crossing it makes the
            // promise about what follows, or asks what came before.
            $mark = $this->builder->createState();
            $after = $this->builder->createState();
            $this->builder->addEpsilon($mark, $after);
            $this->marks[$mark] = $node;

            return new NfaFragment($mark, [$after]);
        }

        // An assertion the refusal walk accepted, or nothing at all: the
        // empty string.
        return $this->epsilonFragment();
    }

    /**
     * A word boundary as the lookarounds it stands for: \b between a word
     * character and something else, (?<=\w)(?!\w)|(?<!\w)(?=\w); \B
     * where it is not, (?<=\w)(?=\w)|(?<!\w)(?!\w).
     *
     * @throws ComplexityException
     */
    private function buildWordBoundary(AssertionHir $node, CharSet $word): NfaFragment
    {
        $boundary = AssertionKind::WordBoundary === $node->kind;
        $pairs = [
            [LookKind::Behind, $boundary ? LookKind::NegativeAhead : LookKind::Ahead],
            [LookKind::NegativeBehind, $boundary ? LookKind::Ahead : LookKind::NegativeAhead],
        ];

        $start = $this->builder->createState();
        $end = $this->builder->createState();
        foreach ($pairs as [$before, $after]) {
            $first = $this->buildNode(new LookHir(new ClassHir($word), $before, true, $node->startPosition, $node->endPosition));
            $second = $this->buildNode(new LookHir(new ClassHir($word), $after, true, $node->startPosition, $node->endPosition));
            $pair = $this->concatenate($first, $second);
            $this->builder->addEpsilon($start, $pair->startState);
            foreach ($pair->acceptStates as $state) {
                $this->builder->addEpsilon($state, $end);
            }
        }

        return new NfaFragment($start, [$end]);
    }

    private static function isWordBoundary(AssertionHir $node): bool
    {
        return null !== $node->wordSet && (AssertionKind::WordBoundary === $node->kind || AssertionKind::NotWordBoundary === $node->kind);
    }

    private function buildConcat(ConcatHir $node): NfaFragment
    {
        if ([] === $node->parts) {
            return $this->epsilonFragment();
        }

        $current = $this->buildNode($node->parts[0]);
        foreach (\array_slice($node->parts, 1) as $part) {
            $current = $this->concatenate($current, $this->buildNode($part));
        }

        return $current;
    }

    private function buildAlternation(AlternationHir $node): NfaFragment
    {
        $start = $this->builder->createState();
        $end = $this->builder->createState();

        foreach ($node->branches as $branch) {
            $fragment = $this->buildNode($branch);
            $this->builder->addEpsilon($start, $fragment->startState);
            foreach ($fragment->acceptStates as $acceptState) {
                $this->builder->addEpsilon($acceptState, $end);
            }
        }

        return new NfaFragment($start, [$end]);
    }

    private function buildRepetition(RepetitionHir $node): NfaFragment
    {
        $min = $node->min;
        $max = $node->max;

        if (0 === $min && 0 === $max) {
            return $this->epsilonFragment();
        }

        if (null === $max) {
            if (0 === $min) {
                return $this->buildStar($node->body);
            }

            return $this->concatenate(
                $this->repeatNode($node->body, $min),
                $this->buildStar($node->body),
            );
        }

        return $this->buildBoundedRepeat($node->body, $min, $max);
    }

    private function buildClass(CharSet $set): NfaFragment
    {
        $start = $this->builder->createState();
        $end = $this->builder->createState();
        $this->builder->addTransition($start, $set, $end);

        return new NfaFragment($start, [$end]);
    }

    private function buildLiteral(LiteralHir $node): NfaFragment
    {
        if ([] === $node->codePoints) {
            return $this->epsilonFragment();
        }

        $start = $this->builder->createState();
        $current = $start;
        foreach ($node->codePoints as $codePoint) {
            $next = $this->builder->createState();
            $this->builder->addTransition($current, CharSet::single($codePoint), $next);
            $current = $next;
        }

        return new NfaFragment($start, [$current]);
    }

    private function buildStar(Hir $node): NfaFragment
    {
        $start = $this->builder->createState();
        $end = $this->builder->createState();
        $fragment = $this->buildNode($node);

        $this->builder->addEpsilon($start, $end);
        $this->builder->addEpsilon($start, $fragment->startState);
        foreach ($fragment->acceptStates as $acceptState) {
            $this->builder->addEpsilon($acceptState, $fragment->startState);
            $this->builder->addEpsilon($acceptState, $end);
        }

        return new NfaFragment($start, [$end]);
    }

    private function repeatNode(Hir $node, int $count): NfaFragment
    {
        if (0 === $count) {
            return $this->epsilonFragment();
        }

        $fragment = $this->buildNode($node);
        for ($i = 1; $i < $count; $i++) {
            $fragment = $this->concatenate($fragment, $this->buildNode($node));
        }

        return $fragment;
    }

    private function buildBoundedRepeat(Hir $node, int $min, int $max): NfaFragment
    {
        if (0 === $max) {
            return $this->epsilonFragment();
        }

        if (0 === $min) {
            $start = $this->builder->createState();
            $acceptStates = [$start];
            $currentAccepts = [$start];

            for ($i = 1; $i <= $max; $i++) {
                $fragment = $this->buildNode($node);
                foreach ($currentAccepts as $acceptState) {
                    $this->builder->addEpsilon($acceptState, $fragment->startState);
                }

                $currentAccepts = $fragment->acceptStates;
                $acceptStates = \array_merge($acceptStates, $currentAccepts);
            }

            return new NfaFragment($start, \array_values(\array_unique($acceptStates)));
        }

        $fragment = $this->buildNode($node);
        $start = $fragment->startState;
        $acceptStates = [];
        $currentAccepts = $fragment->acceptStates;

        if (1 >= $min) {
            $acceptStates = \array_merge($acceptStates, $currentAccepts);
        }

        for ($i = 2; $i <= $max; $i++) {
            $next = $this->buildNode($node);
            foreach ($currentAccepts as $acceptState) {
                $this->builder->addEpsilon($acceptState, $next->startState);
            }

            $currentAccepts = $next->acceptStates;
            if ($i >= $min) {
                $acceptStates = \array_merge($acceptStates, $currentAccepts);
            }
        }

        return new NfaFragment($start, \array_values(\array_unique($acceptStates)));
    }

    private function concatenate(NfaFragment $first, NfaFragment $second): NfaFragment
    {
        foreach ($first->acceptStates as $acceptState) {
            $this->builder->addEpsilon($acceptState, $second->startState);
        }

        return new NfaFragment($first->startState, $second->acceptStates);
    }

    private function epsilonFragment(): NfaFragment
    {
        $state = $this->builder->createState();

        return new NfaFragment($state, [$state]);
    }

    /**
     * Where a search match may start and end: every alternative anchored at
     * its start, or none; at the end, every alternative ending on the same
     * anchor, or none. A mix says the pattern reads the subject differently
     * on each side of an alternative, which one automaton cannot express.
     *
     * @return array{0: bool, 1: AssertionKind|null} the end anchor, null when the match may end anywhere
     */
    private function partialAnchorsOf(Hir $hir): array
    {
        $branches = $hir instanceof AlternationHir ? $hir->branches : [$hir];

        $startAnchored = true;
        $end = null;

        foreach ($branches as $index => $branch) {
            $parts = $branch instanceof ConcatHir ? $branch->parts : [$branch];
            $first = $parts[0] ?? null;
            $last = $parts[\count($parts) - 1] ?? null;

            $branchStartsAnchored = $first instanceof AssertionHir && AssertionKind::SubjectStart === $first->kind;
            $branchEnd = $last instanceof AssertionHir && (AssertionKind::SubjectEnd === $last->kind || AssertionKind::EndOrFinalNewline === $last->kind)
                ? $last->kind
                : null;

            if ($index > 0 && $branchStartsAnchored !== $startAnchored) {
                throw new ComplexityException(
                    'Mixed start anchors across alternatives are not supported in partial match mode.',
                    0,
                    $this->pattern,
                );
            }

            if ($index > 0 && $branchEnd !== $end) {
                throw new ComplexityException(
                    'Mixed end anchors across alternatives are not supported in partial match mode.',
                    0,
                    $this->pattern,
                );
            }

            $startAnchored = $branchStartsAnchored;
            $end = $branchEnd;
        }

        return [$startAnchored, $end];
    }

    private function wrapPartialMatch(
        NfaFragment $fragment,
        bool $startAnchored,
        ?AssertionKind $end,
    ): NfaFragment {
        $charSet = CharSet::universe($this->unicode);

        $start = $fragment->startState;
        if (!$startAnchored) {
            $start = $this->builder->createState();
            $this->builder->addTransition($start, $charSet, $start);
            $this->builder->addEpsilon($start, $fragment->startState);
        }

        if (null === $end) {
            foreach ($fragment->acceptStates as $acceptState) {
                $this->builder->addTransition($acceptState, $charSet, $acceptState);
            }
        }

        // Without /D, "$" and "\Z" also match before a newline that ends the
        // subject: a search accepts that newline after the match.
        $acceptStates = $fragment->acceptStates;
        if (AssertionKind::EndOrFinalNewline === $end) {
            $newline = $this->builder->createState();
            foreach ($fragment->acceptStates as $acceptState) {
                $this->builder->addTransition($acceptState, CharSet::single(0x0A), $newline);
            }
            $acceptStates[] = $newline;
        }

        return new NfaFragment($start, $acceptStates);
    }
}
