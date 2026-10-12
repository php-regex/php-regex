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

use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

/**
 * Example: Validate a regex pattern with PHPRegex
 *
 * This example demonstrates:
 * - Basic pattern validation
 * - Error handling with user-friendly messages
 * - Complexity scoring
 * - ReDoS risk checking
 */

require_once __DIR__.'/../../../vendor/autoload.php';

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
    if (null !== $result->hint && '' !== $result->hint) {
        echo "Hint: {$result->hint}\n";
    }
    echo "\n";
    exit(1);
}

$redosResult = $regex->redos($pattern);

if ($redosResult->severity->rank() >= RedosSeverity::Medium->rank()) {
    echo "⚠️  ReDoS Risk: {$redosResult->severity->value}\n";
    echo "Consider using possessive quantifiers or atomic groups.\n";
} elseif (RedosSeverity::Unknown === $redosResult->severity) {
    echo "?  ReDoS Risk: unknown, the analysis could not reach a verdict\n";
} else {
    echo "✓ ReDoS Risk: {$redosResult->severity->value}\n";
}

echo "\n";
echo "=== Pattern Details ===\n";
$explanation = $regex->explain($pattern);
echo $explanation, "\n";
