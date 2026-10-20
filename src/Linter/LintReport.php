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

namespace PHPRegex\Linter;

use PHPRegex\Optimizer\OptimizationResult;

/**
 * Output from a lint run.
 *
 * A result gathers what was found in one pattern: its issues, each a
 * LintIssue, and its optimizations. problems() gives them as the GitHub,
 * Checkstyle and JUnit reports show them.
 *
 * @internal
 *
 * @phpstan-type FlattenedProblem array{file: string, line: int, column?: int|null, source?: string|null, pattern?: string|null, location?: string|null, problem: Diagnostic}
 * @phpstan-type OptimizationEntry array{file: string, line: int, column?: int|null, fileOffset?: int|null, optimization: OptimizationResult, savings: int, source?: string}
 * @phpstan-type LintResult array{file: string, line: int, column?: int|null, fileOffset?: int|null, source?: string|null, pattern: string|null, location?: string|null, issues: array<LintIssue>, optimizations: array<OptimizationEntry>}
 * @phpstan-type LintStats array{errors: int, warnings: int, optimizations: int, redos?: int, infos?: int, lintErrors?: int, parserFallbacks?: int, notAnalyzed?: int}
 */
final class LintReport
{
    /**
     * @param array<LintResult> $results
     * @param LintStats         $stats   "redos" counts the errors that are ReDoS verdicts and "lintErrors" the lint rules at Error, both among "errors"; "infos" counts the info issues; "parserFallbacks" counts the files the tokenizer read because the PHP parser could not; "notAnalyzed" counts the warnings of the patterns the library gave up on, at one of its own limits, among "warnings". Each optional count is left out at zero; the JSON report always carries them, 0 when there are none
     */
    public function __construct(
        /**
         * @var array<LintResult>
         */
        public readonly array $results,
        /**
         * @var LintStats
         */
        public readonly array $stats,
    ) {}

    /**
     * The diagnostics of a result: one per issue, then one per
     * optimization, in their order.
     *
     * @param LintResult $result
     *
     * @return list<Diagnostic>
     */
    public static function problems(array $result): array
    {
        $problems = [];
        foreach ($result['issues'] as $issue) {
            $problems[] = $issue->diagnostic();
        }

        foreach ($result['optimizations'] as $optimization) {
            $problems[] = new Diagnostic(
                DiagnosticType::Optimization,
                LintSeverity::Info,
                \sprintf('Optimization available (saves %d chars).', $optimization['savings']),
                LintService::ISSUE_ID_OPTIMIZATION,
                null,
                $optimization['optimization']->optimized,
            );
        }

        return $problems;
    }
}
