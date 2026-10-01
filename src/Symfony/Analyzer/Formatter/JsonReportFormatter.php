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

namespace PhpRegex\Symfony\Analyzer\Formatter;

use PhpRegex\Symfony\Analyzer\SecurityReport;

/**
 * @internal
 */
final readonly class JsonReportFormatter
{
    public function format(SecurityReport $report, bool $debug = false): string
    {
        $payload = [
            'sections' => $this->normalizeSections($report, $debug),
        ];

        return (string) json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<int, array<string, mixed>>
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
                'meta' => $section->meta,
                'summary' => $summary,
                'warnings' => $warnings,
                'issues' => $issues,
                'suggestions' => $section->suggestions,
            ];

            if ($debug && [] !== $section->debug) {
                $payload['debug'] = $section->debug;
            }

            $sections[] = $payload;
        }

        return $sections;
    }
}
