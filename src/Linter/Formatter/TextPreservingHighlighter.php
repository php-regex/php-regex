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

namespace PHPRegex\Linter\Formatter;

use PHPRegex\Parser\Text\Ascii;

/**
 * A small highlighter that reads a pattern body as text, for a body the
 * parser cannot highlight: it knows escapes, character classes, groups,
 * anchors and quantifiers, enough to lay the pattern out, and never changes
 * a character, so the text shown is the one found in the source.
 *
 * @internal
 */
final class TextPreservingHighlighter
{
    public function __construct(private readonly ConsoleMarkup $markup) {}

    public function highlight(string $body): string
    {
        $length = \strlen($body);
        $out = '';
        $inClass = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            // An escape and the character it escapes, as one; a backslash
            // that ends the body stays as it is.
            if ('\\' === $char) {
                $out .= $i + 1 < $length ? $this->markup->style('\\'.$body[++$i], ConsoleStyle::Success) : '\\';

                continue;
            }

            // Inside a class the members are left as they are: only the
            // bracket that closes it is marked.
            if ($inClass) {
                $inClass = ']' !== $char;
                $out .= $inClass ? $char : $this->markup->style(']', ConsoleStyle::Accent);

                continue;
            }

            if ('{' === $char) {
                $end = self::countEnd($body, $i);
                if (null === $end) {
                    $out .= '{';
                } else {
                    $out .= $this->markup->style(substr($body, $i, $end - $i + 1), ConsoleStyle::Warning);
                    $i = $end;
                }

                continue;
            }

            $inClass = '[' === $char;
            $out .= match ($char) {
                '[', '(', ')', '|', '.', '^', '$' => $this->markup->style($char, ConsoleStyle::Accent),
                '+', '*', '?' => $this->markup->style($char, ConsoleStyle::Warning),
                default => $char,
            };
        }

        return $out;
    }

    /**
     * Where the count that opens at $start ends, "{2}", "{1,3}" or "{1,}";
     * null when the brace opens no count.
     */
    private static function countEnd(string $body, int $start): ?int
    {
        $length = \strlen($body);
        $j = self::digitsEnd($body, $start + 1);
        if ($j < $length && ',' === $body[$j]) {
            $j = self::digitsEnd($body, $j + 1);
        }

        return $j < $length && '}' === $body[$j] ? $j : null;
    }

    private static function digitsEnd(string $body, int $from): int
    {
        $length = \strlen($body);
        while ($from < $length && Ascii::isDigit($body[$from])) {
            $from++;
        }

        return $from;
    }
}
