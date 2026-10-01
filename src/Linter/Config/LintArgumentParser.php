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

namespace PhpRegex\Linter\Config;

use PhpRegex\Linter\Extraction\InteropPresets;
use PhpRegex\Linter\Formatter\OutputConfiguration;
use PhpRegex\Parser\Exception\InvalidRegexOptionException;
use PhpRegex\Redos\RedosMode;
use PhpRegex\Redos\RedosSeverity;

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

        for ($i = 0; $i < \count($args); $i++) {
            $arg = $args[$i];

            if ('--help' === $arg || '-h' === $arg) {
                return new LintParseResult(null, null, true);
            }

            if ('--quiet' === $arg || '-q' === $arg) {
                $arguments = $this->withVerbosity($arguments, OutputConfiguration::VERBOSITY_QUIET, true);

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

            if (str_starts_with($arg, '--enable-rule=')) {
                $ruleId = substr($arg, \strlen('--enable-rule='));
                $arguments = $this->withLintRule($arguments, $ruleId, true);

                continue;
            }

            if (str_starts_with($arg, '--disable-rule=')) {
                $ruleId = substr($arg, \strlen('--disable-rule='));
                $arguments = $this->withLintRule($arguments, $ruleId, false);

                continue;
            }

            if ('--no-interop' === $arg) {
                $arguments = $this->withInterop($arguments, []);

                continue;
            }

            if (str_starts_with($arg, '--interop=')) {
                $interop = $this->parseInteropValue(substr($arg, \strlen('--interop=')));
                if (null === $interop) {
                    return new LintParseResult(null, 'Invalid value for --interop. Available presets: '.implode(', ', InteropPresets::names()).'.');
                }

                $arguments = $this->withInterop($arguments, $interop);

                continue;
            }

            if ('--interop' === $arg) {
                $i++;
                if (!isset($args[$i])) {
                    return new LintParseResult(null, 'Missing value for --interop.');
                }

                $interop = $this->parseInteropValue($args[$i]);
                if (null === $interop) {
                    return new LintParseResult(null, 'Invalid value for --interop. Available presets: '.implode(', ', InteropPresets::names()).'.');
                }

                $arguments = $this->withInterop($arguments, $interop);

                continue;
            }

            if (str_starts_with($arg, '--pattern-function=')) {
                $arguments = $this->withPatternFunction($arguments, substr($arg, \strlen('--pattern-function=')));

                continue;
            }

            if ('--pattern-function' === $arg) {
                $i++;
                if (!isset($args[$i])) {
                    return new LintParseResult(null, 'Missing value for --pattern-function.');
                }

                $arguments = $this->withPatternFunction($arguments, $args[$i]);

                continue;
            }

            if ('--redos' === $arg) {
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    true,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );

                continue;
            }

            if ('--no-redos' === $arg) {
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    false,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );

                continue;
            }

            if (str_starts_with($arg, '--redos-mode=')) {
                $mode = $this->redosMode(substr($arg, \strlen('--redos-mode=')));
                if (\is_string($mode)) {
                    return new LintParseResult(null, $mode);
                }
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    true,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $mode->value,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
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
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    true,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $mode->value,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
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
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    $arguments->checkRedos,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $value,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );

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
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    $arguments->checkRedos,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $value,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );
                $i++;

                continue;
            }

            if ('--redos-no-jit' === $arg) {
                return new LintParseResult(null, 'The --redos-no-jit option was removed in 2.0: the lint command always runs the ReDoS confirmation without JIT.');
            }

            if ('--no-validate' === $arg) {
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    $arguments->checkRedos,
                    false,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );

                continue;
            }

            if ('--no-optimize' === $arg) {
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    $arguments->checkRedos,
                    $arguments->checkValidation,
                    false,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );

                continue;
            }

            if (str_starts_with($arg, '--generate-baseline=')) {
                $generateBaseline = substr($arg, \strlen('--generate-baseline='));
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    $arguments->checkRedos,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );

                continue;
            }

            if (str_starts_with($arg, '--baseline=')) {
                $baseline = substr($arg, \strlen('--baseline='));
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    $arguments->checkRedos,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );

                continue;
            }

            if (str_starts_with($arg, '--format=')) {
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    substr($arg, \strlen('--format=')),
                    $arguments->quiet,
                    $arguments->checkRedos,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );

                continue;
            }

            if ('--format' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return new LintParseResult(null, 'Missing value for --format.');
                }
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $value,
                    $arguments->quiet,
                    $arguments->checkRedos,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );
                $i++;

                continue;
            }

            if (str_starts_with($arg, '--exclude=')) {
                $exclude = $arguments->exclude;
                $exclude[] = substr($arg, \strlen('--exclude='));
                $arguments = new LintArguments(
                    $arguments->paths,
                    $exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    $arguments->checkRedos,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );

                continue;
            }

            if ('--exclude' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return new LintParseResult(null, 'Missing value for --exclude.');
                }
                $exclude = $arguments->exclude;
                $exclude[] = $value;
                $arguments = new LintArguments(
                    $arguments->paths,
                    $exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    $arguments->checkRedos,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );
                $i++;

                continue;
            }

            if (str_starts_with($arg, '--min-savings=')) {
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    (int) substr($arg, \strlen('--min-savings=')),
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    $arguments->checkRedos,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );

                continue;
            }

            if (str_starts_with($arg, '--jobs=')) {
                $jobs = (int) substr($arg, \strlen('--jobs='));
                if ($jobs < 1) {
                    return new LintParseResult(null, 'The --jobs value must be a positive integer.');
                }
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    $arguments->checkRedos,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );

                continue;
            }

            if ('--min-savings' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return new LintParseResult(null, 'Missing value for --min-savings.');
                }
                $minSavings = (int) $value;
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    $arguments->checkRedos,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );
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
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    $arguments->checkRedos,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $jobs,
                    $arguments->output,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );
                $i++;

                continue;
            }

            if (str_starts_with($arg, '--output=')) {
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    $arguments->checkRedos,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    substr($arg, \strlen('--output=')),
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );

                continue;
            }

            if ('--output' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return new LintParseResult(null, 'Missing value for --output.');
                }
                $arguments = new LintArguments(
                    $arguments->paths,
                    $arguments->exclude,
                    $arguments->minSavings,
                    $arguments->verbosity,
                    $arguments->format,
                    $arguments->quiet,
                    $arguments->checkRedos,
                    $arguments->checkValidation,
                    $arguments->checkOptimizations,
                    $arguments->checkLint,
                    $arguments->jobs,
                    $value,
                    $arguments->baseline,
                    $arguments->generateBaseline,
                    $arguments->ide,
                    $arguments->optimizations,
                    $arguments->redosMode,
                    $arguments->redosThreshold,
                    $arguments->lintRules,
                    $arguments->interop,
                    $arguments->patternFunctions,
                );
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
            $arguments = new LintArguments(
                $paths,
                $arguments->exclude,
                $arguments->minSavings,
                $arguments->verbosity,
                $arguments->format,
                $arguments->quiet,
                $arguments->checkRedos,
                $arguments->checkValidation,
                $arguments->checkOptimizations,
                $arguments->checkLint,
                $arguments->jobs,
                $arguments->output,
                $arguments->baseline,
                $arguments->generateBaseline,
                $arguments->ide,
                $arguments->optimizations,
                $arguments->redosMode,
                $arguments->redosThreshold,
                $arguments->lintRules,
                $arguments->interop,
                $arguments->patternFunctions,
            );
        }

        return new LintParseResult($arguments);
    }

    /**
     * A --redos-mode value, or the message refusing it.
     */
    private function redosMode(string $value): RedosMode|string
    {
        $mode = RedosMode::tryFrom(strtolower($value));

        return match ($mode) {
            null => 'Invalid value for --redos-mode: expected theoretical or confirmed.',
            RedosMode::Off => 'The --redos-mode=off value was removed in 2.0: use --no-redos.',
            default => $mode,
        };
    }

    /**
     * @param "debug"|"normal"|"quiet"|"verbose" $verbosity
     */
    private function withVerbosity(LintArguments $arguments, string $verbosity, bool $quiet = false): LintArguments
    {
        return new LintArguments(
            $arguments->paths,
            $arguments->exclude,
            $arguments->minSavings,
            $verbosity,
            $arguments->format,
            $quiet ?: $arguments->quiet,
            $arguments->checkRedos,
            $arguments->checkValidation,
            $arguments->checkOptimizations,
            $arguments->checkLint,
            $arguments->jobs,
            $arguments->output,
            $arguments->baseline,
            $arguments->generateBaseline,
            $arguments->ide,
            $arguments->optimizations,
            $arguments->redosMode,
            $arguments->redosThreshold,
            $arguments->lintRules,
            $arguments->interop,
            $arguments->patternFunctions,
        );
    }

    /**
     * Read a comma separated preset list, with "none" disabling every preset.
     *
     * @return array<int, string>|null null when a name is not a known preset
     */
    private function parseInteropValue(string $value): ?array
    {
        $names = array_values(array_filter(array_map(trim(...), explode(',', strtolower($value))), static fn (string $name): bool => '' !== $name));

        if (['none'] === $names || [] === $names) {
            return [];
        }

        foreach ($names as $name) {
            if (!InteropPresets::exists($name)) {
                return null;
            }
        }

        return $names;
    }

    /**
     * @param array<int, string> $interop
     */
    private function withInterop(LintArguments $arguments, array $interop): LintArguments
    {
        return new LintArguments(
            $arguments->paths,
            $arguments->exclude,
            $arguments->minSavings,
            $arguments->verbosity,
            $arguments->format,
            $arguments->quiet,
            $arguments->checkRedos,
            $arguments->checkValidation,
            $arguments->checkOptimizations,
            $arguments->checkLint,
            $arguments->jobs,
            $arguments->output,
            $arguments->baseline,
            $arguments->generateBaseline,
            $arguments->ide,
            $arguments->optimizations,
            $arguments->redosMode,
            $arguments->redosThreshold,
            $arguments->lintRules,
            $interop,
            $arguments->patternFunctions,
        );
    }

    private function withPatternFunction(LintArguments $arguments, string $spec): LintArguments
    {
        $patternFunctions = $arguments->patternFunctions;
        if ('' !== trim($spec)) {
            $patternFunctions[] = $spec;
        }

        return new LintArguments(
            $arguments->paths,
            $arguments->exclude,
            $arguments->minSavings,
            $arguments->verbosity,
            $arguments->format,
            $arguments->quiet,
            $arguments->checkRedos,
            $arguments->checkValidation,
            $arguments->checkOptimizations,
            $arguments->checkLint,
            $arguments->jobs,
            $arguments->output,
            $arguments->baseline,
            $arguments->generateBaseline,
            $arguments->ide,
            $arguments->optimizations,
            $arguments->redosMode,
            $arguments->redosThreshold,
            $arguments->lintRules,
            $arguments->interop,
            $patternFunctions,
        );
    }

    private function withCheckLint(LintArguments $arguments, bool $checkLint): LintArguments
    {
        return new LintArguments(
            $arguments->paths,
            $arguments->exclude,
            $arguments->minSavings,
            $arguments->verbosity,
            $arguments->format,
            $arguments->quiet,
            $arguments->checkRedos,
            $arguments->checkValidation,
            $arguments->checkOptimizations,
            $checkLint,
            $arguments->jobs,
            $arguments->output,
            $arguments->baseline,
            $arguments->generateBaseline,
            $arguments->ide,
            $arguments->optimizations,
            $arguments->redosMode,
            $arguments->redosThreshold,
            $arguments->lintRules,
            $arguments->interop,
            $arguments->patternFunctions,
        );
    }

    private function withLintRule(LintArguments $arguments, string $ruleId, bool $enabled): LintArguments
    {
        $lintRules = $arguments->lintRules;
        $lintRules[$ruleId] = $enabled;

        return new LintArguments(
            $arguments->paths,
            $arguments->exclude,
            $arguments->minSavings,
            $arguments->verbosity,
            $arguments->format,
            $arguments->quiet,
            $arguments->checkRedos,
            $arguments->checkValidation,
            $arguments->checkOptimizations,
            $arguments->checkLint,
            $arguments->jobs,
            $arguments->output,
            $arguments->baseline,
            $arguments->generateBaseline,
            $arguments->ide,
            $arguments->optimizations,
            $arguments->redosMode,
            $arguments->redosThreshold,
            $lintRules,
            $arguments->interop,
            $arguments->patternFunctions,
        );
    }
}
