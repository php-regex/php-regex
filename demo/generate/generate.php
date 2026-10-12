<?php

declare(strict_types=1);

use PHPRegex\Toolkit\Regex;

/**
 * Example: Generate a string that matches a pattern
 *
 * This example demonstrates:
 * - generate() through the facade
 * - Proving each sample against the real engine with preg_match()
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$patterns = [
    '/\d{4}-\d{2}-\d{2}/',
    '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/',
    '/v(?<major>\d+)\.(?<minor>\d+)/',
];

$regex = Regex::create();

echo "=== Sample Generation ===\n\n";

foreach ($patterns as $pattern) {
    $sample = $regex->generate($pattern);

    echo "Pattern: {$pattern}\n";
    echo "Sample:  {$sample}\n";
    echo 'Matches: ' . (preg_match($pattern, $sample) === 1 ? '✓ yes' : '✗ no') . "\n\n";
}

echo "The generator walks the automaton of the pattern, so every sample is\n";
echo "proven to match — handy for fixtures, documentation and fuzzing seeds.\n";
