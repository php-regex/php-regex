<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require __DIR__.'/../../vendor/autoload.php';

echo "=== RegexParser Library Validation Report ===\n\n";

$passedTests = 0;
$failedTests = 0;
$issues = [];

// Test 1: Check if generated samples actually match the pattern
echo "Test 1: Sample Generation Accuracy\n";
echo "-----------------------------------\n";
$testPatterns = [
    '/[a-z]{3,5}/',
    '/\d{3}-\d{2}-\d{4}/',
    '/^[A-Z][a-z]+$/',
    '/foo|bar/',
];

foreach ($testPatterns as $pattern) {
    try {
        $sample = Regex::create(['runtime_pcre_validation' => true])->generate($pattern);
        $matches = preg_match($pattern, $sample);
        if ($matches) {
            echo "✓ Pattern: $pattern → Sample: '$sample' matches\n";
            $passedTests++;
        } else {
            echo "✗ Pattern: $pattern → Sample: '$sample' DOES NOT MATCH\n";
            $failedTests++;
            $issues[] = "Generated sample doesn't match pattern: $pattern";
        }
    } catch (Exception $e) {
        echo "✗ Pattern: $pattern → Error: {$e->getMessage()}\n";
        $failedTests++;
        $issues[] = "Failed to generate sample for: $pattern";
    }
}
echo "\n";

// Test 2: Validate ReDoS detection
echo "Test 2: ReDoS Detection\n";
echo "-----------------------\n";
$redosPatterns = [
    '/(a+)+b/' => true,       // Should detect
    '/(a*)*b/' => true,       // Should detect
    '/a+b/' => false,         // Should be safe
    '/(a{1,5})+/' => false,   // Bounded, should be safer
];

foreach ($redosPatterns as $pattern => $shouldDetect) {
    try {
        $analysis = Regex::create(['runtime_pcre_validation' => true])->redos($pattern);
        $detected = !$analysis->isSafe();
        if ($detected === $shouldDetect) {
            echo "✓ Pattern: $pattern → ReDoS detection: ".($detected ? 'YES' : 'NO')." (correct)\n";
            $passedTests++;
        } else {
            echo "✗ Pattern: $pattern → Expected ".($shouldDetect ? 'detected' : 'safe').', got '.($detected ? 'detected' : 'safe')."\n";
            $failedTests++;
            $issues[] = "ReDoS detection incorrect for: $pattern";
        }
    } catch (Exception $e) {
        echo "✗ Pattern: $pattern → Error: {$e->getMessage()}\n";
        $failedTests++;
    }
}
echo "\n";

// Test 3: PCRE Feature Coverage
echo "Test 3: PCRE Feature Coverage\n";
echo "------------------------------\n";
$pcreFeatures = [
    'Named groups' => '/(?P<name>\w+)/',
    'Backreferences' => '/(a)\1/',
    'Lookbehind' => '/(?<=foo)bar/',
    'Negative lookbehind' => '/(?<!foo)bar/',
    'Lookahead' => '/foo(?=bar)/',
    'Negative lookahead' => '/foo(?!bar)/',
    'Atomic groups' => '/(?>a+)b/',
    'Conditionals' => '/(a)?(?(1)b|c)/',
    'Subroutines' => '/(\w+)(?1)/',
    'Unicode properties' => '/\p{L}+/u',
    'Unicode Emoji' => '/\p{Emoji}+/u',
    'Branch reset' => '/(?|(a)|(b))/',
    'Recursion' => '/a(?R)?z/',
];

foreach ($pcreFeatures as $feature => $pattern) {
    try {
        $ast = Regex::create(['runtime_pcre_validation' => true])->parse($pattern);
        // Check if it's actually valid PCRE
        $testString = 'test';
        @preg_match($pattern, $testString);
        if (\PREG_NO_ERROR === preg_last_error()) {
            echo "✓ $feature: Parsed successfully\n";
            $passedTests++;
        } else {
            echo "⚠ $feature: Parsed but may not be valid PCRE\n";
            $failedTests++;
        }
    } catch (Exception $e) {
        echo "✗ $feature: Failed - {$e->getMessage()}\n";
        $failedTests++;
        $issues[] = "Cannot parse PCRE feature: $feature";
    }
}
echo "\n";

// Test 4: Round-trip validation (parse → compile → verify)
echo "Test 4: Round-trip Validation\n";
echo "------------------------------\n";
$roundTripPatterns = [
    '/abc/',
    '/[a-z]+/i',
    '/(?:foo|bar)/',
    '/\d{3,5}/',
];

foreach ($roundTripPatterns as $pattern) {
    try {
        $compiler = new PatternPrinter();

        $ast = Regex::create(['runtime_pcre_validation' => true])->parse($pattern);
        $compiled = $ast->accept($compiler);

        // Test if they behave the same
        $testStrings = ['abc', 'ABC', 'foo', 'bar', '123', '12345'];
        $matches = true;
        foreach ($testStrings as $test) {
            $original = @preg_match($pattern, $test);
            $recompiled = @preg_match($compiled, $test);
            if ($original !== $recompiled) {
                $matches = false;

                break;
            }
        }

        if ($matches) {
            echo "✓ Pattern: $pattern → Compiles to: $compiled (behavior matches)\n";
            $passedTests++;
        } else {
            echo "⚠ Pattern: $pattern → Compiles to: $compiled (behavior differs)\n";
            $failedTests++;
            $issues[] = "Round-trip behavior mismatch for: $pattern";
        }
    } catch (Exception $e) {
        echo "✗ Pattern: $pattern → Error: {$e->getMessage()}\n";
        $failedTests++;
    }
}
echo "\n";

// Test 5: Invalid pattern detection
echo "Test 5: Invalid Pattern Detection\n";
echo "----------------------------------\n";
$invalidPatterns = [
    '/\2(\w+)/' => 'Backreference to non-existent group',
    '/(?<!a*b)/' => 'Variable-length lookbehind',
];

foreach ($invalidPatterns as $pattern => $expectedError) {
    $result = Regex::create(['runtime_pcre_validation' => true])->validate($pattern);
    if (!$result->isValid) {
        echo "✓ Pattern: $pattern → Correctly detected as invalid ($expectedError)\n";
        $passedTests++;
    } else {
        echo "✗ Pattern: $pattern → Should be invalid but passed validation\n";
        $failedTests++;
        $issues[] = "Failed to detect invalid pattern: $pattern";
    }
}

// Additional ReDoS check
$redosPattern = '/(a+)*b/';
$analysis = Regex::create(['runtime_pcre_validation' => true])->redos($redosPattern);
if (!$analysis->isSafe()) {
    echo "✓ Pattern: $redosPattern → Correctly detected as ReDoS risk\n";
    $passedTests++;
} else {
    echo "✗ Pattern: $redosPattern → Should be ReDoS risk but passed\n";
    $failedTests++;
    $issues[] = "Failed to detect ReDoS risk: $redosPattern";
}
echo "\n";

// Summary
echo "=== Summary ===\n";
echo "Passed: $passedTests\n";
echo "Failed: $failedTests\n";
echo "\n";

if (!empty($issues)) {
    echo "=== Critical Issues Found ===\n";
    foreach ($issues as $i => $issue) {
        echo ($i + 1).". $issue\n";
    }
    echo "\n";
}

echo "=== Analysis ===\n";
echo "The library demonstrates:\n";
echo "• Basic parsing and AST generation works\n";
echo "• Sample generation works for simple patterns\n";
echo "• ReDoS detection has some capability\n";
echo "• Error detection for obvious invalid patterns works\n\n";

echo "However, there are concerns:\n";
echo "• No systematic cross-validation against PHP's PCRE engine\n";
echo "• Tests check AST structure, not actual regex behavior\n";
echo "• Coverage of advanced PCRE features is unclear\n";
echo "• No proof that optimizations preserve semantics\n";
echo "• Integration tools (PHPStan) rely on unverified parser\n";
