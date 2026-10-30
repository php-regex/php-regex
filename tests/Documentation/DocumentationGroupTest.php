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

namespace PHPRegex\Tests\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The tests that read the prose (the site under docs/, the READMEs, the
 * changelogs, the upgrade guide, the community files) carry the
 * "documentation" group, which a pull request changing the prose alone runs
 * by itself: tools/phpunit/vendor/bin/phpunit --group documentation. The
 * rule is kept simple so that it holds: a test that names one of those files
 * joins the group, whatever it does with it, and so does every test of this
 * directory.
 */
#[Group('documentation')]
final class DocumentationGroupTest extends TestCase
{
    /**
     * What names the prose in a test: a path of it, or the support classes
     * that read it.
     */
    private const PROSE = '~docs/|README\.md|CHANGELOG\.md|UPGRADE-2\.0\.md|CONTRIBUTING\.md|CODE_OF_CONDUCT\.md|SECURITY\.md|\.github/organization|\bart/|DocumentationPages|UpgradeGuide~';

    private const ATTRIBUTE = "#[Group('documentation')]";

    #[Test]
    public function test_every_test_of_this_directory_is_in_the_group(): void
    {
        $missing = [];
        foreach (glob(__DIR__.'/*Test.php') ?: [] as $path) {
            if (!self::carriesTheGroup((string) file_get_contents($path))) {
                $missing[] = basename($path);
            }
        }

        $this->assertSame([], $missing, 'A test of tests/Documentation carries '.self::ATTRIBUTE.' on its class.');
    }

    #[Test]
    public function test_every_test_naming_the_prose_is_in_the_group(): void
    {
        $root = \dirname(__DIR__, 2);
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/tests', \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            $path = $file->getPathname();
            if (!str_ends_with($path, 'Test.php') || str_contains($path, '/Fixtures/')) {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (1 === preg_match(self::PROSE, $source) && !self::carriesTheGroup($source)) {
                $missing[] = substr($path, \strlen($root) + 1);
            }
        }
        sort($missing);

        $this->assertSame([], $missing, 'A test naming the prose carries '.self::ATTRIBUTE.' on its class.');
    }

    /**
     * The group is one of the suite's, not one its own job alone runs: a
     * change to the code runs these tests with the rest.
     */
    #[Test]
    public function test_the_group_runs_with_the_suite(): void
    {
        $configuration = (string) file_get_contents(\dirname(__DIR__, 2).'/phpunit.xml.dist');

        $this->assertStringNotContainsString('<group>documentation</group>', $configuration);
    }

    /**
     * The attribute on the class, with any other attributes the class
     * carries, right before its declaration.
     */
    private static function carriesTheGroup(string $source): bool
    {
        return 1 === preg_match('~^'.preg_quote(self::ATTRIBUTE, '~').'\n(?:#\[[^\n]+\]\n)*(?:final |abstract )?class ~m', $source)
            || 1 === preg_match('~^(?:#\[[^\n]+\]\n)*'.preg_quote(self::ATTRIBUTE, '~').'\n(?:#\[[^\n]+\]\n)*(?:final |abstract )?class ~m', $source);
    }
}
