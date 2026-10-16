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
final class ReportSection
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
        public readonly string $id,
        public readonly string $title,
        public readonly array $meta = [],
        public readonly array $summary = [],
        public readonly array $warnings = [],
        public readonly array $issues = [],
        public readonly array $suggestions = [],
        public readonly array $debug = [],
    ) {}
}
