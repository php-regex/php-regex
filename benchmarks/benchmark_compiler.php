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

use PhpRegex\Parser\Printer\PatternPrinter;
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

echo "Benchmarking PatternPrinter performance improvements...\n\n";

// Test patterns of varying complexity for compilation
$testPatterns = [
    'simple' => '/hello/',
    'escaped' => '/a\\*c\\?d\\{3\\}/',
    'complex' => '/^(?:user|admin)_(?:\\d{1,3}|[a-z]{2,10})_(?:active|inactive)$/i',
    'char_class' => '/[a-zA-Z0-9_\\-\\.]+/',
    'very_complex' => '/(?:(?<quote>["\'])(?<string>(?:\\\\.|(?!\k<quote>).)*)\k<quote>|(?<number>-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)|(?<boolean>true|false)|(?<null>null))/',
];

$iterations = 1000;

foreach ($testPatterns as $name => $pattern) {
    echo "=== Testing {$name} pattern ===\n";
    echo 'Pattern: '.substr($pattern, 0, 60).(\strlen($pattern) > 60 ? '...' : '')."\n";

    // Benchmark parsing + compilation
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $regex = Regex::create();
        $ast = $regex->parse($pattern);
        $compiled = $ast->accept(new PatternPrinter());
    }
    $compileTime = microtime(true) - $start;

    echo \sprintf("Parse + Compile (%d iterations): %.4f seconds\n", $iterations, $compileTime);
    echo \sprintf("Average time per operation: %.6f seconds\n", $compileTime / $iterations);
    echo \sprintf("Round-trip check: %s\n", $compiled === $pattern ? 'PASS' : 'FAIL');
    echo "\n";
}

// Test compilation-only performance (reuse parsed AST)
echo "=== Compilation-Only Performance ===\n";

$regex = Regex::create();
$ast = $regex->parse('/[a-zA-Z0-9_\\-\\.]+(?:\\?(?:[^#]*))?(?:#.*)?/');
$compiler = new PatternPrinter();

$start = microtime(true);
for ($i = 0; $i < 10000; $i++) {
    $compiled = $ast->accept($compiler);
}
$compilationTime = microtime(true) - $start;

echo \sprintf("Compilation only (10,000 iterations): %.4f seconds\n", $compilationTime);
echo \sprintf("Average time per compilation: %.6f seconds\n", $compilationTime / 10000);

// Test delimiter caching
echo "\n=== Delimiter Caching Test ===\n";

$delimiters = ['/', '#', '!', '@', '%', '^', '&', '*', '-', '+', '=', '|', ':', ';', ',', '.'];

$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    foreach ($delimiters as $delimiter) {
        // This would trigger delimiter mapping in the old implementation
        $regex = Regex::create();
        $ast = $regex->parse("{$delimiter}test{$delimiter}");
        $compiled = $ast->accept(new PatternPrinter());
    }
}
$delimiterTime = microtime(true) - $start;

echo \sprintf("Delimiter mapping (16,000 operations): %.4f seconds\n", $delimiterTime);
echo \sprintf("Average time per delimiter operation: %.6f seconds\n", $delimiterTime / 16000);

echo "\nMemory usage: ".memory_get_peak_usage(true) / 1024 / 1024 ." MB\n";
