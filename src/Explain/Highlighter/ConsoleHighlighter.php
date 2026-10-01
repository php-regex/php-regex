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

namespace PhpRegex\Explain\Highlighter;

/**
 * Highlights regex syntax for console output using ANSI escape codes.
 */
final class ConsoleHighlighter extends AbstractHighlighter
{
    private const RESET = "\033[0m";

    private const COLORS = [
        'meta' => "\033[38;2;86;156;214m",       // Blue
        'quantifier' => "\033[38;2;215;186;125m", // Gold
        'literal' => "\033[38;2;206;145;120m",   // Orange
        'anchor' => "\033[38;2;209;105;105m",    // Red
        'escape' => "\033[38;2;78;201;176m",     // Teal
        'backref' => "\033[38;2;156;220;254m",   // Light Blue
        'group' => "\033[38;2;197;134;192m",     // Purple
        'comment' => "\033[38;2;106;153;85m",    // Green
        'keyword' => "\033[38;2;220;220;170m",   // Yellow
        'flag' => "\033[38;2;181;206;168m",      // Light Green
        'identifier' => "\033[38;2;156;220;254m", // Light Blue
        'number' => "\033[38;2;181;206;168m",    // Light Green
    ];

    protected function wrap(string $content, string $type): string
    {
        if ('' === $content) {
            return '';
        }

        $color = self::COLORS[$type] ?? '';
        if ('' === $color) {
            return $content;
        }

        return $color.$content.self::RESET;
    }

    protected function escape(string $string): string
    {
        return $string;
    }
}
