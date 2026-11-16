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

namespace PHPRegex\Tests\Unit\Lint\Source;

use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The excludes apply to a file of any source as they apply to the PHP
 * files: a directory name, under the base when one is given, or the path
 * of a file or a directory.
 */
final class PatternSourceContextTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_a_source_is_enabled_unless_named_among_the_disabled(): void
    {
        $context = new PatternSourceContext([], [], ['twig']);

        $this->assertFalse($context->isSourceEnabled('twig'));
        $this->assertTrue($context->isSourceEnabled('php'));
    }

    /**
     * @param list<string> $excludes
     */
    #[Test]
    #[DataProvider('provideExcludes')]
    public function test_a_file_is_excluded_by_a_directory_of_its_path(string $file, array $excludes, ?string $base, bool $excluded): void
    {
        $this->assertSame($excluded, (new PatternSourceContext(['src'], $excludes, excludeBase: $base))->excludes($file));
    }

    /**
     * @return iterable<string, array{file: string, excludes: list<string>, base: string|null, excluded: bool}>
     */
    public static function provideExcludes(): iterable
    {
        yield 'a directory anywhere in the path' => ['file' => '/p/a/cache/x.twig', 'excludes' => ['cache'], 'base' => null, 'excluded' => true];
        yield 'a directory name that only starts alike' => ['file' => '/p/a/caches/x.twig', 'excludes' => ['cache'], 'base' => null, 'excluded' => false];
        yield 'a directory name that only ends alike' => ['file' => '/p/a/mycache/x.twig', 'excludes' => ['cache'], 'base' => null, 'excluded' => false];
        yield 'a file named as the directory' => ['file' => '/p/a/cache', 'excludes' => ['cache'], 'base' => null, 'excluded' => false];
        yield 'written with slashes around' => ['file' => '/p/a/cache/x.twig', 'excludes' => ['/cache/'], 'base' => null, 'excluded' => true];
        yield 'written with a backslash' => ['file' => '/p/a/cache/sub/x.twig', 'excludes' => ['cache\\sub'], 'base' => null, 'excluded' => true];
        yield 'an empty exclude excludes nothing' => ['file' => '/p/a/cache/x.twig', 'excludes' => ['', '/'], 'base' => null, 'excluded' => false];
        yield 'an empty exclude before another' => ['file' => '/p/a/cache/x.twig', 'excludes' => ['', 'cache'], 'base' => null, 'excluded' => true];
        yield 'under the base' => ['file' => '/p/templates/cache/x.twig', 'excludes' => ['cache'], 'base' => '/p/templates', 'excluded' => true];
        yield 'under the base, written with a trailing slash' => ['file' => '/p/templates/cache/x.twig', 'excludes' => ['cache'], 'base' => '/p/templates/', 'excluded' => true];
        yield 'a file of the base named as the directory' => ['file' => '/p/templates/cache.twig', 'excludes' => ['cache'], 'base' => '/p/templates', 'excluded' => false];
        yield 'deeper under the base' => ['file' => '/p/templates/mail/cache/x.twig', 'excludes' => ['cache'], 'base' => '/p/templates', 'excluded' => true];
        yield 'a directory above the base' => ['file' => '/p/templates/x.twig', 'excludes' => ['p'], 'base' => '/p/templates', 'excluded' => false];
        yield 'a path outside the base keeps its whole path' => ['file' => '/q/cache/x.twig', 'excludes' => ['cache'], 'base' => '/p/templates', 'excluded' => true];
        yield 'a sibling of the base starting alike keeps its whole path' => ['file' => '/p/templatesx/x.twig', 'excludes' => ['templatesx'], 'base' => '/p/templates', 'excluded' => true];
    }

    /**
     * An exclude that names a file or a directory of the filesystem, from
     * the working directory or the base, behind "./" or by its absolute
     * path, excludes that file or every file under that directory, as it
     * excludes the PHP files.
     *
     * @param string $file    from the project
     * @param string $exclude "{root}" standing for the project
     */
    #[Test]
    #[DataProvider('provideExcludedPaths')]
    public function test_a_file_is_excluded_by_the_path_of_a_file_or_a_directory_naming_it(string $file, string $exclude, bool $fromBase, bool $excluded): void
    {
        $root = $this->enterProject([
            'templates/page.html.twig' => '',
            'templates/legacy/old.html.twig' => '',
        ]);
        if ($fromBase) {
            chdir('/');
        }

        $context = new PatternSourceContext(['templates'], [str_replace('{root}', $root, $exclude)], excludeBase: $fromBase ? $root : null);

        $this->assertSame($excluded, $context->excludes($root.'/'.$file));
    }

    /**
     * @return iterable<string, array{file: string, exclude: string, fromBase: bool, excluded: bool}>
     */
    public static function provideExcludedPaths(): iterable
    {
        yield 'a file' => ['file' => 'templates/page.html.twig', 'exclude' => 'templates/page.html.twig', 'fromBase' => false, 'excluded' => true];
        yield 'a file behind ./' => ['file' => 'templates/page.html.twig', 'exclude' => './templates/page.html.twig', 'fromBase' => false, 'excluded' => true];
        yield 'a file by its absolute path' => ['file' => 'templates/page.html.twig', 'exclude' => '{root}/templates/page.html.twig', 'fromBase' => false, 'excluded' => true];
        yield 'a directory behind ./' => ['file' => 'templates/legacy/old.html.twig', 'exclude' => './templates/legacy', 'fromBase' => false, 'excluded' => true];
        yield 'a directory by its absolute path' => ['file' => 'templates/legacy/old.html.twig', 'exclude' => '{root}/templates/legacy/', 'fromBase' => false, 'excluded' => true];
        yield 'a file beside the directory named' => ['file' => 'templates/page.html.twig', 'exclude' => './templates/legacy', 'fromBase' => false, 'excluded' => false];
        yield 'a file, from the base' => ['file' => 'templates/page.html.twig', 'exclude' => 'templates/page.html.twig', 'fromBase' => true, 'excluded' => true];
        yield 'a directory behind ./, from the base' => ['file' => 'templates/legacy/old.html.twig', 'exclude' => './templates/legacy', 'fromBase' => true, 'excluded' => true];
        yield 'a file gone, behind ./' => ['file' => 'templates/gone.html.twig', 'exclude' => './templates/gone.html.twig', 'fromBase' => false, 'excluded' => true];
        yield 'a file gone with its directory, behind ./' => ['file' => 'old/sub/gone.html.twig', 'exclude' => './old', 'fromBase' => false, 'excluded' => true];
        yield 'a file gone beside the directory named' => ['file' => 'templates/gone.html.twig', 'exclude' => './templates/legacy', 'fromBase' => false, 'excluded' => false];
    }

    /**
     * The base is read by its real path too: a file found by its real path
     * is matched by its path below the base, a base reached through a
     * symbolic link included, so a directory above the base excludes
     * nothing.
     */
    #[Test]
    #[DataProvider('provideExcludesBelowALinkedBase')]
    public function test_a_file_is_matched_below_a_base_reached_through_a_link(string $exclude, bool $excluded): void
    {
        $root = $this->makeProject(['app/templates/cache/x.html.twig' => '']);
        $this->assertTrue(symlink($root.'/app', $root.'/link'));

        $context = new PatternSourceContext(['templates'], [$exclude], excludeBase: $root.'/link');

        $this->assertSame($excluded, $context->excludes($root.'/app/templates/cache/x.html.twig'));
    }

    /**
     * @return iterable<string, array{exclude: string, excluded: bool}>
     */
    public static function provideExcludesBelowALinkedBase(): iterable
    {
        yield 'a directory below the base' => ['exclude' => 'cache', 'excluded' => true];
        yield 'a path below the base' => ['exclude' => 'templates/cache', 'excluded' => true];
        yield 'the directory the link points at, above the base' => ['exclude' => 'app', 'excluded' => false];
    }

    /**
     * A file found below the base by the path written is read there, even
     * when it links outside: the directory the base stands in, above it,
     * excludes nothing.
     */
    #[Test]
    public function test_a_file_linked_below_the_base_is_read_by_the_path_written(): void
    {
        $root = $this->makeProject(['app/templates/x.html.twig' => '', 'other/y.html.twig' => '']);
        $this->assertTrue(symlink($root.'/other/y.html.twig', $root.'/app/templates/link.html.twig'));

        $context = new PatternSourceContext(['templates'], ['app'], excludeBase: $root.'/app/');

        $this->assertFalse($context->excludes($root.'/app/templates/link.html.twig'));
    }

    /**
     * The real path of a base reached through a link holds what lies under
     * that directory, not a sibling whose name starts alike: that one keeps
     * its whole path, and the exclude naming it applies.
     */
    #[Test]
    public function test_a_sibling_of_a_linked_base_starting_alike_is_not_below_it(): void
    {
        $root = $this->makeProject(['app/templates/x.html.twig' => '', 'apple/y.html.twig' => '']);
        $this->assertTrue(symlink($root.'/app', $root.'/link'));

        $context = new PatternSourceContext(['templates'], ['apple'], excludeBase: $root.'/link');

        $this->assertTrue($context->excludes($root.'/apple/y.html.twig'));
    }

    /**
     * With the working directory gone, a relative path leads to no real
     * one: the file and the exclude are compared as written.
     */
    // Windows keeps a working directory from being removed.
    #[RequiresOperatingSystem('Linux|Darwin')]
    #[Test]
    public function test_with_the_working_directory_gone_a_path_is_read_as_written(): void
    {
        $gone = $this->makeProject().'/gone';
        mkdir($gone);
        chdir($gone);
        rmdir($gone);

        $context = new PatternSourceContext([], ['app/Legacy']);

        $this->assertTrue($context->excludes('app/Legacy'));
        $this->assertFalse($context->excludes('app/Kept.php'));
    }
}
