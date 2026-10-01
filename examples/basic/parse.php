<?php

declare(strict_types=1);

use PHPRegex\Toolkit\Regex;

/**
 * Example: Parse and analyze a regex pattern with RegexParser
 *
 * This example demonstrates:
 * - Parsing a pattern into an AST
 * - Getting pattern explanation
 * - Syntax highlighting
 * - AST traversal
 */

require_once __DIR__ . '/../vendor/autoload.php';

$pattern = '/^(?:(?<protocol>https?):\/\/)(?<domain>[a-zA-Z0-9.-]+)(?::(?<port>\d+))?$/';

echo "=== Pattern Parsing ===\n";
echo "Pattern: {$pattern}\n\n";

$regex = Regex::create();

// Parse the pattern into an AST
$ast = $regex->parse($pattern);

echo "✓ Pattern parsed successfully\n\n";

// Get human-readable explanation
$explanation = $regex->explain($pattern);

echo "=== Pattern Explanation ===\n";
echo $explanation;
echo "\n";

// Get syntax highlighting
$highlighted = $regex->highlight($pattern, 'console');

echo "=== Syntax Highlighting ===\n";
echo $highlighted;
echo "\n";

// Display AST structure (simplified)
echo "=== AST Structure ===\n";
echo "Root: RegexNode\n";
echo "Pattern type: " . $ast->pattern::class . "\n";
echo "Flags: {$ast->flags}\n";
echo "Delimiter: {$ast->delimiter}\n";
echo "\n";

// Generate a sample string matching the pattern
$sample = $regex->generate($pattern);

echo "=== Sample String ===\n";
echo "Sample: {$sample}\n";
