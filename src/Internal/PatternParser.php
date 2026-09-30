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

use RegexParser\ErrorCode;
use RegexParser\Exception\ParserException;
use RegexParser\PcreFeature;
use RegexParser\PcreTarget;

/**
 * @internal
 */
final class PatternParser
{
    /**
     * @return array{0: string, 1: string, 2: string}
     */
    public static function extractPatternAndFlags(string $regex, ?PcreTarget $target = null): array
    {
        $target ??= PcreTarget::runtime();
        $phpVersionId = $target->phpVersionId;

        // Trim leading whitespace to match PHP's PCRE behavior
        $regex = ltrim($regex);

        $len = \strlen($regex);
        if ($len < 2) {
            // Nothing at all is an empty pattern; a lone delimiter never
            // closes, and a lone character that is no delimiter is refused
            // as one.
            $code = match (true) {
                0 === $len => ErrorCode::PatternEmpty,
                self::isValidDelimiter($regex) => ErrorCode::DelimiterUnclosed,
                default => ErrorCode::DelimiterInvalid,
            };

            // No body, so no offset: offsets count from the body.
            throw new ParserException('Regex is too short. It must include delimiters, e.g. "/abc/".', $code);
        }

        $delimiter = $regex[0];
        if (!self::isValidDelimiter($delimiter)) {
            $suggested = self::suggestPattern($regex);

            throw new ParserException(\sprintf(
                'Invalid delimiter "%s". Delimiters must not be alphanumeric, backslash, or whitespace. Try %s.',
                $delimiter,
                $suggested,
            ), ErrorCode::DelimiterInvalid);
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

                    // "n" arrived in PHP 8.2; "r" in PHP 8.4, which reads it
                    // only when built against PCRE2 10.43 or later; "e" left
                    // in PHP 7.0.
                    $allowedFlags = 'imsxADSUXJu'.($phpVersionId >= 80200 ? 'n' : '');
                    if ($phpVersionId >= 80400 && $target->supports(PcreFeature::CaselessRestrictModifier)) {
                        $allowedFlags .= 'r';
                    }
                    if ($phpVersionId < 70000) {
                        $allowedFlags .= 'e';
                    }

                    // Validate flags (only allow standard PCRE flags)
                    // n = NO_AUTO_CAPTURE, r = PCRE2_EXTRA_CASELESS_RESTRICT (if supported)
                    // When the closing delimiter shows up again among the
                    // "flags", an unescaped one cut the pattern off early.
                    // Offsets count from the body, and the snippet shows the
                    // text from the body on: the modifiers after it included.
                    $afterOpening = substr($regex, 1);
                    if (str_contains($flags, $closingDelimiter)) {
                        throw new ParserException(\sprintf(
                            'Unescaped delimiter "%1$s" at position %2$d ends the pattern early; what follows is read as modifiers. Escape it as "\\%1$s" or use another delimiter.',
                            $closingDelimiter,
                            $i - 1,
                        ), ErrorCode::DelimiterUnescaped, $i - 1, $afterOpening);
                    }

                    $allowedPattern = '/^['.preg_quote($allowedFlags, '/').']*+$/';
                    if (!preg_match($allowedPattern, $flags)) {
                        // Find the invalid flag for a better error message
                        $invalid = preg_replace('/['.preg_quote($allowedFlags, '/').']/', '', $flags);

                        // The first modifier PHP refuses, whitespace skipped.
                        $faultyFlag = strspn($flagsWithWhitespace, $allowedFlags." \t\n\r\v\f");
                        $flagPosition = $i + $faultyFlag;

                        if (str_contains((string) $invalid, 'e')) {
                            throw new ParserException('The \'e\' flag (preg_replace /e) was removed in PHP 7.0; use preg_replace_callback() instead.', ErrorCode::FlagRemovedE, $flagPosition, $afterOpening);
                        }

                        // Format each invalid flag individually with quotes
                        $formattedFlags = implode(', ', array_map(static fn (string $flag): string => \sprintf('"%s"', $flag), str_split($invalid ?? $flags)));

                        throw new ParserException(\sprintf('Unknown regex flag(s) found: %s', $formattedFlags), ErrorCode::FlagUnknown, $flagPosition, $afterOpening);
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
        ), ErrorCode::DelimiterUnclosed);
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

    private static function isValidDelimiter(string $delimiter): bool
    {
        return 1 === \strlen($delimiter)
            && !Ascii::isAlnum($delimiter)
            && !Ascii::isSpace($delimiter)
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
