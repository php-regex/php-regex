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

namespace PHPRegex\Symfony\Output;

use PHPRegex\Parser\Printer\DisplayEscaper;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * The parts of a line the bundle's reports write alike; the lines every
 * bridge writes alike are Linter\Console\ConsoleWriter's.
 *
 * @internal
 */
final class ConsoleWriter
{
    /**
     * A "label : value" line of a report's header, the labels padded to
     * the longest.
     */
    public static function metaLine(string $label, string $value, int $maxLabelLength): string
    {
        return \sprintf(
            '<fg=white;options=bold>%s</> : <fg=yellow>%s</>',
            self::text(str_pad($label, $maxLabelLength)),
            self::text($value),
        );
    }

    /**
     * A pattern with layout controls and console tags escaped; an empty one as a pair
     * of quotes.
     */
    public static function pattern(string $pattern): string
    {
        if ('' === $pattern) {
            return '<fg=cyan>""</>';
        }

        return '<fg=cyan>'.OutputFormatter::escape(DisplayEscaper::escape($pattern)).'</>';
    }

    /**
     * Plain report text, with layout controls and console markup escaped.
     */
    public static function text(string $text): string
    {
        return OutputFormatter::escape(DisplayEscaper::escapeText($text));
    }

    /**
     * A subject as a string literal would spell it; "-" when there is none.
     */
    public static function example(?string $example): string
    {
        if (null === $example) {
            return '-';
        }

        return DisplayEscaper::quote($example, '<fg=cyan>', '</>');
    }
}
