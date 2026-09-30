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

namespace RegexParser\Lint\Command;

use RegexParser\Lint\Extraction\InteropPresets;
use RegexParser\ReDoS\ReDoSMode;
use RegexParser\ReDoS\ReDoSSeverity;

final class LintConfigLoader
{
    public function load(): LintConfigResult
    {
        $cwd = getcwd();
        if (false === $cwd) {
            return new LintConfigResult([], []);
        }

        /** @var array<string, mixed> $config */
        $config = [];
        $files = [];
        $paths = [$cwd.'/regex.dist.json', $cwd.'/regex.json'];

        foreach ($paths as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $readResult = $this->readLintConfigFile($path);
            if (null !== $readResult->error) {
                return $readResult;
            }

            $normalized = $this->normalizeLintConfig($readResult->config, $path);
            if (null !== $normalized->error) {
                return $normalized;
            }

            /** @var array<string, mixed> $config */
            $config = array_replace_recursive($config, $normalized->config);
            $files[] = $path;
        }

        return new LintConfigResult($config, $files);
    }

    private function readLintConfigFile(string $path): LintConfigResult
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            return new LintConfigResult([], [], 'Failed to read config file: '.$path);
        }

        $decoded = json_decode($contents, true);
        if (null === $decoded && \JSON_ERROR_NONE !== json_last_error()) {
            return new LintConfigResult([], [], 'Invalid JSON in '.$path.': '.json_last_error_msg());
        }

        if (!\is_array($decoded)) {
            return new LintConfigResult([], [], 'Config file must contain a JSON object: '.$path);
        }

        return $this->ensureStringKeyedConfig($decoded, $path);
    }

    /**
     * @param array<mixed, mixed> $config
     */
    private function ensureStringKeyedConfig(array $config, string $path): LintConfigResult
    {
        $normalized = [];
        foreach ($config as $key => $value) {
            if (!\is_string($key)) {
                return new LintConfigResult([], [], 'Config file must contain a JSON object: '.$path);
            }
            $normalized[$key] = $value;
        }

        return new LintConfigResult($normalized, []);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function normalizeLintConfig(array $config, string $path): LintConfigResult
    {
        /** @var array<string, mixed> $normalized */
        $normalized = [];

        if (\array_key_exists('paths', $config)) {
            $paths = $this->normalizeStringList($config['paths'], $path, 'paths');
            if (null !== $paths->error) {
                return $paths;
            }
            $normalized['paths'] = $paths->config['paths'] ?? [];
        }

        if (\array_key_exists('exclude', $config)) {
            $exclude = $this->normalizeStringList($config['exclude'], $path, 'exclude');
            if (null !== $exclude->error) {
                return $exclude;
            }
            $normalized['exclude'] = $exclude->config['exclude'] ?? [];
        }

        if (\array_key_exists('jobs', $config)) {
            if (!\is_int($config['jobs'])) {
                return new LintConfigResult([], [], 'Invalid "jobs" in '.$path.': expected an integer.');
            }
            if ($config['jobs'] < 1) {
                return new LintConfigResult([], [], 'Invalid "jobs" in '.$path.': value must be >= 1.');
            }
            $normalized['jobs'] = $config['jobs'];
        }

        if (\array_key_exists('minSavings', $config)) {
            if (!\is_int($config['minSavings'])) {
                return new LintConfigResult([], [], 'Invalid "minSavings" in '.$path.': expected an integer.');
            }
            if ($config['minSavings'] < 1) {
                return new LintConfigResult([], [], 'Invalid "minSavings" in '.$path.': value must be >= 1.');
            }
            $normalized['minSavings'] = $config['minSavings'];
        }

        if (\array_key_exists('format', $config)) {
            if (!\is_string($config['format']) || '' === $config['format']) {
                return new LintConfigResult([], [], 'Invalid "format" in '.$path.': expected a non-empty string.');
            }
            $normalized['format'] = $config['format'];
        }

        if (\array_key_exists('redosMode', $config)) {
            if (!\is_string($config['redosMode']) || '' === $config['redosMode']) {
                return new LintConfigResult([], [], 'Invalid "redosMode" in '.$path.': expected a non-empty string.');
            }
            $mode = ReDoSMode::tryFrom(strtolower($config['redosMode']));
            if (null === $mode) {
                return new LintConfigResult([], [], 'Invalid "redosMode" in '.$path.': expected off, theoretical, or confirmed.');
            }
            $normalized['redosMode'] = $mode->value;
        }

        if (\array_key_exists('redosThreshold', $config)) {
            if (!\is_string($config['redosThreshold']) || '' === $config['redosThreshold']) {
                return new LintConfigResult([], [], 'Invalid "redosThreshold" in '.$path.': expected a non-empty string.');
            }
            $threshold = ReDoSSeverity::tryFrom(strtolower($config['redosThreshold']));
            if (null === $threshold) {
                return new LintConfigResult([], [], 'Invalid "redosThreshold" in '.$path.': expected low, medium, high, or critical.');
            }
            $normalized['redosThreshold'] = $threshold->value;
        }

        if (\array_key_exists('redosNoJit', $config)) {
            if (!\is_bool($config['redosNoJit'])) {
                return new LintConfigResult([], [], 'Invalid "redosNoJit" in '.$path.': expected a boolean.');
            }
            $normalized['redosNoJit'] = $config['redosNoJit'];
        }

        if (\array_key_exists('rules', $config)) {
            if (!\is_array($config['rules'])) {
                return new LintConfigResult([], [], 'Invalid "rules" in '.$path.': expected an object.');
            }

            $rules = [];
            foreach (['redos', 'validation', 'optimization'] as $ruleKey) {
                if (!\array_key_exists($ruleKey, $config['rules'])) {
                    continue;
                }
                if (!\is_bool($config['rules'][$ruleKey])) {
                    return new LintConfigResult([], [], 'Invalid "rules.'.$ruleKey.'" in '.$path.': expected a boolean.');
                }
                $rules[$ruleKey] = $config['rules'][$ruleKey];
            }

            if ([] !== $rules) {
                $normalized['rules'] = $rules;
            }
        }

        if (\array_key_exists('ide', $config)) {
            if (!\is_string($config['ide'])) {
                return new LintConfigResult([], [], 'Invalid "ide" in '.$path.': expected a string.');
            }
            $normalized['ide'] = $config['ide'];
        }

        if (\array_key_exists('optimizations', $config)) {
            $options = $this->normalizeOptimizationOptions($config['optimizations'], $path, 'optimizations');
            if (null !== $options->error) {
                return $options;
            }
            if (isset($options->config['optimizations'])) {
                $normalized['optimizations'] = $options->config['optimizations'];
            }
        }

        if (\array_key_exists('extraction', $config)) {
            $extraction = $this->normalizeExtractionConfig($config['extraction'], $path);
            if (null !== $extraction->error) {
                return $extraction;
            }
            if ([] !== $extraction->config) {
                $normalized = $this->mergeConfig($normalized, $extraction->config);
            }
        }

        if (\array_key_exists('checks', $config)) {
            if (!\is_array($config['checks'])) {
                return new LintConfigResult([], [], 'Invalid "checks" in '.$path.': expected an object.');
            }

            $checksConfig = [];
            foreach ($config['checks'] as $key => $value) {
                if (!\is_string($key)) {
                    return new LintConfigResult([], [], 'Invalid "checks" in '.$path.': expected an object.');
                }
                $checksConfig[$key] = $value;
            }

            $checks = $this->normalizeChecksConfig($checksConfig, $path);
            if (null !== $checks->error) {
                return $checks;
            }
            if ([] !== $checks->config) {
                $normalized = $this->mergeConfig($normalized, $checks->config);
            }
        }

        return new LintConfigResult($normalized, []);
    }

    /**
     * Normalize which wrapper libraries and project helpers carry patterns.
     */
    private function normalizeExtractionConfig(mixed $value, string $path): LintConfigResult
    {
        if (!\is_array($value)) {
            return new LintConfigResult([], [], 'Invalid "extraction" in '.$path.': expected an object.');
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];

        if (\array_key_exists('interop', $value)) {
            $interop = $this->normalizeStringList($value['interop'], $path, 'extraction.interop');
            if (null !== $interop->error) {
                return $interop;
            }

            /** @var array<int, string> $presets */
            $presets = $interop->config['extraction.interop'] ?? [];
            foreach ($presets as $preset) {
                if (!InteropPresets::exists($preset)) {
                    return new LintConfigResult([], [], 'Invalid "extraction.interop" in '.$path.': unknown preset "'.$preset.'". Available presets: '.implode(', ', InteropPresets::names()).'.');
                }
            }

            $normalized['interop'] = $presets;
        }

        if (\array_key_exists('functions', $value)) {
            $functions = $this->normalizeStringList($value['functions'], $path, 'extraction.functions');
            if (null !== $functions->error) {
                return $functions;
            }

            $normalized['patternFunctions'] = $functions->config['extraction.functions'] ?? [];
        }

        return new LintConfigResult($normalized, []);
    }

    /**
     * @param array<string, mixed> $checks
     */
    private function normalizeChecksConfig(array $checks, string $path): LintConfigResult
    {
        /** @var array<string, mixed> $normalized */
        $normalized = [];

        if (\array_key_exists('validation', $checks)) {
            if (!\is_bool($checks['validation'])) {
                return new LintConfigResult([], [], 'Invalid "checks.validation" in '.$path.': expected a boolean.');
            }
            $rules = $normalized['rules'] ?? [];
            if (!\is_array($rules)) {
                $rules = [];
            }
            $rules['validation'] = $checks['validation'];
            $normalized['rules'] = $rules;
        }

        if (\array_key_exists('redos', $checks)) {
            $redos = $this->normalizeRedosCheck($checks['redos'], $path);
            if (null !== $redos->error) {
                return $redos;
            }
            if ([] !== $redos->config) {
                $normalized = $this->mergeConfig($normalized, $redos->config);
            }
        }

        if (\array_key_exists('optimizations', $checks)) {
            $optimizations = $this->normalizeOptimizationsCheck($checks['optimizations'], $path);
            if (null !== $optimizations->error) {
                return $optimizations;
            }
            if ([] !== $optimizations->config) {
                $normalized = $this->mergeConfig($normalized, $optimizations->config);
            }
        }

        if (\array_key_exists('lint', $checks)) {
            $lint = $this->normalizeLintCheck($checks['lint'], $path);
            if (null !== $lint->error) {
                return $lint;
            }
            if ([] !== $lint->config) {
                $normalized = $this->mergeConfig($normalized, $lint->config);
            }
        }

        return new LintConfigResult($normalized, []);
    }

    private function normalizeRedosCheck(mixed $value, string $path): LintConfigResult
    {
        if (\is_bool($value)) {
            return new LintConfigResult(['rules' => ['redos' => $value]], []);
        }

        if (!\is_array($value)) {
            return new LintConfigResult([], [], 'Invalid "checks.redos" in '.$path.': expected a boolean or object.');
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];
        $enabled = null;
        $hasSettings = false;

        if (\array_key_exists('enabled', $value)) {
            if (!\is_bool($value['enabled'])) {
                return new LintConfigResult([], [], 'Invalid "checks.redos.enabled" in '.$path.': expected a boolean.');
            }
            $enabled = $value['enabled'];
        }

        if (\array_key_exists('mode', $value)) {
            if (!\is_string($value['mode']) || '' === $value['mode']) {
                return new LintConfigResult([], [], 'Invalid "checks.redos.mode" in '.$path.': expected a non-empty string.');
            }
            $mode = ReDoSMode::tryFrom(strtolower($value['mode']));
            if (null === $mode) {
                return new LintConfigResult([], [], 'Invalid "checks.redos.mode" in '.$path.': expected off, theoretical, or confirmed.');
            }
            $normalized['redosMode'] = $mode->value;
            $hasSettings = true;
            if (ReDoSMode::OFF === $mode) {
                $enabled = false;
            }
        }

        if (\array_key_exists('threshold', $value)) {
            if (!\is_string($value['threshold']) || '' === $value['threshold']) {
                return new LintConfigResult([], [], 'Invalid "checks.redos.threshold" in '.$path.': expected a non-empty string.');
            }
            $threshold = ReDoSSeverity::tryFrom(strtolower($value['threshold']));
            if (null === $threshold) {
                return new LintConfigResult([], [], 'Invalid "checks.redos.threshold" in '.$path.': expected low, medium, high, or critical.');
            }
            $normalized['redosThreshold'] = $threshold->value;
            $hasSettings = true;
        }

        if (\array_key_exists('noJit', $value)) {
            if (!\is_bool($value['noJit'])) {
                return new LintConfigResult([], [], 'Invalid "checks.redos.noJit" in '.$path.': expected a boolean.');
            }
            $normalized['redosNoJit'] = $value['noJit'];
            $hasSettings = true;
        }

        if (null !== $enabled || $hasSettings) {
            $rules = $normalized['rules'] ?? [];
            if (!\is_array($rules)) {
                $rules = [];
            }
            $rules['redos'] = $enabled ?? true;
            $normalized['rules'] = $rules;
        }

        return new LintConfigResult($normalized, []);
    }

    private function normalizeOptimizationsCheck(mixed $value, string $path): LintConfigResult
    {
        if (\is_bool($value)) {
            return new LintConfigResult(['rules' => ['optimization' => $value]], []);
        }

        if (!\is_array($value)) {
            return new LintConfigResult([], [], 'Invalid "checks.optimizations" in '.$path.': expected a boolean or object.');
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];
        $enabled = null;
        $hasSettings = false;

        if (\array_key_exists('enabled', $value)) {
            if (!\is_bool($value['enabled'])) {
                return new LintConfigResult([], [], 'Invalid "checks.optimizations.enabled" in '.$path.': expected a boolean.');
            }
            $enabled = $value['enabled'];
        }

        if (\array_key_exists('minSavings', $value)) {
            if (!\is_int($value['minSavings'])) {
                return new LintConfigResult([], [], 'Invalid "checks.optimizations.minSavings" in '.$path.': expected an integer.');
            }
            if ($value['minSavings'] < 1) {
                return new LintConfigResult([], [], 'Invalid "checks.optimizations.minSavings" in '.$path.': value must be >= 1.');
            }
            $normalized['minSavings'] = $value['minSavings'];
            $hasSettings = true;
        }

        if (\array_key_exists('options', $value)) {
            $options = $this->normalizeOptimizationOptions($value['options'], $path, 'checks.optimizations.options');
            if (null !== $options->error) {
                return $options;
            }
            if (isset($options->config['optimizations'])) {
                $normalized['optimizations'] = $options->config['optimizations'];
            }
            $hasSettings = true;
        }

        if (null !== $enabled || $hasSettings) {
            $rules = $normalized['rules'] ?? [];
            if (!\is_array($rules)) {
                $rules = [];
            }
            $rules['optimization'] = $enabled ?? true;
            $normalized['rules'] = $rules;
        }

        return new LintConfigResult($normalized, []);
    }

    private function normalizeLintCheck(mixed $value, string $path): LintConfigResult
    {
        if (\is_bool($value)) {
            return new LintConfigResult(['lintEnabled' => $value], []);
        }

        if (!\is_array($value)) {
            return new LintConfigResult([], [], 'Invalid "checks.lint" in '.$path.': expected a boolean or object.');
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];

        if (\array_key_exists('enabled', $value)) {
            if (!\is_bool($value['enabled'])) {
                return new LintConfigResult([], [], 'Invalid "checks.lint.enabled" in '.$path.': expected a boolean.');
            }
            $normalized['lintEnabled'] = $value['enabled'];
        }

        if (\array_key_exists('rules', $value)) {
            if (!\is_array($value['rules'])) {
                return new LintConfigResult([], [], 'Invalid "checks.lint.rules" in '.$path.': expected an object.');
            }

            /** @var array<string, bool> $lintRules */
            $lintRules = [];
            foreach ($value['rules'] as $ruleId => $enabled) {
                if (!\is_string($ruleId)) {
                    return new LintConfigResult([], [], 'Invalid "checks.lint.rules" in '.$path.': rule IDs must be strings.');
                }
                if (!\is_bool($enabled)) {
                    return new LintConfigResult([], [], 'Invalid "checks.lint.rules.'.$ruleId.'" in '.$path.': expected a boolean.');
                }
                $lintRules[$ruleId] = $enabled;
            }

            if ([] !== $lintRules) {
                $normalized['lintRules'] = $lintRules;
            }
        }

        return new LintConfigResult($normalized, []);
    }

    private function normalizeOptimizationOptions(mixed $options, string $path, string $prefix): LintConfigResult
    {
        if (!\is_array($options)) {
            return new LintConfigResult([], [], 'Invalid "'.$prefix.'" in '.$path.': expected an object.');
        }

        $optConfig = [];
        $keyMapping = [
            'digits' => 'digits',
            'word' => 'word',
            'ranges' => 'ranges',
            'canonicalizeCharClasses' => 'canonicalizeCharClasses',
            'possessive' => 'possessive',
            'factorize' => 'factorize',
            'verifyWithAutomata' => 'verifyWithAutomata',
        ];
        foreach ($keyMapping as $jsonKey => $internalKey) {
            if (\array_key_exists($jsonKey, $options)) {
                if (!\is_bool($options[$jsonKey])) {
                    return new LintConfigResult([], [], 'Invalid "'.$prefix.'.'.$jsonKey.'" in '.$path.': expected a boolean.');
                }
                $optConfig[$internalKey] = $options[$jsonKey];
            }
        }

        if (\array_key_exists('minQuantifierCount', $options)) {
            if (!\is_int($options['minQuantifierCount'])) {
                return new LintConfigResult([], [], 'Invalid "'.$prefix.'.minQuantifierCount" in '.$path.': expected an integer.');
            }
            if ($options['minQuantifierCount'] < 2) {
                return new LintConfigResult([], [], 'Invalid "'.$prefix.'.minQuantifierCount" in '.$path.': value must be >= 2.');
            }
            $optConfig['minQuantifierCount'] = $options['minQuantifierCount'];
        }

        if ([] !== $optConfig) {
            return new LintConfigResult(['optimizations' => $optConfig], []);
        }

        return new LintConfigResult([], []);
    }

    private function normalizeStringList(mixed $value, string $path, string $key): LintConfigResult
    {
        if (\is_string($value)) {
            $value = [$value];
        }

        if (!\is_array($value)) {
            return new LintConfigResult([], [], 'Invalid "'.$key.'" in '.$path.': expected an array of strings.');
        }

        $normalized = [];
        foreach ($value as $entry) {
            if (!\is_string($entry) || '' === $entry) {
                return new LintConfigResult([], [], 'Invalid "'.$key.'" in '.$path.': expected an array of strings.');
            }
            $normalized[] = $entry;
        }

        return new LintConfigResult([$key => $normalized], []);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function mergeConfig(array $base, array $overrides): array
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive($base, $overrides);

        return $merged;
    }
}
