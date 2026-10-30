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

namespace PHPRegex\Tests\Unit\Packages;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Composer installs php-regex/php-regex from the archive git builds of a
 * commit: every tracked file but those export-ignore leaves out. The
 * archive holds the library and its manifest only. The scripts of bin/ run
 * the repository's own tooling, some of them through links into tools/,
 * which an install does not have: the executables an install links are
 * the packages' own, under src/.
 */
#[Group('documentation')]
final class MonorepoArchiveTest extends TestCase
{
    private const SHIPPED = ['CHANGELOG.md', 'LICENSE', 'README.md', 'UPGRADE-2.0.md', 'composer.json', 'src'];

    #[Test]
    public function test_the_monorepo_archive_ships_the_library_and_its_manifest_only(): void
    {
        $tracked = self::tracked();

        // A copy of the files outside a git checkout has no archive to
        // build: the attributes are read instead.
        if ([] === $tracked) {
            $this->assertMatchesRegularExpression('~^/bin\s+export-ignore$~m', (string) file_get_contents(self::root().'/.gitattributes'));
            foreach (self::SHIPPED as $entry) {
                $this->assertFileExists(self::root().'/'.$entry);
            }

            return;
        }

        $ignored = self::exportIgnored($tracked);
        $shipped = [];
        foreach ($tracked as $path) {
            if (!self::isLeftOut($path, $ignored)) {
                $shipped[explode('/', $path)[0]] = true;
            }
        }
        $shipped = array_keys($shipped);
        sort($shipped);

        $this->assertSame(self::SHIPPED, $shipped);
    }

    #[Test]
    public function test_the_monorepo_archive_ships_no_script_of_bin(): void
    {
        $this->assertMatchesRegularExpression('~^/bin\s+export-ignore$~m', (string) file_get_contents(self::root().'/.gitattributes'));

        $manifest = json_decode((string) file_get_contents(self::root().'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);
        $this->assertIsArray($manifest['bin'] ?? null);
        foreach ($manifest['bin'] as $script) {
            $this->assertIsString($script);
            $this->assertStringStartsWith('src/', $script);
        }
    }

    /**
     * The files git tracks, or none outside a git checkout.
     *
     * @return list<string>
     */
    private static function tracked(): array
    {
        $output = self::git(['ls-files', '-z'], '');

        return array_values(array_filter(explode("\0", $output), static fn (string $path): bool => '' !== $path));
    }

    /**
     * The paths, and every directory above them, that an export-ignore
     * attribute names, read from the .gitattributes of the checkout.
     *
     * @param list<string> $tracked
     *
     * @return array<string, true>
     */
    private static function exportIgnored(array $tracked): array
    {
        $paths = [];
        foreach ($tracked as $path) {
            $segments = explode('/', $path);
            for ($length = 1, $count = \count($segments); $length <= $count; $length++) {
                $paths[implode('/', \array_slice($segments, 0, $length))] = true;
            }
        }

        $output = self::git(['check-attr', '-z', '--stdin', 'export-ignore'], implode("\0", array_keys($paths))."\0");
        $fields = explode("\0", $output);
        $ignored = [];
        for ($i = 0; $i + 2 < \count($fields); $i += 3) {
            if ('set' === $fields[$i + 2]) {
                $ignored[$fields[$i]] = true;
            }
        }

        return $ignored;
    }

    /**
     * @param array<string, true> $ignored
     */
    private static function isLeftOut(string $path, array $ignored): bool
    {
        $segments = explode('/', $path);
        for ($length = 1, $count = \count($segments); $length <= $count; $length++) {
            if (isset($ignored[implode('/', \array_slice($segments, 0, $length))])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $arguments
     */
    /**
     * @param list<string> $arguments
     */
    private static function git(array $arguments, string $input): string
    {
        // The input goes through a file: git answers while it reads, and a
        // pipe in both directions would wait on itself once either fills.
        $stdin = tmpfile();
        if (false === $stdin) {
            return '';
        }
        fwrite($stdin, $input);
        rewind($stdin);

        $process = proc_open(['git', '-C', self::root(), ...$arguments], [0 => $stdin, 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($process)) {
            return '';
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($stdin);

        return 0 === proc_close($process) ? $output : '';
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
