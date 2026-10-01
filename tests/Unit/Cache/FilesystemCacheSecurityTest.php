<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Cache;

use PhpRegex\Parser\Cache\ArrayCache;
use PhpRegex\Parser\Cache\FilesystemCache;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A cache on disk is only used where it is asked for, keeps its files to
 * their owner, stores data a reader never runs, and does not trust a
 * directory someone else can write to: a planted tree would change a
 * verdict, a planted script would run.
 */
final class FilesystemCacheSecurityTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/regex-parser-security-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            chmod($this->directory, 0o700);
        }
        (new FilesystemCache($this->directory))->clear();
        @unlink($this->directory.'.marker');
    }

    #[Test]
    public function test_nothing_is_written_to_disk_unless_asked(): void
    {
        $this->assertInstanceOf(ArrayCache::class, Regex::create()->getCache());
        $this->assertInstanceOf(ArrayCache::class, Regex::create(['max_recursion_depth' => 512])->getCache());
        $this->assertInstanceOf(FilesystemCache::class, Regex::create(['cache' => $this->directory])->getCache());
    }

    #[Test]
    public function test_a_file_in_the_cache_is_never_run(): void
    {
        $cache = new FilesystemCache($this->directory);
        $key = $cache->generateKey('/a/');
        $marker = $this->directory.'.marker';

        $cache->write($key, Regex::create(['cache' => null])->parse('/a/'));
        $this->assertStringStartsNotWith('<?php', (string) file_get_contents($key));

        file_put_contents($key, '<?php file_put_contents('.var_export($marker, true).', "ran"); return null;');

        $this->assertNull($cache->load($key));
        $this->assertFileDoesNotExist($marker);
    }

    #[Test]
    public function test_directories_and_files_belong_to_their_owner(): void
    {
        $umask = umask();
        $cache = new FilesystemCache($this->directory);
        $key = $cache->generateKey('/a/');

        $cache->write($key, Regex::create(['cache' => null])->parse('/a/'));

        $this->assertSame($umask, umask());
        $this->assertSame(0o700, fileperms($this->directory) & 0o777);
        $this->assertSame(0o700, fileperms(\dirname($key)) & 0o777);
        $this->assertSame(0o600, fileperms($key) & 0o777);
    }

    /**
     * The file is written beside its final name, never where the process
     * happens to run: a working directory it cannot write to changes nothing.
     */
    #[Test]
    public function test_a_file_is_only_written_inside_the_cache_directory(): void
    {
        $tree = Regex::create(['cache' => null])->parse('/a/');
        $cache = new FilesystemCache($this->directory);
        $key = $cache->generateKey('/a/');
        $workingDirectory = (string) getcwd();
        $readOnly = $this->directory.'-cwd';
        mkdir($readOnly, 0o500);
        chdir($readOnly);

        try {
            $cache->write($key, $tree);
        } finally {
            chdir($workingDirectory);
            chmod($readOnly, 0o700);
            rmdir($readOnly);
        }

        $this->assertEquals($tree, $cache->load($key));
    }

    #[Test]
    public function test_a_directory_others_can_write_to_is_not_trusted(): void
    {
        $tree = Regex::create(['cache' => null])->parse('/a/');
        $trusted = new FilesystemCache($this->directory);
        $key = $trusted->generateKey('/a/');
        $trusted->write($key, $tree);
        $this->assertEquals($tree, $trusted->load($key));

        chmod($this->directory, 0o777);
        $shared = new FilesystemCache($this->directory);
        $other = $shared->generateKey('/b/');
        $shared->write($other, $tree);

        $this->assertNull($shared->load($key));
        $this->assertFileDoesNotExist($other);
    }
}
