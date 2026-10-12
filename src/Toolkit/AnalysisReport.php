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

namespace PHPRegex\Toolkit;

use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Redos\RedosAnalysis;

/**
 * Analysis report for a regex pattern.
 */
final readonly class AnalysisReport
{
    /**
     * @internal built by Regex::analyze()
     *
     * @param list<string>        $errors     messages explaining why analysis failed or degraded
     * @param list<RuleViolation> $lintIssues what the pattern linter reported
     */
    public function __construct(
        public bool $isValid,
        public array $errors,
        public array $lintIssues,
        public RedosAnalysis $redos,
        public OptimizationResult $optimization,
        public string $explanation,
        public string $highlighted,
    ) {}
}
