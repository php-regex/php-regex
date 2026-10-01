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

use PHPRegex\Parser\Lexer;

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once __DIR__.'/../vendor/autoload.php';

echo "Benchmarking Lexer performance improvements...\n\n";

// Test patterns of varying complexity
$testPatterns = [
    'simple' => 'hello',
    'medium' => 'user_(?:\d+|[a-z]+)',
    'complex' => '/^(?:user|admin)_(?:\d{1,3}|[a-z]{2,10})_(?:active|inactive)$/i',
    'very_complex' => '/(?:(?<quote>["\'])(?<string>(?:\\\\.|(?!\k<quote>).)*)\k<quote>|(?<number>-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)|(?<boolean>true|false)|(?<null>null))/',
];

$iterations = 100;

foreach ($testPatterns as $name => $pattern) {
    echo "=== Testing {$name} pattern ===\n";
    echo 'Pattern: '.substr($pattern, 0, 60).(\strlen($pattern) > 60 ? '...' : '')."\n";

    // Benchmark tokenization
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $lexer = new Lexer();
        $stream = $lexer->tokenize($pattern);
        $tokens = $stream->getTokens();
        $count = \count($tokens);
    }
    $tokenizeTime = microtime(true) - $start;

    echo \sprintf("Tokenization (%d iterations): %.4f seconds\n", $iterations, $tokenizeTime);
    echo \sprintf("Average tokens per pattern: %d\n", $count);
    echo \sprintf("Average time per tokenization: %.6f seconds\n", $tokenizeTime / $iterations);
    echo "\n";
}

// Test pattern compilation (first time vs cached)
echo "=== Pattern Compilation Performance ===\n";

$start = microtime(true);
$lexer1 = new Lexer();
$stream1 = $lexer1->tokenize('test');
$firstTime = microtime(true) - $start;

$start = microtime(true);
$lexer2 = new Lexer();
$stream2 = $lexer2->tokenize('test');
$cachedTime = microtime(true) - $start;

echo \sprintf("First compilation: %.6f seconds\n", $firstTime);
echo \sprintf("Cached compilation: %.6f seconds\n", $cachedTime);
echo \sprintf("Speedup: %.2fx\n", $firstTime / $cachedTime);

echo "\nMemory usage: ".memory_get_peak_usage(true) / 1024 / 1024 ." MB\n";
