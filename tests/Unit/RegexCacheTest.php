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

namespace PhpRegex\Tests\Unit;

use PhpRegex\Parser\Cache\CacheInterface;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class RegexCacheTest extends TestCase
{
    public function test_normalize_cache_accepts_directory_path(): void
    {
        $dir = sys_get_temp_dir().'/regex-parser-cache-'.uniqid('', true);

        try {
            $regex = Regex::create(['cache' => $dir]);
            $regex->parse('/abc/');

            $this->assertDirectoryExists($dir);
            $this->assertNotEmpty(glob($dir.'/*'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_normalize_cache_throws_on_empty_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "cache" option cannot be an empty string.');

        Regex::create(['cache' => '  ']);
    }

    public function test_normalize_cache_throws_on_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "cache" option must be null, a cache path, or a CacheInterface implementation.');

        Regex::create(['cache' => new \stdClass()]);
    }

    public function test_normalize_cache_accepts_cache_interface(): void
    {
        $cache = new class implements CacheInterface {
            public bool $writeCalled = false;

            public function generateKey(string $regex): string
            {
                return sys_get_temp_dir().'/'.md5($regex);
            }

            public function write(string $key, RegexNode $ast): void
            {
                $this->writeCalled = true;
            }

            public function load(string $key): ?RegexNode
            {
                return null;
            }
        };

        $regex = Regex::create(['cache' => $cache]);
        $regex->parse('/abc/');

        $this->assertTrue($cache->writeCalled);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if (false === $files) {
            return;
        }

        foreach ($files as $file) {
            if (\in_array($file, ['.', '..'], true)) {
                continue;
            }
            $path = $dir.'/'.$file;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
