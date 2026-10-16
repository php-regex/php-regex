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

/**
 * The lint command's defaults from a loaded regex.json, which the command
 * line then overrides. A check is switched on or off by its "enabled" key
 * only: setting one of its other keys never enables it.
 *
 * A default is set only for a key the file holds: LintDefaults, every key
 * optional, a single path read as a list of one.
 *
 * @phpstan-type LintDefaults array{
 *     paths?: list<string>,
 *     exclude?: list<string>,
 *     jobs?: int,
 *     format?: string,
 *     ide?: string,
 *     interop?: list<string>,
 *     patternFunctions?: list<string>,
 *     checkValidation?: bool,
 *     checkRedos?: bool,
 *     redosMode?: string,
 *     redosThreshold?: string,
 *     checkOptimizations?: bool,
 *     minSavings?: int,
 *     optimizations?: array{
 *         digits?: bool,
 *         word?: bool,
 *         ranges?: bool,
 *         canonicalizeCharClasses?: bool,
 *         possessive?: bool,
 *         factorize?: bool,
 *         minQuantifierCount?: int,
 *         verifyWithAutomata?: bool,
 *     },
 *     checkLint?: bool,
 *     lintRules?: array<string, bool>,
 *     checkComplexity?: bool,
 *     complexityThreshold?: int,
 * }
 *
 * @phpstan-import-type LintConfig from LintConfigResult
 *
 * @internal
 */
final class LintDefaultsBuilder
{
    /**
     * @param LintConfig $config the configuration LintConfigLoader returns
     *
     * @return LintDefaults
     */
    public function build(array $config): array
    {
        $defaults = [];

        if (isset($config['paths'])) {
            $defaults['paths'] = \is_string($config['paths']) ? [$config['paths']] : $config['paths'];
        }
        if (isset($config['exclude'])) {
            $defaults['exclude'] = \is_string($config['exclude']) ? [$config['exclude']] : $config['exclude'];
        }
        if (isset($config['jobs'])) {
            $defaults['jobs'] = $config['jobs'];
        }
        if (isset($config['format'])) {
            $defaults['format'] = $config['format'];
        }
        if (isset($config['ide'])) {
            $defaults['ide'] = $config['ide'];
        }

        $extraction = $config['extraction'] ?? [];
        if (isset($extraction['interop'])) {
            $defaults['interop'] = $extraction['interop'];
        }
        if (isset($extraction['functions'])) {
            $defaults['patternFunctions'] = $extraction['functions'];
        }

        $checks = $config['checks'] ?? [];
        if (isset($checks['validation'])) {
            $defaults['checkValidation'] = $checks['validation'];
        }

        $redos = $checks['redos'] ?? [];
        if (isset($redos['enabled'])) {
            $defaults['checkRedos'] = $redos['enabled'];
        }
        if (isset($redos['mode'])) {
            $defaults['redosMode'] = $redos['mode'];
        }
        if (isset($redos['threshold'])) {
            $defaults['redosThreshold'] = $redos['threshold'];
        }

        $optimizations = $checks['optimizations'] ?? [];
        if (isset($optimizations['enabled'])) {
            $defaults['checkOptimizations'] = $optimizations['enabled'];
        }
        if (isset($optimizations['minSavings'])) {
            $defaults['minSavings'] = $optimizations['minSavings'];
        }
        if (isset($optimizations['options'])) {
            $defaults['optimizations'] = $optimizations['options'];
        }

        $lint = $checks['lint'] ?? [];
        if (isset($lint['enabled'])) {
            $defaults['checkLint'] = $lint['enabled'];
        }
        if (isset($lint['rules'])) {
            $defaults['lintRules'] = $lint['rules'];
        }

        $complexity = $checks['complexity'] ?? [];
        if (isset($complexity['enabled'])) {
            $defaults['checkComplexity'] = $complexity['enabled'];
        }
        if (isset($complexity['threshold'])) {
            $defaults['complexityThreshold'] = $complexity['threshold'];
        }

        return $defaults;
    }
}
