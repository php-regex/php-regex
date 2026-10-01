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

use PHPRegex\Toolkit\Regex;

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once __DIR__.'/../vendor/autoload.php';

echo "Benchmarking Parser performance improvements...\n\n";

// Test patterns of varying complexity
$testPatterns = [
    'simple' => '/hello/',
    'medium' => '/user_(?:\d+|[a-z]+)/',
    'complex' => '/^(?:user|admin)_(?:\d{1,3}|[a-z]{2,10})_(?:active|inactive)$/i',
    'very_complex' => '/(?:(?<quote>["\'])(?<string>(?:\\\\.|(?!\k<quote>).)*)\k<quote>|(?<number>-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)|(?<boolean>true|false)|(?<null>null))/',
];

$iterations = 100;

foreach ($testPatterns as $name => $pattern) {
    echo "=== Testing {$name} pattern ===\n";
    echo 'Pattern: '.substr($pattern, 0, 60).(\strlen($pattern) > 60 ? '...' : '')."\n";

    // Benchmark parsing
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);
        // Just count the pattern length as a simple metric
        $patternLength = \strlen($pattern);
    }
    $parseTime = microtime(true) - $start;

    echo \sprintf("Parsing (%d iterations): %.4f seconds\n", $iterations, $parseTime);
    echo \sprintf("Pattern length: %d characters\n", $patternLength);
    echo \sprintf("Average time per parse: %.6f seconds\n", $parseTime / $iterations);
    echo "\n";
}

// Test token access efficiency
echo "=== Token Access Efficiency Test ===\n";

$pattern = '/a+b*c{1,3}[def]/';
$regex = Regex::create();

$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    $ast = $regex->parse($pattern);
    // This exercises the optimized token access methods
}
$parseTime = microtime(true) - $start;

echo \sprintf("Complex parsing (1,000 iterations): %.4f seconds\n", $parseTime);
echo \sprintf("Average time per parse: %.6f seconds\n", $parseTime / 1000);

echo "\nMemory usage: ".memory_get_peak_usage(true) / 1024 / 1024 ." MB\n";
