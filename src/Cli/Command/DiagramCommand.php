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

namespace RegexParser\Cli\Command;

use RegexParser\Cli\ConsoleStyle;
use RegexParser\Cli\Input;
use RegexParser\Cli\Output;
use RegexParser\Exception\LexerException;
use RegexParser\Exception\ParserException;
use RegexParser\NodeVisitor\AsciiTreeVisitor;
use RegexParser\NodeVisitor\ConsoleHighlighterVisitor;
use RegexParser\NodeVisitor\RailroadSvgVisitor;

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

    public function run(Input $input, Output $output): int
    {
        $pattern = $input->args[0] ?? '';
        if ('' === $pattern) {
            $output->write($output->error("Error: Missing pattern\n"));
            $output->write("Usage: regex diagram <pattern> [--format=text|svg] [--output=<file>]\n");

            return 1;
        }

        $format = 'text';
        $outputPath = null;
        for ($i = 0; $i < \count($input->args); $i++) {
            $arg = $input->args[$i];
            if (str_starts_with($arg, '--format=')) {
                $format = substr($arg, \strlen('--format='));

                continue;
            }
            if ('--format' === $arg) {
                $format = $input->args[$i + 1] ?? $format;
                $i++;

                continue;
            }
            if (str_starts_with($arg, '--output=')) {
                $outputPath = substr($arg, \strlen('--output='));

                continue;
            }
            if ('--output' === $arg) {
                $outputPath = $input->args[$i + 1] ?? $outputPath;
                $i++;
            }
        }

        $format = strtolower($format);
        if (!\in_array($format, ['ascii', 'cli', 'text', 'svg'], true)) {
            $output->write($output->error("Error: Unsupported format '{$format}'. Use --format=text or --format=svg.\n"));

            return 1;
        }

        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return 1;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $meta = [];
        $meta += $this->targetMeta($input, $output);
        $showConsoleOutput = 'svg' !== $format && null === $outputPath;
        if ($showConsoleOutput && $style->visualsEnabled()) {
            $meta['Format'] = $output->warning('text');
            $style->renderBanner('diagram', $meta);
        }

        try {
            $ast = $regex->parse($pattern);
            if ('svg' === $format) {
                /** @var string $diagram */
                $diagram = $ast->accept(new RailroadSvgVisitor());
                if (null !== $outputPath) {
                    if (false === file_put_contents($outputPath, $diagram)) {
                        $output->write($output->error("Error: Unable to write SVG to '{$outputPath}'.\n"));

                        return 1;
                    }

                    return 0;
                }

                $output->write($diagram."\n");

                return 0;
            }

            $diagram = $ast->accept(new AsciiTreeVisitor());
            if (null !== $outputPath) {
                if (false === file_put_contents($outputPath, $diagram)) {
                    $output->write($output->error("Error: Unable to write output to '{$outputPath}'.\n"));

                    return 1;
                }

                return 0;
            }

            if ($style->visualsEnabled()) {
                $style->renderSection('Rendering diagram', 1, 1);
                $highlightedPattern = $output->isAnsi()
                    ? $ast->accept(new ConsoleHighlighterVisitor())
                    : $pattern;
                $style->renderPattern($highlightedPattern);
                $output->write("\n");
            }

            $output->write($diagram."\n");
        } catch (LexerException|ParserException $e) {
            $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error('Diagram failed: '.$e->getMessage())."\n");

            return 1;
        }

        return 0;
    }
}
