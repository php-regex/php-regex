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

namespace PHPRegex\Tests\Unit\Hir;

use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\Hir\ClassSetStore;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The engine's answers kept between processes, in a directory a caller
 * names: what a Unicode scan found, and the general categories, each in a
 * file of its own under a directory named for the library, PHP and PCRE2.
 * A process that finds the file reads it instead of scanning; a file that
 * does not read as the answer to its key is not taken. A new process is
 * played by emptying the process caches.
 */
final class ClassSetStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'php-regex-class-sets-'.bin2hex(random_bytes(6));
        StaticCaches::clear();
    }

    protected function tearDown(): void
    {
        ClassSetProvider::persistIn(null);
        StaticCaches::clear();
        self::remove($this->root);
    }

    #[Test]
    public function test_a_scan_is_kept_and_read_back_by_the_next_process(): void
    {
        ClassSetProvider::persistIn($this->root);
        $scanned = ClassSetProvider::query('\p{Han}', true, '');
        $this->assertInstanceOf(CharSet::class, $scanned);

        $files = self::files($this->root);
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('class-sets-', basename(\dirname($files[0])));

        // The next process takes what the file holds: a planted answer shows it.
        $planted = new ClassSetStore($this->root);
        $planted->save('u|:\p{Han}', ['set' => CharSet::range(0x41, 0x42)]);
        StaticCaches::clear();

        $this->assertSame([[0x41, 0x42]], ClassSetProvider::query('\p{Han}', true, '')?->ranges);
    }

    #[Test]
    public function test_only_the_engine_answers_are_kept(): void
    {
        ClassSetProvider::persistIn($this->root);
        $this->assertInstanceOf(CharSet::class, ClassSetProvider::query('[\p{Greek}\d-]', true, ''));
        $this->assertInstanceOf(CharSet::class, ClassSetProvider::query('\x{3A3}', true, 'i'));
        $this->assertInstanceOf(CharSet::class, ClassSetProvider::query('[a-z]', false, ''));

        $store = new ClassSetStore($this->root);
        $this->assertNotNull($store->load('u|:\p{Greek}'));
        $this->assertNotNull($store->load('u|:\d'));
        $this->assertNotNull($store->load('u|i:\x{3A3}'));
        $this->assertNull($store->load('u|:[\p{Greek}\d-]'), 'A composed set was kept.');
        $this->assertNull($store->load('|:[a-z]'), 'A byte scan was kept.');
        $this->assertCount(3, self::files($this->root));
    }

    #[Test]
    public function test_the_general_categories_are_kept_as_one_answer(): void
    {
        ClassSetProvider::persistIn($this->root);
        $letters = ClassSetProvider::query('\p{L}', true, '');
        $this->assertInstanceOf(CharSet::class, $letters);

        $kept = (new ClassSetStore($this->root))->load('general categories');
        $this->assertIsArray($kept);
        $this->assertCount(30, $kept);
        $this->assertEquals($letters, $kept['Lu']->union($kept['Ll'])->union($kept['Lt'])->union($kept['Lm'])->union($kept['Lo']));
        $this->assertCount(1, self::files($this->root));

        StaticCaches::clear();
        $this->assertEquals($letters, ClassSetProvider::query('\p{L}', true, ''));
    }

    #[Test]
    public function test_a_file_that_does_not_read_as_the_answer_is_not_taken(): void
    {
        ClassSetProvider::persistIn($this->root);
        $expected = ClassSetProvider::query('\p{Han}', true, '');
        $this->assertInstanceOf(CharSet::class, $expected);
        [$file] = self::files($this->root);
        $store = new ClassSetStore($this->root);

        $contents = [
            'empty' => '',
            'another format' => "class-set 0\nu|:\\p{Han}\nset 65-66\n",
            'another key' => "class-set 1\nu|:\\p{Hiragana}\nset 65-66\n",
            'ranges out of order' => "class-set 1\nu|:\\p{Han}\nset 70-80,65-66\n",
            'adjacent ranges' => "class-set 1\nu|:\\p{Han}\nset 65-66,67-68\n",
            'a range backwards' => "class-set 1\nu|:\\p{Han}\nset 66-65\n",
            'a surrogate' => "class-set 1\nu|:\\p{Han}\nset 55296-55296\n",
            'past the last code point' => "class-set 1\nu|:\\p{Han}\nset 65-1114112\n",
            'not a number' => "class-set 1\nu|:\\p{Han}\nset 65-x\n",
            'no set' => "class-set 1\nu|:\\p{Han}\n",
            'a name twice' => "class-set 1\nu|:\\p{Han}\nset 65-66\nset 70-71\n",
        ];
        foreach ($contents as $case => $content) {
            file_put_contents($file, $content);
            $this->assertNull($store->load('u|:\p{Han}'), $case);
            StaticCaches::clear();
            $this->assertEquals($expected, ClassSetProvider::query('\p{Han}', true, ''), $case);
        }

        // The scan wrote its answer again.
        $this->assertEquals(['set' => $expected], $store->load('u|:\p{Han}'));
    }

    #[Test]
    public function test_each_engine_keeps_its_answers_apart(): void
    {
        $this->assertStringContainsString(RegexParser::CACHE_VERSION, ClassSetStore::engine());
        $this->assertStringContainsString(\PHP_VERSION, ClassSetStore::engine());
        $this->assertStringContainsString(\PCRE_VERSION, ClassSetStore::engine());

        $running = new ClassSetStore($this->root);
        $other = new ClassSetStore($this->root, 'another library, PHP or PCRE2');
        $running->save('u|:\p{Han}', ['set' => CharSet::single(0x41)]);

        $this->assertNotSame($running->directory(), $other->directory());
        $this->assertNull($other->load('u|:\p{Han}'));
        $this->assertEquals(['set' => CharSet::single(0x41)], $running->load('u|:\p{Han}'));
    }

    #[Test]
    public function test_nothing_is_kept_without_a_directory(): void
    {
        ClassSetProvider::persistIn($this->root);
        ClassSetProvider::persistIn(null);
        $this->assertInstanceOf(CharSet::class, ClassSetProvider::query('\p{Han}', true, ''));

        $this->assertDirectoryDoesNotExist($this->root);

        ClassSetProvider::persistIn('');
        StaticCaches::clear();
        $this->assertInstanceOf(CharSet::class, ClassSetProvider::query('\p{Greek}', true, ''));
        $this->assertDirectoryDoesNotExist($this->root);
    }

    /**
     * Written as the owner alone reads it: a directory others may write
     * into is neither read nor written, as the tree cache does.
     */
    #[Test]
    // POSIX modes: on Windows the store trusts its directory.
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function test_a_directory_others_may_write_is_left_alone(): void
    {
        $store = new ClassSetStore($this->root);
        $store->save('u|:\p{Han}', ['set' => CharSet::single(0x41)]);
        [$file] = self::files($this->root);
        $this->assertSame(0o600, fileperms($file) & 0o777);
        $this->assertSame(0o700, fileperms($store->directory()) & 0o777);

        chmod($store->directory(), 0o777);
        $this->assertNull($store->load('u|:\p{Han}'));
        $store->save('u|:\p{Greek}', ['set' => CharSet::single(0x42)]);
        $this->assertCount(1, self::files($this->root));
    }

    /**
     * A file that cannot take its name, a directory standing there, is not
     * kept, and leaves nothing behind it.
     */
    #[Test]
    public function test_an_answer_that_cannot_be_written_leaves_nothing(): void
    {
        $store = new ClassSetStore($this->root);
        $store->save('u|:\p{Greek}', ['set' => CharSet::single(0x42)]);
        [$file] = self::files($this->root);
        unlink($file);
        mkdir($file);
        $store->save('u|:\p{Greek}', ['set' => CharSet::single(0x42)]);

        $this->assertDirectoryExists($file);
        $this->assertNull($store->load('u|:\p{Greek}'));
        $this->assertSame(['.', '..', basename($file)], scandir($store->directory()));
    }

    /**
     * A directory that cannot be made, a file where the root should be,
     * fails no analysis: the answer is scanned and not kept.
     */
    #[Test]
    public function test_a_directory_that_cannot_be_written_is_ignored(): void
    {
        file_put_contents($this->root, 'not a directory');

        try {
            ClassSetProvider::persistIn($this->root);
            $this->assertInstanceOf(CharSet::class, ClassSetProvider::query('\p{Han}', true, ''));
            $this->assertNull((new ClassSetStore($this->root))->load('u|:\p{Han}'));
            $this->assertSame('not a directory', file_get_contents($this->root));
        } finally {
            @unlink($this->root);
        }
    }

    /**
     * @return list<string>
     */
    private static function files(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        @chmod($path, 0o700);
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::remove($path.\DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }
}
