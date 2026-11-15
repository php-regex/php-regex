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
        yield 'under the base' => ['file' => '/p/templates/cache/x.twig', 'excludes' => ['cache'], 'base' => '/p/templates', 'excluded' => true];
        yield 'under the base, written with a trailing slash' => ['file' => '/p/templates/cache/x.twig', 'excludes' => ['cache'], 'base' => '/p/templates/', 'excluded' => true];
        yield 'a file of the base named as the directory' => ['file' => '/p/templates/cache.twig', 'excludes' => ['cache'], 'base' => '/p/templates', 'excluded' => false];
        yield 'deeper under the base' => ['file' => '/p/templates/mail/cache/x.twig', 'excludes' => ['cache'], 'base' => '/p/templates', 'excluded' => true];
        yield 'a directory above the base' => ['file' => '/p/templates/x.twig', 'excludes' => ['p'], 'base' => '/p/templates', 'excluded' => false];
        yield 'a path outside the base keeps its whole path' => ['file' => '/q/cache/x.twig', 'excludes' => ['cache'], 'base' => '/p/templates', 'excluded' => true];
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
    }
}
