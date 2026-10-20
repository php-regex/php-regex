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
 * Console tags, for the bridges' reports: their console turns them into
 * colors, or drops them on an undecorated output. The text quoted is
 * escaped as Symfony Console's OutputFormatter::escape() escapes it, so the
 * report needs no console to be built.
 *
 * @internal
 */
final class TagMarkup implements ConsoleMarkup
{
    public function style(string $text, ConsoleStyle $style): string
    {
        return '<'.$style->tag().'>'.$text.'</>';
    }

    public function badge(ConsoleBadge $badge): string
    {
        return '<'.$badge->tag().';options=bold> '.$badge->label().' </>';
    }

    /**
     * "<" and ">" take a backslash, and trailing backslashes become NUL
     * bytes so they do not escape the closing tag that follows (the console
     * prints them back as backslashes).
     */
    public function escape(string $text): string
    {
        $text = str_replace(['<', '>'], ['\\<', '\\>'], $text);

        if (!str_ends_with($text, '\\')) {
            return $text;
        }

        $length = \strlen($text);
        $text = str_replace("\0", '', rtrim($text, '\\'));

        return $text.str_repeat("\0", $length - \strlen($text));
    }

    /**
     * One tag around the whole run.
     */
    public function muted(string ...$segments): string
    {
        return $this->style(implode(' ', $segments), ConsoleStyle::Muted);
    }
}
