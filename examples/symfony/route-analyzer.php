<?php

declare(strict_types=1);

use PhpRegex\Toolkit\Regex;
use PhpRegex\Redos\RedosSeverity;

/**
 * Example: Symfony bundle route conflict analysis
 *
 * This example demonstrates:
 * - Using RegexParser bundle with Symfony
 * - Analyzing route conflicts
 * - Getting suggestions for route ordering
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Example routes from a Symfony application
$routes = [
    '/blog/{slug}',
    '/article/{id<[0-9]+>}',
    '/category/{category<[a-z]+>}',
    '/archive/{year<[0-9]{4>}/{month<[0-9]{2>}',
];

echo "=== Symfony Route Conflict Analysis ===\n\n";

$regex = Regex::create();

foreach ($routes as $route) {
    echo "Analyzing: {$route}\n";
    $validation = $regex->validate($route);

    if (!$validation->isValid) {
        echo "  ✗ Invalid route pattern\n";
        echo "  Error: {$validation->error}\n";
        continue;
    }

    $redos = $regex->redos($route);

    $severityOrder = [RedosSeverity::SAFE, RedosSeverity::LOW, RedosSeverity::MEDIUM, RedosSeverity::HIGH, RedosSeverity::CRITICAL];
    $riskLevel = array_search($redos->severity, $severityOrder, true);

    if ($riskLevel >= 2) {  // MEDIUM or worse
        echo "  ⚠️  ReDoS Risk: {$redos->severity->value}\n";
    }

    echo "  ✓ Valid\n\n";
}

echo "\n=== Recommendations ===\n\n";
echo "1. Analyze routes for conflicts before deploying\n";
echo "2. Use Symfony console: php bin/console regex:routes\n";
echo "3. Reorder routes by specificity (more specific first)\n";
echo "4. Test with realistic URLs to avoid false positives\n";
