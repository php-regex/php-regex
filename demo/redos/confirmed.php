<?php

declare(strict_types=1);

/**
 * Confirms a ReDoS risk by actually running the engine.
 *
 * This example demonstrates:
 * - RedosMode::Confirmed with ConfirmationOptions
 * - Reading the confidence, the witness samples and the suggested rewrite
 * - Watching the attack input stall a naive preg_match()
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

$risky = '/^(\w+)+$/';

echo "=== Confirmed ReDoS Analysis ===\n";
echo "Pattern: {$risky}\n\n";

$regex = Regex::create();
$result = $regex->redos(
    $risky,
    RedosSeverity::Medium,
    RedosMode::Confirmed,
    new ConfirmationOptions(maxInputLength: 1000),
);

echo 'Severity:   '.$result->severity->value."\n";
echo 'Confirmed:  '.($result->isConfirmed() ? 'yes' : 'no')."\n";
echo 'Confidence: '.$result->confidence->value."\n";

if ($result->confirmation !== null) {
    echo 'Witness samples: '.\count($result->confirmation->samples)."\n";
}

foreach ($result->findings as $finding) {
    echo "Finding:    {$finding->message}\n";

    if ($finding->suggestedRewrite !== null) {
        echo "Suggested:  {$finding->suggestedRewrite}\n";
    }
}

echo "\n=== The Attack, Timed ===\n\n";

$attack = \str_repeat('a', 1000).'!';

$start = \hrtime(true);
\preg_match($risky, $attack);
$elapsed = (\hrtime(true) - $start) / 1e6;

\printf("preg_match() on 1000 a's + '!': %.1f ms\n", $elapsed);

$safe = '/^\w+$/';
$start = \hrtime(true);
\preg_match($safe, $attack);
$elapsedSafe = (\hrtime(true) - $start) / 1e6;

\printf("preg_match() on the rewrite %s: %.2f ms\n", $safe, $elapsedSafe);
