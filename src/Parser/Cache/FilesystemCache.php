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
    public function clear(): void
    {
        if (!is_dir($this->directory) || !$this->isTrusted()) {
            return;
        }

        // Only the files this cache writes are removed, and the directories
        // they leave empty: a directory shared with anything else keeps it.
        foreach (self::entries($this->directory) as $shard) {
            $path = $this->directory.\DIRECTORY_SEPARATOR.$shard;
            if (2 !== \strlen($shard) || !self::isHex($shard) || is_link($path) || !is_dir($path)) {
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
