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

namespace PHPRegex\Redos\Internal\Backtrack;

use PHPRegex\Parser\Analysis\LiteralExtractor;
use PHPRegex\Parser\Analysis\LiteralSet;
use PHPRegex\Parser\Hir\Utf8;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Redos\RedosComplexity;

/**
 * The complexity class of one match attempt of a backtracking engine that
 * follows PCRE's order, after Weideman et al., "Analyzing Matching Time
 * Behavior of Backtracking Regular Expression Matchers by Using Ambiguous
 * NFA" (2016).
 *
 * Exponential: a state with two distinct paths back to itself on the same
 * word (EDA), found on the product of the automaton with itself.
 * Polynomial of degree k: a chain of k states, each with a loop on a word
 * and a path to the next one on that word (IDA), found on the product of
 * three. Either counts only with a witness: a prefix reaching the state
 * before any success of a path tried earlier, then the pumped word, then a
 * suffix no path accepts. Without one, every continuation succeeds at once
 * and nothing backtracks.
 *
 * @internal
 */
final class BacktrackProver
{
    private ?PnfaBuilder $builder = null;

    private ?int $stepBound = null;

    /**
     * @var array{ItemAutomaton, Budget, list<string>}|null
     */
    private ?array $search = null;

    public function __construct(
        private readonly int $maxStates,
        private readonly int $maxSteps,
        private readonly int $boundedRepeatCutoff,
    ) {}

    /**
     * @throws ModelLimit
     */
    public function prove(RegexNode $regex): ProofResult
    {
        $budget = new Budget($this->maxSteps, $this->maxStates);
        $this->stepBound = null;
        $this->search = null;
        $builder = $this->builder = new PnfaBuilder($regex, $budget, $this->boundedRepeatCutoff);
        $searches = $builder->build();

        // A search's automaton is kept only while a lookaround of it is still
        // to analyse: the others are released as soon as they are read, but
        // the pattern's own, which search() hands to the search-cost proof.
        $parents = [];
        foreach ($searches as $pnfa) {
            if (null !== $pnfa->parent) {
                $parents[$pnfa->parent] = true;
            }
        }

        /** @var array<int, ItemAutomaton> $automata */
        $automata = [];
        /** @var array<int, list<int>|null> $reach */
        $reach = [];
        $best = null;
        // A lookaround runs within the attempt that meets it: the bounds of
        // the searches add up, a ceiling for their product.
        $stepBound = 0;
        foreach ($searches as $index => $pnfa) {
            if (null !== $pnfa->parent && null !== $pnfa->mark) {
                // A lookaround's body starts where its mark stands, after the
                // character the outer search read last.
                $parent = $automata[$pnfa->parent];
                $contexts = array_keys($parent->markContexts[$pnfa->mark] ?? [ItemAutomaton::CONTEXT_OTHER => true]);
                $before = $reach[$pnfa->parent] ?? null;
                $to = $this->pathToMark($parent, $pnfa->mark, $budget);
                $reach[$index] = null === $before || null === $to ? null : [...$before, ...$to];
            } else {
                // The attempt at the subject's start, and when the pattern
                // looks at the character before, the later attempts of an
                // unanchored search after each kind of character.
                $contexts = $pnfa->looksBehindStart
                    ? [ItemAutomaton::CONTEXT_START, ItemAutomaton::CONTEXT_WORD, ItemAutomaton::CONTEXT_NEWLINE, ItemAutomaton::CONTEXT_OTHER]
                    : [ItemAutomaton::CONTEXT_START];
                $reach[$index] = [];
            }

            $automaton = new ItemAutomaton($pnfa, $budget, $contexts);
            if (isset($parents[$index])) {
                $automata[$index] = $automaton;
            }
            $stepBound = null === $stepBound ? null : self::boundOf($automaton, $stepBound);
            $literals = self::requiredLiterals(null === $pnfa->parent ? $regex->pattern : $pnfa->body, $pnfa->unicode);
            $verdict = (new AmbiguityFinder($automaton, $budget))->find($literals);
            if (null === $pnfa->parent) {
                $this->search = [$automaton, $budget, self::mandatoryRuns($regex->pattern, $pnfa->unicode)];
            }
            unset($automaton);
            if (null === $verdict || null === $reach[$index]) {
                continue;
            }

            $verdict['prefix'] = [...$reach[$index], ...$verdict['prefix']];
            if (null === $best || self::better($verdict, $best)) {
                $best = $verdict;
            }
        }

        // Known before any verdict is refused: a ceiling needs no witness.
        $this->stepBound = $stepBound;

        if (null !== $best && null !== $best['unwitnessed']) {
            throw ModelLimit::unwitnessed($best['unwitnessed']);
        }

        // With a set read as any character, only an exponential pump through
        // exact sets is proven: a larger set can hide the rejecting suffix.
        if ($builder->hasApproximatedSets() && (null === $best || RedosComplexity::Exponential !== $best['complexity'] || $best['approximated'])) {
            throw ModelLimit::approximated();
        }

        $unicode = str_contains($regex->flags, 'u');
        if (null === $best) {
            return new ProofResult(RedosComplexity::Linear, null, null, null, null, [], $unicode, $builder->abstractions());
        }

        if ($best['approximated']) {
            throw ModelLimit::approximated();
        }

        $others = [];
        foreach ([$best['suffix'], ...$best['alternatives']] as $suffix) {
            if ($suffix !== $best['published']) {
                $others[] = Utf8::encode($suffix, $unicode);
            }
        }

        return new ProofResult(
            $best['complexity'],
            $best['degree'],
            Utf8::encode($best['prefix'], $unicode),
            Utf8::encode($best['pump'], $unicode),
            Utf8::encode($best['published'], $unicode),
            array_values(array_unique($others)),
            $unicode,
            $builder->abstractions(),
            $best['withoutMatches'],
        );
    }

    /**
     * An upper bound on the steps of one match attempt, as the degree d of
     * n^d, from the last proof: null when an exponential ambiguity may exist,
     * when the pattern left the model, or when the bound outgrew its budget.
     */
    public function stepBound(): ?int
    {
        return $this->stepBound;
    }

    /**
     * The automaton of the pattern's own search from the last proof, the
     * budget it shares, and the runs of literal characters every match
     * reads, in order; null before a proof finished reading it.
     *
     * @return array{ItemAutomaton, Budget, list<string>}|null
     */
    public function search(): ?array
    {
        return $this->search;
    }

    /**
     * The bounded repeats read as unbounded so far: all of them after a
     * proof, the ones met before the budget ran out otherwise.
     *
     * @return list<string>
     */
    public function abstractions(): array
    {
        return $this->builder?->abstractions() ?? [];
    }

    /**
     * The searches' bound so far plus this automaton's, on a budget of its
     * own so that it takes nothing from the proof; null for an exponential
     * ambiguity or a budget run out.
     */
    private function boundOf(ItemAutomaton $automaton, int $sofar): ?int
    {
        try {
            $bound = (new AmbiguityFinder($automaton, new Budget($this->maxSteps, $this->maxStates)))->stepBound();
        } catch (ModelLimit) {
            return null;
        }

        return null === $bound ? null : $sofar + $bound;
    }

    /**
     * Whether a verdict outranks another: a higher class or degree; at a tie,
     * a witnessed exact pump over an approximated or unwitnessed one.
     *
     * @param array{complexity: RedosComplexity, degree: int|null, approximated: bool, unwitnessed: int|null, ...} $verdict
     * @param array{complexity: RedosComplexity, degree: int|null, approximated: bool, unwitnessed: int|null, ...} $than
     */
    private static function better(array $verdict, array $than): bool
    {
        if (self::outranks($verdict['complexity'], $verdict['degree'], $than['complexity'], $than['degree'])) {
            return true;
        }

        $tie = $verdict['complexity'] === $than['complexity'] && $verdict['degree'] === $than['degree'];
        $exact = !$verdict['approximated'] && null === $verdict['unwitnessed'];
        $thanExact = !$than['approximated'] && null === $than['unwitnessed'];

        return $tie && $exact && !$thanExact;
    }

    /**
     * Literals every match of the search holds, the one it ends with first,
     * then the runs of literals the search must read, from the last one,
     * found down through mandatory groups and repeats, atomic and possessive
     * ones included: PCRE gives up at once on a subject without a literal it
     * requires, so a replayed witness needs it.
     *
     * @return list<list<int>>
     */
    private static function requiredLiterals(NodeInterface $search, bool $unicode): array
    {
        $literals = [];
        $whole = $search->accept(new LiteralExtractor());
        if ($whole instanceof LiteralSet) {
            foreach ($whole->suffixes as $suffix) {
                if ('' !== $suffix) {
                    $literals[] = $suffix;

                    break;
                }
            }
        }

        foreach (array_reverse(self::mandatoryRuns($search, $unicode)) as $literal) {
            if (!\in_array($literal, $literals, true)) {
                $literals[] = $literal;
            }
        }

        $decoded = [];
        foreach ($literals as $literal) {
            $characters = Utf8::decode($literal, $unicode);
            if (null !== $characters && [] !== $characters) {
                $decoded[] = $characters;
            }
        }

        return $decoded;
    }

    /**
     * The runs of literal characters every match of the node reads, in
     * order.
     *
     * @return list<string>
     */
    private static function mandatoryRuns(NodeInterface $node, bool $unicode): array
    {
        $single = self::literalOf($node, $unicode);
        if (null !== $single) {
            return [$single];
        }

        if ($node instanceof GroupNode) {
            return \in_array($node->type, [GroupType::Capturing, GroupType::NonCapturing, GroupType::Named, GroupType::Atomic, GroupType::InlineFlags], true)
                ? self::mandatoryRuns($node->child, $unicode)
                : [];
        }

        if ($node instanceof QuantifierNode) {
            return (QuantifierBounds::parse($node->quantifier)->min ?? 0) >= 1 ? self::mandatoryRuns($node->node, $unicode) : [];
        }

        if (!$node instanceof SequenceNode) {
            $set = $node->accept(new LiteralExtractor());

            return $set instanceof LiteralSet && '' !== self::commonPrefix($set->prefixes) ? [self::commonPrefix($set->prefixes)] : [];
        }

        $runs = [];
        $run = '';
        foreach ($node->children as $child) {
            $literal = self::literalOf($child, $unicode);
            if (null !== $literal) {
                $run .= $literal;

                continue;
            }

            if ('' !== $run) {
                $runs[] = $run;
                $run = '';
            }

            array_push($runs, ...self::mandatoryRuns($child, $unicode));
        }

        if ('' !== $run) {
            $runs[] = $run;
        }

        return $runs;
    }

    /**
     * The literal a node is, written plainly or as one escaped character.
     */
    private static function literalOf(NodeInterface $node, bool $unicode): ?string
    {
        if ($node instanceof LiteralNode) {
            return '' === $node->value ? null : $node->value;
        }

        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return Utf8::character($node->codePoint, $unicode);
        }

        return null;
    }

    /**
     * @param array<string> $prefixes
     */
    private static function commonPrefix(array $prefixes): string
    {
        $common = null;
        foreach ($prefixes as $prefix) {
            if (null === $common) {
                $common = $prefix;

                continue;
            }

            $length = 0;
            $limit = min(\strlen($common), \strlen($prefix));
            while ($length < $limit && $common[$length] === $prefix[$length]) {
                $length++;
            }

            $common = substr($common, 0, $length);
        }

        return $common ?? '';
    }

    private static function outranks(RedosComplexity $complexity, ?int $degree, RedosComplexity $than, ?int $thanDegree): bool
    {
        if ($complexity === $than) {
            return ($degree ?? 0) > ($thanDegree ?? 0);
        }

        return RedosComplexity::Exponential === $complexity;
    }

    /**
     * The shortest input after which the search passes the mark of a
     * sub-search.
     *
     * @return list<int>|null
     */
    private function pathToMark(ItemAutomaton $automaton, int $mark, Budget $budget): ?array
    {
        if (\in_array($mark, $automaton->initials[0]['marks'], true)) {
            return [];
        }

        $paths = [];
        $queue = [];
        foreach ($automaton->initials[0]['items'] as $item) {
            if (!$automaton->isFinal($item) && !isset($paths[$item])) {
                $paths[$item] = [];
                $queue[] = $item;
            }
        }

        for ($head = 0; $head < \count($queue); $head++) {
            $budget->step();
            $item = $queue[$head];
            $path = [...$paths[$item], $automaton->representatives[ItemAutomaton::first($automaton->masks[$item])]];
            if (\in_array($mark, $automaton->marksAfter[$item], true)) {
                return $path;
            }

            foreach ($automaton->successors[$item] as $successor) {
                if (!$automaton->isFinal($successor) && !isset($paths[$successor])) {
                    $paths[$successor] = $path;
                    $queue[] = $successor;
                }
            }
        }

        return null;
    }
}
