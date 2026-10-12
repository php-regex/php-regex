<?php

declare(strict_types=1);

use PHPRegex\Toolkit\Regex;

/**
 * Example: Parse a regex pattern into its AST
 *
 * This example demonstrates:
 * - Parsing a pattern through the facade
 * - Reading the AST root, the delimiter and the flags
 * - Seeing the tokens the lexer produced on the way
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$pattern = '/^(?:(?<protocol>https?):\/\/)(?<domain>[a-zA-Z0-9.-]+)(?::(?<port>\d+))?$/';

echo "=== Pattern Parsing ===\n";
echo "Pattern: {$pattern}\n\n";

$regex = Regex::create();
$ast = $regex->parse($pattern);

echo "✓ Pattern parsed successfully\n\n";

echo "=== AST ===\n";
echo 'Root node:     ' . $ast->pattern::class . "\n";
echo "Flags:         " . ('' === $ast->flags ? '(none)' : $ast->flags) . "\n";
echo "Delimiter:     {$ast->delimiter}\n";
echo 'Child nodes:   ' . count($ast->getChildren()) . "\n";

echo "\n";

echo "=== Tokens (the lexer's view) ===\n";
$tokens = $regex->tokenize($pattern);
echo 'Token count: ' . count($tokens) . "\n";
echo 'First token: ' . json_encode($tokens[0] ?? null) . "\n";
