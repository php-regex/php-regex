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

namespace RegexParser\Cache;

use RegexParser\Exception\CacheException;
use RegexParser\Node\RegexNode;

/**
 * Trees kept in files under a directory the caller names, as data read
 * back with a class allowlist: nothing in the directory is ever run.
 *
 * The directory is created for its owner only, and one that another user
 * owns or that others can write to is left alone: a tree planted there
 * would change what a pattern is judged to be.
 */
final class FilesystemCache implements RemovableCacheInterface
{
    private readonly string $directory;

    private int $hits = 0;

    private int $misses = 0;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '\\/');
    }

    #[\Override]
    public function generateKey(string $regex): string
    {
        $hash = hash('sha256', $regex);

        return $this->directory.\DIRECTORY_SEPARATOR.substr($hash, 0, 2).\DIRECTORY_SEPARATOR.substr($hash, 2).'.cache';
    }

    #[\Override]
    public function write(string $key, RegexNode $ast): void
    {
        if (!$this->isTrusted()) {
            return;
        }

        $directory = \dirname($key);
        $this->createDirectory($directory);

        // Written beside its final name, then moved there: a reader never sees
        // half a file, and nothing lands outside the owner's directory, as
        // tempnam() would when it falls back to the system temp directory.
        $temporary = $key.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (false === @file_put_contents($temporary, AstSerializer::serialize($ast)) || !@chmod($temporary, 0o600) || !@rename($temporary, $key)) {
            @unlink($temporary);

            throw new CacheException(\sprintf('Unable to write the cache file "%s".', $key));
        }
    }

    #[\Override]
    public function load(string $key): ?RegexNode
    {
        $data = $this->isTrusted() && is_file($key) ? @file_get_contents($key) : false;
        $ast = false === $data ? null : AstSerializer::unserialize($data);

        null === $ast ? $this->misses++ : $this->hits++;

        return $ast;
    }

    #[\Override]
    public function clear(?string $regex = null): void
    {
        if (!is_dir($this->directory) || !$this->isTrusted()) {
            return;
        }

        if (null !== $regex) {
            @unlink($this->generateKey($regex));

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo) {
                continue;
            }

            $fileInfo->isDir() ? @rmdir($fileInfo->getPathname()) : @unlink($fileInfo->getPathname());
        }

        @rmdir($this->directory);
    }

    /**
     * @return array{hits: int, misses: int}
     */
    #[\Override]
    public function getStats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses];
    }

    /**
     * Whether the directory is its owner's alone: it belongs to the user
     * running PHP, and neither its group nor others can write to it. Windows
     * permissions are ACLs this check cannot read, and are left to the host.
     */
    private function isTrusted(): bool
    {
        if ('\\' === \DIRECTORY_SEPARATOR || !is_dir($this->directory)) {
            return true;
        }

        $permissions = @fileperms($this->directory);
        if (false === $permissions || 0 !== ($permissions & 0o022)) {
            return false;
        }

        return !\function_exists('posix_geteuid') || @fileowner($this->directory) === posix_geteuid();
    }

    private function createDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new CacheException(\sprintf('Unable to create the cache directory "%s".', $directory));
        }
    }
}
