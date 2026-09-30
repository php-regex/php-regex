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

namespace RegexParser\Tests\Unit\Cache {
    use PHPUnit\Framework\Attributes\Test;
    use PHPUnit\Framework\TestCase;
    use RegexParser\Cache\AstSerializer;
    use RegexParser\Cache\FilesystemCache;
    use RegexParser\Exception\CacheException;
    use RegexParser\Node\RegexNode;
    use RegexParser\Regex;

    final class FilesystemCacheTest extends TestCase
    {
        private string $cacheDir;

        protected function setUp(): void
        {
            $this->cacheDir = sys_get_temp_dir().'/regex-parser-cache-'.uniqid('', true);
        }

        protected function tearDown(): void
        {
            $this->removeDirectory($this->cacheDir);
            @unlink($this->cacheDir.'-file');
        }

        #[Test]
        public function test_write_and_load_cache_file(): void
        {
            $cache = new FilesystemCache($this->cacheDir);
            $key = $cache->generateKey('/abc/');
            $tree = $this->tree('/abc/');

            $cache->write($key, $tree);

            $this->assertFileExists($key);
            $this->assertSame(AstSerializer::serialize($tree), file_get_contents($key));
            $this->assertEquals($tree, $cache->load($key));
            $this->assertSame(['hits' => 1, 'misses' => 0], $cache->getStats());
        }

        #[Test]
        public function test_generate_key_shards_the_sha256_of_the_given_string(): void
        {
            $cache = new FilesystemCache($this->cacheDir.'/');
            $hash = hash('sha256', '/test/');

            $this->assertSame(
                $this->cacheDir.\DIRECTORY_SEPARATOR.substr($hash, 0, 2).\DIRECTORY_SEPARATOR.substr($hash, 2).'.cache',
                $cache->generateKey('/test/'),
            );
        }

        #[Test]
        public function test_clear_removes_cached_entries(): void
        {
            $cache = new FilesystemCache($this->cacheDir);
            $key = $cache->generateKey('/def/');

            $cache->write($key, $this->tree('/def/'));
            $cache->clear();

            $this->assertFileDoesNotExist($key);
            $this->assertDirectoryDoesNotExist($this->cacheDir);
        }

        #[Test]
        public function test_load_returns_null_for_nonexistent_file(): void
        {
            $cache = new FilesystemCache($this->cacheDir);
            $key = $cache->generateKey('/nonexistent/');

            $this->assertNull($cache->load($key));
            $this->assertSame(['hits' => 0, 'misses' => 1], $cache->getStats());
        }

        #[Test]
        public function test_write_throws_on_unwritable_directory(): void
        {
            $cache = new FilesystemCache($this->cacheDir);
            $key = $cache->generateKey('/test/');
            $fileDir = \dirname($key);
            mkdir($fileDir, 0o755, true);
            chmod($fileDir, 0o444); // Make the file's directory read-only

            $this->expectException(CacheException::class);
            $this->expectExceptionMessage('Unable to write the cache file');

            $cache->write($key, $this->tree('/test/'));
        }

        #[Test]
        public function test_clear_specific_regex(): void
        {
            $cache = new FilesystemCache($this->cacheDir);
            $key1 = $cache->generateKey('/abc/');
            $key2 = $cache->generateKey('/def/');
            $tree2 = $this->tree('/def/');

            $cache->write($key1, $this->tree('/abc/'));
            $cache->write($key2, $tree2);

            // Clear only the first regex
            $cache->clear('/abc/');

            $this->assertFileDoesNotExist($key1);
            $this->assertFileExists($key2);
            $this->assertEquals($tree2, $cache->load($key2));
        }

        #[Test]
        public function test_clear_nonexistent_regex(): void
        {
            $cache = new FilesystemCache($this->cacheDir);
            $key = $cache->generateKey('/existing/');
            $tree = $this->tree('/existing/');

            $cache->write($key, $tree);

            // Clear a non-existent regex - should not affect existing files
            $cache->clear('/nonexistent/');

            $this->assertFileExists($key);
            $this->assertEquals($tree, $cache->load($key));
        }

        #[Test]
        public function test_clear_returns_when_directory_missing(): void
        {
            $cache = new FilesystemCache($this->cacheDir);

            $cache->clear();

            $this->assertDirectoryDoesNotExist($this->cacheDir);
        }

        #[Test]
        public function test_clear_skips_broken_symlink_paths(): void
        {
            // Owner-only, so the cache trusts the directory and clears it.
            $cacheDir = $this->cacheDir.'/sub';
            @mkdir($cacheDir, 0o700, true);
            $broken = $cacheDir.'/broken';
            @symlink($cacheDir.'/missing', $broken);

            $cache = new FilesystemCache($cacheDir);
            $cache->clear();

            $this->assertFileDoesNotExist($broken);
        }

        #[Test]
        public function test_create_directory_throws_when_path_is_file(): void
        {
            $filePath = $this->cacheDir.'-file';
            copy(__DIR__.'/../../Fixtures/Cache/x.txt', $filePath);

            $cache = new FilesystemCache($filePath);

            $this->expectException(CacheException::class);
            $this->expectExceptionMessage('Unable to create the cache directory');
            $cache->write($cache->generateKey('/file/'), $this->tree('/file/'));
        }

        #[Test]
        public function test_load_handles_corrupted_file(): void
        {
            $cache = new FilesystemCache($this->cacheDir);
            $key = $cache->generateKey('/test/');
            $tree = $this->tree('/test/');

            $cache->write($key, $tree);
            $data = AstSerializer::serialize($tree);
            file_put_contents($key, substr($data, 0, intdiv(\strlen($data), 2)));

            $this->assertNull($cache->load($key));
            $this->assertSame(['hits' => 0, 'misses' => 1], $cache->getStats());
        }

        private function tree(string $pattern): RegexNode
        {
            return Regex::create(['cache' => null])->parse($pattern);
        }

        private function removeDirectory(string $directory): void
        {
            if (!is_dir($directory)) {
                return;
            }

            // A test may leave a directory read-only (see the unwritable
            // directory test), which blocks deleting its children. Ownership
            // still allows restoring access, so do that before anything else.
            $directories = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($directories as $fileInfo) {
                if ($fileInfo instanceof \SplFileInfo && $fileInfo->isDir()) {
                    @chmod($fileInfo->getPathname(), 0o755);
                }
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($files as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo) {
                    continue;
                }

                $path = $fileInfo->getRealPath();
                if (!\is_string($path)) {
                    continue;
                }

                if ($fileInfo->isDir()) {
                    @rmdir($path);
                } else {
                    @unlink($path);
                }
            }

            @rmdir($directory);
        }
    }
}
