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

use PHPRegex\Parser\Analysis\ComplexityScorer;
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

echo "Benchmarking ComplexityScorer performance improvements...\n\n";

// Test patterns of varying complexity for scoring
$testPatterns = [
    'simple' => '/hello/',
    'quantifiers' => '/a+b*c{1,3}/',
    'nested_quantifiers' => '/(a+)+/', // ReDoS pattern
    'complex' => '/^(?:user|admin)_(?:\d{1,3}|[a-z]{2,10})_(?:active|inactive)$/i',
    'lookarounds' => '/(?=foo)bar(?!baz)/',
    'backrefs' => '/(a|b|c)\1+/',
    'conditionals' => '/(?(1)a|b)/',
    'very_complex' => '/(?:(?<quote>["\'])(?<string>(?:\\\\.|(?!\k<quote>).)*)\k<quote>|(?<number>-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)|(?<boolean>true|false)|(?<null>null))/',
];

$iterations = 1000;

foreach ($testPatterns as $name => $pattern) {
    echo "=== Testing {$name} pattern ===\n";
    echo 'Pattern: '.substr($pattern, 0, 60).(\strlen($pattern) > 60 ? '...' : '')."\n";

    // Benchmark parsing + complexity scoring
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);
        $visitor = new ComplexityScorer();
        $score = $ast->accept($visitor);
    }
    $scoreTime = microtime(true) - $start;

    echo \sprintf("Parse + Score (%d iterations): %.4f seconds\n", $iterations, $scoreTime);
    echo \sprintf("Average time per operation: %.6f seconds\n", $scoreTime / $iterations);
    echo \sprintf("Complexity score: %d\n", $score);
    echo "\n";
}

// Test caching effectiveness
echo "=== Quantifier Caching Test ===\n";

$patternsWithRepeatedQuantifiers = [
    '/a*/', '/a+/', '/a{1,}/', '/a{2,}/', '/a{3,}/', // All unbounded
    '/a{1}/', '/a{2}/', '/a{3}/', '/a{1,2}/', '/a{2,3}/', // All bounded
];

$start = microtime(true);
for ($i = 0; $i < 100; $i++) {
    foreach ($patternsWithRepeatedQuantifiers as $pattern) {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);
        $visitor = new ComplexityScorer();
        $score = $ast->accept($visitor);
    }
}
$cacheTime = microtime(true) - $start;

echo \sprintf("Repeated quantifier patterns (1,000 operations): %.4f seconds\n", $cacheTime);
echo \sprintf("Average time per operation: %.6f seconds\n", $cacheTime / 1000);

// Test ReDoS detection performance
echo "\n=== ReDoS Detection Performance ===\n";

$redosPatterns = [
    '/(a*)+/',     // Double nested
    '/((a*)*)+/',  // Triple nested
    '/(a+)+/',     // Double nested with +
    '/((a+)*)+/',  // Mixed nesting
];

$start = microtime(true);
for ($i = 0; $i < 100; $i++) {
    foreach ($redosPatterns as $pattern) {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);
        $visitor = new ComplexityScorer();
        $score = $ast->accept($visitor);
    }
}
$redosTime = microtime(true) - $start;

echo \sprintf("ReDoS pattern analysis (400 operations): %.4f seconds\n", $redosTime);
echo \sprintf("Average time per analysis: %.6f seconds\n", $redosTime / 400);

echo "\nMemory usage: ".memory_get_peak_usage(true) / 1024 / 1024 ." MB\n";
