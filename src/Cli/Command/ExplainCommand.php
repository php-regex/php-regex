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
use PHPRegex\Toolkit\Regex;

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

        $regex = $this->createRegex($output, $input->regexOptions, $json);
        if (null === $regex) {
            return self::INVALID;
        }

        if ($json) {
            return $this->runJson($output, $regex, $pattern);
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

    /**
     * The document: the pattern and its explanation. Only a valid pattern
     * is explained: a semantic error parses, and would otherwise be
     * explained as what PHP never runs.
     */
    private function runJson(Output $output, Regex $regex, string $pattern): int
    {
        $validation = $regex->validate($pattern);
        if (!$validation->isValid) {
            $output->writeDocument(JsonDocument::error($validation->error ?? 'Invalid pattern.', JsonDocument::STAGE_PATTERN, ['validation' => $validation]));

            return self::FAILURE;
        }

        // A pattern the target compiles is one the explainer reads.
        $output->writeDocument(JsonDocument::encode(['pattern' => $pattern, 'explanation' => $regex->explain($pattern)]));

        return self::SUCCESS;
    }
}
