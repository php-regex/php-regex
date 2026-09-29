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

namespace RegexParser\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Cache\ArrayCache;
use RegexParser\Cache\CachePayloadDecoder;
use RegexParser\Cache\FilesystemCache;
use RegexParser\Node\ClassSetOperationNode;
use RegexParser\Node\ExtendedCharClassNode;
use RegexParser\Node\NodeInterface;
use RegexParser\Node\RegexNode;
use RegexParser\Regex;
use RegexParser\Tests\Support\AstFingerprint;

final class AstCachePayloadTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/regex-parser-ast-cache-'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        foreach ($this->cacheFiles() as $file) {
            @unlink($file);
        }

        foreach ((array) glob($this->cacheDir.'/*', \GLOB_ONLYDIR) as $directory) {
            @rmdir((string) $directory);
        }

        @rmdir($this->cacheDir);
    }

    #[Test]
    public function test_cached_ast_is_valid_php_and_restores_the_tree(): void
    {
        $cache = new FilesystemCache($this->cacheDir);
        Regex::create(['cache' => $cache])->parse('/(?<year>\d{4})-\d{2}/u');

        $key = $this->onlyCacheFile();
        $payload = (string) file_get_contents($key);

        $this->assertStringContainsString("!== '".Regex::CACHE_VERSION."'", $payload);

        $restored = include $key;

        $this->assertInstanceOf(RegexNode::class, $restored);
        $this->assertSame('u', $restored->flags);
        $this->assertSame('(?<year>\d{4})-\d{2}', $restored->source);
    }

    #[Test]
    public function test_second_parse_reads_the_ast_back_from_disk(): void
    {
        $pattern = '/^a(b|c)+$/';

        $cache = new FilesystemCache($this->cacheDir);
        Regex::create(['cache' => $cache])->parse($pattern);

        $reader = new FilesystemCache($this->cacheDir);
        $ast = Regex::create(['cache' => $reader])->parse($pattern);

        $this->assertInstanceOf(RegexNode::class, $ast);
        $this->assertSame(['hits' => 1, 'misses' => 0], $reader->getStats());
    }

    #[Test]
    public function test_an_extended_class_is_read_back_from_a_decoded_payload(): void
    {
        $cache = new ArrayCache();
        $pattern = '/(?[ \\d - [3] ])/';
        Regex::create(['cache' => $cache, 'pcre_version' => '10.45'])->parse($pattern);

        $ast = Regex::create(['cache' => $cache, 'pcre_version' => '10.45'])->parse($pattern);

        $this->assertInstanceOf(ExtendedCharClassNode::class, $ast->pattern);
        $this->assertInstanceOf(ClassSetOperationNode::class, $ast->pattern->expression);
        $this->assertSame(['hits' => 1, 'misses' => 1], $cache->getStats());

        Regex::create(['cache' => new FilesystemCache($this->cacheDir), 'pcre_version' => '10.45'])->parse($pattern);
        $reader = new FilesystemCache($this->cacheDir);
        $fromDisk = Regex::create(['cache' => $reader, 'pcre_version' => '10.45'])->parse($pattern);

        $this->assertInstanceOf(ExtendedCharClassNode::class, $fromDisk->pattern);
        $this->assertSame(['hits' => 1, 'misses' => 0], $reader->getStats());
    }

    #[Test]
    public function test_every_node_class_may_be_read_back(): void
    {
        foreach ((array) glob(\dirname(__DIR__, 3).'/src/Node/*.php') as $file) {
            $class = 'RegexParser\\Node\\'.basename((string) $file, '.php');
            if (class_exists($class) && is_subclass_of($class, NodeInterface::class)
                && !(new \ReflectionClass($class))->isAbstract()) {
                $this->assertContains($class, CachePayloadDecoder::NODE_CLASSES, $class);
            }
        }
    }

    #[Test]
    public function test_a_payload_written_by_another_version_is_ignored(): void
    {
        $cache = new FilesystemCache($this->cacheDir);
        Regex::create(['cache' => $cache])->parse('/abc/');

        $key = $this->onlyCacheFile();
        $payload = str_replace(
            "!== '".Regex::CACHE_VERSION."'",
            "!== '0.0.0-other'",
            (string) file_get_contents($key),
        );
        file_put_contents($key, $payload);

        $reader = new FilesystemCache($this->cacheDir);

        $this->assertNull($reader->load($key));
        $this->assertSame(['hits' => 0, 'misses' => 1], $reader->getStats());
    }

    #[Test]
    public function test_the_cache_version_is_the_fingerprint_of_the_code_that_builds_a_tree(): void
    {
        $this->assertSame(
            Regex::CACHE_VERSION,
            AstFingerprint::compute(),
            'The code that builds the AST changed, so trees cached before it are no longer the ones this '
            .'code would build. Run "task cache-version" and commit src/Regex.php.',
        );
    }

    private function onlyCacheFile(): string
    {
        $files = $this->cacheFiles();
        if ([] === $files) {
            self::fail('No cache file was written.');
        }

        return $files[0];
    }

    /**
     * @return array<int, string>
     */
    private function cacheFiles(): array
    {
        $files = [];
        foreach ((array) glob($this->cacheDir.'/*/*.php') as $file) {
            $files[] = (string) $file;
        }

        return $files;
    }
}
