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

namespace PHPRegex\Parser\Internal;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;

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

        // PHP skips the leading bytes isspace() accepts, NUL not included.
        $regex = Ascii::trimLeadingSpaces($regex);

        $len = \strlen($regex);
        if (0 === $len) {
            // No body, so no offset: offsets count from the body.
            throw new ParserException('Regex is too short. It must include delimiters, e.g. "/abc/".', ErrorCode::PatternEmpty);
        }

        // PHP judges the delimiter before the length, so a lone letter is
        // refused as a delimiter and a lone delimiter never closes.
        $delimiter = $regex[0];
        $readsNul = $phpVersionId >= PhpVersionGates::NUL_IN_PATTERN;
        if (!$readsNul && "\0" === $delimiter) {
            throw self::nulByte(0, $regex);
        }

        if (!self::isValidDelimiter($delimiter)) {
            $message = \sprintf(
                'Invalid delimiter "%s". Delimiters must not be alphanumeric, backslash, or NUL byte.',
                DisplayEscaper::escape($delimiter),
            );
            if ("\0" !== $delimiter) {
                $message .= \sprintf(' Try %s.', self::suggestPattern($regex));
            }

            throw new ParserException($message, ErrorCode::DelimiterInvalid);
        }

        if (1 === $len) {
            throw new ParserException('Regex is too short. It must include delimiters, e.g. "/abc/".', ErrorCode::DelimiterUnclosed);
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

        // Before PHP 8.2 the scan for the closing delimiter stops at a NUL.
        $nul = $readsNul ? false : strpos($regex, "\0", 1);
        if (false !== $nul && ([] === $candidates || $nul < $candidates[0])) {
            throw self::nulByte($nul - 1, $regex);
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
                    // PHP skips a space, "\n" and "\r" among the modifiers;
                    // any other whitespace is an unknown modifier.
                    $flags = str_replace([' ', "\n", "\r"], '', $flagsWithWhitespace);

                    // "n" arrived in PHP 8.2; "r" in PHP 8.4, which reads it
                    // only when built against PCRE2 10.43 or later; "e" left
                    // in PHP 7.0.
                    $allowedFlags = 'imsxADSUXJu'.($phpVersionId >= PhpVersionGates::NO_AUTO_CAPTURE_MODIFIER ? 'n' : '');
                    if ($phpVersionId >= PhpVersionGates::CASELESS_RESTRICT_MODIFIER && $target->supports(PcreFeature::CaselessRestrictModifier)) {
                        $allowedFlags .= 'r';
                    }
                    if ($phpVersionId < PhpVersionGates::EVAL_MODIFIER_REMOVED) {
                        $allowedFlags .= 'e';
                    }

                    // Validate flags (only allow standard PCRE flags)
                    // n = NO_AUTO_CAPTURE, r = PCRE2_EXTRA_CASELESS_RESTRICT (if supported)
                    // When the closing delimiter shows up again among the
                    // "flags", an unescaped one cut the pattern off early.
                    // Offsets count from the body; the snippet shows the
                    // pattern as written, one character further for the
                    // opening delimiter.
                    if (str_contains($flags, $closingDelimiter)) {
                        throw new ParserException(\sprintf(
                            'Unescaped delimiter "%1$s" at position %2$d ends the pattern early; what follows is read as modifiers. Escape it as "\\%1$s" or use another delimiter.',
                            $closingDelimiter,
                            $i - 1,
                        ), ErrorCode::DelimiterUnescaped, $i - 1, $regex, null, $i);
                    }

                    $allowedPattern = '/^['.preg_quote($allowedFlags, '/').']*+$/';
                    if (!LibraryPcre::match($allowedPattern, $flags)) {
                        // Find the invalid flag for a better error message
                        $invalid = LibraryPcre::replace('/['.preg_quote($allowedFlags, '/').']/', '', $flags);

                        // The first modifier PHP refuses, whitespace skipped.
                        $faultyFlag = strspn($flagsWithWhitespace, $allowedFlags." \n\r");
                        $flagPosition = $i + $faultyFlag;

                        if (!$readsNul && "\0" === ($flagsWithWhitespace[$faultyFlag] ?? '')) {
                            throw self::nulByte($flagPosition, $regex);
                        }

                        if (str_contains((string) $invalid, 'e')) {
                            throw new ParserException('The \'e\' flag (preg_replace /e) was removed in PHP 7.0; use preg_replace_callback() instead.', ErrorCode::FlagRemovedE, $flagPosition, $regex, null, $flagPosition + 1);
                        }

                        // Format each invalid flag individually with quotes
                        $formattedFlags = implode(', ', array_map(static fn (string $flag): string => \sprintf('"%s"', DisplayEscaper::escape($flag)), str_split($invalid ?? $flags)));

                        throw new ParserException(\sprintf('Unknown regex flag(s) found: %s', $formattedFlags), ErrorCode::FlagUnknown, $flagPosition, $regex, null, $flagPosition + 1);
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

    private static function nulByte(int $offset, string $regex): ParserException
    {
        return new ParserException(\sprintf('Null byte in regex at position %d: PHP before 8.2 refuses a NUL byte anywhere in a pattern; write it "\\0" or "\\x00".', $offset), ErrorCode::PatternNulByte, $offset, $regex, null, $offset + 1);
    }

    private static function isValidDelimiter(string $delimiter): bool
    {
        return !Ascii::isAlnum($delimiter)
            && "\0" !== $delimiter
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
