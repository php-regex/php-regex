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
use PHPRegex\Cli\Option\Arguments;
use PHPRegex\Cli\Option\Option;
use PHPRegex\Cli\Option\Options;
use PHPRegex\Cli\Output;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
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

    /**
     * The options of the command, which its help lists.
     */
    public static function options(): Options
    {
        return new Options(Arguments::LoosePattern, [
            Option::value('--format', '<format>', 'Output format (console, html)'),
        ], "Usage: regex explain <pattern> [--format=console|html]\n");
    }

    public function run(Input $input, Output $output): int
    {
        $usage = self::options()->usage;
        $arguments = self::options()->parse($input->args);
        if (null !== $arguments->error) {
            return $this->usageError($output, $arguments->error, $usage);
        }

        $pattern = $arguments->argument();
        $format = $arguments->value('--format') ?? 'console';
        if (!\in_array($format, ['console', 'html'], true)) {
            return $this->usageError($output, \sprintf("Unsupported format '%s'. Use --format=console or --format=html.", $format), $usage);
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
        } catch (LexerException|LimitException|ParserException $e) {
            $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error('Explain failed: '.$e->getMessage())."\n");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
