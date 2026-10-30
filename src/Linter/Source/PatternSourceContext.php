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
use PHPRegex\Parser\Text\Path;

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

    /**
     * Whether a file of the filesystem lies under a directory the lint
     * excludes, as the PHP files are left out: an exclude names a
     * directory under the one the excludes are read from, when one is
     * given, or anywhere in the file's path; the paths are matched with
     * "/" whichever separator spelled them.
     *
     * @param string $file the file's path, written with "/" separators
     */
    public function excludes(string $file): bool
    {
        $base = null === $this->excludeBase ? null : rtrim(Path::normalize($this->excludeBase), '/').'/';
        $matched = null !== $base && str_starts_with($file, $base) ? substr($file, \strlen($base)) : $file;

        foreach ($this->excludePaths as $exclude) {
            $exclude = trim(Path::normalize($exclude), '/');
            if ('' !== $exclude && (str_contains($matched, '/'.$exclude.'/') || str_starts_with($matched, $exclude.'/'))) {
                return true;
            }
        }

        return false;
    }
}
