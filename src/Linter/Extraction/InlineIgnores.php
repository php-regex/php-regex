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

use PHPRegex\Parser\Internal\LibraryPcre;

/**
 * The inline markers of a PHP source: "@regex-ignore" silences the last
 * line of its comment, "@regex-ignore-next-line" the line after it. A
 * marker starts its comment, or a line of a block comment. An occurrence
 * is silenced when its line is, the line of its literal: the
 * declaration's for a pattern the file proves.
 *
 * A marker may name the issues it silences, comma-separated, right after
 * it: "@regex-ignore regex.lint.quantifier.nested, regex.lint.redos". What
 * follows that list, or a marker that names none, is a note. A marker that
 * names no issue silences every issue of its line.
 *
 * @internal
 */
final class InlineIgnores
{
    private const NEXT_LINE = '@regex-ignore-next-line';

    /**
     * An identifier: "regex." then dot-separated words.
     */
    private const IDENTIFIER = '[ \t]*(regex\.[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*)';

    /**
     * The lines every issue of which is silenced, one-based: those of the
     * markers that name no issue.
     *
     * @return array<int, bool>
     */
    public static function suppressedLines(string $content): array
    {
        $suppressed = [];
        foreach (self::markers($content) as $marker) {
            if ([] === $marker->identifiers) {
                $suppressed[$marker->coveredLine] = true;
            }
        }

        return $suppressed;
    }

    /**
     * Every marker of the source, in the order of the source.
     *
     * @return list<IgnoreMarker>
     */
    public static function markers(string $content): array
    {
        // A source holding no marker silences nothing, and is not tokenized.
        if ('' === $content || !str_contains($content, '@regex-ignore')) {
            return [];
        }

        // Reading a huge generated file into tokens can exhaust the memory
        // limit, and a fatal error here would lose every result collected so
        // far. Such a file simply keeps no suppression markers.
        if (!MemoryBudget::allows($content, MemoryBudget::TOKENIZE_FACTOR)) {
            return [];
        }

        $markers = [];
        $offset = 0;
        foreach (token_get_all($content) as $token) {
            $text = \is_array($token) ? $token[1] : $token;
            if (\is_array($token) && (\T_COMMENT === $token[0] || \T_DOC_COMMENT === $token[0])) {
                array_push($markers, ...self::markersIn($token[1], $token[2], $offset, $content));
            }
            $offset += \strlen($text);
        }

        return $markers;
    }

    /**
     * The markers of one comment: each starts the comment, after "//", "#",
     * "/*" or "/**", or a line of it, after the "*" that may open the line.
     * A marker met in the middle of a sentence, or quoted, is prose about
     * the markers.
     *
     * @return list<IgnoreMarker>
     */
    private static function markersIn(string $comment, int $startLine, int $commentOffset, string $content): array
    {
        if (!LibraryPcre::matchAll('~(?:\A(?://|#|/\*\*?)|\n[ \t]*\*?)[ \t]*\K@regex-ignore(?:-next-line)?(?![\w-])~', $comment, $found, \PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $endLine = $startLine + substr_count($comment, "\n");
        $markers = [];
        foreach ($found[0] as [$marker, $at]) {
            $offset = $commentOffset + $at;
            $lineStart = strrpos($content, "\n", $offset - \strlen($content));
            $markers[] = new IgnoreMarker(
                $marker,
                $startLine + substr_count($comment, "\n", 0, $at),
                self::NEXT_LINE === $marker ? $endLine + 1 : $endLine,
                self::identifiers($comment, $at + \strlen($marker)),
                $offset,
                false === $lineStart ? $offset + 1 : $offset - $lineStart,
            );
        }

        return $markers;
    }

    /**
     * The identifiers listed right after a marker, comma-separated.
     *
     * @return list<string>
     */
    private static function identifiers(string $comment, int $from): array
    {
        // The list starts after a space, never glued to the marker.
        if (1 !== LibraryPcre::match('/\G[ \t]/', $comment, $space, 0, $from)) {
            return [];
        }

        $identifiers = [];
        $pattern = '/\G'.self::IDENTIFIER.'/';
        while (1 === LibraryPcre::match($pattern, $comment, $match, 0, $from)) {
            $identifiers[] = $match[1];
            $from += \strlen($match[0]);
            $pattern = '/\G[ \t]*,'.self::IDENTIFIER.'/';
        }

        return $identifiers;
    }
}
