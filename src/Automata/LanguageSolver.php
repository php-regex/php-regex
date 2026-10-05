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

namespace PHPRegex\Automata;

use PHPRegex\Automata\Builder\DfaBuilder;
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Match\MatchExplorer;
use PHPRegex\Automata\Match\PriorityNfa;
use PHPRegex\Automata\Match\PriorityNfaBuilder;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Automata\Solver\EquivalenceResult;
use PHPRegex\Automata\Solver\IntersectionResult;
use PHPRegex\Automata\Solver\MatchEquivalenceResult;
use PHPRegex\Automata\Solver\PrefixReader;
use PHPRegex\Automata\Solver\SubsetResult;
use PHPRegex\Automata\Transform\HirToNfaTransformer;
use PHPRegex\Automata\Transform\RegularSubsetValidator;
use PHPRegex\Automata\Unicode\CodePointHelper;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\RegexParser;

/**
 * Answers questions about the languages regexes match: whether two overlap,
 * whether one is contained in another, whether they match the same strings.
 *
 * Each pattern is compiled to a DFA, so only the regular subset is supported:
 * backreferences, lookarounds, recursion and the like throw a
 * ComplexityException instead of an answer that would be wrong.
 */
final readonly class LanguageSolver
{
    private const MAX_BYTE = 255;

    /**
     * The state a DFA falls into on a character it has no transition for:
     * it stays there and never accepts, so both DFAs are total over the
     * merged alphabet and a character only one side can take is still
     * observed.
     */
    private const DEAD = -1;

    /**
     * @param RegexParser|null       $parser   Reads the patterns, for its PHP and PCRE2 target; a default parser when null
     * @param DfaCacheInterface|null $dfaCache Keeps compiled DFAs between questions; nothing is kept when null
     */
    public function __construct(private ?RegexParser $parser = null, private ?DfaCacheInterface $dfaCache = null) {}

    /**
     * Whether some string matches both patterns, and the shortest such string.
     *
     * @throws ComplexityException When a pattern leaves the regular subset or a limit is reached
     */
    public function intersection(string $left, string $right, ?SolverOptions $options = null): IntersectionResult
    {
        $options ??= new SolverOptions();
        [$leftDfa, $rightDfa] = $this->buildDfas($left, $right, $options);

        $example = $this->findExample(
            $leftDfa,
            $rightDfa,
            static fn (bool $leftAccept, bool $rightAccept): bool => $leftAccept && $rightAccept,
        );

        return new IntersectionResult(null === $example, $example);
    }

    /**
     * Whether every string the left pattern matches is matched by the right one,
     * and the shortest string that is not.
     *
     * @throws ComplexityException When a pattern leaves the regular subset or a limit is reached
     */
    public function subsetOf(string $left, string $right, ?SolverOptions $options = null): SubsetResult
    {
        $options ??= new SolverOptions();
        [$leftDfa, $rightDfa] = $this->buildDfas($left, $right, $options);

        $example = $this->findExample(
            $leftDfa,
            $rightDfa,
            static fn (bool $leftAccept, bool $rightAccept): bool => $leftAccept && !$rightAccept,
        );

        return new SubsetResult(null === $example, $example);
    }

    /**
     * Whether both patterns match exactly the same strings, and the shortest
     * string only one side matches, for each side.
     *
     * @throws ComplexityException When a pattern leaves the regular subset or a limit is reached
     */
    public function equivalent(string $left, string $right, ?SolverOptions $options = null): EquivalenceResult
    {
        $options ??= new SolverOptions();
        [$leftDfa, $rightDfa] = $this->buildDfas($left, $right, $options);

        $leftOnlyExample = $this->findExample(
            $leftDfa,
            $rightDfa,
            static fn (bool $leftAccept, bool $rightAccept): bool => $leftAccept && !$rightAccept,
        );

        $rightOnlyExample = $this->findExample(
            $leftDfa,
            $rightDfa,
            static fn (bool $leftAccept, bool $rightAccept): bool => !$leftAccept && $rightAccept,
        );

        return new EquivalenceResult(null === $leftOnlyExample && null === $rightOnlyExample, $leftOnlyExample, $rightOnlyExample);
    }

    /**
     * Whether preg_match() writes the same $matches for both patterns on
     * every subject: the same answer, the same match and the same groups,
     * named alike. Stronger than equivalent(): /a|ab/ and /ab|a/ match the
     * same strings, yet on "ab" the first matches "a" and the second "ab",
     * as PCRE takes the first alternative that leads to a match. The
     * counter-example is the shortest subject the two treat differently.
     *
     * The match mode of the options does not apply: the question is what
     * preg_match() does.
     *
     * @throws ComplexityException When a pattern leaves the fragment the match solver reads, or a limit is reached
     */
    public function matchEquivalent(string $left, string $right, ?SolverOptions $options = null): MatchEquivalenceResult
    {
        $options ??= new SolverOptions();
        $leftNfa = $this->priorityNfa($left, $options);
        $rightNfa = $this->priorityNfa($right, $options);

        if ($leftNfa->unicode !== $rightNfa->unicode) {
            throw new ComplexityException('Both patterns must read the subject the same way, as UTF-8 or as bytes, for the match solver to compare them.', 0, $left);
        }

        $counterExample = (new MatchExplorer($leftNfa, $rightNfa, $options->maxDfaStates, $left))->counterExample();

        return new MatchEquivalenceResult(null === $counterExample, $counterExample, $this->parser()->target()->pcreVersion);
    }

    /**
     * Whether some string starting with the input is matched, under the match
     * mode of the options: a half-typed "2026" begins a date that
     * /^\d{4}-\d{2}-\d{2}$/ matches, where preg_match() can only say 0. In
     * UTF mode, an input ending in the middle of a character is viable when
     * one way to finish the character is.
     *
     * @throws ComplexityException When the pattern leaves the regular subset or a limit is reached
     */
    public function acceptsPrefix(string $pattern, string $input, ?SolverOptions $options = null): bool
    {
        $dfa = $this->buildDfa($pattern, $options ?? new SolverOptions());

        return PrefixReader::begins($dfa, $input, $this->parser()->parse($pattern)->isUnicode());
    }

    /**
     * The set of strings the pattern matches, under the match mode of the
     * options: whether it is finite, how many strings of each length it
     * holds, each of them, and the strings it rejects.
     *
     * @throws ComplexityException When the pattern leaves the regular subset or a limit is reached
     */
    public function language(string $pattern, ?SolverOptions $options = null): Language
    {
        return new Language($this->buildDfa($pattern, $options ?? new SolverOptions()), $this->parser()->parse($pattern)->isUnicode());
    }

    /**
     * Compiles a pattern to its DFA, and stores it in the cache when there is one,
     * so that later questions about the pattern reuse it.
     *
     * @throws ComplexityException When the pattern leaves the regular subset or a limit is reached
     */
    public function compile(string $pattern, ?SolverOptions $options = null): Dfa
    {
        return $this->buildDfa($pattern, $options ?? new SolverOptions());
    }

    /**
     * @throws ComplexityException
     */
    private function priorityNfa(string $pattern, SolverOptions $options): PriorityNfa
    {
        $ast = $this->parser()->parse($pattern);

        // These flags change nothing the tree does not already say.
        $unsupported = array_diff(str_split($ast->flags), ['i', 's', 'u', 'x', 'D', 'U', 'n', 'J', 'S', 'X']);
        if ([] !== array_filter($unsupported, static fn (string $flag): bool => '' !== $flag)) {
            throw new ComplexityException('Unsupported regex flags for the match solver: '.implode(', ', $unsupported).'.', 0, $pattern);
        }

        return (new PriorityNfaBuilder($options->maxNfaStates, $pattern))->build((new HirTranslator())->translate($ast), $ast->isUnicode());
    }

    private function parser(): RegexParser
    {
        return $this->parser ?? RegexParser::create();
    }

    /**
     * @throws ComplexityException
     *
     * @return array{0: Dfa, 1: Dfa}
     */
    private function buildDfas(string $left, string $right, SolverOptions $options): array
    {
        if ($left === $right) {
            $dfa = $this->buildDfa($left, $options);

            return [$dfa, $dfa];
        }

        return [
            $this->buildDfa($left, $options),
            $this->buildDfa($right, $options),
        ];
    }

    /**
     * @throws ComplexityException
     */
    private function buildDfa(string $pattern, SolverOptions $options): Dfa
    {
        $cacheKey = null;
        if (null !== $this->dfaCache) {
            $cacheKey = $this->cacheKey($pattern, $options);
            $cached = $this->dfaCache->get($cacheKey);
            if (null !== $cached) {
                return $cached;
            }
        }

        $ast = $this->parser()->parse($pattern);
        $hir = (new RegularSubsetValidator())->assertSupported($ast, $pattern, $options);

        $transformer = new HirToNfaTransformer($pattern, HirTranslator::unicodeOf($ast));
        $nfa = $transformer->transform($hir, $options);

        $dfa = (new DfaBuilder())->determinize($nfa, $options);

        if (null !== $this->dfaCache && null !== $cacheKey) {
            $this->dfaCache->set($cacheKey, $dfa);
        }

        return $dfa;
    }

    private function cacheKey(string $pattern, SolverOptions $options): string
    {
        // The PHP and PCRE2 judged decide what the pattern means: "{,2}"
        // repeats from PCRE2 10.43 and is text before. The version of the
        // code that reads it keeps a persistent cache from answering with a
        // DFA an older reading built.
        $parts = [
            $pattern,
            RegexParser::CACHE_VERSION,
            $this->parser()->target()->cacheKey(),
            $options->matchMode->value,
            $options->maxNfaStates,
            $options->maxDfaStates,
            $options->minimizeDfa ? '1' : '0',
            $options->minimizationAlgorithm->value,
            $options->determinizationAlgorithm->value,
            $options->maxTransitionsProcessed ?? 'null',
        ];

        return \hash('sha256', \implode('|', $parts));
    }

    /**
     * @param callable(bool, bool): bool $acceptPredicate
     */
    private function findExample(Dfa $left, Dfa $right, callable $acceptPredicate): ?string
    {
        $startLeft = $left->startState;
        $startRight = $right->startState;
        $rightStateCount = \count($right->states);
        $startKey = $this->pairKey($startLeft, $startRight, $rightStateCount);
        $alphabetRanges = $this->mergeAlphabetRanges($left, $right);

        // Both alphabets the bytes: a witness is a byte, not the UTF-8 of
        // its value; with a code point alphabet anywhere it is a character.
        $byteExample = $left->maxCodePoint <= self::MAX_BYTE && $right->maxCodePoint <= self::MAX_BYTE;

        if ($acceptPredicate($left->getState($startLeft)->isAccepting, $right->getState($startRight)->isAccepting)) {
            return '';
        }

        /** @var \SplQueue<array{int, int, int}> $queue */
        $queue = new \SplQueue();
        $queue->enqueue([$startLeft, $startRight, $startKey]);

        /** @var array<int, bool> $visited */
        $visited = [$startKey => true];
        /** @var array<int, array{0:int, 1:int}|null> $previous */
        $previous = [$startKey => null];

        while (!$queue->isEmpty()) {
            $item = $queue->dequeue();
            [$leftStateId, $rightStateId, $currentKey] = $item;
            $leftState = self::DEAD === $leftStateId ? null : $left->getState($leftStateId);
            $rightState = self::DEAD === $rightStateId ? null : $right->getState($rightStateId);

            foreach ($alphabetRanges as [$start]) {
                $symbol = $start;
                $nextLeft = null === $leftState ? self::DEAD : $leftState->transitionFor($symbol);
                $nextLeft ??= self::DEAD;
                $nextRight = null === $rightState ? self::DEAD : $rightState->transitionFor($symbol);
                $nextRight ??= self::DEAD;

                $nextKey = $this->pairKey($nextLeft, $nextRight, $rightStateCount);

                if (isset($visited[$nextKey])) {
                    continue;
                }

                $visited[$nextKey] = true;
                $previous[$nextKey] = [$currentKey, $symbol];

                $nextLeftState = self::DEAD === $nextLeft ? null : $left->getState($nextLeft);
                $nextRightState = self::DEAD === $nextRight ? null : $right->getState($nextRight);
                if ($acceptPredicate(null !== $nextLeftState && $nextLeftState->isAccepting, null !== $nextRightState && $nextRightState->isAccepting)) {
                    return $this->buildExample($nextKey, $previous, $byteExample);
                }

                $queue->enqueue([$nextLeft, $nextRight, $nextKey]);
            }
        }

        return null;
    }

    /**
     * One number per pair of states, the dead one counted in: it never
     * collides, the way a plain product would.
     */
    private function pairKey(int $left, int $right, int $rightStateCount): int
    {
        return ($left + 1) * ($rightStateCount + 1) + ($right + 1);
    }

    /**
     * @param array<int, array{0:int, 1:int}|null> $previous
     */
    private function buildExample(int $key, array $previous, bool $byteExample): string
    {
        $chars = [];
        $current = $key;
        while (null !== $previous[$current]) {
            [$prevKey, $char] = $previous[$current];
            $chars[] = $byteExample ? \chr($char) : (CodePointHelper::toString($char) ?? '');
            $current = $prevKey;
        }

        if ([] === $chars) {
            return '';
        }

        return implode('', \array_reverse($chars));
    }

    /**
     * @return array<int, array{0:int, 1:int}>
     */
    private function mergeAlphabetRanges(Dfa $left, Dfa $right): array
    {
        $min = \min($left->minCodePoint, $right->minCodePoint);
        $max = \max($left->maxCodePoint, $right->maxCodePoint);

        $boundaries = [
            $min => true,
            $max + 1 => true,
        ];

        foreach ([$left, $right] as $dfa) {
            $ranges = $dfa->alphabetRanges;
            if ([] === $ranges) {
                $ranges = [[$dfa->minCodePoint, $dfa->maxCodePoint]];
            }

            foreach ($ranges as [$start, $end]) {
                $boundaries[$start] = true;
                if ($end + 1 <= $max + 1) {
                    $boundaries[$end + 1] = true;
                }
            }
        }

        /** @var array<int> $points */
        $points = \array_keys($boundaries);
        \sort($points, \SORT_NUMERIC);

        $ranges = [];
        $count = \count($points);
        for ($i = 0; $i < $count - 1; $i++) {
            $start = $points[$i];
            $end = $points[$i + 1] - 1;

            if ($start > $max) {
                break;
            }

            $end = \min($end, $max);
            if ($start > $end) {
                continue;
            }

            // The Unicode alphabet has a hole where the surrogates would
            // be: a partition range may fall inside it, or start there and
            // run past it, and the probe of a range must name a character
            // a real subject can hold.
            if ($start >= 0xD800 && $end <= 0xDFFF) {
                continue;
            }
            if ($start >= 0xD800 && $start <= 0xDFFF) {
                $start = 0xE000;
            }

            $ranges[] = [$start, $end];
        }

        if ([] === $ranges) {
            $ranges[] = [$min, $max];
        }

        return $ranges;
    }
}
