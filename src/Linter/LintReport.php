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

namespace PhpRegex\Linter;

use PhpRegex\Optimizer\OptimizationResult;
use PhpRegex\Parser\Validation\ValidationResult;
use PhpRegex\Redos\RedosAnalysis;

/**
 * Output from a lint run.
 *
 * @internal
 *
 * @phpstan-type LintIssue array{type: string, message: string, file: string, line: int, column?: int, fileOffset?: int|null, position?: int|null, issueId?: string, hint?: string|null, tip?: string|null, suggestedPattern?: string, source?: string, pattern?: string, regex?: string, analysis?: RedosAnalysis, validation?: ValidationResult}
 * @phpstan-type OptimizationEntry array{file: string, line: int, column?: int, fileOffset?: int|null, optimization: OptimizationResult, savings: int, source?: string}
 * @phpstan-type LintResult array{file: string, line: int, column?: int, fileOffset?: int|null, source?: string|null, pattern: string|null, location?: string|null, issues: array<LintIssue>, optimizations: array<OptimizationEntry>, problems: array<Diagnostic>}
 * @phpstan-type LintStats array{errors: int, warnings: int, optimizations: int}
 */
final readonly class LintReport
{
    /**
     * @param array<LintResult> $results
     * @param LintStats         $stats
     */
    public function __construct(
        /**
         * @var array<LintResult>
         */
        public array $results,
        /**
         * @var LintStats
         */
        public array $stats,
    ) {}
}
