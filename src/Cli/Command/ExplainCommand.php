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
use PHPRegex\Cli\JsonRequest;
use PHPRegex\Cli\Option\Arguments;
use PHPRegex\Cli\Option\Option;
use PHPRegex\Cli\Option\Options;
use PHPRegex\Cli\Output;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Json\JsonDocument;

/**
 * @internal
 */
final class ExplainCommand extends AbstractCommand implements JsonCommandInterface
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
            Option::value('--format', '<format>', 'Output format (console, html, json)'),
            Option::flag('--json', 'Same as --format=json'),
        ], "Usage: regex explain <pattern> [--format=console|html|json]\n");
    }

    public function run(Input $input, Output $output): int
    {
        $json = JsonRequest::in($input->args);
        $usage = self::options()->usage;
        $arguments = self::options()->parse($input->args);
        if (null !== $arguments->error) {
            return $this->usageError($output, $arguments->error, $usage, $json);
        }

        $pattern = $arguments->argument();
        // --json and --format name the same thing: the last one wins.
        $format = JsonRequest::format($input->args) ?? 'console';
        if (!\in_array($format, ['console', 'html', 'json'], true)) {
            return $this->usageError($output, \sprintf("Unsupported format '%s'. Use --format=console, --format=html or --format=json.", $format), $usage);
        }

        $range = $this->resolveTarget($input, $output, $json);
        if (null === $range) {
            return self::INVALID;
        }

        // The console banner says what resolving the target noticed; stderr
        // does beside a document.
        if ('console' !== $format) {
            $this->reportNotices($output, $range);
        }

        if ($json) {
            return $this->runJson($output, $range, $pattern);
        }

        return $this->reportRange($output, $range, $pattern, $this->explain($input, $output, $range, $pattern, $format));
    }

    /**
     * The explanation, at the floor, with its banner.
     */
    private function explain(Input $input, Output $output, TargetRange $range, string $pattern, string $format): int
    {
        $regex = $range->regex;
        $meta = $this->targetMeta($output, $range);
        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        if ('console' === $format && $style->visualsEnabled()) {
            $meta['Format'] = $output->warning('console');
        }

        if ('console' === $format) {
            $style->renderBanner('explain', $meta, notes: $range->target->notices());
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

    /**
     * The document: the pattern and its explanation. Only a valid pattern
     * is explained: a semantic error parses, and would otherwise be
     * explained as what PHP never runs.
     */
    private function runJson(Output $output, TargetRange $range, string $pattern): int
    {
        $regex = $range->regex;
        $validation = $regex->validate($pattern);
        if (!$validation->isValid) {
            $output->writeDocument(JsonDocument::error($validation->error ?? 'Invalid pattern.', JsonDocument::STAGE_PATTERN, ['validation' => $validation]));

            return self::FAILURE;
        }

        // A pattern the target compiles is one the explainer reads.
        $points = $range->points($pattern);
        $output->writeDocument(JsonDocument::encode(['pattern' => $pattern, 'explanation' => $regex->explain($pattern), 'target' => $range->target->toArray(), 'range' => $points]));

        // stdout holds the document alone: what the range finds goes to
        // stderr, as on the console.
        return $this->reportRange($output, $range, $pattern, self::exitCodeOverRange($points, self::SUCCESS));
    }
}
