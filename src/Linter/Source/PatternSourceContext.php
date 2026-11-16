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
     * @var list<string>|null resolved on the first file asked about
     */
    private ?array $excludedRealPaths = null;

    /**
     * The real path of the base, ending with "/", resolved on the first
     * file the base does not hold by the path given.
     */
    private ?string $realBase = null;

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
     * "/" whichever separator spelled them. An exclude that is the path of
     * a file or a directory, read from the directory the excludes are read
     * from or the working directory, behind "./" or absolute, excludes
     * that file or every file under that directory, whatever path the file
     * is reached by. A path that no longer exists, a file gone, is read by
     * the real path of the nearest directory above it that does.
     *
     * @param string $file the file's path, written with "/" separators
     */
    public function excludes(string $file): bool
    {
        $matched = $this->belowBase($file);

        foreach ($this->excludePaths as $exclude) {
            $exclude = trim(Path::normalize($exclude), '/');
            if ('' !== $exclude && (str_contains($matched, '/'.$exclude.'/') || str_starts_with($matched, $exclude.'/'))) {
                return true;
            }
        }

        $this->excludedRealPaths ??= $this->excludedRealPaths();
        if ([] === $this->excludedRealPaths) {
            return false;
        }

        $real = self::realPath($file);
        foreach ($this->excludedRealPaths as $excluded) {
            if ($real === $excluded || str_starts_with($real, $excluded.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The real path of each path the excludes name, written with "/"
     * separators, one that does not exist read as realPath() reads it: a
     * relative exclude is read from the directory the excludes are read
     * from, or from the working directory.
     *
     * @return list<string>
     */
    private function excludedRealPaths(): array
    {
        $paths = [];
        foreach ($this->excludePaths as $exclude) {
            $spelled = Path::normalize($exclude);
            if ('' === trim($spelled, '/')) {
                continue;
            }

            $absolute = str_starts_with($spelled, '/') || (\strlen($spelled) > 2 && ':' === $spelled[1] && '/' === $spelled[2]);
            $paths[] = rtrim(self::realPath($absolute || null === $this->excludeBase ? $exclude : $this->excludeBase.'/'.$exclude), '/');
        }

        return $paths;
    }

    /**
     * The file's path below the base, as given or, when the base does not
     * hold it so, as both real paths read it: a base reached through a
     * symbolic link holds the files found by their real path. A file
     * outside the base keeps the path given.
     */
    private function belowBase(string $file): string
    {
        if (null === $this->excludeBase) {
            return $file;
        }

        $base = rtrim(Path::normalize($this->excludeBase), '/').'/';
        if (str_starts_with($file, $base)) {
            return substr($file, \strlen($base));
        }

        $this->realBase ??= rtrim(self::realPath($this->excludeBase), '/').'/';
        $real = self::realPath($file);

        return str_starts_with($real, $this->realBase) ? substr($real, \strlen($this->realBase)) : $file;
    }

    /**
     * The real path of a path, written with "/" separators. A path that
     * does not exist is the real path of the nearest directory above it
     * that does, then the rest as written; with none, the path as written.
     */
    private static function realPath(string $path): string
    {
        $rest = '';
        $current = $path;
        while (false === ($real = realpath($current)) && \dirname($current) !== $current) {
            $rest = '/'.basename($current).$rest;
            $current = \dirname($current);
        }

        return false === $real ? Path::fromHost($path) : rtrim(Path::fromHost($real), '/').$rest;
    }
}
