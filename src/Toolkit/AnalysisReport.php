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
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\RedosAnalysis;

/**
 * What Regex::analyze() says of one pattern: each part as the method of
 * the facade that gives it alone returns it, validate(), lint(), redos()
 * and optimize().
 */
final class AnalysisReport
{
    /**
     * @internal built by Regex::analyze()
     *
     * @param ValidationResult    $validation   whether the target accepts the pattern, and why not
     * @param list<RuleViolation> $violations   what the lint rules report; none for a pattern the target refuses
     * @param RedosAnalysis       $redos        the ReDoS verdict
     * @param OptimizationResult  $optimization the rewrite offered; the pattern as written for one the target refuses
     * @param list<string>        $errors       what kept the lint or the optimizer from finishing on a valid pattern,
     *                                          as a limit of the library; none when both finished
     */
    public function __construct(
        public readonly ValidationResult $validation,
        public readonly array $violations,
        public readonly RedosAnalysis $redos,
        public readonly OptimizationResult $optimization,
        public readonly array $errors = [],
    ) {}
}
