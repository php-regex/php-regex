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

$testsDir = __DIR__.'/../yoeunes/tests';
$outputFile = __DIR__.'/../tests/Fixtures/pcre_patterns.php';

if (!is_dir($testsDir)) {
    exit("Error: Tests directory not found at $testsDir\n");
}

$patterns = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testsDir, RecursiveDirectoryIterator::SKIP_DOTS),
);

foreach ($iterator as $file) {
    if ('phpt' !== $file->getExtension()) {
        continue;
    }

    $content = file_get_contents($file->getPathname());

    // Extract the --FILE-- section
    if (preg_match('/--FILE--(.*?)--[A-Z]+--/s', $content, $matches)) {
        $phpCode = $matches[1];

        // Basic extraction of string literals that look like regexes
        // This is a heuristic approach. We look for strings passed to preg_ functions
        // or strings that start/end with common delimiters.

        // Look for preg_match/all/replace/split calls
        // preg_match('pattern', ...)
        if (preg_match_all('/preg_(?:match(?:_all)?|replace(?:_callback)?|split|filter|grep)\s*\(\s*([\'"])(.*?)\1/s', $phpCode, $funcMatches)) {
            foreach ($funcMatches[2] as $idx => $match) {
                // Unescape the string if it was single quoted, but keep it raw if possible
                // For simplicity, we'll try to use the raw string content as the pattern
                // but we need to handle escaped quotes within the string.

                $quote = $funcMatches[1][$idx];
                $pattern = $match;

                // Very basic unescaping for the quote type used
                $pattern = str_replace('\\'.$quote, $quote, $pattern);

                // Check if it looks like a regex (starts and ends with same non-alphanumeric char)
                if (isValidPatternCandidate($pattern)) {
                    $patterns[] = $pattern;
                }
            }
        }

        // Also look for assignments like $pattern = '/.../';
        if (preg_match_all('/\$[a-zA-Z0-9_]+\s*=\s*([\'"])(.*?)\1\s*;/s', $phpCode, $assignMatches)) {
            foreach ($assignMatches[2] as $idx => $match) {
                $quote = $assignMatches[1][$idx];
                $pattern = $match;
                $pattern = str_replace('\\'.$quote, $quote, $pattern);

                if (isValidPatternCandidate($pattern)) {
                    $patterns[] = $pattern;
                }
            }
        }
    }
}

$patterns = array_unique($patterns);
$count = \count($patterns);

$phpContent = "<?php\n\nreturn ".var_export(array_values($patterns), true).";\n";
file_put_contents($outputFile, $phpContent);

echo "Extracted $count unique patterns to $outputFile\n";

function isValidPatternCandidate($str)
{
    if (\strlen($str) < 2) {
        return false;
    }

    $start = $str[0];
    $end = $str[\strlen($str) - 1];

    // Check for common delimiters
    $delimiters = ['/', '#', '~', '!', '%', '@', '`'];

    // If it ends with modifiers, the delimiter is before them
    if (preg_match('/^([\/#~!%@`]).*\1[a-zA-Z]*$/', $str)) {
        return true;
    }

    // Bracket delimiters
    if ('(' === $start && false !== strpos($str, ')')) {
        return true;
    }
    if ('{' === $start && false !== strpos($str, '}')) {
        return true;
    }
    if ('[' === $start && false !== strpos($str, ']')) {
        return true;
    }
    if ('<' === $start && false !== strpos($str, '>')) {
        return true;
    }

    return false;
}
