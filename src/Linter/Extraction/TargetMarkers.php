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
use PHPRegex\Parser\Internal\LibraryPcre;

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
        $markers = [];
        $offset = 0;
        foreach ($tokens as $index => $token) {
            $text = \is_array($token) ? $token[1] : $token;
            if (\is_array($token) && (\T_COMMENT === $token[0] || \T_DOC_COMMENT === $token[0])) {
                $statement = \T_DOC_COMMENT === $token[0] && self::opensItsLine($tokens, $index) ? self::statementAfter($tokens, $index) : null;
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
     * The first and last line of the statement after the token: from its
     * first token past comments and attributes, to the ";" that ends it at
     * its own depth, or to the "}" of a body it opens, "else", "elseif",
     * "catch" and "finally" going on with it. Null when no statement
     * follows.
     *
     * @param list<string|array{int, string, int}> $tokens
     *
     * @return array{int, int}|null
     */
    private static function statementAfter(array $tokens, int $index): ?array
    {
        $count = \count($tokens);
        $fromLine = null;
        $last = null;
        $depth = 0;
        // A string token carries no line: it starts where the token
        // before it ends.
        $comment = $tokens[$index];
        $next = \is_array($comment) ? $comment[2] + substr_count($comment[1], "\n") : 0;
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = \is_array($token) ? $token[1] : $token;
            $line = \is_array($token) ? $token[2] : $next;
            $next = $line + substr_count($text, "\n");
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $fromLine ??= $line;
            $last = $line;

            if (\is_array($token) && \in_array($token[0], [\T_ATTRIBUTE, \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;

                continue;
            }

            if ('(' === $text || '[' === $text || '{' === $text) {
                $depth++;

                continue;
            }

            if (')' === $text || ']' === $text || '}' === $text) {
                $depth--;
                if ($depth < 0) {
                    // The end of the block holding the statement.
                    return null === $fromLine || $fromLine > $line ? null : [$fromLine, $line];
                }

                if (0 === $depth && '}' === $text && !self::goesOn($tokens, $i)) {
                    return [$fromLine, $line];
                }

                continue;
            }

            if (0 === $depth && ';' === $text) {
                return [$fromLine, $line];
            }
        }

        // The end of the file: the statement runs to its last token.
        return null === $fromLine ? null : [$fromLine, $last ?? $fromLine];
    }

    /**
     * Whether the statement goes on after the "}" at $index: an "else",
     * "elseif", "catch" or "finally", or the ";" or the call of an
     * expression the body was part of.
     *
     * @param list<string|array{int, string, int}> $tokens
     */
    private static function goesOn(array $tokens, int $index): bool
    {
        for ($i = $index + 1, $count = \count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return \is_array($token)
                ? \in_array($token[0], [\T_ELSE, \T_ELSEIF, \T_CATCH, \T_FINALLY], true)
                : \in_array($token, [';', ')', ',', '(', '-', '.'], true);
        }

        return false;
    }
}
