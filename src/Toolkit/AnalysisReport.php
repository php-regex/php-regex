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

namespace PhpRegex\Toolkit;

use PhpRegex\Optimizer\OptimizationResult;
use PhpRegex\Redos\RedosAnalysis;

/**
 * Analysis report for a regex pattern.
 */
final readonly class AnalysisReport
{
    /**
     * @param array<string> $errors
     * @param array<mixed>  $lintIssues
     */
    public function __construct(
        public bool $isValid,
        public array $errors,
        public array $lintIssues,
        public RedosAnalysis $redos,
        public OptimizationResult $optimizations,
        public string $explain,
        public string $highlighted,
    ) {}

    /**
     * @return array<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<mixed>
     */
    public function lintIssues(): array
    {
        return $this->lintIssues;
    }

    /**
     * Get ReDoS analysis.
     */
    public function redos(): RedosAnalysis
    {
        return $this->redos;
    }

    /**
     * Get optimization suggestions.
     */
    public function optimizations(): OptimizationResult
    {
        return $this->optimizations;
    }

    public function explain(): string
    {
        return $this->explain;
    }

    /**
     * Get highlighted version.
     */
    public function highlighted(): string
    {
        return $this->highlighted;
    }
}
