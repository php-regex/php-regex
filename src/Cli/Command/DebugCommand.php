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
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\RedosVerdict;
use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\DisplayEscaper;
use PHPRegex\Redos\Heatmap;
use PHPRegex\Redos\InputGenerator;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;

/**
 * @phpstan-import-type LintDefaults from LintDefaultsBuilder
 *
 * @phpstan-type DebugArguments array{pattern: string, inputValue: ?string, format: string, redosMode: RedosMode, redosThreshold: ?RedosSeverity, error: ?string}
 *
 * @internal
 */
final class DebugCommand extends AbstractCommand implements JsonCommandInterface
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

    /**
     * The options of the command, which its help lists.
     */
    public static function options(): Options
    {
        return new Options(Arguments::Pattern, [
            Option::value('--input', '<string>', 'Input string to test against the pattern'),
            Option::value('--format', '<format>', 'Output format (console, json)'),
            Option::flag('--json', 'Same as --format=json'),
            ...self::redosOptions(),
            ...GlobalOptionsParser::target(),
        ], "Usage: regex debug <pattern> [--input <string>] [--format=json] [--redos-mode=off|theoretical|confirmed] [--redos-threshold=low|medium|high|critical]\n");
    }

    public function run(Input $input, Output $output): int
    {
        $json = JsonRequest::in($input->args);

        // The configuration file's defaults, when there is one to read.
        $defaults = [];
        if (null !== $this->configLoader && null !== $this->defaultsBuilder) {
            $configResult = $this->configLoader->load();
            if (null !== $configResult->error) {
                if ($json) {
                    $output->writeDocument(JsonDocument::error($configResult->error, JsonDocument::STAGE_CONFIG));
                } else {
                    $output->writeError($output->error('Error: '.$configResult->error."\n"));
                }

                return self::INVALID;
            }

            // What the file holds that is ignored, as regex lint says it:
            // on stderr, silenced by -q, and the command goes on.
            if (!$output->isQuiet()) {
                foreach ($configResult->warnings as $warning) {
                    $output->writeError('Warning: '.$warning."\n");
                }
            }

            $defaults = $this->defaultsBuilder->build($configResult->config);
        }

        $parsed = $this->parseArguments($input->args, $defaults);
        if (null !== $parsed['error']) {
            return $this->usageError($output, $parsed['error'], self::options()->usage, $json);
        }

        $pattern = $parsed['pattern'];
        $inputValue = $parsed['inputValue'];
        $format = $parsed['format'];
        $redosMode = $parsed['redosMode'];
        $redosThreshold = $parsed['redosThreshold'];

        $regex = $this->createRegex($output, $input->regexOptions, $json);
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
            $style->renderBanner('debug', $meta);
        }

        $target = $regex->target();
        $validation = $regex->validate($pattern);

        // The report holds an invalid pattern, with no analysis of it.
        if ('json' === $format && !$validation->isValid) {
            $output->writeDocument(JsonDocument::encode([
                'pattern' => $pattern,
                'runtime' => $runtime,
                'validation' => $validation,
                'analysis' => null,
                'input' => [
                    'value' => $inputValue,
                    'source' => null === $inputValue ? null : 'user',
                ],
            ]));

            return self::FAILURE;
        }

        try {
            $patternInfo = DelimitedPattern::fromDelimited($pattern, $target);
            $analysis = $regex->redos($pattern, $redosThreshold, $redosMode);
            // The analysis reports a pattern it cannot parse as its error;
            // the exit code reports an invalid pattern, syntax or semantic
            // error alike, as a problem of the pattern.
            $verdict = $validation->isValid && !$this->isConfirmedRedos($analysis, $redosThreshold)
                ? self::SUCCESS
                : self::FAILURE;
            $hasConfirmation = RedosMode::Confirmed === $analysis->mode && null !== $analysis->confirmation;
            // One number per section printed: Heatmap, then Confirmation and Findings when there are any.
            $steps = 1 + ($hasConfirmation ? 1 : 0) + ([] !== $analysis->findings ? 1 : 0);
            // A proof of a linear attempt leaves no hotspot, finding or
            // culprit in the analysis: what it holds is printed as it is.
            $hotspots = $analysis->hotspots;
            $heatmap = new Heatmap();
            $heatmapBody = $heatmap->highlight($patternInfo->pattern, $hotspots, $output->isAnsi());
            $heatmapPattern = $patternInfo->delimiter.$heatmapBody.$patternInfo->delimiter.$patternInfo->flags;
            $highlightedPattern = $pattern;
            $showSyntaxPattern = $output->isAnsi() && $style->visualsEnabled();

            if ($showSyntaxPattern) {
                try {
                    $ast = $regex->parse($pattern);
                    $highlightedBody = $ast->accept(new ConsoleHighlighter());
                    $highlightedPattern = $patternInfo->delimiter.$highlightedBody.$patternInfo->delimiter.$patternInfo->flags;
                } catch (LexerException|LimitException|ParserException) {
                    $highlightedPattern = $pattern;
                }
            }

            $inputSource = '';
            if (null === $inputValue && null !== $analysis->culpritNode) {
                $inputValue = (new InputGenerator())->generate(
                    $analysis->culpritNode,
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
                $output->writeDocument(JsonDocument::encode([
                    'pattern' => $pattern,
                    'runtime' => $runtime,
                    'validation' => $validation,
                    'analysis' => $analysis,
                    'input' => [
                        'value' => $inputValue,
                        'source' => $inputSourceLabel,
                    ],
                ]));

                return $verdict;
            }

            $style->renderSection('Heatmap', 1, $steps);
            if ($showSyntaxPattern) {
                $style->renderPattern($highlightedPattern);
            }

            $showHeatmapLine = [] !== $hotspots || !$showSyntaxPattern;
            if ($showHeatmapLine) {
                $label = $showSyntaxPattern ? 'Heatmap' : 'Pattern';
                $heatmapPrefix = '  '.$label.':    ';
                $output->write($heatmapPrefix.$heatmapPattern."\n");

                // The caret marks the primary hotspot on the line right above it.
                $hotspot = $analysis->primaryHotspot();
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

            // An invalid pattern is reported by the validator's message, as
            // the other commands report it.
            $error = $validation->isValid ? $analysis->error : $validation->error;
            if (null !== $error) {
                $output->write('  Error:      '.$output->error($error)."\n");
            }

            $severityOutput = $this->formatRedosSeverity($analysis, $output);
            $output->write('  Status:    '.$this->redosHeadline($analysis)."\n");
            $output->write('  Severity:  '.$severityOutput.' (score '.$analysis->score.")\n");
            $output->write('  Mode:      '.Ascii::upper($analysis->mode->value)."\n");
            $output->write('  Confidence: '.Ascii::upper($analysis->confidence->value)."\n");

            if (null !== $analysis->vulnerablePart) {
                $output->write('  Culprit:    '.$analysis->vulnerablePart."\n");
            }

            if (null !== $analysis->trigger && '' !== $analysis->trigger) {
                $output->write('  Trigger:    '.$analysis->trigger."\n");
            }

            if ([] !== $hotspots) {
                $output->write('  Hotspots:   '.\count($hotspots)."\n");
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
                // Only a failure of the library itself reaches here in JSON mode: the
                // pattern is valid and the ReDoS analysis reports its own errors.
                $output->writeDocument(JsonDocument::error($e->getMessage(), JsonDocument::STAGE_INTERNAL));

                return self::FAILURE;
            }

            $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error('Debug failed: '.$e->getMessage())."\n");

            return self::FAILURE;
        }

        return $verdict;
    }

    /**
     * @param array<int, string> $args
     * @param LintDefaults       $defaults
     *
     * @return DebugArguments
     */
    private function parseArguments(array $args, array $defaults = []): array
    {
        $inputValue = null;
        $format = 'console';

        // Use config defaults for redosMode, falling back to THEORETICAL
        $defaultMode = RedosMode::Theoretical;
        if (isset($defaults['redosMode']) && \is_string($defaults['redosMode'])) {
            $defaultMode = RedosMode::tryFrom($defaults['redosMode']) ?? RedosMode::Theoretical;
        }
        $redosMode = $defaultMode;

        // Use config defaults for redosThreshold
        $redosThreshold = null;
        if (isset($defaults['redosThreshold']) && \is_string($defaults['redosThreshold'])) {
            // regex.json was validated when it was loaded.
            $redosThreshold = RedosSeverity::fromConfig($defaults['redosThreshold']);
        }

        $parsed = self::options()->parse($args);
        foreach ($parsed->options as $option) {
            if ('--input' === $option->name) {
                $inputValue = $option->value;
            } elseif ('--json' === $option->name) {
                $format = 'json';
            } elseif ('--format' === $option->name) {
                $format = Ascii::lower((string) $option->value);
            } else {
                $error = $this->readRedosOption($option, $redosMode, $redosThreshold);
                if (null !== $error) {
                    return self::errorResult($error);
                }
            }
        }

        if (null !== $parsed->error) {
            return self::errorResult($parsed->error);
        }

        if (!\in_array($format, ['console', 'json'], true)) {
            return self::errorResult('Invalid value for --format.');
        }

        return [
            'pattern' => $parsed->argument(),
            'inputValue' => $inputValue,
            'format' => $format,
            'redosMode' => $redosMode,
            'redosThreshold' => $redosThreshold,
            'error' => null,
        ];
    }

    /**
     * @return DebugArguments
     */
    private static function errorResult(string $error): array
    {
        return ['pattern' => '', 'inputValue' => null, 'format' => 'console', 'redosMode' => RedosMode::Theoretical, 'redosThreshold' => null, 'error' => $error];
    }

    /**
     * The severity, the verdict's own by default, coloured by what stands
     * behind the verdict.
     */
    private function formatRedosSeverity(RedosAnalysis $analysis, Output $output, ?RedosSeverity $severity = null): string
    {
        $severity ??= $analysis->severity;
        $label = Ascii::upper($severity->value);

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
