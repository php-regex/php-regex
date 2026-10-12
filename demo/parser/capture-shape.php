<?php

declare(strict_types=1);

/**
 * Reads the shape of the capture groups of a pattern.
 *
 * This example demonstrates:
 * - The $matches layout preg_match() will produce, as a signature
 * - The $matches layout preg_match_all() will produce
 * - The named marks the pattern defines
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPRegex\Toolkit\Regex;

$pattern = '/(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})/';

echo "=== Capture Shape ===\n";
echo "Pattern: {$pattern}\n\n";

$shape = Regex::create()->captureShape($pattern);

echo "✓ Capture groups: ".\count($shape->groups)."\n";
echo 'Mark verbs: '.($shape->marks === [] ? '(none)' : \implode(', ', $shape->marks))."\n\n";

echo "=== \$matches after preg_match() ===\n";
echo $shape->matchShape()."\n\n";

echo "=== \$matches after preg_match_all() ===\n";
echo $shape->matchAllShape()."\n";

echo "The shapes are signatures: they hold no values, only what each entry\n";
echo "will be (a string, null, or a list), so an IDE or a static analyzer can\n";
echo "type \$matches before the match ever runs.\n";
