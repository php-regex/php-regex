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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under u the first characters of the branches are asked of PCRE, which
 * scans every code point for an atom it has no shorter way to read. An
 * ASCII character written as it is, read in its own case, is itself under
 * every table and option: the rule takes it as it is. The scans PCRE runs
 * are counted by the files a store kept for the next process.
 */
final class AlternationOverlapAsciiStartTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'php-regex-ascii-start-'.bin2hex(random_bytes(6));
        StaticCaches::clear();
        ClassSetProvider::persistIn($this->root);
    }

    protected function tearDown(): void
    {
        ClassSetProvider::persistIn(null);
        StaticCaches::clear();
        self::remove($this->root);
    }

    #[Test]
    #[DataProvider('provideAlternations')]
    public function test_a_branch_starting_with_an_ascii_character_needs_no_scan(string $pattern, bool $scanned, bool $reported): void
    {
        $this->assertNotFalse(preg_match($pattern, ''), $pattern);

        $ids = array_map(static fn (object $issue): string => $issue->id, Regex::create(['cache' => null])->lint($pattern));

        $this->assertSame($reported, \in_array('regex.lint.overlap.charset', $ids, true), $pattern);
        $this->assertSame($scanned, [] !== self::files($this->root), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, scanned: bool, reported: bool}>
     */
    public static function provideAlternations(): iterable
    {
        yield 'two ASCII characters' => ['pattern' => '/(?:a[0]|b[1])+/u', 'scanned' => false, 'reported' => false];
        yield 'ASCII texts before types' => ['pattern' => '/(?:foo\d|bar\d|baz\w)+/u', 'scanned' => false, 'reported' => false];
        yield 'one ASCII character twice, going on alike' => ['pattern' => '/(?:a[01]|a[1])+/u', 'scanned' => false, 'reported' => true];
        // "a0" starts one branch and "a1" the other: no input starts both.
        yield 'one ASCII character twice, going on apart' => ['pattern' => '/(?:a[0]|a[1])+/u', 'scanned' => false, 'reported' => false];
        // A character past ASCII is scanned, which shows the store counts.
        yield 'a character past ASCII' => ['pattern' => '/(?:é[0]|b[1])+/u', 'scanned' => true, 'reported' => false];
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

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::remove($path.\DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }
}
