<?php

declare(strict_types=1);

use PHPRegex\Toolkit\Regex;

/**
 * Example: Transpile a pattern to another regex dialect
 *
 * This example demonstrates:
 * - transpile() through the facade
 * - The ready-to-paste literal for JavaScript and Python
 * - Reading the warnings when a dialect needs a workaround
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$pattern = '/(?P<word>\w+)\k{word}/i';

echo "=== Pattern Transpilation ===\n";
echo "Pattern: {$pattern}\n\n";

$regex = Regex::create();

foreach (['javascript', 'python'] as $target) {
    $result = $regex->transpile($pattern, $target);

    echo "Target: {$target}\n";
    echo "  Literal:      {$result->literal}\n";
    echo "  Constructor:  {$result->constructor}\n";

    if ($result->warnings !== []) {
        echo "  Warnings:\n";

        foreach ($result->warnings as $warning) {
            echo "    - {$warning}\n";
        }
    }

    echo "\n";
}

echo "The transpiler rewrites what the target dialect lacks — here the named\n";
echo "backreference — and tells you exactly what it had to change.\n";
