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

namespace PHPRegex\Linter\Formatter;

use PHPRegex\Linter\Diagnostic;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;

/**
 * JUnit XML output formatter.
 *
 * @phpstan-import-type LintResult from LintReport
 *
 * @phpstan-type FlattenedProblem array{
 *     file: string,
 *     line: int,
 *     source?: string|null,
 *     pattern?: string|null,
 *     location?: string|null,
 *     problem: Diagnostic
 * }
 *
 * @internal
 */
final class JunitFormatter extends AbstractOutputFormatter
{
    public function format(LintReport $report): string
    {
        $entries = $this->flattenProblems($report->results);
        $tests = \count($entries);
        $failures = 0;
        $errors = 0;

        foreach ($entries as $entry) {
            $problem = $entry['problem'];
            \assert($problem instanceof Diagnostic);

            if (LintSeverity::Critical === $problem->severity) {
                $errors++;
            } elseif (LintSeverity::Error === $problem->severity) {
                $failures++;
            }
        }

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            \sprintf(
                '<testsuite name="php-regex" tests="%d" failures="%d" errors="%d" skipped="0">',
                $tests,
                $failures,
                $errors,
            ),
        ];

        foreach ($entries as $entry) {
            $problem = $entry['problem'];
            \assert($problem instanceof Diagnostic);

            $name = $this->formatProblemTitle($problem);
            $file = $this->normalizeFile((string) $entry['file']);
            $line = $this->normalizeLine((int) $entry['line']);
            $message = $this->formatProblemMessage($problem, $entry);

            $lines[] = \sprintf(
                '  <testcase name="%s" classname="%s:%d">',
                ReportSpelling::xmlAttribute($name),
                ReportSpelling::xmlAttribute($file),
                $line,
            );

            if (LintSeverity::Critical === $problem->severity) {
                $lines[] = \sprintf(
                    '    <error message="%s">%s</error>',
                    ReportSpelling::xmlAttribute($problem->message),
                    ReportSpelling::xmlText($message),
                );
            } elseif (LintSeverity::Error === $problem->severity) {
                $lines[] = \sprintf(
                    '    <failure message="%s">%s</failure>',
                    ReportSpelling::xmlAttribute($problem->message),
                    ReportSpelling::xmlText($message),
                );
            } else {
                $lines[] = \sprintf(
                    '    <system-out>%s</system-out>',
                    ReportSpelling::xmlText($message),
                );
            }

            $lines[] = '  </testcase>';
        }

        $lines[] = '</testsuite>';

        return implode("\n", $lines);
    }

    public function formatError(string $message): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<testsuite name="php-regex" tests="1" failures="1" errors="0" skipped="0">',
            '  <testcase name="pattern-collection">',
            \sprintf(
                '    <failure message="%s">%s</failure>',
                ReportSpelling::xmlAttribute($message),
                ReportSpelling::xmlText($message),
            ),
            '  </testcase>',
            '</testsuite>',
        ];

        return implode("\n", $lines);
    }

    /**
     * @phpstan-param array<LintResult> $results
     *
     * @phpstan-return array<FlattenedProblem>
     */
    private function flattenProblems(array $results): array
    {
        $flattened = [];

        foreach ($results as $result) {
            foreach ((array) ($result['problems'] ?? []) as $problem) {
                if (!$problem instanceof Diagnostic) {
                    continue;
                }

                $flattened[] = [
                    'file' => $result['file'],
                    'line' => $result['line'],
                    'source' => $result['source'] ?? null,
                    'pattern' => $result['pattern'],
                    'location' => $result['location'] ?? null,
                    'problem' => $problem,
                ];
            }
        }

        return $flattened;
    }

    private function formatProblemTitle(Diagnostic $problem): string
    {
        $title = ucfirst($problem->type->value);
        if (null !== $problem->code && '' !== $problem->code) {
            $title .= ' ('.$problem->code.')';
        }

        return $title;
    }

    private function normalizeFile(string $file): string
    {
        return str_replace('\\', '/', $file);
    }

    private function normalizeLine(int $line): int
    {
        return max(1, $line);
    }

    /**
     * @phpstan-param FlattenedProblem $context
     */
    private function formatProblemMessage(Diagnostic $problem, array $context): string
    {
        $parts = [$problem->message];
        $location = $context['location'] ?? null;

        if (\is_string($location) && '' !== $location) {
            $parts[] = 'Location: '.$location;
        }

        if (null !== $problem->snippet && '' !== $problem->snippet) {
            $parts[] = $problem->snippet;
        }

        if (null !== $problem->suggestion && '' !== $problem->suggestion) {
            $parts[] = 'Suggestion: '.$problem->suggestion;
        }

        return implode("\n", $parts);
    }
}
