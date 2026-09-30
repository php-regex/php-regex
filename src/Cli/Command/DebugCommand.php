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
use RegexParser\Exception\InvalidRegexOptionException;
use RegexParser\Exception\LexerException;
use RegexParser\Exception\ParserException;
use RegexParser\Internal\DisplayEscaper;
use RegexParser\Lint\Command\LintConfigLoader;
use RegexParser\Lint\Command\LintDefaultsBuilder;
use RegexParser\NodeVisitor\ConsoleHighlighterVisitor;
use RegexParser\ReDoS\ReDoSAnalysis;
use RegexParser\ReDoS\ReDoSConfirmOptions;
use RegexParser\ReDoS\ReDoSHeatmap;
use RegexParser\ReDoS\ReDoSHotspot;
use RegexParser\ReDoS\ReDoSInputGenerator;
use RegexParser\ReDoS\ReDoSMode;
use RegexParser\ReDoS\ReDoSSeverity;
use RegexParser\RegexPattern;
use RegexParser\Runtime\PcreRuntimeInfo;

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
        // Load config file defaults if available
        $defaults = [];
        if (null !== $this->configLoader && null !== $this->defaultsBuilder) {
            $configResult = $this->configLoader->load();
            if (null === $configResult->error) {
                $defaults = $this->defaultsBuilder->build($configResult->config);
            }
        }

        $parsed = $this->parseArguments($input->args, $defaults);
        if (null !== $parsed['error']) {
            $output->write($output->error('Error: '.$parsed['error']."\n"));
            $output->write("Usage: regex debug <pattern> [--input <string>] [--format=json] [--redos-mode=off|theoretical|confirmed] [--redos-threshold=low|medium|high|critical] [--redos-no-jit]\n");

            return 1;
        }

        $pattern = $parsed['pattern'];
        $inputValue = $parsed['inputValue'];
        $format = $parsed['format'];
        $redosMode = $parsed['redosMode'];
        $redosThreshold = $parsed['redosThreshold'];
        $confirmOptions = $parsed['confirmOptions'];

        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return 1;
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
            $patternInfo = RegexPattern::fromDelimited($pattern, $target);
            $analysis = $regex->redos($pattern, $redosThreshold, $redosMode, $confirmOptions);
            $steps = [] !== $analysis->findings ? 2 : 1;
            $heatmap = new ReDoSHeatmap();
            $heatmapBody = $heatmap->highlight($patternInfo->pattern, $analysis->hotspots, $output->isAnsi());
            $heatmapPattern = $patternInfo->delimiter.$heatmapBody.$patternInfo->delimiter.$patternInfo->flags;
            $highlightedPattern = $pattern;
            $showSyntaxPattern = $output->isAnsi() && $style->visualsEnabled();

            if ($showSyntaxPattern) {
                try {
                    $ast = $regex->parse($pattern);
                    $highlightedBody = $ast->accept(new ConsoleHighlighterVisitor());
                    $highlightedPattern = $patternInfo->delimiter.$highlightedBody.$patternInfo->delimiter.$patternInfo->flags;
                } catch (LexerException|ParserException) {
                    $highlightedPattern = $pattern;
                }
            }

            $inputSource = '';
            if (null === $inputValue && null !== $analysis->getCulpritNode()) {
                $inputValue = (new ReDoSInputGenerator())->generate(
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

                    return 1;
                }
                $output->write($json."\n");

                return 0;
            }

            $style->renderSection('Heatmap', 1, $steps);
            if ($showSyntaxPattern) {
                $style->renderPattern($highlightedPattern);
            }

            $showHeatmapLine = [] !== $analysis->hotspots || !$showSyntaxPattern;
            $heatmapPrefix = '';
            if ($showHeatmapLine) {
                $label = $showSyntaxPattern ? 'Heatmap' : 'Pattern';
                $heatmapPrefix = '  '.$label.':    ';
                $output->write($heatmapPrefix.$heatmapPattern."\n");
            }

            if (null !== $analysis->error) {
                $output->write('  Error:      '.$output->error($analysis->error)."\n");
            }

            $severityOutput = $this->formatRedosSeverity($analysis, $output);
            $status = match (true) {
                ReDoSMode::OFF === $analysis->mode => 'ReDoS analysis disabled',
                \in_array($analysis->severity, [ReDoSSeverity::SAFE, ReDoSSeverity::LOW], true) => 'No significant ReDoS risk detected',
                $analysis->isConfirmed() => 'Confirmed ReDoS risk',
                default => 'Potential ReDoS risk (theoretical)',
            };

            $output->write('  Status:    '.$status."\n");
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

            if (null !== $inputValue) {
                $escaped = DisplayEscaper::escape($inputValue);
                $output->write('  Input:      "'.$escaped.'"'.$inputSource."\n");
            }

            if (ReDoSMode::CONFIRMED === $analysis->mode && null !== $analysis->confirmation) {
                $confirmation = $analysis->confirmation;
                $output->write("\n");
                $style->renderSection('Confirmation', $steps, $steps);
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

            $hotspot = null;
            $hotspotRank = -1;
            foreach ($analysis->hotspots as $candidate) {
                if (!$candidate instanceof ReDoSHotspot) {
                    continue;
                }
                $rank = match ($candidate->severity) {
                    ReDoSSeverity::SAFE => 0,
                    ReDoSSeverity::LOW => 1,
                    ReDoSSeverity::MEDIUM => 2,
                    ReDoSSeverity::HIGH => 3,
                    ReDoSSeverity::CRITICAL => 4,
                    ReDoSSeverity::UNKNOWN => 1,
                };
                if ($rank > $hotspotRank) {
                    $hotspotRank = $rank;
                    $hotspot = $candidate;
                }
            }

            if (null !== $hotspot && '' !== $heatmapPrefix) {
                $prefix = $heatmapPrefix;
                $start = max(0, $hotspot->start);
                $length = max(1, $hotspot->end - $hotspot->start);
                $caret = str_repeat(' ', \strlen($prefix) + 1 + $start).str_repeat('^', $length);
                $caretColor = match ($hotspot->severity) {
                    ReDoSSeverity::SAFE, ReDoSSeverity::LOW => Output::GREEN,
                    ReDoSSeverity::MEDIUM => Output::YELLOW,
                    ReDoSSeverity::HIGH, ReDoSSeverity::CRITICAL => Output::RED,
                    ReDoSSeverity::UNKNOWN => Output::GRAY,
                };
                $output->write($output->color($caret, $caretColor)."\n");
            }

            if ([] !== $analysis->findings) {
                $output->write("\n");
                $style->renderSection('Findings', $steps, $steps);
                foreach ($analysis->findings as $finding) {
                    $label = strtoupper($finding->severity->value);
                    $findingSeverity = match ($finding->severity) {
                        ReDoSSeverity::SAFE, ReDoSSeverity::LOW => $output->success($label),
                        ReDoSSeverity::MEDIUM => $output->warning($label),
                        ReDoSSeverity::HIGH, ReDoSSeverity::CRITICAL => $analysis->isConfirmed()
                            ? $output->error($label)
                            : $output->warning($label),
                        ReDoSSeverity::UNKNOWN => $output->info($label),
                    };
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

                return 1;
            }

            $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error('Debug failed: '.$e->getMessage())."\n");

            return 1;
        }

        return 0;
    }

    /**
     * @param array<int, string>   $args
     * @param array<string, mixed> $defaults
     *
     * @return array{pattern: string, inputValue: ?string, format: string, redosMode: ReDoSMode, redosThreshold: ?ReDoSSeverity, confirmOptions: ?ReDoSConfirmOptions, error: ?string}
     */
    private function parseArguments(array $args, array $defaults = []): array
    {
        $pattern = '';
        $inputValue = null;
        $format = 'console';

        // Use config defaults for redosMode, falling back to THEORETICAL
        $defaultMode = ReDoSMode::THEORETICAL;
        if (isset($defaults['redosMode']) && \is_string($defaults['redosMode'])) {
            $defaultMode = ReDoSMode::tryFrom($defaults['redosMode']) ?? ReDoSMode::THEORETICAL;
        }
        $redosMode = $defaultMode;
        $redosModeExplicit = false;

        // Use config defaults for redosThreshold
        $redosThreshold = null;
        if (isset($defaults['redosThreshold']) && \is_string($defaults['redosThreshold'])) {
            // regex.json was validated when it was loaded.
            $redosThreshold = ReDoSSeverity::fromConfig($defaults['redosThreshold']);
        }

        $disableJit = false;

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
                $mode = ReDoSMode::tryFrom($value);
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
                $mode = ReDoSMode::tryFrom(strtolower($value));
                if (null === $mode) {
                    return ['pattern' => '', 'inputValue' => null, 'format' => $format, 'redosMode' => $redosMode, 'redosThreshold' => $redosThreshold, 'confirmOptions' => null, 'error' => 'Invalid value for --redos-mode.'];
                }
                $redosMode = $mode;
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--redos-threshold=')) {
                try {
                    $redosThreshold = ReDoSSeverity::fromConfig(substr($arg, \strlen('--redos-threshold=')));
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
                    $redosThreshold = ReDoSSeverity::fromConfig($value);
                } catch (InvalidRegexOptionException $e) {
                    return ['pattern' => '', 'inputValue' => null, 'format' => $format, 'redosMode' => $redosMode, 'redosThreshold' => $redosThreshold, 'confirmOptions' => null, 'error' => 'Invalid value for --redos-threshold: '.$e->getMessage()];
                }
                $i++;

                continue;
            }

            if (!$stopParsing && '--redos-no-jit' === $arg) {
                $disableJit = true;

                continue;
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

        if ($disableJit) {
            $confirmOptions = new ReDoSConfirmOptions(disableJit: true);
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

    private function formatRedosSeverity(ReDoSAnalysis $analysis, Output $output): string
    {
        $label = strtoupper($analysis->severity->value);

        $color = match ($analysis->severity) {
            ReDoSSeverity::SAFE, ReDoSSeverity::LOW => $output->success($label),
            ReDoSSeverity::MEDIUM => $output->warning($label),
            ReDoSSeverity::HIGH, ReDoSSeverity::CRITICAL => $analysis->isConfirmed()
                ? $output->error($label)
                : $output->warning($label),
            ReDoSSeverity::UNKNOWN => $output->info($label),
        };

        return $color;
    }
}
