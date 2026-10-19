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

namespace PHPRegex\Linter\Advice;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Validation\ValidationResult;

/**
 * What a lint issue on an invalid pattern advises: a fix written for the
 * pattern where its error allows one, else a tip on the error.
 *
 * @internal
 */
final class ValidationTips
{
    /**
     * The tip of an invalid pattern: one written for the pattern when its
     * error allows, else one for the error alone, else none.
     */
    public static function forError(string $message, string $pattern, ValidationResult $validation): ?string
    {
        // Try to provide intelligent, pattern-specific tips
        $intelligentTip = self::patternTip($message, $pattern, $validation);
        if (null !== $intelligentTip) {
            return $intelligentTip;
        }

        // Fallback to generic tips
        return self::errorTip($message);
    }

    private static function patternTip(string $message, string $pattern, ValidationResult $validation): ?string
    {
        if (str_contains($message, 'No closing delimiter')) {
            return self::delimiterFix($pattern);
        }

        if (str_contains($message, 'Unclosed character class')) {
            return self::characterClassFix($pattern);
        }

        if (str_contains($message, 'Invalid quantifier range')) {
            return self::quantifierRangeFix($pattern);
        }

        if (str_contains($message, 'Backreference to non-existent group')) {
            return self::backreferenceFix($pattern);
        }

        if (str_contains($message, 'Lookbehind is unbounded')) {
            return self::lookbehindFix($pattern, $validation);
        }

        return null;
    }

    private static function delimiterFix(string $pattern): string
    {
        // Find the delimiter used
        if (!LibraryPcre::match('/^([#~\-%@!])(.*)$/', $pattern, $matches)) {
            $matches = ['', '/', $pattern];
        }

        $delimiter = $matches[1];
        $content = $matches[2];

        // Check if delimiter appears in content
        if (str_contains($content, $delimiter)) {
            return "Your pattern contains the delimiter '$delimiter' inside. Either escape it as \\$delimiter or use a different delimiter like #pattern#.";
        }

        // Missing closing delimiter
        $suggested = $pattern.$delimiter;

        return "Add the missing closing delimiter: $suggested";
    }

    private static function characterClassFix(string $pattern): ?string
    {
        // For patterns like /[a-z/ we need to add ] before the final delimiter
        if (str_contains($pattern, '[') && !str_contains($pattern, ']')) {
            // Find the last delimiter
            $lastDelimiterPos = strrpos($pattern, '/');
            if (false !== $lastDelimiterPos) {
                $suggested = substr_replace($pattern, ']', $lastDelimiterPos, 0);

                return "Add missing closing bracket: $suggested";
            }
        }

        return null;
    }

    private static function quantifierRangeFix(string $pattern): ?string
    {
        // Look for quantifier ranges in the pattern
        if (LibraryPcre::match('/\{(\d+),(\d+)\}/', $pattern, $matches)) {
            $min = (int) $matches[1];
            $max = (int) $matches[2];

            if ($min > $max) {
                $fixed = '{'.$max.','.$min.'}';
                $suggested = str_replace($matches[0], $fixed, $pattern);

                return "Swap min and max values: $suggested";
            }
        }

        return null;
    }

    private static function backreferenceFix(string $pattern): ?string
    {
        // Find all backreferences in the pattern
        if (LibraryPcre::matchAll('/\\\\(\d+)/', $pattern, $matches)) {
            // Count opening parentheses (capturing groups)
            $openCount = substr_count($pattern, '(');

            foreach ($matches[1] as $match) {
                $refNum = (int) $match;
                if ($refNum > $openCount) {
                    return "Backreference \\$refNum refers to group $refNum, but only $openCount capturing groups exist in the pattern. Valid backreferences are \\1 through \\$openCount.";
                }
            }
        }

        return null;
    }

    private static function lookbehindFix(string $pattern, ValidationResult $validation): ?string
    {
        $offset = $validation->offset ?? 0;

        // Find the lookbehind content
        $before = substr($pattern, 0, $offset);
        $lookbehindStart = strrpos($before, '(?<=');

        if (false === $lookbehindStart) {
            $lookbehindStart = strrpos($before, '(?<!');
        }

        if (false === $lookbehindStart) {
            return null;
        }

        $lookbehindContent = substr($pattern, $lookbehindStart, $offset - $lookbehindStart);

        // Check for unbounded quantifiers in lookbehind
        if (LibraryPcre::match('/[+*][?]?/', $lookbehindContent)) {
            return "Replace unbounded quantifiers in lookbehind with fixed-length alternatives. For example, change (?<=\w*) to (?<=\w{0,10}) with an appropriate maximum length.";
        }

        return null;
    }

    private static function errorTip(string $message): ?string
    {
        if (str_contains($message, 'Unclosed character class')) {
            return 'Character classes must be closed with "]". Check for missing or extra "[".';
        }

        if (str_contains($message, 'Invalid quantifier range')) {
            return 'Quantifier ranges must have min <= max. For example, {3,2} is invalid; use {2,3} or {2} instead.';
        }

        if (str_contains($message, 'Unknown regex flag')) {
            return 'Only valid PCRE flags are: i (case-insensitive), m (multiline), s (dot matches newline), x (extended), U (ungreedy), J (duplicate names).';
        }

        if (str_contains($message, 'Backreference to non-existent group')) {
            return 'Backreferences like \\1 refer to capturing groups. Make sure the group number exists.';
        }

        if (str_contains($message, 'Lookbehind is unbounded')) {
            return 'Variable-length lookbehinds are not allowed in PCRE. Use fixed-length alternatives like (?<=\w{3}) instead of (?<=\w*).';
        }

        if (str_contains($message, 'Invalid conditional construct')) {
            return 'Conditionals need a valid condition: group reference (?(1)...), lookaround (?(?=...)...), or (?(DEFINE)...).';
        }

        return null;
    }
}
