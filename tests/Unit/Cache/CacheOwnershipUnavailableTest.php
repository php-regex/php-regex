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

namespace PHPRegex\Tests\Unit\Cache;

use PHPRegex\Parser\Cache\AstSerializer;
use PHPRegex\Parser\Cache\FilesystemCache;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassSetStore;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CacheOwnershipUnavailableTest extends TestCase
{
    /**
     * With ext-posix, a directory another user owns is not trusted either:
     * fileowner() answers another uid, and both caches leave their files
     * unread.
     */
    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function test_existing_caches_are_ignored_when_another_user_owns_them(): void
    {
        $root = sys_get_temp_dir().'/regex-cache-owner-'.bin2hex(random_bytes(6));
        $cache = new FilesystemCache($root.'/trees');
        Regex::create(['cache' => $cache])->parse('/a/');
        $files = glob($root.'/trees/*/*.cache') ?: [];
        $this->assertCount(1, $files);
        $store = new ClassSetStore($root);
        $store->save('u|:\p{Han}', ['set' => CharSet::single(0x41)]);
        $setFiles = glob($store->directory().'/*.set') ?: [];
        $this->assertCount(1, $setFiles);

        $prelude = 'namespace PHPRegex\Parser\Cache { function fileowner(string $f): int|false { return \posix_geteuid() + 1; } }'
            .' namespace PHPRegex\Parser\Hir { function fileowner(string $f): int|false { return \posix_geteuid() + 1; } }';
        $code = $prelude.' namespace { require $argv[1];'
            .' $cache = new PHPRegex\Parser\Cache\FilesystemCache($argv[2]."/trees");'
            .' $store = new PHPRegex\Parser\Hir\ClassSetStore($argv[2]);'
            .' echo json_encode([function_exists("posix_geteuid"), $cache->load($argv[3]) === null, $store->load("u|:\\p{Han}") === null], JSON_THROW_ON_ERROR); }';

        try {
            $process = proc_open(
                [\PHP_BINARY, '-r', $code, \dirname(__DIR__, 3).'/vendor/autoload.php', $root, $files[0]],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), (string) $stderr);
            $this->assertSame([true, true, true], json_decode((string) $stdout, true, 512, \JSON_THROW_ON_ERROR));
        } finally {
            @unlink($setFiles[0]);
            @rmdir($store->directory());
            @unlink($files[0]);
            @rmdir(\dirname($files[0]));
            @rmdir($root.'/trees');
            @rmdir($root);
        }
    }

    #[Test]
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function test_existing_caches_are_ignored_when_ownership_cannot_be_checked(): void
    {
        $root = sys_get_temp_dir().'/regex-cache-ownership-'.bin2hex(random_bytes(6));
        $cache = new FilesystemCache($root.'/trees');
        Regex::create(['cache' => $cache])->parse('/a/');
        $files = glob($root.'/trees/*/*.cache') ?: [];
        $this->assertCount(1, $files);
        $file = $files[0];
        $planted = AstSerializer::serialize(Regex::create(['cache' => null])->parse('/b/'));
        file_put_contents($file, $planted);
        $store = new ClassSetStore($root);
        $store->save('u|:\p{Han}', ['set' => CharSet::single(0x41)]);
        $setFiles = glob($store->directory().'/*.set') ?: [];
        $this->assertCount(1, $setFiles);
        $setFile = $setFiles[0];
        $setContent = file_get_contents($setFile);

        $code = <<<'CODE'
            require $argv[1];
            $cache = new PHPRegex\Parser\Cache\FilesystemCache($argv[2].'/trees');
            $store = new PHPRegex\Parser\Hir\ClassSetStore($argv[2]);
            $tree = PHPRegex\Toolkit\Regex::create(['cache' => null])->parse('/a/');
            $results = [
                function_exists('posix_geteuid'),
                $cache->load($argv[3]) === null,
                $store->load('u|:\p{Han}') === null,
                PHPRegex\Parser\Cache\AstSerializer::serialize($tree) === PHPRegex\Parser\Cache\AstSerializer::serialize(PHPRegex\Toolkit\Regex::create(['cache' => $cache])->parse('/a/')),
            ];
            PHPRegex\Parser\Hir\ClassSetProvider::persistIn($argv[2]);
            $set = PHPRegex\Parser\Hir\ClassSetProvider::query('\p{Han}', true, '');
            $results[] = $set !== null && !$set->contains(0x41) && $set->contains(0x4E00);
            $cache->write($argv[3], $tree);
            $cache->clear();
            $store->save('another key', ['set' => PHPRegex\Parser\Hir\CharSet::single(0x42)]);
            echo json_encode($results, JSON_THROW_ON_ERROR);
            CODE;

        try {
            $process = proc_open(
                [\PHP_BINARY, '-d', 'disable_functions=posix_geteuid', '-r', $code, \dirname(__DIR__, 3).'/vendor/autoload.php', $root, $file],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), (string) $stderr);
            $this->assertSame([false, true, true, true, true], json_decode((string) $stdout, true, 512, \JSON_THROW_ON_ERROR));
            $this->assertSame($planted, file_get_contents($file));
            $this->assertSame($setContent, file_get_contents($setFile));
            $this->assertSame([$setFile], glob($store->directory().'/*.set'));
        } finally {
            @unlink($setFile);
            @rmdir($store->directory());
            @unlink($file);
            @rmdir(\dirname($file));
            @rmdir($root.'/trees');
            @rmdir($root);
        }
    }
}
