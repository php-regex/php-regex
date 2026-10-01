<?php

declare(strict_types=1);

use PhpRegex\Toolkit\Regex;
use PhpRegex\Redos\RedosSeverity;
use PhpRegex\Redos\RedosMode;
use PhpRegex\Redos\ConfirmationOptions;

/**
 * Example: Advanced ReDoS analysis and mitigation with RegexParser
 *
 * This example demonstrates:
 * - Detecting catastrophic backtracking risks
 * - Running confirmed ReDoS analysis
 * - Applying safe pattern rewrites
 * - Validating optimized patterns
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Risky pattern with nested quantifiers (classic ReDoS example)
$riskyPattern = '/^(\w+)+$/';

$optimizations = [
    'verifyWithAutomata' => false,  // For speed in this example
];

echo "=== ReDoS Analysis ===\n";
echo "Risky Pattern: {$riskyPattern}\n\n";

$regex = Regex::create();

// 1. Theoretical analysis (fast, conservative)
echo "1. Theoretical Analysis:\n";
$theoreticalResult = $regex->redos($riskyPattern, RedosSeverity::MEDIUM, RedosMode::THEORETICAL);

echo "   Severity: {$theoreticalResult->severity->value}\n";
echo "   Findings: " . count($theoreticalResult->findings) . " risk(s) found\n";

if (!empty($theoreticalResult->findings)) {
    foreach ($theoreticalResult->findings as $finding) {
        echo "   - Finding: {$finding->message}\n";
        if ($finding->suggestedRewrite !== null) {
            echo "     Suggested: {$finding->suggestedRewrite}\n";
        }
    }
}

echo "\n";

// 2. Confirmed analysis (slower, more accurate)
echo "2. Confirmed Analysis (with test inputs):\n";
$confirmedOptions = new ConfirmationOptions(
    maxInputLength: 1000,
);
$confirmedResult = $regex->redos($riskyPattern, RedosSeverity::MEDIUM, RedosMode::CONFIRMED, $confirmedOptions);

echo "   Severity: {$confirmedResult->severity->value}\n";
echo "   Confirmed: " . ($confirmedResult->isConfirmed() ? 'Yes' : 'No') . "\n";
echo "   Confidence: {$confirmedResult->confidence->value}\n";

if ($confirmedResult->confirmation !== null) {
    echo "   Test Samples: " . count($confirmedResult->confirmation->samples) . "\n";
}

echo "\n";

// 3. Safe pattern rewrites
echo "=== Safe Pattern Rewrites ===\n\n";

// Rewrite 1: Use possessive quantifiers
$safePattern1 = '/^\w++$/';

echo "Rewrite 1 (Possessive):\n";
echo "  Before: {$riskyPattern}\n";
echo "  After:  {$safePattern1}\n";
echo "  Why: Possessive quantifier (++) prevents backtracking\n\n";

$validation1 = $regex->validate($safePattern1);
$redos1 = $regex->redos($safePattern1, RedosSeverity::MEDIUM, RedosMode::THEORETICAL);

echo "  Valid: " . ($validation1->isValid ? 'Yes' : 'No') . "\n";
echo "  ReDoS Risk: {$redos1->severity->value}\n\n";

// Rewrite 2: Use atomic group
$safePattern2 = '/^(?>\w+)+$/';

echo "Rewrite 2 (Atomic Group):\n";
echo "  Before: {$riskyPattern}\n";
echo "  After:  {$safePattern2}\n";
echo "  Why: Atomic group (?>) prevents backtracking once matched\n\n";

$validation2 = $regex->validate($safePattern2);
$redos2 = $regex->redos($safePattern2, RedosSeverity::MEDIUM, RedosMode::THEORETICAL);

echo "  Valid: " . ($validation2->isValid ? 'Yes' : 'No') . "\n";
echo "  ReDoS Risk: {$redos2->severity->value}\n\n";

// Rewrite 3: Simplify quantifiers
$safePattern3 = '/^\w+$/';

echo "Rewrite 3 (Simplified):\n";
echo "  Before: {$riskyPattern}\n";
echo "  After:  {$safePattern3}\n";
echo "  Why: Remove redundant quantifier nesting\n\n";

$validation3 = $regex->validate($safePattern3);
$redos3 = $regex->redos($safePattern3, RedosSeverity::MEDIUM, RedosMode::THEORETICAL);

echo "  Valid: " . ($validation3->isValid ? 'Yes' : 'No') . "\n";
echo "  ReDoS Risk: {$redos3->severity->value}\n\n";

// Performance comparison
echo "\n=== Performance Comparison ===\n\n";
echo "Testing with malicious input (1000 'a's + mismatch):\n";

$maliciousInput = str_repeat('a', 1000) . '!';

$times = [
    'Risky' => 0.0,
    'Possessive' => 0.0,
    'Atomic' => 0.0,
    'Simplified' => 0.0,
];

// Test risky pattern
$start = microtime(true);
for ($i = 0; $i < 10; $i++) {
    preg_match($riskyPattern, $maliciousInput);
}
$times['Risky'] = microtime(true) - $start;

// Test safe patterns
$start = microtime(true);
for ($i = 0; $i < 10; $i++) {
    preg_match($safePattern1, $maliciousInput);
}
$times['Possessive'] = microtime(true) - $start;

$start = microtime(true);
for ($i = 0; $i < 10; $i++) {
    preg_match($safePattern2, $maliciousInput);
}
$times['Atomic'] = microtime(true) - $start;

$start = microtime(true);
for ($i = 0; $i < 10; $i++) {
    preg_match($safePattern3, $maliciousInput);
}
$times['Simplified'] = microtime(true) - $start;

echo "Risky Pattern:     " . number_format($times['Risky'] * 1000, 3) . " ms\n";
echo "Possessive (++):  " . number_format($times['Possessive'] * 1000, 3) . " ms\n";
echo "Atomic (?>):      " . number_format($times['Atomic'] * 1000, 3) . " ms\n";
echo "Simplified:       " . number_format($times['Simplified'] * 1000, 3) . " ms\n";

$improvements = [
    'Possessive' => $times['Risky'] / $times['Possessive'],
    'Atomic' => $times['Risky'] / $times['Atomic'],
    'Simplified' => $times['Risky'] / $times['Simplified'],
];

echo "\nPerformance Improvements:\n";
echo "  Possessive: " . number_format($improvements['Possessive'], 1) . "x faster\n";
echo "  Atomic:      " . number_format($improvements['Atomic'], 1) . "x faster\n";
echo "  Simplified:   " . number_format($improvements['Simplified'], 1) . "x faster\n";

echo "\n=== Recommendations ===\n\n";
echo "1. Use confirmed mode for critical patterns\n";
echo "2. Prefer possessive quantifiers when backtracking is not needed\n";
echo "3. Use atomic groups to prevent catastrophic backtracking\n";
echo "4. Test rewrites with real input data\n";
echo "5. Consider automata-based equivalence verification for critical systems\n";
