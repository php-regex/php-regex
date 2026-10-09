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

namespace PHPRegex\Linter\Internal;

use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\PatternOccurrence;

/**
 * The stats of a lint run, counted the same way wherever results are
 * counted: a full run and a run a baseline filtered.
 *
 * @phpstan-import-type LintResult from LintReport
 * @phpstan-import-type LintStats from LintReport
 *
 * @internal
 */
final class LintStatsCounter
{
    /**
     * Errors, warnings and optimizations; among the errors, the ReDoS ones
     * and the lint rules at Error are counted again apart ("redos",
     * "lintErrors"), and infos on their own; of the patterns collected, the
     * files the tokenizer read because the PHP parser could not
     * ("parserFallbacks"). A kind that counts zero is left out.
     *
     * @param array<PatternOccurrence> $patterns
     *
     * @phpstan-param array<LintResult> $results
     *
     * @phpstan-return LintStats
     */
    public static function count(array $results, array $patterns = []): array
    {
        $stats = ['errors' => 0, 'warnings' => 0, 'optimizations' => 0];
        $redos = 0;
        $infos = 0;
        $lintErrors = 0;

        foreach ($results as $result) {
            foreach ($result['issues'] as $issue) {
                if ('error' === $issue['type']) {
                    $stats['errors']++;
                    // A ReDoS error or a lint rule at Error fails the run like
                    // any error, but the pattern compiles: the summaries name
                    // them apart.
                    if (isset($issue['analysis'])) {
                        $redos++;
                    } elseif (!isset($issue['validation'])) {
                        $lintErrors++;
                    }
                } elseif ('warning' === $issue['type']) {
                    $stats['warnings']++;
                } elseif ('info' === $issue['type']) {
                    $infos++;
                }
            }

            $stats['optimizations'] += \count($result['optimizations']);
        }

        if ($redos > 0) {
            $stats['redos'] = $redos;
        }

        if ($infos > 0) {
            $stats['infos'] = $infos;
        }

        if ($lintErrors > 0) {
            $stats['lintErrors'] = $lintErrors;
        }

        $parserFallbacks = \count(self::parserFallbacks($patterns));
        if ($parserFallbacks > 0) {
            $stats['parserFallbacks'] = $parserFallbacks;
        }

        return $stats;
    }

    /**
     * The files the tokenizer read because the PHP parser could not, one
     * marker each: a file a run is given twice (by its path and inside its
     * directory) is read twice, and is one file.
     *
     * @param array<PatternOccurrence> $patterns
     *
     * @return list<PatternOccurrence>
     */
    public static function parserFallbacks(array $patterns): array
    {
        $fallbacks = [];
        foreach ($patterns as $pattern) {
            if (null === $pattern->parserFallback) {
                continue;
            }

            $fallbacks[realpath($pattern->file) ?: $pattern->file] ??= $pattern;
        }

        return array_values($fallbacks);
    }
}
