<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Automata\Solver;

use RegexParser\Automata\Builder\DfaBuilder;
use RegexParser\Automata\Model\Dfa;
use RegexParser\Automata\Options\SolverOptions;
use RegexParser\Automata\Transform\AstToNfaTransformer;
use RegexParser\Automata\Transform\RegularSubsetValidator;
use RegexParser\Automata\Unicode\CodePointHelper;
use RegexParser\Exception\ComplexityException;
use RegexParser\Regex;

/**
 * Automata-based solver for regex set operations.
 */
final readonly class RegexSolver implements RegexSolverCompilerInterface, RegexSolverInterface
{
    public function __construct(
        private ?Regex $regex = null,
        private ?RegularSubsetValidator $validator = null,
        private ?DfaBuilder $dfaBuilder = null,
        private ?DfaCacheInterface $dfaCache = null,
    ) {}

    /**
     * @throws ComplexityException
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
     * @throws ComplexityException
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
     * @throws ComplexityException
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
     * @throws ComplexityException
     */
    public function compile(string $pattern, ?SolverOptions $options = null): Dfa
    {
        $options ??= new SolverOptions();

        return $this->buildDfa($pattern, $options);
    }

    private function parser(): Regex
    {
        return $this->regex ?? Regex::create();
    }

    /**
     * @throws ComplexityException
     *
     * @return array{0:Dfa, 1:Dfa}
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
        $validator = $this->validator ?? new RegularSubsetValidator();
        $validator->assertSupported($ast, $pattern, $options);

        $transformer = new AstToNfaTransformer($pattern);
        $nfa = $transformer->transform($ast, $options);

        $dfaBuilder = $this->dfaBuilder ?? new DfaBuilder();

        $dfa = $dfaBuilder->determinize($nfa, $options);

        if (null !== $this->dfaCache && null !== $cacheKey) {
            $this->dfaCache->set($cacheKey, $dfa);
        }

        return $dfa;
    }

    private function cacheKey(string $pattern, SolverOptions $options): string
    {
        // The PHP and PCRE2 judged decide what the pattern means: "{,2}"
        // repeats from PCRE2 10.43 and is text before.
        $parts = [
            $pattern,
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
        $startKey = $startLeft * $rightStateCount + $startRight;
        $alphabetRanges = $this->mergeAlphabetRanges($left, $right);

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
            $leftState = $left->getState($leftStateId);
            $rightState = $right->getState($rightStateId);

            foreach ($alphabetRanges as [$start]) {
                $symbol = $start;
                $nextLeft = $leftState->transitionFor($symbol);
                $nextRight = $rightState->transitionFor($symbol);

                if (null === $nextLeft || null === $nextRight) {
                    continue;
                }

                $nextKey = $nextLeft * $rightStateCount + $nextRight;

                if (isset($visited[$nextKey])) {
                    continue;
                }

                $visited[$nextKey] = true;
                $previous[$nextKey] = [$currentKey, $symbol];

                $nextLeftState = $left->getState($nextLeft);
                $nextRightState = $right->getState($nextRight);
                if ($acceptPredicate($nextLeftState->isAccepting, $nextRightState->isAccepting)) {
                    return $this->buildExample($nextKey, $previous);
                }

                $queue->enqueue([$nextLeft, $nextRight, $nextKey]);
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0:int, 1:int}|null> $previous
     */
    private function buildExample(int $key, array $previous): string
    {
        $chars = [];
        $current = $key;
        while (null !== $previous[$current]) {
            [$prevKey, $char] = $previous[$current];
            $chars[] = CodePointHelper::toString($char) ?? '';
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

            $ranges[] = [$start, \min($end, $max)];
        }

        if ([] === $ranges) {
            $ranges[] = [$min, $max];
        }

        return $ranges;
    }
}
