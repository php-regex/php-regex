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

namespace PHPRegex\Symfony\Analyzer\Formatter;

use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Symfony\Analyzer\SecurityReport;

/**
 * The document regex:analyze --format=json prints, as the JSON output
 * reference describes it. A section's facts and debug rows are labels for
 * people: they are written as {label, value} rows, never as keys.
 *
 * @internal
 */
final class JsonReportFormatter
{
    public function format(SecurityReport $report, bool $debug = false): string
    {
        return JsonDocument::encode([
            'sections' => $this->normalizeSections($report, $debug),
        ]);
    }

    /**
     * @return list<array{
     *     id: string,
     *     title: string,
     *     meta: list<array{label: string, value: int|string}>,
     *     summary: list<array{severity: 'pass'|'warn'|'fail'|'critical', message: string}>,
     *     warnings: list<array{severity: 'pass'|'warn'|'fail'|'critical', message: string}>,
     *     issues: list<array{
     *         kind: string,
     *         severity: 'pass'|'warn'|'fail'|'critical',
     *         title: string,
     *         details: list<array{label: string, value: string, kind: string}>,
     *         notes: array<int, string>,
     *     }>,
     *     suggestions: array<int, string>,
     *     debug?: list<array{label: string, value: int|string}>,
     * }>
     */
    private function normalizeSections(SecurityReport $report, bool $debug): array
    {
        $sections = [];

        foreach ($report->sections as $section) {
            $summary = [];
            foreach ($section->summary as $notice) {
                $summary[] = ['severity' => $notice->severity->value, 'message' => $notice->message];
            }

            $warnings = [];
            foreach ($section->warnings as $notice) {
                $warnings[] = ['severity' => $notice->severity->value, 'message' => $notice->message];
            }

            $issues = [];
            foreach ($section->issues as $issue) {
                $details = [];
                foreach ($issue->details as $detail) {
                    $details[] = [
                        'label' => $detail->label,
                        'value' => $detail->value,
                        'kind' => $detail->kind,
                    ];
                }

                $issues[] = [
                    'kind' => $issue->kind,
                    'severity' => $issue->severity->value,
                    'title' => $issue->title,
                    'details' => $details,
                    'notes' => $issue->notes,
                ];
            }

            $payload = [
                'id' => $section->id,
                'title' => $section->title,
                'meta' => $this->rows($section->meta),
                'summary' => $summary,
                'warnings' => $warnings,
                'issues' => $issues,
                'suggestions' => $section->suggestions,
            ];

            if ($debug && [] !== $section->debug) {
                $payload['debug'] = $this->rows($section->debug);
            }

            $sections[] = $payload;
        }

        return $sections;
    }

    /**
     * @param array<string, int|string> $facts
     *
     * @return list<array{label: string, value: int|string}>
     */
    private function rows(array $facts): array
    {
        $rows = [];
        foreach ($facts as $label => $value) {
            $rows[] = ['label' => (string) $label, 'value' => $value];
        }

        return $rows;
    }
}
