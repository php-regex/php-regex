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
use PHPRegex\Parser\Internal\DisplayEscaper;

/**
 * Checkstyle XML output formatter.
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
final class CheckstyleFormatter extends AbstractOutputFormatter
{
    public function format(LintReport $report): string
    {
        $entries = $this->flattenProblems($report->results);
        $byFile = [];

        foreach ($entries as $entry) {
            $file = $this->normalizeFile((string) $entry['file']);
            $byFile[$file][] = $entry;
        }

        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<checkstyle version="4.3">'];

        foreach ($byFile as $file => $fileEntries) {
            $lines[] = \sprintf('  <file name="%s">', $this->escapeXml($file));
            foreach ($fileEntries as $entry) {
                $problem = $entry['problem'];
                \assert($problem instanceof Diagnostic);

                $line = $this->normalizeLine((int) $entry['line']);
                $column = $this->normalizeColumn($problem->position);
                $severity = $this->mapCheckstyleSeverity($problem->severity);
                $message = $this->formatProblemMessage($problem, $entry);
                $source = $this->formatCheckstyleSource($problem);

                $lines[] = \sprintf(
                    '    <error line="%d" column="%d" severity="%s" message="%s" source="%s"/>',
                    $line,
                    $column,
                    $this->escapeXml($severity),
                    $this->escapeXml($message),
                    $this->escapeXml($source),
                );
            }
            $lines[] = '  </file>';
        }

        $lines[] = '</checkstyle>';

        return implode("\n", $lines);
    }

    public function formatError(string $message): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<checkstyle version="4.3">',
            '  <file name="php-regex">',
            \sprintf(
                '    <error line="1" column="1" severity="error" message="%s" source="php-regex"/>',
                $this->escapeXml($message),
            ),
            '  </file>',
            '</checkstyle>',
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

    private function normalizeFile(string $file): string
    {
        return str_replace('\\', '/', $file);
    }

    private function normalizeLine(int $line): int
    {
        return max(1, $line);
    }

    private function normalizeColumn(?int $position): int
    {
        return $position ?? 1;
    }

    private function mapCheckstyleSeverity(LintSeverity $severity): string
    {
        return match ($severity) {
            LintSeverity::Error, LintSeverity::Critical => 'error',
            LintSeverity::Warning, LintSeverity::Style, LintSeverity::Perf => 'warning',
            LintSeverity::Info => 'info',
        };
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

    private function formatCheckstyleSource(Diagnostic $problem): string
    {
        $source = 'php-regex';

        if (null !== $problem->code && '' !== $problem->code) {
            $source .= '.'.$problem->code;
        }

        return $source;
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars(DisplayEscaper::escape($value), \ENT_XML1 | \ENT_QUOTES);
    }
}
