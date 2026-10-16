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

/**
 * The lines the inline markers of a PHP source silence: a comment holding
 * "@regex-ignore" silences its own last line, one holding
 * "@regex-ignore-next-line" the line after it. An occurrence is silenced
 * when its line is, the line of its literal: the declaration's for a
 * pattern the file proves.
 *
 * @internal
 */
final class InlineIgnores
{
    private const MARKERS = [
        '@regex-ignore-next-line' => 'next',
        '@regex-ignore' => 'same',
    ];

    /**
     * The silenced lines, one-based.
     *
     * @return array<int, bool>
     */
    public static function suppressedLines(string $content): array
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

        $suppressed = [];
        foreach (token_get_all($content) as $token) {
            if (!\is_array($token) || (\T_COMMENT !== $token[0] && \T_DOC_COMMENT !== $token[0])) {
                continue;
            }

            $mode = self::mode($token[1]);
            if (null === $mode) {
                continue;
            }

            $startLine = $token[2];
            $endLine = $startLine + substr_count($token[1], "\n");
            $suppressed['next' === $mode ? $endLine + 1 : $endLine] = true;
        }

        return $suppressed;
    }

    private static function mode(string $comment): ?string
    {
        foreach (self::MARKERS as $marker => $mode) {
            if (str_contains($comment, $marker)) {
                return $mode;
            }
        }

        return null;
    }
}
