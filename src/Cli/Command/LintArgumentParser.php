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

use PHPRegex\Linter\Config\LintArguments;
use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\Rule\RuleCatalog;
use PHPRegex\Linter\Rule\RuleId;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;

/**
 * @internal
 */
final class LintArgumentParser
{
    /**
     * @param array<int, string>   $args
     * @param array<string, mixed> $defaults
     */
    public function parse(array $args, array $defaults = []): LintParseResult
    {
        $arguments = LintArguments::fromDefaults($defaults);
        $pathsProvided = false;
        $warnings = [];

        for ($i = 0; $i < \count($args); $i++) {
            $arg = $args[$i];

            if ('--help' === $arg || '-h' === $arg) {
                return new LintParseResult(null, null, true);
            }

            if ('--quiet' === $arg || '-q' === $arg) {
                $arguments = $this->withVerbosity($arguments, OutputConfiguration::VERBOSITY_QUIET);

                continue;
            }

            if ('--verbose' === $arg || '-v' === $arg) {
                $arguments = $this->withVerbosity($arguments, OutputConfiguration::VERBOSITY_VERBOSE);

                continue;
            }

            if ('--debug' === $arg) {
                $arguments = $this->withVerbosity($arguments, OutputConfiguration::VERBOSITY_DEBUG);

                continue;
            }

            if ('--lint' === $arg) {
                $arguments = $this->withCheckLint($arguments, true);

                continue;
            }

            if ('--no-lint' === $arg) {
                $arguments = $this->withCheckLint($arguments, false);

                continue;
            }

            if (str_starts_with($arg, '--enable-rule=') || str_starts_with($arg, '--disable-rule=')) {
                [$option, $ruleId] = explode('=', $arg, 2);
                if (!RuleCatalog::isConfigurable($ruleId)) {
                    $suggestion = RuleCatalog::suggestion($ruleId);

                    return new LintParseResult(null, \sprintf('Unknown lint rule "%s" in %s.%s', $ruleId, $option, null === $suggestion ? '' : \sprintf(' Did you mean "%s"?', $suggestion)));
                }

                // A rule reads its setting under its short id, as regex.json writes it.
                $arguments = $this->withLintRule($arguments, RuleId::short($ruleId), '--enable-rule' === $option);

                continue;
            }

            if ('--report-unmatched-ignores' === $arg || '--no-report-unmatched-ignores' === $arg) {
                $arguments = $this->withReportUnmatchedIgnores($arguments, '--report-unmatched-ignores' === $arg);

                continue;
            }

            if ('--no-interop' === $arg) {
                $arguments = $this->withInterop($arguments, []);

                continue;
            }

            if (str_starts_with($arg, '--interop=') || '--interop' === $arg) {
                if ('--interop' === $arg && !isset($args[++$i])) {
                    return new LintParseResult(null, 'Missing value for --interop.');
                }

                $interop = $this->parseInteropValue('--interop' === $arg ? $args[$i] : substr($arg, \strlen('--interop=')));
                if (\is_string($interop)) {
                    return new LintParseResult(null, $interop);
                }

                $arguments = $this->withInterop($arguments, $interop);

                continue;
            }

            if (str_starts_with($arg, '--pattern-function=')) {
                $arguments = $this->withPatternFunction($arguments, substr($arg, \strlen('--pattern-function=')), $warnings);

                continue;
            }

            if ('--pattern-function' === $arg) {
                $i++;
                if (!isset($args[$i])) {
                    return new LintParseResult(null, 'Missing value for --pattern-function.');
                }

                $arguments = $this->withPatternFunction($arguments, $args[$i], $warnings);

                continue;
            }

            if ('--redos' === $arg) {
                $arguments = $arguments->with(checkRedos: true);

                continue;
            }

            if ('--no-redos' === $arg) {
                $arguments = $arguments->with(checkRedos: false);

                continue;
            }

            if (str_starts_with($arg, '--redos-mode=')) {
                $mode = $this->redosMode(substr($arg, \strlen('--redos-mode=')));
                if (\is_string($mode)) {
                    return new LintParseResult(null, $mode);
                }
                $arguments = $arguments->with(
                    checkRedos: true,
                    redosMode: $mode->value,
                );

                continue;
            }

            if ('--redos-mode' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return new LintParseResult(null, 'Missing value for --redos-mode.');
                }
                $mode = $this->redosMode($value);
                if (\is_string($mode)) {
                    return new LintParseResult(null, $mode);
                }
                $arguments = $arguments->with(
                    checkRedos: true,
                    redosMode: $mode->value,
                );
                $i++;

                continue;
            }

            if (str_starts_with($arg, '--redos-threshold=')) {
                try {
                    $value = RedosSeverity::fromConfig(substr($arg, \strlen('--redos-threshold=')))->value;
                } catch (InvalidRegexOptionException $e) {
                    return new LintParseResult(null, 'Invalid value for --redos-threshold: '.$e->getMessage());
                }
                $arguments = $arguments->with(redosThreshold: $value);

                continue;
            }

            if ('--redos-threshold' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return new LintParseResult(null, 'Missing value for --redos-threshold.');
                }

                try {
                    $value = RedosSeverity::fromConfig($value)->value;
                } catch (InvalidRegexOptionException $e) {
                    return new LintParseResult(null, 'Invalid value for --redos-threshold: '.$e->getMessage());
                }
                $arguments = $arguments->with(redosThreshold: $value);
                $i++;

                continue;
            }

            if ('--no-validate' === $arg) {
                $arguments = $arguments->with(checkValidation: false);

                continue;
            }

            if ('--no-optimize' === $arg) {
                $arguments = $arguments->with(checkOptimizations: false);

                continue;
            }

            if ('--generate-baseline' === $arg || str_starts_with($arg, '--generate-baseline=')) {
                $generateBaseline = $this->fileValue($args, $i, '--generate-baseline');
                if (null === $generateBaseline) {
                    return new LintParseResult(null, 'Missing value for --generate-baseline.');
                }
                $arguments = $arguments->with(generateBaseline: $generateBaseline);

                continue;
            }

            if ('--baseline' === $arg || str_starts_with($arg, '--baseline=')) {
                $baseline = $this->fileValue($args, $i, '--baseline');
                if (null === $baseline) {
                    return new LintParseResult(null, 'Missing value for --baseline.');
                }
                $arguments = $arguments->with(baseline: $baseline);

                continue;
            }

            // The other commands' --json: an alias of --format=json.
            if ('--json' === $arg) {
                $arguments = $this->withFormat($arguments, 'json');

                continue;
            }

            if (str_starts_with($arg, '--format=')) {
                $arguments = $this->withFormat($arguments, substr($arg, \strlen('--format=')));

                continue;
            }

            if ('--format' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return new LintParseResult(null, 'Missing value for --format.');
                }
                $arguments = $this->withFormat($arguments, $value);
                $i++;

                continue;
            }

            if (str_starts_with($arg, '--exclude=')) {
                $exclude = $arguments->exclude;
                $exclude[] = substr($arg, \strlen('--exclude='));
                $arguments = $arguments->with(exclude: $exclude);

                continue;
            }

            if ('--exclude' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return new LintParseResult(null, 'Missing value for --exclude.');
                }
                $exclude = $arguments->exclude;
                $exclude[] = $value;
                $arguments = $arguments->with(exclude: $exclude);
                $i++;

                continue;
            }

            if (str_starts_with($arg, '--min-savings=')) {
                $arguments = $arguments->with(minSavings: (int) substr($arg, \strlen('--min-savings=')));

                continue;
            }

            if (str_starts_with($arg, '--jobs=')) {
                $jobs = (int) substr($arg, \strlen('--jobs='));
                if ($jobs < 1) {
                    return new LintParseResult(null, 'The --jobs value must be a positive integer.');
                }
                $arguments = $arguments->with(jobs: $jobs);

                continue;
            }

            if ('--min-savings' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return new LintParseResult(null, 'Missing value for --min-savings.');
                }
                $minSavings = (int) $value;
                $arguments = $arguments->with(minSavings: $minSavings);
                $i++;

                continue;
            }

            if ('--jobs' === $arg || '-j' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return new LintParseResult(null, 'Missing value for --jobs.');
                }
                $jobs = (int) $value;
                if ($jobs < 1) {
                    return new LintParseResult(null, 'The --jobs value must be a positive integer.');
                }
                $arguments = $arguments->with(jobs: $jobs);
                $i++;

                continue;
            }

            if (str_starts_with($arg, '--output=')) {
                $arguments = $arguments->with(output: substr($arg, \strlen('--output=')));

                continue;
            }

            if ('--output' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return new LintParseResult(null, 'Missing value for --output.');
                }
                $arguments = $arguments->with(output: $value);
                $i++;

                continue;
            }

            if (str_starts_with($arg, '-')) {
                return new LintParseResult(null, 'Unknown option: '.$arg);
            }

            $paths = $arguments->paths;
            if (!$pathsProvided) {
                $paths = [];
                $pathsProvided = true;
            }
            $paths[] = $arg;
            $arguments = $arguments->with(paths: $paths);
        }

        return new LintParseResult($arguments, pathsGiven: $pathsProvided, warnings: $warnings);
    }

    /**
     * The file a baseline option names, as "--option=file" or as
     * "--option file" (then $i moves past the file); null when there is no
     * file: an empty "=" value, or nothing but an option after the space.
     *
     * @param array<int, string> $args
     */
    private function fileValue(array $args, int &$i, string $option): ?string
    {
        if (str_starts_with($args[$i], $option.'=')) {
            $value = substr($args[$i], \strlen($option) + 1);

            return '' === $value ? null : $value;
        }

        $value = $args[$i + 1] ?? '';
        if ('' === $value || str_starts_with($value, '-')) {
            return null;
        }
        $i++;

        return $value;
    }

    /**
     * A --redos-mode value, or the message refusing it.
     */
    private function redosMode(string $value): RedosMode|string
    {
        $mode = RedosMode::tryFrom(Ascii::lower($value));

        return match ($mode) {
            null => 'Invalid value for --redos-mode: expected theoretical or confirmed.',
            RedosMode::Off => 'Invalid value for --redos-mode: expected theoretical or confirmed; --no-redos skips the analysis.',
            default => $mode,
        };
    }

    private function withFormat(LintArguments $arguments, string $format): LintArguments
    {
        return $arguments->with(format: $format);
    }

    /**
     * @param "debug"|"normal"|"quiet"|"verbose" $verbosity
     */
    private function withVerbosity(LintArguments $arguments, string $verbosity): LintArguments
    {
        return $arguments->with(verbosity: $verbosity);
    }

    /**
     * Read a comma separated preset list, with "none" disabling every preset.
     * A name that is no preset is an error, which the message gives back.
     *
     * @return array<int, string>|string the presets, or the error
     */
    private function parseInteropValue(string $value): array|string
    {
        $names = array_values(array_filter(array_map(static fn (string $v): string => trim($v, " \t\n\r\0\x0B"), explode(',', $value)), static fn (string $name): bool => '' !== $name));

        if (['none'] === array_map(Ascii::lower(...), $names) || [] === $names) {
            return [];
        }

        $presets = [];
        foreach ($names as $name) {
            if (!InteropPresets::exists(Ascii::lower($name))) {
                return InteropPresets::unknownError($name, '--interop');
            }

            $presets[] = Ascii::lower($name);
        }

        return $presets;
    }

    /**
     * @param array<int, string> $interop
     */
    private function withInterop(LintArguments $arguments, array $interop): LintArguments
    {
        return $arguments->with(interop: $interop);
    }

    /**
     * A spec the registry cannot read is left out, with a warning.
     *
     * @param list<string> $warnings
     */
    private function withPatternFunction(LintArguments $arguments, string $spec, array &$warnings): LintArguments
    {
        $patternFunctions = $arguments->patternFunctions;
        $warning = PatternFunctionRegistry::malformedSpecWarning($spec);
        if (PatternFunctionRegistry::isSpec($spec)) {
            $patternFunctions[] = $spec;
        } elseif ('' !== trim($spec, " \t\n\r\0\x0B") && !\in_array($warning, $warnings, true)) {
            $warnings[] = $warning;
        }

        return $arguments->with(patternFunctions: $patternFunctions);
    }

    private function withCheckLint(LintArguments $arguments, bool $checkLint): LintArguments
    {
        return $arguments->with(checkLint: $checkLint);
    }

    private function withReportUnmatchedIgnores(LintArguments $arguments, bool $report): LintArguments
    {
        return $arguments->with(reportUnmatchedIgnores: $report);
    }

    private function withLintRule(LintArguments $arguments, string $ruleId, bool $enabled): LintArguments
    {
        $lintRules = $arguments->lintRules;
        $lintRules[$ruleId] = $enabled;

        return $arguments->with(lintRules: $lintRules);
    }
}
