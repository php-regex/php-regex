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

namespace PHPRegex\Symfony\Analyzer;

/**
 * @internal
 */
final readonly class ReportSection
{
    /**
     * @param array<string, int|string>  $meta
     * @param array<int, AnalysisNotice> $summary
     * @param array<int, AnalysisNotice> $warnings
     * @param array<int, AnalysisIssue>  $issues
     * @param array<int, string>         $suggestions
     * @param array<string, int|string>  $debug
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $meta = [],
        public array $summary = [],
        public array $warnings = [],
        public array $issues = [],
        public array $suggestions = [],
        public array $debug = [],
    ) {}
}
