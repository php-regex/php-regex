<?php

declare(strict_types=1);

use PHPRegex\Toolkit\Regex;

/**
 * Example: Optimize a pattern
 *
 * This example demonstrates:
 * - optimize() through the facade
 * - Reading the rewrites that were applied
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$pattern = '/[0-9]{4}-[0-9]{2}-[0-9]{2}/';

echo "=== Pattern Optimization ===\n";
echo "Pattern: {$pattern}\n\n";

$result = Regex::create()->optimize($pattern);

echo 'Changed: ' . ($result->isChanged() ? 'yes' : 'no') . "\n";
echo "Before:  {$result->original}\n";
echo "After:   {$result->optimized}\n\n";

echo "Rewrites applied:\n";

foreach ($result->changes as $change) {
    echo '  - ' . json_encode($change) . "\n";
}
