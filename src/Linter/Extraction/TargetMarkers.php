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

use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Parser\Engine\LibraryPcre;

/**
 * The "@regex-target" markers of a PHP source, read from its tokens alike
 * by every tool: the lint, whatever reads its files, the PHPStan
 * extension, the Psalm plugin and the language server.
 *
 * "@regex-target php>=8.4" says the code it covers runs on PHP 8.4 and
 * later only: its patterns are judged for those versions alone. The
 * constraint is "php" then a Composer constraint, "pcre" then one, or both
 * in that order. A marker covers the last line of its comment;
 * "@regex-target-next-line" the line after it. In a doc comment that opens
 * its line, "@regex-target" covers the statement or the declaration after
 * the comment, attributes included: a class, a function, a method, a
 * constant, as far as its last line. A marker starts its comment, or a
 * line of a doc comment, as "@regex-ignore" does.
 *
 * @internal
 */
final class TargetMarkers
{
    private const NEXT_LINE = '@regex-target-next-line';

    /**
     * Bytes of memory the statement tables need per token: up to some 70
     * from PHP 8.2, 90 on 8.1, whose arrays take more per entry.
     */
    private const STATEMENT_TABLE_FACTOR = 96;

    /**
     * Every marker of the source, in the order of the source.
     *
     * @return list<TargetMarker>
     */
    public static function read(string $content): array
    {
        if ('' === $content || !str_contains($content, '@regex-target')) {
            return [];
        }

        // As for the ignore markers: a file too large to tokenize keeps none.
        if (!MemoryBudget::allows($content, MemoryBudget::TOKENIZE_FACTOR)) {
            return [];
        }

        $tokens = token_get_all($content);
        $statements = null;
        $markers = [];
        $offset = 0;
        foreach ($tokens as $index => $token) {
            $text = \is_array($token) ? $token[1] : $token;
            if (\is_array($token) && (\T_COMMENT === $token[0] || \T_DOC_COMMENT === $token[0])) {
                if (null === $statements && \T_DOC_COMMENT === $token[0] && str_contains($token[1], '@regex-target') && self::opensItsLine($tokens, $index)) {
                    // The tables that find where each statement ends: a file
                    // with room to be tokenized and not for them keeps none.
                    if (!MemoryBudget::allowsSize(\count($tokens), self::STATEMENT_TABLE_FACTOR)) {
                        return [];
                    }

                    $statements = self::statementRanges($tokens);
                }
                $statement = $statements[$index] ?? null;
                array_push($markers, ...self::markersIn($token[1], $token[2], $offset, $content, $statement));
            }
            $offset += \strlen($text);
        }

        return $markers;
    }

    /**
     * @param array{int, int}|null $statement the first and last line of the statement a doc comment stands before
     *
     * @return list<TargetMarker>
     */
    private static function markersIn(string $comment, int $startLine, int $commentOffset, string $content, ?array $statement): array
    {
        if (!LibraryPcre::matchAll('~(?:\A(?://|#|/\*\*?)|\n[ \t]*\*?)[ \t]*\K@regex-target(?:-next-line)?(?![\w-])~', $comment, $found, \PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $endLine = $startLine + substr_count($comment, "\n");
        $markers = [];
        foreach ($found[0] as [$marker, $at]) {
            $offset = $commentOffset + $at;
            $lineStart = strrpos($content, "\n", $offset - \strlen($content));
            [$fromLine, $toLine] = match (true) {
                self::NEXT_LINE === $marker => [$endLine + 1, $endLine + 1],
                null !== $statement => $statement,
                default => [$endLine, $endLine],
            };
            $text = self::textAfter($comment, $at + \strlen($marker));
            $constraint = TargetConstraint::fromMarker($text);
            $markers[] = new TargetMarker(
                $marker,
                $startLine + substr_count($comment, "\n", 0, $at),
                $fromLine,
                $toLine,
                $text,
                $constraint,
                null === $constraint ? \sprintf('The %s marker holds "%s", which names no PHP or PCRE2 version constraint: write "@regex-target php>=8.4", "@regex-target pcre>=10.43" or "@regex-target php>=8.4 pcre>=10.43".', $marker, $text) : null,
                $offset,
                false === $lineStart ? $offset + 1 : $offset - $lineStart,
            );
        }

        return $markers;
    }

    /**
     * What follows the marker on its line of the comment, without the end
     * of the comment.
     */
    private static function textAfter(string $comment, int $from): string
    {
        $end = strpos($comment, "\n", $from);
        $text = false === $end ? substr($comment, $from) : substr($comment, $from, $end - $from);
        if (str_ends_with(rtrim($text), '*/')) {
            $text = substr(rtrim($text), 0, -2);
        }

        return trim($text);
    }

    /**
     * Whether only whitespace stands before the token on its line.
     *
     * @param list<string|array{int, string, int}> $tokens
     */
    private static function opensItsLine(array $tokens, int $index): bool
    {
        // A comment is never the first token: the open tag comes first.
        $before = $tokens[$index - 1] ?? '';
        if (!\is_array($before)) {
            return false;
        }

        return \T_OPEN_TAG === $before[0] || (\T_WHITESPACE === $before[0] && (str_contains($before[1], "\n") || \T_OPEN_TAG === (\is_array($tokens[$index - 2] ?? null) ? $tokens[$index - 2][0] : null)));
    }

    /**
     * Resolve statement ranges once, including shared suffixes and nested
     * bodies. Matching delimiters lets an outer statement skip its body;
     * the reverse pass reuses the range after each balanced group.
     *
     * @param list<string|array{int, string, int}> $tokens
     *
     * @return array<int, array{int, int}>
     */
    private static function statementRanges(array $tokens): array
    {
        $count = \count($tokens);
        $lines = [];
        $closing = [];
        $stack = [];
        $line = 1;
        // A delimiter is a token of its own: the same character in inline
        // HTML or in the text of a string is no code.
        foreach ($tokens as $i => $token) {
            $text = \is_array($token) ? $token[1] : $token;
            $line = \is_array($token) ? $token[2] : $line;
            $lines[$i] = $line;
            $line += substr_count($text, "\n");
            if (\is_array($token) ? \in_array($token[0], [\T_ATTRIBUTE, \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true) : \in_array($token, ['(', '[', '{'], true)) {
                $stack[] = $i;
            } elseif (\in_array($token, [')', ']', '}'], true) && [] !== $stack) {
                // Like the original depth counter, pair by depth even in
                // incomplete or malformed source, without requiring a type match.
                $closing[array_pop($stack)] = $i;
            }
        }

        $ranges = [];
        // Filled from the end: an array written from its last key down is a
        // hash, three times the memory of a fixed array.
        /** @var \SplFixedArray<int> $ends the last line of the statement each token starts */
        $ends = new \SplFixedArray($count);
        /** @var \SplFixedArray<int|null> $after the token after each closing one */
        $after = new \SplFixedArray($count);
        $next = null;
        $lastLine = null;
        for ($i = $count - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                if (\T_DOC_COMMENT === $token[0] && null !== $next && str_contains($token[1], '@regex-target') && self::opensItsLine($tokens, $i)) {
                    // Every token past $next has its end: the fixed array only says it may be null.
                    $ranges[$i] = [$lines[$next], $ends[$next] ?? $lines[$next]];
                }

                continue;
            }

            $lastLine ??= $lines[$i];
            $end = null === $next ? $lastLine : $ends[$next];
            if (\in_array($token, [';', ')', ']', '}'], true)) {
                $end = $lines[$i];
                $after[$i] = $next;
            } elseif (isset($closing[$i])) {
                $close = $closing[$i];
                $following = $after[$close];
                $continues = null !== $following && (\is_array($tokens[$following])
                    ? \in_array($tokens[$following][0], [\T_ELSE, \T_ELSEIF, \T_CATCH, \T_FINALLY], true)
                    : \in_array($tokens[$following], [';', ')', ',', '(', '-', '.'], true));
                // A body opens on "{"; "{$" and "${" open a variable in a string.
                $end = ('{' === $token && !$continues) || null === $following
                    ? $lines[$close] : $ends[$following];
            } elseif (\is_array($token) ? \in_array($token[0], [\T_ATTRIBUTE, \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true) : \in_array($token, ['(', '[', '{'], true)) {
                $end = $lastLine;
            }
            $ends[$i] = $end;
            $next = $i;
        }

        return $ranges;
    }
}
