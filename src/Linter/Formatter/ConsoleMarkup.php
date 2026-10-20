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
 * How a console report marks its text up: with ANSI codes for `regex lint`,
 * which writes to the terminal itself, or with console tags for the
 * bridges, whose console reads them.
 *
 * @internal
 */
interface ConsoleMarkup
{
    /**
     * The text in a style. The text is written as it is given: escape()
     * what came from outside first.
     */
    public function style(string $text, ConsoleStyle $style): string;

    public function badge(ConsoleBadge $badge): string;

    /**
     * Text from outside (a pattern, a message, a file name) as the markup
     * shows it literally.
     */
    public function escape(string $text): string;

    /**
     * Segments read as one muted run, a space between each.
     */
    public function muted(string ...$segments): string;
}
