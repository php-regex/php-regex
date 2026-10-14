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
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Explain\Highlighter\HtmlHighlighter;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;

/**
 * @internal
 */
final class HighlightCommand extends AbstractCommand
{
    private const USAGE = "Usage: regex highlight <pattern> [--format=auto|console|html]\n";

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
            return $this->usageError($output, $arguments['error'], self::USAGE);
        }

        $pattern = $arguments['pattern'];
        $format = (string) ($arguments['options']['--format'] ?? 'auto');
        if ('auto' === $format) {
            $format = \PHP_SAPI === 'cli' ? 'console' : 'html';
        }

        if (!\in_array($format, ['console', 'html'], true)) {
            return $this->usageError($output, \sprintf("Unsupported format '%s'. Use --format=auto, --format=console or --format=html.", $format), self::USAGE);
        }

        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return self::INVALID;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $meta = [];
        $meta += $this->targetMeta($input, $output);

        try {
            if ('console' === $format && $style->visualsEnabled()) {
                $meta['Format'] = $output->warning('console');
                $style->renderBanner('highlight', $meta);
            }

            $visitor = 'console' === $format ? new ConsoleHighlighter() : new HtmlHighlighter();

            $ast = $regex->parse($pattern);
            $highlighted = $ast->accept($visitor);

            if ('console' === $format && !$output->isAnsi()) {
                $highlighted = $pattern;
            }

            if ('console' === $format && $style->visualsEnabled()) {
                $style->renderSection('Highlighting pattern', 1, 1);
                $style->renderPattern($highlighted);
            } else {
                $output->write($highlighted."\n");
            }
        } catch (LexerException|LimitException|ParserException $e) {
            $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error("Error: {$e->getMessage()}")."\n");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
