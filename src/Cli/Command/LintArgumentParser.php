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

use PHPRegex\Cli\Option\Arguments;
use PHPRegex\Cli\Option\Option;
use PHPRegex\Cli\Option\Options;
use PHPRegex\Cli\Option\ParsedOption;
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
     * The options of `regex lint`, which its help lists.
     */
    public static function options(): Options
    {
        return new Options(Arguments::Paths, [
            Option::value('--exclude', '<path>', 'Paths to exclude (repeatable)'),
            Option::value('--min-savings', '<n>', 'Minimum optimization savings'),
            Option::value('--jobs', '<n>', 'Parallel workers for analysis', ['-j']),
            Option::value('--format', '<format>', 'Output format (console, json, github, checkstyle, junit)'),
            Option::flag('--json', 'Same as --format=json'),
            Option::value('--output', '<file>', 'Write output to file'),
            Option::value('--baseline', '<file>', 'Leave out the issues a baseline file lists'),
            Option::value('--generate-baseline', '<file>', 'Write the issues found to a baseline file'),
            Option::flag('--redos', 'Run the ReDoS risk analysis, off by default'),
            Option::flag('--no-redos', 'Skip the ReDoS risk analysis when regex.json turns it on'),
            Option::value('--redos-mode', '<mode>', 'ReDoS mode (theoretical, confirmed)'),
            Option::value('--redos-threshold', '<sev>', 'Lowest ReDoS severity reported (low, medium, high, critical)'),
            Option::flag('--no-validate', 'Skip validation errors (structural lint only)'),
            Option::flag('--no-optimize', 'Disable optimization suggestions'),
            Option::flag('--lint', 'Run the lint rules (the default)'),
            Option::flag('--no-lint', 'Skip the lint rules'),
            Option::value('--enable-rule', '<id>', 'Turn a lint rule on (repeatable)')->attachedOnly(),
            Option::value('--disable-rule', '<id>', 'Turn a lint rule off (repeatable)')->attachedOnly(),
            Option::value('--interop', '<presets>', 'Libraries to read patterns from, all by default (composer-pcre, nette-utils, spatie-regex, illuminate-support, phpunit, webmozart-assert, beberlei-assert, symfony-validator, none)')->takingAnyValue(),
            Option::flag('--no-interop', 'Read patterns from the native calls only (preg_*, RegexIterator, filter_var())'),
            Option::value('--pattern-function', '<spec>', 'Extra call carrying a pattern, e.g. App\\Str::matches#1 (repeatable)')->takingAnyValue(),
            Option::flag('--verbose', 'Show detailed output', ['-v']),
            Option::flag('--debug', 'Show debug information'),
            Option::flag('--quiet', '', ['-q'])->hide(),
            Option::flag('--report-unmatched-ignores')->hide(),
            Option::flag('--no-report-unmatched-ignores')->hide(),
            Option::flag('--help', '', ['-h'])->hide(),
        ], "Usage: regex lint [paths...] [--exclude <path>] [--min-savings <n>] [--jobs <n>] [--format console|json|github|checkstyle|junit] [--json] [--output <file>] [--baseline <file>] [--generate-baseline <file>] [--no-report-unmatched-ignores] [--redos] [--no-redos] [--redos-mode=theoretical|confirmed] [--redos-threshold=low|medium|high|critical] [--no-validate] [--no-optimize] [--lint|--no-lint] [--enable-rule=<id>] [--disable-rule=<id>] [--interop <presets>] [--no-interop] [--pattern-function <spec>] [--verbose|--debug|--quiet]\n");
    }

    /**
     * @param array<int, string>   $args
     * @param array<string, mixed> $defaults
     */
    public function parse(array $args, array $defaults = []): LintParseResult
    {
        $parsed = self::options()->parse($args);
        $arguments = LintArguments::fromDefaults($defaults);
        $warnings = [];

        foreach ($parsed->options as $option) {
            if ('--help' === $option->name) {
                return new LintParseResult(null, null, true);
            }

            $read = $this->apply($arguments, $option, $warnings);
            if (\is_string($read)) {
                return new LintParseResult(null, $read);
            }
            $arguments = $read;
        }

        if (null !== $parsed->error) {
            return new LintParseResult(null, $parsed->error);
        }

        $pathsGiven = [] !== $parsed->arguments;
        if ($pathsGiven) {
            $arguments = $arguments->with(paths: $parsed->arguments);
        }

        return new LintParseResult($arguments, pathsGiven: $pathsGiven, warnings: $warnings);
    }

    /**
     * The arguments with the option applied, or the message refusing it.
     *
     * @param list<string> $warnings
     */
    private function apply(LintArguments $arguments, ParsedOption $option, array &$warnings): LintArguments|string
    {
        $value = (string) $option->value;

        return match ($option->name) {
            '--quiet' => $this->withVerbosity($arguments, OutputConfiguration::VERBOSITY_QUIET),
            '--verbose' => $this->withVerbosity($arguments, OutputConfiguration::VERBOSITY_VERBOSE),
            '--debug' => $this->withVerbosity($arguments, OutputConfiguration::VERBOSITY_DEBUG),
            '--lint', '--no-lint' => $this->withCheckLint($arguments, '--lint' === $option->name),
            '--enable-rule', '--disable-rule' => $this->withRule($arguments, $option->name, $value),
            '--report-unmatched-ignores', '--no-report-unmatched-ignores' => $this->withReportUnmatchedIgnores($arguments, '--report-unmatched-ignores' === $option->name),
            '--no-interop' => $this->withInterop($arguments, []),
            '--interop' => $this->withInteropValue($arguments, $value),
            '--pattern-function' => $this->withPatternFunction($arguments, $value, $warnings),
            '--redos', '--no-redos' => $arguments->with(checkRedos: '--redos' === $option->name),
            '--redos-mode' => $this->withRedosMode($arguments, $value),
            '--redos-threshold' => $this->withRedosThreshold($arguments, $value),
            '--no-validate' => $arguments->with(checkValidation: false),
            '--no-optimize' => $arguments->with(checkOptimizations: false),
            // A baseline option given "=" and nothing names no file.
            '--generate-baseline' => '' === $value ? 'Missing value for --generate-baseline.' : $arguments->with(generateBaseline: $value),
            '--baseline' => '' === $value ? 'Missing value for --baseline.' : $arguments->with(baseline: $value),
            // The other commands' --json: an alias of --format=json.
            '--json' => $this->withFormat($arguments, 'json'),
            '--format' => $this->withFormat($arguments, $value),
            '--exclude' => $arguments->with(exclude: [...$arguments->exclude, $value]),
            '--min-savings' => $arguments->with(minSavings: (int) $value),
            '--jobs' => (int) $value < 1 ? 'The --jobs value must be a positive integer.' : $arguments->with(jobs: (int) $value),
            default => $arguments->with(output: $value),
        };
    }

    /**
     * The arguments with the rule turned on or off, or the message refusing
     * a rule no rules map may name.
     */
    private function withRule(LintArguments $arguments, string $option, string $ruleId): LintArguments|string
    {
        if (!RuleCatalog::isConfigurable($ruleId)) {
            $suggestion = RuleCatalog::suggestion($ruleId);

            return \sprintf('Unknown lint rule "%s" in %s.%s', $ruleId, $option, null === $suggestion ? '' : \sprintf(' Did you mean "%s"?', $suggestion));
        }

        // A rule reads its setting under its short id, as regex.json writes it.
        return $this->withLintRule($arguments, RuleId::short($ruleId), '--enable-rule' === $option);
    }

    private function withInteropValue(LintArguments $arguments, string $value): LintArguments|string
    {
        $interop = $this->parseInteropValue($value);

        return \is_string($interop) ? $interop : $this->withInterop($arguments, $interop);
    }

    private function withRedosMode(LintArguments $arguments, string $value): LintArguments|string
    {
        $mode = $this->redosMode($value);

        return \is_string($mode) ? $mode : $arguments->with(checkRedos: true, redosMode: $mode->value);
    }

    private function withRedosThreshold(LintArguments $arguments, string $value): LintArguments|string
    {
        try {
            return $arguments->with(redosThreshold: RedosSeverity::fromConfig($value)->value);
        } catch (InvalidRegexOptionException $e) {
            return 'Invalid value for --redos-threshold: '.$e->getMessage();
        }
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
