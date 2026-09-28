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

namespace RegexParser\Internal;

use RegexParser\Exception\ParserException;

/**
 * @internal
 */
final class PatternParser
{
    /**
     * @var array<int|string, bool>
     */
    private static array $supportsModifierR = [];

    /**
     * @var array<int|string, bool>
     */
    private static array $supportsModifierE = [];

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    public static function extractPatternAndFlags(string $regex, ?int $phpVersionId = null): array
    {
        // Trim leading whitespace to match PHP's PCRE behavior
        $regex = ltrim($regex);

        $len = \strlen($regex);
        if ($len < 2) {
            throw new ParserException('Regex is too short. It must include delimiters, e.g. "/abc/".', 0, $regex);
        }

        $delimiter = $regex[0];
        if (!self::isValidDelimiter($delimiter)) {
            $suggested = self::suggestPattern($regex);

            throw new ParserException(\sprintf(
                'Invalid delimiter "%s". Delimiters must not be alphanumeric, backslash, or whitespace. Try %s.',
                $delimiter,
                $suggested,
            ));
        }
        // Handle bracket delimiters style: (pattern), [pattern], {pattern}, <pattern>
        $closingDelimiter = self::closingDelimiter($delimiter);

        // For bracket-style delimiters, PHP requires balanced nesting: the
        // pattern ends at the closer matching the opening bracket, so
        // "{a{b}" has no ending delimiter. Scan forward, tracking depth.
        if ($delimiter !== $closingDelimiter) {
            $endIndex = null;
            $depth = 1;
            for ($k = 1; $k < $len; $k++) {
                $ch = $regex[$k];
                if ('\\' === $ch) {
                    $k++;

                    continue;
                }
                if ($ch === $delimiter) {
                    $depth++;
                } elseif ($ch === $closingDelimiter && 0 === --$depth) {
                    $endIndex = $k;

                    break;
                }
            }

            $candidates = null === $endIndex ? [] : [$endIndex];
        } else {
            // PHP ends the pattern at the first delimiter a backslash does not
            // escape, whatever construct it sits in: a class, a comment or a
            // \Q...\E run does not hide it, so "/[/]/" has the flags "]/".
            $candidates = [];
            for ($k = 1; $k < $len; $k++) {
                if ('\\' === $regex[$k]) {
                    $k++;

                    continue;
                }
                if ($regex[$k] === $closingDelimiter) {
                    $candidates = [$k];

                    break;
                }
            }
        }

        foreach ($candidates as $i) {
            if ($regex[$i] === $closingDelimiter) {
                // Check if escaped (count odd number of backslashes before it)
                $escapes = 0;
                for ($j = $i - 1; $j > 0 && '\\' === $regex[$j]; $j--) {
                    $escapes++;
                }

                if (0 === $escapes % 2) {
                    // Found the end delimiter
                    $pattern = substr($regex, 1, $i - 1);
                    $flagsWithWhitespace = substr($regex, $i + 1);
                    $flags = preg_replace('/\s+/', '', $flagsWithWhitespace) ?? '';

                    $allowedFlags = 'imsxADSUXJun';
                    if (self::supportsModifierR($phpVersionId)) {
                        $allowedFlags .= 'r';
                    }
                    if (self::supportsModifierE($phpVersionId)) {
                        $allowedFlags .= 'e';
                    }

                    // Validate flags (only allow standard PCRE flags)
                    // n = NO_AUTO_CAPTURE, r = PCRE2_EXTRA_CASELESS_RESTRICT (if supported)
                    $allowedPattern = '/^['.preg_quote($allowedFlags, '/').']*+$/';
                    if (!preg_match($allowedPattern, $flags)) {
                        // Find the invalid flag for a better error message
                        $invalid = preg_replace('/['.preg_quote($allowedFlags, '/').']/', '', $flags);

                        $flagsPosition = \strlen($regex) - \strlen($flags);

                        if (str_contains((string) $invalid, 'e')) {
                            throw new ParserException('The \'e\' flag (preg_replace /e) was removed in PHP 7.0; use preg_replace_callback() instead.', $flagsPosition, $regex);
                        }

                        // Format each invalid flag individually with quotes
                        $formattedFlags = implode(', ', array_map(static fn (string $flag): string => \sprintf('"%s"', $flag), str_split($invalid ?? $flags)));

                        throw new ParserException(\sprintf('Unknown regex flag(s) found: %s', $formattedFlags), $flagsPosition, $regex);
                    }

                    return [$pattern, $flags, $delimiter];
                }
            }
        }

        $pattern = substr($regex, 1);
        $suggested = self::suggestPattern($pattern, $delimiter);

        throw new ParserException(\sprintf(
            'No closing delimiter "%s" found. You opened with "%s"; expected closing "%s". Tip: escape "%s" inside the pattern (\\%s) or use a different delimiter, e.g. %s.',
            $closingDelimiter,
            $delimiter,
            $closingDelimiter,
            $closingDelimiter,
            $closingDelimiter,
            $suggested,
        ));
    }

    public static function closingDelimiter(string $delimiter): string
    {
        return match ($delimiter) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            '<' => '>',
            default => $delimiter,
        };
    }

    private static function supportsModifierR(?int $phpVersionId = null): bool
    {
        $key = $phpVersionId ?? 'runtime';
        if (\array_key_exists($key, self::$supportsModifierR)) {
            return self::$supportsModifierR[$key];
        }

        if (null === $phpVersionId) {
            $modifier = \chr(114);
            $pattern = '/a/'.$modifier;
            $result = @preg_match($pattern, '');
            self::$supportsModifierR[$key] = false !== $result;

            return self::$supportsModifierR[$key];
        }

        self::$supportsModifierR[$key] = $phpVersionId >= 80400;

        return self::$supportsModifierR[$key];
    }

    /**
     * Check if the 'e' modifier (PREG_REPLACE_EVAL) is supported.
     * The 'e' modifier was removed in PHP 7.0, so it's only valid for PHP < 7.0.
     */
    private static function supportsModifierE(?int $phpVersionId = null): bool
    {
        $key = $phpVersionId ?? 'runtime';
        if (\array_key_exists($key, self::$supportsModifierE)) {
            return self::$supportsModifierE[$key];
        }

        if (null === $phpVersionId) {
            // At runtime, we're always on PHP 7.0+, so 'e' is never supported
            // @phpstan-ignore-next-line smaller.alwaysFalse
            self::$supportsModifierE[$key] = \PHP_VERSION_ID < 70000;

            return self::$supportsModifierE[$key];
        }

        self::$supportsModifierE[$key] = $phpVersionId < 70000;

        return self::$supportsModifierE[$key];
    }

    private static function isValidDelimiter(string $delimiter): bool
    {
        return 1 === \strlen($delimiter)
            && !ctype_alnum($delimiter)
            && !ctype_space($delimiter)
            && '\\' !== $delimiter;
    }

    private static function suggestPattern(string $pattern, ?string $avoidDelimiter = null): string
    {
        $delimiter = str_contains($pattern, '#') && !str_contains($pattern, '~') ? '~' : '#';
        if (null !== $avoidDelimiter && $delimiter === $avoidDelimiter) {
            $delimiter = '#' === $delimiter ? '~' : '#';
        }
        $escaped = str_replace($delimiter, '\\'.$delimiter, $pattern);

        return $delimiter.$escaped.$delimiter;
    }
}
