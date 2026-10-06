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
use PHPRegex\Cli\PcreRuntimeInfo;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Internal\RedosVerdict;
use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Internal\DisplayEscaper;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\Heatmap;
use PHPRegex\Redos\Internal\InputGenerator;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

/**
 * @internal
 */
final class DebugCommand extends AbstractCommand
{
    public function __construct(private readonly ?LintConfigLoader $configLoader = null, private readonly ?LintDefaultsBuilder $defaultsBuilder = null) {}

    public function getName(): string
    {
        return 'debug';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'Deep ReDoS analysis with heatmap output';
    }

    public function run(Input $input, Output $output): int
    {
        // The configuration file's defaults, when there is one to read.
        $defaults = [];
        if (null !== $this->configLoader && null !== $this->defaultsBuilder) {
            $configResult = $this->configLoader->load();
            if (null !== $configResult->error) {
                $output->write($output->error('Error: '.$configResult->error."\n"));

                return self::INVALID;
            }
            $defaults = $this->defaultsBuilder->build($configResult->config);
        }

        $parsed = $this->parseArguments($input->args, $defaults);
        if (null !== $parsed['error']) {
            $output->write($output->error('Error: '.$parsed['error']."\n"));
            $output->write("Usage: regex debug <pattern> [--input <string>] [--format=json] [--redos-mode=off|theoretical|confirmed] [--redos-threshold=low|medium|high|critical]\n");

            return self::INVALID;
        }

        $pattern = $parsed['pattern'];
        $inputValue = $parsed['inputValue'];
        $format = $parsed['format'];
        $redosMode = $parsed['redosMode'];
        $redosThreshold = $parsed['redosThreshold'];
        $confirmOptions = $parsed['confirmOptions'];

        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return self::INVALID;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $meta = [];
        $meta += $this->targetMeta($input, $output);
        $runtime = PcreRuntimeInfo::fromIni();
        $meta['PCRE'] = $output->warning($runtime->version);
        $meta['PCRE JIT'] = $output->warning($runtime->jitSetting ?? 'unknown');
        $meta['Backtrack'] = $output->warning((string) ($runtime->backtrackLimit ?? 'unknown'));
        $meta['Recursion'] = $output->warning((string) ($runtime->recursionLimit ?? 'unknown'));

        if ('json' !== $format) {
            $style->renderBanner('Debug', $meta);
        }

        $target = $regex->target();

        try {
            $patternInfo = DelimitedPattern::fromDelimited($pattern, $target);
            $analysis = $regex->redos($pattern, $redosThreshold, $redosMode, $confirmOptions);
            // The analysis reports a pattern it cannot parse as its error;
            // the exit code reports it as a problem of the pattern.
            $verdict = $this->parses($regex, $pattern) && !$this->isConfirmedRedos($analysis, $redosThreshold)
                ? self::SUCCESS
                : self::FAILURE;
            $hasConfirmation = RedosMode::Confirmed === $analysis->mode && null !== $analysis->confirmation;
            // One number per section printed: Heatmap, then Confirmation and Findings when there are any.
            $steps = 1 + ($hasConfirmation ? 1 : 0) + ([] !== $analysis->findings ? 1 : 0);
            $heatmap = new Heatmap();
            $heatmapBody = $heatmap->highlight($patternInfo->pattern, $analysis->hotspots, $output->isAnsi());
            $heatmapPattern = $patternInfo->delimiter.$heatmapBody.$patternInfo->delimiter.$patternInfo->flags;
            $highlightedPattern = $pattern;
            $showSyntaxPattern = $output->isAnsi() && $style->visualsEnabled();

            if ($showSyntaxPattern) {
                try {
                    $ast = $regex->parse($pattern);
                    $highlightedBody = $ast->accept(new ConsoleHighlighter());
                    $highlightedPattern = $patternInfo->delimiter.$highlightedBody.$patternInfo->delimiter.$patternInfo->flags;
                } catch (LexerException|ParserException) {
                    $highlightedPattern = $pattern;
                }
            }

            $inputSource = '';
            if (null === $inputValue && null !== $analysis->getCulpritNode()) {
                $inputValue = (new InputGenerator())->generate(
                    $analysis->getCulpritNode(),
                    $patternInfo->flags,
                    $analysis->severity,
                );
                $inputSource = ' (auto)';
            }
            $inputSourceLabel = null;
            if (null !== $inputValue) {
                $inputSourceLabel = '' !== $inputSource ? 'auto' : 'user';
            }

            if ('json' === $format) {
                $payload = [
                    'pattern' => $pattern,
                    'runtime' => $runtime,
                    'analysis' => $analysis,
                    'input' => [
                        'value' => $inputValue,
                        'source' => $inputSourceLabel,
                    ],
                ];
                $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
                if (false === $json) {
                    $output->write($output->error("Error: Failed to encode JSON\n"));

                    return self::FAILURE;
                }
                $output->write($json."\n");

                return $verdict;
            }

            $style->renderSection('Heatmap', 1, $steps);
            if ($showSyntaxPattern) {
                $style->renderPattern($highlightedPattern);
            }

            $showHeatmapLine = [] !== $analysis->hotspots || !$showSyntaxPattern;
            if ($showHeatmapLine) {
                $label = $showSyntaxPattern ? 'Heatmap' : 'Pattern';
                $heatmapPrefix = '  '.$label.':    ';
                $output->write($heatmapPrefix.$heatmapPattern."\n");

                // The caret marks the primary hotspot on the line right above it.
                $hotspot = $analysis->getPrimaryHotspot();
                if (null !== $hotspot) {
                    $start = max(0, $hotspot->start);
                    $length = max(1, $hotspot->end - $hotspot->start);
                    $caret = str_repeat(' ', \strlen($heatmapPrefix) + \strlen($patternInfo->delimiter) + $start).str_repeat('^', $length);
                    $caretColor = match ($hotspot->severity) {
                        RedosSeverity::Safe, RedosSeverity::Low => Output::GREEN,
                        RedosSeverity::Medium => Output::YELLOW,
                        RedosSeverity::High, RedosSeverity::Critical => Output::RED,
                        RedosSeverity::Unknown => Output::GRAY,
                    };
                    $output->write($output->color($caret, $caretColor)."\n");
                }
            }

            if (null !== $analysis->error) {
                $output->write('  Error:      '.$output->error($analysis->error)."\n");
            }

            $severityOutput = $this->formatRedosSeverity($analysis, $output);
            $output->write('  Status:    '.$this->redosHeadline($analysis)."\n");
            $output->write('  Severity:  '.$severityOutput.' (score '.$analysis->score.")\n");
            $output->write('  Mode:      '.strtoupper($analysis->mode->value)."\n");
            $output->write('  Confidence: '.strtoupper($analysis->confidenceLevel()->value)."\n");

            if (null !== $analysis->getVulnerableSubpattern()) {
                $output->write('  Culprit:    '.$analysis->getVulnerableSubpattern()."\n");
            }

            if (null !== $analysis->trigger && '' !== $analysis->trigger) {
                $output->write('  Trigger:    '.$analysis->trigger."\n");
            }

            if ([] !== $analysis->hotspots) {
                $output->write('  Hotspots:   '.\count($analysis->hotspots)."\n");
            }

            foreach ($this->redosVerdictLines($analysis) as $line) {
                $output->write('  '.$line."\n");
            }

            if (null !== $inputValue) {
                $escaped = DisplayEscaper::escapeText($inputValue);
                $output->write('  Input:      "'.$escaped.'"'.$inputSource."\n");
            }

            if ($hasConfirmation && null !== $analysis->confirmation) {
                $confirmation = $analysis->confirmation;
                $output->write("\n");
                $style->renderSection('Confirmation', 2, $steps);
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

            if ([] !== $analysis->findings) {
                $output->write("\n");
                $style->renderSection('Findings', $steps, $steps);
                foreach ($analysis->findings as $finding) {
                    $findingSeverity = $this->formatRedosSeverity($analysis, $output, $finding->severity);
                    $output->write('  - ['.$findingSeverity.'] '.$finding->message."\n");
                    if (null !== $finding->suggestedRewrite && '' !== $finding->suggestedRewrite) {
                        $output->write('      Suggested (verify behavior): '.$finding->suggestedRewrite."\n");
                    }
                }
            }
        } catch (\Throwable $e) {
            if ('json' === $format) {
                $json = json_encode(['error' => $e->getMessage(), 'stage' => 'debug'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
                $output->write(($json ?: '{"error":"Debug failed"}')."\n");

                return self::FAILURE;
            }

            $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error('Debug failed: '.$e->getMessage())."\n");

            return self::FAILURE;
        }

        return $verdict;
    }

    private function parses(Regex $regex, string $pattern): bool
    {
        try {
            $regex->parse($pattern);

            return true;
        } catch (LexerException|ParserException) {
            return false;
        }
    }

    /**
     * @param array<int, string>   $args
     * @param array<string, mixed> $defaults
     *
     * @return array{pattern: string, inputValue: ?string, format: string, redosMode: RedosMode, redosThreshold: ?RedosSeverity, confirmOptions: ?ConfirmationOptions, error: ?string}
     */
    private function parseArguments(array $args, array $defaults = []): array
    {
        $pattern = '';
        $inputValue = null;
        $format = 'console';

        // Use config defaults for redosMode, falling back to THEORETICAL
        $defaultMode = RedosMode::Theoretical;
        if (isset($defaults['redosMode']) && \is_string($defaults['redosMode'])) {
            $defaultMode = RedosMode::tryFrom($defaults['redosMode']) ?? RedosMode::Theoretical;
        }
        $redosMode = $defaultMode;
        $redosModeExplicit = false;

        // Use config defaults for redosThreshold
        $redosThreshold = null;
        if (isset($defaults['redosThreshold']) && \is_string($defaults['redosThreshold'])) {
            // regex.json was validated when it was loaded.
            $redosThreshold = RedosSeverity::fromConfig($defaults['redosThreshold']);
        }

        $confirmOptions = null;
        $stopParsing = false;

        for ($i = 0; $i < \count($args); $i++) {
            $arg = $args[$i];

            if (!$stopParsing && '--' === $arg) {
                $stopParsing = true;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--input=')) {
                $inputValue = substr($arg, \strlen('--input='));

                continue;
            }

            if (!$stopParsing && '--input' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return ['pattern' => '', 'inputValue' => null, 'format' => $format, 'redosMode' => $redosMode, 'redosThreshold' => $redosThreshold, 'confirmOptions' => null, 'error' => 'Missing value for --input.'];
                }
                $inputValue = $value;
                $i++;

                continue;
            }

            if (!$stopParsing && ('--json' === $arg)) {
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
                    return ['pattern' => '', 'inputValue' => null, 'format' => $format, 'redosMode' => $redosMode, 'redosThreshold' => $redosThreshold, 'confirmOptions' => null, 'error' => 'Missing value for --format.'];
                }
                $format = strtolower($value);
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--redos-mode=')) {
                $value = strtolower(substr($arg, \strlen('--redos-mode=')));
                $mode = RedosMode::tryFrom($value);
                if (null === $mode) {
                    return ['pattern' => '', 'inputValue' => null, 'format' => $format, 'redosMode' => $redosMode, 'redosThreshold' => $redosThreshold, 'confirmOptions' => null, 'error' => 'Invalid value for --redos-mode.'];
                }
                $redosMode = $mode;

                continue;
            }

            if (!$stopParsing && '--redos-mode' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return ['pattern' => '', 'inputValue' => null, 'format' => $format, 'redosMode' => $redosMode, 'redosThreshold' => $redosThreshold, 'confirmOptions' => null, 'error' => 'Missing value for --redos-mode.'];
                }
                $mode = RedosMode::tryFrom(strtolower($value));
                if (null === $mode) {
                    return ['pattern' => '', 'inputValue' => null, 'format' => $format, 'redosMode' => $redosMode, 'redosThreshold' => $redosThreshold, 'confirmOptions' => null, 'error' => 'Invalid value for --redos-mode.'];
                }
                $redosMode = $mode;
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--redos-threshold=')) {
                try {
                    $redosThreshold = RedosSeverity::fromConfig(substr($arg, \strlen('--redos-threshold=')));
                } catch (InvalidRegexOptionException $e) {
                    return ['pattern' => '', 'inputValue' => null, 'format' => $format, 'redosMode' => $redosMode, 'redosThreshold' => $redosThreshold, 'confirmOptions' => null, 'error' => 'Invalid value for --redos-threshold: '.$e->getMessage()];
                }

                continue;
            }

            if (!$stopParsing && '--redos-threshold' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return ['pattern' => '', 'inputValue' => null, 'format' => $format, 'redosMode' => $redosMode, 'redosThreshold' => $redosThreshold, 'confirmOptions' => null, 'error' => 'Missing value for --redos-threshold.'];
                }

                try {
                    $redosThreshold = RedosSeverity::fromConfig($value);
                } catch (InvalidRegexOptionException $e) {
                    return ['pattern' => '', 'inputValue' => null, 'format' => $format, 'redosMode' => $redosMode, 'redosThreshold' => $redosThreshold, 'confirmOptions' => null, 'error' => 'Invalid value for --redos-threshold: '.$e->getMessage()];
                }
                $i++;

                continue;
            }

            if (!$stopParsing && '--redos-no-jit' === $arg) {
                return ['pattern' => '', 'inputValue' => null, 'format' => $format, 'redosMode' => $redosMode, 'redosThreshold' => $redosThreshold, 'confirmOptions' => null, 'error' => '--redos-no-jit was removed in 2.0: the confirmation always runs without JIT.'];
            }

            if (!$stopParsing && str_starts_with($arg, '-')) {
                return ['pattern' => '', 'inputValue' => null, 'format' => $format, 'redosMode' => $redosMode, 'redosThreshold' => $redosThreshold, 'confirmOptions' => null, 'error' => 'Unknown option: '.$arg];
            }

            if ('' === $pattern) {
                $pattern = $arg;

                continue;
            }
        }

        if ('' === $pattern) {
            return ['pattern' => '', 'inputValue' => null, 'format' => $format, 'redosMode' => $redosMode, 'redosThreshold' => $redosThreshold, 'confirmOptions' => null, 'error' => 'Missing pattern.'];
        }

        if (!\in_array($format, ['console', 'json'], true)) {
            return ['pattern' => '', 'inputValue' => null, 'format' => $format, 'redosMode' => $redosMode, 'redosThreshold' => $redosThreshold, 'confirmOptions' => null, 'error' => 'Invalid value for --format.'];
        }

        return [
            'pattern' => $pattern,
            'inputValue' => $inputValue,
            'format' => $format,
            'redosMode' => $redosMode,
            'redosThreshold' => $redosThreshold,
            'confirmOptions' => $confirmOptions,
            'error' => null,
        ];
    }

    /**
     * The severity, the verdict's own by default, coloured by what stands
     * behind the verdict.
     */
    private function formatRedosSeverity(RedosAnalysis $analysis, Output $output, ?RedosSeverity $severity = null): string
    {
        $severity ??= $analysis->severity;
        $label = strtoupper($severity->value);

        $color = match ($severity) {
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
