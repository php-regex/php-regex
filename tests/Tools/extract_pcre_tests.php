#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * PCRE Test Extractor
 *
 * This script extracts test cases from PHP's official PCRE .phpt test files
 * and generates a comprehensive fixture file for the php-regex project.
 */
$testsDir = __DIR__.'/../yoeunes/tests';
$outputFile = __DIR__.'/Fixtures/php_pcre_comprehensive.php';

// Get all .phpt files
$phptFiles = glob($testsDir.'/*.phpt');
sort($phptFiles);

echo 'Found '.\count($phptFiles)." .phpt files\n";

$allTestCases = [];

foreach ($phptFiles as $phptFile) {
    $filename = basename($phptFile);
    echo "Processing: $filename\n";

    $content = file_get_contents($phptFile);
    $testCases = extractTestCases($content, $filename);

    foreach ($testCases as $case) {
        $allTestCases[] = $case;
    }
}

echo "\nTotal test cases extracted: ".\count($allTestCases)."\n";

// Generate the fixture file
generateFixtureFile($allTestCases, $outputFile);

echo "Generated: $outputFile\n";

/**
 * Extract test cases from a .phpt file content
 */
function extractTestCases(string $content, string $filename): array
{
    $cases = [];

    // Parse sections
    $sections = parsePhptSections($content);

    if (!isset($sections['TEST']) || !isset($sections['FILE'])) {
        return $cases;
    }

    $testDescription = trim($sections['TEST']);
    $phpCode = $sections['FILE'];
    $category = categorizeTest($filename);

    // Extract preg_* function calls from PHP code
    $pregCalls = extractPregCalls($phpCode);

    foreach ($pregCalls as $index => $call) {
        // Execute to get actual result
        $result = executeAndCapture($call);

        if (null !== $result) {
            $cases[] = [
                'pattern' => $call['pattern'],
                'subject' => $call['subject'],
                'flags' => $call['flags'] ?? 0,
                'offset' => $call['offset'] ?? 0,
                'expectedReturn' => $result['return'],
                'expectedMatches' => $result['matches'] ?? [],
                'expectedResult' => $result['result'] ?? null,
                'description' => $testDescription.' - case '.($index + 1),
                'source' => $filename,
                'functions' => [$call['function']],
                'category' => $category,
            ];
        }
    }

    return $cases;
}

/**
 * Parse .phpt file sections
 */
function parsePhptSections(string $content): array
{
    $sections = [];
    $currentSection = null;
    $currentContent = '';

    $lines = explode("\n", $content);

    foreach ($lines as $line) {
        if (preg_match('/^--([A-Z_]+)--\s*$/', $line, $matches)) {
            if (null !== $currentSection) {
                $sections[$currentSection] = $currentContent;
            }
            $currentSection = $matches[1];
            $currentContent = '';
        } else {
            $currentContent .= $line."\n";
        }
    }

    if (null !== $currentSection) {
        $sections[$currentSection] = $currentContent;
    }

    return $sections;
}

/**
 * Extract preg_* function calls from PHP code
 */
function extractPregCalls(string $phpCode): array
{
    $calls = [];

    // Remove PHP tags
    $phpCode = preg_replace('/<\?php|\?>/', '', $phpCode);

    // Pattern to match preg_match, preg_match_all, preg_replace, preg_split, preg_grep calls
    $pregFunctions = [
        'preg_match',
        'preg_match_all',
        'preg_replace',
        'preg_replace_callback',
        'preg_split',
        'preg_grep',
        'preg_filter',
    ];

    foreach ($pregFunctions as $func) {
        // Match function calls with single-quoted strings (preserve escapes)
        $patternSingle = '/'.preg_quote($func, '/').'\s*\(\s*\'((?:[^\']|\\\\\')*?)\'\s*,\s*([^,\)]+)(?:\s*,\s*([^,\)]+))?(?:\s*,\s*([^,\)]+))?(?:\s*,\s*([^,\)]+))?\s*\)/s';

        // Match function calls with double-quoted strings
        $patternDouble = '/'.preg_quote($func, '/').'\s*\(\s*"((?:[^"]|\\\\")*)"\s*,\s*([^,\)]+)(?:\s*,\s*([^,\)]+))?(?:\s*,\s*([^,\)]+))?(?:\s*,\s*([^,\)]+))?\s*\)/s';

        // Process single-quoted patterns (minimal escape processing)
        if (preg_match_all($patternSingle, (string) $phpCode, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $patternStr = $match[1];
                $subject = trim($match[2]);

                // For single-quoted strings, only \' and \\ are escape sequences
                $patternStr = str_replace(["\\'", '\\\\'], ["'", '\\'], $patternStr);

                // Clean up subject - it might be a variable or string
                $subjectValue = resolveValue($subject, $phpCode);

                if (null !== $subjectValue && isValidPattern($patternStr)) {
                    $call = [
                        'function' => $func,
                        'pattern' => $patternStr,
                        'subject' => $subjectValue,
                    ];

                    // Parse flags if present
                    if (isset($match[3]) && !empty(trim($match[3]))) {
                        $flagsStr = trim($match[3]);
                        if ('$' !== $flagsStr[0]) {
                            $call['flags'] = resolveFlags($flagsStr);
                        }
                    }

                    // Parse offset if present (for preg_match)
                    if (isset($match[4]) && !empty(trim($match[4]))) {
                        $offsetStr = trim($match[4]);
                        if (is_numeric($offsetStr) || '-' === $offsetStr[0]) {
                            $call['offset'] = (int) $offsetStr;
                        }
                    }

                    $calls[] = $call;
                }
            }
        }

        // Process double-quoted patterns (full escape processing)
        if (preg_match_all($patternDouble, (string) $phpCode, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $patternStr = $match[1];
                $subject = trim($match[2]);

                // Skip patterns with variable interpolation
                if (str_contains($patternStr, '$') || str_contains($patternStr, '{')) {
                    continue;
                }

                // For double-quoted strings, handle C-style escape sequences
                $patternStr = stripcslashes($patternStr);

                // Clean up subject - it might be a variable or string
                $subjectValue = resolveValue($subject, $phpCode);

                if (null !== $subjectValue && isValidPattern($patternStr)) {
                    $call = [
                        'function' => $func,
                        'pattern' => $patternStr,
                        'subject' => $subjectValue,
                    ];

                    // Parse flags if present
                    if (isset($match[3]) && !empty(trim($match[3]))) {
                        $flagsStr = trim($match[3]);
                        if ('$' !== $flagsStr[0]) {
                            $call['flags'] = resolveFlags($flagsStr);
                        }
                    }

                    // Parse offset if present (for preg_match)
                    if (isset($match[4]) && !empty(trim($match[4]))) {
                        $offsetStr = trim($match[4]);
                        if (is_numeric($offsetStr) || '-' === $offsetStr[0]) {
                            $call['offset'] = (int) $offsetStr;
                        }
                    }

                    $calls[] = $call;
                }
            }
        }
    }

    return $calls;
}

/**
 * Resolve a PHP value (variable or literal)
 */
function resolveValue(string $value, string $phpCode): ?string
{
    $value = trim($value);

    // Direct string literal
    if (preg_match('/^[\'"](.*)[\'"]\s*$/', $value, $m)) {
        return stripcslashes($m[1]);
    }

    // Variable reference - try to find its value
    if ('$' === $value[0]) {
        $varName = substr($value, 1);
        // Look for variable assignment
        if (preg_match('/\$'.preg_quote($varName, '/').'\s*=\s*[\'"]([^\'"]*)[\'"]\s*;/', $phpCode, $m)) {
            return stripcslashes($m[1]);
        }
    }

    // Array access or other complex expression - skip for now
    return null;
}

/**
 * Resolve PCRE flags from string representation
 */
function resolveFlags(string $flagsStr): int
{
    $flags = 0;
    $flagsStr = trim($flagsStr);

    $flagMap = [
        'PREG_OFFSET_CAPTURE' => \PREG_OFFSET_CAPTURE,
        'PREG_PATTERN_ORDER' => \PREG_PATTERN_ORDER,
        'PREG_SET_ORDER' => \PREG_SET_ORDER,
        'PREG_UNMATCHED_AS_NULL' => \defined('PREG_UNMATCHED_AS_NULL') ? \PREG_UNMATCHED_AS_NULL : 512,
    ];

    foreach ($flagMap as $name => $value) {
        if (str_contains($flagsStr, $name)) {
            $flags |= $value;
        }
    }

    // Handle numeric flags
    if (is_numeric($flagsStr)) {
        $flags = (int) $flagsStr;
    }

    return $flags;
}

/**
 * Check if a pattern is valid
 */
function isValidPattern(string $pattern): bool
{
    if (empty($pattern)) {
        return false;
    }

    // Try to compile the pattern
    try {
        $result = @preg_match($pattern, '');

        return false !== $result;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Execute a preg_* call and capture results
 */
function executeAndCapture(array $call): ?array
{
    $func = $call['function'];
    $pattern = $call['pattern'];
    $subject = $call['subject'];
    $flags = $call['flags'] ?? 0;
    $offset = $call['offset'] ?? 0;

    try {
        switch ($func) {
            case 'preg_match':
                $matches = [];
                $return = @preg_match($pattern, (string) $subject, $matches, $flags, $offset);
                if (false === $return) {
                    return null;
                }

                return [
                    'return' => $return,
                    'matches' => $matches,
                ];

            case 'preg_match_all':
                $matches = [];
                $return = @preg_match_all($pattern, (string) $subject, $matches, $flags, $offset);
                if (false === $return) {
                    return null;
                }

                return [
                    'return' => $return,
                    'matches' => $matches,
                ];

            case 'preg_split':
                $result = @preg_split($pattern, (string) $subject, -1, $flags);
                if (false === $result) {
                    return null;
                }

                return [
                    'return' => \count($result),
                    'result' => $result,
                ];

            case 'preg_replace':
                // For preg_replace, we need replacement string which we don't always have
                return null;

            case 'preg_grep':
                // preg_grep needs an array subject
                return null;

            default:
                return null;
        }
    } catch (\Throwable) {
        return null;
    }
}

/**
 * Categorize test based on filename
 */
function categorizeTest(string $filename): string
{
    if (preg_match('/^(\d{3})\.phpt$/', $filename)) {
        return 'basic_matching';
    }
    if (preg_match('/^bug\d+/', $filename)) {
        return 'bug_regressions';
    }
    if (preg_match('/^gh\d+/', $filename)) {
        return 'github_issues';
    }
    if (preg_match('/^errors?\d*\.phpt$/', $filename)) {
        return 'error_handling';
    }
    if (preg_match('/^preg_match_all/', $filename)) {
        return 'preg_match_all';
    }
    if (preg_match('/^preg_match/', $filename)) {
        return 'preg_match';
    }
    if (preg_match('/^preg_replace/', $filename)) {
        return 'preg_replace';
    }
    if (preg_match('/^preg_split|^split/', $filename)) {
        return 'preg_split';
    }
    if (preg_match('/^preg_grep|^grep/', $filename)) {
        return 'preg_grep';
    }
    if (preg_match('/^preg_quote/', $filename)) {
        return 'preg_quote';
    }
    if (preg_match('/^preg_filter/', $filename)) {
        return 'preg_filter';
    }
    if (preg_match('/^pcre_|^match_flags/', $filename)) {
        return 'flags_modifiers';
    }
    if (preg_match('/limit\.phpt$/', $filename)) {
        return 'performance_limits';
    }
    if (preg_match('/null_bytes|invalid_utf8/', $filename)) {
        return 'special_characters';
    }
    if (preg_match('/delimiter/', $filename)) {
        return 'delimiters';
    }

    return 'other';
}

/**
 * Generate the fixture file
 */
function generateFixtureFile(array $testCases, string $outputFile): void
{
    // Group by category
    $grouped = [];
    foreach ($testCases as $case) {
        $category = $case['category'];
        $grouped[$category] ??= [];
        $grouped[$category][] = $case;
    }

    // Sort categories
    ksort($grouped);

    $output = "<?php\n\n";
    $output .= "declare(strict_types=1);\n\n";
    $output .= "/**\n";
    $output .= " * Comprehensive PCRE Test Suite extracted from php-src/ext/pcre/tests\n";
    $output .= ' * Contains '.\count($testCases)." test cases covering preg_* functions and edge cases\n";
    $output .= " *\n";
    $output .= ' * Auto-generated on '.date('Y-m-d H:i:s')."\n";
    $output .= " */\n";
    $output .= "return [\n";

    foreach ($grouped as $category => $cases) {
        $categoryTitle = strtoupper(str_replace('_', ' ', $category));
        $output .= "\n    // ==================== CATEGORY: {$categoryTitle} ====================\n";

        foreach ($cases as $case) {
            $output .= "    [\n";
            $output .= "        'pattern' => ".varExportShort($case['pattern']).",\n";
            $output .= "        'subject' => ".varExportShort($case['subject']).",\n";
            $output .= "        'flags' => ".$case['flags'].",\n";
            $output .= "        'offset' => ".($case['offset'] ?? 0).",\n";
            $output .= "        'expectedReturn' => ".varExportShort($case['expectedReturn']).",\n";
            $output .= "        'expectedMatches' => ".exportArrayShort($case['expectedMatches'], 2).",\n";
            if (isset($case['expectedResult'])) {
                $output .= "        'expectedResult' => ".exportArrayShort($case['expectedResult'], 2).",\n";
            }
            $output .= "        'description' => ".varExportShort($case['description']).",\n";
            $output .= "        'source' => ".varExportShort($case['source']).",\n";
            $output .= "        'functions' => ".exportArrayShort($case['functions'], 0, true).",\n";
            $output .= "        'category' => ".varExportShort($case['category']).",\n";
            $output .= "    ],\n";
        }
    }

    $output .= "];\n";

    file_put_contents($outputFile, $output);
}

/**
 * Export a value using short array syntax
 */
function varExportShort($value): string
{
    if (null === $value) {
        return 'null';
    }
    if (\is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (\is_int($value)) {
        return (string) $value;
    }
    if (\is_string($value)) {
        return "'".addcslashes($value, "'\\")."'";
    }
    if (\is_array($value)) {
        return exportArrayShort($value, 0, true);
    }

    return var_export($value, true);
}

/**
 * Export array with short [] syntax
 */
function exportArrayShort(array $arr, int $indent = 0, bool $inline = false): string
{
    if (empty($arr)) {
        return '[]';
    }

    $spaces = str_repeat('    ', $indent);
    $innerSpaces = str_repeat('    ', $indent + 1);

    // Check if array is sequential (list)
    $isSequential = array_keys($arr) === range(0, \count($arr) - 1);

    // For small arrays or inline request, use single line
    if ($inline || (\count($arr) <= 3 && !hasNestedArrays($arr))) {
        $parts = [];
        foreach ($arr as $key => $value) {
            $valueStr = \is_array($value) ? exportArrayShort($value, 0, true) : varExportShort($value);
            if ($isSequential) {
                $parts[] = $valueStr;
            } else {
                $keyStr = \is_int($key) ? $key : "'".addslashes((string) $key)."'";
                $parts[] = "{$keyStr} => {$valueStr}";
            }
        }

        return '['.implode(', ', $parts).']';
    }

    $output = "[\n";
    foreach ($arr as $key => $value) {
        $valueStr = \is_array($value) ? exportArrayShort($value, $indent + 1, false) : varExportShort($value);

        if ($isSequential) {
            $output .= "{$innerSpaces}{$valueStr},\n";
        } else {
            $keyStr = \is_int($key) ? $key : "'".addslashes((string) $key)."'";
            $output .= "{$innerSpaces}{$keyStr} => {$valueStr},\n";
        }
    }
    $output .= "{$spaces}]";

    return $output;
}

/**
 * Check if array has nested arrays
 */
function hasNestedArrays(array $arr): bool
{
    $found = false;
    foreach ($arr as $value) {
        if (\is_array($value)) {
            $found = true;

            break;
        }
    }

    return $found;
}

/**
 * Export array with proper formatting
 */
function exportArray(array $arr): string
{
    if (empty($arr)) {
        return '[]';
    }

    $output = "[\n";
    foreach ($arr as $key => $value) {
        $keyStr = \is_int($key) ? $key : "'".addslashes((string) $key)."'";

        if (\is_array($value)) {
            $valueStr = exportArray($value);
        } elseif (\is_string($value)) {
            $valueStr = var_export($value, true);
        } elseif (\is_int($value)) {
            $valueStr = (string) $value;
        } elseif (null === $value) {
            $valueStr = 'null';
        } else {
            $valueStr = var_export($value, true);
        }

        $output .= "            {$keyStr} => {$valueStr},\n";
    }
    $output .= '        ]';

    return $output;
}
