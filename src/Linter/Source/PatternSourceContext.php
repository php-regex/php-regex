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

/**
 * Context passed to pattern sources during extraction.
 *
 * @internal
 */
final readonly class PatternSourceContext
{
    /**
     * @var callable(int, int): void|null
     */
    public mixed $progress;

    /**
     * @param array<string>                 $paths
     * @param array<string>                 $excludePaths
     * @param array<string>                 $disabledSources
     * @param callable(int, int): void|null $progress
     * @param array<string>                 $declarationPaths where the functions marked #[RegexPattern] are read,
     *                                                        besides the paths linted: the project's paths
     * @param array<string>                 $vendorPaths      the libraries read the same way, vendor/: a project
     *                                                        declaration wins over a copy found there
     */
    public function __construct(
        public array $paths,
        public array $excludePaths,
        private array $disabledSources = [],
        ?callable $progress = null,
        public int $workers = 1,
        public array $declarationPaths = [],
        public array $vendorPaths = [],
    ) {
        $this->progress = $progress;
    }

    public function isSourceEnabled(string $name): bool
    {
        return !\in_array($name, $this->disabledSources, true);
    }
}
