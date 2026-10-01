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
use PhpRegex\Cli\PcreRuntimeInfo;
use PhpRegex\Explain\Highlighter\ConsoleHighlighter;
use PhpRegex\Parser\Exception\InvalidRegexOptionException;
use PhpRegex\Parser\Exception\LexerException;
use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Validation\ValidationResult;
use PhpRegex\Redos\Confirmation;
use PhpRegex\Redos\ConfirmationOptions;
use PhpRegex\Redos\RedosAnalysis;
use PhpRegex\Redos\RedosMode;
use PhpRegex\Redos\RedosSeverity;

final class AnalyzeCommand extends AbstractCommand
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
        $parsed = $this->parseArguments($input->args);

        if (null !== $parsed['error']) {
            $output->write($output->error('Error: '.$parsed['error']."\n"));
            $output->write("Usage: regex analyze <pattern> [--format=json] [--redos-mode=off|theoretical|confirmed] [--redos-threshold=low|medium|high|critical]\n");

            return self::INVALID;
        }

        $regex = $this->createRegex($output, $input->regexOptions);
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

            $ast = $regex->parse($pattern);
            $validation = $regex->validate($pattern);
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
            'validation' => [
                'valid' => $validation->isValid,
                'error' => $validation->error,
                'complexity_score' => $validation->complexityScore,
                'category' => $validation->category?->value,
                'offset' => $validation->offset,
                'hint' => $validation->hint,
                'error_code' => $validation->errorCode?->value,
            ],
            'redos' => $analysis,
            'explain' => $explain,
        ];

        $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            $output->write($output->error("Error: Failed to encode JSON\n"));

            return self::FAILURE;
        }

        $output->write($json."\n");

        return self::SUCCESS;
    }

    private function renderConsoleOutput(Output $output, ConsoleStyle $style, string $highlightedPattern, ValidationResult $validation, RedosAnalysis $analysis, string $explain): int
    {
        $steps = 4;

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
        $status = $this->getRedosStatus($analysis);

        $style->renderKeyValueBlock([
            'Status' => $status,
            'Severity' => $severityOutput.' (score '.$analysis->score.')',
            'Mode' => strtoupper($analysis->mode->value),
            'Confidence' => strtoupper($analysis->confidenceLevel()->value),
        ]);

        if ($analysis->error) {
            $output->write('  '.$output->error('ReDoS error: '.$analysis->error)."\n");
        }

        $hotspot = $analysis->getPrimaryHotspot();
        if (null !== $hotspot) {
            $output->write('  Hotspot:   '.$hotspot->start.'-'.$hotspot->end."\n");
        }

        if (RedosMode::Confirmed === $analysis->mode && null !== $analysis->confirmation) {
            $this->renderConfirmationSection($output, $style, $analysis->confirmation);
        }

        if ($style->visualsEnabled()) {
            $output->write("\n");
        }

        $style->renderSection('Explanation', 4, $steps);
        $output->write($explain."\n");

        return self::SUCCESS;
    }

    private function renderConfirmationSection(Output $output, ConsoleStyle $style, Confirmation $confirmation): void
    {
        $output->write("\n");
        $style->renderSection('Confirmation', 3, 4);

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

    private function getRedosStatus(RedosAnalysis $analysis): string
    {
        return match (true) {
            RedosMode::Off === $analysis->mode => 'ReDoS analysis disabled',
            \in_array($analysis->severity, [RedosSeverity::Safe, RedosSeverity::Low], true) => 'No significant ReDoS risk detected',
            $analysis->isConfirmed() => 'Confirmed ReDoS risk',
            default => 'Potential ReDoS risk (theoretical)',
        };
    }

    private function handleAnalysisError(Output $output, string $format, string $errorMessage): int
    {
        if ('json' === $format) {
            $json = json_encode(['error' => $errorMessage, 'stage' => 'analyze'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
            $output->write(($json ?: '{"error":"Analyze failed"}')."\n");

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
            RedosSeverity::High, RedosSeverity::Critical => $analysis->isConfirmed()
                ? $output->error($label)
                : $output->warning($label),
            RedosSeverity::Unknown => $output->info($label),
        };

        return $color;
    }
}
