<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Cli\Command;

use PhpRegex\Cli\ConsoleStyle;
use PhpRegex\Cli\Input;
use PhpRegex\Cli\Output;
use PhpRegex\Explain\AsciiTreeRenderer;
use PhpRegex\Explain\Highlighter\ConsoleHighlighter;
use PhpRegex\Explain\RailroadSvgRenderer;
use PhpRegex\Parser\Exception\LexerException;
use PhpRegex\Parser\Exception\ParserException;

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
        $usage = "Usage: regex diagram <pattern> [--format=text|svg] [--output=<file>]\n";
        $arguments = $this->readArguments($input->args, [], ['--format', '--output']);
        if (null !== $arguments['error']) {
            return $this->usageError($output, $arguments['error'], $usage);
        }

        $pattern = $arguments['pattern'];
        $format = strtolower((string) ($arguments['options']['--format'] ?? 'text'));
        $outputPath = $arguments['options']['--output'] ?? null;
        $outputPath = \is_string($outputPath) ? $outputPath : null;
        if (!\in_array($format, ['ascii', 'cli', 'text', 'svg'], true)) {
            return $this->usageError($output, \sprintf("Unsupported format '%s'. Use --format=text or --format=svg.", $format), $usage);
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
            $meta['Format'] = $output->warning('text');
            $style->renderBanner('diagram', $meta);
        }

        try {
            $ast = $regex->parse($pattern);
            if ('svg' === $format) {
                /** @var string $diagram */
                $diagram = $ast->accept(new RailroadSvgRenderer());
                if (null !== $outputPath) {
                    if (false === @file_put_contents($outputPath, $diagram)) {
                        $output->write($output->error("Error: Unable to write SVG to '{$outputPath}'.\n"));

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
                    $output->write($output->error("Error: Unable to write output to '{$outputPath}'.\n"));

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
        } catch (LexerException|ParserException $e) {
            $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error('Diagram failed: '.$e->getMessage())."\n");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
