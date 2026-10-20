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

use PHPRegex\Optimizer\OptimizerOptions;

/**
 * Input parameters for a lint run.
 *
 * @internal
 */
final class LintRequest
{
    /**
     * @param array<string>    $paths
     * @param array<string>    $excludePaths
     * @param array<string>    $disabledSources
     * @param OptimizerOptions $optimizations          what an optimization may rewrite; lint checks every
     *                                                 rewrite with the automata unless told otherwise
     * @param array<string>    $declarationPaths       where the functions marked #[RegexPattern] are read, besides
     *                                                 the paths linted: the project's paths
     * @param array<string>    $vendorPaths            the libraries read the same way, vendor/: a project
     *                                                 declaration wins over a copy found there
     * @param bool             $checkComplexity        whether regex.lint.complexity is reported, at the threshold
     *                                                 of the analysis
     * @param bool             $reportUnmatchedIgnores whether an inline marker, or an identifier it names,
     *                                                 that silences nothing is reported as regex.ignore.unmatched
     * @param string|null      $excludeBase            the directory the excludes are read from, the project's when
     *                                                 the paths are; null reads them as the files are walked
     */
    public function __construct(
        public readonly array $paths,
        public readonly array $excludePaths,
        public readonly int $minSavings,
        private readonly array $disabledSources = [],
        public readonly bool $checkValidation = true,
        public readonly bool $checkRedos = false,
        public readonly bool $checkOptimizations = true,
        public readonly int $analysisWorkers = 1,
        public readonly OptimizerOptions $optimizations = new OptimizerOptions(verifyWithAutomata: true),
        public readonly array $declarationPaths = [],
        public readonly array $vendorPaths = [],
        public readonly bool $checkComplexity = false,
        public readonly bool $reportUnmatchedIgnores = true,
        public readonly ?string $excludeBase = null,
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
