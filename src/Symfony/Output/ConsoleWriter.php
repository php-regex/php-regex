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

use PHPRegex\Parser\Text\DisplayEscaper;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The lines the bundle's commands and reports write alike.
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
     * A "label : value" line of a report's header, the labels padded to
     * the longest.
     */
    public static function metaLine(string $label, string $value, int $maxLabelLength): string
    {
        return \sprintf(
            '<fg=white;options=bold>%s</> : <fg=yellow>%s</>',
            str_pad($label, $maxLabelLength),
            $value,
        );
    }

    /**
     * A pattern as written, its console tags escaped; an empty one as a pair
     * of quotes.
     */
    public static function pattern(string $pattern): string
    {
        if ('' === $pattern) {
            return '<fg=cyan>""</>';
        }

        return '<fg=cyan>'.OutputFormatter::escape($pattern).'</>';
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

    public static function footer(SymfonyStyle $io): void
    {
        $message = 'If PHPRegex helps, a GitHub star is appreciated: ';
        $io->writeln('  <fg=gray>'.$message.'https://github.com/php-regex/php-regex</>');
        $io->newLine();
    }
}
