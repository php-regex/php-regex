<?php

declare(strict_types=1);

use PHPRegex\Toolkit\Regex;
use PHPRegex\Redos\RedosSeverity;

/**
 * Example: Validate a regex pattern with RegexParser
 *
 * This example demonstrates:
 * - Basic pattern validation
 * - Error handling with user-friendly messages
 * - Complexity scoring
 * - ReDoS risk checking
 */

require_once __DIR__ . '/../vendor/autoload.php';

$pattern = '/\d{4}-\d{2}-\d{2}/';

echo "=== Pattern Validation ===\n";
echo "Pattern: {$pattern}\n\n";

$regex = Regex::create();
$result = $regex->validate($pattern);

if ($result->isValid) {
    echo "✓ Pattern is valid\n";
    echo "Complexity Score: {$result->complexityScore}\n";
    echo "\n";
} else {
    echo "✗ Pattern is invalid\n";
    echo "Error: {$result->error}\n";
    if ($result->hint !== null && $result->hint !== '') {
        echo "Hint: {$result->hint}\n";
    }
    echo "\n";
    exit(1);
}

$redosResult = $regex->redos($pattern);

$severityOrder = [RedosSeverity::Safe, RedosSeverity::Low, RedosSeverity::Medium, RedosSeverity::High, RedosSeverity::Critical];
$riskLevel = array_search($redosResult->severity, $severityOrder, true);

if ($riskLevel >= 2) {  // MEDIUM or worse
    echo "⚠️  ReDoS Risk: {$redosResult->severity->value}\n";
    echo "Consider using possessive quantifiers or atomic groups.\n";
} else {
    echo "✓ ReDoS Risk: {$redosResult->severity->value}\n";
}

echo "\n";
echo "=== Pattern Details ===\n";
$explanation = $regex->explain($pattern);
echo $explanation;
