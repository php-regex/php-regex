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
use RegexParser\NodeVisitor\ConsoleHighlighterVisitor;
use RegexParser\NodeVisitor\HtmlHighlighterVisitor;

final class HighlightCommand extends AbstractCommand
{
    public function getName(): string
    {
        return 'highlight';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'Highlight a regex for display';
    }

    public function run(Input $input, Output $output): int
    {
        $pattern = $input->args[0] ?? '';
        if ('' === $pattern) {
            $output->write($output->error("Error: Missing pattern\n"));
            $output->write("Usage: regex highlight <pattern> [--format=auto|cli|html]\n");

            return 1;
        }

        $format = 'auto';
        for ($i = 0; $i < \count($input->args); $i++) {
            $arg = $input->args[$i];
            if (str_starts_with($arg, '--format=')) {
                $format = substr($arg, 9);

                break;
            }
            if ('--format' === $arg) {
                $format = $input->args[$i + 1] ?? $format;
                $i++;
            }
        }

        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return 1;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $meta = [];
        $meta += $this->targetMeta($input, $output);

        try {
            if ('auto' === $format) {
                $format = \PHP_SAPI === 'cli' ? 'cli' : 'html';
            }

            if ('cli' === $format && $style->visualsEnabled()) {
                $meta['Format'] = $output->warning('cli');
                $style->renderBanner('highlight', $meta);
            }

            $visitor = match ($format) {
                'cli' => new ConsoleHighlighterVisitor(),
                'html' => new HtmlHighlighterVisitor(),
                default => throw new \InvalidArgumentException("Invalid format: $format"),
            };

            $ast = $regex->parse($pattern);
            $highlighted = $ast->accept($visitor);

            if ('cli' === $format && !$output->isAnsi()) {
                $highlighted = $pattern;
            }

            if ('cli' === $format && $style->visualsEnabled()) {
                $style->renderSection('Highlighting pattern', 1, 1);
                $style->renderPattern($highlighted);
            } else {
                $output->write($highlighted."\n");
            }
        } catch (LexerException|ParserException|\InvalidArgumentException $e) {
            $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error("Error: {$e->getMessage()}")."\n");

            return 1;
        }

        return 0;
    }
}
