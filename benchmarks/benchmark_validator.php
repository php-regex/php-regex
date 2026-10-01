<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use PhpRegex\Toolkit\Regex;

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once __DIR__.'/../vendor/autoload.php';

echo "Benchmarking Validator performance improvements...\n\n";

// Test patterns of varying complexity for validation
$testPatterns = [
    'simple' => '/hello/',
    'quantifiers' => '/a+b*c{1,3}/',
    'groups' => '/(user|admin)_(?:\d+|[a-z]+)/',
    'complex' => '/^(?:user|admin)_(?:\d{1,3}|[a-z]{2,10})_(?:active|inactive)$/i',
    'unicode' => '/\p{L}+_\p{N}+/u',
    'backrefs' => '/(a|b|c)\1+/',
    'very_complex' => '/(?:(?<quote>["\'])(?<string>(?:\\\\.|(?!\k<quote>).)*)\k<quote>|(?<number>-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)|(?<boolean>true|false)|(?<null>null))/',
];

$iterations = 100;

foreach ($testPatterns as $name => $pattern) {
    echo "=== Testing {$name} pattern ===\n";
    echo 'Pattern: '.substr($pattern, 0, 60).(\strlen($pattern) > 60 ? '...' : '')."\n";

    // Benchmark parsing + validation
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);
        // Validation happens automatically in parse()
    }
    $parseTime = microtime(true) - $start;

    echo \sprintf("Parse + Validate (%d iterations): %.4f seconds\n", $iterations, $parseTime);
    echo \sprintf("Average time per operation: %.6f seconds\n", $parseTime / $iterations);
    echo "\n";
}

// Test quantifier bounds caching specifically
echo "=== Quantifier Bounds Caching Test ===\n";

$patternWithManyQuantifiers = '/a*b+c?d{1,5}e{2,}f{3,6}g{1}h{0,}/';
$regex = Regex::create();

$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    $ast = $regex->parse($patternWithManyQuantifiers);
}
$parseTime = microtime(true) - $start;

echo \sprintf("Pattern with many quantifiers (1,000 iterations): %.4f seconds\n", $parseTime);
echo \sprintf("Average time per parse: %.6f seconds\n", $parseTime / 1000);

// Test Unicode property caching
echo "\n=== Unicode Property Caching Test ===\n";

$patternsWithUnicode = [
    '/\p{L}+/u',
    '/\p{N}+/u',
    '/\p{Lu}+/u',
    '/\p{Ll}+/u',
];

$start = microtime(true);
for ($i = 0; $i < 100; $i++) {
    foreach ($patternsWithUnicode as $pattern) {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);
    }
}
$unicodeTime = microtime(true) - $start;

echo \sprintf("Unicode property validation (400 operations): %.4f seconds\n", $unicodeTime);
echo \sprintf("Average time per validation: %.6f seconds\n", $unicodeTime / 400);

echo "\nMemory usage: ".memory_get_peak_usage(true) / 1024 / 1024 ." MB\n";
