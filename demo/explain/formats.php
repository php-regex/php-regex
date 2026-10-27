<?php

declare(strict_types=1);

/**
 * Renders a pattern for several outputs.
 *
 * This example demonstrates:
 * - explain() as plain text and as HTML
 * - highlight() with terminal colours (stripped when piped)
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPRegex\Toolkit\ExplainFormat;
use PHPRegex\Toolkit\HighlightFormat;
use PHPRegex\Toolkit\Regex;

$pattern = '/(?<quote>"|\'\')(?<body>(?:(?!\k<quote>).)+)\k<quote>/s';

$regex = Regex::create();

echo "=== Explanation as Text ===\n\n";
echo $regex->explain($pattern, ExplainFormat::Text);
echo "\n\n";

echo "=== Explanation as HTML (excerpt) ===\n\n";
$html = $regex->explain($pattern, ExplainFormat::Html);
echo \substr($html, 0, 400)."\n\n";

echo "=== Syntax Highlighting (Console) ===\n\n";
$highlighted = $regex->highlight($pattern, HighlightFormat::Console);
if (!\stream_isatty(\STDOUT)) {
    $highlighted = \preg_replace('/\e\[[\d;]*m/', '', $highlighted) ?? $highlighted;
}
echo $highlighted."\n";
