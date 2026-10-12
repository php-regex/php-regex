<?php

declare(strict_types=1);

/**
 * Judges the same pattern for several engine targets.
 *
 * This example demonstrates:
 * - Creating a facade pinned to a PHP and a PCRE2 version
 * - Asking which targets accept the pattern, and which reject it
 * - Reading the verdicts of a compatibility report
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPRegex\Toolkit\Regex;

$pattern = '/\p{Greek}+/u';

echo "=== Version Targets ===\n";
echo "Pattern: {$pattern}\n\n";

$targets = [
    'the running engine' => [],
    'PHP 8.2 / PCRE2 10.40' => ['php_version' => '8.2', 'pcre_version' => '10.40'],
];

foreach ($targets as $label => $options) {
    $regex = Regex::create($options);
    $compatibility = $regex->compatibility($pattern);

    echo "Target: {$label}\n";
    echo '  Verdicts:    '.\count($compatibility->verdicts())."\n";
    echo '  Valid everywhere: '.($compatibility->isValidEverywhere() ? 'yes' : 'no')."\n";

    foreach ($compatibility->invalidVerdicts() as $verdict) {
        echo '  Rejected:    '.\json_encode($verdict)."\n";
    }

    echo "\n";
}

echo "A pattern your editor accepts may still fail on the PHP release you\n";
echo "deploy to; pin the target once and every check judges for that engine.\n";
