<?php

declare(strict_types=1);

use PHPRegex\Explain\RailroadSvgRenderer;
use PHPRegex\Toolkit\Regex;

/**
 * Example: Draw a railroad diagram of a pattern
 *
 * This example demonstrates:
 * - Rendering the AST of a pattern as an SVG railroad diagram
 * - The renderer is a node visitor: any transform on the AST works the same way
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$pattern = '/^(?<protocol>https?):\\/\\/(?<domain>[a-z0-9.-]+)(?::(?<port>\d{2,5}))?$/';

echo "=== Railroad Diagram ===\n";
echo "Pattern: {$pattern}\n\n";

$ast = Regex::create()->parse($pattern);
$svg = (new RailroadSvgRenderer())->visitRegex($ast);

echo "✓ Rendered " . strlen($svg) . " bytes of SVG\n\n";

echo "--- excerpt ---\n";
echo substr($svg, 0, 220) . "\n…\n";

echo "\nOpen it as a file to see the tracks: each lane is a group, each loop\n";
echo "a quantifier, and the anchors frame the whole diagram.\n";
