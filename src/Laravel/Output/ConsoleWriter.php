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

namespace PHPRegex\Laravel\Output;

use PHPRegex\Toolkit\Regex;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The lines the package's commands write alike.
 *
 * @internal
 */
final class ConsoleWriter
{
    /**
     * The document as it is, never read for console tags, and written even
     * under --quiet: it is the output asked for, not a status line.
     */
    public static function document(OutputInterface $output, string $document): void
    {
        $output->write($document, false, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
    }

    /**
     * The pattern highlighted on a decorated output; elsewhere, as plain
     * text, so that no escape sequence reaches a pipe or --no-ansi.
     */
    public static function pattern(Regex $regex, OutputInterface $output, string $pattern): string
    {
        if ($output->isDecorated()) {
            return $regex->highlight($pattern, 'console');
        }

        return '<fg=yellow>'.OutputFormatter::escape($pattern).'</>';
    }
}
