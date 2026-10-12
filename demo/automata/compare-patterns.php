<?php

declare(strict_types=1);

/**
 * Proves whether two patterns match the same language.
 *
 * This example demonstrates:
 * - LanguageSolver::equivalent() with witness strings when they differ
 * - LanguageSolver::subsetOf()
 */

require_once __DIR__.'/../../vendor/autoload.php';

use PHPRegex\Automata\LanguageSolver;

$solver = new LanguageSolver();

echo "=== Pattern Equivalence ===\n\n";

$pairs = [
    ['/a{3}/', '/aaa/'],
    ['/a+/', '/[a-z]+/'],
];

foreach ($pairs as [$left, $right]) {
    echo "{$left}  vs  {$right}\n";

    $result = $solver->equivalent($left, $right);

    echo '  Equivalent: '.($result->isEquivalent ? 'yes' : 'no')."\n";

    if (!$result->isEquivalent) {
        echo '  Only the left matches:  '.($result->leftOnlyExample ?? '(none)')."\n";
        echo '  Only the right matches: '.($result->rightOnlyExample ?? '(none)')."\n";
    }

    echo "\n";
}

echo "=== Sublanguage ===\n\n";

$subset = $solver->subsetOf('/ab+/', '/a+b*/');

echo "Everything /ab+/ matches, /a+b*/ matches too: ".($subset->isSubset ? 'yes' : 'no')."\n";
