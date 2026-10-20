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

namespace PHPRegex\Linter\Config;

use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Parser\Text\Ascii;

/**
 * @internal
 */
final class LintArguments
{
    /**
     * @param array<int, string>                 $paths
     * @param array<int, string>                 $exclude
     * @param "debug"|"normal"|"quiet"|"verbose" $verbosity
     * @param array<string, bool|int>            $optimizations
     * @param array<string, bool>                $lintRules
     * @param array<int, string>                 $interop                enabled wrapper presets
     * @param array<int, string>                 $patternFunctions       extra calls carrying a pattern
     * @param int                                $complexityThreshold    the complexity score from which a pattern is
     *                                                                   reported, once checkComplexity turns it on
     * @param bool                               $reportUnmatchedIgnores whether an inline marker or a baseline entry
     *                                                                   that silences nothing is reported
     */
    public function __construct(
        public readonly array $paths,
        public readonly array $exclude,
        public readonly int $minSavings,
        public readonly string $verbosity,
        public readonly string $format,
        public readonly bool $checkRedos,
        public readonly bool $checkValidation,
        public readonly bool $checkOptimizations,
        public readonly bool $checkLint,
        public readonly int $jobs,
        public readonly ?string $output = null,
        public readonly ?string $baseline = null,
        public readonly ?string $generateBaseline = null,
        public readonly string $ide = '',
        public readonly array $optimizations = [],
        public readonly string $redosMode = 'theoretical',
        public readonly ?string $redosThreshold = null,
        public readonly array $lintRules = [],
        public readonly array $interop = InteropPresets::DEFAULT_PRESETS,
        public readonly array $patternFunctions = [],
        public readonly bool $checkComplexity = false,
        public readonly int $complexityThreshold = 50,
        public readonly bool $reportUnmatchedIgnores = true,
    ) {}

    /**
     * A copy with the values given in place of these, each named after the
     * property it sets: one left out keeps its value (so a nullable one is
     * never set back to null this way).
     *
     * @param array<int, string>|null                 $paths
     * @param array<int, string>|null                 $exclude
     * @param "debug"|"normal"|"quiet"|"verbose"|null $verbosity
     * @param array<string, bool|int>|null            $optimizations
     * @param array<string, bool>|null                $lintRules
     * @param array<int, string>|null                 $interop
     * @param array<int, string>|null                 $patternFunctions
     */
    public function with(
        ?array $paths = null,
        ?array $exclude = null,
        ?int $minSavings = null,
        ?string $verbosity = null,
        ?string $format = null,
        ?bool $checkRedos = null,
        ?bool $checkValidation = null,
        ?bool $checkOptimizations = null,
        ?bool $checkLint = null,
        ?int $jobs = null,
        ?string $output = null,
        ?string $baseline = null,
        ?string $generateBaseline = null,
        ?string $ide = null,
        ?array $optimizations = null,
        ?string $redosMode = null,
        ?string $redosThreshold = null,
        ?array $lintRules = null,
        ?array $interop = null,
        ?array $patternFunctions = null,
        ?bool $checkComplexity = null,
        ?int $complexityThreshold = null,
        ?bool $reportUnmatchedIgnores = null,
    ): self {
        return new self(
            paths: $paths ?? $this->paths,
            exclude: $exclude ?? $this->exclude,
            minSavings: $minSavings ?? $this->minSavings,
            verbosity: $verbosity ?? $this->verbosity,
            format: $format ?? $this->format,
            checkRedos: $checkRedos ?? $this->checkRedos,
            checkValidation: $checkValidation ?? $this->checkValidation,
            checkOptimizations: $checkOptimizations ?? $this->checkOptimizations,
            checkLint: $checkLint ?? $this->checkLint,
            jobs: $jobs ?? $this->jobs,
            output: $output ?? $this->output,
            baseline: $baseline ?? $this->baseline,
            generateBaseline: $generateBaseline ?? $this->generateBaseline,
            ide: $ide ?? $this->ide,
            optimizations: $optimizations ?? $this->optimizations,
            redosMode: $redosMode ?? $this->redosMode,
            redosThreshold: $redosThreshold ?? $this->redosThreshold,
            lintRules: $lintRules ?? $this->lintRules,
            interop: $interop ?? $this->interop,
            patternFunctions: $patternFunctions ?? $this->patternFunctions,
            checkComplexity: $checkComplexity ?? $this->checkComplexity,
            complexityThreshold: $complexityThreshold ?? $this->complexityThreshold,
            reportUnmatchedIgnores: $reportUnmatchedIgnores ?? $this->reportUnmatchedIgnores,
        );
    }

    /**
     * @param array<string, mixed> $defaults
     */
    public static function fromDefaults(array $defaults): self
    {
        $paths = self::normalizeStringList($defaults['paths'] ?? []);
        $exclude = self::normalizeStringList($defaults['exclude'] ?? []);

        $minSavings = $defaults['minSavings'] ?? 1;
        if (!\is_int($minSavings)) {
            if (\is_string($minSavings) && Ascii::isDigit($minSavings)) {
                $minSavings = (int) $minSavings;
            } else {
                $minSavings = 1;
            }
        }
        if ($minSavings < 1) {
            $minSavings = 1;
        }

        $verbosity = $defaults['verbosity'] ?? OutputConfiguration::VERBOSITY_NORMAL;
        if (!\is_string($verbosity) || '' === $verbosity) {
            $verbosity = OutputConfiguration::VERBOSITY_NORMAL;
        } elseif (!\in_array($verbosity, [OutputConfiguration::VERBOSITY_QUIET, OutputConfiguration::VERBOSITY_NORMAL, OutputConfiguration::VERBOSITY_VERBOSE, OutputConfiguration::VERBOSITY_DEBUG], true)) {
            $verbosity = OutputConfiguration::VERBOSITY_NORMAL;
        }

        $format = $defaults['format'] ?? 'console';
        if (!\is_string($format) || '' === $format) {
            $format = 'console';
        }

        $checkRedos = $defaults['checkRedos'] ?? false;
        if (!\is_bool($checkRedos)) {
            $checkRedos = false;
        }

        $checkValidation = $defaults['checkValidation'] ?? true;
        if (!\is_bool($checkValidation)) {
            $checkValidation = true;
        }

        $checkOptimizations = $defaults['checkOptimizations'] ?? true;
        if (!\is_bool($checkOptimizations)) {
            $checkOptimizations = true;
        }

        $checkLint = $defaults['checkLint'] ?? true;
        if (!\is_bool($checkLint)) {
            $checkLint = true;
        }

        $lintRules = $defaults['lintRules'] ?? [];
        if (!\is_array($lintRules)) {
            $lintRules = [];
        } else {
            $validatedLintRules = [];
            foreach ($lintRules as $ruleId => $enabled) {
                if (\is_string($ruleId) && \is_bool($enabled)) {
                    $validatedLintRules[$ruleId] = $enabled;
                }
            }
            $lintRules = $validatedLintRules;
        }

        $interop = \array_key_exists('interop', $defaults)
            ? self::normalizeStringList($defaults['interop'])
            : InteropPresets::DEFAULT_PRESETS;
        $interop = array_values(array_filter($interop, InteropPresets::exists(...)));

        $patternFunctions = self::normalizeStringList($defaults['patternFunctions'] ?? []);

        $checkComplexity = $defaults['checkComplexity'] ?? false;
        if (!\is_bool($checkComplexity)) {
            $checkComplexity = false;
        }

        $complexityThreshold = $defaults['complexityThreshold'] ?? 50;
        if (!\is_int($complexityThreshold) || $complexityThreshold < 1) {
            $complexityThreshold = 50;
        }

        $reportUnmatchedIgnores = $defaults['reportUnmatchedIgnores'] ?? true;
        if (!\is_bool($reportUnmatchedIgnores)) {
            $reportUnmatchedIgnores = true;
        }

        $jobs = $defaults['jobs'] ?? -1; // -1 means auto-detect
        if (!\is_int($jobs)) {
            if (\is_string($jobs) && Ascii::isDigit($jobs)) {
                $jobs = (int) $jobs;
            } else {
                $jobs = -1;
            }
        }
        if ($jobs < 1 && -1 !== $jobs) {
            $jobs = -1;
        }

        $output = $defaults['output'] ?? null;
        if (!\is_string($output) || '' === $output) {
            $output = null;
        }

        $baseline = $defaults['baseline'] ?? null;
        if (!\is_string($baseline) || '' === $baseline) {
            $baseline = null;
        }

        $generateBaseline = $defaults['generateBaseline'] ?? null;
        if (!\is_string($generateBaseline) || '' === $generateBaseline) {
            $generateBaseline = null;
        }

        $ide = $defaults['ide'] ?? '';
        if (!\is_string($ide)) {
            $ide = '';
        }

        $redosMode = $defaults['redosMode'] ?? 'theoretical';
        if (!\is_string($redosMode) || '' === $redosMode) {
            $redosMode = 'theoretical';
        }
        $redosMode = Ascii::lower($redosMode);

        $redosThreshold = $defaults['redosThreshold'] ?? null;
        if (!\is_string($redosThreshold) || '' === $redosThreshold) {
            $redosThreshold = null;
        } else {
            $redosThreshold = Ascii::lower($redosThreshold);
        }

        $optimizations = $defaults['optimizations'] ?? [];
        if (!\is_array($optimizations)) {
            $optimizations = [];
        } else {
            $validated = [];
            foreach ($optimizations as $key => $value) {
                if (!\is_string($key)) {
                    continue;
                }
                if ('minQuantifierCount' === $key) {
                    if (\is_int($value)) {
                        $validated[$key] = $value;

                        continue;
                    }
                    if (\is_string($value) && Ascii::isDigit($value)) {
                        $validated[$key] = (int) $value;

                        continue;
                    }
                }
                if (\is_bool($value)) {
                    $validated[$key] = $value;
                }
            }
            $optimizations = $validated;
        }

        return new self(
            paths: $paths,
            exclude: $exclude,
            minSavings: $minSavings,
            verbosity: $verbosity,
            format: $format,
            checkRedos: $checkRedos,
            checkValidation: $checkValidation,
            checkOptimizations: $checkOptimizations,
            checkLint: $checkLint,
            jobs: $jobs,
            output: $output,
            baseline: $baseline,
            generateBaseline: $generateBaseline,
            ide: $ide,
            optimizations: $optimizations,
            redosMode: $redosMode,
            redosThreshold: $redosThreshold,
            lintRules: $lintRules,
            interop: $interop,
            patternFunctions: $patternFunctions,
            checkComplexity: $checkComplexity,
            complexityThreshold: $complexityThreshold,
            reportUnmatchedIgnores: $reportUnmatchedIgnores,
        );
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entry) {
            if (\is_string($entry) && '' !== $entry) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }
}
