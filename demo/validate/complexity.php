<?php

declare(strict_types=1);

/**
 * Measures a pattern's complexity and reads the whole analysis report.
 *
 * This example demonstrates:
 * - The complexity score of a validation result
 * - analyze(): validity, errors, lint issues and ReDoS in one call
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPRegex\Toolkit\Regex;

echo "=== Complexity Score ===\n\n";

$regex = Regex::create();

$patterns = [
    'date' => '/\d{4}-\d{2}-\d{2}/',
    'risky slug' => '/^([a-z0-9]+-?)+$/',
];

foreach ($patterns as $name => $pattern) {
    $result = $regex->validate($pattern);
    echo "{$name}: {$pattern}\n";
    echo '  Valid: '.($result->isValid ? 'yes' : 'no')."\n";
    echo "  Complexity score: {$result->complexityScore}\n\n";
}

echo "=== Analysis Report (one call, every angle) ===\n\n";

$report = $regex->analyze($patterns['risky slug']);

echo 'Valid:      '.($report->isValid ? 'yes' : 'no')."\n";
echo 'Errors:     '.\count($report->errors)."\n";
echo 'Lint issues: '.\count($report->lintIssues)."\n";
echo 'ReDoS:      '.$report->redos->severity->value."\n";

if ($report->optimization->isChanged()) {
    echo "Optimized:  {$report->optimization->optimized}\n";
} else {
    echo "Optimized:  (no rewrite suggested)\n";
}
