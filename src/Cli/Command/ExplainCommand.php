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
        $usage = "Usage: regex explain <pattern> [--format=text|html]\n";
        $arguments = $this->readArguments($input->args, [], ['--format']);
        if (null !== $arguments['error']) {
            return $this->usageError($output, $arguments['error'], $usage);
        }

        $pattern = $arguments['pattern'];
        $format = $arguments['options']['--format'] ?? 'text';
        if (!\is_string($format) || !\in_array($format, ['text', 'html'], true)) {
            return $this->usageError($output, \sprintf("Unsupported format '%s'. Use --format=text or --format=html.", (string) $format), $usage);
        }

        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return self::INVALID;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $meta = [];
        $meta += $this->targetMeta($input, $output);
        if ('text' === $format && $style->visualsEnabled()) {
            $meta['Format'] = $output->warning('text');
        }

        if ('text' === $format) {
            $style->renderBanner('explain', $meta);
        }

        try {
            $highlightedPattern = $pattern;
            if ('text' === $format && $style->visualsEnabled() && $output->isAnsi()) {
                $ast = $regex->parse($pattern);
                $highlightedPattern = $ast->accept(new ConsoleHighlighterVisitor());
            }

            $explanation = $regex->explain($pattern, $format);

            if ('text' === $format && $style->visualsEnabled()) {
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
