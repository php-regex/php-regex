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
use PHPRegex\Cli\Output;
use PHPRegex\Cli\PcreRuntimeInfo;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Linter\Internal\RedosVerdict;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Internal\JsonDocument;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;

/**
 * @internal
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
            return $this->usageError($output, $parsed['error'], "Usage: regex analyze <pattern> [--format=json] [--redos-mode=off|theoretical|confirmed] [--redos-threshold=low|medium|high|critical]\n", $json);
        }

        $regex = $this->createRegex($output, $input->regexOptions, $json);
        if (null === $regex) {
            return self::INVALID;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $runtime = PcreRuntimeInfo::fromIni();
        $meta = $this->buildRuntimeMeta($input, $output, $runtime);

        if ('json' !== $parsed['format']) {
            $style->renderBanner('analyze', $meta);
        }

        try {
            $pattern = $parsed['pattern'];
            $format = $parsed['format'];
            $redosMode = $parsed['redosMode'];
            $redosThreshold = $parsed['redosThreshold'];
            $confirmOptions = $parsed['confirmOptions'];

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
                ]));

                return self::FAILURE;
            }

            $ast = $regex->parse($pattern);
            $analysis = $regex->redos($pattern, $redosThreshold, $redosMode, $confirmOptions);
            $explain = $regex->explain($pattern);

            $highlightedPattern = $output->isAnsi()
                ? $ast->accept(new ConsoleHighlighter())
                : $pattern;

            $rendered = 'json' === $format
                ? $this->renderJsonOutput($output, $pattern, $runtime, $validation, $analysis, $explain)
                : $this->renderConsoleOutput($output, $style, $highlightedPattern, $validation, $analysis, $explain);

            if (self::SUCCESS !== $rendered || !$validation->isValid || $this->isConfirmedRedos($analysis, $redosThreshold)) {
                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (LexerException|ParserException $e) {
            return $this->handleAnalysisError($output, $parsed['format'], $e->getMessage());
        }
    }

    /**
     * @param array<int, string> $args
     *
     * @return array{pattern: string, format: string, redosMode: RedosMode, redosThreshold: ?RedosSeverity, confirmOptions: ?ConfirmationOptions, error: ?string}
     */
    private function parseArguments(array $args): array
    {
        $pattern = '';
        $format = 'console';
        $redosMode = RedosMode::Theoretical;
        $redosThreshold = null;
        $confirmOptions = null;
        $stopParsing = false;

        for ($i = 0; $i < \count($args); $i++) {
            $arg = $args[$i];

            if (!$stopParsing && '--' === $arg) {
                $stopParsing = true;

                continue;
            }

            if (!$stopParsing && '--json' === $arg) {
                $format = 'json';

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--format=')) {
                $format = strtolower(substr($arg, \strlen('--format=')));

                continue;
            }

            if (!$stopParsing && '--format' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return $this->errorResult($format, $redosMode, $redosThreshold, 'Missing value for --format.');
                }
                $format = strtolower($value);
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--redos-mode=')) {
                $value = strtolower(substr($arg, \strlen('--redos-mode=')));
                $mode = RedosMode::tryFrom($value);
                if (null === $mode) {
                    return $this->errorResult($format, $redosMode, $redosThreshold, 'Invalid value for --redos-mode.');
                }
                $redosMode = $mode;

                continue;
            }

            if (!$stopParsing && '--redos-mode' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return $this->errorResult($format, $redosMode, $redosThreshold, 'Missing value for --redos-mode.');
                }
                $mode = RedosMode::tryFrom(strtolower($value));
                if (null === $mode) {
                    return $this->errorResult($format, $redosMode, $redosThreshold, 'Invalid value for --redos-mode.');
                }
                $redosMode = $mode;
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--redos-threshold=')) {
                try {
                    $redosThreshold = RedosSeverity::fromConfig(substr($arg, \strlen('--redos-threshold=')));
                } catch (InvalidRegexOptionException $e) {
                    return $this->errorResult($format, $redosMode, $redosThreshold, 'Invalid value for --redos-threshold: '.$e->getMessage());
                }

                continue;
            }

            if (!$stopParsing && '--redos-threshold' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return $this->errorResult($format, $redosMode, $redosThreshold, 'Missing value for --redos-threshold.');
                }

                try {
                    $redosThreshold = RedosSeverity::fromConfig($value);
                } catch (InvalidRegexOptionException $e) {
                    return $this->errorResult($format, $redosMode, $redosThreshold, 'Invalid value for --redos-threshold: '.$e->getMessage());
                }
                $i++;

                continue;
            }

            if (!$stopParsing && '--redos-no-jit' === $arg) {
                return $this->errorResult($format, $redosMode, $redosThreshold, '--redos-no-jit was removed in 2.0: the confirmation always runs without JIT.');
            }

            if (!$stopParsing && str_starts_with($arg, '-')) {
                return $this->errorResult($format, $redosMode, $redosThreshold, 'Unknown option: '.$arg);
            }

            if ('' === $pattern) {
                $pattern = $arg;

                continue;
            }
        }

        if ('' === $pattern) {
            return $this->errorResult($format, $redosMode, $redosThreshold, 'Missing pattern.');
        }

        if (!\in_array($format, ['console', 'json'], true)) {
            return $this->errorResult($format, $redosMode, $redosThreshold, 'Invalid value for --format.');
        }

        return [
            'pattern' => $pattern,
            'format' => $format,
            'redosMode' => $redosMode,
            'redosThreshold' => $redosThreshold,
            'confirmOptions' => $confirmOptions,
            'error' => null,
        ];
    }

    /**
     * @return array{pattern: string, format: string, redosMode: RedosMode, redosThreshold: ?RedosSeverity, confirmOptions: ?ConfirmationOptions, error: string}
     */
    private function errorResult(string $format, RedosMode $redosMode, ?RedosSeverity $redosThreshold, string $error): array
    {
        return [
            'pattern' => '',
            'format' => $format,
            'redosMode' => $redosMode,
            'redosThreshold' => $redosThreshold,
            'confirmOptions' => null,
            'error' => $error,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildRuntimeMeta(Input $input, Output $output, PcreRuntimeInfo $runtime): array
    {
        $meta = [];

        $meta += $this->targetMeta($input, $output);

        $meta['PCRE'] = $output->warning($runtime->version);
        $meta['PCRE JIT'] = $output->warning($runtime->jitSetting ?? 'unknown');
        $meta['Backtrack'] = $output->warning((string) ($runtime->backtrackLimit ?? 'unknown'));
        $meta['Recursion'] = $output->warning((string) ($runtime->recursionLimit ?? 'unknown'));

        return $meta;
    }

    private function renderJsonOutput(Output $output, string $pattern, PcreRuntimeInfo $runtime, ValidationResult $validation, RedosAnalysis $analysis, string $explain): int
    {
        $payload = [
            'pattern' => $pattern,
            'runtime' => $runtime,
            'parse' => ['ok' => true],
            'validation' => $validation,
            'redos' => $analysis,
            'explain' => $explain,
        ];

        $output->writeDocument(JsonDocument::encode($payload));

        return self::SUCCESS;
    }

    private function renderConsoleOutput(Output $output, ConsoleStyle $style, string $highlightedPattern, ValidationResult $validation, RedosAnalysis $analysis, string $explain): int
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

        if (!$validation->isValid && $validation->error) {
            $output->write('  '.$output->error($validation->error)."\n");
        }

        if (!$validation->isValid && null !== $validation->caretSnippet) {
            $output->write($output->error($validation->caretSnippet)."\n");
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
            'Mode' => strtoupper($analysis->mode->value),
            'Confidence' => strtoupper($analysis->confidenceLevel()->value),
        ]);

        foreach ($this->redosVerdictLines($analysis) as $line) {
            $output->write('  '.$line."\n");
        }

        if ($analysis->error) {
            $output->write('  '.$output->error('ReDoS error: '.$analysis->error)."\n");
        }

        $hotspot = $analysis->getPrimaryHotspot();
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

        return self::SUCCESS;
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
        $label = strtoupper($analysis->severity->value);

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
