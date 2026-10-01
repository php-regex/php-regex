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
use PhpRegex\Explain\Highlighter\ConsoleHighlighter;
use PhpRegex\Explain\Highlighter\HtmlHighlighter;
use PhpRegex\Parser\Exception\LexerException;
use PhpRegex\Parser\Exception\ParserException;

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
        $arguments = $this->readArguments($input->args, [], ['--format']);
        if (null !== $arguments['error']) {
            return $this->usageError($output, $arguments['error'], "Usage: regex highlight <pattern> [--format=auto|cli|html]\n");
        }

        $pattern = $arguments['pattern'];
        $format = (string) ($arguments['options']['--format'] ?? 'auto');
        if ('auto' === $format) {
            $format = \PHP_SAPI === 'cli' ? 'cli' : 'html';
        }

        if (!\in_array($format, ['cli', 'html'], true)) {
            $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error("Error: Invalid format: {$format}")."\n");

            return self::INVALID;
        }

        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return self::INVALID;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $meta = [];
        $meta += $this->targetMeta($input, $output);

        try {
            if ('cli' === $format && $style->visualsEnabled()) {
                $meta['Format'] = $output->warning('cli');
                $style->renderBanner('highlight', $meta);
            }

            $visitor = 'cli' === $format ? new ConsoleHighlighter() : new HtmlHighlighter();

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
        } catch (LexerException|ParserException $e) {
            $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error("Error: {$e->getMessage()}")."\n");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
