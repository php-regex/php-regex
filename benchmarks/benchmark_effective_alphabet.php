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

use PhpRegex\Automata\Builder\DfaBuilder;
use PhpRegex\Automata\Exception\ComplexityException;
use PhpRegex\Automata\Model\Dfa;
use PhpRegex\Automata\Model\DfaState;
use PhpRegex\Automata\Model\Nfa;
use PhpRegex\Automata\Options\MatchMode;
use PhpRegex\Automata\Options\SolverOptions;
use PhpRegex\Automata\Transform\AstToNfaTransformer;
use PhpRegex\Automata\Transform\RegularSubsetValidator;
use PhpRegex\Toolkit\Regex;

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once __DIR__.'/../vendor/autoload.php';

$patterns = [
    'Arabic block' => '/[\x{0600}-\x{06FF}]{2,}/u',
    'Emoji block' => '/[\x{1F600}-\x{1F64F}]{2,}/u',
    'Mixed blocks' => '/[\x{0600}-\x{06FF}\x{1F600}-\x{1F64F}]{2,}/u',
];

$options = new SolverOptions(matchMode: MatchMode::FULL, minimizeDfa: false);
$parser = Regex::create();
$validator = new RegularSubsetValidator();

echo "DFA Construction Benchmark (Effective Alphabet)\n";
echo "=============================================\n\n";

$rows = [];

foreach ($patterns as $label => $pattern) {
    $ast = $parser->parse($pattern);
    $validator->assertSupported($ast, $pattern, $options);

    $transformer = new AstToNfaTransformer($pattern);
    $nfa = $transformer->transform($ast, $options);

    $optimized = measure('Effective alphabet', static fn () => (new DfaBuilder())->determinize($nfa, $options));
    $naive = measure('Naive full alphabet', static fn () => determinizeNaive($nfa, $options));

    $rows[] = [$label, $optimized['label'], $optimized['time'], $optimized['memory'], $optimized['states']];
    $rows[] = [$label, $naive['label'], $naive['time'], $naive['memory'], $naive['states']];
}

printTable(['Pattern', 'Algorithm', 'Time (ms)', 'Memory (MB)', 'States'], $rows);

/**
 * @return array{label: string, time: string, memory: string, states: string}
 */
function measure(string $label, callable $callback): array
{
    $baselineMemory = memory_get_usage(true);
    $start = hrtime(true);
    $dfa = $callback();
    $elapsed = hrtime(true) - $start;
    $memory = memory_get_usage(true) - $baselineMemory;

    return [
        'label' => $label,
        'time' => \sprintf('%.2f', $elapsed / 1_000_000),
        'memory' => \sprintf('%.2f', $memory / 1024 / 1024),
        'states' => (string) \count($dfa->states),
    ];
}

/**
 * @throws \PhpRegex\Automata\Exception\ComplexityException
 */
function determinizeNaive(Nfa $nfa, SolverOptions $options): Dfa
{
    $startSet = epsilonClosure([$nfa->startState], $nfa);

    $stateMap = [];
    $stateSets = [];

    $queue = new SplQueue();

    $startKey = setKey($startSet);
    $stateMap[$startKey] = 0;
    $stateSets[0] = $startSet;
    $queue->enqueue(0);

    $transitions = [];
    $accepting = [];

    while (!$queue->isEmpty()) {
        $dfaId = $queue->dequeue();
        $currentSet = $stateSets[$dfaId];
        $accepting[$dfaId] = isAccepting($currentSet, $nfa);

        $stateTransitions = [];
        for ($char = $nfa->minCodePoint; $char <= $nfa->maxCodePoint; $char++) {
            $moveSet = move($currentSet, $char, $nfa);
            $targetSet = epsilonClosure($moveSet, $nfa);
            $targetKey = setKey($targetSet);

            if (!isset($stateMap[$targetKey])) {
                $newId = \count($stateMap);
                if ($newId >= $options->maxDfaStates) {
                    throw new ComplexityException(
                        \sprintf('DFA state limit exceeded (%d).', $options->maxDfaStates),
                    );
                }
                $stateMap[$targetKey] = $newId;
                $stateSets[$newId] = $targetSet;
                $queue->enqueue($newId);
            }

            $stateTransitions[$char] = $stateMap[$targetKey];
        }

        $transitions[$dfaId] = $stateTransitions;
    }

    $states = [];
    foreach ($transitions as $stateId => $stateTransitions) {
        $states[$stateId] = new DfaState($stateId, $stateTransitions, $accepting[$stateId] ?? false);
    }

    return new Dfa(0, $states, [], $nfa->minCodePoint, $nfa->maxCodePoint);
}

/**
 * @param array<int> $stateIds
 *
 * @return array<int>
 */
function epsilonClosure(array $stateIds, Nfa $nfa): array
{
    $queue = new SplQueue();
    $seen = [];

    foreach ($stateIds as $stateId) {
        $queue->enqueue($stateId);
        $seen[$stateId] = true;
    }

    while (!$queue->isEmpty()) {
        $stateId = $queue->dequeue();
        $state = $nfa->getState($stateId);
        foreach ($state->epsilonTransitions as $target) {
            if (!isset($seen[$target])) {
                $seen[$target] = true;
                $queue->enqueue($target);
            }
        }
    }

    $result = \array_keys($seen);
    \sort($result, \SORT_NUMERIC);

    return $result;
}

/**
 * @param array<int> $stateIds
 */
function isAccepting(array $stateIds, Nfa $nfa): bool
{
    foreach ($stateIds as $stateId) {
        if ($nfa->getState($stateId)->isAccepting) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<int> $stateIds
 *
 * @return array<int>
 */
function move(array $stateIds, int $char, Nfa $nfa): array
{
    $targets = [];
    foreach ($stateIds as $stateId) {
        $state = $nfa->getState($stateId);
        foreach ($state->transitions as $transition) {
            if ($transition->charSet->contains($char)) {
                $targets[$transition->target] = true;
            }
        }
    }

    $result = \array_keys($targets);
    \sort($result, \SORT_NUMERIC);

    return $result;
}

/**
 * @param array<int> $stateIds
 */
function setKey(array $stateIds): string
{
    if ([] === $stateIds) {
        return 'empty';
    }

    return \implode(',', $stateIds);
}

/**
 * @param array<int, string>             $headers
 * @param array<int, array<int, string>> $rows
 */
function printTable(array $headers, array $rows): void
{
    $widths = [];
    foreach ($headers as $index => $header) {
        $widths[$index] = \strlen($header);
    }

    foreach ($rows as $row) {
        foreach ($row as $index => $value) {
            $widths[$index] = max($widths[$index], \strlen($value));
        }
    }

    $separator = '+';
    foreach ($widths as $width) {
        $separator .= \str_repeat('-', $width + 2).'+';
    }

    echo $separator."\n";
    echo formatRow($headers, $widths);
    echo $separator."\n";

    foreach ($rows as $row) {
        echo formatRow($row, $widths);
    }

    echo $separator."\n";
}

/**
 * @param array<int, string> $row
 * @param array<int, int>    $widths
 */
function formatRow(array $row, array $widths): string
{
    $cells = [];
    foreach ($row as $index => $value) {
        $cells[] = ' '.\str_pad($value, $widths[$index]).' ';
    }

    return '|'.implode('|', $cells)."|\n";
}
