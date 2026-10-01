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

use PhpRegex\Optimizer\OptimizerOptions;

/**
 * Input parameters for a lint run.
 *
 * @internal
 */
final readonly class LintRequest
{
    /**
     * @param array<string>                        $paths
     * @param array<string>                        $excludePaths
     * @param array<string>                        $disabledSources
     * @param \PhpRegex\Optimizer\OptimizerOptions $optimizations   what an optimization may rewrite; lint checks every
     *                                                              rewrite with the automata unless told otherwise
     * @param array<string, bool>                  $lintRules
     */
    public function __construct(
        public array $paths,
        public array $excludePaths,
        public int $minSavings,
        private array $disabledSources = [],
        public bool $checkValidation = true,
        public bool $checkRedos = false,
        public bool $checkOptimizations = true,
        public bool $checkLint = true,
        public int $analysisWorkers = 1,
        public OptimizerOptions $optimizations = new OptimizerOptions(verifyWithAutomata: true),
        public array $lintRules = [],
    ) {}

    /**
     * @return array<string>
     */
    public function getDisabledSources(): array
    {
        return $this->disabledSources;
    }

    public function isSourceEnabled(string $name): bool
    {
        return !\in_array($name, $this->disabledSources, true);
    }
}
