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

namespace PhpRegex\Linter\Config;

/**
 * The lint command's defaults from a loaded regex.json, which the command
 * line then overrides. A check is switched on or off by its "enabled" key
 * only: setting one of its other keys never enables it.
 */
final class LintDefaultsBuilder
{
    /**
     * Top-level keys read as they are.
     */
    private const TOP_LEVEL = ['paths', 'exclude', 'jobs', 'format', 'ide'];

    /**
     * Nested keys, as a path in the file => the default they set.
     */
    private const NESTED = [
        'extraction.interop' => 'interop',
        'extraction.functions' => 'patternFunctions',
        'checks.validation' => 'checkValidation',
        'checks.redos.enabled' => 'checkRedos',
        'checks.redos.mode' => 'redosMode',
        'checks.redos.threshold' => 'redosThreshold',
        'checks.optimizations.enabled' => 'checkOptimizations',
        'checks.optimizations.minSavings' => 'minSavings',
        'checks.optimizations.options' => 'optimizations',
        'checks.lint.enabled' => 'checkLint',
        'checks.lint.rules' => 'lintRules',
    ];

    /**
     * @param array<string, mixed> $config the configuration LintConfigLoader returns
     *
     * @return array<string, mixed>
     */
    public function build(array $config): array
    {
        $defaults = [];

        foreach (self::TOP_LEVEL as $key) {
            if (\array_key_exists($key, $config)) {
                $defaults[$key] = \is_string($config[$key]) && \in_array($key, ['paths', 'exclude'], true)
                    ? [$config[$key]]
                    : $config[$key];
            }
        }

        foreach (self::NESTED as $path => $default) {
            $value = $config;
            foreach (explode('.', $path) as $segment) {
                if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                    continue 2;
                }
                $value = $value[$segment];
            }
            $defaults[$default] = $value;
        }

        return $defaults;
    }
}
