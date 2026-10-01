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

use PHPRegex\Automata\Minimization\DfaMinimizer;
use PHPRegex\Automata\Minimization\HopcroftWorklist;
use PHPRegex\Automata\Minimization\MoorePartitionRefinement;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\DfaState;

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once __DIR__.'/../vendor/autoload.php';

$seed = 1337;
$stateCount = 160;
$alphabetSize = 180;

mt_srand($seed);

$alphabet = [];
for ($i = 0; $i < $alphabetSize; $i++) {
    $alphabet[] = $i * 3 + 1;
}

$dfa = buildRandomDfa($stateCount, $alphabet);

$algorithms = [
    'Moore partition refinement' => new MoorePartitionRefinement(),
    'Hopcroft worklist' => new HopcroftWorklist(),
];

echo "DFA Minimization Benchmark\n";
echo "===========================\n\n";
echo \sprintf("States: %d\n", $stateCount);
echo \sprintf("Alphabet: %d symbols\n", $alphabetSize);
echo \sprintf("Seed: %d\n\n", $seed);

$rows = [];

foreach ($algorithms as $label => $algorithm) {
    $minimizer = new DfaMinimizer($algorithm);
    $baselineMemory = memory_get_usage(true);
    $start = hrtime(true);
    $minimized = $minimizer->minimize($dfa);
    $elapsed = hrtime(true) - $start;
    $memory = memory_get_usage(true) - $baselineMemory;

    $rows[] = [
        $label,
        \sprintf('%.2f', $elapsed / 1_000_000),
        \sprintf('%.2f', $memory / 1024 / 1024),
        \sprintf('%d', \count($minimized->states)),
    ];
}

printTable(
    ['Algorithm', 'Time (ms)', 'Memory (MB)', 'Minimized States'],
    $rows,
);

/**
 * @param array<int> $alphabet
 */
function buildRandomDfa(int $stateCount, array $alphabet): Dfa
{
    $states = [];

    for ($stateId = 0; $stateId < $stateCount; $stateId++) {
        $transitions = [];
        foreach ($alphabet as $symbol) {
            $transitions[$symbol] = mt_rand(0, $stateCount - 1);
        }

        $states[$stateId] = new DfaState($stateId, $transitions, (bool) mt_rand(0, 1));
    }

    return new Dfa(0, $states);
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
