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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExamplesScriptsTest extends TestCase
{
    /**
     * The demo applications ship their own suites and their own trees; every
     * other PHP file under demo/ is a standalone example script.
     */
    private const APPLICATIONS = ['symfony', 'laravel'];

    /**
     * @return \Generator<string, array{script: string}>
     */
    public static function provideScripts(): \Generator
    {
        $repoRoot = \dirname(__DIR__, 2);
        $demoRoot = $repoRoot.'/demo';
        $applications = \array_map(
            static fn (string $application): string => $demoRoot.'/'.$application.'/',
            self::APPLICATIONS,
        );

        $scripts = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($demoRoot, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
        );

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            $path = $file->getPathname();

            foreach ($applications as $application) {
                if (\str_starts_with($path, $application)) {
                    continue 2;
                }
            }

            if ('php' === $file->getExtension()) {
                $scripts[] = \substr($path, \strlen($repoRoot) + 1);
            }
        }

        \sort($scripts);

        foreach ($scripts as $script) {
            yield $script => ['script' => $script];
        }
    }

    #[Test]
    #[DataProvider('provideScripts')]
    public function test_example_script_runs_clean(string $script): void
    {
        [$output, $exitCode] = $this->runScript($script);

        $this->assertSame(0, $exitCode, $output);
        $this->assertNotSame('', $output);
    }

    #[Test]
    #[DataProvider('provideScripts')]
    public function test_example_script_is_indexed_in_the_demo_readme(string $script): void
    {
        $readme = \file_get_contents(\dirname(__DIR__, 2).'/demo/README.md');

        $this->assertIsString($readme);
        $this->assertStringContainsString(
            $script,
            (string) $readme,
            \sprintf('Every example must be indexed in demo/README.md; %s is missing.', $script),
        );
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function runScript(string $script): array
    {
        // Started without a shell, both streams read: the quoting of a
        // shell is not the same on every host.
        $process = proc_open([\PHP_BINARY, \dirname(__DIR__, 2).'/'.$script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [rtrim($output, "\n"), proc_close($process)];
    }
}
