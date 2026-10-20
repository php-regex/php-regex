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

namespace PHPRegex\Linter\Extraction;

use PHPRegex\Parser\Engine\LibraryPcre;

/**
 * The string a constant expression of the source folds to, read from its
 * tokens: string literals, heredocs and nowdocs, joined by "." and wrapped
 * in parentheses, each decoded as PHP decodes it.
 *
 * A pattern argument, an array item and an option key are all such
 * expressions; reading them in one place gives each the same value and the
 * same position, the first token's, or the opening parenthesis's as
 * php-parser places it. Anything else, a variable, a constant, a call or an
 * interpolation, is no constant string here: the value collector follows
 * those.
 *
 * @internal
 */
final class StringExpressionReader
{
    /**
     * Parse a regex expression, handling patterns with flags.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $tokenIndexes
     * @param array<int, int>                            $tokenOffsets
     *
     * @return array{pattern: string, line: int, offset?: int|null, column?: int|null}|null
     */
    public static function readPattern(array $tokens, array $tokenIndexes, array $tokenOffsets, string $content): ?array
    {
        $result = self::read($tokens, $tokenIndexes, $tokenOffsets, $content);
        if (null === $result) {
            return null;
        }

        $pattern = $result['pattern'];

        // Check if this looks like a regex with flags (e.g., "/pattern/m" or "{pattern}u")
        // Need to handle escaped delimiters in the string
        if (LibraryPcre::match('/^([\'"{}\/#~%])(.*?)([\'"{}\/#~%])([A-Za-z]*)\z/', $pattern, $matches)) {
            $delimiter = $matches[1];
            $regexBody = $matches[2];
            $flags = $matches[4];

            // The pattern body returned from parseConstantStringExpression()
            // has already been decoded from the PHP string literal. Avoid
            // running stripslashes() again here, which would incorrectly
            // drop significant escapes like \\d, \\w, or \\x7f.
            $closingDelimiter = '{' === $delimiter ? '}' : $delimiter;

            // A pattern whose closing delimiter does not match its opening
            // delimiter (e.g. "/foo#") is broken at runtime; do not "repair"
            // it here — fall back so the raw pattern is validated as-is and
            // the delimiter error surfaces in the lint report.
            if ($matches[3] !== $closingDelimiter) {
                return null;
            }

            // Reconstruct the pattern with flags preserved, using the proper
            // closing delimiter for bracket-style delimiters.
            $fullPattern = $delimiter.$regexBody.$closingDelimiter.$flags;

            return [
                'pattern' => $fullPattern,
                'line' => $result['line'],
                'offset' => $result['offset'] ?? null,
                'column' => $result['column'] ?? null,
            ];
        }

        // Not a regex with flags, let the caller fall back to plain string parsing.
        return null;
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $tokenIndexes
     * @param array<int, int>                            $tokenOffsets
     *
     * @return array{pattern: string, line: int, offset?: int|null, column?: int|null}|null
     */
    public static function read(array $tokens, array $tokenIndexes, array $tokenOffsets, string $content): ?array
    {
        $parts = [];
        $firstLine = null;
        $firstTokenOffset = null;
        $firstTokenColumn = null;
        $expectString = true;
        // The opening token and raw body of the heredoc being read, if any.
        $heredoc = null;

        foreach ($tokens as $index => $token) {
            if (null !== $heredoc) {
                if (\is_array($token) && \T_ENCAPSED_AND_WHITESPACE === $token[0]) {
                    $heredoc[1] .= $token[1];

                    continue;
                }

                // Anything else between the markers is interpolation.
                if (!\is_array($token) || \T_END_HEREDOC !== $token[0]) {
                    return null;
                }

                $decoded = PhpStringLiteral::decodeHeredoc($heredoc[0], $heredoc[1], $token[1]);
                if (null === $decoded) {
                    return null;
                }

                $parts[] = $decoded;
                $heredoc = null;
                $expectString = false;

                continue;
            }

            if (PhpTokens::isIgnorable($token)) {
                continue;
            }

            if ('(' === $token || ')' === $token) {
                continue;
            }

            if ($expectString) {
                $isString = \is_array($token) && \T_CONSTANT_ENCAPSED_STRING === $token[0];
                $opensHeredoc = \is_array($token) && \T_START_HEREDOC === $token[0];
                if (!$isString && !$opensHeredoc) {
                    return null;
                }

                $firstLine ??= $token[2];
                if (null === $firstTokenOffset) {
                    $tokenIndex = $tokenIndexes[$index] ?? null;
                    if (\is_int($tokenIndex) && isset($tokenOffsets[$tokenIndex])) {
                        $firstTokenOffset = $tokenOffsets[$tokenIndex];
                        $firstTokenColumn = PendingPattern::column($content, $firstTokenOffset);
                    }
                }

                if ($opensHeredoc) {
                    $heredoc = [$token[1], ''];

                    continue;
                }

                $parts[] = PhpTokens::stringValue($token[1]);
                $expectString = false;

                continue;
            }

            if ('.' === $token) {
                $expectString = true;

                continue;
            }

            return null;
        }

        if ($expectString || null === $firstLine) {
            return null;
        }

        // ('/a/') . 'i' starts at its parenthesis, as php-parser places it.
        $lead = PhpTokens::nextSignificant($tokens, 0, \count($tokens));
        $leadOffset = '(' === $tokens[$lead ?? 0] ? ($tokenOffsets[$tokenIndexes[$lead] ?? -1] ?? null) : null;
        if (null !== $leadOffset && null !== $firstTokenOffset) {
            $firstLine -= substr_count($content, "\n", $leadOffset, $firstTokenOffset - $leadOffset);
            $firstTokenOffset = $leadOffset;
            $firstTokenColumn = PendingPattern::column($content, $leadOffset);
        }

        $pattern = implode('', $parts);

        return [
            'pattern' => $pattern,
            'line' => $firstLine,
            'offset' => $firstTokenOffset,
            'column' => $firstTokenColumn,
        ];
    }

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $tokenIndexes
     *
     * @return array{0: array<int, array{int, string, int}|string>, 1: array<int, int>}
     */
    public static function stripParentheses(array $tokens, array $tokenIndexes): array
    {
        $totalTokens = \count($tokens);
        if (0 === $totalTokens) {
            return [$tokens, $tokenIndexes];
        }

        while (true) {
            $startIndex = PhpTokens::nextSignificant($tokens, 0, $totalTokens);
            $endIndex = PhpTokens::previousSignificant($tokens, $totalTokens - 1);

            if (null === $startIndex || null === $endIndex) {
                break;
            }

            if ('(' !== $tokens[$startIndex] || ')' !== $tokens[$endIndex]) {
                break;
            }

            $depth = 0;
            $wrapsAll = true;
            for ($i = $startIndex; $i <= $endIndex; $i++) {
                $token = $tokens[$i];
                if ('(' === $token) {
                    $depth++;
                } elseif (')' === $token) {
                    $depth--;
                    if (0 === $depth && $i < $endIndex) {
                        $wrapsAll = false;

                        break;
                    }
                }
            }

            if (!$wrapsAll || 0 !== $depth) {
                break;
            }

            $tokens = \array_slice($tokens, $startIndex + 1, $endIndex - $startIndex - 1);
            $tokenIndexes = \array_slice($tokenIndexes, $startIndex + 1, $endIndex - $startIndex - 1);
            $totalTokens = \count($tokens);
        }

        return [$tokens, $tokenIndexes];
    }
}
