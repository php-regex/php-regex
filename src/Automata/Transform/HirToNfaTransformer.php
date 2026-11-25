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
use PHPRegex\Automata\Support\WorkBudget;
use PHPRegex\Parser\Analysis\AtomicInertiaAnalyzer;
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

    public const ASSERTION_MESSAGE = '\K and \G read where a match starts or stood before, which the automata solver cannot read as a pure language.';

    public const POSSESSIVE_MESSAGE = 'Possessive quantifiers never give back what they matched, which is ordered behaviour the solver cannot read as a pure language.';

    public const SURROGATE_MESSAGE = 'PCRE refuses any pattern that names a surrogate code point, which the automata solver cannot read as a pure language.';

    public const ZERO_REPEAT_ASSERTION_MESSAGE = 'An anchor, an assertion or a lookaround in a group repeated no time still moves where PCRE2 tries a match, which the automata solver does not read.';

    public const SKIPPED_GROUP_MESSAGE = 'PCRE2 makes a character repeated before a possessive or atomic group that may match nothing possessive, as if the group could not be skipped, which the automata solver does not read.';

    public const LOCALE_TABLES_MESSAGE = 'Without "u", PHP hands PCRE2 the character tables of the locale a program sets: a possessive quantifier or an atomic group proven on a byte past ASCII beside a class holds under the tables of C alone, and the target names no other locale.';

    private const MIN_CODEPOINT = 0;

    private const MAX_CODEPOINT = 255;

    private const UNICODE_MAX_CODEPOINT = 0x10FFFF;

    private const SURROGATE_FIRST = 0xD800;

    private const SURROGATE_LAST = 0xDFFF;

    private NfaBuilder $builder;

    /**
     * The possessive repetitions and atomic groups an enclosing sequence
     * has proven inert, by object id, for the refusal walk being run.
     *
     * @var array<int, true>
     */
    private array $vouched = [];

    private readonly AtomicInertiaAnalyzer $inertia;

    private bool $insideLookaround = false;

    /**
     * @var array<int, LookHir> the mark state of each lookaround
     */
    private array $marks = [];

    /**
     * The anchors at the edges of the pattern, by object id: they hold by
     * where the match starts and ends, and compile to nothing.
     *
     * @var array<int, true>
     */
    private array $edges = [];

    /**
     * The tree the refusal walk being run reads.
     */
    private ?Hir $root = null;

    /**
     * @param LocaleBoundClasses|null $localeBound the classes whose characters past ASCII the tables
     *                                             of an unknown locale decide; null when the tables
     *                                             read are the ones the pattern runs under
     */
    public function __construct(
        private readonly string $pattern,
        private readonly bool $unicode,
        private readonly ?LocaleBoundClasses $localeBound = null
    ) {
        $this->inertia = new AtomicInertiaAnalyzer();
    }

    /**
     * @throws ComplexityException When the tree leaves the regular subset or a limit is reached
     */
    public function transform(Hir $hir, SolverOptions $options): Nfa
    {
        $this->assertTranslatable($hir, $options);

        $alphabetMax = $this->unicode ? self::UNICODE_MAX_CODEPOINT : self::MAX_CODEPOINT;
        $this->builder = new NfaBuilder($options->maxNfaStates, self::MIN_CODEPOINT, $alphabetMax);

        $this->marks = [];
        [$startAnchored, $end, $this->edges] = $this->edgesOf($hir, $options->matchMode);
        $fragment = $this->buildNode($hir);
        if (MatchMode::Partial === $options->matchMode) {
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
     * Each state built is charged to the budget given.
     *
     * @throws ComplexityException
     */
    public function lookaroundBody(LookHir $look, SolverOptions $options, ?WorkBudget $budget = null): Nfa
    {
        $alphabetMax = $this->unicode ? self::UNICODE_MAX_CODEPOINT : self::MAX_CODEPOINT;
        $this->builder = new NfaBuilder($options->maxNfaStates, self::MIN_CODEPOINT, $alphabetMax, $budget);
        $this->edges = [];
        $fragment = $this->buildNode($look->body);
        $behind = LookKind::Behind === $look->kind || LookKind::NegativeBehind === $look->kind;

        return $this->builder->build($behind ? $this->wrapPartialMatch($fragment, false, AssertionKind::SubjectEnd) : $fragment);
    }

    /**
     * Whether the tree reads as a pure language, refused with one message
     * per reason when it does not: nothing is built.
     *
     * @throws ComplexityException
     *
     * @return bool whether a possessive quantifier or an atomic group was
     *              proven to read as its greedy, plain spelling
     */
    public function assertTranslatable(Hir $hir, SolverOptions $options): bool
    {
        $this->vouched = [];
        $this->root = $hir;
        $this->assertSequence([$hir], $options, MatchMode::Full === $options->matchMode ? CharSet::empty() : null);

        return [] !== $this->vouched;
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
                $this->assertSequence([$node->body], new SolverOptions(matchMode: MatchMode::Full, maxNfaStates: $options->maxNfaStates, maxDfaStates: $options->maxDfaStates), $this->inertia->lookaroundBodyFollow($node));
            } finally {
                $this->insideLookaround = false;
            }

            return;
        }

        // Proven inert, an atomic group reads as its body, below.
        if ($node instanceof AtomicHir && !($this->vouched[spl_object_id($node)] ?? false)) {
            throw new ComplexityException(self::ATOMIC_MESSAGE, $node->startPosition, $this->pattern);
        }

        if ($node instanceof AssertionHir) {
            if ($this->insideLookaround) {
                throw new ComplexityException(self::LOOKAROUND_ANCHOR_MESSAGE, $node->startPosition, $this->pattern);
            }

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
            if (Greed::Possessive === $node->greed && !($this->vouched[spl_object_id($node)] ?? false)) {
                throw new ComplexityException(self::POSSESSIVE_MESSAGE, $node->startPosition, $this->pattern);
            }

            // PCRE2 reads a group repeated no time to choose where it tries
            // a match: "/(?:x|(?=a)){0}\w/" finds no "b" in "\tb".
            $assertion = 0 === $node->max ? self::assertionIn($node->body) : null;
            if (null !== $assertion) {
                throw new ComplexityException(self::ZERO_REPEAT_ASSERTION_MESSAGE, $assertion->startPosition, $this->pattern);
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
     * A sequence: each part that ends in a possessive quantifier or an
     * atomic group is judged against what follows it — the rest of the
     * sequence and, at its end, whatever follows the sequence itself.
     *
     * @throws ComplexityException
     */
    private function assertConcat(ConcatHir $node, SolverOptions $options, ?CharSet $follow): void
    {
        $this->assertSequence($node->parts, $options, $follow);
    }

    /**
     * @param list<Hir> $parts
     *
     * @throws ComplexityException
     */
    private function assertSequence(array $parts, SolverOptions $options, ?CharSet $follow): void
    {
        $follows = $this->inertia->followSets($parts, $follow);
        $commitments = $this->inertia->commitmentsFrom($parts);
        foreach ($parts as $index => $part) {
            $partFollow = $follows[$index];
            $commitment = $this->inertia->commitmentAtEnd($part);
            if (null !== $commitment) {
                $this->assertCommitmentIsSafe($commitment, $partFollow, $commitments[$index + 2] ?? false);
            }

            $this->assertNode($part, $options, $partFollow);
        }
    }

    /**
     * The anchors and word boundaries read as the lookarounds they stand
     * for; "\G" and "\K" read where a match starts, which no language says.
     *
     * @throws ComplexityException
     */
    private function assertAssertionKind(AssertionHir $node): void
    {
        if (AssertionKind::MatchStart !== $node->kind && AssertionKind::ResetMatchStart !== $node->kind
            && (null !== $node->wordSet || !self::isWordBoundaryKind($node->kind))) {
            return;
        }

        throw new ComplexityException(self::ASSERTION_MESSAGE, $node->startPosition, $this->pattern);
    }

    /**
     * A possessive quantifier or an atomic group reads the same language as
     * its backtracking spelling when nothing that follows it can consume
     * what it would have to give back (see AtomicInertiaAnalyzer). What
     * follows it is everything: the rest of its sequence and, past the
     * sequence's end, whatever follows the sequence itself. A follow that
     * cannot be worked out, or one a PARTIAL match may extend, refuses, and
     * so does a commitment with another one further along the sequence:
     * each proof reads what follows its own commitment, and the two do not
     * compose.
     *
     * @param bool $commitsBeyond whether another commitment occurs beyond the
     *                            part right after the one it ends
     *
     * @throws ComplexityException
     */
    private function assertCommitmentIsSafe(RepetitionHir|AtomicHir $commitment, ?CharSet $follow, bool $commitsBeyond): void
    {
        if (!$this->inertia->commitmentIsInert($commitment, $follow, $commitsBeyond)) {
            throw new ComplexityException($commitment instanceof AtomicHir ? self::ATOMIC_MESSAGE : self::POSSESSIVE_MESSAGE, $commitment->startPosition, $this->pattern);
        }

        // Without "u" a class takes the bytes past ASCII the tables of the
        // locale give it: under en_IE.ISO8859-1 "\w" takes "\xE9", and
        // "/^c\xE9++b?\w$/" no longer matches "c\xE9\xE9". A proof that
        // reads such a byte, against such a class in the commitment or in
        // what follows it, holds under the tables of C alone.
        if (null !== $this->localeBound && null !== $this->root && null !== $follow
            && (LocaleBoundClasses::pastAscii($follow) || LocaleBoundClasses::readsPastAscii($commitment))
            && ($this->localeBound->bindsSomeIn($commitment) || $this->localeBound->bindsSomeMeeting($this->root, $follow))) {
            throw new ComplexityException(self::LOCALE_TABLES_MESSAGE, $commitment->startPosition, $this->pattern);
        }

        $this->vouched[spl_object_id($commitment)] = true;
    }

    /**
     * The first anchor, assertion or lookaround under the node, or null.
     */
    private static function assertionIn(Hir $hir): ?Hir
    {
        $pending = [$hir];
        while (null !== $node = array_pop($pending)) {
            if ($node instanceof AssertionHir || $node instanceof LookHir) {
                return $node;
            }

            array_push($pending, ...$node->children());
        }

        return null;
    }

    private static function surrogates(): CharSet
    {
        return CharSet::range(self::SURROGATE_FIRST, self::SURROGATE_LAST);
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

        if ($node instanceof CaptureHir || $node instanceof AtomicHir) {
            // An atomic group reaching the build was proven inert by the
            // refusal walk: it reads as its body.
            return $this->buildNode($node->body);
        }

        if ($node instanceof AssertionHir && null !== $node->wordSet && self::isWordBoundary($node)) {
            return $this->buildWordBoundary($node, $node->wordSet);
        }

        if ($node instanceof AssertionHir && !isset($this->edges[spl_object_id($node)])) {
            return $this->buildNode($this->anchorAsLookaround($node));
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

    /**
     * An anchor away from the edges as the lookarounds it stands for, the
     * newline being "\n": "\A" with nothing before, "\z" with nothing
     * after, "$" and "\Z" before at most a final newline; under /m, "^"
     * at the start or after a newline more follows, "$" before a newline
     * or at the end.
     */
    private function anchorAsLookaround(AssertionHir $node): Hir
    {
        $any = new ClassHir(CharSet::universe($this->unicode));
        $newline = new LiteralHir([0x0A]);
        $look = static fn (Hir $body, LookKind $kind): LookHir => new LookHir($body, $kind, true, $node->startPosition, $node->endPosition);

        return match ($node->kind) {
            AssertionKind::SubjectStart => $look($any, LookKind::NegativeBehind),
            AssertionKind::SubjectEnd => $look($any, LookKind::NegativeAhead),
            AssertionKind::EndOrFinalNewline => $look(new AlternationHir([self::notNewline($any), new ConcatHir([$newline, $any])]), LookKind::NegativeAhead),
            AssertionKind::LineStart => new AlternationHir([
                $look($any, LookKind::NegativeBehind),
                new ConcatHir([$look($newline, LookKind::Behind), $look($any, LookKind::Ahead)]),
            ]),
            AssertionKind::LineEnd => $look(self::notNewline($any), LookKind::NegativeAhead),
            // The refusal walk lets no other kind through.
            default => new ConcatHir([]),
        };
    }

    private static function notNewline(ClassHir $any): ClassHir
    {
        return new ClassHir($any->set->subtract(CharSet::single(0x0A)));
    }

    private static function isWordBoundary(AssertionHir $node): bool
    {
        return null !== $node->wordSet && self::isWordBoundaryKind($node->kind);
    }

    private static function isWordBoundaryKind(AssertionKind $kind): bool
    {
        return AssertionKind::WordBoundary === $kind || AssertionKind::NotWordBoundary === $kind;
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
     * The anchors at the edges of the pattern, which hold by where the match
     * starts and ends: a full match spans the subject, so each alternative's
     * leading "\A" and trailing "\z", "$" or "\Z" hold; a search starts at
     * the subject's start when every alternative says so, and ends on the
     * same anchor in every alternative, or the anchors read where they
     * stand.
     *
     * @return array{0: bool, 1: AssertionKind|null, 2: array<int, true>} whether a search starts at the subject's start, the anchor it ends on, the edge anchors by object id
     */
    private function edgesOf(Hir $hir, MatchMode $mode): array
    {
        $starts = [];
        $ends = [];
        foreach ($hir instanceof AlternationHir ? $hir->branches : [$hir] as $branch) {
            $parts = $branch instanceof ConcatHir ? $branch->parts : [$branch];
            $first = $parts[0] ?? null;
            $last = $parts[\count($parts) - 1] ?? null;
            $starts[] = $first instanceof AssertionHir && AssertionKind::SubjectStart === $first->kind ? $first : null;
            $ends[] = $last instanceof AssertionHir && (AssertionKind::SubjectEnd === $last->kind || AssertionKind::EndOrFinalNewline === $last->kind) ? $last : null;
        }

        if (MatchMode::Full !== $mode) {
            if (\in_array(null, $starts, true)) {
                $starts = [];
            }

            $kinds = array_unique(array_map(static fn (?AssertionHir $end): string => $end?->kind->name ?? '', $ends));
            if (1 !== \count($kinds) || [''] === array_values($kinds)) {
                $ends = [];
            }
        }

        $edges = [];
        foreach ([...$starts, ...$ends] as $anchor) {
            if (null !== $anchor) {
                $edges[spl_object_id($anchor)] = true;
            }
        }

        return [[] !== $starts && !\in_array(null, $starts, true), [] === $ends ? null : $ends[0]?->kind, $edges];
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
