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
use PHPRegex\Cli\GlobalOptionsParser;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\JsonRequest;
use PHPRegex\Cli\Option\Arguments;
use PHPRegex\Cli\Option\Option;
use PHPRegex\Cli\Option\Options;
use PHPRegex\Cli\Output;
use PHPRegex\Cli\PcreRuntimeInfo;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\RangePoints;
use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\Snippet;
use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;

/**
 * @internal
 *
 * @phpstan-type AnalyzeArguments array{pattern: string, format: string, redosMode: RedosMode, redosThreshold: ?RedosSeverity, error: ?string}
 *
 * @phpstan-import-type RangePoint from RangePoints
 */
final class AnalyzeCommand extends AbstractCommand implements JsonCommandInterface
{
    public function getName(): string
    {
        return 'analyze';
    }

    public function getAliases(): array
    {
        return ['analyse'];
    }

    public function getDescription(): string
    {
        return 'Parse, validate, and analyze ReDoS risk';
    }

    public function run(Input $input, Output $output): int
    {
        $json = JsonRequest::in($input->args);
        $parsed = $this->parseArguments($input->args);

        if (null !== $parsed['error']) {
            return $this->usageError($output, $parsed['error'], self::options()->usage, $json);
        }

        $range = $this->resolveTarget($input, $output, $json);
        if (null === $range) {
            return self::INVALID;
        }

        $exitCode = $this->analyze($input, $output, $range, $parsed);

        // The JSON document holds each version's verdict.
        return 'json' === $parsed['format'] ? $exitCode : $this->reportRange($output, $range, $parsed['pattern'], $exitCode);
    }

    /**
     * The options of the command, which its help lists.
     */
    public static function options(): Options
    {
        return new Options(Arguments::Pattern, [
            Option::value('--format', '<format>', 'Output format (console, json)'),
            Option::flag('--json', 'Same as --format=json'),
            ...GlobalOptionsParser::target(),
            ...self::redosOptions(),
        ], "Usage: regex analyze <pattern> [--format=json] [--redos-mode=off|theoretical|confirmed] [--redos-threshold=low|medium|high|critical]\n");
    }

    /**
     * The analysis, at the floor.
     *
     * @param AnalyzeArguments $parsed
     */
    private function analyze(Input $input, Output $output, TargetRange $range, array $parsed): int
    {
        $regex = $range->regex;
        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $runtime = PcreRuntimeInfo::fromIni();
        $meta = $this->buildRuntimeMeta($output, $range, $runtime);

        if ('json' !== $parsed['format']) {
            $style->renderBanner('analyze', $meta, notes: $range->target->notices());
        } else {
            $this->reportNotices($output, $range);
        }

        try {
            $pattern = $parsed['pattern'];
            $format = $parsed['format'];
            $redosMode = $parsed['redosMode'];
            $redosThreshold = $parsed['redosThreshold'];

            $validation = $regex->validate($pattern);
            // The report holds an invalid pattern, its parse failed and the
            // analyses that need a valid pattern left empty.
            if ('json' === $format && !$validation->isValid) {
                $output->writeDocument(JsonDocument::encode([
                    'pattern' => $pattern,
                    'runtime' => $runtime,
                    'parse' => ['ok' => false],
                    'validation' => $validation,
                    'redos' => null,
                    'explain' => null,
                    'target' => $range->target->toArray(),
                    'range' => $range->points($pattern),
                ]));

                return self::FAILURE;
            }

            $ast = $regex->parse($pattern);
            $analysis = $regex->redos($pattern, $redosThreshold, $redosMode);
            $explain = $regex->explain($pattern);

            // A control, a hidden character or an invalid byte is spelled out
            // and the pattern then echoed without colours.
            $echoed = DisplayEscaper::escape($pattern);
            $highlightedPattern = $output->isAnsi() && $echoed === $pattern
                ? $ast->accept(new ConsoleHighlighter())
                : $echoed;

            $exitCode = !$validation->isValid || $this->isConfirmedRedos($analysis, $redosThreshold) ? self::FAILURE : self::SUCCESS;

            if ('json' === $format) {
                $points = $range->points($pattern);
                $this->renderJsonOutput($output, $pattern, $runtime, $validation, $analysis, $explain, $range->target, $points);

                return self::exitCodeOverRange($points, $exitCode);
            }

            $this->renderConsoleOutput($output, $style, $highlightedPattern, $validation, $analysis, $explain);

            return $exitCode;
        } catch (LexerException|LimitException|ParserException $e) {
            return $this->handleAnalysisError($output, $parsed['format'], $e->getMessage());
        }
    }

    /**
     * @param array<int, string> $args
     *
     * @return AnalyzeArguments
     */
    private function parseArguments(array $args): array
    {
        $parsed = self::options()->parse($args);
        $format = 'console';
        $redosMode = RedosMode::Theoretical;
        $redosThreshold = null;

        foreach ($parsed->options as $option) {
            if ('--json' === $option->name) {
                $format = 'json';
            } elseif ('--format' === $option->name) {
                $format = Ascii::lower((string) $option->value);
            } else {
                $error = $this->readRedosOption($option, $redosMode, $redosThreshold);
                if (null !== $error) {
                    return $this->errorResult($error);
                }
            }
        }

        if (null !== $parsed->error) {
            return $this->errorResult($parsed->error);
        }

        if (!\in_array($format, ['console', 'json'], true)) {
            return $this->errorResult('Invalid value for --format.');
        }

        return [
            'pattern' => $parsed->argument(),
            'format' => $format,
            'redosMode' => $redosMode,
            'redosThreshold' => $redosThreshold,
            'error' => null,
        ];
    }

    /**
     * @return AnalyzeArguments
     */
    private function errorResult(string $error): array
    {
        return [
            'pattern' => '',
            'format' => 'console',
            'redosMode' => RedosMode::Theoretical,
            'redosThreshold' => null,
            'error' => $error,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildRuntimeMeta(Output $output, TargetRange $range, PcreRuntimeInfo $runtime): array
    {
        $meta = $this->targetMeta($output, $range);

        $meta['PCRE'] = $output->warning($runtime->version);
        $meta['PCRE JIT'] = $output->warning($runtime->jitSetting ?? 'unknown');
        $meta['Backtrack'] = $output->warning((string) ($runtime->backtrackLimit ?? 'unknown'));
        $meta['Recursion'] = $output->warning((string) ($runtime->recursionLimit ?? 'unknown'));

        return $meta;
    }

    /**
     * @param ProjectTarget    $target the target judged, written as lint writes it
     * @param list<RangePoint> $points the verdict of each version of the range
     */
    private function renderJsonOutput(Output $output, string $pattern, PcreRuntimeInfo $runtime, ValidationResult $validation, RedosAnalysis $analysis, string $explain, ProjectTarget $target, array $points): void
    {
        $payload = [
            'pattern' => $pattern,
            'runtime' => $runtime,
            'parse' => ['ok' => true],
            'validation' => $validation,
            'redos' => $analysis,
            'explain' => $explain,
            'target' => $target->toArray(),
            'range' => $points,
        ];

        $output->writeDocument(JsonDocument::encode($payload));
    }

    private function renderConsoleOutput(Output $output, ConsoleStyle $style, string $highlightedPattern, ValidationResult $validation, RedosAnalysis $analysis, string $explain): void
    {
        $confirmation = RedosMode::Confirmed === $analysis->mode ? $analysis->confirmation : null;
        // One number per section printed: Confirmation only when there is one.
        $steps = null !== $confirmation ? 5 : 4;

        $style->renderSection('Parsing pattern', 1, $steps);
        $style->renderPattern($highlightedPattern);
        $style->renderKeyValueBlock([
            'Parse' => $output->success('OK'),
        ]);

        if ($style->visualsEnabled()) {
            $output->write("\n");
        }

        $style->renderSection('Validation', 2, $steps);
        $validationStatus = $validation->isValid ? $output->success('OK') : $output->error('INVALID');
        $style->renderKeyValueBlock([
            'Status' => $validationStatus,
        ]);

        if (!$validation->isValid && null !== $validation->error) {
            $output->write('  '.$output->error(DisplayEscaper::escapeText($validation->error))."\n");
        }

        if (!$validation->isValid && null !== $validation->caretSnippet) {
            $output->write($output->error(Snippet::escapeForTerminal($validation->caretSnippet))."\n");
        }

        if ($style->visualsEnabled()) {
            $output->write("\n");
        }

        $style->renderSection('ReDoS analysis', 3, $steps);
        $severityOutput = $this->formatRedosSeverity($analysis, $output);
        $status = $this->redosHeadline($analysis);

        $style->renderKeyValueBlock([
            'Status' => $status,
            'Severity' => $severityOutput.' (score '.$analysis->score.')',
            'Mode' => Ascii::upper($analysis->mode->value),
            'Confidence' => Ascii::upper($analysis->confidence->value),
        ]);

        foreach ($this->redosVerdictLines($analysis) as $line) {
            $output->write('  '.$line."\n");
        }

        if ($analysis->error) {
            $output->write('  '.$output->error('ReDoS error: '.$analysis->error)."\n");
        }

        $hotspot = $analysis->primaryHotspot();
        if (null !== $hotspot) {
            $output->write('  Hotspot:   '.$hotspot->start.'-'.$hotspot->end."\n");
        }

        if (null !== $confirmation) {
            $this->renderConfirmationSection($output, $style, $confirmation, $steps);
        }

        if ($style->visualsEnabled()) {
            $output->write("\n");
        }

        $style->renderSection('Explanation', $steps, $steps);
        $output->write($explain."\n");
    }

    private function renderConfirmationSection(Output $output, ConsoleStyle $style, Confirmation $confirmation, int $steps): void
    {
        $output->write("\n");
        $style->renderSection('Confirmation', 4, $steps);

        $sampleParts = [];
        foreach ($confirmation->samples as $sample) {
            $sampleParts[] = \sprintf('len=%d avg=%.2fms', $sample->inputLength, $sample->durationMs);
        }

        $output->write('  Status:    '.($confirmation->confirmed ? 'CONFIRMED' : 'NOT CONFIRMED')."\n");

        if (null !== $confirmation->evidence) {
            $output->write('  Evidence:  '.$confirmation->evidence."\n");
        }

        if ([] !== $sampleParts) {
            $output->write('  Samples:   '.implode(', ', $sampleParts)."\n");
        }

        $output->write('  JIT:       '.($confirmation->jitSetting ?? 'unknown')."\n");
        $output->write('  Backtrack: '.($confirmation->backtrackLimit ?? 'unknown')."\n");
        $output->write('  Recursion: '.($confirmation->recursionLimit ?? 'unknown')."\n");

        if ($confirmation->timedOut) {
            $output->write("  Note:      confirmation timed out within limits\n");
        }

        if (null !== $confirmation->note) {
            $output->write('  Note:      '.$confirmation->note."\n");
        }
    }

    private function handleAnalysisError(Output $output, string $format, string $errorMessage): int
    {
        // Not reached in JSON mode today: an invalid pattern is reported in the payload,
        // and parse(), redos() and explain() repeat the parse validate() accepted.
        if ('json' === $format) {
            $output->writeDocument(JsonDocument::error($errorMessage, JsonDocument::STAGE_PATTERN));

            return self::FAILURE;
        }

        $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error('Analyze failed: '.$errorMessage)."\n");

        return self::FAILURE;
    }

    private function formatRedosSeverity(RedosAnalysis $analysis, Output $output): string
    {
        $label = Ascii::upper($analysis->severity->value);

        $color = match ($analysis->severity) {
            RedosSeverity::Safe, RedosSeverity::Low => $output->success($label),
            RedosSeverity::Medium => $output->warning($label),
            RedosSeverity::High, RedosSeverity::Critical => RedosVerdict::standsConfirmed($analysis)
                ? $output->error($label)
                : $output->warning($label),
            RedosSeverity::Unknown => $output->info($label),
        };

        return $color;
    }
}
