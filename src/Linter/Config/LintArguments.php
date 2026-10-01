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
use PhpRegex\Parser\Internal\Ascii;

final readonly class LintArguments
{
    /**
     * @param array<int, string>                 $paths
     * @param array<int, string>                 $exclude
     * @param "debug"|"normal"|"quiet"|"verbose" $verbosity
     * @param array<string, bool|int>            $optimizations
     * @param array<string, bool>                $lintRules
     * @param array<int, string>                 $interop          enabled wrapper presets
     * @param array<int, string>                 $patternFunctions extra calls carrying a pattern
     */
    public function __construct(
        public array $paths,
        public array $exclude,
        public int $minSavings,
        public string $verbosity,
        public string $format,
        public bool $quiet,
        public bool $checkRedos,
        public bool $checkValidation,
        public bool $checkOptimizations,
        public bool $checkLint,
        public int $jobs,
        public ?string $output = null,
        public ?string $baseline = null,
        public ?string $generateBaseline = null,
        public string $ide = '',
        public array $optimizations = [],
        public string $redosMode = 'theoretical',
        public ?string $redosThreshold = null,
        public array $lintRules = [],
        public array $interop = InteropPresets::DEFAULT_PRESETS,
        public array $patternFunctions = [],
    ) {}

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

        $quiet = $defaults['quiet'] ?? false;
        if (!\is_bool($quiet)) {
            $quiet = false;
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
        $redosMode = strtolower($redosMode);

        $redosThreshold = $defaults['redosThreshold'] ?? null;
        if (!\is_string($redosThreshold) || '' === $redosThreshold) {
            $redosThreshold = null;
        } else {
            $redosThreshold = strtolower($redosThreshold);
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
            $paths,
            $exclude,
            $minSavings,
            $verbosity,
            $format,
            $quiet,
            $checkRedos,
            $checkValidation,
            $checkOptimizations,
            $checkLint,
            $jobs,
            $output,
            $baseline,
            $generateBaseline,
            $ide,
            $optimizations,
            $redosMode,
            $redosThreshold,
            $lintRules,
            $interop,
            $patternFunctions,
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
