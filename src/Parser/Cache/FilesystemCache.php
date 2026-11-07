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

namespace PHPRegex\Parser\Cache;

use PHPRegex\Parser\Exception\CacheException;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Text\Path;

/**
 * Trees kept in files under a directory the caller names, as data read
 * back with a class allowlist: nothing in the directory is ever run.
 *
 * The directory is created for its owner only, and one that another user
 * owns or that others can write to is left alone: a tree planted there
 * would change what a pattern is judged to be.
 *
 * @phpstan-import-type CacheStats from RemovableCacheInterface
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
    public function generateKey(string $seed): string
    {
        $hash = hash('sha256', $seed);

        return $this->directory.\DIRECTORY_SEPARATOR.substr($hash, 0, 2).\DIRECTORY_SEPARATOR.substr($hash, 2).'.cache';
    }

    #[\Override]
    public function write(string $key, RegexNode $ast): void
    {
        if (!$this->isTrusted($key)) {
            if (is_file($this->directory) && !is_link($this->directory)) {
                throw new CacheException(\sprintf('Unable to create the cache directory "%s".', $this->directory));
            }

            return;
        }

        $directory = \dirname($key);
        $this->createDirectory($directory);
        if (!$this->isTrusted($key)) {
            return;
        }

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
        $data = $this->isTrusted($key) && is_file($key) ? @file_get_contents($key) : false;
        $ast = false === $data ? null : AstSerializer::unserialize($data);

        null === $ast ? $this->misses++ : $this->hits++;

        return $ast;
    }

    #[\Override]
    public function clear(): void
    {
        if (!is_dir($this->directory) || !$this->isTrusted()) {
            return;
        }

        // Only the files this cache writes are removed, and the directories
        // they leave empty: a directory shared with anything else keeps it.
        foreach (self::entries($this->directory) as $shard) {
            $path = $this->directory.\DIRECTORY_SEPARATOR.$shard;
            if (2 !== \strlen($shard) || !self::isHex($shard) || is_link($path) || !is_dir($path) || !$this->isTrusted($path)) {
                continue;
            }

            foreach (self::entries($path) as $file) {
                if (self::isTreeFile($file)) {
                    @unlink($path.\DIRECTORY_SEPARATOR.$file);
                }
            }

            @rmdir($path);
        }

        @rmdir($this->directory);
    }

    /**
     * @return CacheStats
     */
    #[\Override]
    public function getStats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses];
    }

    /**
     * Validate every existing component, without following symbolic links.
     * A trusted root alone does not protect a writable shard or file. Checking
     * the parents too prevents another user replacing a checked directory.
     * Root-owned sticky ancestors (such as /tmp) protect our owned children.
     * Windows ACLs remain the host's responsibility.
     */
    private function isTrusted(?string $key = null): bool
    {
        if (Path::onWindows()) {
            return true;
        }

        if (!\function_exists('posix_geteuid')) {
            return false;
        }

        $path = $key ?? $this->directory;
        if (!str_starts_with($path, '/')) {
            $path = getcwd().'/'.$path;
        }
        $root = $this->directory;
        if (!str_starts_with($root, '/')) {
            $root = getcwd().'/'.$root;
        }

        $root = '/'.implode('/', array_filter(explode('/', $root), static fn (string $part): bool => '' !== $part && '.' !== $part));

        $current = '';
        $inside = false;
        foreach (explode('/', $path) as $component) {
            if ('' === $component || '.' === $component) {
                continue;
            }
            if ('..' === $component) {
                return false;
            }
            $current .= '/'.$component;
            $inside = $inside || $current === rtrim($root, '/');
            clearstatcache(true, $current);
            $stat = @lstat($current);
            if (false === $stat) {
                // Missing descendants can be created only through the parents
                // already checked, with owner-only permissions.
                continue;
            }
            $type = $stat['mode'] & 0o170000;
            if (0o040000 !== $type && (0o100000 !== $type || $current !== $path)) {
                return false;
            }
            if ($stat['uid'] !== posix_geteuid() && ($inside || 0 !== $stat['uid'])) {
                return false;
            }
            $stickyAncestor = !$inside && 0 === $stat['uid'] && 0o040000 === $type && 0 !== ($stat['mode'] & 0o1000);
            if (0 !== ($stat['mode'] & 0o022) && !$stickyAncestor) {
                return false;
            }
        }

        return true;
    }

    private function createDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new CacheException(\sprintf('Unable to create the cache directory "%s".', $directory));
        }
    }

    /**
     * @return list<string>
     */
    private static function entries(string $directory): array
    {
        return array_values(array_diff(@scandir($directory) ?: [], ['.', '..']));
    }

    /**
     * A file generateKey() names, "<62 hex digits>.cache", or one write()
     * left beside it, "<name>.<12 hex digits>.tmp".
     */
    private static function isTreeFile(string $file): bool
    {
        if (str_ends_with($file, '.tmp')) {
            $file = substr($file, 0, -17);
        }

        return 68 === \strlen($file) && str_ends_with($file, '.cache') && self::isHex(substr($file, 0, 62));
    }

    private static function isHex(string $text): bool
    {
        return \strlen($text) === strspn($text, '0123456789abcdef');
    }
}
