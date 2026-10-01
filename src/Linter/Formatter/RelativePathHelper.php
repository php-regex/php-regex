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

namespace PhpRegex\Linter\Formatter;

final readonly class RelativePathHelper
{
    private ?string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = null === $basePath ? null : $this->normalizePath($basePath);
    }

    public function getRelativePath(string $path): string
    {
        $normalizedPath = $this->normalizePath($path);
        $basePath = $this->basePath ?? $this->detectBasePath();

        if (null === $basePath || '' === $basePath) {
            return $normalizedPath;
        }

        $basePath = rtrim($basePath, '/');
        $baseWithTrailingSlash = $basePath.'/';

        if (str_starts_with($normalizedPath, $baseWithTrailingSlash)) {
            return substr($normalizedPath, \strlen($baseWithTrailingSlash));
        }

        return $normalizedPath;
    }

    public function getBasePath(): ?string
    {
        return $this->basePath ?? $this->detectBasePath();
    }

    private function detectBasePath(): ?string
    {
        $cwd = getcwd();

        return false === $cwd ? null : $this->normalizePath($cwd);
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
