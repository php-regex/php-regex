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

use PhpRegex\Parser\Analysis\LiteralSet;

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once __DIR__.'/../vendor/autoload.php';

echo "Benchmarking LiteralSet performance improvements...\n\n";

// Test 1: Cross product performance
echo "=== Cross Product Performance ===\n";
$left = ['user_', 'admin_', 'guest_'];
$right = ['123', '456', '789', 'abc'];

$start = microtime(true);
for ($i = 0; $i < 10000; $i++) {
    $set1 = new LiteralSet($left, $left, true);
    $set2 = new LiteralSet($right, $right, true);
    $result = $set1->concat($set2);
    // Access result to ensure it's computed
    $count = \count($result->prefixes);
}
$crossProductTime = microtime(true) - $start;

echo \sprintf("Cross product operations (10,000 iterations): %.4f seconds\n", $crossProductTime);
echo \sprintf("Average result size: %d items\n\n", $count);

// Test 2: Deduplication performance
echo "=== Deduplication Performance ===\n";
$largeArray = [];
for ($i = 0; $i < 1000; $i++) {
    $largeArray[] = 'item'.($i % 100); // Create duplicates
}

$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    $set = new LiteralSet($largeArray, $largeArray, true);
    $deduplicated = $set->prefixes; // Access to trigger deduplication
}
$dedupTime = microtime(true) - $start;

echo \sprintf("Deduplication operations (1,000 iterations): %.4f seconds\n", $dedupTime);
echo \sprintf("Original size: %d, Deduplicated size: %d\n\n", \count($largeArray), \count($deduplicated));

// Test 3: Longest string computation
echo "=== Longest String Performance ===\n";
$strings = [];
for ($i = 0; $i < 1000; $i++) {
    $strings[] = str_repeat('a', $i + 1);
}

$start = microtime(true);
for ($i = 0; $i < 10000; $i++) {
    $set = new LiteralSet($strings, $strings, true);
    $longest = $set->getLongestPrefix();
}
$longestTime = microtime(true) - $start;

echo \sprintf("Longest string computation (10,000 iterations): %.4f seconds\n", $longestTime);
echo \sprintf("Longest string length: %d characters\n\n", \strlen($longest));

// Test 4: Memory limits
echo "=== Memory Limit Enforcement ===\n";
$veryLargeArray = [];
for ($i = 0; $i < 200; $i++) {
    $veryLargeArray[] = 'item'.$i;
}

$set = new LiteralSet($veryLargeArray, $veryLargeArray, true);
echo \sprintf("Input size: %d, Limited size: %d (max 100)\n", \count($veryLargeArray), \count($set->prefixes));

echo "\nMemory usage: ".memory_get_peak_usage(true) / 1024 / 1024 ." MB\n";
