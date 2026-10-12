<?php

declare(strict_types=1);

/**
 * Explains a pattern in plain English.
 *
 * This example demonstrates:
 * - explain() through the facade
 * - Reading the explanation of a real-world URL pattern
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPRegex\Toolkit\Regex;

$pattern = '/^(?<protocol>https?):\\/\\/(?<domain>[a-z0-9.-]+)(?::(?<port>\d{2,5}))?$/';

echo "=== Pattern Explanation ===\n";
echo "Pattern: {$pattern}\n\n";

echo Regex::create()->explain($pattern);
echo "\n";
