<?php

declare(strict_types=1);

use PHPRegex\Automata\LanguageSolver;

/**
 * Example: Compile a pattern to a deterministic automaton
 *
 * This example demonstrates:
 * - LanguageSolver::compile(): the DFA of a pattern
 * - acceptsPrefix(): asking the automaton which subjects can still match
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$pattern = '/^(?:ab)+$/';

echo "=== DFA Compilation ===\n";
echo "Pattern: {$pattern}\n\n";

$solver = new LanguageSolver();
$dfa = $solver->compile($pattern);

echo "✓ Compiled to a deterministic automaton\n\n";

echo "=== Prefix Queries ===\n";
echo "Can a subject still lead to a full match?\n\n";

$subjects = [
    'ab' => true,
    'abab' => true,
    'a' => true,
    'aba' => true,
    'b' => false,
    'x' => false,
];

foreach ($subjects as $subject => $expected) {
    $accepted = $solver->acceptsPrefix($pattern, $subject);
    $mark = $accepted === $expected ? '✓' : '✗';

    echo "{$mark} '{$subject}': " . ($accepted ? 'yes' : 'no') . "\n";
}

echo "\nThe automaton answers without running PCRE: the query is a walk over\n";
echo "the compiled states, so it stays fast no matter how costly the pattern\n";
echo "would be to backtrack.\n";
