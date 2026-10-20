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

namespace PHPRegex\Linter\Console;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * The lines the commands of the Symfony and Laravel bridges write alike.
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
     * The title line of a report: the version, and under -v the author.
     */
    public static function title(OutputInterface $output, string $version): void
    {
        $output->writeln('<fg=cyan;options=bold>PHPRegex</> <fg=yellow>'.$version.'</>'.($output->isVerbose() ? ' by Younes ENNAJI' : ''));
    }

    /**
     * The request for a star that ends a report, under -v only.
     */
    public static function footer(OutputInterface $output): void
    {
        if (!$output->isVerbose()) {
            return;
        }

        $output->writeln('  <fg=gray>If PHPRegex helps, a GitHub star is appreciated: https://github.com/php-regex/php-regex</>');
        $output->writeln('');
    }
}
