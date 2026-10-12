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
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;

/**
 * @internal
 */
final class ExplainCommand extends AbstractCommand
{
    public function getName(): string
    {
        return 'explain';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'Explain a regex pattern in plain language';
    }

    public function run(Input $input, Output $output): int
    {
        $usage = "Usage: regex explain <pattern> [--format=console|html]\n";
        $arguments = $this->readArguments($input->args, [], ['--format']);
        if (null !== $arguments['error']) {
            return $this->usageError($output, $arguments['error'], $usage);
        }

        $pattern = $arguments['pattern'];
        $format = $arguments['options']['--format'] ?? 'console';
        if (!\is_string($format) || !\in_array($format, ['console', 'html'], true)) {
            return $this->usageError($output, \sprintf("Unsupported format '%s'. Use --format=console or --format=html.", (string) $format), $usage);
        }

        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return self::INVALID;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $meta = [];
        $meta += $this->targetMeta($input, $output);
        if ('console' === $format && $style->visualsEnabled()) {
            $meta['Format'] = $output->warning('console');
        }

        if ('console' === $format) {
            $style->renderBanner('explain', $meta);
        }

        try {
            $highlightedPattern = $pattern;
            if ('console' === $format && $style->visualsEnabled() && $output->isAnsi()) {
                $ast = $regex->parse($pattern);
                $highlightedPattern = $ast->accept(new ConsoleHighlighter());
            }

            $explanation = $regex->explain($pattern, 'console' === $format ? 'text' : 'html');

            if ('console' === $format && $style->visualsEnabled()) {
                $style->renderSection('Pattern', 1, 2);
                $style->renderPattern($highlightedPattern);
                $output->write("\n");
                $style->renderSection('Explanation', 2, 2);
            }

            $output->write($explanation."\n");
        } catch (LexerException|ParserException $e) {
            $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error('Explain failed: '.$e->getMessage())."\n");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
