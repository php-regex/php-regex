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

/**
 * ANSI codes, for a report written straight to the terminal; with colors
 * off, the text alone and each badge as its label, padded to four.
 *
 * @internal
 */
final class AnsiMarkup implements ConsoleMarkup
{
    private const RESET = "\033[0m";
    private const BOLD = "\033[1m";

    public function __construct(private readonly bool $ansi) {}

    public function style(string $text, ConsoleStyle $style): string
    {
        return $this->ansi ? $style->ansi().$text.self::RESET : $text;
    }

    public function badge(ConsoleBadge $badge): string
    {
        if (!$this->ansi) {
            return \sprintf('%-4s', $badge->label());
        }

        return $badge->ansi().self::BOLD.' '.$badge->label().' '.self::RESET;
    }

    /**
     * ANSI codes are never read in the text: it stays as it is.
     */
    public function escape(string $text): string
    {
        return $text;
    }

    /**
     * Each segment in codes of its own.
     */
    public function muted(string ...$segments): string
    {
        return implode(' ', array_map(fn (string $segment): string => $this->style($segment, ConsoleStyle::Muted), $segments));
    }
}
