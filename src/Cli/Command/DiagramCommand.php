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

namespace PHPRegex\Cli\Command;

use PHPRegex\Cli\ConsoleStyle;
use PHPRegex\Cli\GlobalOptionsParser;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Option\Arguments;
use PHPRegex\Cli\Option\Option;
use PHPRegex\Cli\Option\Options;
use PHPRegex\Cli\Output;
use PHPRegex\Explain\AsciiTreeRenderer;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Explain\RailroadSvgRenderer;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Text\Ascii;

/**
 * @internal
 */
final class DiagramCommand extends AbstractCommand
{
    public function getName(): string
    {
        return 'diagram';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'Render a diagram of the AST (text or SVG)';
    }

    /**
     * The options of the command, which its help lists.
     */
    public static function options(): Options
    {
        return new Options(Arguments::LoosePattern, [
            Option::value('--format', '<format>', 'Output format (console, svg)'),
            Option::value('--output', '<file>', 'Write output to file'),
            ...GlobalOptionsParser::target(),
        ], "Usage: regex diagram <pattern> [--format=console|svg] [--output=<file>]\n");
    }

    public function run(Input $input, Output $output): int
    {
        $usage = self::options()->usage;
        $arguments = self::options()->parse($input->args);
        if (null !== $arguments->error) {
            return $this->usageError($output, $arguments->error, $usage);
        }

        $pattern = $arguments->argument();
        $format = Ascii::lower($arguments->value('--format') ?? 'console');
        $outputPath = $arguments->value('--output');
        if (!\in_array($format, ['console', 'svg'], true)) {
            return $this->usageError($output, \sprintf("Unsupported format '%s'. Use --format=console or --format=svg.", $format), $usage);
        }

        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return self::INVALID;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $meta = [];
        $meta += $this->targetMeta($input, $output);
        $showConsoleOutput = 'svg' !== $format && null === $outputPath;
        if ($showConsoleOutput && $style->visualsEnabled()) {
            $meta['Format'] = $output->warning('console');
            $style->renderBanner('diagram', $meta);
        }

        try {
            $ast = $regex->parse($pattern);
            if ('svg' === $format) {
                $diagram = (new RailroadSvgRenderer())->visitRegex($ast);
                if (null !== $outputPath) {
                    if (false === @file_put_contents($outputPath, $diagram)) {
                        $output->writeError($output->error("Error: Unable to write SVG to '{$outputPath}'.\n"));

                        return self::INVALID;
                    }

                    return self::SUCCESS;
                }

                $output->write($diagram."\n");

                return self::SUCCESS;
            }

            $diagram = $ast->accept(new AsciiTreeRenderer());
            if (null !== $outputPath) {
                if (false === @file_put_contents($outputPath, $diagram)) {
                    $output->writeError($output->error("Error: Unable to write output to '{$outputPath}'.\n"));

                    return self::INVALID;
                }

                return self::SUCCESS;
            }

            if ($style->visualsEnabled()) {
                $style->renderSection('Rendering diagram', 1, 1);
                $highlightedPattern = $output->isAnsi()
                    ? $ast->accept(new ConsoleHighlighter())
                    : $pattern;
                $style->renderPattern($highlightedPattern);
                $output->write("\n");
            }

            $output->write($diagram."\n");
        } catch (LexerException|LimitException|ParserException $e) {
            $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error('Diagram failed: '.$e->getMessage())."\n");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
