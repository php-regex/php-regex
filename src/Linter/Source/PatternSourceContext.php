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

namespace PHPRegex\Linter\Source;

use PHPRegex\Linter\LintProgress;

/**
 * Context passed to pattern sources during extraction.
 *
 * @internal
 */
final class PatternSourceContext
{
    /**
     * @param array<string> $paths
     * @param array<string> $excludePaths
     * @param array<string> $disabledSources
     * @param array<string> $declarationPaths where the functions marked #[RegexPattern] are read,
     *                                        besides the paths linted: the project's paths
     * @param array<string> $vendorPaths      the libraries read the same way, vendor/: a project
     *                                        declaration wins over a copy found there
     * @param string|null   $excludeBase      the directory the excludes are read from; null reads
     *                                        them as the files are walked
     */
    public function __construct(
        public readonly array $paths,
        public readonly array $excludePaths,
        private readonly array $disabledSources = [],
        public readonly ?LintProgress $progress = null,
        public readonly int $workers = 1,
        public readonly array $declarationPaths = [],
        public readonly array $vendorPaths = [],
        public readonly ?string $excludeBase = null,
    ) {}

    public function isSourceEnabled(string $name): bool
    {
        return !\in_array($name, $this->disabledSources, true);
    }
}
